# RideSync Manual Screen Reader Test Plan

Date: 2026-05-18

## Goal

Validate that RideSync's rider, driver, and admin workflows are usable without sight and without relying on visual-only status cues.

## Required Matrix

| Platform | Browser | Screen Reader |
|---|---|---|
| Windows | Firefox or Chrome | NVDA |
| macOS | Safari | VoiceOver |
| iPhone | Safari | VoiceOver |
| Android | Chrome | TalkBack |

## Global Checks

- Page title and first heading identify the current task.
- Keyboard focus moves in a sensible order.
- Focus is visible and not trapped.
- Inputs have useful accessible names and error messages.
- Buttons and links announce the action, not only an icon or color.
- Status badges have text equivalents.
- Tables or repeated records announce row context clearly.
- Drawer, menu, and modal-like controls announce expanded/collapsed or selected state when applicable.
- Live updates do not interrupt reading unexpectedly.
- Form errors are announced or reachable immediately after submit.

## Rider Flow

1. Register, log in, and log out.
2. Update profile and submit student verification.
3. Search rides with pickup and destination.
4. Post a ride using route shortcut and manual entry.
5. Open ride detail and understand live status steps.
6. Send, cancel, accept, or reject a match request where applicable.
7. Read, mark, and clear notifications.
8. Submit a report and rating from an eligible ride.

Pass condition: the tester can complete every flow without visual assistance and without guessing unlabeled controls.

## Driver Flow

1. Register and log in.
2. Complete profile and vehicle details.
3. Upload required documents.
4. Understand verification status and missing requirements.
5. Toggle availability with location allowed and denied.
6. Accept, reject, and complete direct requests.
7. Claim a community ride.
8. Review trip history and earnings.

Pass condition: busy/offline/verified states are understandable from announced text, not color alone.

## Admin Flow

1. Log in and navigate each dashboard section.
2. Use global search and filter controls.
3. Open detail drawers and return to the table context.
4. Review driver verification status, documents, confidence, flags, and audit history.
5. Approve/reject/escalate verification using keyboard only.
6. Triage reports and verify audit log entries.
7. Confirm restricted controls are absent or disabled for lower admin roles.

Pass condition: tables, drawer content, destructive actions, and verification decisions are discoverable and reversible where expected.

## Evidence Template

| Date | Build/Commit | Platform | Browser | Screen Reader | Tester | Flows Passed | Bugs Found | Evidence |
|---|---|---|---|---|---|---:|---:|---|
| YYYY-MM-DD | commit SHA | Windows 11 | Firefox | NVDA | name | 24/24 | 0 | notes/video/path |

## Bug Severity

- Critical: a core flow cannot be completed with the screen reader.
- High: unlabeled or misleading control can cause wrong account, ride, payment, or verification action.
- Medium: flow is completable but inefficient, confusing, or missing state announcements.
- Low: wording or focus polish issue that does not block completion.

## Exit Criteria

- 0 critical or high screen-reader bugs.
- All rider, driver, and admin core flows pass on at least one desktop and one mobile screen reader.
- Any remaining medium issues have tracked follow-up work.
