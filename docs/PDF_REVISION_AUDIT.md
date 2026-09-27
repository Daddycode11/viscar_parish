# Primary PDF revision audit — 28 September 2026

Authority: `For Enhancement (1).pdf`, 14 pages, SHA-256 `8c722eec8a59f89ee82693a56fa7a9e6760c561677068de252521495e31a43ed`. All pages were extracted and visually inspected, including embedded screenshots and captions. This document supersedes earlier completion assumptions, not the original audit history. The five linked SharePoint videos on pages 4, 9, 11, 13 and 14 were inaccessible; no video content is claimed reviewed.

The initial matrix was presented before application edits. Initial baseline: 307 integration assertions passed and 67 browser checks passed on isolated synthetic records. Passing older tests did not establish PDF compliance. Before screenshots of 17 relevant routes at desktop/mobile widths are in `audit/pdf-primary-20260928/before/`; PDF page images and extracted text are beside that directory (local ignored evidence).

Local database inspection was read-only: `vicarparish_local`, six applications, eight `application_attachments` rows, three services. Existing one-to-many attachment storage and legacy document maps remain intact. Functional tests use disposable `vicar_revision_*` databases, never these parish records. The IDE's temporary extracted ZIP is not the workspace source and was not modified.

Status key: C = COMPLETE AND CORRECT (locally verified); P = PARTIALLY IMPLEMENTED; I = IMPLEMENTED INCORRECTLY; M = MISSING; T = NEEDS MANUAL/PRODUCTION TEST. Initial statuses remain recorded below. Updates and limitations follow the matrix.

| ID / PDF | Requirement | Current implementation at audit | Initial status | Files involved | Required fix / verification |
|---|---|---|---|---|---|
| 01 / 1 | White-backed crest | Login uses portrait; other pages use crest | I | public/login.php, public/signup.php, includes/site_settings.php | Use existing crest consistently; preserve custom uploads |
| 02 / 1 | Light welcome/loading screen | Splash still navy | I | loading.php | Cream background, legible navy/gold text |
| 03 / 1 | Recovery design matches system | Bare standalone form | I | includes/recovery_form.php | Reuse authentication styling |
| 04 / 2 | Login top spacing | Panels vertically center and leave large gap | P | assets/css/login.css | Reduce gap; desktop/mobile comparison |
| 05 / 3 | Better Admin request data | Plain summaries; analytics has finance table | P | includes/request_summary.php, financial_summary.php | Clear status labels, no reschedule analytics |
| 06 / 3 | Delivery data presentation | Large table above composer | P | includes/announcement_status.php | Compact expandable section, empty state |
| 07 / 3 | Settings colors affect buttons only | Root theme variables override all pages | I | includes/branding_head.php, settings_page.php | Portal button scope; homepage unchanged |
| 08 / 3 | Audience selector ordering/layout | Long checkbox list | P | admin/announcements.php | Bounded searchable audience list |
| 09 / 3 | Refunds per parish, exclude reschedules | Actual completed refunds by parish | C | includes/financial_totals.php | Preserve, verify periods and totals |
| 10 / 4,7 | Canonical service dropdown plus custom | Shared canonical categories and custom names | C | includes/service_types.php, service_editor.php | Preserve nine categories; no new Holy Orders/Reconciliation |
| 11 / 4 | Accurate cross-parish service demand | Groups by canonical category | C | includes/report_data.php, admin/analytics.php | Add explicit aggregation regression |
| 12 / 4 | Revenue estimate presentation | Unformatted bottom paragraph | P | admin/analytics.php | Distinct estimate metric and methodology |
| 13 / 4 | Remove Admin approve/reject | UI and server deny parish operations | C | includes/access.php, admin/applications.php | Preserve |
| 14 / 4 | Protected central records and downloads | Four approved categories, password gate, protected documents | C | admin/main_database.php, public/document.php | Preserve local tested permissions |
| 15 / 5 | Announcement edit/delete | Handlers exist | T | admin/announcements.php | Persist edit, soft-delete, queued delivery cancellation tests |
| 16 / 5 | Backup and restore | Archive service exists | T | includes/backup_service.php | Isolated database/file round-trip |
| 17 / 5,6,9 | Security checkbox left | Shared CSS rule | T | includes/security_page.php, assets/css/enhancements.css | Inspect actual control bounds |
| 18 / 5,6,10,13 | Round undistorted logo | CSS exists; inconsistent image selection | P | role layouts, enhancements.css | Render checks with actual crest |
| 19 / 6 | Classification in service editor | Shared editor implements it | C | staff/services.php, includes/service_editor.php | Preserve historical classifications |
| 20 / 6,14 | Form edit icons functional | Browser edits quoted labels successfully | C | staff/services.php | Preserve; other editors separately tested |
| 21 / 7 | Profile crop | Circular sidebar; settings fallback plain initial | P | includes/revision_helpers.php, settings_page.php | Shared circle for both photo/initial |
| 22 / 7 | Application/dashboard action parity | Reject/docs/approve shared handler | C | staff/applications.php, dashboard.php | Preserve |
| 23 / 8 | Full submitted answers and document labels | Shared snapshots and grouped attachments | C | includes/application_details.php, attachments.php | Preserve |
| 24 / 8 | Secretary corrections | Blocks all applications with issued certificate | P | staff/application_details.php | Audited answer corrections without mutating issued records |
| 25 / 8 | Parishioners view-only, own parish | Server/UI restrictions tested | C | staff/parishioners.php, includes/access.php | Preserve |
| 26 / 8 | Sacramental detail labels | Shared historical labels | C | staff/record_application.php | Preserve |
| 27 / 8 | QR resolves specific application | Token validation and open action implemented | T | staff/checkin.php | Browser token flow plus physical mobile camera |
| 28 / 8 | User-defined amount | Browser creation/booking and cents checks pass | C | booking workflow, service editor | Preserve |
| 29 / 9 | Financial Overview label | Menu renamed | C | staff/includes/layout.php | Preserve |
| 30 / 9 | Accounting tabs | Responsive tabs | C | staff/accounting.php | Preserve |
| 31 / 9 | Report selector and print PDF | Unified report types; browser PDF generated | C | staff/accounting_report.php, export.php | Preserve |
| 32 / 9 | Bounded Bookkeeper dashboard | Limited datasets and responsive grids | T | staff/dashboard.php | Dense-data screenshot/scroll check |
| 33 / 10 | Check bank/number/20k/particulars/receipt/signatories | Bank/signatories and cap work; number generic optional Reference | P | includes/accounting.php, staff/accounting.php | Required named Check Number, useful form layout |
| 34 / 10 | Petty 500 per entry, same-date receipts, 10k set | Amount/set limits implemented; attachments one per accounting voucher | P | includes/accounting_rules.php, staff/accounting.php | Verify limits; grouped receipt evidence can be PDF |
| 35 / 10 | Secretary replenishes petty cash | Bookkeeper-only action | I | accounting_rules.php, access.php | Dedicated Secretary page, CSRF, parish scope, replay protection |
| 36 / 10 | Deposit bank/amount/source particulars | Implemented with generic labels | P | staff/accounting.php | Source/destination labels |
| 37 / 10 | Journal corrections only check/receipt particulars | Accepts other voucher types | I | includes/accounting_rules.php | Restrict UI and server; original values retained |
| 38 / 11 | Verified Revenue and required net deductions | Shared completed transaction totals | C | includes/financial_totals.php | Preserve; arithmetic regression |
| 39 / 11 | Payment details arrangement | Modal styles need actual comparison | T | staff/payments.php | Open modal at desktop/mobile |
| 40 / 11 | Refund error | Local request/approval/completion passes | C | includes/request_workflows.php | Linked error recording unavailable; production unverified |
| 41 / 11 | Circular default initial | Settings unframed initial | I | revision_helpers.php, settings_page.php | Reusable circular avatar |
| 42 / 12 | Remove redundant application panel | Still present, unfiltered | I | parishioner/dashboard.php | Remove; retain table details/QR |
| 43 / 12 | Remove duplicate help interface | Sidebar routes to separate FAQ chat | I | parishioner/includes/layout.php | Use existing Messages as primary route; preserve old conversations |
| 44 / 12 | Arrange parish preferences | Long fieldset stack | P | includes/settings_page.php | Responsive bounded grid |
| 45 / 13 | Booking contrast | Some overrides, no screenshot-specific proof | T | booking page, enhancements.css | Inspect selected/unselected services |
| 46 / 13 | Requirement notes alignment | Shared wrapped text panel | T | booking page, enhancements.css | Compare populated notes |
| 47 / 13 | Dashboard filters apply datasets | Queries filter, redundant panel does not | P | parishioner/dashboard.php | Remove bypass panel; populated empty-period assertion |
| 48 / 13 | Available/full/red date calendar on mobile | Shared calendar and capacity endpoint | T | assets/js/availability-calendar.js | Real full-day state and browser selection |
| 49 / 14 | Bordered request tabs | Shared request links CSS | C | includes/requests_page.php, enhancements.css | Preserve |
| 50 / 14 | Calendar filter and scheduled bookings | Events plus owned bookings | C | parishioner/events.php | Preserve; explicit boundary checks |
| 51 / 14 | Announcement readability | Adjacent plain articles | P | includes/announcements_page.php | Separate cards, readable date/body |
| 52 / 14 | Cancel pending/approved with reason and notification | State changes and history tested | C | includes/request_workflows.php | Keep checked-in/closed protection; include Cancel in navigation |
| 53 / 14 | SMS functioning | Provider transport implemented, live delivery unknown | T | includes/notifications.php, transport helpers | Mock run; live provider/phone remains manual |
| 54 / 14 | Remaining edit icons | Form editor tested; announcement/settings/schedule require coverage | P | role editor pages | Targeted interaction tests |
| 55 / additional | Multiple requirement attachments | Normalized table; 27 integration checks pass | C | includes/attachments.php, upload/document handlers | Browser remove-before-upload, legacy preservation |
| 56 / additional | Shared accessible password visibility | Shared component present | T | password_visibility include/CSS/JS | Rerun desktop/mobile, validation and Enter checks |

## Implementation batches

Batch 1 in progress: accounting authorization and journal restrictions, explicit Check Number, approved application answer corrections. No database migration: existing columns/tables are sufficient. Existing accounting records, attachments and certificates are preserved. Secretary replenishment closes a set and records an audit entry; it does not move money or grant other accounting privileges.

## Verification limits

No production database, live SMS/email delivery, physical camera/printer or inaccessible linked video is treated as verified. No GitHub push, deployment preparation or live deployment is part of this revision.
