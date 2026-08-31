<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Identity event source
    |---------------------------------------------------------------------------
    |
    | Which driver the identity feed arrives on (ADR 0002). Symmetric with the
    | producer side in dxs-platform/platform: moving off SQS is THIS value plus
    | one driver class, never a change to the inbox, dedupe, ordering or apply.
    |
    */

    'source' => env('IDENTITY_SOURCE', 'sqs'),

    'sources' => [

        'sqs' => [
            'driver' => 'sqs',
            // Empty is the default and it is SAFE: the consumer refuses to run
            // and says why, rather than acknowledging messages it never applied.
            'queue_url' => env('IDENTITY_SQS_QUEUE_URL', ''),
            'region' => env('IDENTITY_AWS_REGION', 'ap-northeast-1'),
            'wait_seconds' => (int) env('IDENTITY_SQS_WAIT_SECONDS', 20),
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

    'consumer' => [
        // Messages per run. The command runs under cron + flock with roughly a
        // 55-second budget, and SQS hands over at most 10 per receive call.
        'batch' => (int) env('IDENTITY_CONSUMER_BATCH', 50),
    ],

];
