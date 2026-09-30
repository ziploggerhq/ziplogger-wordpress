// A local ZipLogger receiver for end-to-end tests. It is NOT the real service: it models the parts of the
// ingestion contract the plugin depends on (written from the service's own source) so
// delivery behaviour can be exercised deterministically, including faults.
//
//   POST /ingest/v1/logs   X-Api-Key, optional Idempotency-Key, JSON array | object | NDJSON
//        202 {accepted, rejected}   429 partial {accepted, rejected}+Retry-After   401 / 400 / 413 / 422 / 409 / 503
//   POST <other ingest paths> are captured generically (see ROUTES) so each signal can be inspected.
//
// Control (never used by the plugin):
//   POST /__control   {mode, ...}  fault injection:  ok | down | reset | slow | partial | auth | poison | limit
//   GET  /__received[?path=/x]     everything captured so far
//   DELETE /__received             forget everything
//   GET  /__stats
//
// Browser-facing endpoints (CORS: any origin, like the real service) are modelled too:
//   GET  /ingest/v1/replay/config   the recording switch (control.replayConfig overrides the answer)
//   POST /ingest/v1/replay          replay chunks (gzip accepted); a repeated (session, sequence) is acknowledged, not stored twice
//   ANY  /third-party/*             a stand-in for a third-party site, so tests can see exactly which headers reached it
import http from 'node:http';
import crypto from 'node:crypto';
import zlib from 'node:zlib';
import { createReadModel } from './mock-read-model.mjs';

const PORT = Number(process.env.PORT || 5081);
const KEYS = Object.fromEntries((process.env.MOCK_KEYS || 'zk_e2e_server_key_0000000000:ingest,zk_e2e_browser_key_00000000:ingest,zk_e2e_read_key_000000000000:read,zk_e2e_wrong_scope_key_000000:ingest')
  .split(',').map((p) => p.split(':')));
const MAX_BODY = Number(process.env.MOCK_MAX_BODY || 32 * 1024 * 1024);

const received = [];            // every request the receiver saw (including rejected ones)
const claims = new Map();       // Idempotency-Key -> { hash, state, accepted, response }
let control = { mode: 'ok', delayMs: 0, acceptedPrefix: 0, marker: 'POISON', remaining: Infinity };
const replaySeen = new Set();   // "<key>:<session>:<sequence>"
const DEFAULT_REPLAY_CONFIG = { enabled: true, sampleRate: 1, maskInputs: true, maskAllText: false, maxSessionSeconds: 3600, maxSessionBytes: 50000000, flushIntervalMs: 1000 };

const json = (res, status, body, headers = {}) => {
  const text = typeof body === 'string' ? body : JSON.stringify(body);
  res.writeHead(status, { 'content-type': 'application/json', 'access-control-allow-origin': '*', ...headers });
  res.end(text);
};

function cors(req, res) {
  res.setHeader('access-control-allow-origin', req.headers.origin || '*');
  res.setHeader('access-control-allow-headers', req.headers['access-control-request-headers'] || '*');
  res.setHeader('access-control-allow-methods', 'GET, POST, OPTIONS');
  res.setHeader('access-control-max-age', '600');
}

async function readBody(req) {
  const chunks = [];
  let size = 0;
  for await (const c of req) {
    size += c.length;
    if (size > MAX_BODY) throw Object.assign(new Error('too large'), { status: 413 });
    chunks.push(c);
  }
  let buf = Buffer.concat(chunks);
  const enc = String(req.headers['content-encoding'] || '');
  if (enc.includes('gzip')) buf = zlib.gunzipSync(buf);
  return buf;
}

function parseLogs(text) {
  const t = text.trim();
  if (!t) return [];
  if (t.startsWith('[')) return JSON.parse(t);
  if (t.startsWith('{') && !t.includes('\n')) return [JSON.parse(t)];
  return t.split('\n').filter(Boolean).map((l) => JSON.parse(l));
}

// The contract the backend's IngestLogDto binds. Anything else is silently ignored by the real service;
// the receiver records it so tests can assert the plugin never invents properties.
const LOG_KEYS = new Set(['timestamp', 'source', 'severity', 'message', 'release', 'commitSha', 'stackTrace', 'fields', 'tags']);
const SEVERITIES = new Set(['debug', 'info', 'warn', 'error', 'fatal']);
const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,7})?(Z|[+-]\d{2}:\d{2})$/;

function lintLog(rec) {
  const problems = [];
  if (typeof rec !== 'object' || rec === null || Array.isArray(rec)) return ['record is not an object'];
  for (const k of Object.keys(rec)) if (!LOG_KEYS.has(k)) problems.push(`unknown property ${k}`);
  if (typeof rec.message !== 'string') problems.push('message is not a string');
  if (rec.timestamp !== undefined && !(typeof rec.timestamp === 'string' && ISO.test(rec.timestamp))) problems.push('timestamp is not ISO-8601');
  if (rec.severity !== undefined && !SEVERITIES.has(rec.severity)) problems.push(`severity ${rec.severity} would be coerced to info`);
  if (rec.fields !== undefined && (typeof rec.fields !== 'object' || Array.isArray(rec.fields))) problems.push('fields is not an object');
  if (rec.tags !== undefined && !(Array.isArray(rec.tags) && rec.tags.every((x) => typeof x === 'string'))) problems.push('tags is not a string list');
  return problems;
}

function handleLogs(req, res, entry, body) {
  const key = req.headers['x-api-key'];
  if (!key) return json(res, 401, { error: 'Missing X-Api-Key' });
  const scope = KEYS[String(key)];
  if (!scope || !scope.split('+').includes('ingest')) return json(res, 401, { error: 'Invalid API key' });
  entry.tenant = String(key);

  let records;
  try { records = parseLogs(body.toString('utf8')); } catch (e) { return json(res, 400, { error: 'Invalid JSON', detail: String(e.message) }); }
  if (records.length === 0) return json(res, 400, { error: 'No log entries in payload' });
  entry.records = records;
  entry.lint = records.flatMap((r, i) => lintLog(r).map((p) => `#${i}: ${p}`));

  if (control.mode === 'poison' && body.toString('utf8').includes(control.marker)) {
    return json(res, 400, { error: 'Invalid JSON', detail: 'poison marker' });
  }

  const idem = req.headers['idempotency-key'];
  const hash = crypto.createHash('sha256').update(body).digest('hex');
  let skip = 0;
  if (idem) {
    const k = `${key}:${String(idem).slice(0, 200)}`;
    const existing = claims.get(k);
    if (existing) {
      if (existing.hash !== hash) return json(res, 422, { error: 'This Idempotency-Key was already used with a different payload. Use a new key for a new batch.' });
      if (existing.state === 'completed') { entry.replayed = true; return json(res, existing.status, existing.response); }
      if (existing.state === 'processing') return json(res, 409, { error: 'still being processed' }, { 'retry-after': '2' });
      if (existing.state === 'partial') skip = Math.min(existing.accepted, records.length);
    }
    claims.set(k, { hash, state: 'processing', accepted: existing?.accepted || 0 });
    entry.claimKey = k;
  }

  let toAdmit = records.length - skip;
  let accepted = skip;
  let rejected = 0;
  if (control.mode === 'partial') {
    const room = Math.max(0, control.acceptedPrefix - accepted);
    const take = Math.min(toAdmit, room || 0);
    accepted += take; rejected = toAdmit - take;
  } else {
    accepted += toAdmit;
  }
  entry.admitted = records.slice(skip, accepted);
  const status = rejected === 0 ? 202 : 429;
  const response = { accepted, rejected };
  if (entry.claimKey) claims.set(entry.claimKey, { hash, state: rejected === 0 ? 'completed' : 'partial', accepted, status, response });
  return json(res, status, response, rejected ? { 'retry-after': '1' } : {});
}

// Other signals are captured verbatim and acknowledged; tests inspect them through /__received.
const CAPTURE = new Map([
  ['/ingest/v1/events', { ok: 202, reply: (n) => ({ accepted: n, rejected: 0 }) }],
  ['/v1/traces', { ok: 200, reply: () => ({}) }],
  ['/v1/logs', { ok: 200, reply: () => ({}) }],
  ['/v1/metrics', { ok: 200, reply: () => ({}) }],
]);

const grafana = createReadModel({ received, KEYS, json });

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const path = url.pathname;

  if (path === '/__control' && req.method === 'POST') {
    const b = JSON.parse((await readBody(req)).toString('utf8') || '{}');
    control = { mode: 'ok', delayMs: 0, acceptedPrefix: 0, marker: 'POISON', remaining: Infinity, ...b };
    return json(res, 200, control);
  }
  if (path === '/__received') {
    if (req.method === 'DELETE') { received.length = 0; claims.clear(); replaySeen.clear(); return json(res, 200, { cleared: true }); }
    const only = url.searchParams.get('path');
    return json(res, 200, received.filter((r) => !only || r.path === only));
  }
  if (path === '/__stats') {
    return json(res, 200, { total: received.length, byPath: received.reduce((m, r) => ((m[r.path] = (m[r.path] || 0) + 1), m), {}), control });
  }

  if (req.method === 'OPTIONS') { cors(req, res); res.writeHead(204); return res.end(); }
  cors(req, res);

  let body;
  try { body = await readBody(req); } catch (e) { return json(res, e.status || 400, { error: String(e.message) }); }
  const entry = {
    at: new Date().toISOString(),
    method: req.method,
    path,
    headers: req.headers,
    bytes: body.length,
    body: body.toString('utf8'),
    mode: control.mode,
  };
  received.push(entry);

  if (control.delayMs) await new Promise((r) => setTimeout(r, control.delayMs));
  if (control.mode === 'reset') { entry.outcome = 'reset'; return req.socket.destroy(); }
  if (control.mode === 'down') { entry.outcome = 503; return json(res, 503, { error: 'unavailable' }, { 'retry-after': '1' }); }
  if (control.mode === 'auth') { entry.outcome = 401; return json(res, 401, { error: 'Invalid API key' }); }
  if (control.mode === 'limit' && control.remaining-- <= 0) { entry.outcome = 413; return json(res, 413, { error: 'Payload too large' }); }

  if (path.startsWith('/grafana/') && req.method === 'GET') return grafana(req, res, url, entry);

  if (path === '/ingest/v1/logs' && req.method === 'POST') {
    handleLogs(req, res, entry, body);
    entry.outcome = res.statusCode;
    return;
  }

  if (path === '/ingest/v1/replay/config' && req.method === 'GET') {
    const key = req.headers['x-api-key'];
    if (!key || !KEYS[String(key)]) { entry.outcome = 401; return json(res, 401, { error: key ? 'Invalid API key' : 'Missing X-Api-Key' }); }
    entry.tenant = String(key);
    entry.outcome = 200;
    return json(res, 200, { ...DEFAULT_REPLAY_CONFIG, ...(control.replayConfig || {}) });
  }

  if (path === '/ingest/v1/replay' && req.method === 'POST') {
    const key = req.headers['x-api-key'];
    if (!key || !KEYS[String(key)]) { entry.outcome = 401; return json(res, 401, { error: key ? 'Invalid API key' : 'Missing X-Api-Key' }); }
    entry.tenant = String(key);
    try {
      const chunk = JSON.parse(entry.body);
      entry.replay = { sessionId: chunk.sessionId, sequence: chunk.sequence, events: (chunk.events || []).length, final: !!(chunk.meta && chunk.meta.final), url: chunk.meta && chunk.meta.url };
      const id = `${key}:${chunk.sessionId}:${chunk.sequence}`;
      entry.duplicate = replaySeen.has(id);
      replaySeen.add(id);
    } catch (e) { entry.outcome = 400; return json(res, 400, { error: 'Invalid replay chunk' }); }
    entry.outcome = 202;
    return json(res, 202, { accepted: true });
  }

  if (path.startsWith('/third-party')) {
    entry.outcome = 200;
    return json(res, 200, { ok: true });
  }

  const cap = CAPTURE.get(path);
  if (cap && req.method === 'POST') {
    const key = req.headers['x-api-key'];
    if (!key || !KEYS[String(key)]) { entry.outcome = 401; return json(res, 401, { error: key ? 'Invalid API key' : 'Missing X-Api-Key' }); }
    entry.tenant = String(key);
    let n = 1;
    try { const p = JSON.parse(entry.body); n = Array.isArray(p) ? p.length : (p.events?.length ?? 1); } catch { /* not JSON: protobuf etc. */ }
    entry.outcome = cap.ok;
    return json(res, cap.ok, cap.reply(n));
  }

  entry.outcome = 404;
  json(res, 404, { error: 'not found' });
});

server.listen(PORT, () => console.log(`mock ZipLogger receiver on :${PORT}`));
