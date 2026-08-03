<?php

use App\Http\Controllers\PointageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\EnterpriseController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\HarvestController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleUsageController;
use App\Http\Controllers\StockInventoryController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockAlertController;
use App\Http\Controllers\FuelTransactionController;
use App\Http\Controllers\ManualStockEntryController;
use App\Http\Controllers\StockReportController;
use App\Http\Controllers\StockController; // Import StockController
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
    Route::post('/farms/{farm}/activate', [App\Http\Controllers\FarmController::class, 'activate'])->name('farms.activate');
    Route::post('/farms/deactivate', [App\Http\Controllers\FarmController::class, 'deactivate'])->name('farms.deactivate');
    Route::delete('/farms/{farm}', [App\Http\Controllers\FarmController::class, 'destroy'])->name('farms.destroy');
    Route::get('/farms/{farm}/settings', [App\Http\Controllers\FarmController::class, 'settings'])->name('farms.settings');
    Route::patch('/farms/{farm}/settings', [App\Http\Controllers\FarmController::class, 'updateSettings'])->name('farms.updateSettings');
    Route::post('/farms/{farm}/operations', [App\Http\Controllers\FarmController::class, 'addOperation'])->name('farms.operations.store');
    Route::put('/farms/operations/{operation}', [App\Http\Controllers\FarmController::class, 'updateOperation'])->name('farms.operations.update');
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

    Route::middleware('farm.selected')->group(function () {
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

    // Stock Management - Dashboard
    Route::get('/stock', [StockController::class, 'index'])->name('stock.dashboard');

    // Stock Management - Products
    Route::get('/stock/products', [ProductController::class, 'index'])->name('stock.products.index');
    Route::post('/stock/products', [ProductController::class, 'store'])->name('stock.products.store');
    Route::get('/stock/products/categories', [ProductController::class, 'categories'])->name('stock.products.categories');
    Route::get('/stock/products/unit-types', [ProductController::class, 'unitTypes'])->name('stock.products.unit-types');
    Route::get('/stock/products/low-stock', [ProductController::class, 'lowStock'])->name('stock.products.low-stock');
    Route::get('/stock/products/{product}', [ProductController::class, 'show'])->name('stock.products.show');
    Route::put('/stock/products/{product}', [ProductController::class, 'update'])->name('stock.products.update');
    Route::delete('/stock/products/{product}', [ProductController::class, 'destroy'])->name('stock.products.destroy');
    Route::post('/stock/products/{product}/toggle-active', [ProductController::class, 'toggleActive'])->name('stock.products.toggle-active');

    // Stock Management - Vehicles
    Route::get('/stock/vehicles', [VehicleController::class, 'index'])->name('stock.vehicles.index');
    Route::post('/stock/vehicles', [VehicleController::class, 'store'])->name('stock.vehicles.store');
    Route::get('/stock/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('stock.vehicles.show');
    Route::put('/stock/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('stock.vehicles.update');
    Route::delete('/stock/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->name('stock.vehicles.destroy');
    Route::post('/stock/vehicles/{vehicle}/toggle-active', [VehicleController::class, 'toggleActive'])->name('stock.vehicles.toggle-active');

    // Stock Management - Vehicle Usage / Location (rental tracking)
    Route::get('/stock/vehicle-usage', [VehicleUsageController::class, 'index'])->name('stock.vehicle-usage.index');
    Route::post('/stock/vehicle-usage/cell', [VehicleUsageController::class, 'cell'])->name('stock.vehicle-usage.cell');

    // Stock Management - Inventory
    Route::get('/stock/inventory', [StockInventoryController::class, 'index'])->name('stock.inventory.index');
    Route::get('/stock/inventory/{inventory}', [StockInventoryController::class, 'show'])->name('stock.inventory.show');
    Route::post('/stock/inventory/adjust', [StockInventoryController::class, 'adjust'])->name('stock.inventory.adjust');
    Route::post('/stock/inventory/count', [StockInventoryController::class, 'count'])->name('stock.inventory.count');
    Route::get('/stock/inventory/movements/{product}', [StockInventoryController::class, 'movements'])->name('stock.inventory.movements');

    // Stock Management - Movements
    Route::get('/stock/movements', [StockMovementController::class, 'index'])->name('stock.movements.index');
    Route::post('/stock/movements/in', [StockMovementController::class, 'stockIn'])->name('stock.movements.in');

    // Stock Management - Stock Alerts
    Route::get('/stock/alerts', [StockAlertController::class, 'index'])->name('stock.alerts.index');
    Route::post('/stock/alerts/{alert}/resolve', [StockAlertController::class, 'resolve'])->name('stock.alerts.resolve');
    Route::get('/stock/alerts/unresolved-count', [StockAlertController::class, 'unresolvedCount'])->name('stock.alerts.unresolved-count');

    // Stock Management - Fuel Transactions
    // Read-only history — new fuel/vehicle consumption is logged via Entrée Manuelle (stock.manual-entries.store)
    Route::get('/stock/fuel-transactions', [FuelTransactionController::class, 'index'])->name('stock.fuel-transactions.index');
    Route::get('/stock/fuel-transactions/{transaction}', [FuelTransactionController::class, 'show'])->name('stock.fuel-transactions.show');

    // Stock Management - Manual Stock Entries
    Route::get('/stock/manual-entries', [ManualStockEntryController::class, 'index'])->name('stock.manual-entries.index');
    Route::post('/stock/manual-entries', [ManualStockEntryController::class, 'store'])->name('stock.manual-entries.store');
    Route::get('/stock/manual-entries/{entry}', [ManualStockEntryController::class, 'show'])->name('stock.manual-entries.show');
    Route::get('/stock/manual-entries/{entry}/edit', [ManualStockEntryController::class, 'edit'])->name('stock.manual-entries.edit');
    Route::put('/stock/manual-entries/{entry}', [ManualStockEntryController::class, 'update'])->name('stock.manual-entries.update');
    Route::delete('/stock/manual-entries/{entry}', [ManualStockEntryController::class, 'destroy'])->name('stock.manual-entries.destroy');
    Route::post('/stock/manual-entries/{entry}/verify', [ManualStockEntryController::class, 'verify'])->name('stock.manual-entries.verify');

    // Stock Management - Reports & Analytics
    Route::get('/stock/reports', [StockReportController::class, 'index'])->name('stock.reports.index');
    Route::get('/stock/reports/inventory-value', [StockReportController::class, 'inventoryValue'])->name('stock.reports.inventory-value');
    Route::get('/stock/reports/movement-history', [StockReportController::class, 'movementHistory'])->name('stock.reports.movement-history');
    Route::get('/stock/reports/consumption-by-operation', [StockReportController::class, 'consumptionByOperation'])->name('stock.reports.consumption-by-operation');
    Route::get('/stock/reports/cost-per-hectare', [StockReportController::class, 'costPerHectare'])->name('stock.reports.cost-per-hectare');
    Route::get('/stock/reports/stock-turnover', [StockReportController::class, 'stockTurnover'])->name('stock.reports.stock-turnover');
    }); // end farm.selected group
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
