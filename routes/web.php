<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegistrationController;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::prefix('register')->name('register.')->group(function () {
    Route::get('/', [RegistrationController::class, 'showRegistrationForm'])->name('email');
    Route::post('/', [RegistrationController::class, 'register'])->middleware('throttle:5,1')->name('store');
});

Route::middleware(['auth', 'active'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])
        ->middleware('permission:users.manage|roles.manage|departments.manage|employees.manage|onboarding.review|audit.view')
        ->name('dashboard');

    Route::middleware('permission:onboarding.review')->group(function () {
        Route::post('/onboarding/{onboarding}/approve', [AdminController::class, 'approveOnboarding'])->name('onboarding.approve');
        Route::post('/onboarding/{onboarding}/reject', [AdminController::class, 'rejectOnboarding'])->name('onboarding.reject');
    });

    Route::middleware('permission:users.manage')->group(function () {
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
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
        Route::put('/employees/{employee}', [AdminController::class, 'updateEmployee'])->name('employees.update');
        Route::delete('/employees/{employee}', [AdminController::class, 'destroyEmployee'])->name('employees.destroy');
    });

    Route::middleware('permission:roles.manage')->group(function () {
        Route::post('/roles', [AdminController::class, 'storeRole'])->name('roles.store');
        Route::put('/roles/{role}', [AdminController::class, 'updateRole'])->name('roles.update');
        Route::delete('/roles/{role}', [AdminController::class, 'destroyRole'])->name('roles.destroy');
    });
});
