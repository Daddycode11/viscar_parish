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
