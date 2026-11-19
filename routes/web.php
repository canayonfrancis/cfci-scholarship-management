<?php

use App\Http\Controllers\AcademicCertificateController;
use App\Http\Controllers\AcademicPeriodController;
use App\Http\Controllers\AcademicProgramController;
use App\Http\Controllers\BillingBatchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DelistingReasonController;
use App\Http\Controllers\FinancialRecordController;
use App\Http\Controllers\PaymentAcknowledgmentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ScholarController;
use App\Http\Controllers\ScholarImportController;
use App\Http\Controllers\ScholarshipAssignmentController;
use App\Http\Controllers\ScholarshipBatchController;
use App\Http\Controllers\ScholarshipEvaluationController;
use App\Http\Controllers\ScholarshipFundsController;
use App\Http\Controllers\ScholarshipProgramController;
use App\Http\Controllers\ScholarshipReportController;
use App\Http\Controllers\StudentAccountController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Middleware\ConfirmStudentAccountPassword;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', 'cache.headers:no_store;private'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::prefix('student-accounts')->name('student-accounts.')
        ->middleware(['can:student-accounts.provision', 'cache.headers:no_store;private'])->group(function () {
            Route::get('/', [StudentAccountController::class, 'index'])->name('index');
            Route::get('{student}/confirm', [StudentAccountController::class, 'confirm'])
                ->middleware(ConfirmStudentAccountPassword::class)->name('confirm');
            Route::get('{student}', [StudentAccountController::class, 'show'])->name('show');
            Route::post('{student}/create', [StudentAccountController::class, 'createAccount'])
                ->middleware([ConfirmStudentAccountPassword::class, 'throttle:student-account-links'])->name('create');
            Route::post('{student}/initial-password', [StudentAccountController::class, 'setInitialPassword'])
                ->middleware([ConfirmStudentAccountPassword::class, 'throttle:student-account-links'])->name('initial-password');
            foreach (['deactivate', 'reactivate'] as $action) {
                Route::patch('{student}/'.$action, [StudentAccountController::class, $action])
                    ->middleware([ConfirmStudentAccountPassword::class, 'throttle:student-account-links'])->name($action);
            }
            foreach (['setup-link', 'password-reset'] as $action) {
                Route::post('{student}/'.$action, [StudentAccountController::class, 'credentialLink'])
                    ->middleware([ConfirmStudentAccountPassword::class, 'throttle:student-account-links'])->name($action);
            }
        });

    Route::get('student/profile', [StudentProfileController::class, 'show'])
        ->middleware('cache.headers:no_store;private')->name('student.profile');
    Route::get('student/profile/edit', [StudentProfileController::class, 'edit'])
        ->middleware('cache.headers:no_store;private')->name('student.profile.edit');
    Route::patch('student/profile', [StudentProfileController::class, 'update'])
        ->middleware(['cache.headers:no_store;private', 'throttle:20,1'])->name('student.profile.update');

    Route::get('scholarship-reports/{report?}', [ScholarshipReportController::class, 'index'])->middleware('cache.headers:no_store;private')->name('scholarship-reports.index');
    Route::resource('scholarship-batches', ScholarshipBatchController::class)->parameters(['scholarship-batches' => 'batch'])->only(['index', 'store', 'show'])->middleware('cache.headers:no_store;private');
    Route::get('rosters', [ScholarshipBatchController::class, 'index'])->name('rosters.index')->middleware('cache.headers:no_store;private');
    Route::get('rosters/{batch}', [ScholarshipBatchController::class, 'show'])->name('rosters.show')->middleware('cache.headers:no_store;private');
    Route::post('rosters/{batch}/scholars', [ScholarshipBatchController::class, 'addScholars'])->name('rosters.scholars.store')->middleware('cache.headers:no_store;private');
    Route::post('scholars/{scholar}/batch-memberships', [ScholarshipBatchController::class, 'join'])->name('scholars.batch-memberships.store');
    Route::prefix('financial-records')->name('financial.')->middleware('cache.headers:no_store;private')->controller(FinancialRecordController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('funding-status', 'fundingStatus')->name('funding-status');
        Route::get('batches/{batch}', 'batch')->name('batch');
        Route::post('batches/{batch}/payroll', [PayrollController::class, 'store'])->name('payrolls.store');
        Route::get('payrolls/{payroll}', [PayrollController::class, 'show'])->name('payrolls.show');
        Route::get('payrolls/{payroll}/print', [PayrollController::class, 'print'])->name('payrolls.print');
        Route::post('receipts', 'createReceipt')->name('receipts.store');
        Route::get('receipts/{receipt}', 'receipt')->name('receipt');
        Route::post('receipts/{receipt}/post', 'postReceipt')->name('receipts.post');
        Route::post('batches/{batch}/funding', 'allocate')->name('allocate');
        Route::post('payments', 'createPayout')->name('payouts.store');
        Route::get('payments/{payout}', 'payout')->name('payout');
        Route::post('payments/{payout}/post', 'postPayout')->name('payouts.post');
        Route::post('payments/{payout}/acknowledgments', [PaymentAcknowledgmentController::class, 'store'])->name('acknowledgments.store');
        Route::post('payments/{payout}/deductions', 'deduct')->name('deduct');
        Route::post('deductions/{deduction}/settle', 'settle')->name('settle');
        Route::post('batches/{batch}/liquidations', 'prepareLiquidation')->name('liquidations.store');
        Route::get('liquidations/{liquidation}', 'liquidation')->name('liquidation');
        Route::get('liquidations/{liquidation}/print', 'printLiquidation')->name('liquidations.print');
        Route::post('liquidations/{liquidation}/refresh', 'refreshLiquidation')->name('liquidations.refresh');
        Route::post('liquidations/{liquidation}/submit', 'submitLiquidation')->name('liquidations.submit');
        Route::post('liquidations/{liquidation}/review', 'reviewLiquidation')->name('liquidations.review');
        Route::post('evidence/{type}/{id}', 'uploadEvidence')->whereNumber('id')->name('evidence.store');
        Route::get('evidence/{type}/{id}/{document}', 'download')->whereNumber('id')->name('evidence.download');
        Route::post('evidence/{type}/{id}/{document}/review', 'reviewEvidence')->whereNumber('id')->name('evidence.review');
    });
    Route::resource('academic-programs', AcademicProgramController::class)->only(['index', 'store', 'update']);
    Route::get('academic-years', [AcademicPeriodController::class, 'years'])->name('academic-years.index')->middleware('cache.headers:no_store;private');
    Route::resource('delisting-reasons', DelistingReasonController::class)->only(['index', 'store', 'update'])->middleware('cache.headers:no_store;private');
    Route::get('billing-batches', [BillingBatchController::class, 'index'])->name('billing-batches.index');
    Route::get('billing-batches/options', [BillingBatchController::class, 'options'])->middleware('cache.headers:no_store;private')->name('billing-batches.options');
    Route::post('billing-batches/terms', [BillingBatchController::class, 'configure'])->name('billing-batches.terms');
    Route::post('billing-batches', [BillingBatchController::class, 'store'])->name('billing-batches.store');
    Route::get('billing-batches/report', [ScholarshipFundsController::class, 'report'])->name('billing-batches.report');
    Route::post('billing-batches/budget', [ScholarshipFundsController::class, 'budget'])->name('billing-batches.budget');
    Route::post('billing-batches/{batch}/approve', [ScholarshipFundsController::class, 'approve'])->name('billing-batches.approve');
    Route::post('billing-batches/{batch}/items/{item}/adjust', [ScholarshipFundsController::class, 'adjust'])->name('billing-batches.items.adjust');
    Route::get('billing-batches/{batch}/scholar-list', [BillingBatchController::class, 'exportScholars'])->middleware('cache.headers:no_store;private')->name('billing-batches.scholars.export');
    Route::get('billing-batches/{batch}', [BillingBatchController::class, 'show'])->name('billing-batches.show');
    Route::post('billing-batches/{batch}/enrollment', [BillingBatchController::class, 'enrollment'])->name('billing-batches.enrollment');
    Route::post('billing-batches/{batch}/exclusion', [BillingBatchController::class, 'exclusion'])->name('billing-batches.exclusion');
    Route::patch('billing-batches/{batch}', [BillingBatchController::class, 'update'])->name('billing-batches.update');
    Route::post('billing-batches/{batch}/items', [BillingBatchController::class, 'add'])->name('billing-batches.items.store');
    Route::post('billing-batches/{batch}/items/bulk', [BillingBatchController::class, 'addMany'])->name('billing-batches.items.bulk');
    Route::delete('billing-batches/{batch}/items/{item}', [BillingBatchController::class, 'remove'])->name('billing-batches.items.destroy');
    Route::post('billing-batches/{batch}/submit', [BillingBatchController::class, 'submit'])->name('billing-batches.submit');
    Route::post('academic-periods/validation-context', [AcademicPeriodController::class, 'saveContext'])->name('academic-periods.context');
    Route::get('evaluations', [ScholarshipEvaluationController::class, 'index'])->name('evaluations.index');
    Route::get('evaluations/create', [ScholarshipEvaluationController::class, 'create'])->name('evaluations.create');
    Route::post('evaluations', [ScholarshipEvaluationController::class, 'store'])->name('evaluations.store');
    Route::get('evaluations/{application}', [ScholarshipEvaluationController::class, 'show'])->name('evaluations.show');
    Route::post('evaluations/{application}/policy', [ScholarshipEvaluationController::class, 'confirmPolicy'])->name('evaluations.policy');
    Route::post('evaluations/{application}/run', [ScholarshipEvaluationController::class, 'evaluate'])->name('evaluations.run');
    Route::post('evaluations/{application}/evidence', [ScholarshipEvaluationController::class, 'evidence'])->name('evaluations.evidence');
    Route::patch('evaluations/{application}/evidence/{document}', [ScholarshipEvaluationController::class, 'reviewEvidence'])->name('evaluations.evidence.review');
    Route::get('evaluations/{application}/evidence/{document}', [ScholarshipEvaluationController::class, 'download'])->name('evaluations.evidence.download');
    Route::post('evaluations/{application}/external-approval', [ScholarshipEvaluationController::class, 'externalApproval'])->name('evaluations.external');
    Route::post('evaluations/{application}/activate', [ScholarshipEvaluationController::class, 'activate'])->name('evaluations.activate');
    Route::get('academic-certificates/{record}/{document}', AcademicCertificateController::class)
        ->middleware('cache.headers:no_store;private')->name('academic-certificates.download');
    Route::prefix('scholars/bulk-upload')->name('scholars.imports.')
        ->middleware(['can:create,App\Models\Student', 'cache.headers:no_store;private'])
        ->controller(ScholarImportController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('template', 'template')->middleware('throttle:20,1')->name('template');
            Route::post('preview', 'preview')->middleware('throttle:6,1')->name('preview');
            Route::get('{import}', 'show')->whereUuid('import')->name('show');
            Route::post('{import}/confirm', 'confirm')->whereUuid('import')->middleware('throttle:10,1')->name('confirm');
            Route::delete('{import}', 'cancel')->whereUuid('import')->name('cancel');
        });
    Route::resource('scholars', ScholarController::class)->only(['index', 'show', 'edit', 'update'])->middleware('cache.headers:no_store;private');
    Route::resource('scholarship-programs', ScholarshipProgramController::class)->parameters(['scholarship-programs' => 'program'])->except('destroy');
    Route::resource('academic-periods', AcademicPeriodController::class)->parameters(['academic-periods' => 'period'])->except('destroy');
    Route::scopeBindings()->group(function () {
        Route::get('scholars/{scholar}/assignments/create', [ScholarshipAssignmentController::class, 'create'])->name('scholars.assignments.create');
        Route::post('scholars/{scholar}/assignments', [ScholarshipAssignmentController::class, 'store'])->name('scholars.assignments.store');
        Route::get('scholars/{scholar}/assignments/{assignment}/edit', [ScholarshipAssignmentController::class, 'edit'])->name('scholars.assignments.edit');
        Route::patch('scholars/{scholar}/assignments/{assignment}', [ScholarshipAssignmentController::class, 'update'])->name('scholars.assignments.update');
    });
});

require __DIR__.'/auth.php';
