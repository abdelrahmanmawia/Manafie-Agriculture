<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Enterprise;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Sector;
use App\Models\Parcelle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CsvDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Farm
        $farm = \App\Models\Farm::updateOrCreate([
            'name' => 'Persealand',
        ]);

        // 2. Create Enterprises linked to Farm
        $persea = Enterprise::create([
            'farm_id' => $farm->id,
            'name' => 'PERSEALAND',
            'contract_type' => 'avec_contrat',
            'default_brut_rate' => 97.44,
        ]);

        $agri = Enterprise::create([
            'farm_id' => $farm->id,
            'name' => 'AGRI INTERIM',
            'contract_type' => 'avec_contrat',
            'default_brut_rate' => 97.44,
        ]);

        $hafila = Enterprise::create([
            'farm_id' => $farm->id,
            'name' => 'HAFILATY',
            'contract_type' => 'sans_contrat',
            'default_brut_rate' => 90.87,
        ]);

        // Create Super Admin
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
            ]
        );

        // 2. Operations Data
        $operations = [
            ['OPERATIONS' => 'Caporal', 'ABREVIATION' => 'Cp'],
            ['OPERATIONS' => 'Changement plants', 'ABREVIATION' => 'Ch.P'],
            ['OPERATIONS' => 'Arrachage Eucalyptus', 'ABREVIATION' => 'Ar.Eu'],
            ['OPERATIONS' => 'Brumisation', 'ABREVIATION' => 'Bru'],
            ['OPERATIONS' => 'Creusement tranchée', 'ABREVIATION' => 'Cr.T'],
            ['OPERATIONS' => 'Desherbage chimique', 'ABREVIATION' => 'D.Ch'],
            ['OPERATIONS' => 'Desherbage manuel', 'ABREVIATION' => 'D.M'],
            ['OPERATIONS' => 'Chaulage', 'ABREVIATION' => 'Chlg'],
            ['OPERATIONS' => 'Ebourgeonnage', 'ABREVIATION' => 'Ebo'],
            ['OPERATIONS' => 'Entretien bassin', 'ABREVIATION' => 'En.B'],
            ['OPERATIONS' => 'Entretien parcelle', 'ABREVIATION' => 'En.P'],
            ['OPERATIONS' => 'Estimation de Production', 'ABREVIATION' => 'Est.P'],
            ['OPERATIONS' => 'Epandage Fumier', 'ABREVIATION' => 'Ep.F'],
            ['OPERATIONS' => 'Fixation brise vent', 'ABREVIATION' => 'F.B.V'],
            ['OPERATIONS' => 'Irrigation', 'ABREVIATION' => 'Irr'],
            ['OPERATIONS' => 'Fixation bouchon', 'ABREVIATION' => 'F.B'],
            ['OPERATIONS' => 'Fixation cloture', 'ABREVIATION' => 'F.Cl'],
            ['OPERATIONS' => 'Fixation goutteur', 'ABREVIATION' => 'F.G'],
            ['OPERATIONS' => 'Gardiennage / Jour', 'ABREVIATION' => 'G.J'],
            ['OPERATIONS' => 'Montage pompe', 'ABREVIATION' => 'M.P'],
            ['OPERATIONS' => 'Gardiennage / Nuit', 'ABREVIATION' => 'G.N'],
            ['OPERATIONS' => 'Jardin', 'ABREVIATION' => 'Jrd'],
            ['OPERATIONS' => 'Mastique', 'ABREVIATION' => 'Mst'],
            ['OPERATIONS' => 'Ramassage Escargot', 'ABREVIATION' => 'R.Es'],
            ['OPERATIONS' => 'Reparation fuites', 'ABREVIATION' => 'R.F'],
            ['OPERATIONS' => 'Récolte', 'ABREVIATION' => 'Réc'],
            ['OPERATIONS' => 'Palissage', 'ABREVIATION' => 'Pls'],
            ['OPERATIONS' => 'Paillage', 'ABREVIATION' => 'Pai'],
            ['OPERATIONS' => 'Tractoristes', 'ABREVIATION' => 'Tr'],
            ['OPERATIONS' => 'Purge', 'ABREVIATION' => 'Pur'],
            ['OPERATIONS' => 'acasia', 'ABREVIATION' => 'Aca'],
            ['OPERATIONS' => 'Contrôle Tuyau', 'ABREVIATION' => 'C.T'],
            ['OPERATIONS' => 'Contrôle Serre', 'ABREVIATION' => 'C.S'],
            ['OPERATIONS' => 'Traitement Foliaire', 'ABREVIATION' => 'Tr.F'],
            ['OPERATIONS' => 'Tuteurage', 'ABREVIATION' => 'Tut'],
        ];

        // Create shared Operations for the Farm
        foreach ($operations as $op) {
            if (isset($op['OPERATIONS'])) {
                Operation::create([
                    'name' => $op['OPERATIONS'],
                    'abbreviation' => $op['ABREVIATION'],
                    'farm_id' => $farm->id
                ]);
            }
        }

        // Create Blocs with hierarchical structure (Bloc -> Sector -> Parcelle)
        $blocData = [
            'B1' => [
                'area_m2' => 786666.67,
                'area_ha' => 78.67,
                'sectors' => [
                    'S1' => [
                        'area_m2' => 188090.09,
                        'area_ha' => 18.81,
                        'total_trees' => 10439,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 1254, 'fuerte_trees' => 171, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 25675.68, 'area_ha' => 2.57, 'total_trees' => 1425],
                            ['name' => 'P2', 'hass_trees' => 1010, 'fuerte_trees' => 114, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 20252.25, 'area_ha' => 2.03, 'total_trees' => 1124],
                            ['name' => 'P3', 'hass_trees' => 987, 'fuerte_trees' => 133, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 20180.18, 'area_ha' => 2.02, 'total_trees' => 1120],
                            ['name' => 'P4', 'hass_trees' => 1034, 'fuerte_trees' => 130, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 20972.97, 'area_ha' => 2.10, 'total_trees' => 1164],
                            ['name' => 'P5', 'hass_trees' => 900, 'fuerte_trees' => 126, 'lambhass_trees' => 53, 'zutano_trees' => 0, 'area_m2' => 19441.44, 'area_ha' => 1.94, 'total_trees' => 1079],
                            ['name' => 'P6', 'hass_trees' => 948, 'fuerte_trees' => 102, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 18918.92, 'area_ha' => 1.89, 'total_trees' => 1050],
                            ['name' => 'P7', 'hass_trees' => 958, 'fuerte_trees' => 119, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 19405.41, 'area_ha' => 1.94, 'total_trees' => 1077],
                            ['name' => 'P8', 'hass_trees' => 828, 'fuerte_trees' => 96, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 16648.65, 'area_ha' => 1.66, 'total_trees' => 924],
                            ['name' => 'P9', 'hass_trees' => 1303, 'fuerte_trees' => 173, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 26594.59, 'area_ha' => 2.66, 'total_trees' => 1476],
                        ],
                    ],
                    'S2' => [
                        'area_m2' => 196720.72,
                        'area_ha' => 19.67,
                        'total_trees' => 10918,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 1265, 'fuerte_trees' => 174, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 25927.93, 'area_ha' => 2.59, 'total_trees' => 1439],
                            ['name' => 'P2', 'hass_trees' => 1113, 'fuerte_trees' => 147, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22702.70, 'area_ha' => 2.27, 'total_trees' => 1260],
                            ['name' => 'P3', 'hass_trees' => 1323, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 26108.11, 'area_ha' => 2.61, 'total_trees' => 1449],
                            ['name' => 'P4', 'hass_trees' => 987, 'fuerte_trees' => 147, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 20432.43, 'area_ha' => 2.04, 'total_trees' => 1134],
                            ['name' => 'P5', 'hass_trees' => 1134, 'fuerte_trees' => 126, 'lambhass_trees' => 64, 'zutano_trees' => 0, 'area_m2' => 23855.86, 'area_ha' => 2.39, 'total_trees' => 1324],
                            ['name' => 'P6', 'hass_trees' => 1029, 'fuerte_trees' => 168, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 21567.57, 'area_ha' => 2.16, 'total_trees' => 1197],
                            ['name' => 'P7', 'hass_trees' => 1134, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22702.70, 'area_ha' => 2.27, 'total_trees' => 1260],
                            ['name' => 'P8', 'hass_trees' => 975, 'fuerte_trees' => 189, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 20972.97, 'area_ha' => 2.10, 'total_trees' => 1164],
                            ['name' => 'P9', 'hass_trees' => 619, 'fuerte_trees' => 72, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 12450.45, 'area_ha' => 1.25, 'total_trees' => 691],
                        ],
                    ],
                    'S3' => [
                        'area_m2' => 214234.23,
                        'area_ha' => 21.42,
                        'total_trees' => 11890,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 1119, 'fuerte_trees' => 154, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22936.94, 'area_ha' => 2.29, 'total_trees' => 1273],
                            ['name' => 'P2', 'hass_trees' => 1208, 'fuerte_trees' => 132, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 24144.14, 'area_ha' => 2.41, 'total_trees' => 1340],
                            ['name' => 'P3', 'hass_trees' => 1365, 'fuerte_trees' => 176, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 27765.77, 'area_ha' => 2.78, 'total_trees' => 1541],
                            ['name' => 'P4', 'hass_trees' => 1141, 'fuerte_trees' => 132, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22936.94, 'area_ha' => 2.29, 'total_trees' => 1273],
                            ['name' => 'P5', 'hass_trees' => 1119, 'fuerte_trees' => 154, 'lambhass_trees' => 67, 'zutano_trees' => 0, 'area_m2' => 24144.14, 'area_ha' => 2.41, 'total_trees' => 1340],
                            ['name' => 'P6', 'hass_trees' => 1208, 'fuerte_trees' => 132, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 24144.14, 'area_ha' => 2.41, 'total_trees' => 1340],
                            ['name' => 'P7', 'hass_trees' => 1119, 'fuerte_trees' => 154, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22936.94, 'area_ha' => 2.29, 'total_trees' => 1273],
                            ['name' => 'P8', 'hass_trees' => 1208, 'fuerte_trees' => 132, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 24144.14, 'area_ha' => 2.41, 'total_trees' => 1340],
                            ['name' => 'P9', 'hass_trees' => 1034, 'fuerte_trees' => 136, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 21081.08, 'area_ha' => 2.11, 'total_trees' => 1170],
                        ],
                    ],
                    'S4' => [
                        'area_m2' => 187621.62,
                        'area_ha' => 18.76,
                        'total_trees' => 10413,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 1050, 'fuerte_trees' => 146, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 21549.55, 'area_ha' => 2.15, 'total_trees' => 1196],
                            ['name' => 'P2', 'hass_trees' => 1126, 'fuerte_trees' => 114, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22342.34, 'area_ha' => 2.23, 'total_trees' => 1240],
                            ['name' => 'P3', 'hass_trees' => 1274, 'fuerte_trees' => 152, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 25693.69, 'area_ha' => 2.57, 'total_trees' => 1426],
                            ['name' => 'P4', 'hass_trees' => 1064, 'fuerte_trees' => 114, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 21225.23, 'area_ha' => 2.12, 'total_trees' => 1178],
                            ['name' => 'P5', 'hass_trees' => 1045, 'fuerte_trees' => 133, 'lambhass_trees' => 62, 'zutano_trees' => 0, 'area_m2' => 22342.34, 'area_ha' => 2.23, 'total_trees' => 1240],
                            ['name' => 'P6', 'hass_trees' => 1126, 'fuerte_trees' => 114, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22342.34, 'area_ha' => 2.23, 'total_trees' => 1240],
                            ['name' => 'P7', 'hass_trees' => 1045, 'fuerte_trees' => 133, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 21225.23, 'area_ha' => 2.12, 'total_trees' => 1178],
                            ['name' => 'P8', 'hass_trees' => 1126, 'fuerte_trees' => 114, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22342.34, 'area_ha' => 2.23, 'total_trees' => 1240],
                            ['name' => 'P9', 'hass_trees' => 416, 'fuerte_trees' => 59, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 8558.56, 'area_ha' => 0.86, 'total_trees' => 475],
                        ],
                    ],
                ],
            ],
            'B2' => [
                'area_m2' => 786841.57,
                'area_ha' => 78.68,
                'sectors' => [
                    'S1' => [
                        'area_m2' => 135135.14,
                        'area_ha' => 13.51,
                        'total_trees' => 7500,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 601, 'fuerte_trees' => 108, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 12774.77, 'area_ha' => 1.28, 'total_trees' => 709],
                            ['name' => 'P2', 'hass_trees' => 864, 'fuerte_trees' => 96, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 17297.30, 'area_ha' => 1.73, 'total_trees' => 960],
                            ['name' => 'P3', 'hass_trees' => 848, 'fuerte_trees' => 112, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 17297.30, 'area_ha' => 1.73, 'total_trees' => 960],
                            ['name' => 'P4', 'hass_trees' => 944, 'fuerte_trees' => 112, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 19027.03, 'area_ha' => 1.90, 'total_trees' => 1056],
                            ['name' => 'P5', 'hass_trees' => 800, 'fuerte_trees' => 112, 'lambhass_trees' => 48, 'zutano_trees' => 0, 'area_m2' => 17297.30, 'area_ha' => 1.73, 'total_trees' => 960],
                            ['name' => 'P6', 'hass_trees' => 864, 'fuerte_trees' => 96, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 17297.30, 'area_ha' => 1.73, 'total_trees' => 960],
                            ['name' => 'P7', 'hass_trees' => 816, 'fuerte_trees' => 96, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 16432.43, 'area_ha' => 1.64, 'total_trees' => 912],
                            ['name' => 'P8', 'hass_trees' => 794, 'fuerte_trees' => 189, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 17711.71, 'area_ha' => 1.77, 'total_trees' => 983],
                        ],
                    ],
                    'S2' => [
                        'area_m2' => 142972.97,
                        'area_ha' => 14.30,
                        'total_trees' => 7935,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 600, 'fuerte_trees' => 74, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 12144.14, 'area_ha' => 1.21, 'total_trees' => 674],
                            ['name' => 'P2', 'hass_trees' => 992, 'fuerte_trees' => 108, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 19819.82, 'area_ha' => 1.98, 'total_trees' => 1100],
                            ['name' => 'P3', 'hass_trees' => 974, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 19819.82, 'area_ha' => 1.98, 'total_trees' => 1100],
                            ['name' => 'P4', 'hass_trees' => 1084, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 21801.80, 'area_ha' => 2.18, 'total_trees' => 1210],
                            ['name' => 'P5', 'hass_trees' => 919, 'fuerte_trees' => 126, 'lambhass_trees' => 55, 'zutano_trees' => 0, 'area_m2' => 19819.82, 'area_ha' => 1.98, 'total_trees' => 1100],
                            ['name' => 'P6', 'hass_trees' => 937, 'fuerte_trees' => 108, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 18828.83, 'area_ha' => 1.88, 'total_trees' => 1045],
                            ['name' => 'P7', 'hass_trees' => 882, 'fuerte_trees' => 108, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 17837.84, 'area_ha' => 1.78, 'total_trees' => 990],
                            ['name' => 'P8', 'hass_trees' => 636, 'fuerte_trees' => 80, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 12900.90, 'area_ha' => 1.29, 'total_trees' => 716],
                        ],
                    ],
                    'S3' => [
                        'area_m2' => 108720.72,
                        'area_ha' => 10.87,
                        'total_trees' => 6034,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 1153, 'fuerte_trees' => 150, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23477.48, 'area_ha' => 2.35, 'total_trees' => 1303],
                            ['name' => 'P2', 'hass_trees' => 975, 'fuerte_trees' => 108, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 19513.51, 'area_ha' => 1.95, 'total_trees' => 1083],
                            ['name' => 'P3', 'hass_trees' => 1014, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 20540.54, 'area_ha' => 2.05, 'total_trees' => 1140],
                            ['name' => 'P4', 'hass_trees' => 1128, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 22594.59, 'area_ha' => 2.26, 'total_trees' => 1254],
                            ['name' => 'P5', 'hass_trees' => 1071, 'fuerte_trees' => 126, 'lambhass_trees' => 57, 'zutano_trees' => 0, 'area_m2' => 22594.59, 'area_ha' => 2.26, 'total_trees' => 1254],
                            ['name' => 'P6', 'hass_trees' => 0, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 0, 'area_ha' => 2.35, 'total_trees' => 0],
                            ['name' => 'P7', 'hass_trees' => 0, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 0, 'area_ha' => 0, 'total_trees' => 0],
                        ],
                    ],
                    'S4' => [
                        'area_m2' => 129819.82,
                        'area_ha' => 12.98,
                        'total_trees' => 7205,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 844, 'fuerte_trees' => 104, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 17081.08, 'area_ha' => 1.71, 'total_trees' => 948],
                            ['name' => 'P2', 'hass_trees' => 1075, 'fuerte_trees' => 144, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 21963.96, 'area_ha' => 2.20, 'total_trees' => 1219],
                            ['name' => 'P3', 'hass_trees' => 1086, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 21837.84, 'area_ha' => 2.18, 'total_trees' => 1212],
                            ['name' => 'P4', 'hass_trees' => 1068, 'fuerte_trees' => 144, 'lambhass_trees' => 53, 'zutano_trees' => 0, 'area_m2' => 22792.79, 'area_ha' => 2.28, 'total_trees' => 1265],
                            ['name' => 'P5', 'hass_trees' => 881, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 18144.14, 'area_ha' => 1.81, 'total_trees' => 1007],
                            ['name' => 'P6', 'hass_trees' => 899, 'fuerte_trees' => 108, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 18144.14, 'area_ha' => 1.81, 'total_trees' => 1007],
                            ['name' => 'P7', 'hass_trees' => 477, 'fuerte_trees' => 70, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 9855.86, 'area_ha' => 0.99, 'total_trees' => 547],
                        ],
                    ],
                    'S5' => [
                        'area_m2' => 137445.86,
                        'area_ha' => 13.74,
                        'total_trees' => 6771,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 262, 'fuerte_trees' => 30, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 5473.19, 'area_ha' => 0.55, 'total_trees' => 292],
                            ['name' => 'P2', 'hass_trees' => 1058, 'fuerte_trees' => 108, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23056.34, 'area_ha' => 2.31, 'total_trees' => 1166],
                            ['name' => 'P3', 'hass_trees' => 1075, 'fuerte_trees' => 144, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1219],
                            ['name' => 'P4', 'hass_trees' => 1068, 'fuerte_trees' => 144, 'lambhass_trees' => 54, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1266],
                            ['name' => 'P5', 'hass_trees' => 1086, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1212],
                            ['name' => 'P6', 'hass_trees' => 881, 'fuerte_trees' => 126, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1007],
                            ['name' => 'P7', 'hass_trees' => 528, 'fuerte_trees' => 81, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 16436.33, 'area_ha' => 1.64, 'total_trees' => 609],
                        ],
                    ],
                    'S6' => [
                        'area_m2' => 132747.06,
                        'area_ha' => 13.27,
                        'total_trees' => 7001,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 893, 'fuerte_trees' => 117, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 17147.06, 'area_ha' => 1.71, 'total_trees' => 1010],
                            ['name' => 'P2', 'hass_trees' => 1116, 'fuerte_trees' => 72, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1188],
                            ['name' => 'P3', 'hass_trees' => 1152, 'fuerte_trees' => 144, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1296],
                            ['name' => 'P4', 'hass_trees' => 1062, 'fuerte_trees' => 126, 'lambhass_trees' => 55, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1243],
                            ['name' => 'P5', 'hass_trees' => 1152, 'fuerte_trees' => 144, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1296],
                            ['name' => 'P6', 'hass_trees' => 855, 'fuerte_trees' => 113, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 968],
                        ],
                    ],
                ],
            ],
            'B3' => [
                'area_m2' => 846775.13,
                'area_ha' => 84.68,
                'sectors' => [
                    'S1' => [
                        'area_m2' => 99733.51,
                        'area_ha' => 9.97,
                        'total_trees' => 5262,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 1030, 'fuerte_trees' => 120, 'lambhass_trees' => 2, 'zutano_trees' => 1, 'area_m2' => 13794.87, 'area_ha' => 2.07, 'total_trees' => 1153],
                            ['name' => 'P2', 'hass_trees' => 630, 'fuerte_trees' => 53, 'lambhass_trees' => 1, 'zutano_trees' => 0, 'area_m2' => 17069.17, 'area_ha' => 1.71, 'total_trees' => 684],
                            ['name' => 'P3', 'hass_trees' => 687, 'fuerte_trees' => 91, 'lambhass_trees' => 2, 'zutano_trees' => 0, 'area_m2' => 16223.54, 'area_ha' => 1.62, 'total_trees' => 780],
                            ['name' => 'P4', 'hass_trees' => 732, 'fuerte_trees' => 93, 'lambhass_trees' => 33, 'zutano_trees' => 0, 'area_m2' => 16230.45, 'area_ha' => 1.62, 'total_trees' => 858],
                            ['name' => 'P5', 'hass_trees' => 726, 'fuerte_trees' => 91, 'lambhass_trees' => 2, 'zutano_trees' => 0, 'area_m2' => 16527.24, 'area_ha' => 1.65, 'total_trees' => 819],
                            ['name' => 'P6', 'hass_trees' => 850, 'fuerte_trees' => 117, 'lambhass_trees' => 1, 'zutano_trees' => 0, 'area_m2' => 19888.24, 'area_ha' => 1.99, 'total_trees' => 968],
                        ],
                    ],
                    'S2' => [
                        'area_m2' => 99326.26,
                        'area_ha' => 9.93,
                        'total_trees' => 5695,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 1289, 'fuerte_trees' => 0, 'lambhass_trees' => 1, 'zutano_trees' => 144, 'area_m2' => 20310.98, 'area_ha' => 2.03, 'total_trees' => 1434],
                            ['name' => 'P2', 'hass_trees' => 777, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 84, 'area_m2' => 17090.87, 'area_ha' => 1.71, 'total_trees' => 861],
                            ['name' => 'P3', 'hass_trees' => 707, 'fuerte_trees' => 35, 'lambhass_trees' => 37, 'zutano_trees' => 0, 'area_m2' => 16230.41, 'area_ha' => 1.62, 'total_trees' => 779],
                            ['name' => 'P4', 'hass_trees' => 809, 'fuerte_trees' => 0, 'lambhass_trees' => 38, 'zutano_trees' => 96, 'area_m2' => 16230.44, 'area_ha' => 1.62, 'total_trees' => 943],
                            ['name' => 'P5', 'hass_trees' => 777, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 72, 'area_m2' => 16506.35, 'area_ha' => 1.65, 'total_trees' => 849],
                            ['name' => 'P6', 'hass_trees' => 754, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 75, 'area_m2' => 12957.21, 'area_ha' => 1.30, 'total_trees' => 829],
                        ],
                    ],
                    'S3' => [
                        'area_m2' => 122396.93,
                        'area_ha' => 12.24,
                        'total_trees' => 6977,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 540, 'fuerte_trees' => 60, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 6797.09, 'area_ha' => 0.68, 'total_trees' => 600],
                            ['name' => 'P2', 'hass_trees' => 1167, 'fuerte_trees' => 144, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23119.99, 'area_ha' => 2.31, 'total_trees' => 1311],
                            ['name' => 'P3', 'hass_trees' => 1166, 'fuerte_trees' => 144, 'lambhass_trees' => 52, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1362],
                            ['name' => 'P4', 'hass_trees' => 1167, 'fuerte_trees' => 144, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23119.98, 'area_ha' => 2.31, 'total_trees' => 1311],
                            ['name' => 'P5', 'hass_trees' => 1167, 'fuerte_trees' => 144, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23119.95, 'area_ha' => 2.31, 'total_trees' => 1311],
                            ['name' => 'P6', 'hass_trees' => 974, 'fuerte_trees' => 108, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23119.92, 'area_ha' => 2.31, 'total_trees' => 1082],
                        ],
                    ],
                    'S4' => [
                        'area_m2' => 140713.70,
                        'area_ha' => 14.07,
                        'total_trees' => 7505,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 952, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 117, 'area_m2' => 16507.64, 'area_ha' => 1.65, 'total_trees' => 1069],
                            ['name' => 'P2', 'hass_trees' => 1113, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 119, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1232],
                            ['name' => 'P3', 'hass_trees' => 1096, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 153, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1249],
                            ['name' => 'P4', 'hass_trees' => 1152, 'fuerte_trees' => 0, 'lambhass_trees' => 54, 'zutano_trees' => 136, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1342],
                            ['name' => 'P5', 'hass_trees' => 1208, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 114, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1322],
                            ['name' => 'P6', 'hass_trees' => 1001, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 114, 'area_m2' => 22673.49, 'area_ha' => 2.27, 'total_trees' => 1115],
                            ['name' => 'P7', 'hass_trees' => 150, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 26, 'area_m2' => 9052.57, 'area_ha' => 0.91, 'total_trees' => 176],
                        ],
                    ],
                    'S5' => [
                        'area_m2' => 137445.86,
                        'area_ha' => 13.74,
                        'total_trees' => 7559,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 493, 'fuerte_trees' => 62, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 5473.19, 'area_ha' => 0.55, 'total_trees' => 555],
                            ['name' => 'P2', 'hass_trees' => 1121, 'fuerte_trees' => 133, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23056.34, 'area_ha' => 2.31, 'total_trees' => 1254],
                            ['name' => 'P3', 'hass_trees' => 1178, 'fuerte_trees' => 133, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1311],
                            ['name' => 'P4', 'hass_trees' => 1121, 'fuerte_trees' => 133, 'lambhass_trees' => 57, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1311],
                            ['name' => 'P5', 'hass_trees' => 1167, 'fuerte_trees' => 152, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1319],
                            ['name' => 'P6', 'hass_trees' => 1128, 'fuerte_trees' => 133, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1261],
                            ['name' => 'P7', 'hass_trees' => 476, 'fuerte_trees' => 72, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 16436.33, 'area_ha' => 1.64, 'total_trees' => 548],
                        ],
                    ],
                    'S6' => [
                        'area_m2' => 156055.89,
                        'area_ha' => 15.61,
                        'total_trees' => 7686,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 783, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 95, 'area_m2' => 17147.06, 'area_ha' => 1.71, 'total_trees' => 878],
                            ['name' => 'P2', 'hass_trees' => 1076, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 112, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1188],
                            ['name' => 'P3', 'hass_trees' => 1130, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 112, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1242],
                            ['name' => 'P4', 'hass_trees' => 1114, 'fuerte_trees' => 0, 'lambhass_trees' => 54, 'zutano_trees' => 128, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1296],
                            ['name' => 'P5', 'hass_trees' => 1114, 'fuerte_trees' => 0, 'lambhass_trees' => 0, 'zutano_trees' => 112, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1226],
                            ['name' => 'P6', 'hass_trees' => 1188, 'fuerte_trees' => 192, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23120.00, 'area_ha' => 2.31, 'total_trees' => 1380],
                            ['name' => 'P7', 'hass_trees' => 432, 'fuerte_trees' => 44, 'lambhass_trees' => 0, 'zutano_trees' => 0, 'area_m2' => 23308.83, 'area_ha' => 2.33, 'total_trees' => 476],
                        ],
                    ],
                    'S7' => [
                        'area_m2' => 91102.98,
                        'area_ha' => 9.11,
                        'total_trees' => 5273,
                        'spacing' => '6*3',
                        'parcelles' => [
                            ['name' => 'P1', 'hass_trees' => 190, 'fuerte_trees' => 63, 'lambhass_trees' => 0, 'zutano_trees' => 331, 'area_m2' => 12913.42, 'area_ha' => 1.29, 'total_trees' => 584],
                            ['name' => 'P2', 'hass_trees' => 0, 'fuerte_trees' => 65, 'lambhass_trees' => 0, 'zutano_trees' => 543, 'area_m2' => 12268.46, 'area_ha' => 1.23, 'total_trees' => 608],
                            ['name' => 'P3', 'hass_trees' => 0, 'fuerte_trees' => 71, 'lambhass_trees' => 0, 'zutano_trees' => 885, 'area_m2' => 15469.14, 'area_ha' => 1.55, 'total_trees' => 956],
                            ['name' => 'P4', 'hass_trees' => 0, 'fuerte_trees' => 124, 'lambhass_trees' => 0, 'zutano_trees' => 1012, 'area_m2' => 18536.21, 'area_ha' => 1.85, 'total_trees' => 1136],
                            ['name' => 'P5', 'hass_trees' => 0, 'fuerte_trees' => 104, 'lambhass_trees' => 0, 'zutano_trees' => 1143, 'area_m2' => 21603.28, 'area_ha' => 2.16, 'total_trees' => 1247],
                            ['name' => 'P6', 'hass_trees' => 145, 'fuerte_trees' => 63, 'lambhass_trees' => 0, 'zutano_trees' => 534, 'area_m2' => 10312.47, 'area_ha' => 1.03, 'total_trees' => 742],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($blocData as $blocName => $blocInfo) {
            // Create Bloc
            $bloc = Bloc::updateOrCreate(
                [
                    'farm_id' => $farm->id,
                    'name' => $blocName,
                ],
                [
                    'farm_id' => $farm->id,
                    'name' => $blocName,
                    'area_m2' => $blocInfo['area_m2'],
                    'area_ha' => $blocInfo['area_ha'],
                ]
            );

            // Create Sectors and Parcelles
            foreach ($blocInfo['sectors'] as $sectorName => $sectorInfo) {
                $sector = Sector::updateOrCreate(
                    [
                        'bloc_id' => $bloc->id,
                        'name' => $sectorName,
                    ],
                    [
                        'bloc_id' => $bloc->id,
                        'name' => $sectorName,
                        'area_m2' => $sectorInfo['area_m2'],
                        'area_ha' => $sectorInfo['area_ha'],
                        'total_trees' => $sectorInfo['total_trees'],
                        'spacing' => $sectorInfo['spacing'],
                    ]
                );

                // Create Parcelles
                foreach ($sectorInfo['parcelles'] as $parcelleData) {
                    Parcelle::updateOrCreate(
                        [
                            'bloc_id' => $bloc->id,
                            'sector_id' => $sector->id,
                            'name' => $parcelleData['name'],
                        ],
                        [
                            'bloc_id' => $bloc->id,
                            'sector_id' => $sector->id,
                            'name' => $parcelleData['name'],
                            'hass_trees' => $parcelleData['hass_trees'],
                            'fuerte_trees' => $parcelleData['fuerte_trees'],
                            'lambhass_trees' => $parcelleData['lambhass_trees'],
                            'zutano_trees' => $parcelleData['zutano_trees'],
                            'area_m2' => $parcelleData['area_m2'],
                            'area_ha' => $parcelleData['area_ha'],
                            'spacing' => $sectorInfo['spacing'],
                            'total_trees' => $parcelleData['total_trees'],
                        ]
                    );
                }
            }
        }

        foreach ([$persea, $agri, $hafila] as $ent) {
            // Operations are now shared at Farm level, no need to create per enterprise
        }


        // 3. Employees Data

        // --- PERSEALAND EMPLOYEES ---
        $perseaEmployees = [
            ['8','CHIKH','KHALID','G696271','B1',97.44,0],
            ['10','EL GARADI ','EL HOUSSINE','G292536','B1',97.44,0],
            ['17','EL-FAOUY ','OUTMANE','GM180174','B1',97.44,0],
            ['127','EL GARADI ','MOHAMMED','GG4096','B1',97.44,0],
            ['1','TAHRI','ABDELMOUNIM','GM79175','B1',97.44,0],
            ['2','BEJTIT','ABDELLAH','X281610','',97.44,0],
            ['41','EL MESSAOUDI ','NOURDINE','JC605433','',97.44,0],
            ['46','CHIKH','ABDELHAK','G269749','',97.44,0],
            ['47','CHOURI','AMINE','JC605168','',97.44,0],
            ['101','GUERRADI','EL MILOUDI','G272017','',97.44,0],
            ['120','CHEGDANI','ALLAL','G369423','',97.44,0],
            ['25','HRAIA','ABD-ELRAHIM','G545142','',97.44,0],
            ['66','LAFHAL','RACHID','G637616','',97.44,0],
            ['74','SLIKI','HASSAN','G699446','',97.44,0],
            ['80','DIOP','DAOUDA','AO3994678','',97.44,0],
            ['88','EL MAMOUN','LEKBIR','G253755','',97.44,0],
            ['45','CHEIKHOU','FAYE','AO3958084','',97.44,0],
            ['91','EL-GHRISSI','ABDENBI','GM105238','',97.44,0],
            ['93','EL-JOHRI','ABDERRAHIM','GM43336','',97.44,0],
            ['','LO','MBAYE','AO3896990','',97.44,0],
            ['99','GUERRADI','LARBI','G275768','',97.44,0],
            ['100','GUERRADI','TAIBI','G298190','',97.44,0],
            ['105','JBILOU','MOHAMED','GM116168','',97.44,0],
            ['109','LAFHAL','MOKHTAR','G23305','',97.44,0],
            ['117','SECK','ABLAYE','AO3021740','',97.44,0],
        ];

        foreach ($perseaEmployees as $data) {
            $this->createEmp($persea->id, 'P', $data);
        }

        // --- AGRI INTERIM EMPLOYEES ---
        $agriEmployees = [
            ['39','AHANNI ','AZIZA','G546734','B1',97.44,0],
            ['5','ASSAL','DRISS','G733931','B1',97.44,0],
            ['9','CHIKH','ZINEB','G534915','B1',97.44,0],
            ['82','EL GARADI','FATNA','G634398','B1',97.44,0],
            ['11','EL GARADI','NAJAT','G715005','B1',97.44,0],
            ['12','EL GARADI','MOSTAFA','G530908','B1',97.44,13.27],
            ['92','ELGUERADI','MOHAMMED','G374544','',97.44,0],
            ['15','EL MAMOUNE','BOUAZZA','G649472','B1',97.44,0],
            ['20','GUERADI','YAMNA','G282994','B1',97.44,0],
            ['21','GUERADI','IMANE','G675222','B1',97.44,0],
            ['22','GUERADI','KALTHOUM','G715000','B1',97.44,0],
            ['23','GUERRADI','MAHJOUBA','AE256355','B1',97.44,0],
            ['26','KORCHI','BOUCHRA','G374204','B1',97.44,0],
            ['27','KORCHI','KHADIJA','G500742','B1',97.44,0],
            ['28','KORCHI','HADDOUM','G535675','B1',97.44,0],
            ['29','KORCHI','MAHJOUBA','G540158','B1',97.44,0],
            ['108','KORCHI','YAMNA','G374207','B1',97.44,0],
            ['31','MAMOUN','SMAIL','G429691','B1',97.44,0],
            ['33','MAMOUN','YOUNES','GG18887','B1',97.44,0],
            ['35','OUAHEB','AICHA','G421241','B1',97.44,0],
            ['72','RAGRAGY','EL MAMOUNE','GG14284','B1',97.44,0],
            ['119','ZABTEI','NADIA','G425361','B1',97.44,0],
            ['2','EL KORCHI ','HALIMA','G730495','',97.44,0],
            ['5','QACHLAT','FATIMA','G534673','',97.44,0],
            ['6','QACHLAT','KAOUTAR','G739210','',97.44,0],
            ['18','EL GARADI ','OUIAM','GG23898','',97.44,0],
            ['38','ABBA','JAMILA','GY17511','B2',97.44,0],
            ['40','AHNI','SBAIA','G374212','B2',97.44,0],
            ['41','BOUKHADAMI','ZAHRA','AB104997','B2',97.44,0],
            ['42','BOUZBIBA','MIRA','G559220','B2',97.44,0],
            ['48','DERROUSSI','DRISS','GN208409','B2',97.44,13.27],
            ['51','EL HAIRECH','SOMIA','G647912','B2',97.44,0],
            ['13','EL KHALLOUKI','AMINA','G657532','B2',97.44,0],
            ['53','EL KORCHI','MOHAMMED','G337067','B2',97.44,3.27],
            ['54','EL KORCHI','MINA','G537718','B2',97.44,0],
            ['55','EL MAMOUN','MOHAMMED ','G763291','B2',97.44,0],
            ['56','EL MAMOUN','HABIBA','G545043','B2',97.44,0],
            ['63','KORCHI','HAMOUCHA','G502551','B2',97.44,0],
            ['65','LABIDI','KALTOUM','G424091','B2',97.44,0],
            ['67','MAMOUN','ABDELALI','G324832','B2',97.44,0],
            ['68','MAMOUN','KHALYD','GG28869','B2',97.44,0],
            ['69','MAMOUN','ABDELAZIZ','G374236','B2',97.44,0],
            ['71','MAMOUN','LATIFA','G503689','B2',97.44,0],
            ['73','RTIT','HAFIDA','G639187','B2',97.44,0],
            ['75','ZBITI','HANANE','G545176','B2',97.44,0],
            ['81','DRAIDI','NAJAT','G733957','B3',97.44,0],
            ['84','EL GARADI','ZAHIA','G524744','B3',97.44,0],
            ['89','EL MAMOUNE','AICHA','G531652','B3',97.44,0],
            ['90','EL-BOUANANI','FATIMA','D790161','B3',97.44,0],
            ['59','ES-SAHLI','MARIEM','G681122','B3',97.44,0],
            ['60','FANOUG','TAOUFIK','GN201671','B3',97.44,3.27],
            ['95','ELKORCHI','AMINA','G531177','B3',97.44,0],
            ['96','ELMAMOUNE','LARBI','G421993','B3',97.44,0],
            ['19','ESSIFI','RKIA','G693581','B3',97.44,0],
            ['97','FHAIL','FATIMA','AB525101','B3',97.44,0],
            ['57','EL MESSAOUDI','HAMID','JC664857','B3',97.44,0],
            ['102','GUERRADI','KHADIJA','G374229','B3',97.44,3.27],
            ['111','LAQHAL','JAOUAD','G281895','B3',97.44,53.27],
            ['112','LAQHAL','YOUSSEF','GN228588','B3',97.44,0],
            ['70','MAMOUN','FATNA','G631731','B3',97.44,0],
            ['114','MIZOUQAT','LOUBNA','G258391','B3',97.44,0],
            ['86','EL GHRISSI','SAID','GM76526','B3',97.44,3.27],
            ['116','SAHRAOUY','ZHOR','GA165026','B3',97.44,0],
            ['102','QACHLAT','HICHAM','G791086','B3',97.44,0],
            ['95','KORCHI','BOUAZZA','GG4725','B3',97.44,0],
            ['86','HRAYA','FARAH','GG7873','B3',97.44,0],
            ['85','MAMOUN','SABAH','GG19109','B3',97.44,0],
            ['83','KORCHI','SANAA','G750656','B3',97.44,0],
            ['94','GUERADI','OTHMANE','','B3',97.44,0],
        ];

        foreach ($agriEmployees as $data) {
            $this->createEmp($agri->id, 'A', $data);
        }

        // --- HAFILATY EMPLOYEES ---
        $hafilatyEmployees = [
            ['1','GUERRADI','SOUAD','G672425','B1',90.87,0],
            ['3','MAMOUNE','KHADIJA','G723750','B1',90.87,0],
            ['4','ELGUERADI','FATIMA ZAHRA','GG23634','',90.87,0],
            ['7','LAFHAL','FATIMAEZZAHRA','GG3148','B1',90.87,0],
            ['8','KHALOKHY','ABDEALI','','B1',90.87,0],
            ['9','KHALOKHY','ZILALY','','B1',90.87,0],
            ['10','HARBAL','MILOUDI','','B1',90.87,0],
            ['11','AOUDA','MOHAMMED','GY46829','B1',90.87,0],
            ['12','EL HAMOY','JAWAD','','B1',90.87,0],
            ['13','GNES','ZAKARIA','','B1',90.87,0],
            ['14','DERBALI','RIYAHI','','B1',90.87,0],
            ['15','KORCHI','KHADIJA','','B1',90.87,0],
            ['16','EL HAIRECH','RACHIDA','','B1',90.87,0],
            ['17','GUARADI','MILOUDA','','B1',90.87,0],
            ['18','EL GARADI','OUIAM','','B1',90.87,0],
            ['19','EZ-ZIHER','DRISSIA','GA150682','B1',90.87,0],
            ['20','HENI','SAADIA','','B1',90.87,0],
            ['21','RTIT','FATIMA','G649465','B1',90.87,0],
            ['22','EL JAAFRY','AYMAN','GA270228','B1',90.87,0],
            ['23','BENAISSA','ENNAJJ','GN177356','B1',90.87,0],
            ['24','KHALOKHI','MOHAMMED','','B1',90.87,0],
            ['25','HMINE','ZOUHAIR','GA240950','B1',90.87,0],
            ['26','HRAIA','RACHIDA','G503503','B1',90.87,0],
            ['27','HRAIA','FATIMA','G374238','B1',90.87,0],
            ['28','LAFHAL','NADIA','G704134','B1',90.87,0],
            ['29','LAHMAR','SANAA','G611325','B1',90.87,0],
            ['30','EL HAIMER','IBRAHIMA','GA258123','B1',90.87,0],
            ['31','EL FAKIR','BILAL','GA266847','B1',90.87,0],
            ['32','EL OUAHDAN','BILAL','GA251160','B1',90.87,0],
            ['33','ZARI','CHAIMAE','G798374','B1',90.87,0],
            ['34','FHAIL','JEMAA','G500724','B1',90.87,0],
            ['35','LACHHAB','ILYASS','','B1',90.87,0],
            ['36','AGORAM','FATIMAZZAHRA','G703441','B1',90.87,0],
            ['37','LAKHDAR','MAROUAN','GA249233','B1',90.87,0],
            ['38','ELRAHAMROUSSI','MOHAMMED','','B1',90.87,0],
            ['39','LAGRAIN','HAKIM','GA267429','B1',90.87,0],
            ['40','ELMASSAK','MOUAD','GY32161','B1',90.87,0],
            ['41','EL HILLALI','OUSSAMA','GG40483','B1',90.87,0],
            ['42','SOROUR','ABDESSAMAD','GY38086','B1',90.87,0],
            ['43','ZANFOURI','HATIM','GY37400','B1',90.87,0],
            ['44','LEKTOUI','DRISSIA','','B1',90.87,0],
            ['45','HAMOUMY','ABDEL SALAM','','B1',90.87,0],
            ['46','EL GHARDMANI','OUSSAMA','GA265458','B1',90.87,0],
            ['47','HMINE','BILAL','','B1',90.87,0],
            ['48','KHALKHY','BADRE','','B1',90.87,0],
            ['49','MNIOULAT','GHIZLANE','GA195576','B1',90.87,0],
            ['50','EL KADDOURI','AHMED','PB292174','B1',94.14,0],
            ['51','EL MAZOURY','NOUREDDINE','JC603943','B1',94.14,0],
            ['52','SLIKI','DRISS','G501031','B1',94.14,0],
            ['53','BOUKTEF','FATNA','G503524','B2',90.87,0],
            ['54','ZABTE','FATNA','G647659','B2',90.87,0],
            ['55','LAARAFJI','FATNA','G334029','B2',90.87,0],
            ['56','LATIFI','SAADIA','G715820','B2',90.87,0],
            ['57','MIDY','ZAHRA','PB172836','B2',90.87,0],
            ['58','ELKORCHI','FATIHA','G374223','B2',90.87,0],
            ['59','AHAJAM','AHMED','G457850','B2',90.87,0],
            ['60','ZOUIA','NADIA','','B2',90.87,0],
            ['61','HADIR','CHARAFFEDDINE','','B2',90.87,0],
            ['62','KINNI','SOUHAIB','','B2',90.87,0],
            ['63','KANNI','ABD RAHHIM','','B2',90.87,0],
            ['64','DOUMI','ANNAS','','B2',90.87,0],
            ['65','EL AZHARI','BADRE','','B2',90.87,0],
            ['66','BENBARAKA','AYOUB','GI23503','B2',90.87,0],
            ['67','MOUJOUD','ADAM','GA273571','B2',90.87,0],
            ['68','EL OUAFY','MOHAMED','GI18604','B2',90.87,0],
            ['69','MOUIMI','FATIMA-EZZARA','G623341','B2',90.87,0],
            ['70','EL BAYE','OUIALI','GG40504','B2',90.87,0],
            ['71','GUANSI','ABDALLAH','GG6073','B2',90.87,0],
            ['72','SALHI','ADAM','GG4160','B2',90.87,0],
            ['73','JAMAE','ABDAESLAM','GA241436','B2',90.87,0],
            ['74','KIHEL','HLIMA','G28511','B2',90.87,0],
            ['75','LAARAIBI','OUSSAMA','GA272829','B2',90.87,0],
            ['77','RAHALI','ABDELALI','GA2544811','B2',90.87,0],
            ['81','ELHOURCH','BAHIJA','G673843','B2',90.87,0],
            ['82','GUERADI','AZIZA','G631694','B2',90.87,0],
            ['84','RTIT','FATNA','G524950','B3',90.87,0],
            ['88','EL BOUNY','AICHA','AB155813','B3',90.87,0],
            ['89','BOUZBIBA','ILHAM','G544873','B3',90.87,0],
            ['90','ELHAIRECH','AZIZA','G502183','B3',90.87,0],
            ['91','SADAOUI','ZOHRA','G534379','B3',90.87,0],
            ['92','KORCHI','FATNA','','B3',90.87,0],
            ['93','KABOUCH','AICHA','G718303','B3',90.87,0],
            ['94','GUERADI','OTHMANE','','B3',90.87,0],
            ['95','KORCHI','BOUAZZA','','B3',90.87,0],
            ['96','BENFADELI','FATIMA','','B3',90.87,0],
            ['97','GUERADI','AICHA','G762782','B3',90.87,0],
            ['98','BERIGUI','ZHOUR','G678385','B3',90.87,0],
            ['99','GUERADI','KHADIJA','G538780','B3',90.87,0],
            ['100','ESSAHAROUY','FATIMA','','B3',90.87,0],
            ['101','EL GARADI','OUIJDANE','','B3',90.87,0],
            ['103','EL HAMZI','FOUZIA','JM57165','B3',90.87,0],
            ['104','AGUICH','DALAL','G762985','B3',90.87,0],
            ['105','HAOUMA','RAHMA','G757103','B3',90.87,0],
            ['','FHAIL','ABDERAHIM','GG18825','',0,0],
            ['106','EL GARADI','KHADIJA','G374205','B3',90.87,0],
            ['','LO','MBAYE','A03896990','B3',94.14,0],
        ];

        foreach ($hafilatyEmployees as $data) {
            $this->createEmp($hafila->id, 'H', $data);
        }
    }

    private function createEmp($entId, $prefix, $data)
    {
        $num = $data[0] ?: rand(1000, 9999);
        $firstName = $data[2];
        $lastName = $data[1];
        $cin = $data[3] !== '' ? $data[3] : null;
        $blocName = $data[4];
        $brut = $data[5];
        $complement = $data[6] ?? 0;
        $ent = Enterprise::find($entId);

        Employee::create([
            'farm_id' => $ent->farm_id,
            'enterprise_id' => $entId,
            'matricule' => $prefix . '-' . $num . '-' . rand(1,999),
            'full_name' => $firstName . ' ' . $lastName,
            'cin' => $cin,
            'base_rate' => $brut,
            'complement' => $complement,
            'type' => $prefix === 'H' ? 'hafila' : 'persea',
        ]);

        if ($blocName) {
            Bloc::firstOrCreate(['name' => $blocName, 'farm_id' => $ent->farm_id]);
        }
    }
}
