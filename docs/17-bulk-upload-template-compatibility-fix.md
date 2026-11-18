# Bulk Upload Scholars template compatibility fix

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

Targeted source changes and independent read-only package inspection are complete.
The developer supplied `docs/cfci-scholars-template.xlsx` after the initial request.
Inspection confirms that the old blanket external-relationship rejection conflicts
with its three automatic Email mailto links. Its ordinary workbook content type is
correct. The different, quoted UI format-error message is not explained by this
file's manifest and the inspected source; that runtime discrepancy remains unconfirmed.

No automated test cases were created or executed. No manual/browser functional
testing, import, account creation, database mutation or migration was performed.
Existing tests were left intact. This report contains no functional testing verdict.

## Root cause analysis and evidence

Reviewed the required [implementation report](16-bulk-scholar-upload-implementation.md),
ScholarImportController, ScholarImportRequest, ScholarImports, ScholarSpreadsheet,
ScholarImportTemplate, ScholarImportValidator, ScholarProfile, relevant Student
fields/validation, and the existing private-storage/transaction/authorization flow.
No spreadsheet dependency is installed; parsing/generation use DOM/libxml, ZipArchive
and PHP CSV facilities.

### Exact quoted error: its previous source and all triggering conditions

Previous location: `ScholarSpreadsheet::xlsx()`, immediately after parsing
`[Content_Types].xml` and before reading the hardcoded workbook path. The relevant
logic initialized `$validWorkbookType = false`, iterated namespace-aware `Override`
elements, and assigned the flag **only** when `PartName === '/xl/workbook.xml'`.
The assigned value was an exact comparison against:

```text
application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml
```

If the final flag was false, it raised:

> Upload an ordinary .xlsx workbook. Macro-enabled and other workbook formats are not supported.

Therefore this particular branch could run when:

1. No Override element was found in the expected package content-types namespace,
   including missing or incorrectly namespaced declarations.
2. No such Override had the exact literal `/xl/workbook.xml` part name. A workbook
   legitimately resolved to another internal path, or whose effective type came
   from a Default declaration, was not considered.
3. A matching Override's ContentType differed from the exact string, including a
   genuinely unsupported type or differences in MIME casing/surrounding whitespace.
4. Invalid duplicate matching Overrides existed and the final matching one set the
   flag to false. The new parser explicitly rejects duplicate declarations instead.

The previous parser already used namespace-aware DOM; it did **not** search raw XML
with substring matching to find the workbook content type. XML declaration, normal
formatting/attribute order and a supported BOM alone are not established causes.
Malformed XML, unreadable ZIPs, missing XML parts, unsafe paths and resource-limit
errors take other branches. Header/row/suffix/course/email validation occurs later
and cannot cause this particular format flag to be false.

**Important limit:** independent package inspection found exactly one matching
Override in the expected namespace, with the exact expected type. Thus this file's
declaration meets the previous format flag's condition. The quoted-error cause is
not established by the source/package evidence. Stale deployment code, a different
upload or another runtime discrepancy are possibilities, not confirmed findings.
No prohibited application request was performed to investigate that discrepancy.

### Confirmed compatibility defect: blanket external-relationship rejection

Previous source: `ScholarSpreadsheet::xlsx()` scanned every `.rels` part and rejected
every relationship with `TargetMode="External"`, regardless of relationship type,
source worksheet or cell reference. This scan ran **before** the content-type check.

The actual `xl/worksheets/_rels/sheet1.xml.rels` contains three hyperlink
relationships with `TargetMode="External"` and query-free `mailto:` targets.
`sheet1.xml` references these IDs at I2, I3 and I4. Consequently the previous source
would reject this workbook at its **external workbook links** branch before its
format-type flag was reached. This is the confirmed compatibility root cause from
source/package evidence. It does not explain the different quoted UI message.

### Other confirmed limitations and potential issues

| Finding | Evidence and classification |
| --- | --- |
| Workbook and shared-string paths were hardcoded | Previous `xlsx()` assumed `xl/workbook.xml`, its fixed `.rels` path and `xl/sharedStrings.xml`. Confirmed OPC compatibility limitation. |
| Content type used only one explicit Override/path | Previous flag did not resolve root officeDocument relationships or effective Default/Override types. Confirmed limitation. |
| Normal empty rows were already skipped | Actual rows 5–1001 contain 9,970 `t="s"` cells referencing blank shared strings. Previous `blank()` already ignores this normal form. They are not established as the rejection cause. |
| Typed empty cells could produce spurious failure | Previous cell decoding interpreted `t="s"` before checking for an absent/blank value, and could flag other empty typed cells as unsupported. Confirmed source edge case; no such missing-value cells were found in the supplied workbook. |
| Unicode-only whitespace was not consistently blank | Previous PHP `trim()` did not cover all Unicode separators. Confirmed general source limitation; the actual 997 blank shared-string rows are already empty. |
| `SR` was already allowed | Previous suffix rule was nullable string ≤30. It had no case-sensitive Jr./Sr. whitelist. The provided `SR` alone is not an established rejection cause. Common-form normalization was missing. |
| Template's ordinary content type was already correct | Generator declared the required workbook type and a root officeDocument relationship. No incorrect generated type was established. |
| MIME validation is independent | Existing upload allowlist includes ordinary XLSX/ZIP and supported CSV MIME types. Nothing in the quoted content-type error proves an HTTP MIME rejection. |

## Independent workbook package inspection

Inspected read-only: `docs/cfci-scholars-template.xlsx`.

- Size: **42,013 bytes**.
- SHA-256: `732bfe7cfd92102d36d717f93078ccef3fe9c2188932f07e7933a4cbf580fa44`.
- ZIP entries: **14**; total expanded bytes **454,663**; largest entry **425,889**.
- Detected MIME: `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
- Final hash comparison confirms the populated workbook was not modified.
- Git inspection confirms it is untracked and excluded by the new exact-path
  `.gitignore` rule. It was not staged, committed, copied into fixtures or imported.
- Shared strings: **65**. Data worksheet rows: **1,001**, including the header.
- The workbook was not imported, modified, added to the Git index or committed.
  A scoped `.gitignore` entry excludes this populated workbook from accidental
  future inclusion. Inspection outputs contain structural metadata, not Student values.

| Independently inspected property | Compatibility consequence |
| --- | --- |
| Readable ZIP with .xlsx extension and normal MIME | Meets the existing format/MIME category; package sizes are below configured safety limits. |
| `[Content_Types].xml` has the expected Types namespace and exact ordinary `/xl/workbook.xml` Override | Independent manifest inspection classifies the old type-flag comparison as true. This declaration is not a confirmed cause of the quoted message. |
| Root officeDocument relationship targets `xl/workbook.xml` | Correct OPC discovery; first sheet is Scholars, followed by Instructions and Courses. |
| No VBA/macro content in package paths, relationship metadata or content-type declarations | Ordinary workbook, not a macro-enabled workbook. |
| All ten headers in their exact required order | Compatible data header, unchanged by the fix. |
| All three Student ID and Contact cells have shared-string type `s` and begin with zero | Values can be retained as text without reconstructing digits. |
| Styles contain Text number format 49, including Excel's extra saved style | Existing Text formatting retained; no need to reject the added hyperlink/style metadata. |
| All three Course values exist on Courses | Template-reference consistency confirmed; current database active-course membership was not queried. |
| All three Sex/year values match the instructed choices | No value-choice conflict found in package inspection. |
| EXT at row 4 is `SR`; other supplied EXT cells are blank | Previously valid; new imported row 4 normalizes to `Sr.`. |
| Exactly rows 2, 3 and 4 are populated; rows 5–1001 contain only blank shared strings | Expected application record count is three. No need to delete the prepared empty rows. |
| Exactly three external relationships, all simple query-free mailto hyperlinks on I2/I3/I4 | These trigger the previous blanket external-link ban; the new exception is limited to this shape. |
| Zero formula cells in the Scholars worksheet | Formula prohibition can remain unchanged. |
| Excel added internal theme, properties and person metadata | Ordinary extra internal metadata is not inherently a macro or external data connection. It is ignored as row data. |

Specific parts inspected, without copying private scholar content:
`[Content_Types].xml`, `xl/workbook.xml`, `xl/worksheets/sheet1.xml`,
`xl/worksheets/_rels/sheet1.xml.rels`, `xl/sharedStrings.xml`, `xl/styles.xml`.
No actual Scholar names, addresses or contact values are copied into this report.

## Workbook/package validation changes

New internal helper: `ScholarWorkbookPackage::inspect()`.

- Index ZIP entries with the existing limits, duplicate/path guards and bounded
  reads. Macro, linked-workbook/data-connection and embedded executable/OLE/ActiveX
  content receive distinct messages and remain rejected.
- Parse the actual Types/Relationships/workbook/worksheet roots with DOM namespaces.
  XML declarations, supported encodings/BOMs, whitespace and attribute order do not
  require a literal raw XML match. DTD/entities remain forbidden; `LIBXML_NONET` is
  retained, and no entity-substitution or external-DTD-loading option is enabled.
- Find the workbook through the package root's internal `officeDocument`
  relationship. Resolve package-absolute and relative internal targets with bounded
  dot-segment normalization; root escape, URL schemes, query/fragment targets,
  missing parts, invalid/duplicate relationships and unsafe paths are rejected.
- Compute a part's effective content type from an Override, otherwise the Default
  for its extension. MIME type casing/outer whitespace are normalized. Missing,
  ambiguous, unsupported and macro-enabled types get appropriate messages.
- Resolve the first worksheet and optional shared-string table through workbook
  relationships. Extra ordinary Excel-generated internal properties/styles/metadata
  are not rejected merely for being absent from the original generated workbook.
- Validate worksheet/shared-string/relationship content types and namespace roots.
  Strict OOXML and unsupported package/cell formats remain outside this importer.

This follows OPC's distinction between parts, relationships and declared content
types; the main part is discovered by its relationship rather than assumed storage
path. See [Microsoft's parts overview](https://learn.microsoft.com/en-us/previous-versions/windows/desktop/opc/parts-overview)
and [Microsoft's content-type discussion](https://learn.microsoft.com/en-us/archive/msdn-magazine/2009/november/application-guidelines-on-digital-signature-practices-for-common-criteria-security).

## Email hyperlink handling

Only this external relationship exception is accepted:

1. The source is the selected first data worksheet.
2. Its exact relationship type is the standard Office Open XML `hyperlink` type.
3. A worksheet hyperlink references that relationship ID on an individual **I-column
   Email data cell**, row 2 through the independent worksheet safety limit.
4. It is a simple `mailto:` target with a syntactically valid email address. URI
   queries, headers, additional locations, control characters and unsupported target
   shapes are rejected. Percent-encoded characters are inspected after decoding;
   `+` aliases are not converted to spaces.

Other external relationships, orphaned external hyperlinks, non-Email cell links,
web/file/javascript links, linked workbooks and data connections remain rejected.
The helper does not open a URL, call mail/HTTP clients, navigate a link or evaluate a
formula. No network request is made. Existing email validation uses no DNS rule.

The cell value from shared/inline/plain strings remains authoritative. The metadata
address is never substituted into a row or persisted. A syntactically invalid actual
Email cell remains invalid even if its hyperlink target is a valid address.

Microsoft documents the worksheet reference and relationship-ID distinction in its
[Hyperlinks reference](https://learn.microsoft.com/en-us/dotnet/api/documentformat.openxml.spreadsheet.hyperlinks?view=openxml-3.0.1).

## Template generator changes

- Reuse `ScholarWorkbookPackage::WORKBOOK_TYPE` for the existing correct ordinary
  workbook content-type declaration, avoiding generator/parser value drift.
- Explain normal Email entry and the narrow mailto metadata exception.
- Explain suffix normalization and empty formatting/string/whitespace rows.
- Preserve exactly Student ID, Last Name, First Name, Middle Name, EXT, Sex, Course,
  Year Level, Email, Contact, in order. Middle Name may be blank and EXT is optional.
- Preserve Text styles for Student ID/Contact, frozen/styled header, worksheets and
  order, dynamic active course names, existing Sex/year choices and all limits.
- Do not add formulas, macros, hyperlinks, hardcoded course lists or connections.
  Existing course-dropdown defined ranges are unchanged; they are not cell formulas
  and are never evaluated by the importer.

The controller's dynamic database course query and file-download flow are unchanged.

## Suffix and empty-row changes

`ScholarProfile::normalizeSuffix()` is used by the import validator only:

| Input (case-insensitive) | New imported value |
| --- | --- |
| Blank | null |
| JR, Jr, Jr. | Jr. |
| SR, Sr, Sr. | Sr. |
| II, III, IV | Uppercase corresponding numeral |
| Other legitimate suffix | Trimmed original, subject to existing free-text ≤30 rule |

No historical suffix is rewritten. This uses the existing template's Jr./Sr.
presentation; the database has no stricter enumerated suffix convention. Unsupported
length/control/formula content still fails rather than adding an unapproved whitelist.
The preview retains source text, so `SR` can appear there while the saved suffix is
`Sr.`. Confirmation uses the same normalization and saved-field postconditions.

`ScholarSpreadsheet::xlsx()` now identifies a genuinely empty non-formula cell
before interpreting its cached type. Blank shared strings, empty inline strings,
formatting-only cells and surrounding Unicode whitespace/BOM separators are ignored.
CSV's existing parser uses the same Unicode-aware row trimming. A formula, actual
error value or partially filled row is not silently discarded. Physical row numbers
are retained; the record counter increments only for populated/error rows. Independent
worksheet, archive, XML, shared-string, file-size and record limits are unchanged.

## Security and existing behavior retained

- Authentication, CSRF, existing create-role authorization, per-owner preview access
  and throttles are unchanged.
- Private retained originals, encrypted server state, source hashes, expiry and
  filesystem locks remain in ScholarImports.
- Preview and final confirmation call the same ScholarSpreadsheet/validator; no
  trusted client row source, subset import or validation bypass was introduced.
- Current database duplicate checks, course validation, transaction retries,
  all-or-nothing writes, saved-field/User-link checks, audit events and durable
  idempotent receipts remain unchanged.
- ID/Contact strings are not converted to numbers. Numeric Excel ID/Contact cells
  remain rejected to avoid inventing zeros/digits already lost by Excel.
- CSV support remains. No scholarship, roster, billing, account/authentication,
  database schema, email configuration or sending behavior was changed.

## Files and dependencies

Created:

- `app/Services/Records/ScholarWorkbookPackage.php`
- `docs/17-bulk-upload-template-compatibility-fix.md`

Modified:

- `.gitignore` — excludes only `/docs/cfci-scholars-template.xlsx` to protect the
  populated personal-data workbook; no workbook was staged or committed.
- `app/Services/Records/ScholarSpreadsheet.php`
- `app/Services/Records/ScholarImportTemplate.php`
- `app/Services/Records/ScholarImportValidator.php`
- `app/Services/Records/ScholarProfile.php`
- `app/Http/Requests/ScholarImportRequest.php` — macro-specific extension/MIME error
  wording; extension/MIME/size allowlists were not expanded.
- `resources/views/scholars/bulk-upload.blade.php` — guidance only, existing layout.
- `docs/16-bulk-scholar-upload-implementation.md` — dated compatibility cross-reference.

No dependencies or migrations were added. No configuration change, route cache
rebuild or frontend asset build is required for these PHP/template-copy changes.
Deploy through the normal PHP deployment process, including its usual opcode cache
refresh if applicable. No application setup command was executed.

## Static review and actual checks

Reviewed source references/method definitions, namespace/URI/type handling, package
paths, harmless-vs-unsafe relationship distinctions, actual-cell authority, suffix
mapping, empty-row counting and reuse in confirmation. The original generator already
had the correct standard workbook type and Text formats; these were retained.

Source-only commands:

```text
php -l app/Services/Records/ScholarWorkbookPackage.php
php -l app/Services/Records/ScholarSpreadsheet.php
php -l app/Services/Records/ScholarImportTemplate.php
php -l app/Services/Records/ScholarImportValidator.php
php -l app/Services/Records/ScholarProfile.php
php -l app/Http/Requests/ScholarImportRequest.php
php vendor/bin/pint <the same six PHP files>
git diff --check -- <changed tracked source/view/docs paths>
```

An initial source-lint run found duplicate private helper declarations in the draft
package helper. They were removed. Final PHP syntax checks reported no syntax
errors and Pint completed source formatting. These checks do not execute the
importer and are not functional tests.

Read-only independent ZIP/XML inventory and native PHP file-info inspection were
performed after the developer supplied the workbook path. They inspected only the
existing package and metadata; they did not invoke the application's parser,
preview, validator, database or confirmation action. No replacement workbook or
functional test harness was generated. No PHPUnit, Pest, Artisan test, frontend
test suite, browser automation, manual functional scenario, import, migration or
sample record insertion was executed. The original workbook hash is retained above.

## Manual verification instructions — prepared only, not executed

Use an existing Registrar, Coordinator or Test Administrator account with Student
create permission, and a controlled development database. No credentials are
invented or included here. Use fresh fictional identities if the supplied workbook
contains real records or existing database identities.

1. Open **Scholars → Bulk Upload → Download Template**. Open the download in Microsoft
   Excel. Expect Scholars first, then Instructions and Courses; exact ten headers,
   existing style, Text ID/Contact cells and current active courses.
2. Enter exactly three valid new scholar rows on rows 2–4. Leave one Middle Name and
   EXT blank, use `JR` on another row and `SR` on the third. Keep original leading
   zeros and choose existing active courses and accepted Sex/year values.
3. Type valid Email values normally and allow Excel to create its automatic mailto
   hyperlinks. Leave all remaining prepared data rows empty. Save as ordinary .xlsx.
4. Select the saved file in **Bulk Upload Scholars** and choose **Preview**. Expect
   total 3, valid 3, invalid 0 and duplicate 0, assuming current database values are
   valid and IDs are new. Expect rows 2, 3 and 4 only. No record may be inserted yet.
5. Confirm leading zeros/contact spelling, names, course and row numbers. No generic
   workbook-format or unsafe-external-link error should arise solely from the
   automatic simple mailto metadata. No manual hyperlink/XML removal is needed.
6. Check the review confirmation and select **Confirm Import**. Expect an atomic
   three-record result, then search the three IDs in Scholars. Expect canonical
   Jr./Sr. and intact text identifiers. Existing records must remain unchanged.
7. Repeat that successful confirmation. Expect its existing receipt and no second
   batch. This and all other functional steps remain for the developer to execute.

Additional scenarios and expected outcomes — **all not executed**:

| Developer action | Expected outcome |
| --- | --- |
| Preview the original populated workbook in the controlled development environment | Expected only its three populated records, subject to current row/database rules and duplicate checks; no functional preview was performed by the agent |
| Leave 997 formatting-only/empty-string/Unicode-whitespace rows | No extra records or required-field errors; actual populated row numbers retained |
| Partially complete a fourth row | That physical row is invalid; whole import blocked |
| Use SR, Sr, Sr., JR, Jr, Jr., ii, III, iv or blank EXT | Accepted common forms normalize; blank becomes null |
| Use an EXT longer than 30 characters or unsafe formula/control content | Row-specific invalid EXT/content error |
| Put a valid mailto target on an invalid actual Email cell | Actual Email remains invalid; metadata is never substituted |
| Use mailto with query/header parameters, a web/file/javascript hyperlink, a non-Email external hyperlink or an unreferenced external relationship | Explicit external-link rejection |
| Use a linked workbook/data connection, VBA, macro-enabled workbook or embedded executable content | Appropriate security/unsupported-format error |
| Add a formula to a scholar cell | Formula rejected; no calculation or import |
| Break a required header or a package XML/relationship | Specific header or corrupted/invalid-package error |
| Duplicate a Student ID in file or use an existing database ID | Duplicate row errors; existing records unchanged; entire import blocked |
| Use an inactive/unknown course, bad contact or invalid email | Existing row validations block the batch |
| Exceed the upload, populated-record or independent ZIP/XML/worksheet limit | Corresponding limit error; no partial batch |
| Export only Scholars as CSV UTF-8 with fresh identities | Existing CSV preview/import and suffix normalization retained |
| Try unauthorized roles or another operator's preview | Existing server-side permission/owner checks deny access |

## Remaining limitations

- The populated workbook's package has been independently inspected. Its content
  type is valid and the old external-mailto rejection is established. The different
  quoted format-error message still needs developer-side runtime/deployment
  investigation if it persists; this report does not invent a manifest defect.
- Actual Excel save/reopen interoperability, UI behavior, preview counts, database
  import/rollback and concurrency are not runtime-verified by the agent.
- This is still a bounded ordinary transitional XLSX/UTF-8 CSV importer, not a
  spreadsheet engine. Strict OOXML, macro/binary/password-protected formats and
  unsupported complex workbook features remain unsupported.
- Only simple automatic mailto hyperlinks attached to first-sheet Email data cells
  are allowed externally. Link queries/headers, ranges and other external metadata
  remain outside this narrowly approved exception.
- A course on Courses is not proof it remains active in the current database.
  Duplicate IDs or changed reference data can still prevent a valid file's import.
- Re-upload pending previews created before this fix. Suffix/row normalization can
  change the server fingerprint; confirmation intentionally requires fresh review.

**Final implementation status: IMPLEMENTED — PENDING MANUAL VERIFICATION.**
