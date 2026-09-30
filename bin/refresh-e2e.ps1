# Rebuild the browser bundles and the ZIP (both in containers, so the result does not depend on the host),
# ready for the end-to-end tests. Usage: pwsh bin/refresh-e2e.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Push-Location $root
try {
	docker run --rm -v "${root}:/work" -w /work/frontend node:22-alpine node build.mjs
	docker run --rm -v "${root}:/w" -w /w ziplogger-ci:php8.3-wplatest sh -c "php bin/build-zip.php && php bin/verify-zip.php dist/ziplogger.zip"
} finally {
	Pop-Location
}
