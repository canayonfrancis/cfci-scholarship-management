<?php

use App\Http\Controllers\AcademicPeriodController;
use App\Http\Controllers\AcademicProgramController;
use App\Http\Controllers\AcademicVerificationController;
use App\Http\Controllers\BillingBatchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialRecordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScholarController;
use App\Http\Controllers\ScholarshipAssignmentController;
use App\Http\Controllers\ScholarshipEvaluationController;
use App\Http\Controllers\ScholarshipFundsController;
use App\Http\Controllers\ScholarshipProgramController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', 'cache.headers:no_store;private'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::prefix('financial-records')->name('financial.')->middleware('cache.headers:no_store;private')->controller(FinancialRecordController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('batches/{batch}', 'batch')->name('batch');
        Route::post('receipts', 'createReceipt')->name('receipts.store');
        Route::get('receipts/{receipt}', 'receipt')->name('receipt');
        Route::post('receipts/{receipt}/post', 'postReceipt')->name('receipts.post');
        Route::post('batches/{batch}/funding', 'allocate')->name('allocate');
        Route::post('payments', 'createPayout')->name('payouts.store');
        Route::get('payments/{payout}', 'payout')->name('payout');
        Route::post('payments/{payout}/post', 'postPayout')->name('payouts.post');
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
    Route::get('billing-batches', [BillingBatchController::class, 'index'])->name('billing-batches.index');
    Route::post('billing-batches/terms', [BillingBatchController::class, 'configure'])->name('billing-batches.terms');
    Route::post('billing-batches', [BillingBatchController::class, 'store'])->name('billing-batches.store');
    Route::get('billing-batches/report', [ScholarshipFundsController::class, 'report'])->name('billing-batches.report');
    Route::post('billing-batches/budget', [ScholarshipFundsController::class, 'budget'])->name('billing-batches.budget');
    Route::post('billing-batches/{batch}/approve', [ScholarshipFundsController::class, 'approve'])->name('billing-batches.approve');
    Route::post('billing-batches/{batch}/items/{item}/adjust', [ScholarshipFundsController::class, 'adjust'])->name('billing-batches.items.adjust');
    Route::get('billing-batches/{batch}', [BillingBatchController::class, 'show'])->name('billing-batches.show');
    Route::patch('billing-batches/{batch}', [BillingBatchController::class, 'update'])->name('billing-batches.update');
    Route::post('billing-batches/{batch}/items', [BillingBatchController::class, 'add'])->name('billing-batches.items.store');
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
    Route::get('academic-verifications/{verification}/correction', [AcademicVerificationController::class, 'correction'])->name('academic-verifications.correction');
    Route::post('academic-verifications/{verification}/correction', [AcademicVerificationController::class, 'correct'])->name('academic-verifications.correct');
    Route::get('academic-verifications/{verification}/certificates/{document}', [AcademicVerificationController::class, 'certificate'])->name('academic-verifications.certificate');
    Route::resource('academic-verifications', AcademicVerificationController::class)->parameters(['academic-verifications' => 'verification'])->except('destroy');
    Route::resource('scholars', ScholarController::class)->except('destroy')->middleware('cache.headers:no_store;private');
    Route::resource('scholarship-programs', ScholarshipProgramController::class)->parameters(['scholarship-programs' => 'program'])->except('destroy');
    Route::resource('academic-periods', AcademicPeriodController::class)->parameters(['academic-periods' => 'period'])->except('destroy');
    Route::scopeBindings()->group(function () {
        Route::get('scholars/{scholar}/assignments/create', [ScholarshipAssignmentController::class, 'create'])->name('scholars.assignments.create');
        Route::post('scholars/{scholar}/assignments', [ScholarshipAssignmentController::class, 'store'])->name('scholars.assignments.store');
        Route::get('scholars/{scholar}/assignments/{assignment}/edit', [ScholarshipAssignmentController::class, 'edit'])->name('scholars.assignments.edit');
        Route::patch('scholars/{scholar}/assignments/{assignment}', [ScholarshipAssignmentController::class, 'update'])->name('scholars.assignments.update');
    });
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
