# Refund and reschedule revision audit

The pre-change audit was presented in the conversation before source changes. This file records those findings. The user's pasted A–O requirements define the task; the two-page PDF provides the requirements summary and accounting interface examples.

| Area | Before revision | Relevant code and finding |
|---|---|---|
| Request permissions | Partial | `includes/request_workflows.php`, `includes/requests_page.php`, role layouts: staff lists were filtered, but Admin could review requests and had management links. |
| Help / FAQ | Implemented, insufficient discoverability | `parishioner/help.php`, `parishioner/faq.php`, `includes/help.php`: real FAQ matching and Secretary handoff existed. Preserve messaging; add practical guidance in FAQ. |
| Profile synchronization | Broken | Three separate settings upload handlers; `currentUser()` omitted `profile_picture`; sidebars rendered initials; some settings pages rendered layout before saving. |
| Homepage settings | Partial | `index.php` read `site_settings`; Admin `save_system` was a TODO that still displayed success. |
| Language | Missing | No shared translation catalog or persistent account language preference. |
| Accounting | Partial | `staff/receipts.php`, `public/receipt.php`: existing official receipts. No voucher/disbursement/deposit model. |
| Export | Partial | `staff/export.php`: existing payment reports, but payment-method queries referenced a nonexistent `method` column. No new document report types. |
| Walk-in | Missing | Reusable online booking validation in `includes/workflows.php`, but no Secretary entry workflow or application provenance. |
| Automatic sacramental record | Missing | `staff/records.php` created records manually. Approval did not create linked records. No explicit sacrament classification on services. |
| Application QR | Partial | Local QR encoder and public verification existed; no persistent visible application detail/print page in the dashboard. |
| Forgot password | Broken | Login linked to a nonexistent `public/forgot_password.php`. |
| Announcements | Partial | Existing provider configuration and transports; missing configuration could return success. No durable recipient/channel delivery ledger or submission idempotency. |
| Parish targeting | Partial | Targeting used account parish, without user-selected subscriptions or multi-parish preferences. |
| Bookkeeper quick actions | Broken | Links to Secretary-only announcements and applications. |
| Sensitive password verification | Missing | Role/parish guards existed, but no short-lived password re-verification. |

## Schema inspection

Inspected both SQL schema/migrations and live `information_schema` in `vicarparish_local`. Existing `users.profile_picture`, `site_settings`, `application_requests`, `receipts`, `applications.form_data`, `applications.uploaded_files`, `applications.qr_code`, and sacramental certificate fields are reused. No duplicate equivalents were added.

Required additions: account language and credential version; application source/creator; explicit service sacrament type; accounting documents; reset token hashes; durable rate limits; parish subscriptions; announcement parish targets, submission keys, and channel delivery rows.

Implementation order: additive migration and shared security helpers; role separation and settings; accounting; walk-in and linked records; recovery and announcements; isolated regression/provider/browser verification.

Local MySQL was initially stopped and was started for inspection/testing. Production provider credentials were not supplied. Local simulation and mock-provider tests must not be reported as live delivery.
