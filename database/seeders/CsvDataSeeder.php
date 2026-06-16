<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Enterprise;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CsvDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Farm
        $farm = \App\Models\Farm::create([
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
                    'name' => $op['OPERATIONS'] . ' (' . $op['ABREVIATION'] . ')',
                    'farm_id' => $farm->id
                ]);
            }
        }

        // Create shared Blocs for the Farm
        foreach (['B1', 'B2', 'B3'] as $blocName) {
            Bloc::firstOrCreate([
                'name' => $blocName,
                'farm_id' => $farm->id,
            ]);
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
        $cin = $data[3];
        $blocName = $data[4];
        $brut = $data[5];
        $complement = $data[6] ?? 0;

        Employee::create([
            'enterprise_id' => $entId,
            'matricule' => $prefix . '-' . $num . '-' . rand(1,999),
            'full_name' => $firstName . ' ' . $lastName,
            'cin' => $cin,
            'base_rate' => $brut,
            'complement' => $complement,
            'type' => $prefix === 'H' ? 'hafila' : 'persea',
        ]);

        if ($blocName) {
            $ent = Enterprise::find($entId);
            Bloc::firstOrCreate(['name' => $blocName, 'farm_id' => $ent->farm_id]);
        }
    }
}
