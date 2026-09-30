# Source code and rebuilding the browser scripts

The PHP in this plugin is shipped as readable source. The two browser scripts in `assets/js/` are **built** (bundled and minified) from readable sources that live in the `frontend/` directory of the plugin's public source repository, https://github.com/ziploggerhq/ziplogger-wordpress:

| Shipped file | Built from |
| --- | --- |
| `assets/js/ziplogger.min.js` | `frontend/src/entry.js` and the modules it imports (`index.js`, `consent.js`, `identity.js`, `errors.js`, `network.js`, `tracing.js`, `analytics.js`, `replay.js`, `commerce.js`, `performance.js`, `navigation.js`, `client.js`, `util.js`), bundled with the official ZipLogger browser client (`@ziplogger/browser`) and Google's `web-vitals` |
| `assets/js/ziplogger-recorder.min.js` | `frontend/src/recorder-entry.js`, which bundles rrweb's recorder (`@rrweb/record`) |

`assets/js/manifest.json` records the exact dependency versions, the size and SHA-256 of each file. `assets/js/THIRD-PARTY-LICENSES.txt` carries the licence text of every package inside them.

Rebuilding (needs Node.js 22; the repository's `docs/BUILD.md` has the reproducible, containerised version):

```bash
cd frontend
npm ci
npm run build        # writes ../plugin/ziplogger/assets/js/*
npm test             # unit tests (jsdom) including checks on the built files
```

The build is deterministic: dependency versions are pinned in `package.json` and `package-lock.json`, esbuild is pinned, and the output contains no timestamps or absolute paths.
