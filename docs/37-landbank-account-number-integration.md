# LANDBANK Savings Account Number integration

Status: **IMPLEMENTED — PENDING MANUAL VERIFICATION**.

Static source review only. No automated tests were created or executed; no manual/browser testing, application commands, migrations, seeders or database changes were performed. Runtime behavior and encryption round trips remain for developer verification.

## Field and storage

The existing `Student` model / `students` table owns scholar profiles. No suitable bank-account field was found in the existing source schema. Added `landbank_account_number` as a nullable **TEXT** column using:

`database/migrations/2026_11_20_000002_add_landbank_account_number_to_students.php`

The migration adds only this column: no separate table, defaults, backfill or changes to existing scholars. Laravel's existing **encrypted** cast protects saved values and TEXT accommodates ciphertext. Values stay strings, including leading zeros. The field is hidden from ordinary model array/JSON serialization.

Validation is optional, digits only, with a technical maximum of 100 characters. No exact official bank-account length, unique-account rule, bank ownership verification or eligibility condition was invented. Whitespace-only/empty input is treated as blank through existing request/import normalization. Nonempty account numbers must be provided as strings; Excel numeric cells are rejected rather than guessing lost leading zeros.

Keep the existing application encryption key and its secure backups. Do not run `key:generate` to deploy this field. Rolling this migration back drops the new column and its account values; review/back up before any rollback.

## Scholar forms and permissions

- **Information Registry → Scholars → open scholar → Bank Account Information** displays a masked number. Only the last four characters are shown; numbers of four or fewer characters are masked entirely.
- Authorized staff can explicitly choose **View full account number**, which reloads the existing scholar route with `show_bank_account=1`. The controller enforces `StudentPolicy::viewBankAccount` before rendering the full value. The default page does not embed the full account number in hidden HTML or JavaScript. **Hide full account number** returns to the masked page. No account number is placed in the URL.
- **Edit record → Contact Information** has an optional password-style entry, masked saved-number reference, and **Show new account number** toggle for the new entry only. Existing plaintext is never prefilled. Blank input preserves the saved number; entering a number replaces it. **Remove the saved LANDBANK account number** explicitly clears it. Removal and replacement cannot be submitted together.
- This checkout has no individual scholar creation route/form; its existing creation flow is **Bulk Upload Scholars**. That flow supports the new optional field without adding another creation interface.
- The existing Student self-edit allowlist remains unchanged. Students may see their own masked number through the shared profile display, but cannot reveal it, change it or access another scholar's bank information through Student profile actions.

| Existing account role | Bank access |
| --- | --- |
| Registrar Staff (`registrar`) | Masked display, explicit full reveal, edit/clear, bulk import through existing permissions. |
| School Scholarship Coordinator (`coordinator`) | Same bank access as Registrar. |
| Retained Test Administrator (`administrator`) | Same bank access as Registrar. |
| Retained legacy Finance (`finance`) | Masked financial/profile display and explicit full reveal; no scholar edit/import permissions added. |
| Retained legacy Viewer (`viewer`) | No bank display or full reveal; direct reveal request rejected. |
| Student | Own masked profile display only; existing own-profile authorization/association rules remain. |

No existing role memberships or unrelated policy decisions changed. The new bank-specific view/manage checks reuse the existing staff roles and scholar-update permission. Mutations retain CSRF checks and the existing locked update transaction.

## Bulk upload compatibility

The existing template download generates an optional eleventh column, **LANDBANK Savings Account Number**, immediately after **Contact** (column K). The new column and its blank entry cells use Excel **Text** formatting; its width and autofilter range include K. Instructions describe the optional value and preservation of leading zeros. No populated workbook was edited or committed.

`ScholarSpreadsheet` accepts:

- New eleven-column XLSX/CSV templates, with either a supplied or blank bank value. A new-format CSV row may also omit the empty final bank cell.
- Existing ten-column templates ending at Contact; bank information is normalized to an empty optional value.

Unrelated header order, formulas, unexpected nonempty columns, invalid package content, duplicate IDs, invalid course/contact data and row/file limits retain their existing protections. A populated XLSX bank cell must be **Text**. Once Excel has converted an account number to a number and removed zeros, the importer cannot reconstruct the original account; re-enter it from the source as Text.

Import preview masks the bank cell, including invalid input; the full value is not embedded in the rendered preview. The existing encrypted preview state, owner-only access, original-file hash, confirmation-time revalidation, transaction, locking, postcondition checks and audit receipt are reused. `ScholarImportValidator` supplies a nonempty bank string to the existing `ScholarImports` creation loop; the model encrypts it, and the existing saved-field check compares the decrypted string after saving. No duplicate import loop or account table was added.

Blank bank values are omitted from imported attributes. Imports remain **new scholars only**: existing Student IDs are rejected, never overwritten or merged. Therefore an old template or blank imported value cannot erase an existing scholar's bank number. Existing scholars need no reimport; use their existing edit page for optional bank updates.

An upload preview created before this deployment may require refreshed validation/reupload because the parsed optional-column shape changed. This does not require reimporting saved scholars.

## Billing, payment and exports

- **Billing and Validation → Billing batches → open batch → Roster Validation** shows the masked current-profile number beneath the scholar name for authorized bank viewers.
- **Liquidation and Payment → Payments and liquidation → submitted batch → Payments & liquidation → Record a student payment** retrieves masked bank information from each existing billing item's scholar in the selection. No second bank-account input is introduced.
- On an existing **Student payment record**, authorized personnel see the masked current-profile number and a **Review scholar bank information** link. Full reveal remains an explicit authorized action on the scholar profile.
- These displays describe **current profile information**, not a retained historical bank destination. Updating a profile does not change existing payment/financial snapshots.
- General scholar listings, both billing scholar-list exports, roster exports and existing report exports do not gain full account numbers. No new full-account financial export was added because no approved export currently explicitly requires one. An approved bank-file specification and authorization would be needed before adding such output.
- Missing bank information remains **Not provided**, without changing eligibility, approved rates, payable amounts, receipt/payment verification, scholarship status or liquidation requirements. No LANDBANK API, transfer initiation, payment approval or new approval stage was introduced.

## Security and privacy reused

The field joins `Student::PROTECTED_IDENTIFIERS`, which already drives hidden serialization and `RecordAudit` redaction. Scholar creation/update audit states retain only `[REDACTED]`/null for the account field. No full account numbers or new account logging statements were added.

`bootstrap/app.php` excludes the field from flashed validation input. The edit form never calls `old()` for the bank value; re-entry is required after a failed save. Validation errors describe the field/rule rather than quoting its value. Existing private/no-store headers on scholar, import and financial routes are retained.

The original uploaded XLSX/CSV remains a sensitive temporary **private source file**, as in the existing import architecture; it is not claimed to be encrypted at rest. Its root is `storage/app/scholar-imports-private`, outside public storage with serving disabled. Preview state is encrypted separately. Existing confirmation/cancel cleanup, expiry and scheduled cleanup remain in place. Restrict server filesystem/backup access and handle source workbooks as confidential files.

## Files modified

- `database/migrations/2026_11_20_000002_add_landbank_account_number_to_students.php` — nullable encrypted-value storage; not executed.
- `app/Models/Student.php` — encrypted/hidden account field and masked display helper.
- `app/Policies/StudentPolicy.php` — bank-specific staff read/manage checks.
- `app/Services/Records/ScholarProfile.php` — shared optional validation and masking.
- `app/Http/Requests/ScholarRequest.php` — optional validation, authorization and clear/replacement conflict rule.
- `app/Http/Controllers/ScholarController.php` — explicit authorized full reveal; blank-preserving edits/explicit removal.
- `bootstrap/app.php` — prevent bank values in flashed validation input.
- `app/Services/Records/ScholarSpreadsheet.php` — optional column, legacy format compatibility and text-cell protection.
- `app/Services/Records/ScholarImportValidator.php` — bank validation and nonempty creation attributes.
- `app/Services/Records/ScholarImportTemplate.php` — column K Text formatting, headers/filter/instructions.
- `resources/views/scholars/form.blade.php` — optional protected bank entry.
- `resources/views/scholars/profile-details.blade.php` — masked profile display and explicit authorized reveal.
- `resources/views/scholars/bulk-upload.blade.php` — revised instructions and masked preview.
- `resources/views/billing/partials/roster-validation.blade.php` — masked account context.
- `resources/views/financial/batch.blade.php` — masked existing-account information during payment selection.
- `resources/views/financial/payout.blade.php` — masked profile reference and authorized profile link.
- This report.

`RecordAudit`, `ScholarImports`, existing Student self-edit rules, financial calculations, export/report selection and authorization, routes, navigation and Ngrok/proxy configuration are reused without changes. The shared XLSX text-format correction below changes formatting only, not export contents or financial totals.

## Excel Text / scientific-notation and Contact correction

Status: **IMPLEMENTED — PENDING MANUAL VERIFICATION**. This follow-up changes only the existing workbook writer, reader, upload instructions and this report; it adds no database changes or dependencies.

Static inspection found that the preceding writer already used built-in Excel Text format 49 on column defaults and blank entry cells. The reported populated workbook was not inspected or opened in Excel during this task, so the exact editing/conversion step that produced scientific notation is not confirmed. An older download or pasting numeric cells with their formatting can defeat the intended entry format; merely changing a previously converted number to Text cannot restore its original digits. The identified backend gap was that a scientific-notation **string** could still pass the existing general Student ID string validation. The subsequent usability issue came from applying the same strict text-cell rejection to Contact as to Student ID and LANDBANK, before consulting the existing valid-phone rules.

Implemented corrections:

- The existing `ScholarImportTemplate` / `ZipArchive` OOXML writer now defines an explicit custom number format **`@`** (`numFmtId=165`). Blank-entry, header and report-text styles apply that format. The existing red header fill, typography, widths, freeze panes, filter and column order are retained.
- **A — Student ID**, **J — Contact**, and **K — LANDBANK Savings Account Number** retain their Text column defaults across each entire column. Every blank entry cell from row 2 through the configured row limit plus the header row (default: row 1001) also has an explicit Text style; formatting does not depend on a populated sample row.
- Student ID (A) and LANDBANK (K) also have simple `ISTEXT` Excel validation with a stop message for numeric entries. Contact (J) keeps its Text format but no longer has a text-only stop rule, so valid numeric contacts from ordinary Excel files are not blocked. Blank cells remain allowed at this spreadsheet layer; existing backend rules still require Student ID and Contact. Pasting can replace or bypass spreadsheet validation, so backend validation remains authoritative.
- All non-currency cells, including instruction examples and identifier values in the existing XLSX scholar-list exports, continue to be written as explicit `inlineStr` strings. No populated sample scholars were added. Billing monetary cells retain their existing separate PHP currency number format (`numFmtId=164`); amounts are not converted to Text.
- `ScholarSpreadsheet::IDENTIFIER_COLUMNS` identifies A/J/K for scientific-text checks. `STRICT_TEXT_COLUMNS` identifies A/K for Excel entry validation and strict reader checks. Student ID and LANDBANK numeric XLSX cells remain rejected even if someone changes only their display format to Text; their messages explain that lost zeros/rounded digits cannot be reconstructed.
- Both XLSX and CSV reject scientific-notation **text** in these fields, including examples such as `9.09231E+13` and `1e14`. Messages name the field without echoing a private account value. Numeric Contact cells receive the separate exact conversion described below; this does not permit numeric bank accounts or expand ambiguous CSV strings.
- Numeric XLSX Contact cells (`t="n"` or the implicit numeric type) are accepted only when their complete stored decimal value can be read as an exact valid integer contact. A small bounded string operation locates the decimal point, permits only zero discarded fractional digits, and refuses to append absent digits to an abbreviated coefficient. No contact number is converted through PHP integers or floats, rounded, padded or given a missing `0`, `+63` or `0063` prefix. The resulting string must pass the existing `ScholarProfile::normalizePhone` check; import continues to store the accepted contact string under the existing behavior.
- Plain numeric `9171234567` and `81234567`, an exact integral decimal `9171234567.0`, and a complete numeric coefficient `9.171234567E9` can resolve to approved phone strings. Fractional `917123456.7`, abbreviated coefficient `9.17E9`, incomplete contacts and unsupported country-prefix forms such as numeric `639171234567` fail. Text values such as `09171234567`, `+639171234567` and `00639171234567` retain their existing validation and leading characters. A numeric mobile whose Excel-stored string is `9171234567` is accepted directly under the already-approved ten-digit mobile rule; no lost zero is recreated.
- The reader returns strings; existing import attributes and saved-field comparisons preserve strings. Only unrelated existing fields such as year level/sex are interpreted numerically. Ten-column template compatibility, optional/blank bank behavior, duplicate prevention, original-file revalidation, transactions, masking, encrypted storage and staff authorization remain unchanged.
- Existing authorized XLSX billing exports inherit the explicit `@` report-text style for Student ID through the shared writer. No Contact/LANDBANK columns or full bank-account exports were introduced. CSV has no Excel formatting metadata; opening CSV by double-clicking can cause Excel to reinterpret its text. Use the XLSX template or explicitly import those CSV columns as Text.

Files changed for this correction:

- `app/Services/Records/ScholarImportTemplate.php` — explicit `@` styles, A/K-only text-entry restriction, string-written contact examples and instructions; shared report-text style also covers existing XLSX identifier exports.
- `app/Services/Records/ScholarSpreadsheet.php` — strict identifier positions, bounded exact numeric Contact conversion, clear field-specific errors and scientific-notation text rejection.
- `app/Services/Records/ScholarImportValidator.php` — reuse existing contact normalization, with a clearer complete-contact error distinct from strict bank-number errors.
- `resources/views/scholars/bulk-upload.blade.php` — distinguish acceptable numeric contacts from strict text identifiers and damaged values.
- This report.

Manual verification for this correction — **NOT TESTED**:

1. Registrar: **Information Registry → Scholars → Bulk Upload Scholars → Download Template**. Download a fresh copy; existing local files do not update automatically. Check that A, J and K display **Text** / format code **@**, both at row 2 and at the last configured empty entry row. Confirm red headers, dropdowns, filters and order are unchanged.
2. Use controlled fictional formatting examples, not actual financial instructions: Student ID `000012345678901234`, Contact `09171234567`, and LANDBANK `000909231234567890`. Fill the other required fields using permitted development data. Type the examples into the Text-formatted cells, save and reopen the workbook yourself. Expect exact characters/zeros, no scientific notation and no numeric conversion. These are formatting examples, not an official bank-account length or verified accounts.
3. Preview that workbook. Expect a valid long text identifier and masked bank value. On a developer-authorized import, use the existing explicit full-reveal action to compare the saved bank string with its original, and verify existing encryption/redaction. No transfer or change to eligibility should occur.
4. In disposable copies, provide numeric Student ID or LANDBANK cells. Excel should reject ordinary invalid numeric entry when its A/K validation is retained. If pasting bypasses Excel validation, preview must still reject these numeric XLSX identifiers. Changing only the format back to Text must not be accepted as recovery of the original number. For Contact, preview numeric `9171234567` and `81234567` instead: these must be accepted under the existing phone rules, without the former text-cell rejection. Where the stored numeric coefficient is complete and exact, `9171234567.0` / `9.171234567E9` must resolve to `9171234567` without rounding. Inspect the stored XML only if needed to distinguish Excel display formatting from its actual stored value.
5. In disposable files, provide scientific-notation **text**, such as `9.09231E+13`, in each of A/J/K, including UTF-8 CSV. Expect a clear scientific-notation error and a blocked import, with no reconstructed digits. Recover by re-entering the full value from the original source, not by formatting the damaged value or using a formula to expand it.
6. Preview old ten-column XLSX/CSV templates with new fictional IDs, and new templates with blank LANDBANK values. Expect existing compatibility/optional behavior. Check duplicate IDs and formula cells still block the entire import. Existing stored scholars must not be reimported or overwritten.
7. Export **All roster members** and **Billing items only** from an authorized billing batch with a leading-zero Student ID. Expect Student ID to remain a string with explicit `@` format, actual item amounts to retain currency formatting, and no new bank-account data in either export.
8. Check Contact `917123456.7` as a genuinely fractional numeric cell, seven-digit/incomplete contacts, abbreviated numeric coefficient `9.17E9`, and numeric `639171234567`. Expect contact-specific rejection, no rounded or added digits/prefixes and no partial batch. Keep valid text `09171234567` / `+639171234567` in separate controlled rows and confirm their existing acceptance. On a developer-authorized confirmation, verify accepted contacts remain strings, Student ID duplicate handling is unchanged and LANDBANK values remain encrypted/masked. Upload a fresh preview after this correction if an earlier preview still contains the old type-rejection result.

Only source/diff review and `git diff --check` were performed. No workbook was generated/opened for testing, no import was executed, no automated tests or application commands were run, and no database records were modified. Excel behavior, export rendering and round-trip persistence above remain for developer verification. Once a digit-only string has already been rounded and converted back to text outside the system, its lost history cannot be detected or recovered reliably; compare it with the original source.

## Required developer commands

**This formatting correction needs no migration, dependency installation or asset build.** Download a fresh template. The migration command below belongs to the original LANDBANK field installation only; do not rerun it if already applied. No new migration was created in this follow-up.

From the existing project directory, after reviewing the migration and confirming the intended database configuration/backups, run:

```powershell
php artisan migrate --path=database/migrations/2026_11_20_000002_add_landbank_account_number_to_students.php
```

Apply it before using nonempty bank values or clearing a saved value. This migration does not run other pending migrations. If compiled views are stale, also run:

```powershell
php artisan view:clear
```

Neither command was executed by this implementation. No dependency installation, new asset build, environment-key change, reset, seed or separate database is required. The form uses existing Alpine and CSS components.

## Manual verification for the developer

**All items below are NOT TESTED.** Use developer-controlled fictional records and files in an authorized development session. `001234567890` below is arbitrary formatting-test input, not a verified real bank account. Never initiate actual transfers or change real financial history to test this feature.

1. Review/apply the migration against the intended environment. Confirm existing scholars still exist and the new column is nullable TEXT. Do not reset, seed or reimport existing records.
2. Registrar: **Information Registry → Scholars → controlled scholar → Edit record**. Enter `001234567890` in the optional LANDBANK field and save. Expected: normal update succeeds; **Bank Account Information** displays `********7890`, with no full number in default HTML. Profile completeness/eligibility remains governed by its original requirements.
3. Registrar: choose **View full account number**. Expected: `001234567890` with leading zeros intact. **Hide full account number** returns to masked display. In an authorized database inspection, verify storage contains ciphertext rather than that plain value; do not publish it in logs/screenshots.
4. Edit an unrelated field with the bank input blank. Expected: the existing bank value is retained. Choose **Remove the saved LANDBANK account number** and save. Expected: it becomes null/Not provided; other scholar and financial history remains. Replacing and removing together must return a validation error.
5. Try `ABC123`, `12-34`, `1e10`, over-100-character input and an array/JSON numeric input in an authorized validation session. Expected: appropriate string/digit/size errors, no partial save or echoed bank value. No exact bank length is mandated; one-to-four-character formatting-test strings remain wholly masked. Re-enter the value after unrelated validation failure; it must not be restored from flashed input.
6. Check role boundaries with existing test accounts. Registrar/Coordinator/Test Administrator can edit and reveal. Legacy Finance can reveal but cannot edit/import. Viewer cannot see bank details or obtain full data via `show_bank_account=1`. Student can see only their own masked value; adding bank/clear fields to the existing Student self-edit request must be rejected, and other scholars' staff profile routes remain denied.
7. **Information Registry → Scholars → Bulk Upload Scholars → Download Template**. Confirm K1 is **LANDBANK Savings Account Number**, immediately after Contact, with Text formatting. Enter new fictional scholar records using an existing active course name and the existing required columns; enter `001234567890` as Text in one bank cell and leave another blank. Preview must show the new header with masked/empty values, never full bank values.
8. Confirm the valid preview. Expected: the original transaction creates only the new profiles, encrypts the nonempty account and preserves zeros on authorized reveal; blank is null. No Student login, scholarship activation, enrollment verification or bank transfer is created. Repeat confirmation must retain existing receipt/idempotency behavior.
9. Upload an old ten-column XLSX and UTF-8 CSV ending at Contact using different new fictional Student IDs. Expected: accepted under unchanged validation, optional bank value blank. Also check new eleven-column CSV with a blank or omitted final value. No existing scholar needs to be reimported.
10. Check numeric/formula bank cells, duplicate Student IDs, existing Student IDs, reordered headers, extra nonempty columns and invalid bank input. Expected: the whole import remains blocked; no partial records. Existing Student IDs must not be merged, even when the bank cell is blank. Cancel/expire a preview and confirm the existing private-file cleanup behavior.
11. With authorized staff, open an appropriate existing billing draft's **Roster Validation** and an authorized existing payment batch's **Record a student payment** selection. Expected: matching current-profile numbers are masked without repeated entry. A missing account number adds no new eligibility or payment blocker. On a payment detail, the profile link supports the explicit reveal for authorized staff. Do not record fictional financial transactions just to reach these pages.
12. Export **All roster members**, **Billing items only**, existing roster/report files, and inspect general scholar listings/default JSON serialization. Expected: no full account number appears. Existing financial totals, original payment snapshots and export scopes remain unchanged.
13. Inspect scholar create/update audits and validation session input through authorized developer tools. Expected: bank audit fields are `[REDACTED]`/null and the field is absent from flashed old input. Verify existing scholar editing, Student profile editing, imports and navigation still work through the current Ngrok deployment.

Static patch review included `git diff --check`; no functional, encryption, browser or migration test result is claimed. The field intentionally does not confirm that an account exists, belongs to a beneficiary or is accepted by LANDBANK.
