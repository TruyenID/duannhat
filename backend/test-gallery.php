<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;

$product = Product::with('gallery')->first();

echo "Product: {$product->name}\n";
echo 'Gallery count (eager): '.$product->gallery->count()."\n";
echo 'Gallery count (lazy): '.$product->gallery()->count()."\n";
echo 'Files count: '.$product->files()->count()."\n";
echo 'Files (gallery) count: '.$product->files()->where('collection', 'gallery')->count()."\n";

if ($product->gallery->isNotEmpty()) {
    echo "\nFirst gallery file:\n";
    $file = $product->gallery->first();
    echo "  ID: {$file->id}\n";
    echo "  Path: {$file->path}\n";
    echo "  URL: {$file->getUrl()}\n";
}
