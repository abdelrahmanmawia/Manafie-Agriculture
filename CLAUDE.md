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

Stock domain is largely parallel and independent: `Product`, `Vehicle`, `StockInventory`, `StockMovement`, `FuelTransaction`, `ManualStockEntry`, `StockAlert` — all scoped by `farm_id` (not `enterprise_id`). There is currently no model linking stock consumption back to a specific `PointageRecord`/`Harvest`/`Operation` — the two systems' analytics are intentionally kept separate for now; a bridge (e.g. a `StockConsumption`-style table) can be reintroduced later once Stock is finished. There is no Purchase Order / supplier-ordering feature — procurement (contacting suppliers, placing orders) is handled by a "responsable d'achat" outside this app; the magasinier (warehouse keeper) only logs what physically arrives, via `StockMovementController::stockIn`.

### Authorization model — no policies/gates, role checks are inline
There is no Laravel Policy/Gate layer. Access control is done by hand by comparing `$request->user()->role` against string literals: `'super_admin'`, `'farm_manager'`, `'data_entry'`. `farm_manager`/`data_entry` are always locked to their own `farm_id`/`enterprise_id` columns. `super_admin` has neither set on their own user row — instead they operate through a **session-based active farm** (see below), and every controller (Pointage and Stock alike) scopes its super_admin branch to that farm. Cross-tenant access returns `abort(403)`, not a 404.
- **Stock** controllers use two shared helpers on the base [Controller.php](app/Http/Controllers/Controller.php): `scopedFarmId()` for reads and `resolveWriteFarmId()` for writes. Both resolve to `session('active_farm_id')` for super_admin and `$user->farm_id` for everyone else. Stock has no enterprise concept — it's pooled at the farm level, shared across all of a farm's enterprises/divisions.
- **Pointage/Analytics/Payroll/Harvests** controllers inline the same idea per-method (see [PointageController.php](app/Http/Controllers/PointageController.php)): super_admin's branch scopes by `session('active_farm_id')` via `enterprise.farm_id`, with the existing `?enterprise_id=` query param still available as a secondary drill-down into one division of that farm; `farm_manager` is scoped to their `farm_id`; everyone else to their own `enterprise_id`, falling back to `farm_id` if unset.

When adding routes/controllers that touch `Quinzaine`, `Employee`, `Pointage*` data, replicate the inline per-method pattern; for new `Stock*` controllers, reuse `scopedFarmId()`/`resolveWriteFarmId()` rather than duplicating the branching or introducing a new mechanism.

### Super admin works within one active farm at a time
`super_admin` has no `farm_id` of their own — they must activate one via `FarmController::activate` (`POST /farms/{farm}/activate`, sets `session('active_farm_id')`) before touching Pointage or Stock. The `farm.selected` middleware ([EnsureFarmSelected.php](app/Http/Middleware/EnsureFarmSelected.php), applied to the Pointage+Harvests+Analytics+Payroll+Settings+Stock route group in `routes/web.php`) redirects them back to the Hub (`dashboard`) if no farm is active yet. `FarmController::deactivate` clears it (the "Changer de Ferme" action in the nav). The active farm is shared to every Inertia page as the `activeFarm` prop (see `HandleInertiaRequests::share()`) so the layout can display it without every controller passing it manually. There is no "view all farms at once" mode — by design, super_admin always works inside exactly one farm, same as everyone else, just able to switch which one.

### Navigation: three zones (Hub / Pointage / Stock)
`AuthenticatedLayout.jsx` derives an active zone from the current route name (`route().current('stock.*')`, etc.) and renders a persistent left sidebar ([WorkspaceSubNav.jsx](resources/js/Components/WorkspaceSubNav.jsx)) listing that zone's own pages, driven by a small per-zone config array defined inline in the layout — not by anything each page passes in. The top bar itself only ever shows 3 links (Accueil/Pointage/Gestion de Stock) plus the user menu. Do not reintroduce per-page nav bars or filters (like the removed `FarmFilter` component) — zone membership and the active farm are both handled centrally in the layout/session, not per-page.

### Payroll calculation is centralized and formula-driven
All pay/invoice math lives in [PayrollService.php](app/Services/PayrollService.php) — do not reimplement pay calculations in controllers. It branches on `Enterprise.contract_type`:
- `avec_contrat` ("AGRIPER" logic) — worker net pay includes deduction rate, overtime (`hs`) at a fixed hourly rate, holiday (`is_jf`) bonus, plus a separate client-invoicing (`net_factur_j`/TTC) calculation using different fixed charges/tax constants.
- anything else ("HAFILATY" logic) — simple pass-through of `brut` rate as net pay, no client invoicing.

The constants at the top of the class (`DEDUCTION_RATE`, `HS_HOURLY_RATE`, `CHARGE_RATE`, etc.) were reverse-engineered from an existing Excel model — treat them as fixed business constants, not tunables, unless the user provides updated formulas.

`PayrollService::calculate()` is called per `PointageRecord` (per employee/day); `generateSnapshot()` aggregates a whole `Quinzaine` into a `QuinzaineSummary` (totals by employee/operation/bloc) — this is what powers payslips and history views.

### Stock movements always pair a `StockMovement` row with a `StockInventory` update
Every stock-affecting controller action (`StockMovementController::stockIn/stockOut/adjustment`, `FuelTransactionController`, `ManualStockEntryController`) wraps the write in `DB::transaction()` and does two things together: insert an immutable `StockMovement` audit row, then mutate `StockInventory.quantity_on_hand` (`firstOrCreate` if it doesn't exist yet). Follow this pattern for any new stock-mutating endpoint rather than updating inventory directly. Stock only ever flows from the central `magasin` out to a bloc (`stockOut`) — there is no bloc-to-bloc transfer in this business, and no such endpoint (a `transfer()` method existed but modeled a workflow that doesn't happen and silently destroyed stock quantities; it was removed).

### Quinzaine locking
A `Quinzaine` has `is_closed`. Once closed, `PointageController::updateCell` refuses further edits ("This period is closed and cannot be modified"). Any new mutation on pointage data for a given quinzaine should check this flag first.

## Code conventions

- **Controllers**: plain resourceful methods (`index`, `store`, `show`, `edit`, `update`, `destroy`) plus ad-hoc action methods (e.g. `toggleActive`, `receive`, `resolve`, `verify`) — no form requests, validation is inline via `$request->validate([...])` in the controller method.
- **Routes**: all defined in [routes/web.php](routes/web.php) (no `Route::resource`, every route is spelled out explicitly), grouped under `auth`+`verified` middleware, named `area.subarea.action` (e.g. `stock.manual-entries.verify`). New stock/pointage features should follow this flat, explicitly-named style rather than switching to resource routing.
- **Models**: thin — `$fillable`, `$casts`, and relationship methods only, no accessors/scopes/business logic. Business logic belongs in `app/Services` (see `PayrollService`) or the controller.
- **JSON vs Inertia responses**: page-loading actions return `Inertia::render(...)`. For mutating actions, the response must match how the frontend calls it — if the page calls the route via Inertia's `useForm()` (`post`/`put`/`delete`), the controller **must** return a redirect (e.g. `redirect()->back()`), not `response()->json(...)`; Inertia throws "All Inertia requests must receive a valid Inertia response" otherwise (this bug was found and fixed across `Product`/`Vehicle`/`FuelTransaction`/`ManualStockEntry` controllers — follow their `store`/`update`/`destroy` as the template for any new `useForm()`-driven action). Only return raw `response()->json(...)`/`JsonResponse` for endpoints genuinely called via plain `fetch`/`axios` (e.g. `ProductController::categories/unitTypes/lowStock`), not Inertia's form helper.
- **Frontend pages** mirror the route structure 1:1 under `resources/js/Pages/<Area>/<Action>.jsx` (e.g. `Stock/Vehicles/Index.jsx`, `Stock/Vehicles/Edit.jsx`). Shared chrome is `Layouts/AuthenticatedLayout.jsx` / `GuestLayout.jsx`; reusable inputs/buttons live in `resources/js/Components/`.
- French domain vocabulary is used throughout code, not just UI copy (`bloc`, `sector`/`secteur`, `parcelle`, `quinzaine`, `jf` = jour férié, `hs` = heures supplémentaires) — keep new fields/variables consistent with this rather than translating to English.
