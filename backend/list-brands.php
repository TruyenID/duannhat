<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Brand;
use Illuminate\Contracts\Console\Kernel;

echo "All brands:\n\n";

$brands = Brand::all(['id', 'name', 'slug']);

foreach ($brands as $brand) {
    echo "Slug: {$brand->slug}\n";
    echo "Name: {$brand->name}\n";
    echo "ID: {$brand->id}\n";
    echo "---\n";
}
