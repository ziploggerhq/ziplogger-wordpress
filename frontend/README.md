# Browser scripts of the ZipLogger plugin: source and exact build instructions

The plugin ships two minified scripts, built from the readable sources in this directory:

| Shipped file (`plugin/ziplogger/assets/js/`) | Built from |
| --- | --- |
| `ziplogger.min.js` | `src/entry.js` and the modules it imports, bundled with the ZipLogger browser client (`@ziplogger/browser` 0.6.0) and Google's `web-vitals` 6.2.2 |
| `ziplogger-recorder.min.js` | `src/recorder-entry.js`, which bundles rrweb's recorder (`@rrweb/record` 2.1.6) |

Everything that decides what the scripts do is in `src/`. The bundler is esbuild 0.28.2. Dependency versions are pinned exactly in `package.json` and locked, with integrity hashes, in `package-lock.json`.

## Rebuild

You need Node.js 22 (`.nvmrc`) and npm.

```bash
cd frontend
npm ci               # installs exactly what package-lock.json says
npm run build        # writes ../plugin/ziplogger/assets/js/ (both scripts, manifest.json, THIRD-PARTY-LICENSES.txt)
npm test             # unit tests (jsdom) and checks on the built files
```

Without a local Node, the same in a container (this is how the shipped files were built):

```bash
docker run --rm -v "$PWD:/work" -w /work/frontend node:22-alpine sh -c "npm ci && npm run build"
```

## Checking that the shipped files are what this source builds

The build is deterministic: no timestamps, no absolute paths, normalised line endings. After `npm run build`, the size and SHA-256 of each script in `plugin/ziplogger/assets/js/manifest.json` must equal the ones committed in this repository, and `git status` must show no change under `plugin/ziplogger/assets/js/`.

```bash
sha256sum ../plugin/ziplogger/assets/js/*.min.js     # compare with manifest.json
```

## Licences

`plugin/ziplogger/assets/js/THIRD-PARTY-LICENSES.txt` carries the licence text of every third-party package that is inside the bundles, and the build regenerates it. The licence texts of packages that ship none are kept in `licenses/` (copied from the projects' own repositories) and are added to that file by the build. The plugin itself is GPL-2.0-or-later; `@ziplogger/browser` and rrweb are MIT, `web-vitals` is Apache-2.0.

More: [../docs/BUILD.md](../docs/BUILD.md) (the whole plugin, the ZIP, the tests).
