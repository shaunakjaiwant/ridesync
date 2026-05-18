# RideSync DAST Security Test Plan

Date: 2026-05-18

## Goal

Run repeatable dynamic application security testing against a disposable staging environment and keep evidence that the rider, driver, admin, API, upload, verification, and realtime surfaces do not expose critical or high-risk web vulnerabilities.

## Scope

In scope:

- Public pages under `/ridesync/`.
- Rider pages, actions, JSON APIs, notification mutations, ride search, ride posting, match lifecycle, reports, ratings, and profile upload.
- Driver pages, actions, document upload, availability, request lifecycle, and trip completion.
- Admin dashboard, driver verification, user/ride/report moderation, global search, and audit views.
- JSON endpoints under `/ridesync/api/*.php`.
- SSE endpoints under `/ridesync/api/events.php`, `/ridesync/api/admin_events.php`, and `/ridesync/api/driver_verification_events.php`.

Out of scope unless the environment is disposable and seeded for deletion testing:

- Permanent account removal of non-test accounts.
- Production payment, KYC, SMS, email, or third-party provider side effects.
- Destructive database maintenance and backup restore commands.

## Prerequisites

- A staging URL in `RIDESYNC_BASE_URL`.
- Seeded disposable accounts for rider, driver, and super admin.
- `RIDESYNC_DEBUG=false`.
- Production-like security headers enabled.
- Test uploads should use non-sensitive sample files.
- A fresh database snapshot that can be restored after active scanning.

## Baseline Scan

Run this first for a passive crawl:

```bash
mkdir -p qa-artifacts/zap
docker run --rm -t \
  -v "$PWD/qa-artifacts/zap:/zap/wrk:rw" \
  ghcr.io/zaproxy/zaproxy:stable \
  zap-baseline.py \
  -t "$RIDESYNC_BASE_URL" \
  -r zap-baseline.html \
  -J zap-baseline.json \
  -x zap-baseline.xml
```

Pass condition: 0 high alerts, 0 critical alerts, and every medium alert has an owner and disposition.

## Authenticated Scan Flow

Record login journeys with ZAP HUD or browser proxy for each role:

- Rider login, dashboard, search, post ride, ride detail, notifications, profile.
- Driver login, dashboard, requests, profile, document upload, history, earnings.
- Admin login, dashboard sections, search, driver verification detail, report triage, audit log.

Use a ZAP context limited to the staging host and path:

```text
^https?://[^/]+/ridesync/.*
```

Exclude logout URLs during crawling when session persistence is needed. Run destructive form actions only against disposable test entities.

## Active Scan

Use active scan only on staging or disposable local environments:

```bash
mkdir -p qa-artifacts/zap
docker run --rm -t \
  -v "$PWD/qa-artifacts/zap:/zap/wrk:rw" \
  ghcr.io/zaproxy/zaproxy:stable \
  zap-full-scan.py \
  -t "$RIDESYNC_BASE_URL" \
  -r zap-full.html \
  -J zap-full.json \
  -x zap-full.xml
```

## Manual Abuse Cases

Verify these by hand or through ZAP request replay:

- Missing CSRF on rider, driver, and admin POST actions is rejected.
- Cross-origin POST with a hostile `Origin` is rejected.
- Unauthenticated API access returns 401 or 403 without stack traces.
- A rider cannot fetch another rider's live ride status.
- A driver cannot accept overlapping trips.
- A moderator cannot perform super-admin account status or removal actions.
- Upload endpoints reject oversized, invalid MIME, executable, and polyglot files.
- Signed driver document URLs expire and cannot be forged by changing the document id.
- Search inputs escape reflected script payloads.
- Rate-limited endpoints return 429 without revealing internals.

## Evidence

Store artifacts under `qa-artifacts/zap/`:

- HTML report.
- JSON report.
- XML report.
- Authentication notes and test account ids.
- False-positive disposition table.

Evidence table:

| Date | Build/Commit | Target | Scan Type | Critical | High | Medium Open | Owner | Evidence Path |
|---|---|---|---|---:|---:|---:|---|---|
| YYYY-MM-DD | commit SHA | staging URL | Baseline/Auth/Active | 0 | 0 | 0 | name | qa-artifacts/zap/... |

## Exit Criteria

- 0 critical or high findings.
- All medium findings are fixed, accepted with a written rationale, or converted into tracked work.
- No sensitive sample documents, identifiers, tokens, stack traces, or SQL errors appear in scanner responses.
- Scanner session cookies and generated reports are not committed.
