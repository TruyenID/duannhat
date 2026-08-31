<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$storage = Storage::disk('s3');

// Minimal valid JPG (1x1 pixel)
$jpgBase64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCiABhw/9k=';

echo "Uploading placeholder images to MinIO (s3://tempo/gallery-fixtures/)...\n";

$storage->makeDirectory('gallery-fixtures');

foreach (range(1, 10) as $i) {
    $jpg = base64_decode($jpgBase64);
    $path = "gallery-fixtures/product-{$i}.jpg";
    $storage->put($path, $jpg);
    echo "✓ Uploaded: {$path}\n";
}

echo "\n✓ Done! Uploaded 10 placeholder images.\n";
echo "Now run: php artisan db:seed --class=ProductGallerySeeder\n";
