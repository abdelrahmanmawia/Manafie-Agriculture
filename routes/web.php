<?php

use App\Http\Controllers\PointageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\EnterpriseController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\HarvestController;
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
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [EnterpriseController::class, 'index'])->name('dashboard');

    // Farm & Enterprise Management
    Route::post('/farms', [App\Http\Controllers\FarmController::class, 'store'])->name('farms.store');
    Route::delete('/farms/{farm}', [App\Http\Controllers\FarmController::class, 'destroy'])->name('farms.destroy');
    Route::get('/farms/{farm}/settings', [App\Http\Controllers\FarmController::class, 'settings'])->name('farms.settings');
    Route::patch('/farms/{farm}/settings', [App\Http\Controllers\FarmController::class, 'updateSettings'])->name('farms.updateSettings');
    Route::post('/farms/{farm}/operations', [App\Http\Controllers\FarmController::class, 'addOperation'])->name('farms.operations.store');
    Route::post('/farms/{farm}/blocs', [App\Http\Controllers\FarmController::class, 'addBloc'])->name('farms.blocs.store');
    Route::post('/farms/{farm}/sectors', [App\Http\Controllers\FarmController::class, 'addSector'])->name('farms.sectors.store');
    Route::post('/farms/{farm}/parcelles', [App\Http\Controllers\FarmController::class, 'addParcelle'])->name('farms.parcelles.store');
    Route::delete('/farms/operations/{operation}', [App\Http\Controllers\FarmController::class, 'deleteOperation'])->name('farms.operations.destroy');
    Route::delete('/farms/blocs/{bloc}', [App\Http\Controllers\FarmController::class, 'deleteBloc'])->name('farms.blocs.destroy');
    Route::delete('/farms/sectors/{sector}', [App\Http\Controllers\FarmController::class, 'deleteSector'])->name('farms.sectors.destroy');
    Route::delete('/farms/parcelles/{parcelle}', [App\Http\Controllers\FarmController::class, 'deleteParcelle'])->name('farms.parcelles.destroy');

    Route::post('/enterprises', [EnterpriseController::class, 'store'])->name('enterprises.store');
    Route::patch('/enterprises/{enterprise}', [EnterpriseController::class, 'update'])->name('enterprises.update');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive'])->name('employees.toggle-active');
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
    Route::get('/pointage/export/{quinzaine}', [PointageController::class, 'export'])->name('pointage.export');
    Route::get('/pointage/export-all-divisions/{quinzaine}', [PointageController::class, 'exportAllDivisions'])->name('pointage.exportAllDivisions'); // New route
    Route::post('/pointage/cell', [PointageController::class, 'updateCell'])->name('pointage.cell');
    Route::post('/pointage', [PointageController::class, 'store'])->name('pointage.store');
    Route::get('/pointage/summary/{quinzaine}', [PointageController::class, 'summary'])->name('pointage.summary');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/payroll-history', [PayrollController::class, 'history'])->name('payroll.history');
    Route::get('/payroll/payslip/{employee}/{quinzaine}', [PayrollController::class, 'downloadPayslip'])->name('payroll.payslip');
    Route::get('/payroll/general-payslip/{quinzaine}', [PayrollController::class, 'downloadGeneralPayslip'])->name('payroll.general-payslip');

    // Harvests
    Route::get('/harvests', [HarvestController::class, 'index'])->name('harvests.index');
    Route::post('/harvests', [HarvestController::class, 'store'])->name('harvests.store');
    Route::post('/harvests/bulk-weigh', [HarvestController::class, 'bulkWeigh'])->name('harvests.bulkWeigh');
    Route::delete('/harvests/{harvest}', [HarvestController::class, 'destroy'])->name('harvests.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
