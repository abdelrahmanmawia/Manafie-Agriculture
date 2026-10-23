# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A farm/agriculture management system ("Pointage" = time-tracking in French) covering: employee time-tracking and payroll, multi-farm/multi-enterprise hierarchy, harvests, and warehouse stock management (products, vehicles, purchase orders, fuel, movements). UI text and business terms are in French (quinzaine, bloc, parcelle, pointage).

## Stack

- **Backend**: Laravel 10, PHP ^8.1
- **Frontend**: React 18 + Inertia.js (`@inertiajs/react`) — no separate SPA API, controllers return `Inertia::render()`
- **Build**: Vite + `laravel-vite-plugin`, Tailwind CSS, Headless UI
- **Auth/scaffolding**: Laravel Breeze (React variant) + Laravel Sanctum
- **DB**: MySQL (`.env` has `DB_CONNECTION=mysql`, database `farm_management`, matching `config/database.php`'s own default) — check `.env` before assuming, since this has changed before
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

Note: `phpunit.xml` sets `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` under its `<php>` block, which overrides `.env` during `php artisan test` — the suite runs against an isolated in-memory SQLite DB regardless of the app's own MySQL connection, so it never touches real data and also nothing there validates MySQL-specific behavior. Verify anything DB-engine-specific (raw SQL, generated columns, FK behavior) against the real MySQL connection directly (e.g. `php artisan migrate:fresh` or a tinker query), not through the test suite.

## Architecture

### Domain hierarchy
`Farm` → `Enterprise` (a farm has many enterprises/divisions) → `Employee`, `Quinzaine` (pay period), `User`.
`Farm` → `Bloc` → `Sector` → `Parcelle` (field/land breakdown, via `hasManyThrough`).
`Quinzaine` (bi-weekly pay period) → `PointageRecord` (one row per employee/day/operation) → rolls up into `QuinzaineSummary` (a cached snapshot, generated when a quinzaine is closed).

`Equipe` (chef d'équipe team, farm-scoped) is the unit of the farm's first pointage step: the Grid's "Par équipe" mode (`?mode=equipe&equipe=<id|none>`) lists one team across all divisions, chef first, and points each worker against **their own division's** quinzaine for the same dates, so payroll and closing stay per division. A worker is in at most one équipe (`employees.equipe_id`, nullable — "Sans équipe" lists the rest). An équipe optionally rides a `TransportVehicle`, and that is the only rider assignment: a vehicle's riders (`TransportVehicle::employees()`, which prices its auto daily cost) are the members of the équipes riding it — there is no `employees.transport_vehicle_id` any more (migration 000046 turned each vehicle's riders into one équipe named after it). Teams span divisions, so a division-locked user can view équipes but not change them (`EquipeController`).

Transport cost is computed from the pointage, never ticked by hand ([TransportService.php](app/Services/TransportService.php)): on each day, a vehicle costs (distinct workers who worked that day — a record with an operation, so an unworked JF doesn't count — and whose record carries that vehicle) × `transport_vehicles.price_per_person`, or its flat `fixed_net_per_day` when set (kept as an option), and 0 when nobody rode it. `pointage_records.transport_vehicle_id` is stamped from the worker's équipe on the record's first write (`TransportService::riderVehicleId()`, called by `PointageController::upsertPointageRecord()` and the scan sync) and kept afterwards, so moving a worker to another équipe doesn't move past days to the new vehicle — any new path that creates pointage records must stamp it too. The only manual input is the exception `TransportVehicleAbsence` ("the vehicle didn't come that day": its riders came on their own, are paid, the vehicle costs 0), set from the Grille Transport. Residence locations no longer price transport.

Stock domain is largely parallel and independent: `Product`, `Vehicle`, `StockInventory`, `StockMovement`, `FuelTransaction`, `ManualStockEntry`, `StockAlert` — all scoped by `farm_id` (not `enterprise_id`). There is currently no model linking stock consumption back to a specific `PointageRecord`/`Harvest`/`Operation` — the two systems' analytics are intentionally kept separate for now; a bridge (e.g. a `StockConsumption`-style table) can be reintroduced later once Stock is finished. There is no Purchase Order / supplier-ordering feature — procurement (contacting suppliers, placing orders) is handled by a "responsable d'achat" outside this app; the magasinier (warehouse keeper) only logs what physically arrives, via `StockMovementController::stockIn`.

### Authorization model — no policies/gates, role checks are inline
There is no Laravel Policy/Gate layer. Access control is done by hand by comparing `$request->user()->role` against string literals: `'super_admin'`, `'farm_manager'`, `'data_entry'`. `farm_manager`/`data_entry` are always locked to their own `farm_id`/`enterprise_id` columns. `super_admin` has neither set on their own user row — instead they operate through a **session-based active farm** (see below), and every controller (Pointage and Stock alike) scopes its super_admin branch to that farm. Cross-tenant access returns `abort(403)`, not a 404.
All tenant scoping goes through the helpers on the base [Controller.php](app/Http/Controllers/Controller.php) — never re-derive it per controller (hand-rolled copies drifted apart and leaked data across farms and divisions):
- `scopedFarmId()` (reads) / `resolveWriteFarmId()` (writes): the super_admin's `session('active_farm_id')`, everyone else's `$user->farm_id`.
- `assertFarmScope($request, $record->farm_id)`: any farm-owned record (all Stock/Transport models, operations, blocs, harvests).
- `assertEnterpriseInScope()` / `assertQuinzaineInScope()` / `assertEmployeeInScope()`: farm check **plus the division lock** — a user with `users.enterprise_id` set may only touch that division (its quinzaines, its employees).
- `scopedEnterpriseId()`: the `?enterprise_id=` drill-down filter, validated against the farm (403 if foreign); a division-locked user always gets their own.
- `selectableEnterprises()`: the division list for pickers/filters (none for a division-locked user).

Stock has no enterprise concept — it's pooled at the farm level, so Stock controllers only ever need `assertFarmScope()`. Controller-specific *role* rules (e.g. only farm_manager/super_admin may create/delete a division; a super_admin may open any farm's settings from the Hub) stay in small private helpers that call these.

### Super admin works within one active farm at a time
`super_admin` has no `farm_id` of their own — they must activate one via `FarmController::activate` (`POST /farms/{farm}/activate`, sets `session('active_farm_id')`) before touching Pointage or Stock. The `farm.selected` middleware ([EnsureFarmSelected.php](app/Http/Middleware/EnsureFarmSelected.php), applied to the Pointage+Harvests+Analytics+Payroll+Settings+Stock route group in `routes/web.php`) redirects them back to the Hub (`dashboard`) if no farm is active yet. `FarmController::deactivate` clears it (the "Changer de Ferme" action in the nav). The active farm is shared to every Inertia page as the `activeFarm` prop (see `HandleInertiaRequests::share()`) so the layout can display it without every controller passing it manually. There is no "view all farms at once" mode — by design, super_admin always works inside exactly one farm, same as everyone else, just able to switch which one.

### Navigation: one persistent sidebar, grouped by domain
`AuthenticatedLayout.jsx` no longer swaps between three separate sidebars — [AppSidebar.jsx](resources/js/Components/AppSidebar.jsx) renders one sidebar for the whole app, with a Pointage section, a Stock section, and a small Administration section (Utilisateurs, Paramètres Système), each independently collapsible. The section matching the current route opens by default (`route().current('pointage.*')` etc., computed inline in the layout — not passed in by pages), but a user can have several open at once; this state lives in `AppSidebar` itself and resets on every Inertia navigation (the layout isn't a persistent layout, so the component remounts per page). Sections are omitted entirely when the user lacks that domain (`User::canAccessPointage()`/`canAccessStock()`, mirrored client-side) or, for Pointage/Stock, when a super_admin hasn't activated a farm yet. The top bar itself now only shows the logo/wordmark and the user menu — no zone-switcher links — since the sidebar is always visible with every domain the user has access to. `farms.settings` (shared by Pointage, Stock, and Administration alike) lives under Administration and needs no special handling now that there's only one sidebar tree. Do not reintroduce per-zone sidebars or per-page nav bars/filters (like the removed `FarmFilter` component) — domain access and the active farm are both handled centrally in the layout/session, not per-page.

### Payroll calculation is centralized and formula-driven
All pay/invoice math lives in [PayrollService.php](app/Services/PayrollService.php) — do not reimplement pay calculations in controllers. It branches on `Enterprise.contract_type`:
- `avec_contrat` ("AGRIPER" logic) — worker net pay includes deduction rate, overtime (`hs`) at a fixed hourly rate, holiday (`is_jf`) bonus, plus a separate client-invoicing (`net_factur_j`/TTC) calculation using different fixed charges/tax constants.
- anything else ("HAFILATY" logic) — simple pass-through of `brut` rate as net pay, no client invoicing.

The constants at the top of the class (`DEDUCTION_RATE`, `HS_HOURLY_RATE`, `CHARGE_RATE`, etc.) were reverse-engineered from an existing Excel model — treat them as fixed business constants, not tunables, unless the user provides updated formulas.

Client invoicing (`net_factur_j`/`total_ttc`) is **dated**: `calculateInvoicing()` uses the October 2026 formula (from "P-15102026 PERSEALAND AGRI INTERIM.xlsx": comp × 1.0674, a JF day weighted like one H.S. hour, no separate JF bonus in TTC) for quinzaines starting on/after `OCTOBER_2026_INVOICING_FROM`, and the earlier formula before that, so re-exporting a past period never changes it. Always pass the quinzaine's `start_date`. Its inputs (TOTAL J = days actually worked, JF = every JF day worked or not, piece-rate excluded) are defined once in `invoicingForRecords()` — the closing snapshot and the Excel export both go through it; don't recount them elsewhere. Worker pay (`calculate()`) is not dated.

Jours fériés: a worked JF day pays NET/J + NET/J; an unworked one is a record with no operation/bloc paying one NET/J. Marking a holiday for a whole day (Grid day header → `JourFerieService`) covers every open division of the farm (only their own for a division-locked user) and pays the unworked holiday only to workers eligible under Code du travail art. 219 §2: worked the division's last work day before it, or 13 days in the 30 days before it. Piece-rate workers are left for manual handling (art. 220's 1/26 rule isn't computed).

The division Excel export ([PointageExport.php](app/Exports/PointageExport.php), one sheet per division in `DivisionsPointageExport`) reproduces the farm's own "FICHE DE POINTAGE DES OUVRIERS" workbooks — one of three fiches per division (`PointageExport::ficheFor()`): invoiced → "AGRI INTERIM" (NET FACTUR J/TTC), else `enterprises.mo_declaree` → declared (RIB/COMP/SAL BRUT) or "MO NON DECLARE" (4% margin footer). It is a live workbook built cell by cell (not a Blade view): day cells hold "BLOC|ABREVIATION" codes and every total, bloc table and footer is the fiche's own Excel formula, mirroring PayrollService (NET FACTUR J dated the same way). Only SAL NET/J, SAL BRUT/J, H.S hours and J.F count are written as values; whatever the formula can't express (piece-rate days, a NET/J that changed mid-period) is added to TOTAL NET as a constant so it equals the records' stored net. `PointageExportTest` evaluates the formulas against PayrollService — keep it passing when changing either side.

`PayrollService::calculate()` is called per `PointageRecord` (per employee/day); `generateSnapshot()` aggregates a whole `Quinzaine` into a `QuinzaineSummary` (totals by employee/operation/bloc) — this is what powers payslips and history views.

### Stock movements always pair a `StockMovement` row with a `StockInventory` update
Every stock-affecting controller action (`StockMovementController::stockIn/stockOut/adjustment`, `FuelTransactionController`, `ManualStockEntryController`) wraps the write in `DB::transaction()` and does two things together: insert an immutable `StockMovement` audit row, then mutate `StockInventory.quantity_on_hand` (`firstOrCreate` if it doesn't exist yet). Follow this pattern for any new stock-mutating endpoint rather than updating inventory directly. Stock only ever flows from the central `magasin` out to a bloc (`stockOut`) — there is no bloc-to-bloc transfer in this business, and no such endpoint (a `transfer()` method existed but modeled a workflow that doesn't happen and silently destroyed stock quantities; it was removed).

A sortie (`ManualStockEntry`) is editable/deletable only while unverified. Anyone with Stock access — the magasinier included — can verify it; once verified it is locked for everyone until a `farm_manager`/`super_admin` unlocks it (`ManualStockEntryController::unverify`). Réceptions (`stockIn`) and inventory counts have no edit/delete at all. Every stock write locks its `StockInventory` row through `Controller::lockedInventoryFor()` (one row per product, `unique(product_id)`).

### Quinzaine locking
A `Quinzaine` has `is_closed`. Once closed, `PointageController::updateCell` refuses further edits ("This period is closed and cannot be modified"). Any new mutation on pointage data for a given quinzaine should check this flag first.

## Code conventions

- **Controllers**: plain resourceful methods (`index`, `store`, `show`, `edit`, `update`, `destroy`) plus ad-hoc action methods (e.g. `toggleActive`, `receive`, `resolve`, `verify`) — no form requests, validation is inline via `$request->validate([...])` in the controller method.
- **Routes**: all defined in [routes/web.php](routes/web.php) (no `Route::resource`, every route is spelled out explicitly), grouped under `auth`+`verified` middleware, named `area.subarea.action` (e.g. `stock.manual-entries.verify`). New stock/pointage features should follow this flat, explicitly-named style rather than switching to resource routing.
- **Models**: thin — `$fillable`, `$casts`, and relationship methods only, no accessors/scopes/business logic. Business logic belongs in `app/Services` (see `PayrollService`) or the controller.
- **JSON vs Inertia responses**: page-loading actions return `Inertia::render(...)`. For mutating actions, the response must match how the frontend calls it — if the page calls the route via Inertia's `useForm()` (`post`/`put`/`delete`), the controller **must** return a redirect (e.g. `redirect()->back()`), not `response()->json(...)`; Inertia throws "All Inertia requests must receive a valid Inertia response" otherwise (this bug was found and fixed across `Product`/`Vehicle`/`FuelTransaction`/`ManualStockEntry` controllers — follow their `store`/`update`/`destroy` as the template for any new `useForm()`-driven action). Only return raw `response()->json(...)`/`JsonResponse` for endpoints genuinely called via plain `fetch`/`axios` (e.g. `ProductController::categories/unitTypes/lowStock`), not Inertia's form helper.
- **Frontend pages** mirror the route structure 1:1 under `resources/js/Pages/<Area>/<Action>.jsx` (e.g. `Stock/Vehicles/Index.jsx`, `Stock/Vehicles/Edit.jsx`). Shared chrome is `Layouts/AuthenticatedLayout.jsx` / `GuestLayout.jsx`; reusable inputs/buttons live in `resources/js/Components/`.
- French domain vocabulary is used throughout code, not just UI copy (`bloc`, `sector`/`secteur`, `parcelle`, `quinzaine`, `jf` = jour férié, `hs` = heures supplémentaires) — keep new fields/variables consistent with this rather than translating to English.
