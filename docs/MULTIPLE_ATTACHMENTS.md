# Multiple requirement attachments ? 27 September 2026

## Audit and design

The live `vicarparish_local` schema stored a single filename per requirement key in the JSON `applications.uploaded_files` map. `service_requirements` defines requirement names; dynamic file fields use `service_fields`. `application_document_requests` stores staff requests and submission timestamps, not individual files. `accounting_documents` attachments belong to a separate accounting workflow. There was no reusable application attachment table.

Initial booking and Secretary walk-in shared `submit_booking()`. Request Documents used a separate single-file handler. Protected viewing resolved an application plus a map key; application detail pages and the Admin application modal read that map. Storage used `storage/private` with an older `uploads/requirements` fallback. Existing files were never publicly linked directly by the changed views.

There was no persisted attachment deletion/replacement endpoint to extend. This revision adds removal from the pre-upload selection only; it introduces no way to delete a previously uploaded attachment.

## Database migration

`tools/migrate_attachments.php` adds `application_attachments`:

- `id`, `application_id` (foreign key), `requirement_key`
- `original_name`, `stored_name`, `storage_location`
- `mime_type`, `size_bytes`, `created_at`
- Unique `(application_id, requirement_key, stored_name)` index for idempotent backfill.

Each file is a separate row. Requirement keys preserve the existing `req_ID`, `field_ID`, and `additional_REQUEST_ID` identities. Labels come from application schema snapshots and document requests, preserving historical requirements even if current service definitions change. A foreign key to the mutable service-requirement table is deliberately avoided because deleting a current definition must not delete historical attachments.

The migration backfills rows without changing `applications.uploaded_files`, moving files, or deleting data. It detects private versus legacy storage. Original filenames that were never recorded cannot be reconstructed; legacy rows display their existing filename. New uploads preserve a sanitized original basename separately from a random server-side filename.

Applied twice successfully to `vicarparish_local`. Before/after checks confirmed all six application maps were byte-for-byte unchanged and both unique legacy file SHA-256 hashes matched. No legacy file was missing. Eight requirement-file relationships were backfilled. A private SQL backup was taken before migration; its location and hashes are recorded in `storage/private/attachment-migration-audit.json` (not a public download).

For another installation, back up its database and uploaded documents, deploy these files, then run `php tools/migrate_attachments.php` under its intended database configuration. `tools/setup_local.php` also runs this migration. Restore archives must match the current schema, as before.

## Upload and compatibility behavior

- Initial booking, dynamic file requirements, Secretary walk-in and requested-document submissions accept multiple files for each requirement.
- A shared browser script displays selected filenames using text nodes and provides keyboard-accessible Remove buttons. Removing one selection leaves all other selected files intact. Review displays every remaining filename; uploads send each file using array-style multipart field names.
- Single-file multipart requests remain accepted. Old `app=...&key=...` URLs still resolve their original document. The old map remains a first-file compatibility pointer, while the new table contains every attachment. New protected URLs select an attachment ID and require a matching authorized application.
- Owner/parish/role restrictions and staff/Admin password re-verification remain enforced. Admin, Secretary and Parishioner views list requirement names and all files with protected View/Download actions. Requested-document submission lists all submitted files.
- Every file is checked individually against the existing PDF/JPG/JPEG/PNG extension allowlist, server-side `finfo` MIME detection, upload success, actual uploaded-file status and 5 MB size limit. Browser MIME declarations are not trusted. Unsupported/executable extensions and spoofed MIME content are rejected. Original basenames are sanitized and escaped when displayed.
- Storage uses random 160-bit names, checks for an existing target, and never reuses original names as storage paths. Duplicate original filenames therefore remain separate attachments. All validation completes before storing a batch; the existing transaction rollback cleans new files after downstream failure.
- A maximum of 20 files per submission matches the local PHP upload-count limit. New forms send a file count so silently truncated uploads cannot be accepted as a complete submission. PHP's total POST/upload limits still apply; this installation has a 40 MB POST limit. No server configuration was changed.
- Request fulfillment still happens once per staff request. A new request creates a new attachment group; it does not overwrite the prior group's files.
- Backup creation now includes every attachment file, including secondary files absent from the legacy map. Restore checks that all referenced attachment files appear in its manifest.

## Exact files modified

| File | Change |
| --- | --- |
| includes/workflows.php | Multi-file booking integration; retain legacy first-file map; unique target check |
| includes/document_workflows.php | Multi-file request submission through shared validation/storage |
| includes/application_details.php | Grouped attachment lists |
| includes/application_document.php | Authorized attachment-ID lookup and legacy URL support |
| includes/backup_service.php | Include and validate all normalized attachments |
| public/document.php | Safe original filename in download headers |
| public/document_view.php | Preserve selected attachment ID in preview/download URLs |
| admin/applications.php | Include all attachments and requirement labels in Admin modal |
| parishioner/apply_service.php | Load shared selection UI |
| parishioner/documents.php | Multiple selection, all-file list and complete multipart submission |
| staff/walk_in.php | Multiple requirement and dynamic-file inputs; shared selection UI |
| assets/js/apply-service.js | Multiple inputs, all-file review and multipart submission |
| tools/setup_local.php | Run attachment migration |
| tools/test_separation.py | Run new attachment assertions in isolated regression fixture |
| tools/test_master_storage.php | Check backup manifest covers every normalized attachment |

## Files added

- `includes/attachments.php` ? normalization, validation orchestration, storage metadata and grouped rendering.
- `assets/js/multiple-attachments.js` ? shared selected-file list and individual removal.
- `tools/migrate_attachments.php` ? additive schema and legacy backfill.
- `tools/attachment_checks.py`, `tools/test_attachments.py` ? targeted HTTP tests and runner.
- `tools/browser_attachments.py` ? desktop/mobile browser checks.
- This report; `attachment-test-results.json`, `attachment-browser-results.json`, and screenshots in `revision-evidence/attachments/`.

## Verification

Targeted HTTP suite: 27 passed, zero failed. Covers one file, multiple files, mixed PNG/PDF, duplicate original filenames with distinct storage paths, invalid extension, MIME spoof, oversized file, incomplete upload count, application-ID mismatch, owner/Secretary/Admin viewing, cross-owner denial, multi-file Request Documents, legacy records and links, idempotent migration, walk-in uploads and CSRF.

Browser suite: 19 passed, zero failed. Chrome at 1440px and 390px checks native multiple selection, all filenames displayed, removal of only one selection, remaining-file review, actual booking persistence, Secretary display and requested-document submission. No uncaught browser errors. This is mobile viewport coverage, not a claim of physical Android/iPhone picker certification.

All 14 changed/added application PHP files and migration/setup scripts passed syntax checks. Full regression: 307 passed, 0 failed. Backup/restore: 11 passed, zero failed, including every normalized attachment in the manifest and a real restore round trip. Results are recorded in `separation-test-results.json` and `master-storage-results.json`.

No authentication, password, finance business rules, or unrelated UI behavior was changed.
