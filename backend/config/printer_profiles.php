<?php

/*
|--------------------------------------------------------------------------
| Printer capability profiles — plan-052 DESIGN §3b (#1166)
|--------------------------------------------------------------------------
|
| A profile describes WHAT A MACHINE CAN DO. It never describes what a slip
| SAYS — the content is one template (#1171) for every printer in the fleet.
| The renderer reads a profile to choose the way OUT (native text vs raster,
| which cut command, whether to kick the drawer); it must never branch per
| model, which is why this file is data and there is an architecture test
| forbidding model names in the formatter.
|
| Adding a machine = adding a preset here (or filling `printers.model_profile`
| from the setup wizard). It never needs a release of the workstation binary.
|
| Every key is optional in a stored `model_profile`: PrinterCapabilityProfile
| merges what a shop declared over `escpos_generic`, so a half-finished wizard
| run is strictly better than nothing (P-40).
|
*/

return [

    /*
    | The fallback (P-29). Chosen so an unknown machine — the cheap Xprinter /
    | Rongta / Zjiang class that a shop bought on a marketplace — prints
    | something readable on the first try: plain text, a full cut, no drawer
    | pulse (a wrong pin can jam a till), and the honest admission that it
    | cannot report its own errors.
    */
    'escpos_generic' => [
        'transports' => ['ws_lan'],
        'charset' => [
            'kanji' => false,
            'codepages' => ['CP437', 'CP858'],
        ],
        'text_mode' => 'auto',
        'finishing' => [
            'cut' => [
                'mode' => 'gs_v_full',
                'feed_before_cut' => 4,
                'auto_cut_per_job' => false,
            ],
            'drawer_kick' => [
                'supported' => false,
                'pin' => 2,
                'on_ms' => 120,
                'off_ms' => 240,
            ],
            'buzzer' => ['supported' => false],
        ],
        'error_detect' => [
            'level' => 'none',
            'asb' => false,
            'dle_eot' => false,
            'poll_interval_s' => 30,
        ],
        'health' => [
            'method' => 'tcp_dial',
            'interval_s' => 60,
            'timeout_ms' => 3000,
            'offline_after_misses' => 3,
        ],
        'columns' => ['58mm' => 32, '80mm' => 48],
        'quirks' => [],
    ],

    /*
    | Epson TM-i (TM-m30-i, TM-T88-i…). Kanji ROM, ASB, an embedded HTTP server
    | — the machine a shop buys when it wants no PC at all.
    */
    'epson_tm_i' => [
        'transports' => ['ws_lan', 'epos_http'],
        'charset' => [
            'kanji' => true,
            'codepages' => ['CP932', 'CP437'],
        ],
        'text_mode' => 'native',
        'finishing' => [
            'cut' => [
                'mode' => 'gs_v_partial',
                'feed_before_cut' => 4,
                'auto_cut_per_job' => false,
            ],
            'drawer_kick' => [
                'supported' => true,
                'pin' => 2,
                'on_ms' => 120,
                'off_ms' => 240,
            ],
            'buzzer' => ['supported' => true],
        ],
        'error_detect' => [
            'level' => 'protocol',
            'asb' => true,
            'dle_eot' => true,
            'poll_interval_s' => 30,
        ],
        'health' => [
            'method' => 'http_ping',
            'interval_s' => 60,
            'timeout_ms' => 3000,
            'offline_after_misses' => 3,
        ],
        'columns' => ['58mm' => 32, '80mm' => 48],
        'quirks' => [],
    ],

    /*
    | Star mC-Print / TSP series. StarPRNT cut (ESC d), WebPRNT + CloudPRNT.
    | `reconnect_between_jobs`: the RAW port accepts one TCP session at a time,
    | so a held-open connection makes the SECOND job of a burst fail (this is
    | the same single-slot behaviour Manager.probeEach already works around).
    */
    'star_mcprint' => [
        'transports' => ['ws_lan', 'webprnt', 'cloudprnt'],
        'charset' => [
            'kanji' => true,
            'codepages' => ['CP932', 'CP437'],
        ],
        'text_mode' => 'native',
        'finishing' => [
            'cut' => [
                'mode' => 'esc_d',
                'feed_before_cut' => 3,
                'auto_cut_per_job' => false,
            ],
            'drawer_kick' => [
                'supported' => true,
                'pin' => 1,
                'on_ms' => 100,
                'off_ms' => 200,
            ],
            'buzzer' => ['supported' => false],
        ],
        'error_detect' => [
            'level' => 'status_back',
            'asb' => true,
            'dle_eot' => true,
            'poll_interval_s' => 30,
        ],
        'health' => [
            'method' => 'dle_eot',
            'interval_s' => 60,
            'timeout_ms' => 3000,
            'offline_after_misses' => 3,
        ],
        'columns' => ['58mm' => 32, '80mm' => 48],
        'quirks' => ['reconnect_between_jobs'],
    ],

];
