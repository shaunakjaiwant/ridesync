# RideSync Physical Device And Safari Test Plan

## Required Devices

- Android phone, current stable Chrome.
- Android phone, low-memory or older OS profile if available.
- iPhone, current iOS Safari.
- iPhone, previous major iOS Safari if available.
- iPad Safari or tablet-sized browser.
- Desktop Safari on macOS.

## Required Network Conditions

- Wi-Fi.
- 4G/5G.
- Throttled 3G or high-latency network.
- Offline transition during form submit and ride state polling.

## Core Flows

1. Rider registration, login, logout.
2. Rider profile update and profile photo upload.
3. Rider ride search with long pickup/drop text.
4. Rider ride posting and cancellation.
5. Rider match request, cancel request, notification read/clear.
6. Driver registration, login, profile update.
7. Driver document upload with JPG, PNG, and PDF.
8. Driver availability toggle with permission allowed and denied.
9. Driver request accept/reject/complete.
10. Admin login, global search, table filters, drawer open/close.
11. Admin driver verification detail, document preview signed URL, AI status polling.
12. Admin report triage and audit log review.

## Interaction Stress

- Rapid double tap on submit buttons.
- Browser back/forward during form workflows.
- Rotate device during ride detail and admin verification screens.
- Background and foreground the browser during polling.
- Deny location permission, then retry.
- Upload oversized or invalid file.
- Use long names, long addresses, and high zoom text size.

## Accessibility Checks

- Keyboard navigation on desktop Safari.
- iOS VoiceOver labels for navigation, forms, and admin action buttons.
- Android TalkBack labels for major rider/driver controls.
- Text size at 125% and 150%.
- Color contrast in light mode under outdoor brightness.

## Evidence Template

Record one row per device/browser:

| Device | OS | Browser | Build/Commit | Flows Passed | Bugs Found | Screenshots/Video | Tester | Date |
|---|---|---|---|---:|---:|---|---|---|
| iPhone 15 | iOS 18.x | Safari | commit SHA | 12/12 | 0 | link/path | name | YYYY-MM-DD |

## Exit Criteria

- 0 critical/high device-specific bugs.
- 0 blocked core flows.
- 0 reproducible layout overflows on supported viewports.
- 0 uncaught browser console errors during sampled flows.
- All upload and signed-document-preview flows verified on at least one Android and one iPhone.
