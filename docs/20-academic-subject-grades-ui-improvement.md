# Academic subject grades UI improvement

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## 1. Scope and verification restrictions

Redesigned the shared **Subject percentage grades** editor used by Add academic
record, Edit academic draft and Correct academic record. This is a presentation and
local form interaction change built on REC-01, documented in
`docs/19-academic-grade-computation-and-validation.md`.

No automated tests were created or executed. No PHPUnit, Pest, Artisan tests,
browser automation, manual functional testing, sample database records, migrations,
imports, grade saves, verification operations or database resets were performed.
The developer must perform the functional checks below. Successful source
compilation is not evidence of working runtime interactions.

## 2. Existing implementation and UI issues

Inspected `AcademicVerificationController`, `AcademicVerificationRequest`,
`SaveAcademicVerification`, `AcademicRecord`, `AcademicRecordGrade`, the shared
academic form/editor, `academic-grades.js`, existing table enhancement, CSS and the
generic modal component.

Previously each subject occupied an expanded fieldset with identity, six numeric
fields, status, remarks and preview. Adding several subjects made the academic form
long and difficult to compare. Removal immediately spliced a row without confirmation.
The summary used a broad two-column details list. Errors were available in the
page-level validation banner but were not associated with individual editor rows.

The existing shared form already chooses create, draft update or correction actions;
all three continue including the same editor. Existing local grade previews already
handled exact percentage arithmetic, reported averages, special marks and provisional
weighted GWA. Those algorithms were reused rather than rebuilt.

## 3. Implementation summary and files

| File | Change in this task |
| --- | --- |
| `resources/views/components/reported-grades-editor.blade.php` | Compact pending-subject table, committed hidden inputs, row-specific server errors, empty state and responsive summary |
| `resources/views/components/academic-subject-modal.blade.php` | New single reusable add/edit dialog and one shared confirmation dialog for removal/discard |
| `resources/js/academic-subject-editor.js` | New Alpine interaction state, local validation, draft isolation, add/edit/remove, confirmation, focus/scroll handling; delegates all grade/GWA computations |
| `resources/js/academic-grades.js` | Exports the existing decimal parser and exact decimal formatter for reuse; calculation function bodies are unchanged |
| `resources/js/app.js` | Registers `academicSubjectEditor`; retains existing calculator registration |
| `resources/css/app.css` | Scoped academic editor/dialog/summary styles using existing colors and control conventions |
| `docs/20-academic-subject-grades-ui-improvement.md` | This report and unexecuted manual verification guide |

`npm run build` also regenerated ignored local `public/build` assets. No packages
were added. The working tree contained earlier REC-01 changes before this task;
they were preserved and are not claimed as new changes in this report.

No controller, route, request, policy, model, save action, eligibility service,
database schema, authentication, account, bulk upload, billing, email or certificate
storage implementation was changed by this UI task. Existing read-only grade tables
and evaluation screens were left intact.

## 4. Subject grades table

The section provides **Add subject**, a short explanation and a scrollable table:

1. Subject name prominently, with subject code and row number as secondary text.
2. Units.
3. Prelim.
4. Midterm.
5. Semi-Final.
6. Final.
7. Reported average.
8. Computed average, with its unrounded value when complete.
9. Subject result, numeric result when different, and review/exclusion reasons.
10. **Edit** and **Remove** actions.

Result text accompanies green Passed, red Failed and amber review/special-status
indicators. Existing INC, dropped, missing, unverified and reconciliation results
are retained. Blank reported values display an em dash; numeric `0` remains visible.
No Equivalent column or editable Equivalent value is included.

The empty state says **No subjects added yet** and directs the user to Add subject.
Long identity text wraps. The table scrolls horizontally within its own focusable,
labeled region on narrow screens.

The table deliberately uses the existing `data-table-ready` opt-out marker. The
global static table enhancer snapshots/detaches rows and would conflict with Alpine
reactivity and original subject ordering. This editor shows the pending rows in
their original order without static sorting/pagination. Other tables are unchanged.

## 5. Add and edit dialog

One dialog switches between **Add subject grade** and **Edit subject grade**.
It groups fields into:

- Subject information: name, optional code and units.
- Grading periods: Prelim, Midterm, Semi-Final and Final.
- Additional information: reported average, reported status and remarks.
- Calculation preview: computed average, unrounded value, subject result and reasons.

The modal preview delegates to the existing `academicGrades().subject()` assessment.
It updates during input without adding the unsaved draft to the table or summary.

Local validation requires a subject name, applies the existing length/decimal/status
rules, and checks nonblank duplicate codes case-insensitively against other rows.
Errors appear inside the modal with field associations. Invalid input keeps the
dialog open and focuses the first invalid control. Missing grades, blank units,
zero units, special marks and valid discrepancies remain permissible reported data;
they retain existing calculation/review behavior rather than acquiring new rules.

**Add subject** validates and appends one local row. **Save changes** validates and
replaces the selected row at its existing position and with its existing grade ID.
Neither operation sends an HTTP request or independently writes to the database.
The resulting notice explicitly asks the user to save/verify the parent record.

## 6. Cancel, discard and remove

Opening Add clones the empty row. Opening Edit clones the selected committed row.
Changes are isolated in `draft`. Cancel/close/Escape returns immediately when there
are no changes; otherwise a confirmation asks whether to discard the modal changes.
Canceling that confirmation returns to the unchanged modal draft. Confirming discard
leaves the previously committed row and academic summary unchanged.

Remove asks exactly:

> Are you sure you want to remove this subject from the academic record?

Its buttons are **Cancel** and **Remove subject**. Cancel retains all rows. Confirm
splices only the selected pending row and its UI metadata. All summary values then
derive from the remaining rows. No separate deletion request is made. Changes only
reach persistence when the parent form is submitted through the existing save action.

The existing correction action writes a new revision and scopes original grade IDs
to its source record. Removing a pending correction row does not delete that row from
the previous verified revision. Existing certificate/history handling is unchanged.

## 7. Compact academic summary

A responsive grid immediately below the table contains total, passed, failed,
incomplete, dropped, missing and reconciliation counts, total recorded units,
included subjects and included units. A separate prominent card shows **Provisional
percentage GWA** and explicitly says it is not an officially certified Registrar GWA.

All values delegate to the existing live `academicGrades().summary` getter using
committed rows. Modal drafts do not change these values until applied. Numeric result
counts can overlap review flags; the explanatory copy preserves that distinction.

Failing numeric subjects remain included when otherwise eligible for the provisional
denominator. INC, dropped, explicitly unverified, discrepant/missing grades and
nonpositive/missing units retain their existing exclusions and visible reasons.
The official GWA formula/rounding policy remains pending school approval.

## 8. Local state and parent submission

`rows` contains committed plain reported values. `rowKeys` separately provides stable
UI identities; `rowErrors` separately stores server feedback. UI keys/error metadata
never enter the calculator or submitted grade fields. Add/edit/remove synchronizes
these arrays while retaining the order and values of unaffected rows.

Only committed rows produce hidden parent-form inputs:

```text
grades_present = 1
grades[0][grade_id]
grades[0][subject_code]
grades[0][subject_name]
grades[0][units]
grades[0][prelim]
grades[0][midterm]
grades[0][semi_final]
grades[0][final]
grades[0][average]
grades[0][remarks]
grades[0][reported_status]
... contiguous indices for subsequent rows
```

No computed average/GWA or Equivalent input is submitted. Retained grade IDs allow
the unchanged backend to preserve legacy Equivalent data without exposing an editor.
Modal controls have no input names and no nested form; all modal buttons have
`type="button"`. Enter in a text input applies local changes rather than submitting
the parent. Textarea newlines and select/button keyboard behavior remain available.
A parent submit listener also prevents normal event-based submission while a dialog
is open. No local/session storage or separate network endpoint is introduced.

The 60-row technical limit remains enforced by the existing backend and by Add subject.
Editing/removing existing rows remains available at the limit. No new course-load
eligibility restriction is introduced.

## 9. Server validation and recovery

The unchanged request remains authoritative, including authorization, scoped/distinct
grade IDs, distinct codes, grade/units validation, the row limit, source requirements
and correction rules. Local validation is a convenience, not an authorization or
calculation boundary. All arithmetic is recalculated on the server by existing code.

After a rejected parent submission, the editor prefers flashed `old('grades')` over
saved database values. A submitted empty list remains empty when another field
fails. It does not silently reload previously removed saved subjects.

Server errors are mapped to their original submitted row index before display
normalization, then displayed beside that subject and inside its Edit dialog.
They stay attached to the subject when another pending row is removed. Applying a
locally valid edit clears that row's previous feedback locally; the user is told
to resubmit for authoritative server validation. Page-level error reporting remains.
Unrecoverable/malformed request-wide errors remain in the existing global banner.

Browser file inputs still require reselection after a rejected full submission when
the browser clears them; this existing file-input behavior has not been changed.
Previously stored supporting certificates are never removed by local subject edits.

## 10. Accessibility and responsive design

Reused the existing brand variables, record inputs/buttons/cards/badges and Alpine
architecture. Reviewed the generic `x-modal`; its unconditional Escape/backdrop close
and focus behavior do not support this editor's dirty-state confirmation requirements.
Rather than modifying a modal used elsewhere or adding a dependency, these shared
subject dialogs use native HTML `dialog.showModal()` with existing visual conventions.

- Centered 820px maximum editor with subtle backdrop and responsive width.
- Two-column groups become a single column at 640px; no page-wide table overflow.
- Internally scrolling body, with header/footer outside that scroll area.
- Native modal background inertness plus explicit Tab/Shift+Tab containment.
- Accessible dialog titles/descriptions, control labels and error descriptions.
- Initial focus on Subject name; confirmation initially focuses Cancel.
- Escape follows the appropriate cancel/discard path.
- Explicit return focus to the triggering button; after removing that button's row,
  focus falls back to Add subject.
- Background scroll lock is retained while confirmation overlays the editor and
  restored to its prior inline value after all subject dialogs close.
- Status announcements for applied changes and existing calculation previews;
  semantic row/column headers and text results in addition to color.
- No new animation or frontend dependency.

These accessibility/responsiveness properties were reviewed in source only. Actual
keyboard, assistive technology and device behavior requires developer verification.

## 11. Preserved academic rules and security boundaries

The body of each existing JavaScript grade parsing, arithmetic, assessment and
summary function remains unchanged. Two existing helpers were merely exported.
No backend academic or eligibility source was changed in this task.

Preserved four-period mean, exact unrounded 75% comparison, zero-versus-blank
distinction, reported/computed reconciliation, special marks, provisional weighted
GWA and exclusions, manual standing, certified percentage GWA, verification/revision
behavior, reassessment and program-specific academic requirements. Failed subjects
do not acquire automatic scholarship disqualification. No new automatic award action
is introduced. Existing authorization, CSRF, locking and certificate safeguards remain
at the same backend entry points.

## 12. Database and setup

No migration or schema change is needed for this UI. No migration was created or
executed, and no database records were read or modified for functional scenarios.
The prior REC-01 schema/readiness prerequisites from document 19 still apply; this
UI does not bypass the existing pending-upgrade save restrictions. The developer
must review any outstanding earlier migration independently before functional use.

Deploy/rebuild the changed assets with:

```powershell
npm run build
```

This command was already executed locally for compilation. Use the normal deployment
procedure for existing view/asset caches; no dependency installation or new service
configuration is required by this change.

## 13. Actual static checks and review findings

| Check actually performed | Actual result |
| --- | --- |
| `node --check resources/js/academic-subject-editor.js` | Exit 0; no syntax errors |
| `node --check resources/js/academic-grades.js` | Exit 0; no syntax errors |
| `node --check resources/js/app.js` | Exit 0; no syntax errors |
| Static Blade `compileString` on editor, subject-modal component and existing parent form, followed by `php -l` on temporary compiled sources | Three compiled sources; no PHP syntax errors; no views rendered |
| `npm run build` | Exit 0; Vite 7.3.6 transformed 63 modules; production assets compiled |
| Task-start source hash comparison | Zero existing backend/route/database/configuration files and zero existing test files changed during this UI task |
| Calculator source comparison | Removing only the new helper-export/comment block reproduces the exact task-start SHA-256 of `academic-grades.js`; arithmetic, parsing, assessment and summary bodies are unchanged |
| `git diff --check` on changed tracked UI sources | No whitespace errors; Git reported CRLF-to-LF normalization notices only |

Static review covered modal state, add/edit/remove/cancel flows, cloning and hidden
payload synchronization, stable keys versus contiguous indices, old-input recovery,
row error association, parent submit isolation, original row IDs, correction/history
preservation, calculation reuse, responsive styles and keyboard/scroll handling.

Review corrections included separating all metadata from calculation rows, preventing
the static table enhancer from detaching reactive rows, retaining an explicitly empty
old subject list, preserving special-status defaults and leaving select/textarea
keyboard interactions out of the text-input Enter shortcut.

No behavioral PASS claims are made. No automated or manual functional checks were
performed, as instructed.

## 14. Developer manual verification guide — NOT EXECUTED

Use only controlled development data and existing authorized development accounts.
Do not use real academic/student records. Have the developer create any fictional
scholar/semester fixtures through existing approved workflows; none were created by
this implementation. Resolve earlier REC-01 readiness requirements first.

1. Sign in as Registrar Staff (or the retained Test Administrator). Open **Academic
   verification → Add academic record**. Select a fictional existing scholar and the
   correct configured semester. Complete other fields using a fictional Registrar
   source; leave certified GWA blank unless the fixture includes a separate certified
   value. Expect the subject table's empty state and unavailable provisional GWA.
2. Click **Add subject**. Expect the centered Add subject grade modal and focus on
   Subject name. Try Add subject without a name; expect an in-modal error and the
   dialog to remain open. Enter the first sample row below. Expect live mean/result.
3. Click **Add subject** in the modal. Expect one row, updated summary and a notice
   that the parent record still needs saving. Add the second sample. Expect two rows,
   six included units, one Passed, one Failed and provisional GWA 75.00.
4. Click **Edit** on the first row. Change all four components from 80 to 90 and clear
   the reported average. Expect a modal mean of 90.00 but the table/summary to remain
   unchanged until applied. Click Cancel; confirm discard. Expect the original row
   values and provisional GWA 75.00. Repeat, then Save changes; expect the first row
   updated in place and provisional GWA 80.00, with no duplicate row.
5. Reopen a subject, edit a value and press Escape. Cancel the discard confirmation;
   expect the modal draft retained. Escape again and confirm discard; expect original
   table values. Repeat using the close button. Check focus returns to the trigger.
6. Click **Remove** on a row. Cancel; expect no change. Repeat and confirm Remove
   subject; expect only that row removed, all other values/order preserved and all
   counts/units/GWA recalculated. Expect focus on Add subject after removal.
7. Add the other sample rows independently. Check each expected result below. For
   conflicting reported/computed values, expect a warning without overwriting either
   source value. Do not treat permitted review conditions as invalid entry syntax.
8. Enter invalid values such as `101`, `-1`, `80%` or `1e2` in a numeric grade and try
   to apply. Expect local errors, retained draft and no added/replaced table row.
   Repeat with negative units and a duplicate nonblank subject code using different
   letter case. Expect rejection. Blank codes/grades and real numeric zero remain
   supported as described below.
9. Add another valid subject and use the parent **Save draft**. Open **Edit / verify
   draft** from the record detail page. Expect all reported values and ordering
   retained. Edit/remove/add subjects and save again; inspect the saved read-only
   record for exactly the final rows and recomputed backend values.
10. Verify error recovery on a development draft: add/edit/remove rows, then choose
    **Verify record** without the required Registrar source. Expect the page-level
    server error and the full pending subject list retained. Remove all rows and
    repeat; expect the empty list to remain empty rather than reloading saved rows.
    Reattach any unsaved browser file inputs if required after validation failure.
11. To inspect row-specific server feedback, use the browser's development tools only
    on fictional data to change a pending hidden `grades[n][prelim]` to `101` before
    submitting the parent. Expect a backend rejection, error beside the correct row,
    and the error inside that row's Edit dialog. Correct/apply it, remove a preceding
    row if present, then resubmit; expect intact data and proper row associations.
12. Provide the remaining required fictional source/enrollment/standing information
    and choose **Verify record**. Use **Create correction** on that verified record.
    Expect existing subjects preloaded. Enter the required correction reason, edit
    one row, remove another and add a new row. Save the correction. Expect a new
    revision and unchanged previous verified rows/certificates. Inspect any existing
    legacy Equivalent values through historical read-only details, not this editor.
13. Reach the 60-row form limit using distinct fictional subjects. Expect Add subject
    disabled at 60, with editing/removal still available. Remove one; expect Add
    subject available again. Confirm no accidental parent submission from any modal
    button or text-input Enter key.
14. Check at desktop and narrow mobile viewport sizes. Expect wrapping names, a
    horizontally scrollable table region, single-column modal groups, compact summary
    and reachable footer actions with internal body scrolling. With a dialog open,
    expect background page scroll blocked. Use Tab/Shift+Tab to verify focus remains
    in the top dialog. Confirm screen-reader labels/errors/status text as applicable.
15. Confirm existing certified-GWA attestation, manual standing, source selection,
    Save draft/Verify record, correction reason, certificate access and reassessment
    behavior remain intact. No subject modal action should independently persist data,
    change a scholarship decision or activate an award. Verify unauthorized users
    remain denied by the existing protected academic routes/actions.

Every manual step above has actual status **NOT TESTED**. The steps are instructions,
not reports of completed actions or existing fixtures/login credentials.

## 15. Fictional sample data and expected results — NOT EXECUTED

Use these as new subject fixtures in a controlled development academic record.
They are not actual students, grades or scholarship-program policies.

| Code / subject name | Units | Prelim / Midterm / Semi-Final / Final | Reported average / status | Expected result |
| --- | --- | --- | --- | --- |
| UI-A / Fictional Analytics | 3 | 80 / 80 / 80 / 80 | 80 / regular | 80.00 Passed; included |
| UI-B / Fictional Writing | 3 | 70 / 70 / 70 / 70 | 70 / regular | 70.00 Failed; included; not automatic scholarship disqualification |
| UI-ZERO / Fictional Zero Grade | 1 | 0 / 0 / 0 / 0 | blank / regular | 0.00 Failed, not missing; included |
| UI-EDGE / Fictional Threshold | 3 | 74.9999 / 74.9999 / 74.9999 / 74.9999 | blank / regular | Display 75.00, unrounded 74.9999 Failed |
| UI-INC / Fictional Incomplete | 3 | 80 / INC / blank / blank | INC / inc | Incomplete; no complete average; excluded |
| UI-DRP / Fictional Dropped | 3 | blank / blank / blank / blank | blank / dropped | Dropped, not numeric failure; excluded |
| UI-MISSING / Fictional Missing Grade | 3 | 80 / 80 / blank / 80 | 80 / regular | Missing grades; reported average cannot replace components; excluded |
| UI-RECON / Fictional Discrepancy | 3 | 80 / 80 / 80 / 80 | 81 / regular | Computed 80.00 and reported 81 remain; reconciliation; excluded |
| UI-UNVERIFIED / Fictional Unverified | 3 | 85 / 85 / 85 / 85 | blank / unverified | Unverified; numeric Passed count can overlap; excluded |
| UI-NOUNITS / Fictional Missing Units | blank | 90 / 90 / 90 / 90 | blank / regular | Passed arithmetic; units reason; excluded from GWA |
| UI-CONFLICT / Fictional Conflicting Marks | 3 | INC / DRP / blank / blank | blank / regular | Reconciliation; both special categories retained; excluded |

With UI-A and UI-B only, expect provisional GWA 75.00, recorded/included units 6,
two subjects, one Passed and one Failed. Removing UI-B leaves provisional GWA 80.00,
three included units and one Passed. If UI-A is instead edited to all 90 with no
reported average while UI-B remains, expect provisional GWA 80.00. These expected
values follow existing source arithmetic and have not been functionally executed.

## 16. Known limitations and final status

- Runtime add/edit/remove, parent persistence, browser/accessibility and full workflow
  behavior are unverified by request. No screenshots were taken because browser/manual
  testing was prohibited.
- The editor requires JavaScript, BigInt and modern native HTML dialog support.
  No new compatibility polyfill was added.
- Pending local subjects are not persisted until the parent Save draft/Verify record.
  Closing/reloading/navigating away from the page can lose those pending changes;
  no autosave or browser storage was introduced.
- Frontend code comparison/input checks assist entry; backend validation remains the
  final authority, including Unicode/collation and scoped record checks.
- Original persisted row order is loaded as supplied by the existing relationship;
  no new database ordering column, drag/reorder UI or historical rewrite was added.
- This presentation change does not approve an official GWA policy or resolve any
  outstanding school-policy or prior migration prerequisites from document 19.

**Final status: IMPLEMENTED — PENDING MANUAL VERIFICATION.**
