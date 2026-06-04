<?php

use App\Http\Controllers\PointageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\EnterpriseController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\UserController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [EnterpriseController::class, 'index'])->name('dashboard');
    
    // Admin Management
    Route::post('/enterprises', [EnterpriseController::class, 'store'])->name('enterprises.store');
    
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
    
    Route::get('/settings', [EnterpriseController::class, 'settings'])->name('settings.index');
    Route::post('/settings/operation', [EnterpriseController::class, 'addOperation'])->name('settings.operation');
    Route::delete('/settings/operation/{operation}', [EnterpriseController::class, 'deleteOperation'])->name('settings.operation.destroy');
    Route::post('/settings/bloc', [EnterpriseController::class, 'addBloc'])->name('settings.bloc');
    Route::delete('/settings/bloc/{bloc}', [EnterpriseController::class, 'deleteBloc'])->name('settings.bloc.destroy');
    Route::post('/settings/quinzaine', [EnterpriseController::class, 'createQuinzaine'])->name('settings.quinzaine');
    Route::post('/settings/quinzaine/{quinzaine}/close', [EnterpriseController::class, 'closeQuinzaine'])->name('settings.quinzaine.close');

    // Pointage
    Route::get('/pointage', [PointageController::class, 'index'])->name('pointage.index');
    Route::get('/pointage/grid/{quinzaine}', [PointageController::class, 'grid'])->name('pointage.grid');
    Route::post('/pointage/cell', [PointageController::class, 'updateCell'])->name('pointage.cell');
    Route::post('/pointage', [PointageController::class, 'store'])->name('pointage.store');
    Route::get('/pointage/summary/{quinzaine}', [PointageController::class, 'summary'])->name('pointage.summary');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
