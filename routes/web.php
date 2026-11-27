<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuditHistoryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/applicant', function () {
        abort_unless(auth()->user()->roles()->where('slug', 'applicant')->exists(), 403);

        return view('applicant.portal');
    })->name('applicant.portal');
    Route::get('/stay-tuned', function () {
        abort_if(auth()->user()->hasPermission('employees.manage') || auth()->user()->roles()->where('slug', 'super-admin')->exists(), 403);

        return view('auth.stay-tuned');
    })->name('stay-tuned');
});

Route::prefix('register')->name('register.')->group(function () {
    Route::get('/', [RegistrationController::class, 'showRegistrationForm'])->name('email');
    Route::post('/', [RegistrationController::class, 'register'])->middleware('throttle:5,1')->name('store');
});

Route::middleware(['auth', 'active'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])
        ->middleware('permission:users.manage|roles.manage|departments.manage|employees.manage|onboarding.review|audit.view')
        ->name('dashboard');
    Route::get('/users', [AdminController::class, 'usersPage'])
        ->middleware('permission:users.manage|employees.manage')
        ->name('users');
    Route::get('/calendar', [AdminController::class, 'calendarPage'])
        ->middleware('permission:users.manage|employees.manage')
        ->name('calendar');
    Route::get('/history', [AuditHistoryController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('history');
    Route::middleware('permission:contracts.view|contracts.manage')->group(function () {
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts');
        Route::get('/contracts/{contract}/preview', [ContractController::class, 'preview'])->name('contracts.preview');
        Route::get('/contracts/{contract}/download', [ContractController::class, 'download'])->name('contracts.download');
        Route::post('/contracts/{contract}/response', [ContractController::class, 'submitResponse'])->name('contracts.response.submit');
        Route::get('/contracts/{contract}/my-response', [ContractController::class, 'downloadOwnResponse'])->name('contracts.response.own-download');
    });
    Route::get('/leaves', [AdminController::class, 'leavesPage'])->name('leaves');
    Route::get('/onboarding', [AdminController::class, 'onboardingPage'])
        ->middleware('permission:onboarding.review')
        ->name('onboarding');

    Route::middleware('permission:onboarding.review')->group(function () {
        Route::post('/onboarding/{onboarding}/approve', [AdminController::class, 'approveOnboarding'])->name('onboarding.approve');
        Route::post('/onboarding/{onboarding}/reject', [AdminController::class, 'rejectOnboarding'])->name('onboarding.reject');
    });

    Route::middleware('permission:users.manage')->group(function () {
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
        Route::middleware('permission:employees.manage')->post(
            '/users/with-employee',
            [AdminController::class, 'storeUserWithEmployee'],
        )->name('users.with-employee.store');
        Route::middleware('permission:employees.manage')->put(
            '/users/{user}/profile',
            [AdminController::class, 'updateUserProfile'],
        )->name('users.profile.update');
        Route::put('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser'])->name('users.destroy');
    });

    Route::middleware('permission:departments.manage')->group(function () {
        Route::post('/departments', [AdminController::class, 'storeDepartment'])->name('departments.store');
        Route::put('/departments/{department}', [AdminController::class, 'updateDepartment'])->name('departments.update');
        Route::delete('/departments/{department}', [AdminController::class, 'destroyDepartment'])->name('departments.destroy');
    });

    Route::middleware('permission:employees.manage')->group(function () {
        Route::post('/employees', [AdminController::class, 'storeEmployee'])->name('employees.store');
        Route::get('/employees/list', [AdminController::class, 'employeeList'])->name('employees.list');
        Route::put('/employees/{employee}', [AdminController::class, 'updateEmployee'])->name('employees.update');
        Route::delete('/employees/{employee}', [AdminController::class, 'destroyEmployee'])->name('employees.destroy');
    });

    Route::middleware('permission:contracts.manage')->group(function () {
        Route::post('/contracts/folders', [ContractController::class, 'storeFolder'])->name('contracts.folders.store');
        Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{contract}/recipients/{recipient}/response/preview', [ContractController::class, 'previewResponse'])->name('contracts.response.preview');
        Route::get('/contracts/{contract}/recipients/{recipient}/response', [ContractController::class, 'downloadResponse'])->name('contracts.response.download');
        Route::post('/contracts/{contract}/recipients/{recipient}/approve', [ContractController::class, 'approveResponse'])->name('contracts.response.approve');
        Route::post('/contracts/{contract}/recipients/{recipient}/request-changes', [ContractController::class, 'requestResponseChanges'])->name('contracts.response.changes');
        Route::post('/contracts/{contract}/convert-editor', [ContractController::class, 'convertToEditor'])->name('contracts.convert-editor');
        Route::put('/contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
        Route::delete('/contracts/{contract}', [ContractController::class, 'destroy'])->name('contracts.destroy');
    });

    Route::middleware('permission:roles.manage')->group(function () {
        Route::post('/roles', [AdminController::class, 'storeRole'])->name('roles.store');
        Route::put('/roles/{role}', [AdminController::class, 'updateRole'])->name('roles.update');
        Route::delete('/roles/{role}', [AdminController::class, 'destroyRole'])->name('roles.destroy');
    });
});
