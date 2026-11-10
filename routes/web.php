<?php

use App\Http\Controllers\AcademicPeriodController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScholarController;
use App\Http\Controllers\ScholarshipAssignmentController;
use App\Http\Controllers\ScholarshipProgramController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
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
