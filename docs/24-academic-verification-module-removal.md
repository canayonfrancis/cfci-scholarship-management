# Academic verification module removal

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Scope

Removed the standalone Academic verification module at the user's request:

- Removed its Billing and Validation navigation entry.
- Removed all `/academic-verifications` routes, including list/detail, create/store,
  draft editing/verification, correction and the old certificate URL.
- Removed the module controller, FormRequest, three pages, subject editor/modal
  components and the editor's JavaScript. The editor/calculator registrations are
  removed from the production app entry point.
- Removed academic record creation/history buttons from Scholars and academic
  management actions from Roster. The obsolete roster Action column was removed.
- Replaced evaluation links to removed record pages with read-only retained source
  information and protected certificate links. The evaluation progress stage is now
  labeled Academic requirements.

## Retained data and safeguards

No academic record, grade, certificate, student, policy, evaluation, roster, financial
record or audit entry was modified/deleted. No database migration was introduced or
executed. AcademicRecord/AcademicRecordGrade models, relationships, history and
immutability protections remain.

Evaluations still read their existing academic sources and enforce the same academic
policy, verified enrollment/grades, certified GWA, reassessment and activation checks.
Missing or unverified academic sources remain pending review; removal does not make
them automatically eligible. The backend arithmetic, 75% comparisons and provisional
GWA inclusion rules are unchanged. Existing read-only grade tables remain.

No replacement academic entry/verification/correction workflow was introduced. New
academic sources cannot be entered or verified through this removed interface.
Existing missing-source cases may therefore remain pending until an independently
approved replacement data-entry mechanism is provided.

The internal `SaveAcademicVerification` action and academic policies are retained as
domain code; they have no public module routes. The pure `academic-grades.js` helper
is retained unchanged but is no longer registered/imported into the app bundle.
Shared dialog styles remain because evaluation dialogs still use them.

## Protected historical certificate downloads

To avoid losing access to existing evidence, the old certificate download logic was
extracted into the read-only `AcademicCertificateController`:

```text
GET /academic-certificates/{record}/{document}
name: academic-certificates.download
```

It remains authenticated and uses the original AcademicRecord `view` policy. It checks
document ownership, academic disk, grade-certificate type, the exact record storage
prefix, existence and matching file checksum. Filename sanitization and private,
no-store/nosniff download headers are preserved. It exposes no record list, create,
verify, update, correction or public file URL.

Evaluations' Academic records and History tabs use a read-only retained-source component.
The evaluation controller eager loads existing certificate relations for those displays.

## Files

Deleted:

- `app/Http/Controllers/AcademicVerificationController.php`
- `app/Http/Requests/AcademicVerificationRequest.php`
- `resources/views/academic-verifications/index.blade.php`
- `resources/views/academic-verifications/show.blade.php`
- `resources/views/academic-verifications/form.blade.php`
- `resources/views/components/reported-grades-editor.blade.php`
- `resources/views/components/academic-subject-modal.blade.php`
- `resources/js/academic-subject-editor.js`

Created:

- `app/Http/Controllers/AcademicCertificateController.php`
- `resources/views/components/retained-academic-source.blade.php`
- `docs/24-academic-verification-module-removal.md`

Modified:

- `routes/web.php`
- `resources/views/layouts/navigation.blade.php`
- `resources/views/scholars/show.blade.php`
- `resources/views/scholarship-batches/show.blade.php`
- `resources/views/evaluations/partials/academic-records.blade.php`
- `resources/views/evaluations/partials/history.blade.php`
- `resources/views/evaluations/partials/supporting-evidence.blade.php`
- `app/Http/Controllers/ScholarshipEvaluationController.php`
- `app/View/EvaluationWorkspace.php` (stage label only)
- `resources/js/app.js`
- `docs/23-menu-restructuring-and-registry-integration.md` (current removal note)

## Actual static verification

As previously instructed, no automated test was created or executed and no manual/
browser functional testing was performed. Existing test sources were not modified.

- Static reference search: no remaining `academic-verifications` route/view calls
  in application, resources or route source.
- Pint completed for the four changed/new PHP files.
- `php -l` on routes, both affected controllers and EvaluationWorkspace: no syntax errors.
- `node --check resources/js/app.js`: no syntax errors.
- Static Blade `compileString()` plus PHP lint: seven changed/new templates compiled
  without syntax errors. No view or HTTP operation was rendered/executed.
- `npm run build`: completed successfully; Vite compiled 63 modules.
- `git diff --check`: no whitespace errors; existing line-ending notices only.
- Task-start hash comparison reviewed preservation of domain calculations, policies,
  models, schema and existing test files.

These checks do not establish runtime behavior. Historical tests referencing removed
routes/classes remain in the repository and require review before a future test run;
no suite pass is claimed. Documents 19–22 describe earlier implementations and are
historical where they reference the removed module.

## Deployment and developer manual checks — NOT EXECUTED

Production assets were rebuilt locally. Deploy with the normal `npm run build`
process. No route-cache file was present during local file inspection. If another
deployment caches routes/views, clear or rebuild those caches through its normal
deployment procedure so removed URLs do not use stale controller references.

The developer should verify on isolated existing development data:

1. Academic verification is absent from both desktop and mobile navigation.
2. Old module list/create/detail/edit/correction/write URLs are unavailable.
3. Scholar and Roster pages contain no links/actions to removed routes and retain
   their existing roster/enrollment/billing data.
4. Evaluation source details, grade calculations and history still display existing
   data without linking to removed pages.
5. Authorized staff can download a retained certificate through the protected route;
   unauthorized users, mismatched records/documents, missing/changed files remain denied.
6. Missing/unverified/stale academic evidence still prevents eligible reliance and
   activation under the existing rules. No record is automatically verified by removal.
7. Existing stored grades, documents, policies, historical decisions and financial
   records remain intact.

All functional checks are **NOT TESTED**. No student records or database contents
were changed for verification.
