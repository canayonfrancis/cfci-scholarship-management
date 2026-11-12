# Scholar Information enhancement

Implemented and verified on 2026-11-11. This extends the existing `students` identity, ScholarController/ScholarRequest, staff policies and Blade record screens. Authentication, Registrar certification, scholarship validation, assignment activation, rates and financial workflows retain their existing authority and rules.

## Structure and field mapping

The inspected schema already had Student ID (`student_number`, unique string), first/middle/last names, email, phone, record status and an optional existing user link. It had no sex, birthdate, parents, structured address, official identifiers, demographic indicators or academic-program reference table. Registrar `academic_records` already preserve semester-specific course/year strings, verification revisions and private evidence; `academic_periods` already represent academic year plus semester. No location-reference table exists.

Migration `2026_11_11_000007_expand_scholar_information.php` adds **24 nullable student columns** and the minimal `academic_programs` reference table. It does not rename/backfill existing columns, create student accounts, recreate periods, introduce a duplicate scholar table or generate academic certification rows.

| Information | Storage / behavior |
| --- | --- |
| Student ID | Existing unique `student_number`; existing normalization and 50-character limit retained. |
| LRN | New encrypted string `lrn`: exactly 12 digits or `0` when unavailable; null for an incomplete profile. |
| PhilSys / National ID | New encrypted string `philsys_id`: four groups of four digits separated by hyphens, optional. |
| 4Ps ID | New encrypted string `four_ps_id`, optional; preserves leading zeros and nonnumeric labels. |
| Last, given, middle names | Existing `last_name`, `first_name`, `middle_name`. Last/given names remain registration requirements. |
| Extension / suffix | New nullable `suffix`; appended to displayed full names. |
| Sex | New nullable numeric `sex`: `0` Male, `1` Female; labels shown, no inferred answer. |
| Birthdate | New nullable DATE `birthdate`; valid YYYY-MM-DD, no future birthdate. No stored age. |
| Complete program name | New nullable `academic_program_id`, restrictive FK to registered `academic_programs`; this is distinct from scholarship programs. |
| Year level | New nullable integer `year_level`, chosen from `config/scholar-profile.php`. |
| Academic year and semester | New nullable `academic_period_id`, restrictive FK to existing `academic_periods`. One dropdown selects a valid year/semester pair. |
| Father’s names | New optional `father_last_name`, `father_first_name`, `father_middle_name`. |
| Mother’s maiden names | New `mother_maiden_last_name`, `mother_maiden_first_name`, `mother_maiden_middle_name`; last/given required only for completeness. No copying from student names. |
| Permanent address | New independently editable `street_barangay`, `city_municipality`, `province`, `region`, `zip_code`; ZIP remains a string. No guessed location tables or stricter unconfirmed ZIP policy. |
| Contact number | Existing `phone`; recognized Philippine formats normalize to a 10-digit mobile starting with 9 or an 8-digit landline. |
| Email | Existing `email`, validated without DNS lookups; does not need to match a staff/login email. |
| Disability | New nullable `disability_type`: all 11 requested choices, including explicit `none`; null means not provided. |
| Solo-parent dependent | New nullable boolean `solo_parent_dependent`; false is an answered No, null is unanswered. Required only for completeness. |
| First-generation student | New nullable boolean `first_generation_student`; optional, no assumed No. |
| Indigenous People group | New nullable free-text `indigenous_people_group`; no surname/address classification or boolean substitution. |

The complete enumeration is implemented, including separate components of parent names/address and the semester selection. No fields were dropped to fit the prompt’s shorthand reference to “29 fields.”

## Academic information and retained history

The new academic fields on `students` represent its **current administrative profile**, not Registrar-certified enrollment or eligibility evidence. Scholar-profile writers may maintain those fields under their existing permissions. Giving them the ability to edit a verified `AcademicRecord` would conflict with the existing Registrar-only correction policy, so those records are not repurposed or edited here.

Every profile create/update appends an atomic audit event. Academic program name, year, semester and year level are retained as snapshots, shown in paginated **Academic profile activity**. Moving a current profile to another year/semester leaves prior snapshots, original Registrar records, grade revisions, assignments, validation decisions, approvals and financial history intact. Academic periods referenced by current profiles **or their retained snapshots** cannot be relabeled. Existing academic-record uniqueness/revision rules remain enforced unchanged.

There was no course/program reference table to reuse. Administrators register actual school program names at `/academic-programs`, linked from the scholar form. They may deactivate/reactivate entries; linked names are retained. No school offerings are inferred from random historical course strings. Fresh application reference lists remain empty until an Administrator supplies registered names. Inactive programs remain valid on existing linked profiles but cannot be newly selected.

The initial year-level entry choices are 1–6, configurable in `config/scholar-profile.php`. These are form choices, not a scholarship duration cutoff, graduation rule or automatic eligibility test. No year, semester, enrollment status or actual validation context is selected automatically. Existing 2007/future academic-year handling is reused without reseeding calendar rows.

## Saveability, completeness and contact review

Basic registration still requires only Student ID, given name, last name and record status. Other fields may remain null. Any supplied value must pass its format/relationship validation.

`ScholarProfile::missing()` and Student’s `hasCompleteProfile()` / `missingProfileFields()` compute completeness on demand; no cached complete flag or eligibility flag is stored. Required official values include LRN, sex, birthdate, current program/year/period, mother’s maiden last/given names, all five address fields, phone, email and solo-parent answer in addition to core identity. `0` and false count as answered values. Optional fields never block completeness.

Common mobile forms `09171234567`, `9171234567`, `+63 917 123 4567` and `00639171234567` normalize to `9171234567`. Spaces/parentheses/hyphens in an eight-digit landline are removed. An unchanged legacy number outside these confirmed formats remains saveable for review instead of being discarded; it does not satisfy official contact completeness. The migration does not normalize or overwrite any existing phone number.

## Permissions and sensitive information

- Existing Administrator, Registrar and Coordinator profile-write permissions are retained. Only those roles may view protected IDs, birthdate, family, address and additional demographic details. Finance/Viewer retain their existing basic record, current academic summary and contact access.
- LRN, PhilSys and 4Ps values use Laravel encrypted casts with existing application encryption configuration, are hidden from array/JSON serialization, and never appear in the directory. They are decrypted only when needed by authorized profile pages.
- Student before/after audit payloads redact all three identifiers. No plaintext copies of them are recorded in these audit events.
- Validation failures retain normal form input. Protected identifiers are excluded from flashed session input; new entries must be re-entered after an error. Editing forms retain the existing saved identifier values. This exception is explained on the form.
- Scholar pages use private, no-store cache headers. Unauthorized direct edits remain rejected server-side. Output uses Blade escaping. No account, role, scholarship or approval is mass-assigned from profile input.

## Interfaces and sample data

Create/Edit forms retain the dashboard palette, inputs, buttons and responsive grids. Eight sections cover identification, personal, academic, family, address, contact, additional and scholarship information. Labels distinguish basic requirements, complete-profile requirements and optional values. The detail screen shows a completeness badge, missing-field list for authorized profile staff, grouped details and retained history. The directory adds academic program/year/semester and completeness while keeping search, scholarship/status filters, pagination and View/Edit permissions.

## Scholar Records academic-year filter (2026-11-12)

The Scholar Records directory now includes an **Academic year** dropdown alongside search, active scholarship and record status. Choices come from distinct registered `academic_periods.academic_year` values, newest first; a year appears once even when both semesters are registered. No year is selected automatically.

Filtering matches the Student's **current academic profile period**, the same year displayed in the directory's Academic year / semester column. Both semesters of the selected year are included. Registrar revisions, earlier profile snapshots, assignments and billing history do not make a scholar match a different current-profile year. The query uses the existing `academicPeriod` relationship without multiplying scholar rows.

**All academic years** includes profiles whose year is not yet provided. A specific year excludes those unknown profiles; registered years with no matching scholars show the normal empty state. Archived scholars are included unless record status is also filtered. The filter does not establish certified enrollment, scholarship eligibility or historical as-of status.

Academic year combines with the existing name/ID/email search, active scholarship and record-status filters. The GET parameter is `academic_year=2026-2027`; selected values remain visible and pagination retains every active filter. Submitting the form starts again at page 1. **Clear** removes all directory filters. Unregistered, malformed and array-valued year inputs are rejected server-side.

This reuses the existing staff read permissions, period records, Blade fields and responsive design. No migration, new school-year data, financial mutation, profile rewrite or dashboard filter was introduced. Three feature cases cover both semesters, current-versus-historical membership, archived/unknown profiles, distinct year options, empty results, combined filters, pagination and invalid input; verification is recorded in [progress.md](progress.md).

## Development fixtures and verification

Fresh development fixtures use five `[SAMPLE]` academic programs and produce **45 complete / 35 incomplete profiles** among the original 80 students. Historical students retain an unspecified program instead of asserting old course offerings. LRN is `0` or null; generated PhilSys values stay null; 4Ps references use explicit `SAMPLE-*` strings. Non-dialable `00000000` landline-format placeholders are confined to fictional fixtures. Optional indicators include both answers and null. No production/manual student data is used.

Existing completed datasets are not retroactively rewritten. A new separate **`cfci_sms_profiles_demo`** was created and verified. It has the usual 80 seed students plus two identifiable browser-created examples, totaling **82 students: 46 complete / 36 incomplete**. The earlier `cfci_sms_samples_demo` retains its original 80 students with new fields null. Source application `cfci_scholarship` retains its three manual students unchanged.

```powershell
php artisan migrate --no-interaction
php artisan sms:seed-demo --database=cfci_sms_profiles_demo --academic-year=2026-2027 --synthetic-policy --no-interaction
php artisan test --filter=ScholarInformationTest
php artisan test
npm run build
```

To browse the new sample profiles, use a separate terminal:

```powershell
$env:DB_DATABASE = 'cfci_sms_profiles_demo'
$env:DB_URL = ''
php artisan serve --port=8001
```

The existing sample Administrator is `sample.administrator@example.test`, initial password `CfciSample2026!`. The same Finance/Registrar/Coordinator/Viewer accounts documented in `sample-data.md` are available. These process-scoped overrides leave `.env` unchanged.

## Executed verification

- **Full SQLite suite: 260 passed, two MySQL-only concurrency skips / 2,544 assertions.** Existing validation, billing, approval and financial-history tests remain passing.
- **41 new ScholarInformation cases / 305 assertions:** all requested fields, complete/partial creation, edit, identifiers/encryption/redaction, invalid formats, phone normalization/legacy retention, zero/false/null distinctions, registered-program selection and authority, protected views/posts, validation input handling, uniqueness, retained academic/scholarship history, existing billing eligibility, period identity after progression and transaction rollback.
- Updated sample suite: **11 passed / 360 assertions**, including complete/incomplete samples, relationships, repeatability and rollback. Existing phone test now expects required normalization.
- Applied the additive migration to both existing local MariaDB databases. Before/after fingerprints across **29 original non-infrastructure tables per database** confirmed all pre-existing values and counts preserved, apart from the expected new migration bookkeeping entry. All 24 new fields remain null for the three source and 80 older-demo students. Birthdate is physically a DATE; `.env` and credentials are unchanged.
- New persistent MariaDB fixture: **103 foreign-key constraints** verified, all **36 existing eligible billing items** still valid with correct totals; 310 evidence files remain present. Repeating the new demo seeder preserved every table, including browser edits and the two added students. No received funds or payouts were generated.
- Real Chrome workflow passed: complete and partial create, edit, normalized contact, retained No/Female selections, protected Finance view and directory, responsive desktop/mobile layouts and no JavaScript exceptions. Screenshots were inspected. The browser server/processes were stopped afterward.
- Production frontend build, Blade compilation, Pint and whitespace checks passed.

School grading rules, CoScho eligibility, actual active/source configuration and confirmed calendar dates remain pending under the existing policy documents. They are independent of this completed profile enhancement. Real program names remain an Administrator configuration input; no additional attachment is needed.
