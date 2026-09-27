# Explanation of revisions and changes

## Purpose and scope

The revision completes missing workflows and repairs verified defects in the existing parish platform. It retains the PHP/MySQL structure, current page layouts, working parish and service selectors, dashboard tables, password hashing, and session authentication.

Unchecked PDF requirements were investigated before implementation. An unchecked item was not automatically treated as missing. The original audit classified 49 details as 6 Already Working, 34 Partially Implemented, 8 Not Implemented, and 1 Unable to Verify. The original audit remains separate from the post-revision checklist so reviewers can compare findings without losing the baseline.

The main change is that a visible interface now has stronger supporting processing: the server checks the user's role, record ownership, parish, required input, and current database state before accepting a workflow change.

## Functional changes

| Area | Problem found before revision | Revised behavior and reason |
|---|---|---|
| Parish information | The event page had broken dependencies; mass schedule maintenance was missing and absent end times displayed incorrectly. | The calendar reads stored parish events. Secretaries can maintain mass schedules, and parishioner information reflects saved data. |
| Booking | Required fields, service/parish relationships, and capacity could be bypassed by submitting requests directly. | Submission validates the selected active parish/service, future schedule, required dynamic fields and documents, and available capacity inside a transaction. This prevents the interface from being the only validation layer. |
| Payment | Some paths accepted another user's application, incorrect amounts or methods, repeated confirmation, or left application payment status stale. | Shared processing checks ownership, the booking's stored fee, payment method/reference, and payment state. Bookkeeper confirmation updates the payment and application together. |
| Booking QR | A QR image did not provide a complete booking-verification and attendance workflow. | A local QR contains a verification URL with application ID, parish ID, and a random token. The verifier checks these values; authorized event-day check-in records the actor and timestamp once. |
| Refund/reschedule | Request storage and complete review flows were absent. | Parishioners submit requests; the responsible staff role reviews them. Rescheduling rechecks availability. Refund approval and recording money returned are separate steps. |
| Additional documents | Staff could request documents without a complete parishioner submission path. | Requests persist, notify the applicant, appear under Requested Documents, and accept a private upload that staff can download with the application. |
| Certificates and receipts | Receipt persistence failed on a binding error; certificate access and public authenticity checks were incomplete. | Receipt issuance persists an official number for a completed payment. Certificate generation uses authorized records and existing numbering/templates, with a public authenticity endpoint. |
| Announcements | Existing audience selection worked, but authorization and subsequent reader visibility were inconsistent. | Existing targeting is preserved, with authenticated publishing and audience-aware reading. Staff-only announcements are hidden from parishioners. |
| Dashboards/reports | Several administrator figures were demonstration values; staff financial scope was inconsistent. | Dashboard aggregates and report exports use database records. Parishioner status refresh polls every five seconds. Zero-revenue comparisons render without division errors. |
| FAQ and messaging | FAQ search and messaging existed separately, without automatic answers or handoff state. | Conservative word matching selects an active FAQ from the chosen parish. Unmatched questions notify the secretary. Automated replies stop during staff handling and resume after staff resolves the inquiry. |
| Login security | Administrative guards were incomplete; suspended sessions could remain usable; the login OTP stub was disconnected. | Shared access checks verify active accounts and roles. Optional email OTP adds a second login step with expiry, attempt limits, and replay prevention. |
| Notifications/reminders | Transition notifications were inconsistent; no scheduled reminder runner existed. | Revised transitions use shared notification helpers. A command-line runner records reminders for approved, unchecked-in bookings due within 24 hours, once per booking/schedule. |

GCash support records a submitted transfer reference for manual verification. It does not initiate a transfer or automatically receive a provider webhook. Cash submissions also remain pending until a bookkeeper verifies receipt of payment.

## How the main booking flow fits together

1. The parishioner selects a parish, service, schedule, and required information/documents.
2. The server validates the complete submission and locks the relevant service record while checking capacity.
3. A pending application is stored with a fee snapshot and secure QR token. Notifications and actor logging are recorded.
4. The applicant may submit a cash payment intention or GCash reference. This creates a pending payment record.
5. The secretary reviews the application; the bookkeeper independently verifies payment. Approval and payment status are separate facts.
6. A completed payment can receive an official receipt. The applicant sees saved statuses in their dashboard.
7. On the scheduled day, the assigned parish secretary validates the QR and records check-in for an approved booking.

A transaction groups related database changes so an error can roll them back together. External SMS/email dispatch occurs after committed workflow changes where the shared transaction routes apply. Successful database processing does not prove that an external provider delivered a message.

## Access and document protection

The shared access layer enforces administrator, secretary, bookkeeper, and parishioner responsibilities before affected page handlers run. Staff operations are limited to their assigned parish; parishioner application/payment/document operations are limited to owned records. State-changing requests require a session verification token, also called a CSRF token.

Uploads accept validated PDF, JPG/JPEG, or PNG content up to 5 MB. The server checks file content as well as the extension, stores files with random names in private storage, and serves them through an authorized download endpoint. Local Apache checks confirmed that configuration, SQL, and private storage cannot be downloaded directly.

Passwords continue to use one-way password hashing. Session cookies are hardened, login regenerates the session ID, and active account status is checked again on access. Two-factor login uses email codes; its attempt controls are session-based. Deployment-level rate limiting remains a follow-up.

## Database and configuration changes

The migration files extend existing tables and create supporting tables without deleting existing application records. The local compatibility migration also accounts for differences found between the supplied schema and the machine's existing database.

| Table/change | Purpose |
|---|---|
| `users.two_factor_enabled` | Stores the user's optional second-factor preference. |
| `applications.fee_snapshot` | Stores the booking fee used for later payment validation. |
| `applications.checked_in_at`, `checked_in_by` | Records attendance time and responsible staff member. |
| `application_requests` | Stores refund/reschedule requests, review decisions, actor references, notes, and timestamps. |
| `application_document_requests.submitted_at` | Records that an additional-document request has received a submission. |
| `messages.is_bot` | Distinguishes automated FAQ replies from human messages. |
| `help_conversations` | Tracks the selected parish, assigned secretary, and whether staff handling is active. |
| `reminder_deliveries` | Prevents duplicate reminders for the same application and schedule. |
| `site_settings` | Supplies a settings table expected by the existing homepage. |
| Compatibility fields/tables | Adds missing announcement actor storage and staff audit storage, and includes pending account status. |

For existing bookings whose fee snapshot is empty, the migration copies the service's current fee. That backfill cannot reconstruct a historical fee if the service price had already changed; older financial records should be reviewed accordingly.

Local settings are kept in the ignored `config.local.php`, separate from reusable configuration helpers. Environment variables can override them for isolated tests. Local mode logs simulated email/SMS to protected storage. No credentials or real OTP values are included in these documents.

## Implementation map

| Responsibility | Main files |
|---|---|
| Login, active sessions, OTP | [auth.php](../includes/auth.php), [verify_otp.php](../verify_otp.php), [security_page.php](../includes/security_page.php) |
| Role/parish/owner guards and CSRF | [access.php](../includes/access.php) |
| Shared booking, payment, receipt, certificate transitions | [workflows.php](../includes/workflows.php), [workflow_routes.php](../includes/workflow_routes.php) |
| Refund/reschedule processing | [request_workflows.php](../includes/request_workflows.php), [requests_page.php](../includes/requests_page.php) |
| Additional documents and authorized downloads | [document_workflows.php](../includes/document_workflows.php), [documents.php](../parishioner/documents.php), [document.php](../public/document.php) |
| QR rendering, public verification, attendance | [qr.php](../includes/qr.php), [public/qr.php](../public/qr.php), [verify.php](../public/verify.php), [checkin.php](../staff/checkin.php) |
| FAQ selection and staff conversation | [help.php](../includes/help.php), [parishioner/help.php](../parishioner/help.php), [messages.php](../staff/messages.php) |
| Real aggregates and exports | [analytics.php](../includes/analytics.php), [report_data.php](../includes/report_data.php), [reports.php](../admin/reports.php) |
| Notifications and reminder runner | [notifications.php](../includes/notifications.php), [reminders.php](../tools/reminders.php) |
| Schema and local installation | [migration_revisions.sql](../database/migration_revisions.sql), [migration_local_compatibility.sql](../database/migration_local_compatibility.sql), [setup_local.php](../tools/setup_local.php) |

This is a responsibility map, not an exhaustive list of edited files. The [49-item report](../REVISION_REPORT.md) links affected pages and evidence for each audited requirement.

## Recorded verification

| Evidence | Recorded result | What it establishes |
|---|---|---|
| [HTTP/database tests](../revision-test-results.json) | 114 passed, 0 failed | Role access, persistence, rejected invalid operations, booking/payment transitions, document requests, reports, handoff, reminders, and OTP behavior on synthetic data. |
| [Browser tests](../revision-evidence/browser-results.json) | 4 passed; no uncaught JavaScript errors | Actual browser login, seven-step booking, pending cash payment with database read-back, and navigation through revised pages. |
| [PHP lint](../revision-lint-results.json) | 100 files; no syntax failures | Parsed PHP files contain no detected syntax errors. |
| [Apache checks](../revision-apache-results.json) | 6 passed | Login is reachable and selected sensitive paths return HTTP 403 locally. |

Tests used separate synthetic schemas with users across two parishes. They did not exercise production records or real money transfers. Screenshots, including [booking confirmation](../revision-evidence/booking-confirmation.png), supplement processing evidence; they are not standalone proof of completion.

## Remaining acceptance and deployment work

- Literal JWT authentication is not implemented. The working PHP session architecture was retained; the literal PDF requirement remains open.
- Production financial-storage encryption, transport configuration, and key management remain Unable to Verify without infrastructure evidence.
- Live SMS/email delivery, failure retries, and deployment scheduler execution remain unverified. The reminder runner exists, but no operating-system schedule was installed.
- Physical scanner/camera capture, receipt printing, and parish approval of certificate templates/content remain unverified. Existing print/save-to-PDF output is retained.
- Arbitrary parish-specific form-to-certificate mappings require acceptance with actual service definitions. Known fields are supported.
- Simultaneous multi-worker load was not stress-tested, and exhaustive logging of every legacy administrative edit is not certified.

Use [LOCAL_SETUP.md](../LOCAL_SETUP.md) for startup and migration commands. These open items remain visible in the revision checklist rather than being marked complete from local page rendering.
