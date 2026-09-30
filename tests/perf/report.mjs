// Turn the two result files into the Markdown tables of docs/PERFORMANCE.md, so no number is typed by hand.
//
//   node tests/perf/report.mjs [results-server.json] [results-browser.json]
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const serverFile = process.argv[2] || path.join(here, 'results-server.json');
const browserFile = process.argv[3] || path.join(here, 'results-browser.json');

const fmt = (n, digits = 1) => Number(n).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
const signed = (n, digits = 1) => (n >= 0 ? '+' : '-') + fmt(Math.abs(n), digits);
const mib = (bytes) => fmt(bytes / 1048576, 1);
const row = (cells) => `| ${cells.join(' | ')} |`;
const out = [];

if (fs.existsSync(serverFile)) {
  const s = JSON.parse(fs.readFileSync(serverFile, 'utf8'));
  const base = s.configs.find((c) => c.id === 'inactive');
  out.push(`### Server: one page request (${s.rounds} requests per row, interleaved, after ${s.warmupRounds} warm-up rounds; measured ${s.generated.slice(0, 10)})`, '');
  out.push(row(['Configuration', 'PHP time, median (ms)', 'p95 (ms)', 'Added to the median (ms)', 'DB queries, median', 'Added queries', 'Peak memory in use, median (MiB)', 'Added memory (MiB)']));
  out.push(row(['---', '---:', '---:', '---:', '---:', '---:', '---:', '---:']));
  for (const c of s.configs) {
    const isBase = c.id === 'inactive';
    out.push(row([
      c.label,
      fmt(c.phpMs.median, 1),
      fmt(c.phpMs.p95, 1),
      isBase ? 'baseline' : signed(c.phpMs.median - base.phpMs.median, 1),
      fmt(c.queries.median, 0),
      isBase ? 'baseline' : signed(c.queries.median - base.queries.median, 0),
      fmt(c.peakUsedBytes.median / 1048576, 2),
      isBase ? 'baseline' : signed((c.peakUsedBytes.median - base.peakUsedBytes.median) / 1048576, 2),
    ]));
  }
  out.push('');
}

if (fs.existsSync(browserFile)) {
  const b = JSON.parse(fs.readFileSync(browserFile, 'utf8'));
  const base = b.configs.find((c) => c.id === 'inactive');
  out.push(`### Browser: one cold page load in Chromium (${b.rounds} loads per row, interleaved, after ${b.warmupRounds} warm-up rounds; measured ${b.generated.slice(0, 10)})`, '');
  out.push(row(['Configuration', 'Script execution, median (ms)', 'Main-thread task time, median (ms)', 'Added task time (ms)', 'Longest task, median (ms)', 'JS heap, median (MB)', 'Plugin bytes on the wire', 'Requests to ZipLogger, median', 'Load event, median (ms)']));
  out.push(row(['---', '---:', '---:', '---:', '---:', '---:', '---:', '---:', '---:']));
  for (const c of b.configs) {
    const isBase = c.id === 'inactive';
    out.push(row([
      c.label,
      fmt(c.scriptMs.median, 1),
      fmt(c.taskMs.median, 1),
      isBase ? 'baseline' : signed(c.taskMs.median - base.taskMs.median, 1),
      fmt(c.longTaskMs.median, 1),
      fmt(c.jsHeapMB.median, 1),
      Number(c.pluginEncodedBytes).toLocaleString('en-US'),
      fmt(c.requestsToZipLogger.median, 0),
      fmt(c.loadMs.median, 0),
    ]));
  }
  out.push('');
}

console.log(out.join('\n'));
