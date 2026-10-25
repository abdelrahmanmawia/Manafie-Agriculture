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

GitHub Actions (`.github/workflows/tests.yml`) runs the suite on every push and pull request twice — on a MySQL 8.4 service and on SQLite — after `npm run build` (pages render through `@vite`). Locally, the MySQL run is `DB_CONNECTION=mysql DB_DATABASE=<empty scratch db> php artisan test` (never the real `farm_management`: RefreshDatabase wipes it). Things that only fail on MySQL so far: money stored to the centime (compare with a 0.01 delta), JSON columns come back with sorted keys, and cascades run in no fixed order (delete restrict-keyed children explicitly, as `FarmController::destroy()` does).

## Architecture

### Domain hierarchy
`Farm` → `Enterprise` (a farm has many enterprises/divisions) → `Employee`, `Quinzaine` (pay period), `User`.
`Farm` → `Bloc` → `Sector` → `Parcelle` (field/land breakdown, via `hasManyThrough`).
`Quinzaine` (bi-weekly pay period) → `PointageRecord` (one row per employee/day/operation) → rolls up into `QuinzaineSummary` (a cached snapshot, generated when a quinzaine is closed). A quinzaine's records belong to its division even after a worker moves to another one (`employees.enterprise_id` is only their *current* division), so anything listing a quinzaine's people (fiche export, division Grid, état global) takes everyone with records in it — the moved ones read-only in the Grid — never just `Employee::where('enterprise_id', …)`.

`Equipe` (chef d'équipe team, farm-scoped) is the unit of the farm's first pointage step: the Grid's "Par équipe" mode (`?mode=equipe&equipe=<id|none>`) lists one team across all divisions, chef first, and points each worker against **their own division's** quinzaine for the same dates, so payroll and closing stay per division. A worker is in at most one équipe (`employees.equipe_id`, nullable — "Sans équipe" lists the rest). An équipe optionally rides a `TransportVehicle`, and that is the only rider assignment: a vehicle's riders (`TransportVehicle::employees()`, which prices its auto daily cost) are the members of the équipes riding it — there is no `employees.transport_vehicle_id` any more (migration 000046 turned each vehicle's riders into one équipe named after it). Teams span divisions, so a division-locked user can view équipes but not change them (`EquipeController`). An équipe or a vehicle can be deactivated (switch on the Équipes / Véhicules pages) without losing anything: an inactive équipe keeps its members but drops out of the Grid's team picker, and its active members are deactivated with it (`EquipeController::switchMembers()`, marked `employees.desactive_avec_equipe`) — reactivating it brings back exactly those, not a member deactivated on their own (switching a worker by hand clears the mark); any still-active member is listed under "Sans équipe" — `riderVehicleId()` stamps no vehicle on new days of an inactive équipe or vehicle; days already pointed keep theirs.

"Worked" is `pointage_records.present`, not "has an operation": the farm points in two steps ([RepartitionService.php](app/Services/RepartitionService.php)) — first who came, per équipe ("✓ tous présents" in the Grid, or a cell's "Présent — opération à préciser"), which records a present day with no operation/bloc yet, paid like any worked day (the operation never changes a day-rate day's pay); then what they did, per division, worker by worker or by headcount ("≡ Répartir": totals per bloc/operation for the day — workers already on an operation stay, the "à préciser" ones are assigned, keeping first the operation of their last worked day). The only non-present record is the unworked paid jour férié. Pay, invoicing (TOTAL J), JF eligibility, transport riders and the Excel fiche ("P" in the day cell) all go by `present`; a quinzaine can't close while it has present days without an operation (`EnterpriseController::closeQuinzaine()`). Any new path that writes pointage records must set `present` explicitly. A worker is present in one division a day: every path writing a present day first checks `RepartitionService::presentAilleurs()` (cell and paste refuse with `errors.date`, "tous présents" skips and names them, a scan comes back not ok) — workers listed on two divisions' fiches for the same days used to be paid twice.

Transport cost is computed from the pointage, never ticked by hand ([TransportService.php](app/Services/TransportService.php)): on each day, a vehicle costs (distinct workers present that day — `present`, so an unworked JF doesn't count and an "à préciser" day does — and whose record carries that vehicle) × `transport_vehicles.price_per_person`, or its flat `fixed_net_per_day` when set (kept as an option), and 0 when nobody rode it. `pointage_records.transport_vehicle_id` is stamped from the worker's équipe on the record's first write (`TransportService::riderVehicleId()`, called by `PointageController::upsertPointageRecord()` and the scan sync) and kept afterwards, so moving a worker to another équipe doesn't move past days to the new vehicle — any new path that creates pointage records must stamp it too. The only manual input is the exception `TransportVehicleAbsence` ("the vehicle didn't come that day": its riders came on their own, are paid, the vehicle costs 0), set from the Grille Transport. Residence locations no longer price transport.

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

Fixed monthly salary ([SalaireFixeService.php](app/Services/SalaireFixeService.php)): an employee with `employees.salaire_mensuel` set (pointeurs, chefs — e.g. 3500/4000 DH) is paid that amount once a month, in the month's second quinzaine (start day ≥ 16), as a `pointage_records` row with `nature = 'salaire_mensuel'` (no operation/bloc, `present = false`, dated the quinzaine's `end_date`, net = the salary) — even with no days pointed. Their day records (`nature = 'jour'`, the default) are still pointed for presence/operations but pay 0: `PointageRecordObserver` forces it on every save. The unique key is (employee, quinzaine, date, nature), so **any query that looks up or deletes "the day's record" must filter `nature = 'jour'`**. Only open quinzaines are synced: changing the salary/division/active flag (`EmployeeController`) re-syncs, and creating a second-half quinzaine pays its division's salaried workers. Invoicing bills the days as usual (salary rows are excluded); the fiche adds the salary to TOTAL NET through its adjustment and as a "SALAIRES FIXES" footer line on declared/non-declared fiches.

Jours fériés: a worked JF day pays NET/J + NET/J; an unworked one is a record with no operation/bloc paying one NET/J. Marking a holiday for a whole day (Grid day header → `JourFerieService`) covers every open division of the farm (only their own for a division-locked user) and pays the unworked holiday only to workers eligible under Code du travail art. 219 §2: worked the division's last work day before it, or 13 days in the 30 days before it. Piece-rate workers are left for manual handling (art. 220's 1/26 rule isn't computed).

The division Excel export ([PointageExport.php](app/Exports/PointageExport.php), one sheet per division in `DivisionsPointageExport`) reproduces the farm's own "FICHE DE POINTAGE DES OUVRIERS" workbooks — one of three fiches per division (`PointageExport::ficheFor()`): invoiced → "AGRI INTERIM" (NET FACTUR J/TTC), else `enterprises.mo_declaree` → declared (RIB/COMP/SAL BRUT) or "MO NON DECLARE" (4% margin footer). It is a live workbook built cell by cell (not a Blade view): day cells hold "BLOC|ABREVIATION" codes and every total, bloc table and footer is the fiche's own Excel formula, mirroring PayrollService (NET FACTUR J dated the same way). Only SAL NET/J, SAL BRUT/J, H.S hours and J.F count are written as values, plus the bloc tables' J.F / H.SUP per operation, taken from the records (each hour/day on the operation it was worked on; an unworked J.F on the worker's main operation) — there is no "OP H.S / J.F" column like the hand-made fiches had; whatever the formula can't express (piece-rate days, a NET/J that changed mid-period) is added to TOTAL NET as a constant so it equals the records' stored net. `PointageExportTest` evaluates the formulas against PayrollService — keep it passing when changing either side.

The payment sheet ([PaiementEquipesExport.php](app/Exports/PaiementEquipesExport.php), "Paiement par équipe" on the Quinzaines page) reproduces the farm's "POINTAGE PAR TRANSPORT" workbook for one period across all divisions: a RÉSUMÉ sheet (per équipe: workers, days, net split VIREMENT / ESPÈCES / RIB À VÉRIFIER, headcount per day) and one sheet per équipe (chef first, + "Sans équipe"). NET À PAYER = TOTAL JOURS × SAL. NET/J (`PayrollService::salNetJFromRecords()`, shared with the fiche; 0 for a salaried worker) + PRIMES + SALAIRE FIXE (the salary rows), PRIMES being everything else the records pay, so it always equals their stored net. PAIEMENT is the workbook's own formula on the RIB (24 digits → VIREMENT, none → ESPÈCES); AMO / CNSS is derived, not stored: an invoicing division's workers are CNSS, a non-declared division's AMO, others CNSS if they have a RIB, else AMO.

`php artisan pointage:import-fiches <dir>` ([FicheImportService.php](app/Services/FicheImportService.php)) rebuilds the farm's pointage from those same workbooks (Sept–Oct 2026 manifest in the command): it deletes quinzaines, records, summaries, transport costs and équipes, imports each division fiche (one present record per "BLOC|ABREVIATION" day, H.S. spread over the days), builds the équipes from the team workbook, and imports a presence-only period as "à préciser" — its team sheets set each worker's équipe (new workers and team changes included) before their days are written, so the days ride that équipe's vehicle. Workers are matched by CIN then name and updated from their latest fiche. One transaction; `--dry-run` reports and rolls back. The farm points in the app since, so without `--dry-run` it asks before wiping (or needs `--force`). A TOTAL NET typed by hand (a fixed salary) has no days to carry it and is only reported.

`PayrollService::calculate()` is called per `PointageRecord` (per employee/day); `generateSnapshot()` aggregates a whole `Quinzaine` into a `QuinzaineSummary` (totals by employee/operation/bloc) — this is what powers payslips and history views.

### Stock movements always pair a `StockMovement` row with a `StockInventory` update
Every stock-affecting controller action (`StockMovementController::stockIn/stockOut/adjustment`, `FuelTransactionController`, `ManualStockEntryController`) wraps the write in `DB::transaction()` and does two things together: insert an immutable `StockMovement` audit row, then mutate `StockInventory.quantity_on_hand` (`firstOrCreate` if it doesn't exist yet). Follow this pattern for any new stock-mutating endpoint rather than updating inventory directly. Stock only ever flows from the central `magasin` out to a bloc (`stockOut`) — there is no bloc-to-bloc transfer in this business, and no such endpoint (a `transfer()` method existed but modeled a workflow that doesn't happen and silently destroyed stock quantities; it was removed).

Seeders: `StockRealDataSeeder` loads the farm's real magasin + matériel inventories (opening stock as "Inventaire initial" adjustments); `DemoStockSeeder` (calls it first, so it resets the farm's stock) adds demo consumables, suppliers and 90 days of réceptions, sorties, fuelings, maintenance, rental days and counts through the same stock rules — test data, not for a farm in production.

Fuel goes out as a vehicle's fill-up, not a sortie: a "Consommation" sortie of a product whose category is `product_categories.is_carburant` (the farm's "Carburant") is recorded by `ManualStockEntryController::ravitaillement()` as a `FuelTransaction` (`fueling`, vehicle required, at the CUMP) with its `out` movement (`reference_type = 'fuel_transaction'`) — it shows on the Carburant page and in the vehicle's fuel cost; a loss/theft of fuel stays a sortie, and a sortie can't be edited into a fill-up. A fill-up is removed (liters back to the magasin) by a manager any time, by whoever recorded it the same day (`FuelTransactionController::destroy`).

"Stock faible" means at or under a minimum someone set: a product with `min_stock_level` 0 is never low and gets no alert, even at 0 (`Product::isLowStock()`, `StockAlertService::syncLowStock()`, the inventory's low_stock filter and its counter all use that rule).

The Mouvements, Sorties and Carburant lists are paginated on the server (50 per page, `paginate()->withQueryString()`) with their filters in the URL (`from`/`to` dates, type, search; sorties also `statut`, fuel also `vehicle_id`) through `useListFilters()` + `Components/Pagination.jsx` — don't load a whole history into a page again; the Carburant totals are computed over every matching row, not the page.

A sortie (`ManualStockEntry`) is editable/deletable only while unverified. Anyone with Stock access — the magasinier included — can verify it; once verified it is locked for everyone until a `farm_manager`/`super_admin` unlocks it (`ManualStockEntryController::unverify`). Réceptions (`stockIn`) and inventory counts have no edit/delete at all. Every stock write locks its `StockInventory` row through `Controller::lockedInventoryFor()` (one row per product, `unique(product_id)`).

### "Aujourd'hui" is the day's starting point
The Pointage sidebar follows the day's work: Aujourd'hui, Quinzaines, Équipes, Employés, Transport, Historique de paie, Analyses, Récoltes, then setup — Divisions (`pointage.index`, `Pointage/Index.jsx`: create a division, each one's quinzaines/analysis/settings; no totals any more) and Badges. A Pointage-only `data_entry` lands on Aujourd'hui.

`Pointage/Aujourdhui.jsx` (`PointageController::aujourdhui()`, `?date=`, default today; first item of the Pointage sidebar) shows the day's two steps: each active équipe's headcount / present / "à préciser" that day (plus "Sans équipe"), each card opening the équipe Grid on that day, then each division's days "à préciser" that day and over the period. The Grid opens on `?jour=` when it's in the period, else on today, else on the first day.

### The Quinzaines page is per period, not per division
`Pointage/Quinzaines.jsx` shows one card per pay period (its dates) with one line per division: its progress (`PointageController::quinzaines()` adds `stats` — ouvriers, jours, net, `a_preciser`), its grid/exports and edit/delete/close. A period is opened for several divisions at once (`EnterpriseController::createQuinzaine()` takes `enterprise_ids`; a division that already has the dates is skipped, all skipped → `errors.quinzaine`), with the next period's dates and label suggested; it is closed per division or for the whole period at once (`EnterpriseController::closePeriode()`, all or nothing: any division with days "à préciser" keeps them all open). Refusals (closing with days "à préciser", duplicates) come back as `errors.quinzaine`, shown in a banner on the page — `FlashToast` only shows `flash.success`/`flash.error`.

### Change history and backups
Every pointage day written through the model is traced in `pointage_changes` by `PointageRecordObserver` (created / updated / deleted, the pay-relevant fields before and after, the user — `null` for console/system) — so pointage code must create, update and delete records **through the model**, never `PointageRecord::where(...)->update()/delete()` (the import's wholesale wipe is the one exception). The Grid's day dialog shows it (`PointageController::historique()`, JSON). The dialog carries the day's `present`, so saving an "à préciser" day without an operation keeps it; clearing a day is its explicit "Marquer absent" button.

`php artisan db:backup` writes a gzipped mysqldump to `config('backup.path')` (`.env` `BACKUP_PATH`, outside the project — the dumps hold CIN/RIB) and keeps the newest `BACKUP_KEEP`; it runs daily at 13:00 from the Kernel schedule and, on the office PC, from the Windows task "FarmManagement - sauvegarde quotidienne" (log: `storage/logs/backup.log`). Take one before any migration or data fix on the real database.

### Quinzaine locking
A `Quinzaine` has `is_closed`. Once closed, `PointageController::updateCell` refuses further edits ("This period is closed and cannot be modified"). Any new mutation on pointage data for a given quinzaine should check this flag first.

## Code conventions

- **Controllers**: plain resourceful methods (`index`, `store`, `show`, `edit`, `update`, `destroy`) plus ad-hoc action methods (e.g. `toggleActive`, `receive`, `resolve`, `verify`) — no form requests, validation is inline via `$request->validate([...])` in the controller method.
- **Routes**: all defined in [routes/web.php](routes/web.php) (no `Route::resource`, every route is spelled out explicitly), grouped under `auth`+`verified` middleware, named `area.subarea.action` (e.g. `stock.manual-entries.verify`). New stock/pointage features should follow this flat, explicitly-named style rather than switching to resource routing.
- **Adding a worker** (`EmployeeController::store()`) warns when they look like an existing one (`DoublonEmployeService`: a name or CIN a typo away) and creates them only once confirmed (`confirmer_doublon`); the exact same CIN is refused outright.
- **Models**: thin — `$fillable`, `$casts`, and relationship methods only, no accessors/scopes/business logic. Business logic belongs in `app/Services` (see `PayrollService`) or the controller.
- **JSON vs Inertia responses**: page-loading actions return `Inertia::render(...)`. For mutating actions, the response must match how the frontend calls it — if the page calls the route via Inertia's `useForm()` (`post`/`put`/`delete`), the controller **must** return a redirect (e.g. `redirect()->back()`), not `response()->json(...)`; Inertia throws "All Inertia requests must receive a valid Inertia response" otherwise (this bug was found and fixed across `Product`/`Vehicle`/`FuelTransaction`/`ManualStockEntry` controllers — follow their `store`/`update`/`destroy` as the template for any new `useForm()`-driven action). Only return raw `response()->json(...)`/`JsonResponse` for endpoints genuinely called via plain `fetch`/`axios` (e.g. `ProductController::categories/unitTypes/lowStock`), not Inertia's form helper.
- **Frontend pages** mirror the route structure 1:1 under `resources/js/Pages/<Area>/<Action>.jsx` (e.g. `Stock/Vehicles/Index.jsx`, `Stock/Vehicles/Edit.jsx`). Shared chrome is `Layouts/AuthenticatedLayout.jsx` / `GuestLayout.jsx`; reusable inputs/buttons live in `resources/js/Components/`.
- French domain vocabulary is used throughout code, not just UI copy (`bloc`, `sector`/`secteur`, `parcelle`, `quinzaine`, `jf` = jour férié, `hs` = heures supplémentaires) — keep new fields/variables consistent with this rather than translating to English.
