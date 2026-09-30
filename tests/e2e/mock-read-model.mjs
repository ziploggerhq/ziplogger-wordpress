// The read side of the local ZipLogger stand-in: what the workspace's Grafana-compatible query surface would
// answer, computed from what the receiver has been sent. It follows the request and response shapes documented
// in the service's own source, so the plugin's dashboard code can be exercised end to end.
//
// It is NOT the real service. The query language support is a small subset written for these tests, and only a
// key with the READ scope is accepted (an ingestion key gets 401, as it should).

export function createReadModel({ received, KEYS, json }) {
  const storedLogs = () => received
    .filter((e) => e.path === '/ingest/v1/logs' && e.admitted && !e.replayed)
    .flatMap((e) => e.admitted.map((r) => ({ ...r, _tenant: e.tenant })));

  const storedSpans = () => received.filter((e) => e.path === '/v1/traces' && e.outcome === 200).flatMap((e) => {
    let doc;
    try { doc = JSON.parse(e.body); } catch { return []; }
    return (doc.resourceSpans || []).flatMap((rs) => {
      const service = ((rs.resource?.attributes || []).find((a) => a.key === 'service.name') || {}).value?.stringValue || 'unknown';
      return (rs.scopeSpans || []).flatMap((ss) => (ss.spans || []).map((sp) => ({ ...sp, _service: service })));
    });
  });

  function labelValue(record, label) {
    if (label === 'service' || label === 'source') return record.source;
    if (label === 'severity' || label === 'level') return record.severity;
    if (label === 'release') return record.release;
    if (label === 'commit_sha' || label === 'commitSha') return record.commitSha;
    if (label.startsWith('field_')) {
      const v = (record.fields || {})[label.slice(6)];
      return v === undefined ? undefined : String(v);
    }
    return undefined;
  }

  function parseLogQl(expr) {
    const m = /^\s*(?:(count_over_time|rate)\s*\(\s*)?\{([^}]*)\}\s*(?:\[(\d+)([smhd])\])?\s*\)?\s*$/.exec(expr || '');
    if (!m) return { error: 'Only a stream selector, optionally inside count_over_time or rate, is supported.' };
    const matchers = [];
    for (const part of m[2].split(/,(?=(?:[^"]*"[^"]*")*[^"]*$)/)) {
      const mm = /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(=~|!=|=)\s*"((?:[^"\\]|\\.)*)"\s*$/.exec(part);
      if (part.trim() && !mm) return { error: `Cannot parse the label matcher: ${part.trim()}` };
      if (mm) matchers.push({ label: mm[1], op: mm[2], value: mm[3] });
    }
    const unit = { s: 1, m: 60, h: 3600, d: 86400 }[m[4]] || 0;
    return { matchers, aggregation: m[1] || null, rangeSeconds: m[3] ? Number(m[3]) * unit : 0 };
  }

  function matches(record, matchers) {
    return matchers.every(({ label, op, value }) => {
      const v = labelValue(record, label);
      if (op === '=') return v === value;
      if (op === '!=') return v !== value;
      return v !== undefined && new RegExp(`^(?:${value})$`).test(v);
    });
  }

  // The service renders a log line as the message followed by its fields in logfmt.
  const logfmt = (r) => [r.message, ...Object.entries(r.fields || {})
    .filter(([, v]) => v !== null && v !== '')
    .slice(0, 40)
    .map(([k, v]) => `${k.replace(/[^A-Za-z0-9_]/g, '_')}=${/[\s"=]/.test(String(v)) ? JSON.stringify(String(v)) : v}`)].join(' ');

  const unixSeconds = (raw, fallback) => {
    if (raw === undefined || raw === null || raw === '') return fallback;
    const n = Number(raw);
    if (Number.isFinite(n)) return n > 1e17 ? Math.floor(n / 1e9) : n > 1e11 ? Math.floor(n / 1e3) : Math.floor(n);
    const d = Date.parse(raw);
    return Number.isFinite(d) ? Math.floor(d / 1000) : fallback;
  };

  return function grafana(req, res, url, entry) {
    const key = String(req.headers['x-api-key'] || '');
    if (!key) { entry.outcome = 401; return json(res, 401, { error: 'Missing X-Api-Key' }); }
    if (KEYS[key] !== 'read') { entry.outcome = 401; return json(res, 401, { error: 'Invalid API key' }); }
    entry.tenant = key;
    entry.outcome = 200;
    const path = url.pathname;
    const q = url.searchParams;
    const now = Math.floor(Date.now() / 1000);
    const end = unixSeconds(q.get('end'), now);
    const start = unixSeconds(q.get('start'), end - 3600);

    if (path === '/grafana/health') {
      return json(res, 200, { status: 'ok', workspace: 'e2e-workspace', surfaces: { loki: '/grafana/loki', prometheus: '/grafana/prometheus', tempo: '/grafana/tempo' } });
    }

    if (path === '/grafana/loki/api/v1/query_range' || path === '/grafana/loki/api/v1/query') {
      const parsed = parseLogQl(q.get('query'));
      if (parsed.error) { entry.outcome = 400; return json(res, 400, { status: 'error', error: parsed.error }); }
      const inWindow = storedLogs().filter((r) => {
        const t = Math.floor(Date.parse(r.timestamp) / 1000);
        return t >= start && t <= end && matches(r, parsed.matchers);
      });
      if (parsed.aggregation) {
        const step = Math.max(parsed.rangeSeconds, 1);
        const buckets = new Map();
        for (const r of inWindow) {
          const b = Math.floor(Date.parse(r.timestamp) / 1000 / step) * step;
          buckets.set(b, (buckets.get(b) || 0) + 1);
        }
        const values = [...buckets.entries()].sort((a, b) => a[0] - b[0]).map(([t, c]) => [t, String(parsed.aggregation === 'rate' ? c / step : c)]);
        return json(res, 200, { status: 'success', data: { resultType: 'matrix', result: [{ metric: {}, values }] } });
      }
      const limit = Math.min(Number(q.get('limit') || 1000), 5000);
      const groups = new Map();
      for (const r of inWindow.sort((a, b) => Date.parse(b.timestamp) - Date.parse(a.timestamp)).slice(0, limit)) {
        const k = `${r.source}|${r.severity}`;
        if (!groups.has(k)) groups.set(k, { stream: { service: r.source, severity: r.severity }, values: [] });
        groups.get(k).values.push([String(BigInt(Date.parse(r.timestamp)) * 1000000n), logfmt(r)]);
      }
      return json(res, 200, { status: 'success', data: { resultType: 'streams', result: [...groups.values()] } });
    }

    if (path === '/grafana/prometheus/api/v1/query_range' || path === '/grafana/prometheus/api/v1/query') {
      const m = /^\s*([A-Za-z_:][A-Za-z0-9_:]*)\s*(?:\{\s*service\s*=\s*"([^"]*)"\s*\})?\s*$/.exec(q.get('query') || '');
      const known = ['ziplogger_request_count', 'ziplogger_request_error_count', 'ziplogger_request_p95_ms'];
      if (!m || !known.includes(m[1])) { entry.outcome = 400; return json(res, 400, { status: 'error', error: `Unknown metric. Available: ${known.join(', ')}` }); }
      const sm = /^(\d+)(s|m|h)?$/.exec(q.get('step') || '3600');
      const step = sm ? Number(sm[1]) * ({ s: 1, m: 60, h: 3600 }[sm[2] || 's']) : 3600;
      const spans = storedSpans().filter((sp) => sp.kind === 2 && (!m[2] || sp._service === m[2]));
      const buckets = new Map();
      for (const sp of spans) {
        const t = Math.floor(Number(sp.startTimeUnixNano) / 1e9);
        if (t < start || t > end) continue;
        const b = Math.floor(t / step) * step;
        if (!buckets.has(b)) buckets.set(b, []);
        buckets.get(b).push(sp);
      }
      const values = [...buckets.entries()].sort((a, b) => a[0] - b[0]).map(([t, list]) => {
        if (m[1] === 'ziplogger_request_count') return [t, String(list.length)];
        if (m[1] === 'ziplogger_request_error_count') return [t, String(list.filter((sp) => (sp.status || {}).code === 2).length)];
        const ms = list.map((sp) => (Number(sp.endTimeUnixNano) - Number(sp.startTimeUnixNano)) / 1e6).sort((a, b) => a - b);
        return [t, String(ms[Math.max(0, Math.ceil(0.95 * ms.length) - 1)])];
      });
      return json(res, 200, { status: 'success', data: { resultType: 'matrix', result: values.length ? [{ metric: { service: m[2] || '' }, values }] : [] } });
    }

    if (path === '/grafana/tempo/api/search') {
      const service = q.get('service.name') || q.get('service');
      const errorsOnly = q.get('status') === 'error';
      const limit = Math.min(Number(q.get('limit') || 20), 200);
      const byTrace = new Map();
      for (const sp of storedSpans()) {
        if (!byTrace.has(sp.traceId)) byTrace.set(sp.traceId, []);
        byTrace.get(sp.traceId).push(sp);
      }
      const traces = [];
      for (const [traceId, list] of byTrace) {
        const root = list.find((sp) => sp.kind === 2) || list[0];
        if (service && !list.some((sp) => sp._service === service)) continue;
        if (errorsOnly && !list.some((sp) => (sp.status || {}).code === 2)) continue;
        const t = Math.floor(Number(root.startTimeUnixNano) / 1e9);
        if (t < start || t > end) continue;
        traces.push({ traceID: traceId, rootServiceName: root._service, rootTraceName: root.name, startTimeUnixNano: root.startTimeUnixNano, durationMs: (Number(root.endTimeUnixNano) - Number(root.startTimeUnixNano)) / 1e6, spanSets: [] });
      }
      traces.sort((a, b) => Number(b.startTimeUnixNano) - Number(a.startTimeUnixNano));
      return json(res, 200, { traces: traces.slice(0, limit), metrics: { inspectedTraces: traces.length } });
    }

    entry.outcome = 404;
    return json(res, 404, { error: 'not found' });
  };
}
