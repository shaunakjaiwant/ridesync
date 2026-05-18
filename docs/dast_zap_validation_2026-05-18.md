# RideSync ZAP DAST Validation

Date: 2026-05-18

## Tooling

- OWASP ZAP 2.17.0 installed locally.
- Eclipse Temurin JRE 17.0.19 installed as the ZAP runtime.
- Target: `http://localhost/ridesync/`
- Mode: unauthenticated ZAP quick active scan.

## Baseline Findings

Baseline report: `qa-artifacts/zap/zap-quick-20260518-175412.html`

The first scan found no high-risk alerts. It did find three medium alerts:

- `Hidden File Found`: `http://localhost/ridesync/.git/config` returned `200 OK`.
- `CSP: style-src unsafe-inline`: CSP allowed broad inline styles.
- `Sub Resource Integrity Attribute Missing`: public templates loaded Google Fonts from a third-party stylesheet URL.

It also reported low-risk Apache version leakage from the local XAMPP server and Unix timestamp disclosure from `filemtime()` cache-busting query strings.

## Fixes Applied

- Root `.htaccess` now blocks all dot-directory URL paths, including `.git/config`.
- Docker Apache hardening now blocks dot directories at the directory level.
- Root `.htaccess` disables Apache error-page signatures for app-controlled error responses.
- Public templates no longer load Google Fonts or unpkg CDN assets.
- Leaflet 1.9.4 is vendored under `assets/vendor/leaflet/1.9.4`.
- CSP no longer uses broad `style-src 'unsafe-inline'`; inline style compatibility is limited to `style-src-attr`.
- Asset cache busters now use 12-character SHA-256 content hashes instead of Unix file modification timestamps.
- Stale authenticated sessions are now validated against the database principal row before page helpers touch dependent tables.

## Regression Protection

- `tests/regression/security_surface_hardening.php`
  - Verifies dot-directory denial configuration.
  - Verifies local Leaflet assets exist.
  - Verifies templates do not reference Google Fonts or unpkg.
  - Verifies asset versions are content hashes, not Unix timestamps.
  - Verifies CSP avoids broad style `unsafe-inline`.
- `tests/regression/session_principal_integrity.php`
  - Verifies stale rider, driver, and admin sessions are evicted before DB-dependent page work.

## Re-Scan Findings

Re-scan report: `qa-artifacts/zap/zap-quick-20260518-181307.html`

The post-fix scan reported:

- High: 0
- Medium: 0
- Low: 2
- Informational: 3

Resolved from the baseline:

- `.git/config` exposure.
- CSP broad `style-src 'unsafe-inline'`.
- External stylesheet SRI alert.
- Unix timestamp cache-buster disclosure.

Remaining low alerts:

- `In Page Banner Information Leak`
- `Server Leaks Version Information via "Server" HTTP Response Header Field`

These remaining lows are emitted by the local XAMPP Apache server for `localhost` root assets and the `Server` response header. RideSync's production Docker Apache config already sets `ServerTokens Prod` and `ServerSignature Off`; fully suppressing the local XAMPP `Server` header requires server-level XAMPP configuration outside the project repository.

## Remaining DAST Work

- Run an authenticated ZAP scan against a staging deployment with seeded rider, driver, and admin accounts.
- Run DAST against the production-like Docker/Apache stack once Docker Desktop is available.
- Attach the ZAP alert threshold to CI/CD so high/medium alerts fail release candidates.
