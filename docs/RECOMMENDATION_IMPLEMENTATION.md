# Recommendation.pdf implementation

Reviewed 5 October 2026. Source: the four-page `Recommendation.pdf` supplied by the user. The document was treated as product feedback; the user's request authorized implementation and a Git push.

| Recommendation | Implementation / verification |
| --- | --- |
| Parish names in messaging | Compose searches active parishes and routes to an assigned secretary; conversation labels use the parish name. |
| Review & Resubmit | Updated the parishioner navigation and document-page title. |
| Brighter booking dates and legend | Green, red and grey date buttons have labeled swatches and accessible date/status labels. |
| Available and scheduled calendars | Both parishioner and secretary calendars offer separate views. Availability is service-specific, uses aggregate counts, and includes time slots. Scheduled summaries show time and service/event title, with details on selection. |
| Mobile keyboard and readability | Visual viewport changes keep focused inputs visible; dialogs resize, mobile inputs use 16px text, and dashboard labels are larger. |
| Acknowledgement receipts | Updated receipt screens, printable receipts and accounting labels. Existing receipt numbers remain intact. |
| Export filters and service column | One report date-filter form; service names in the report and CSV; revenue/refund status filters now apply. |
| Refund approval | Existing role checks retained and tested: only bookkeepers review refunds; approval and actual refund completion are separate. |
| Service weekdays, times and capacity | Secretary can configure fixed HH:MM slots or user-defined time, weekday restrictions, per-time capacity, and daily capacity. Zero capacity limits mean unlimited. Booking and rescheduling enforce limits under the existing service-row transaction lock. Existing services retain their previous behavior. |
| Service create/edit failures | Invalid submissions keep the editor open with entered values; fixed slots and limits round-trip through create/edit. Database migration adds the required columns. |
| Approved application rescheduling | Existing permission retained and tested; linked record dates stay synchronized. Requests snapshot their original schedule and show old/new times. Legacy requests without a snapshot are explicitly labeled. |
| Reschedule/cancellation review | Requests remain subject to secretary approval. The original booking stays active until approval. |
| Login terms/privacy and rate limiting | Linked portal Terms of Use and Data Privacy Notice; three consecutive incorrect passwords block the email/IP pair for five minutes in database storage, including across new sessions. Successful login resets the counter. |
| Icons, palette and dashboard shortcuts | Existing SVG icons retained; green/gold palette follows the supplied project logo. Dashboard cards link to their relevant lists. Existing bounded dashboard previews are retained. |
| Trends | Accessible SVG monthly line charts with service filtering and exact-value tables; application counts for operational roles and verified revenue for bookkeepers. Up to 12 months of the selected period are shown. |
| QR codes and uploaded documents | Existing local QR image/verification and authorized application download routes regression-tested. New bank QR and payment-proof images use authenticated routes; proof access is limited to its owner and assigned-parish bookkeeper. |
| Automatic refresh and search | Existing dashboard polling retained. List pages refresh when idle, with no edited form or open dialog. GET-form search fields submit after a typing pause and restore focus. |
| Phone validation and SMS | Exact Philippine mobile-number validation on signup, profile, walk-in accounts and SMS transport. Alphabetic characters and extra/missing digits are rejected. Mock SMS/email provider acceptance, rejection and missing configuration are tested. |
| Complete update messages | Application submission, approval/rejection, rescheduling and payment verification messages include application, service, status and schedule details; rejection reasons and request review notes are included. |
| Manual digital payments | Secretary Settings links to payment-method management. Each digital method has a bank-generated QR and instructions; application/retry/walk-in payments require a reference and proof file. Submitted payments remain pending until bookkeeper verification. Bank names are snapshotted on payments. Cash remains available. |
| Vicariate name and homepage announcements | Existing Vicariate naming preserved. Public parish announcements now appear on the homepage; staff-only announcements remain excluded. |

## Database and deployment

`tools/migrate_recommendations.php` is additive and rerunnable. It adds service scheduling fields, original request schedules, manual payment metadata and the parish payment-method table. The normal local setup and guarded production migration include it; deployment schema checks and the release file manifest include the additions. Backup/restore includes referenced bank QR and payment-proof files.

The configured local database was backed up to protected storage before migration. User, service, application and payment row counts were unchanged. No production deployment or real payment operation was performed.

For an existing production installation, back up its database and use the repository's guarded `tools/migrate_production.php` workflow in maintenance mode before serving the updated code. Pushing source to Git does not apply the production database migration.

## Verification and deployment limits

- Integration: 425 checks passed, including the new slot, payment-proof, lockout, phone, calendar and report cases.
- Transport: 14 checks passed against loopback mock providers; no messages were sent to real recipients.
- Backup/reconciliation: 11 checks passed on synthetic data.
- PHP syntax: 163 files passed.
- Browser checks exercise service create/edit, fixed-time selection, parish compose search, report filters, dashboard links, and 1440px/390px layouts. Results are recorded in ignored local evidence under `revision-evidence/recommendations/`.

Parishes must enter their actual office hours and upload their approved bank QR images; no financial account details or office hours were invented. Production SMS credentials, provider balance/sender settings and actual handset delivery require installation-specific verification. Camera scanning depends on browser support and a secure origin; existing paste/USB-scanner fallback remains available. Booking QR links use configured `APP_URL`, which must be reachable from the scanning device. Physical mobile keyboards were not tested on real devices. The portal notices describe implemented behavior and should be reviewed by the parish for its retention/contact policies before production use.
