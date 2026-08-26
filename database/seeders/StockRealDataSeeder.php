<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use App\Models\VehicleUsage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Real Produits + Matériel data for Persealand, transcribed from the farm's own
 * "inventaire de magasin.xlsx" and "INVENTAIRE DES ENGINS 2026.xlsx" spreadsheets —
 * every row hardcoded below rather than read from the spreadsheets at seed time.
 * Replaces StockSeeder's random demo data for this farm.
 *
 * Vehicles are always one row per physical unit — a "Quad x3" line in the source becomes
 * 3 separate rows here, never a single row with quantity=3, since each is individually
 * driven/registered and needs its own status, driver and maintenance history. Generic
 * towed implements (broyeur, charrue...) are the opposite: no individual identity is
 * lost by keeping them as one row with a quantity, so they stay grouped.
 *
 * A few plates could not be filled from the source and are marked accordingly:
 *  - plate_number "A RENSEIGNER" — the source had no real plate for this vehicle
 *    (3x Dacia Duster, 3x Quad), or listed the same plate twice for two different
 *    tractors/drivers (a second "5710"). Needs the real plate confirmed on-site.
 */
class StockRealDataSeeder extends Seeder
{
    public function run(): void
    {
        $farm = Farm::where('name', 'Persealand')->first();
        if (!$farm) {
            $this->command->warn('Persealand farm not found — skipping StockRealDataSeeder.');
            return;
        }

        $this->command->info('Seeding real Produits + Matériel data for Persealand...');

        // Wipe every trace of the old dummy/demo Stock data for this farm, not just the
        // Products/Vehicles rows themselves — StockSeeder also generated fake fuel
        // transactions, sorties, movements and alerts against them, which would otherwise
        // dangle (or keep showing up in the UI via a soft-deleted product's withTrashed()
        // relations) after the products/vehicles they reference are gone.
        $oldProductIds = Product::withTrashed()->where('farm_id', $farm->id)->pluck('id');
        $oldVehicleIds = Vehicle::where('farm_id', $farm->id)->pluck('id');

        DB::statement('PRAGMA foreign_keys = OFF;');
        StockMovement::whereIn('product_id', $oldProductIds)->delete();
        FuelTransaction::whereIn('vehicle_id', $oldVehicleIds)->delete();
        ManualStockEntry::whereIn('product_id', $oldProductIds)->orWhereIn('vehicle_id', $oldVehicleIds)->delete();
        StockAlert::whereIn('product_id', $oldProductIds)->delete();
        VehicleUsage::whereIn('vehicle_id', $oldVehicleIds)->delete();
        VehicleMaintenanceLog::whereIn('vehicle_id', $oldVehicleIds)->delete();
        StockInventory::whereIn('product_id', $oldProductIds)->delete();
        Product::withTrashed()->where('farm_id', $farm->id)->forceDelete();
        Vehicle::where('farm_id', $farm->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON;');

        $productsData = [
            ['name' => 'BRIDE LIBRE 200', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 25],
            ['name' => 'BRIDE LIBRE 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 21],
            ['name' => 'BRIDE LIBRE 100  4"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 13],
            ['name' => 'BRIDE LIBRE 80 3\'\'', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 31],
            ['name' => 'BRIDE LIBRE 65  1/2"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 1],
            ['name' => 'BOUCHON 63', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'BOUCHON 50', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'BOUCHON 32', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COUDE 225 Ǿ / 90˚', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 2],
            ['name' => 'COUDE 200 Ǿ / 45˚', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 4],
            ['name' => 'COUDE 160/45', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 10],
            ['name' => 'COUDE 140/90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 2],
            ['name' => 'COUDE 140/45', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 8],
            ['name' => 'COUDE 125/90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COUDE 125/45', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 32],
            ['name' => 'COUDE 110/45', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 26],
            ['name' => 'COUDE 90/45', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COUDE 90/90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COUDE 75/90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 8],
            ['name' => 'COUDE 63/90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 11],
            ['name' => 'COUDE 63/45', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 15],
            ['name' => 'COUDE 50/90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 10],
            ['name' => 'TE 225', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 1],
            ['name' => 'TE 200', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 3],
            ['name' => 'TE 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 15],
            ['name' => 'TE 140', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 1],
            ['name' => 'TE 125', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 33],
            ['name' => 'TE 110', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 16],
            ['name' => 'TE 90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 5],
            ['name' => 'TE 75', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 16],
            ['name' => 'TE 63', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 2],
            ['name' => 'TE 50', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 29],
            ['name' => 'TE 32', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 10],
            ['name' => 'COLLECTEUR 8 VOIES', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COLLET STRIE 225', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 3],
            ['name' => 'COLLET STRIE 200', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 16],
            ['name' => 'COLLET STRIE 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 14],
            ['name' => 'COLLET STRIE 110', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 5],
            ['name' => 'COLLET STRIE 90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COLLET STRIE 75', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COLLET STRIE 63', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COLLET STRIE 50', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'CLAPET ANTI-RETOUR 90  3"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'CLAPET ANTI-RETOUR 75  2"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'CLAPET ANTI-RETOUR 50  1/"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'KIT DE BRIDES 110', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'KIT DE BRIDES 100', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT 140', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT 225', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT 200', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT 110', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (noir) 300', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (noir) 225', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (noir) 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (noir) 140', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (noir) 125', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (noir) 110', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (Jaune) 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (Jaune) 110', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'JOINT COUVERCLE (Jaune) 90', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'MANCHON ELECTROSOUDABLE', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'REDUCTION 160/140', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'REDUCTION 140/125', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'REDUCTION 125/110', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'REDUCTION 90/75', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'REDUCTION 75/63', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'REDUCTION 63/50', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'REDUCTION MALE/FEMALE 1*3/4', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 1],
            ['name' => 'REDUCTION FELTE MAL/FEMELLE 1"1/2*1"*1/4', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'EMBOUT MALE 63/75', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 2],
            ['name' => 'EMBOUT MALE 90/110*3"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 28],
            ['name' => 'EMBOUT MALE 32*40  1\'\'', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 12],
            ['name' => 'EMBOUT 63/75*3"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'EMBOUT 63/50', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'EMBOUT 32*40 (1\'\')', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'EMBOUT 32*4', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'EMBOUT FEMILLE 90/110*3"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 7],
            ['name' => 'EMBOUT FEMILLE 50*1\'\'(1/2)', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 15],
            ['name' => 'ADAPTATEUR FEMELLE 90*3"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'ADAPTATEUR FEMELLE 50*1/2"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE 110  4"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE 90  3"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE 75', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE 63', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE 50  1/2"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE 32  1"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE POLYETHYLENE 32', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE POLYETHYLENE 63', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNA PAPILLON COMPLET 200', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNA PAPILLON COMPLET 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNA PAPILLON COMPLET 125', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNA PAPILLON COMPLET', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE PAPILLON 200', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE PAPILLON 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE PAPILLON 150', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE A OPERCULE 100', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE DE CONTRÔLE HYDRAULIQUE 100 mm  4"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE A AIR GRAND 1"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE A AIR PETIT 1"', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'VANNE A AIR 2 "', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'COLLE  KG', 'category' => 'other', 'unit_type' => 'kg', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'TURBINE', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'BOITE DE JONCTION', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'SONDE DE NIVEAU', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'SOLENOIDE', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'REGULATEUR ATOMISEUR / MANOMETRE 50bar', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'CARDAN DE TRANSMITION', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'FILTRE ATOMISEUR 50', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'FILTRE ATOMISEUR 40', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'FLEXIBLE D\'ATOMISEUR', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'FLEXIBLE DE REMPLISAGE ATOMISEUR', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'PULVERISATEUR HAUTE PRESSION (g) (Lance)', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'PULVERISATEUR HAUTE PRESSION (p) Lance', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'RACCORD DE JONCTION (Joint Jibault) DN 200', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'RACCORD DE JONCTION (Joint Jibault) DN 160', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'RACCORD DE JONCTION (Joint Jibault) DN 140', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'RACCORD DE JONCTION (Joint Jibault) DN 125', 'category' => 'tools', 'unit_type' => 'units', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'HUILE 15/40  L', 'category' => 'vehicle_needs', 'unit_type' => 'liters', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
            ['name' => 'GRAISSE  KG', 'category' => 'vehicle_needs', 'unit_type' => 'kg', 'min_stock_level' => 0, 'quantity_on_hand' => 0],
        ];

        foreach ($productsData as $data) {
            $product = Product::create([
                'farm_id' => $farm->id,
                'name' => $data['name'],
                'category' => $data['category'],
                'unit_type' => $data['unit_type'],
                'min_stock_level' => $data['min_stock_level'],
                'is_active' => true,
            ]);

            StockInventory::create([
                'product_id' => $product->id,
                'quantity_on_hand' => $data['quantity_on_hand'],
                'quantity_reserved' => 0,
            ]);
        }

        $assetsData = [
            ['asset_type' => 'vehicle', 'type' => 'tractor', 'name' => 'MASSY FERGUSON', 'model' => null, 'plate_number' => '5710', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => 'TAHRI ABDELMOUNIM', 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'tractor', 'name' => 'MASSY FERGUSON', 'model' => null, 'plate_number' => 'A RENSEIGNER', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => 'BEJTIT ABDELLAH', 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'tractor', 'name' => 'MASSY FERGUSON', 'model' => null, 'plate_number' => '4708', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => 'EL GHRISSI  SAID', 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'tractor', 'name' => 'LANDINI', 'model' => null, 'plate_number' => '90 F', 'serial_number' => null, 'quantity' => 1, 'status' => 'in_repair', 'is_location' => false, 'driver_name' => 'HRAIA ABD-ELRAHIM', 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => 'PANNE'],
            ['asset_type' => 'vehicle', 'type' => 'tractor', 'name' => 'MASSY FERGUSON', 'model' => null, 'plate_number' => '3090', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'tractor', 'name' => 'LANDINI', 'model' => null, 'plate_number' => '8865', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => true, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'sprayer', 'name' => 'ATOMISEUR', 'model' => 'BENAGRI', 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => 2000, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'sprayer', 'name' => 'ATOMISEUR', 'model' => 'Q.I 09', 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => 2000, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'sprayer', 'name' => 'ATOMISEUR', 'model' => 'Q.I 09', 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => 2000, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'sprayer', 'name' => 'ATOMISEUR', 'model' => 'Q.I 09', 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'in_repair', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => 2000, 'fuel_type' => 'diesel', 'notes' => 'PANNE'],
            ['asset_type' => 'equipment', 'type' => 'sprayer', 'name' => 'PULVERSATEUR PORTE', 'model' => 'BENAGRI', 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => 600, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'sprayer', 'name' => 'PULVERSATEUR PORTE', 'model' => 'BENAGRI', 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => 600, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'sprayer', 'name' => 'PULVERSATEUR TRACTE', 'model' => 'SOLMAX', 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => 1600, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'sprayer', 'name' => 'PULVERSATEUR  TRACTE', 'model' => 'SOLMAX', 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => 1600, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'mulcher', 'name' => 'BROYEUR', 'model' => 'BREXTRA S 09 Z  1,60', 'plate_number' => null, 'serial_number' => null, 'quantity' => 2, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'roller', 'name' => 'COVER CROPS', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 3, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'plow', 'name' => 'CHARRUE A DISQUE', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 2, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'mower', 'name' => 'FAUCHEUSE', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 2, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'leveler', 'name' => 'LAME NIVELEUSE', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 2, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'car', 'name' => 'DACIA', 'model' => 'DUSTER', 'plate_number' => 'A RENSEIGNER 2', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'car', 'name' => 'DACIA', 'model' => 'DUSTER', 'plate_number' => 'A RENSEIGNER 3', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'car', 'name' => 'DACIA', 'model' => 'DUSTER', 'plate_number' => 'A RENSEIGNER 4', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'quad', 'name' => 'QUAD', 'model' => 'LINHAI', 'plate_number' => 'A RENSEIGNER 5', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'quad', 'name' => 'QUAD', 'model' => 'LINHAI', 'plate_number' => 'A RENSEIGNER 6', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'vehicle', 'type' => 'quad', 'name' => 'QUAD', 'model' => 'LINHAI', 'plate_number' => 'A RENSEIGNER 7', 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'pump', 'name' => 'POMPE A ROUE OUVERTE 2,2 KW', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'pump', 'name' => 'POMPE A ROUE OUVERTE 1,77 KW', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'pump', 'name' => 'POMPE 0,37 KW', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'pump', 'name' => 'POMPE IMMERGIE', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 3, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
            ['asset_type' => 'equipment', 'type' => 'pump', 'name' => 'POMPE DE SURPRESSION', 'model' => null, 'plate_number' => null, 'serial_number' => null, 'quantity' => 1, 'status' => 'operational', 'is_location' => false, 'driver_name' => null, 'capacity_liters' => null, 'fuel_type' => 'diesel', 'notes' => null],
        ];

        foreach ($assetsData as $data) {
            $driver = $data['driver_name']
                ? Employee::where('farm_id', $farm->id)->where('full_name', $data['driver_name'])->first()
                : null;

            Vehicle::create([
                'farm_id' => $farm->id,
                'asset_type' => $data['asset_type'],
                'type' => $data['type'],
                'name' => $data['name'],
                'model' => $data['model'],
                'plate_number' => $data['plate_number'],
                'serial_number' => $data['serial_number'],
                'quantity' => $data['quantity'],
                'status' => $data['status'],
                'is_location' => $data['is_location'],
                'default_driver_id' => $driver?->id,
                'capacity_liters' => $data['capacity_liters'],
                'fuel_type' => $data['fuel_type'],
                'notes' => $data['notes'],
                'is_active' => true,
            ]);
        }

        $this->command->info('Done: ' . count($productsData) . ' produits, ' . count($assetsData) . ' matériel.');
    }
}
