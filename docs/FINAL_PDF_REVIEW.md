# Final PDF review — 28 September 2026

Reference: `For Enhancement (2).pdf`, 15 pages. All page images, screenshots, arrows and captions visually reviewed before edits. Rendered evidence is in `audit/pdf-final/`. Earlier audits refer to a different PDF and are not completion evidence for this review.

## Initial remaining-issues audit

| PDF pages | Requirement | Initial classification | Evidence / action |
|---|---|---|---|
| 1 | White crest/navigation; recovery styling | PARTIALLY CORRECT | Inspect existing assets; recovery is still a bare form |
| 2, 5, 7 | Dashboard Back removal; compact return links; header/filter order | LAYOUT/UI ISSUE | Shared navigation explicitly sends dashboards home; staff filter precedes header |
| 2 | Analytics without finance block | LAYOUT/UI ISSUE | Financial summary still included |
| 2 | Central database compact filters/details; fetching | PARTIALLY CORRECT | Stacked filters; queries restrict to approved canonical sacramental types; test fetching |
| 3 | Finance header, parish table, totals, compact period | LAYOUT/UI ISSUE | Summary precedes header; only refunds grouped by parish |
| 3 | Backup failure | NEEDS MANUAL TEST | Reproduce on synthetic data before changing backup logic |
| 4 | Announcement heading, editing layout, external delivery | PARTIALLY CORRECT | Delivery table precedes heading; delivery code exists, live providers unverified |
| 4 | View Admin application | NEEDS MANUAL TEST | Existing AJAX handler/modal; exercise browser |
| 4, 12 | Additional audit report types | MISSING | Admin selector lacks refunds and accounting types |
| 5, 10 | Bounded dashboard datasets | NEEDS MANUAL TEST | Existing limits/grids; inspect all target widths |
| 5 | Request Docs label; application correction shortcut | PARTIALLY CORRECT | Request button says Documents; correction form exists |
| 5, 6 | One modal close; edit requirements | FUNCTIONAL ISSUE | Navigation injects duplicate close; requirements support add/delete only |
| 6, 7 | Parish members include applicants/subscribers; reply | FUNCTIONAL ISSUE | Member list uses home parish only; reply denies cross-home-parish applicants |
| 6 | Existing action colors; remove duplicate sponsors editor | PARTIALLY CORRECT | Existing act-navy/gold/wine classes; duplicate Sponsors field remains |
| 7 | Documents action; compact message back | NEEDS MANUAL TEST | Trace actual destination and existing modal behavior |
| 7, 15 | Secretary confirms cancellation; active request tab | FUNCTIONAL ISSUE | Cancellation completes immediately; tabs lack active indication |
| 8, 9 | Full answers/document labels; Secretary corrections | PARTIALLY CORRECT | Shared renderer/correction exists; legacy schema fallback needs inspection |
| 8, 9 | Read-only parishioners and parish scope | CORRECT — NO CHANGE | No edit/suspend permission; retain scoped application visibility |
| 9 | Record labels, QR, user-defined amount | NEEDS MANUAL TEST | Existing implementations; run regression and QR browser flow |
| 9–11 | Financial menu, accounting tabs/export, checkbox/logo | PARTIALLY CORRECT | Existing components; visual checks required |
| 11 | Voucher limits, bank/check/signatories, petty replenishment, journal restrictions | CORRECT — NO CHANGE | Existing validated rules; rerun regressions |
| 11 | Verified/net arithmetic | CORRECT — NO CHANGE | Shared financial_totals implementation; preserve calculations |
| 11, 12 | Payment details/refund error | NEEDS MANUAL TEST | Existing workflow; reproduce with isolated records |
| 12 | Circular initial avatar | LAYOUT/UI ISSUE | Settings wraps plain initial without avatar class |
| 12–14 | Redundant application/help panels; preferences; booking contrast/notes | PARTIALLY CORRECT | Inspect current pages; preserve existing messaging and booking logic |
| 14 | Dashboard date scope, capacity calendar, event bookings | NEEDS MANUAL TEST | Existing filters/calendar; regression and responsive browser checks |
| 14 | Announcement readability | LAYOUT/UI ISSUE | Adjacent unseparated articles |
| 15 | SMS and editing icons | NEEDS MANUAL TEST | Provider delivery requires live verification; exercise local editors |

No production changes or deployment authorized. No application database migration planned. Runtime checks use synthetic `vicar_revision_*` records with external sends disabled.

## Corrections implemented

- Public pages: retained the official white-backed crest with contained proportions; styled password recovery using the existing authentication stylesheet. Fixed homepage narrow-screen navigation and animated-content overflow. The subsequent user commits introduced a shared white login/signup header; final verification includes those changes.
- Shared navigation: dashboards no longer render a return-home control; other pages use compact accessible arrows. Existing modal close buttons are reused, including dynamically loaded content. Admin pages now have the missing modal open/close helpers.
- Dashboards and filters: Secretary heading precedes its date controls; filters use one wrapping shared bar. Removed the redundant, unfiltered Parishioner application-link panel while retaining application details and QR access in the main table. Existing bounded dashboard queries remain intact.
- Admin Analytics/Finance: removed the finance block from Analytics while retaining independent date filtering. Finance now places its heading and summary cards first, followed by period totals and the requested parish-by-parish financial columns. Replaced the verified hardcoded monthly chart and percentage claim with values from the existing financial totals helper; no financial formula changed.
- Central records: compact responsive filter bar. Existing approved-record, canonical-service, password-verification and document-access restrictions remain in place.
- Announcements: heading first, collapsed delivery-status details with a bounded table, aligned editing forms, and separated readable Parishioner announcement articles. Channel selection, subscriptions and delivery processing remain unchanged.
- Admin applications: verified missing modal helpers and an inappropriate flex container caused viewing problems. Password verification now occurs before the application page; details flow vertically. Applied the same confirmed container correction to Bookkeeper payment details.
- Secretary services: added in-place requirement editing with CSRF validation, parish ownership checks and audit logging. Existing application snapshots retain their historical requirement labels. Removed duplicate modal close controls.
- Secretary applications/records: renamed the document-request action to **Request Docs**, added a correction shortcut, and supplied the existing service schema for legacy applications without a saved schema. Approved, unchecked-in applications remain correctable without changing issued certificates. Removed the duplicate Sponsors field from record editing while preserving its stored value. Renamed the misleading Documents return action to **Back to records**.
- Parishioners/messages: a shared membership predicate includes home-parish members, applicants and active channel subscribers. Secretary lists and reply authorization use that association without transferring the user's home parish or exposing another parish's application history. Unrelated users remain excluded; account editing/suspension remain forbidden.
- Requests: cancellation remains pending until the assigned Secretary approves it, as specified on page 7. Approval cancels the application and archives its linked record; rejection preserves the booking. Payment state is unchanged and refunds remain separate. Request notifications target the correct tab; active tabs have an explicit visual and accessible state.
- Reports: Admin audit selector now includes Revenue, Refund, Official Receipt, Check Voucher, Petty Cash Voucher, Journal Voucher, Disbursement and Deposits, with CSV/Excel-compatible export and printable PDF view. Existing report types and Bookkeeper write restrictions remain intact.
- Shared presentation: restored the existing action-button colors, prevented action text and crest distortion, made settings avatars circular, arranged parish preferences in a responsive grid, and routed the primary Parishioner messaging navigation to the existing Messages page. Legacy help history remains available.

## Database changes

No application schema changes or migrations were introduced. No real parish records were changed. Existing columns and tables support all corrections. Regression tools create disposable `vicar_revision_*` databases and run their existing fixture migrations there; browser stress records are also confined to those databases.

Read-only checks of the configured local database found 32 transactional tables, six applications and no missing files among 98 media paths considered by the backup preflight. The isolated backup/restore test passed all 11 checks, including attachments, invalid-archive rollback and financial reconciliation. The backup service was preserved. The subsequent confirmed-issues pass corrected the controller to read POST `_action` explicitly; actual Create and Restore form submissions now pass against an isolated database.

## Manual verification and limits

- Real SMS and email receipt, provider configuration, queue scheduling and provider rejection behavior in the deployed environment still require a controlled live test. Local simulations or provider acceptance alone do not establish delivery.
- Physical QR-camera scanning, physical printing, and Safari/iOS/Android device behavior require manual checks. Chromium viewport emulation is not physical-device testing.
- Four linked SharePoint recordings (pages 10, 12 and 14) could not be opened by the web tool. Their contents are not claimed reviewed; all 15 PDF page images and captions were reviewed.
- Production backup/restore and real-record reconciliation remain deployment-stage checks. No deployment, GitHub push, production credential change, destructive production migration or production data deletion was performed.


## Confirmed-issues pass and second PDF comparison (2026-09-29)

The supplied 15-page PDF was compared again with the implemented pages and captured desktop/mobile views. This is a grouped evidence update, not a replacement numbering scheme for the missing original audit.

### Requirement-count limitation

The latest request supplies a baseline of **79 requirements: 31 completed, 14 partial, 1 incorrect, 1 missing, 32 manual**. The original 79 individual rows/IDs were not found in the workspace or supplied attachments. The available earlier audit contains 56 items for a different PDF; the initial table above groups the current PDF into 28 rows. Therefore an exact revised 79-item count or item-by-item status transition cannot be honestly calculated. The original audit path was requested. Its 32 manual classifications are not automatically promoted by these local checks.

The confirmed controller defect is resolved, but mapping it to the original INCORRECT row requires that source. Likewise, the parish financial table is implemented and verified, but its original MISSING/PARTIAL row ID is unavailable. No remaining reproduced incorrect or missing behavior was found in the scoped checks; this does not certify all unknown original rows.

### Confirmed changes and evidence

| Area / PDF pages | Before this pass | Second review result |
|---|---|---|
| Backup, p3 | Controller depended on an ambient action variable | Explicit POST dispatch; actual Create, download, Restore and success notice pass. Service unchanged; CSRF, role and confirmation checks pass. Production restore remains manual. |
| Parish financial table, p3 | Currency/column mapping and empty state incomplete | Explicit six-column key mapping, peso/two-decimal formatting, shared financial_totals and selected dates; no-parish empty state. Existing arithmetic retained. |
| Dashboard scope, p14 | Payment history used creation date; 50 applications could conceal older matches | Effective payment date, selected-period totals/list, stable 50-row pagination retaining filters, full payment-history link. Recurring Mass schedules remain lifetime data. |
| Dense dashboards, pp5/10/14 | Long tables increased page height | Scroll-bounded previews; existing Secretary View All links retained. Parishioner pagination reaches older records. |
| Public header, p1 | Narrow header overflow after shared-header changes | Brand wraps, logo shrinks safely, navigation remains visible at 320/390 px. |
| Calendar, pp9/14 | Existing capacity rules required review | Server scope, past/full-day blocking, month navigation, booking validation and unlimited-zero semantics reviewed; browser booking and full/released-capacity regressions pass. No calendar rewrite. Physical-device interaction remains manual. |
| Edit actions, pp4?9/15 | Audit requested | Admin users/parishes, announcements, services/requirements, application corrections, records, schedules and settings traced through targets, handlers and shared authorization/CSRF. Existing integration/browser cases exercised saves. Uncovered original icon-specific manual rows cannot be certified without their source. |

### Remaining grouped PDF items

- Locally completed presentation/functionality: crest/recovery; dashboard return controls/header order; Analytics cleanup; compact central filters; Finance table; announcement layout; application/payment modals; Admin report types; bounded dashboards; Request Docs/correction link; requirement editor; associated parish member access/replies; action colors/sponsor editor; Documents return; cancellation review/tabs; full answers/corrections; read-only membership; circular avatar; preferences/messages and readable announcements.
- Preserved and regression checked: accounting voucher limits/replenishment/journal history, role boundaries, verified/net calculations, user-defined booking amount, reports and scheduled applications. No additional changes warranted.
- Partially verified: external announcement delivery, physical QR/printing, actual mobile calendar interaction, inaccessible linked videos, deployment backup/provider behavior. Local browser evidence is not a physical-device or production acceptance result.
- Exact remaining original PARTIAL / INCORRECT / MISSING counts: **unreconciled pending the original 79-row audit**. Do not infer zero from the grouped review.

### Working-tree files reviewed in this pass

Application changes: `admin/backup.php`, `assets/css/enhancements.css`, `includes/dashboard_filter.php`, `includes/financial_summary.php`, `includes/public_header.php`, `parishioner/dashboard.php`, `staff/dashboard.php`.

Regression/documentation changes: `tools/browser_master.py`, `tools/final_pdf_browser.py`, `tools/pdf_revision_checks.py`, `docs/FINAL_PDF_REVIEW.md`. The pre-existing concurrent change to `tools/test_icons.py` was preserved, not attributed to this pass.

Earlier PDF corrections are retained in commits through `96ad17d`; their behavior is described above. No push/deployment occurred.

### Manual acceptance checklist and risks

- [ ] Controlled SMS and email receipt with real providers; rejection/retry and queue scheduling.
- [ ] Android/iPhone QR scanning and calendar taps/month changes, including full/past days.
- [ ] Physical printed reports/certificates: margins, crest, page breaks, signatures.
- [ ] Target browsers/devices: multiple-file selection, removal, persisted downloads. Chromium desktop/mobile-emulation interaction already passed locally.
- [ ] Hostinger upload/private-storage permissions, cron, provider behavior and environment configuration.
- [ ] Staged production backup/restore rehearsal with approved recovery plan and record/attachment reconciliation.
- [ ] Review the four inaccessible SharePoint videos and map the original 79 audit rows.

Material limits: one local Chromium engine, synthetic fixtures, no live providers or production restore, and missing source audit. Backup restore is inherently data-replacing; only disposable databases were restored. No new schema migration or financial formula was introduced.

**Ready for manual acceptance testing: YES.** Exact 79-row audit reconciliation remains outstanding; this is not production/deployment approval.


### Final regression results

| Suite | Passed | Failed | Evidence |
|---|---:|---:|---|
| HTTP integration, permissions, accounting, attachments, dashboard dates/pagination | 362 | 0 | separation-test-results.json |
| PDF responsive/browser review, five widths 320 to 1440 px | 188 | 0 | audit/pdf-final/browser.log |
| Browser editors/booking/reports and actual backup Create/Restore | 69 | 0 | audit/pdf-final/master-browser.log |
| Password visibility/accessibility/layout | 557 | 0 | password-visibility-results.json |
| Actual multi-file browser selection/removal/upload | 19 | 0 | attachment-browser-results.json |
| Loopback SMS/SMTP transport | 14 | 0 | separation-transport-results.json |
| Isolated backup service/attachments/rollback/reconciliation | 11 | 0 | master-storage-results.json |
| **Total automated assertions** | **1220** | **0** | Synthetic/local evidence only |

All 36 PHP files changed since the pre-review baseline or currently modified passed PHP lint. Navigation JavaScript syntax and git diff --check passed. Tests do not establish live provider delivery, production restore or real-device behavior.

Earlier failures were resolved: narrow shared-header overflow; a browser fixed-delay race replaced with an explicit wait; the backup browser uploader now retains the downloaded .zip filename; dashboard probes now use valid TIMESTAMP-range dates and inspect rendered payment history rather than expecting payments in the applications-only live JSON response. Final reruns passed.
