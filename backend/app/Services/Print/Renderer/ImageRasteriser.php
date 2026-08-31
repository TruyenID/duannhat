<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use InvalidArgumentException;

/**
 * #1957 mảnh B — turn an uploaded image into the bytes a thermal head prints.
 *
 * ## Why this lives on Cloud and not on the workstation
 *
 * The workstation never sees the PNG. Cloud rasterises once and ships the
 * packed 1-bit result, because two image DECODERS agreeing pixel-for-pixel is a
 * far larger promise than two ENCODERS agreeing on an eight-byte header — and
 * only the second can be pinned by a golden fixture. Letting Go decode PNGs
 * would open a new parity front (GD's scaling and threshold vs a Go library's)
 * for a decorative feature. Rasterising here makes both sides byte-identical BY
 * CONSTRUCTION, with nothing to police.
 *
 * The output feeds {@see Escpos::raster()} and Go's `escpos.Raster` unchanged:
 * packed MSB-first, each row padded to a whole byte — the layout the `GS v 0`
 * command itself uses, so nothing is repacked downstream.
 *
 * ## Threshold, not dithering — and that is a decision, not a shortcut
 *
 * Floyd–Steinberg looks better on photographs and worse on the thing this
 * actually prints: a shop logo, usually flat colour with small lettering.
 * Dithering turns 8-point text into grey mush on a 203 dpi head.
 *
 * It also costs reproducibility, which matters more here than it looks: the
 * design is content-addressed, so the SAME image must always yield the SAME
 * bytes or every render invents a new hash and the sync feed never settles.
 * A fixed threshold is deterministic; an error-diffusion kernel is
 * deterministic too but far more sensitive to a library upgrade changing a
 * rounding edge.
 *
 * ## Never upscale
 *
 * A logo narrower than the target is left at its own width. Stretching it adds
 * no detail, blurs the edges the threshold then hardens into jaggies, and eats
 * paper. Downscaling to fit is the only resize this performs.
 */
final class ImageRasteriser
{
    /**
     * Luminance at or below which a pixel is INK.
     *
     * 128 is the midpoint and the value every reference ESC/POS tool uses. It is
     * named rather than inlined because changing it changes every stored hash —
     * a migration, not a tweak.
     */
    private const INK_THRESHOLD = 128;

    /**
     * Rasterise an image into packed 1-bit rows.
     *
     * @param  string  $bytes  the uploaded file's contents (PNG/JPEG/GIF/WEBP — whatever GD reads)
     * @param  int  $maxWidthDots  hard ceiling in dots; the caller has already clamped it to
     *                             the paper's printable width (TR-22, `clampImages`)
     * @return array{width: int, height: int, data: string}
     */
    public function rasterise(string $bytes, int $maxWidthDots): array
    {
        if ($bytes === '') {
            throw new InvalidArgumentException('image: empty upload');
        }
        if ($maxWidthDots <= 0) {
            throw new InvalidArgumentException('image: max width must be positive');
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            // A hard reject, unlike a too-wide image. TR-22 clamps what the
            // system can fix; a file it cannot decode is not one of those, and
            // accepting it would store a blank logo that prints as nothing and
            // reads to the operator as "the printer is broken".
            throw new InvalidArgumentException('image: not a decodable image');
        }

        try {
            $srcW = imagesx($src);
            $srcH = imagesy($src);
            if ($srcW < 1 || $srcH < 1) {
                throw new InvalidArgumentException('image: zero-sized');
            }

            // Downscale only — see the class docblock.
            $width = min($srcW, $maxWidthDots);
            $height = (int) max(1, (int) round($srcH * ($width / $srcW)));

            $canvas = imagecreatetruecolor($width, $height);
            // White ground. A transparent PNG otherwise composites onto BLACK,
            // and the logo prints as a solid rectangle — which looks like a
            // hardware fault rather than a bad upload.
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $width, $height, $srcW, $srcH);

            try {
                return [
                    'width' => $width,
                    'height' => $height,
                    'data' => $this->pack($canvas, $width, $height),
                ];
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($src);
        }
    }

    /**
     * Pack the canvas MSB-first, one bit per dot, each row padded to a byte.
     *
     * Bit set = ink. That is the `GS v 0` convention, and it is the opposite of
     * how a human reads "1 = white paper" — worth stating, because inverting it
     * produces a photographic negative that still prints happily.
     *
     * @param  \GdImage  $canvas
     */
    private function pack($canvas, int $width, int $height): string
    {
        $bytesPerRow = intdiv($width + 7, 8);
        $out = '';

        for ($y = 0; $y < $height; $y++) {
            $row = array_fill(0, $bytesPerRow, 0);

            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($canvas, $x, $y);
                // Rec. 601 luma. A flat average makes red logos far too light
                // and blue ones far too dark on a monochrome head.
                $lum = (int) round(
                    0.299 * (($rgb >> 16) & 0xFF)
                    + 0.587 * (($rgb >> 8) & 0xFF)
                    + 0.114 * ($rgb & 0xFF)
                );

                if ($lum <= self::INK_THRESHOLD) {
                    $row[$x >> 3] |= 0x80 >> ($x & 7);
                }
            }

            foreach ($row as $byte) {
                $out .= chr($byte);
            }
        }

        return $out;
    }
}
