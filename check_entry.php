<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$entry = App\Models\ManualStockEntry::find(17);
if ($entry) {
    echo "Entry ID 17 exists:\n";
    echo "Farm ID: " . $entry->farm_id . "\n";
    echo "Product ID: " . $entry->product_id . "\n";
    echo "Verified: " . ($entry->is_verified ? 'yes' : 'no') . "\n";
} else {
    echo "Entry ID 17 not found\n";
}
