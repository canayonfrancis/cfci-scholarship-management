# Bulk Upload Scholars implementation

Date: 2026-11-19 (Asia/Manila)

**Final implementation status: IMPLEMENTED — PENDING MANUAL VERIFICATION**

## 1. Executive summary

The Scholars directory now offers **Bulk Upload** instead of manual Add scholar.
Authorized staff download an Excel template, upload .xlsx or UTF-8 CSV, review
row-specific validation and explicitly confirm a completely valid batch. Preview
does not insert records. Final confirmation reparses the retained source, checks
current database state, and saves all profiles and audit history in one transaction.

No automated test cases were created, changed or executed for this work. No
manual/browser functional testing was performed. No sample accounts/records were
created. Existing database contents were not changed and no migrations were run.
The manual guide below is prepared for the developer and has **not been executed**.
There is no functional testing verdict.

## 2. Existing functionality analysis

Reviewed ScholarController, ScholarRequest, Student/AcademicProgram/AuditEvent,
StudentPolicy/RecordPolicy, RecordAudit, ScholarProfile, routes, directory/detail/
shared form views, table enhancements, related migrations and scholar workflow
documentation. No existing spreadsheet importer or spreadsheet package was found;
existing CSV exports are reporting functions, not reusable importers.

The configured database was inspected through **read-only schema metadata**;
no functional calls or data mutations were made. Source migrations and current
metadata agree on these relevant fields:

- Scholar identities are `students`; there is no separate scholar-registration
  table to populate. Existing profiles are reused through View/Edit and later
  assignment workflows, rather than importing a second identity.
- `student_number`: unique `varchar(50)`, `utf8mb4_unicode_ci` in the inspected DB.
- First/middle/last names: `varchar(255)`; middle name nullable. Suffix: nullable
  `varchar(30)`. Sex uses existing integer codes 0 = Male, 1 = Female.
- `email`: nullable `varchar(255)`, **not unique**. It is contact information,
  independent of the unique login email on Users. Shared contact email remains
  permitted; importing one never claims a matching User account.
- `phone`: nullable `varchar(40)`; existing ScholarProfile normalizer recognizes
  Philippine mobile and eight-digit landline formats.
- `academic_program_id` references existing `academic_programs`; that table has a
  unique name, active flag and no separate course-code column. Course names must
  resolve to existing active rows. Codes are accepted only if registered as a name;
  no abbreviations or alias policy is invented.
- `year_level`: nullable unsigned small integer; configured entry values are 1–6.
- `user_id` is nullable/unique and references Users. Imports leave it unset.
- Students retain academic, assignment, roster, billing and payment relationships.
- Audit events already support correlation IDs. Their required subject ID is
  supplied by the first created Student on the batch receipt.

Existing staff creation authority comes from StudentPolicy's writer list through
RecordPolicy::create: `administrator`, `registrar`, `coordinator`. It is preserved.

## 3. Implementation summary

Added a private upload → preview → explicit confirmation workflow. New profiles
receive the existing active **administrative record** status, without a scholarship
assignment, academic semester, enrollment certification, login account or email.
Existing profiles and all their history remain untouched. Other official profile
fields may remain incomplete and can be maintained through the existing Edit page.

Removed the dedicated manual create/store actions and resource routes. The shared
scholar form remains available strictly for editing existing records. Search,
filters, sorting, pagination, View/Edit, scholarship assignment, rosters and the
separate Billing “Add scholar” financial action retain their existing code paths.

## 4. Files created

- `app/Http/Controllers/ScholarImportController.php`
- `app/Http/Requests/ScholarImportRequest.php`
- `app/Services/Records/ScholarSpreadsheet.php`
- `app/Services/Records/ScholarImportTemplate.php`
- `app/Services/Records/ScholarImportValidator.php`
- `app/Services/Records/ScholarImports.php`
- `app/Console/Commands/CleanupScholarImports.php`
- `config/scholar-imports.php`
- `resources/views/scholars/bulk-upload.blade.php`
- `docs/16-bulk-scholar-upload-implementation.md` (this report)

## 5. Files modified

- `app/Http/Controllers/ScholarController.php`: removed manual create/store only.
- `app/Http/Requests/ScholarRequest.php`: now authorizes existing-record updates.
- `config/filesystems.php`: new local private, non-served scholar-import disk.
- `routes/web.php`: protected import routes; scholar resource limited to
  index/show/edit/update.
- `routes/console.php`: hourly expiry cleanup schedule.
- `resources/views/scholars/index.blade.php`: Bulk Upload entry and result summary.
- `resources/views/scholars/form.blade.php`: retained editing form, removed create
  branches and references to removed routes.
- `resources/css/app.css`: scoped upload summary, consent, instructions and preview
  styles using existing brand variables and responsive record components.
- `docs/18-scholar-account-creation-flow.md`
- `docs/steps/02-scholars-and-assignments.md`
- `docs/steps/02-scholar-registration-and-assignment.md`

Production frontend assets were rebuilt from the changed CSS. Older historical
verification reports were not rewritten; any Add scholar references there describe
the earlier interface. Existing automated test files remain intact.

## 6. Files removed

None. Manual creation shared a form with Edit, so its creation-only branches were
removed while the editing component was retained. ScholarController::create/store
and the `scholars.create` / `scholars.store` routes no longer exist. GET
`/scholars/create` cannot open a creation form; POST `/scholars` has no create route.

## 7. Database changes

**None. No migration is required.** Existing Student uniqueness/FKs and audit tables
are reused. No reset, seed, truncate, delete, backfill or production migration was
performed. Import success is the only application path that inserts new Students,
and it requires the authorized operator's explicit confirmation.

## 8. Dependencies

No Composer or npm dependencies were added. The bounded, data-only OOXML reader/
writer uses PHP's existing DOM/libxml and ZipArchive extensions. CSV uses PHP's
CSV parser with explicit delimiter/enclosure/escape settings and a strict quoting
check. `mbstring`, `fileinfo`, PDO MySQL and normal Laravel dependencies are also
required. These PHP modules were listed as present locally with `php -m`.
This implementation is deliberately not a general Excel calculation engine.

## 9. Required setup and commands

On another checkout/deployment, install the project's normal dependencies and
compile assets as usual. There are no new package installation or migration steps.

```powershell
npm run build
```

If that deployment caches routes/configuration, rebuild its caches using its normal
deployment procedure (or clear stale caches with `php artisan route:clear` and
`php artisan config:clear`). Those application commands were not run for this work.

Ensure `storage/app/scholar-imports-private` and normal Laravel storage directories
are writable by the application and inaccessible through the web server. The disk
is outside public symlinks, private, and has URL serving disabled. Keep APP_KEY stable
to read pending encrypted previews. Do not place uploads on the public disk.

Run Laravel's scheduler normally, such as `php artisan schedule:work` for local
development, or `php artisan schedule:run` once per minute through the deployment
scheduler. It invokes `scholars:clean-imports` hourly. The developer may also run:

```powershell
php artisan scholars:clean-imports
```

This command only cleans temporary import files, never Students or audit records.
It was **not executed** during implementation.

Configurable defaults (no local environment file was edited):

| Environment key | Default | Meaning |
| --- | --- | --- |
| `SCHOLAR_IMPORT_MAX_UPLOAD_KB` | 5120 | Maximum uploaded file size, KB |
| `SCHOLAR_IMPORT_MAX_ROWS` | 1000 | Nonempty records in one batch |
| `SCHOLAR_IMPORT_PREVIEW_MINUTES` | 30 | Preview lifetime |

PHP `upload_max_filesize` and `post_max_size` and any reverse-proxy request limit
must accommodate the chosen upload size plus request overhead. Retain sufficient
request timeout/memory for the configured batch size; lower it when necessary.

## 10. Template structure

Download filename: `cfci-scholars-template.xlsx`.

First worksheet **Scholars**, with exactly these columns, in this order:

| Column | Required |
| --- | --- |
| Student ID | Yes |
| Last Name | Yes |
| First Name | Yes |
| Middle Name | May be blank |
| EXT | Optional |
| Sex | Yes |
| Course | Yes |
| Year Level | Yes |
| Email | Yes |
| Contact | Yes |

The template has a frozen branded header, readable column widths, Text formatting
on preformatted entry cells (including Student ID and Contact), and Sex/Year Level/
active-course dropdowns. **Instructions** explains accepted values, limits and the
workflow; **Courses** lists current registered active course names. These reference
sheets contain no sample scholar records and are not imported. Only the first
worksheet is read. For CSV, export only Scholars as CSV UTF-8 (Comma delimited).

## 11. Column mapping

| Template column | Existing Student field | Handling |
| --- | --- | --- |
| Student ID | `student_number` | Trim surrounding whitespace; preserve text, case and leading zeros |
| Last Name | `last_name` | Trim; Unicode/spaces/hyphens/apostrophes supported |
| First Name | `first_name` | Same |
| Middle Name | `middle_name` | Blank becomes null |
| EXT | `suffix` | Blank becomes null; existing free-text suffix rule |
| Sex | `sex` | Male/0 → 0; Female/1 → 1 |
| Course | `academic_program_id` | Resolve registered active name using database collation |
| Year Level | `year_level` | Configured numeric value or matching configured label |
| Email | `email` | Trim, lowercase, contact-format validation |
| Contact | `phone` | Validate with ScholarProfile::normalizePhone; retain original trimmed text |

Unlike the existing Edit action's phone normalization, this upload intentionally
retains the accepted contact spelling, including leading zeroes, as requested.
Existing Edit/Student edit normalization and uppercase-on-edit ID behavior were not
changed. No uploaded field can set a User link, role, scholarship or other columns.

## 12. Validation rules

- Student ID required, string ≤50; existing identity rule has no numeric-only
  restriction. New IDs are compared using actual database collation weights,
  including case, accent and trailing-space comparison behavior.
- First/last names required, ≤255 each; middle name nullable ≤255; suffix nullable
  ≤30, including Jr., Sr., II, III, IV. No ASCII-only name expression is imposed.
- Sex required; existing Male/Female codes retained, labels case-insensitive.
- Course required; must resolve unambiguously to an existing active course. No
  unknown course insertion, guessed code-to-name mapping or reactivation occurs.
- Year Level required; existing `ScholarProfile::yearLevels()` rules apply.
- Email required, valid format, ≤255. Contact email is not made unique and is not
  compared with User emails for account linking. No DNS/network validation is added.
- Contact required, ≤40; must match the existing PH mobile/landline normalizer.
- Upload extension, MIME and size are validated on the backend; the service also
  checks valid upload/extension/size before retaining it. Unsupported, corrupt or
  empty files and wrong/missing/repeated/out-of-order headers are rejected.
- UTF-8 CSV supports BOM, quoted delimiters and multiline fields. Malformed quotes
  are rejected. Errors refer to actual physical starting lines; .xlsx row numbers
  come from worksheet row references. Empty rows are ignored.
- Default resource ceilings: 1,000 records; 10,000 physical worksheet rows; 200 ZIP
  entries; 32 MB combined expanded ZIP data; 8 MB per internal part; 20,000 shared
  strings. Excessive cell text/control characters produce row errors.
- Formula cells and formula-like value prefixes are rejected. A legitimate
  `+63` phone is explicitly allowed through existing phone validation. Formulas are
  never executed. Macros, external relationships, embedded objects, merged data
  cells, XML DTD/entities and unsupported cell types are rejected. ZIP members are
  read in memory within limits, never extracted to client-chosen paths.
- Numeric Excel Student ID/Contact cells are rejected with row-specific guidance
  to re-enter original values as Text. The importer cannot recover zeros or digits
  already lost by Excel; silently guessing them is prohibited.

## 13. Duplicate handling

All occurrences of a repeated Student ID in one file are invalid. Any ID already in
Students, including archived or unassigned profiles, is invalid. Nothing is merged
or overwritten. Duplicate rows are a subset of invalid rows; total = valid + invalid.
Shared contact email is permitted under existing database rules, so it does not
inflate the duplicate-identity count or create an artificial uniqueness policy.

Confirmation reruns these checks with locked database reads. The existing Student
ID unique constraint remains the final race safeguard. A uniqueness conflict rolls
back the whole batch and refreshes duplicate validation. Separate valid imports
with the same identities cannot create duplicate Students.

## 14. Authorization and routes

Permitted roles: **Registrar Staff** (`registrar`), **School Scholarship Coordinator**
(`coordinator`), retained **Test Administrator** (`administrator`). Students, Finance,
Viewer and roleless accounts do not receive scholar-create permission.

All routes are in Laravel's authenticated web/CSRF group and use
`can:create,App\Models\Student` plus private/no-store response headers:

| Method / path | Route name | Action |
| --- | --- | --- |
| GET `/scholars/bulk-upload` | `scholars.imports.index` | Upload page |
| GET `/scholars/bulk-upload/template` | `scholars.imports.template` | Template download; throttled |
| POST `/scholars/bulk-upload/preview` | `scholars.imports.preview` | Validate and retain source; throttled |
| GET `/scholars/bulk-upload/{import}` | `scholars.imports.show` | Owner's preview |
| POST `/scholars/bulk-upload/{import}/confirm` | `scholars.imports.confirm` | Explicit import; throttled |
| DELETE `/scholars/bulk-upload/{import}` | `scholars.imports.cancel` | Remove pending upload |

UUID route constraints and owner checks protect previews/cancellation. Import
service mutation entry points also authorize create permission; confirmation
reloads and locks the actor and role memberships inside its transaction. A hidden
button is not the authorization mechanism. Another user's UUID cannot expose their
preview or receipt. Plaintext passwords, tokens and account actions are absent.

## 15. Workflow, state and transactions

1. Upload: retain the original file under a generated UUID on a non-served private
   disk; never use the client filename as a path. Parse, validate and encrypt the
   server preview with owner, expiry, SHA-256 source hash and validation fingerprint.
2. Preview: display raw text, row numbers, status and row-specific error lists.
   Reuse existing table chevrons and a single bottom rows-per-page selector. No
   records are inserted. All invalid batches have Confirm Import disabled.
3. Confirm: require an accepted review checkbox. Ignore client-supplied row values;
   acquire a nonblocking filesystem lock, verify owner/lifetime/source integrity,
   reparse/revalidate, lock actor/roles/courses/existing IDs, compare the preview
   fingerprint, and then insert validated Students and audit events atomically.
4. Postconditions check each saved identity/field and absent User link. Canceled
   writes, audit failure or any exception rolls back the entire batch. Transaction
   deadlocks use the existing three-attempt Laravel transaction pattern.
5. A committed `scholar_bulk_imported` audit receipt uses the upload UUID as
   correlation ID. Repeating that owner's successful request returns its receipt
   without reinserting records, including after temporary files were cleaned.
6. Success redirects with HTTP 303 to the unfiltered Scholars list and shows total,
   imported, duplicate, invalid and final status counts. Successful atomic imports
   necessarily have zero invalid/duplicate rows.
7. Cancellation deletes only that owner's pending files. Expired/corrupt/orphaned
   files age out through the cleanup schedule. Files are also removed on preview
   failure and after success; no retention of uploaded source is required for the
   durable audit receipt. At most 256 small stable lock files are retained outside
   upload directories to avoid lock-replacement races.

## 16. Error handling

File errors return clear upload guidance; row errors remain with their original
row number. An invalid batch is blocked both in UI and backend. Changed database
validation refreshes the preview and requires review/correction. No valid subset
is imported. Expired/missing/tampered sources require a fresh upload. Busy locks
return retry guidance. Unexpected errors use a generic notification and a sanitized
application report without original file contents, row values or SQL bindings.

The source hash and encrypted server-owned state are authoritative. Result counts
and hidden client fields cannot substitute for source validation. A committed
receipt remains authoritative if later temporary-file cleanup needs attention.

## 17. Static implementation review and actual command results

Reviewed routes/order/permissions, mappings and lengths against models/migrations
and read-only live metadata, native OOXML package parts, template/import header
compatibility, file security/limits, row numbering, duplicate/collation handling,
transaction/audit/postconditions, receipt retries and cancellation/cleanup.

Static review corrections included:

- Supplying the existing audit table's non-null subject ID on batch receipts.
- Using database weights and padding behavior rather than PHP-only lowercase
  comparisons for MySQL unique identity semantics.
- Retaining active course names and database relationships without inventing codes.
- Keeping the shared Edit form while removing its manual-create branches.
- Keeping lock paths stable independently of upload-directory removal.
- Keeping contact-email sharing consistent with the actual non-unique schema.
- Checking strict CSV quote structure and XML/ZIP bounds without executing formulas.

Commands executed for source/asset checks only:

```text
php -m
php -l <each of the 13 changed/new PHP source/config/route files>
php vendor/bin/pint <the same 13 PHP files>
npm run build
git diff --check -- <changed tracked source/view/docs files>
```

The PHP syntax checks reported no syntax errors. Pint completed source formatting;
Vite built 61 modules successfully. These are syntax/format/compilation checks,
**not functional verification**. The read-only metadata inspection queried schema
columns/indexes/FKs, not student values. No application import, template download,
cleanup command, route interaction or database write was executed for verification.

No PHPUnit, Pest, Artisan test, frontend test suite, browser automation or manual
functional scenario was executed. Existing tests that reference removed
`scholars.create` / `scholars.store` routes describe the former manual-creation
contract and will need developer review when testing resumes; they were left intact.

## 18. Beginner-friendly manual testing guide — NOT EXECUTED

Use a development database and fictional data only. Use an existing account with
Registrar, Coordinator or Test Administrator permission; no credentials are
invented or disclosed here. The required existing StudentPolicy permission is
`create` for Student. Ensure at least one actual active Academic program exists;
use its exact name from the template's Courses sheet.

### Register a valid batch

1. Sign in normally. Open **Scholars** in the navigation.
2. Search each fictional Student ID below first. If already present, choose fresh
   fictional IDs; do not remove existing records to make a scenario fit.
3. Select **Bulk Upload**, then **Download Template**. Expect a downloadable .xlsx
   file with Scholars, Instructions and Courses sheets, and the exact ten headers.
4. Open the file in Excel or another compatible editor. Read Instructions. On
   Scholars, enter the fictional rows below, replacing the course placeholder with
   an existing active name from Courses. Keep Student ID and Contact as Text. Leave
   the first row's Middle Name and EXT genuinely blank.
5. Save as .xlsx. On **Bulk Upload Scholars**, select the file and choose **Preview**.
6. Expect total 2, valid 2, invalid 0, duplicate 0, with rows numbered 2 and 3.
   Confirm leading zeros, Unicode/apostrophes, suffix and blank middle name remain
   intact. Preview must not add either ID to the Scholars directory.
7. Review the preview, check **I reviewed this preview and confirm that these are
   new scholar records**, then select **Confirm Import**.
8. Expect return to Scholars, a completed result showing 2 imported and 0 invalid/
   duplicate, and both new records discoverable by Student ID without old filters.
9. Select **View**, then **Edit record** if further official profile data is needed.
   Expect the mapped course/year, no Student login association, no automatically
   assigned scholarship, and incomplete remaining official profile information.
10. Refresh/revisit the completed import URL or resubmit its confirmation. Expect
    the same receipt and no additional records. Search the two IDs to confirm.
11. Repeat separately with **new fictional IDs** exported as CSV UTF-8. Export only
    Scholars; do not export Instructions/Courses or assume Excel retained numbers
    correctly if columns were converted to numeric.

### Invalid, duplicate, security and lifecycle scenarios

| Scenario to perform in development | Expected result | Execution status |
| --- | --- | --- |
| Repeat an ID twice inside a fresh file | Both occurrences invalid/duplicate; entire import blocked | Not executed |
| Upload a previously imported ID | Existing profile is untouched; duplicate row blocks import | Not executed |
| Use an uppercase/lowercase or accent-equivalent ID under current collation | Database-equivalent duplicate is rejected | Not executed |
| Missing required name/email/contact/course/year/sex, invalid email or phone | Row-specific errors; zero saved records | Not executed |
| Blank Middle Name and EXT with other fields valid | Accepted | Not executed |
| Nonexistent/inactive course or unsupported year/sex | Clear validation error; no new course/Student | Not executed |
| Two new IDs sharing a contact email | Existing non-unique contact-email rule is preserved; no login account is created | Not executed |
| Missing/repeated/out-of-order headers; empty or corrupt file; .xls renamed .xlsx | Rejected before any import | Not executed |
| Formula, macro-enabled workbook, external link, merged data cells or numeric Excel ID/contact | Rejected or row-marked invalid; no unsafe evaluation | Not executed |
| Spacer rows or a quoted multiline CSV value | Errors retain original worksheet/starting physical line numbers | Not executed |
| File above configured KB limit or more than 1,000 default records | Rejected; no records saved | Not executed |
| Correct an invalid file and upload it again | New preview reflects corrections; explicit confirmation still required | Not executed |
| Choose Cancel import on a preview | Pending source/preview removed; no Students inserted | Not executed |
| Wait beyond the preview lifetime before confirming | Expired message; fresh upload required | Not executed |
| Register an overlapping ID in another authorized batch after preview | Confirmation revalidates; no partial batch/overwrite | Not executed |
| Two near-simultaneous confirmations of one upload | Only one committed batch; retry returns receipt or busy guidance | Not executed |
| Another authorized user's preview UUID | No access to another operator's pending source/preview | Not executed |
| Student/Finance/Viewer opens bulk page or invokes its direct endpoints | Backend denies access; no mutation | Not executed |
| Existing scholar View/Edit/Search/filter/pagination, scholarship/roster/Billing operations | Existing authorized workflows remain operational | Not executed |

Verify per-Student `scholar_created` and batch `scholar_bulk_imported` audit events
through your established administrative/database review procedure; do not invent an
Audit History menu. To exercise rollback safely, use your own controlled development
failure mechanism; do not break live constraints or permissions. Check that neither
Student nor audit rows from the failed batch persist. No rollback scenario has been
executed during this implementation.

## 19. Fictional sample data for the developer only

These records were **not inserted**. The course placeholder must be replaced with
an exact, existing active course name. `.test` addresses are fictional contact data;
the feature does not send email or create accounts.

| Student ID (Text) | Last Name | First Name | Middle Name | EXT | Sex | Course | Year Level | Email | Contact (Text) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 000SCH-2026-001 | O'Neill | Lina | (leave blank) | (leave blank) | Female | [existing active course name] | 1 | bulk-lina@example.test | 09990000001 |
| 000SCH-2026-002 | Dela Cruz | José | Reyes | Jr. | Male | [existing active course name] | 2 | bulk-jose@example.test | 09990000002 |

For duplicate/invalid scenarios, use a separate copy of the file, retain one valid
fictional row and deliberately repeat its ID or omit a required field. Expect the
whole batch to remain unsaved, including the otherwise-valid row.

## 20. Known issues and limitations

- Functional correctness, actual Excel/editor interoperability, browser layout,
  authorization responses, concurrency and rollback remain **unverified at runtime**
  because the developer explicitly reserved all functional testing.
- The importer targets the existing MySQL/MariaDB UTF-8 schema and its comparison
  weights. SQLite and other drivers are intentionally unsupported for this feature.
- It handles the provided ordinary transitional .xlsx template and UTF-8 CSV, not
  .xls, .xlsm, encrypted/password-protected files, strict OOXML, macro packages,
  external workbooks or a general spreadsheet formatting/calculation feature set.
- Only the first worksheet contains imported data. Non-UTF-8 CSV must be re-saved
  as CSV UTF-8; numeric identifiers must be corrected from original records rather
  than having missing digits guessed.
- File size may be below the configured upload ceiling yet exceed internal ZIP/XML
  limits; such complex files must be simplified or split into smaller batches.
- Course names, not guessed course codes, are authoritative. No active courses means
  no valid imports. Additional official profile details remain for later editing.
- Pending files and filesystem locks require a shared private filesystem with
  working advisory locking across all app workers. A multi-node deployment without
  this shared storage needs deployment adaptation before use.
- Expiry cleanup depends on Laravel's scheduler being operational. Stable lock
  files are intentionally bounded and retained. New corrected uploads are independent
  previews; older previews remain owned/private until canceled or expired.
- Existing automated test expectations for manual creation were not updated because
  the user required existing test files to remain intact.
- No email configuration, sending behavior or unrelated feature was modified.

## 21. Final implementation status

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

The code and static review are complete. The developer must perform the prepared
manual scenarios and review/update relevant automated contracts before treating
the feature as functionally verified. No testing success/failure verdict is claimed.
