<?php

use App\Models\Printer;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\PrinterCapabilityProfile;

/**
 * plan-052 T1.4b / T1.4c — DESIGN §3b.
 *
 * A profile answers questions about a MACHINE. The point of these tests is
 * that every such question has a safe answer even when nobody has ever told
 * us anything about the machine (P-29), and that the answers a shop DID give
 * are never quietly overridden.
 */
describe('P-29 — an undeclared machine still prints', function () {
    it('falls back to escpos_generic for null / empty / missing profiles', function (array|string|null $stored) {
        $profile = PrinterCapabilityProfile::resolve($stored);

        expect($profile->cutMode())->toBe('gs_v_full')
            ->and($profile->feedBeforeCut())->toBe(4)
            ->and($profile->supportsDrawerKick())->toBeFalse()
            ->and($profile->columnsFor(58))->toBe(32)
            ->and($profile->columnsFor(80))->toBe(48)
            ->and($profile->errorDetectLevel())->toBe('none')
            ->and($profile->healthMethod())->toBe('tcp_dial')
            ->and($profile->transports())->toBe(['ws_lan']);
    })->with([[null], [[]], ['']]);

    it('answers for a printer row that has never been through the wizard', function () {
        $printer = new Printer(['model_profile' => null]);

        expect($printer->capabilityProfile()->cutMode())->toBe('gs_v_full');
    });

    it('falls back rather than throwing on a preset name nobody defined', function () {
        expect(PrinterCapabilityProfile::resolve('a_printer_we_never_heard_of')->cutMode())
            ->toBe('gs_v_full');
    });
});

describe('P-40 — a half-finished wizard run is better than none', function () {
    it('merges a partial answer set over the generic base', function () {
        // The operator answered exactly one question: "did it cut?" — no.
        $profile = PrinterCapabilityProfile::resolve([
            'finishing' => ['cut' => ['mode' => 'none']],
        ]);

        expect($profile->cutMode())->toBe('none')
            ->and($profile->cutsPaper())->toBeFalse()
            // …and everything unanswered still has a working default.
            ->and($profile->feedBeforeCut())->toBe(4)
            ->and($profile->columnsFor(80))->toBe(48);
    });

    it('inherits a named preset and lets the shop override one field of it', function () {
        $profile = PrinterCapabilityProfile::resolve([
            'preset' => 'star_mcprint',
            'finishing' => ['cut' => ['feed_before_cut' => 6]],
        ]);

        expect($profile->cutMode())->toBe('esc_d')          // from the preset
            ->and($profile->feedBeforeCut())->toBe(6)       // overridden
            ->and($profile->supportsKanji())->toBeTrue();   // from the preset
    });

    it('lets a shop CLEAR an inherited quirk a firmware update fixed', function () {
        $inherited = PrinterCapabilityProfile::resolve('star_mcprint');
        $cleared = PrinterCapabilityProfile::resolve(['preset' => 'star_mcprint', 'quirks' => []]);

        expect($inherited->hasQuirk('reconnect_between_jobs'))->toBeTrue()
            ->and($cleared->quirks())->toBe([]);
    });
});

describe('P-30 — raster fallback for a machine with no kanji ROM', function () {
    it('rasterises a block containing characters the machine cannot render', function () {
        $profile = PrinterCapabilityProfile::resolve([
            'charset' => ['kanji' => false, 'codepages' => ['CP437']],
            'text_mode' => 'auto',
        ]);

        expect($profile->textModeFor('唐揚げ 2点'))->toBe('raster')
            ->and($profile->textModeFor('Cà phê sữa đá'))->toBe('raster');
    });

    it('keeps numbers and money native — rasterising a whole slip jams a rush', function () {
        $profile = PrinterCapabilityProfile::resolve([
            'charset' => ['kanji' => false],
            'text_mode' => 'auto',
        ]);

        expect($profile->textModeFor('TOTAL  1,980'))->toBe('native')
            ->and($profile->textModeFor('2026-07-28 19:04'))->toBe('native');
    });

    it('prints everything natively on a machine that has the ROM', function () {
        $profile = PrinterCapabilityProfile::resolve('epson_tm_i');

        expect($profile->supportsKanji())->toBeTrue()
            ->and($profile->textModeFor('唐揚げ'))->toBe('native');
    });

    it('honours an explicit operator choice over the auto heuristic', function () {
        $forcedRaster = PrinterCapabilityProfile::resolve([
            'charset' => ['kanji' => true],
            'text_mode' => 'raster',
        ]);
        $forcedNative = PrinterCapabilityProfile::resolve([
            'charset' => ['kanji' => false],
            'text_mode' => 'native',
        ]);

        expect($forcedRaster->textModeFor('TOTAL 100'))->toBe('raster')
            ->and($forcedNative->textModeFor('唐揚げ'))->toBe('native');
    });
});

describe('P-32 — quirks', function () {
    it('reports reconnect_between_jobs for a Star machine with one TCP slot', function () {
        expect(PrinterCapabilityProfile::resolve('star_mcprint')->hasQuirk('reconnect_between_jobs'))->toBeTrue()
            ->and(PrinterCapabilityProfile::resolve(null)->hasQuirk('reconnect_between_jobs'))->toBeFalse();
    });
});

describe('P-33 — the confidence a machine can honestly earn', function () {
    it('caps a level-A machine at sent_only', function () {
        $profile = PrinterCapabilityProfile::resolve(['error_detect' => ['level' => 'none']]);

        expect($profile->printConfidence())->toBe('sent_only')
            ->and($profile->supportsPreflightStatus())->toBeFalse();
    });

    it('lets a status-back or protocol machine reach confirmed', function (string $level) {
        $profile = PrinterCapabilityProfile::resolve(['error_detect' => ['level' => $level]]);

        expect($profile->printConfidence())->toBe('confirmed')
            ->and($profile->supportsPreflightStatus())->toBeTrue();
    })->with(['status_back', 'protocol']);

    it('treats an unrecognised level as the least capable one', function () {
        expect(PrinterCapabilityProfile::resolve(['error_detect' => ['level' => 'magic']])->errorDetectLevel())
            ->toBe('none');
    });
});

describe('P-36 — cut mode none', function () {
    it('reports that no cut command must be sent to a tear-bar machine', function () {
        $profile = PrinterCapabilityProfile::resolve(['finishing' => ['cut' => ['mode' => 'none']]]);

        expect($profile->cutsPaper())->toBeFalse();
    });

    it('reports a cut for every machine that has a blade', function (string $preset) {
        expect(PrinterCapabilityProfile::resolve($preset)->cutsPaper())->toBeTrue();
    })->with(['escpos_generic', 'epson_tm_i', 'star_mcprint']);
});

describe('P-37 — drawer kick', function () {
    it('says NO for the generic machine so the UI can hide the button', function () {
        expect(PrinterCapabilityProfile::resolve(null)->supportsDrawerKick())->toBeFalse();
    });

    it('carries the pin and pulse timing when the machine can kick', function () {
        $timing = PrinterCapabilityProfile::resolve('star_mcprint')->drawerKickTiming();

        expect(PrinterCapabilityProfile::resolve('star_mcprint')->supportsDrawerKick())->toBeTrue()
            ->and($timing)->toBe(['pin' => 1, 'on_ms' => 100, 'off_ms' => 200]);
    });

    it('lets a shop correct the timing without a release', function () {
        $profile = PrinterCapabilityProfile::resolve([
            'finishing' => ['drawer_kick' => ['supported' => true, 'pin' => 5, 'on_ms' => 200, 'off_ms' => 400]],
        ]);

        expect($profile->drawerKickTiming())->toBe(['pin' => 5, 'on_ms' => 200, 'off_ms' => 400]);
    });
});

describe('P-38 — health method follows the tier that owns the queue', function () {
    it('dials the socket for a machine that cannot say anything else', function () {
        expect(PrinterCapabilityProfile::resolve(null)->healthMethod())->toBe('tcp_dial');
    });

    it('asks a status-capable machine directly', function () {
        expect(PrinterCapabilityProfile::resolve('star_mcprint')->healthMethod())->toBe('dle_eot');
    });

    it('pings an ePOS machine over HTTP', function () {
        expect(PrinterCapabilityProfile::resolve('epson_tm_i')->healthMethod())->toBe('http_ping');
    });

    it('accepts poll_silence — the only method that works behind a shop NAT', function () {
        expect(PrinterCapabilityProfile::resolve(['health' => ['method' => 'poll_silence']])->healthMethod())
            ->toBe('poll_silence');
    });

    it('rejects an unknown method rather than probing in an undefined way', function () {
        expect(PrinterCapabilityProfile::resolve(['health' => ['method' => 'telepathy']])->healthMethod())
            ->toBe('tcp_dial');
    });
});

describe('transports a machine can actually speak', function () {
    it('limits a generic ESC/POS box to the workstation path', function () {
        $profile = PrinterCapabilityProfile::resolve(null);

        expect($profile->supportsTransport(PrintTransport::WsLan))->toBeTrue()
            ->and($profile->supportsTransport(PrintTransport::CloudPrnt))->toBeFalse()
            ->and($profile->supportsTransport(PrintTransport::EposHttp))->toBeFalse();
    });

    it('knows a Star machine can also poll Cloud', function () {
        expect(PrinterCapabilityProfile::resolve('star_mcprint')->supportsTransport(PrintTransport::CloudPrnt))
            ->toBeTrue();
    });

    it('P-39: every non-ws_lan transport still needs a Cloud renderer', function () {
        expect(PrintTransport::WsLan->requiresCloudRenderer())->toBeFalse()
            ->and(PrintTransport::EposHttp->requiresCloudRenderer())->toBeTrue()
            ->and(PrintTransport::WebPrnt->requiresCloudRenderer())->toBeTrue()
            ->and(PrintTransport::CloudPrnt->requiresCloudRenderer())->toBeTrue();
    });
});

describe('P-31 — changing a profile mid-shift', function () {
    it('resolves from the stored value at the moment it is read, not from a cached copy', function () {
        $printer = new Printer(['model_profile' => ['finishing' => ['cut' => ['mode' => 'none']]]]);
        expect($printer->capabilityProfile()->cutMode())->toBe('none');

        // The next read sees the new profile — the job already rendered keeps
        // the bytes it was built with, which is what makes this safe.
        $printer->model_profile = ['preset' => 'epson_tm_i'];
        expect($printer->capabilityProfile()->cutMode())->toBe('gs_v_partial');
    });
});
