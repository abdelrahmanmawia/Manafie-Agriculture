# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A farm/agriculture management system ("Pointage" = time-tracking in French) covering: employee time-tracking and payroll, multi-farm/multi-enterprise hierarchy, harvests, and warehouse stock management (products, vehicles, purchase orders, fuel, movements). UI text and business terms are in French (quinzaine, bloc, parcelle, pointage).

## Stack

- **Backend**: Laravel 10, PHP ^8.1
- **Frontend**: React 18 + Inertia.js (`@inertiajs/react`) — no separate SPA API, controllers return `Inertia::render()`
- **Build**: Vite + `laravel-vite-plugin`, Tailwind CSS, Headless UI
- **Auth/scaffolding**: Laravel Breeze (React variant) + Laravel Sanctum
- **DB**: SQLite by default (`.env` has `DB_CONNECTION=sqlite`); `config/database.php` default is `mysql` — check `.env` before assuming
- **Excel/PDF**: `maatwebsite/excel` (payroll/pointage exports), `barryvdh/laravel-dompdf` (payslips)
- **Charts**: `recharts` (frontend)

## Commands

```bash
# Install
composer install
npm install

# Dev servers (run both)
php artisan serve
npm run dev

# Build frontend for production
npm run build

# DB
php artisan migrate
php artisan migrate:fresh --seed   # rebuilds schema and runs DatabaseSeeder

# Tests (PHPUnit, Feature + Unit suites)
php artisan test
php artisan test --filter=TestName
vendor/bin/phpunit tests/Feature/SomeTest.php

# Code style
vendor/bin/pint          # Laravel Pint formatter
```

Note: `phpunit.xml` has the sqlite in-memory test DB lines commented out, so `php artisan test` currently runs against whatever `DB_CONNECTION`/`DB_DATABASE` is set in `.env`/environment — be aware this is not isolated per test run.

## Architecture

### Domain hierarchy
`Farm` → `Enterprise` (a farm has many enterprises/divisions) → `Employee`, `Quinzaine` (pay period), `User`.
`Farm` → `Bloc` → `Sector` → `Parcelle` (field/land breakdown, via `hasManyThrough`).
`Quinzaine` (bi-weekly pay period) → `PointageRecord` (one row per employee/day/operation) → rolls up into `QuinzaineSummary` (a cached snapshot, generated when a quinzaine is closed).

Stock domain is largely parallel and independent: `Product`, `Vehicle`, `StockInventory`, `StockMovement`, `PurchaseOrder`/`PurchaseOrderItem`, `FuelTransaction`, `ManualStockEntry`, `StockAlert`, `StockConsumption` — all scoped by `farm_id` (not `enterprise_id`).

### Authorization model — no policies/gates, role checks are inline
There is no Laravel Policy/Gate layer and no role middleware registered in `app/Http/Kernel.php`. Access control is done by hand in each controller by comparing `$request->user()->role` against string literals: `'super_admin'`, `'farm_manager'`, `'data_entry'` (see [PointageController.php](app/Http/Controllers/PointageController.php)). Scoping rules to replicate when adding a new controller:
- `super_admin` — sees everything, can pass an explicit `enterprise_id`/`farm_id` query param
- `farm_manager` — scoped to their `farm_id` (via `enterprise.farm_id` or a controller's own `farm_id` column)
- everyone else (`data_entry`, etc.) — scoped to their own `enterprise_id`; if none, falls back to `farm_id`, else denied
- Cross-tenant access returns `abort(403)`, not a 404

When adding routes/controllers that touch `Quinzaine`, `Employee`, `Pointage*`, or `Stock*` data, replicate this same role-branching rather than introducing a new authorization mechanism, unless asked to.

### Payroll calculation is centralized and formula-driven
All pay/invoice math lives in [PayrollService.php](app/Services/PayrollService.php) — do not reimplement pay calculations in controllers. It branches on `Enterprise.contract_type`:
- `avec_contrat` ("AGRIPER" logic) — worker net pay includes deduction rate, overtime (`hs`) at a fixed hourly rate, holiday (`is_jf`) bonus, plus a separate client-invoicing (`net_factur_j`/TTC) calculation using different fixed charges/tax constants.
- anything else ("HAFILATY" logic) — simple pass-through of `brut` rate as net pay, no client invoicing.

The constants at the top of the class (`DEDUCTION_RATE`, `HS_HOURLY_RATE`, `CHARGE_RATE`, etc.) were reverse-engineered from an existing Excel model — treat them as fixed business constants, not tunables, unless the user provides updated formulas.

`PayrollService::calculate()` is called per `PointageRecord` (per employee/day); `generateSnapshot()` aggregates a whole `Quinzaine` into a `QuinzaineSummary` (totals by employee/operation/bloc) — this is what powers payslips and history views.

### Stock movements always pair a `StockMovement` row with a `StockInventory` update
Every stock-affecting controller action (`StockMovementController::stockIn/stockOut/transfer/adjustment`, `FuelTransactionController`, `ManualStockEntryController`, `PurchaseOrderController::receive`) wraps the write in `DB::transaction()` and does two things together: insert an immutable `StockMovement` audit row, then mutate `StockInventory.quantity_on_hand` (`firstOrCreate` if it doesn't exist yet). Follow this pattern for any new stock-mutating endpoint rather than updating inventory directly.

### Quinzaine locking
A `Quinzaine` has `is_closed`. Once closed, `PointageController::updateCell` refuses further edits ("This period is closed and cannot be modified"). Any new mutation on pointage data for a given quinzaine should check this flag first.

## Code conventions

- **Controllers**: plain resourceful methods (`index`, `store`, `show`, `edit`, `update`, `destroy`) plus ad-hoc action methods (e.g. `toggleActive`, `receive`, `resolve`, `verify`) — no form requests, validation is inline via `$request->validate([...])` in the controller method.
- **Routes**: all defined in [routes/web.php](routes/web.php) (no `Route::resource`, every route is spelled out explicitly), grouped under `auth`+`verified` middleware, named `area.subarea.action` (e.g. `stock.purchase-orders.receive`). New stock/pointage features should follow this flat, explicitly-named style rather than switching to resource routing.
- **Models**: thin — `$fillable`, `$casts`, and relationship methods only, no accessors/scopes/business logic. Business logic belongs in `app/Services` (see `PayrollService`) or the controller.
- **JSON vs Inertia responses**: page-loading actions return `Inertia::render(...)`; AJAX-style mutating actions (mostly under `/stock/...`) return `response()->json(...)`, often with explicit `JsonResponse` return types.
- **Frontend pages** mirror the route structure 1:1 under `resources/js/Pages/<Area>/<Action>.jsx` (e.g. `Stock/PurchaseOrders/Index.jsx`, `Stock/PurchaseOrders/Edit.jsx`). Shared chrome is `Layouts/AuthenticatedLayout.jsx` / `GuestLayout.jsx`; reusable inputs/buttons live in `resources/js/Components/`.
- French domain vocabulary is used throughout code, not just UI copy (`bloc`, `sector`/`secteur`, `parcelle`, `quinzaine`, `jf` = jour férié, `hs` = heures supplémentaires) — keep new fields/variables consistent with this rather than translating to English.
