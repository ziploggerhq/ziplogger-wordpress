// Builds the two browser bundles the plugin ships:
//
//   assets/js/ziplogger.min.js           the plugin's script: official ZipLogger browser client + replay
//                                        controller, glue, Core Web Vitals. Loaded on pages that need it.
//   assets/js/ziplogger-recorder.min.js  rrweb's record(). Loaded lazily, only when a session is recorded.
//
// Plus assets/js/manifest.json (sizes, hashes, exact dependency versions) and
// THIRD-PARTY-LICENSES.txt (the licence text of every package that ended up inside a bundle).
//
// Reproducible: dependency versions are pinned in package.json and package-lock.json, esbuild is pinned,
// and the output contains no timestamps or absolute paths. Run `npm ci && npm run build`.

import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const outDir = resolve(here, '..', 'plugin', 'ziplogger', 'assets', 'js');
mkdirSync(outDir, { recursive: true });

const pkg = JSON.parse(readFileSync(join(here, 'package.json'), 'utf8'));

// The SDK's replay module imports rrweb dynamically as a default. The plugin always supplies its own
// loader, so the default must not drag rrweb into the main bundle.
const stubRrweb = {
  name: 'stub-rrweb-in-main-bundle',
  setup(b) {
    b.onResolve({ filter: /^@rrweb\/record$/ }, () => ({ path: 'rrweb-stub', namespace: 'zl-stub' }));
    b.onLoad({ filter: /.*/, namespace: 'zl-stub' }, () => ({ contents: 'export const record = undefined;', loader: 'js' }));
  },
};

const common = {
  bundle: true,
  format: 'iife',
  target: ['es2018'],
  minify: true,
  legalComments: 'none',
  sourcemap: false,
  metafile: true,
  logLevel: 'warning',
};

const bundles = [
  { name: 'ziplogger.min.js', entry: 'src/entry.js', plugins: [stubRrweb] },
  { name: 'ziplogger-recorder.min.js', entry: 'src/recorder-entry.js', plugins: [] },
];

function packageOf(inputPath) {
  const parts = inputPath.split(/[\\/]/);
  const i = parts.lastIndexOf('node_modules');
  if (i === -1) return null;
  const first = parts[i + 1];
  return first.startsWith('@') ? first + '/' + parts[i + 2] : first;
}

const used = new Map(); // package name -> bundles using it
const manifest = { generator: 'ziplogger-wordpress-frontend', bundles: {}, dependencies: pkg.dependencies, tools: { esbuild: pkg.devDependencies.esbuild } };

for (const b of bundles) {
  const result = await build({
    ...common,
    entryPoints: [join(here, b.entry)],
    outfile: join(outDir, b.name),
    plugins: b.plugins,
    write: false,
  });
  const out = result.outputFiles[0];
  // Deterministic output: normalise line endings, no trailing whitespace.
  // rrweb embeds a worker's source as a template literal that ends in a "//# sourceMappingURL=" line for a
  // map that is not shipped; browsers would only ever 404 on it. Removing the whole line is safe there.
  const text = out.text.replace(/\r\n/g, '\n').replace(/\n\/\/# sourceMappingURL=[^\n]*(?=\n)/g, '');
  writeFileSync(join(outDir, b.name), text);
  const bytes = Buffer.byteLength(text);
  manifest.bundles[b.name] = {
    bytes,
    sha256: createHash('sha256').update(text).digest('hex'),
    source: b.entry,
  };
  for (const input of Object.keys(result.metafile.inputs)) {
    const p = packageOf(input);
    if (p) used.set(p, (used.get(p) || new Set()).add(b.name));
  }
  console.log(`${b.name}: ${bytes} bytes`);
}

// Licences of everything that is inside a bundle.
let licences = 'Third-party software included in the browser assets of the ZipLogger plugin.\n'
  + 'The plugin itself is GPL-2.0-or-later. Each package below is distributed under its own licence.\n\n';
const list = [];
for (const name of [...used.keys()].sort()) {
  const dir = join(here, 'node_modules', ...name.split('/'));
  const meta = JSON.parse(readFileSync(join(dir, 'package.json'), 'utf8'));
  let text = '';
  for (const candidate of ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'license', 'LICENCE']) {
    if (existsSync(join(dir, candidate))) { text = readFileSync(join(dir, candidate), 'utf8').replace(/\r\n/g, '\n').trim(); break; }
  }
  // A package that ships no licence file (@rrweb/record) has its licence text kept in frontend/licenses/, copied
  // from the project's own repository, so that the notice the licence requires travels with the bundle.
  const kept = join(here, 'licenses', name.replace('/', '__') + '.txt');
  if (!text && existsSync(kept)) text = readFileSync(kept, 'utf8').replace(/\r\n/g, '\n').trim();
  list.push({ name, version: meta.version, license: typeof meta.license === 'string' ? meta.license : (meta.license && meta.license.type) || 'see package', bundles: [...used.get(name)].sort() });
  licences += '-'.repeat(78) + `\n${name} ${meta.version} (${list[list.length - 1].license})  [${list[list.length - 1].bundles.join(', ')}]\n` + '-'.repeat(78) + `\n${text || '(no licence file shipped in the package; see its package.json)'}\n\n`;
}
// Code that is compiled INTO a dependency's own distribution file, so esbuild cannot see it as a package.
const inlined = [{ name: 'base64-arraybuffer', into: '@rrweb/record', bundle: 'ziplogger-recorder.min.js', licence: 'MIT' }];
for (const item of inlined) {
  if (!used.has(item.into)) continue;
  const text = readFileSync(join(here, 'licenses', item.name + '.txt'), 'utf8').replace(/\r\n/g, '\n').trim();
  licences += '-'.repeat(78) + `\n${item.name} (${item.licence}), compiled into ${item.into}  [${item.bundle}]\n` + '-'.repeat(78) + `\n${text}\n\n`;
}
manifest.packages = list;
writeFileSync(join(outDir, 'THIRD-PARTY-LICENSES.txt'), licences);
writeFileSync(join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(`packages bundled: ${list.map((p) => p.name + '@' + p.version).join(', ')}`);
