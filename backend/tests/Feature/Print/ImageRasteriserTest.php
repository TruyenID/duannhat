<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\ImageRasteriser;
use PHPUnit\Framework\Assert;

/**
 * #1957 mảnh B — the Cloud-side rasteriser.
 *
 * The workstation never decodes an image: Cloud rasterises once and ships the
 * packed result. That choice is what makes the two repos byte-identical BY
 * CONSTRUCTION rather than by a gate — so these tests are about the packing
 * contract, which is the thing both sides depend on.
 */
function pngOf(int $w, int $h, callable $paint): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 255, 255));
    $paint($im);

    ob_start();
    imagepng($im);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

it('I1: packs MSB-first with ink as a SET bit', function () {
    // One row, 8 dots, left half black. MSB-first means the leftmost dot is the
    // high bit, so black-left-half is 0xF0 — not 0x0F.
    //
    // Inverting this produces a photographic negative that still prints
    // perfectly happily, which is why it is asserted on an asymmetric pattern
    // rather than a solid one.
    $png = pngOf(8, 1, function ($im) {
        imagefilledrectangle($im, 0, 0, 3, 0, (int) imagecolorallocate($im, 0, 0, 0));
    });

    $out = (new ImageRasteriser)->rasterise($png, 8);

    expect($out['width'])->toBe(8);
    expect($out['height'])->toBe(1);
    expect(bin2hex($out['data']))->toBe('f0');
});

it('I2: pads each row to a whole byte', function () {
    // 12 dots = 2 bytes per row with 4 bits of padding. A row that is not a
    // whole number of bytes is refused outright by both encoders (`Raster` /
    // `raster()`), so getting this wrong does not misprint — it prints NOTHING,
    // silently.
    $png = pngOf(12, 2, function ($im) {
        imagefilledrectangle($im, 0, 0, 11, 1, (int) imagecolorallocate($im, 0, 0, 0));
    });

    $out = (new ImageRasteriser)->rasterise($png, 12);

    expect(strlen($out['data']))->toBe(4);
    // Trailing padding bits must be CLEAR, not a copy of the last dot: a set
    // pad bit prints a stray column down the right edge of every logo.
    expect(bin2hex($out['data']))->toBe('fff0fff0');
});

it('I3: downscales to the ceiling and never upscales', function () {
    $wide = pngOf(100, 50, fn ($im) => null);
    $narrow = pngOf(20, 10, fn ($im) => null);

    $r = new ImageRasteriser;

    expect($r->rasterise($wide, 40)['width'])->toBe(40);
    // Aspect preserved: 50 * (40/100) = 20.
    expect($r->rasterise($wide, 40)['height'])->toBe(20);

    // Stretching adds no detail, blurs edges the threshold then hardens into
    // jaggies, and eats paper.
    expect($r->rasterise($narrow, 576)['width'])->toBe(20);
});

it('I4: a transparent PNG composites onto WHITE, not black', function () {
    // The failure this prevents looks like hardware: a transparent logo
    // composited onto black prints as a solid rectangle, and the operator
    // reports "the printer is putting out a black box".
    $im = imagecreatetruecolor(8, 1);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    imagedestroy($im);

    $out = (new ImageRasteriser)->rasterise($png, 8);

    expect(bin2hex($out['data']))->toBe('00', 'a fully transparent image must print nothing');
});

it('I5: the packed output is accepted by the encoder that has to send it', function () {
    // The contract that matters. `Escpos::raster()` refuses a byte count that is
    // not a whole number of rows, so this proves the two halves agree rather
    // than each being self-consistent.
    $png = pngOf(37, 9, function ($im) {
        imagefilledellipse($im, 18, 4, 30, 8, (int) imagecolorallocate($im, 0, 0, 0));
    });

    $out = (new ImageRasteriser)->rasterise($png, 37);

    $e = new Escpos;
    $before = $e->length();
    $e->raster($out['width'], $out['data']);

    Assert::assertGreaterThan(
        $before,
        $e->length(),
        'the encoder refused the rasteriser output — the two halves disagree on packing',
    );
});

it('I6: an undecodable upload is a hard reject, not a blank logo', function () {
    // TR-22 clamps what the system CAN fix (an oversized image). A file it
    // cannot decode is not one of those: storing it would yield a logo that
    // prints as nothing, which reads to the operator as a broken printer rather
    // than a bad upload.
    expect(fn () => (new ImageRasteriser)->rasterise('this is not an image', 576))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => (new ImageRasteriser)->rasterise('', 576))
        ->toThrow(InvalidArgumentException::class);
});

it('I7: the same image always yields the same bytes', function () {
    // Load-bearing for the design, not a nicety: the sync feed is
    // content-addressed, so a rasteriser that varied between runs would mint a
    // new hash every render and the feed would never settle — every workstation
    // re-pulling a logo that never changed.
    //
    // This is also why the threshold is fixed rather than an error-diffusion
    // kernel: both are deterministic, but a dither is far more sensitive to a
    // library upgrade nudging a rounding edge.
    $png = pngOf(64, 16, function ($im) {
        imagefilledrectangle($im, 4, 4, 40, 12, (int) imagecolorallocate($im, 30, 90, 200));
    });

    $r = new ImageRasteriser;
    $first = $r->rasterise($png, 48);
    $second = $r->rasterise($png, 48);

    expect(bin2hex($second['data']))->toBe(bin2hex($first['data']));
});
