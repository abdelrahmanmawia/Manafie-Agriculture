<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Bloc;

$data = [
    'B1' => ['area_ha'=>13.57,'area_m2'=>135700,'spacing'=>'6x6','hass_trees'=>1056,'fuerte_trees'=>242,'lambhass_trees'=>60,'zutano_trees'=>40,'total_trees'=>1398],
    'B2' => ['area_ha'=>11.22,'area_m2'=>112200,'spacing'=>'6x6','hass_trees'=>980,'fuerte_trees'=>180,'lambhass_trees'=>50,'zutano_trees'=>30,'total_trees'=>1240],
    'B3' => ['area_ha'=>10.85,'area_m2'=>108500,'spacing'=>'6x6','hass_trees'=>890,'fuerte_trees'=>210,'lambhass_trees'=>45,'zutano_trees'=>25,'total_trees'=>1170],
];

foreach ($data as $name => $fields) {
    $count = Bloc::where('name', $name)->whereNull('parent_bloc')->update($fields);
    echo "$name: $count row(s) updated\n";
}
echo "Done.\n";
