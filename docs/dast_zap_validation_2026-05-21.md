# RideSync ZAP DAST Validation - 2026-05-21

## Scope

- Target: `http://127.0.0.1:8079/ridesync/`
- Tool: OWASP ZAP 2.17.0
- Mode: unauthenticated quick active scan against a disposable local production-mode PHP target.
- Router: `tools/tunnel_router.php` with production-style deny rules and security headers enabled.

## Result

| Risk | Count |
| --- | ---: |
| High | 0 |
| Medium | 0 |
| Low | 0 |
| Informational | 1 |

The remaining informational alert is ZAP's User Agent Fuzzer signal. No high, medium, or low alerts remained after hardening the local scan router and canonicalizing the rider login role parameter.

## Fixes Verified

- Hidden-file probing for `/ridesync/.git/config` now returns `404` with security headers in the local tunnel/router path.
- Root-level probes such as `/sitemap.xml` now return `404` with CSP and `X-Content-Type-Options: nosniff`.
- Static assets served through the router include CSP, frame denial, referrer policy, and nosniff headers.
- `pages/login.php?role=login.php` redirects to the canonical rider login URL instead of rendering a 200 response for an unexpected role value.

## Evidence

- Final report: `qa-artifacts/zap/zap-final-20260521-103818.html`
- Pre-fix report with local-router findings: `qa-artifacts/zap/zap-router-20260521-102853.html`

`qa-artifacts/` is intentionally ignored because ZAP reports can include local URLs, request payloads, and environment-specific scan details.

## Remaining Release Gate

Run an authenticated ZAP scan against a disposable staging deployment with seeded rider, driver, and admin accounts before public launch. This local pass validates the unauthenticated public surface and scan-router hardening, not every authenticated workflow.
