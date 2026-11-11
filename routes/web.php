<?php

use App\Http\Controllers\AcademicPeriodController;
use App\Http\Controllers\AcademicVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScholarController;
use App\Http\Controllers\ScholarshipAssignmentController;
use App\Http\Controllers\ScholarshipEvaluationController;
use App\Http\Controllers\ScholarshipProgramController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
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
    Route::resource('scholars', ScholarController::class)->except('destroy');
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
