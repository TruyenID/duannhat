<?php

/**
 * Quick script to create placeholder gallery images
 * Run: docker compose exec app php create-gallery-fixtures.php
 */
$dir = __DIR__.'/storage/app/gallery-fixtures';
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$colors = [
    ['bg' => [240, 240, 245], 'text' => [100, 100, 120], 'name' => 'Pho'],
    ['bg' => [255, 245, 235], 'text' => [180, 100, 60], 'name' => 'Banh Mi'],
    ['bg' => [235, 250, 240], 'text' => [60, 140, 80], 'name' => 'Bun'],
    ['bg' => [245, 235, 250], 'text' => [140, 80, 160], 'name' => 'Coffee'],
    ['bg' => [255, 250, 240], 'text' => [200, 150, 100], 'name' => 'Spring Roll'],
    ['bg' => [240, 245, 255], 'text' => [80, 100, 180], 'name' => 'Smoothie'],
    ['bg' => [250, 240, 240], 'text' => [180, 80, 80], 'name' => 'Noodle'],
    ['bg' => [245, 255, 245], 'text' => [100, 160, 100], 'name' => 'Salad'],
];

for ($i = 1; $i <= 8; $i++) {
    $img = imagecreatetruecolor(800, 600);
    $color = $colors[$i - 1];

    $bg = imagecolorallocate($img, ...$color['bg']);
    $textColor = imagecolorallocate($img, ...$color['text']);

    imagefilledrectangle($img, 0, 0, 800, 600, $bg);

    $text = $color['name'];
    $font = 5; // built-in font
    $x = (800 - strlen($text) * imagefontwidth($font)) / 2;
    $y = (600 - imagefontheight($font)) / 2;

    imagestring($img, $font, (int) $x, (int) $y, $text, $textColor);

    $filename = "$dir/product-$i.jpg";
    imagejpeg($img, $filename, 85);
    imagedestroy($img);

    echo "Created: $filename\n";
}

echo "\nDone! Created 8 placeholder images.\n";
echo "Now run: docker compose exec app php artisan db:seed --class=ProductGallerySeeder\n";
