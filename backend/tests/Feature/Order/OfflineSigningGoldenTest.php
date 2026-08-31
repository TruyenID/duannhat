<?php

use App\Services\Order\Offline\OfflineOrderSigningMessage as Msg;
use App\Services\Order\Offline\SelectionWire;
use App\Services\Order\ValueObjects\OrderSelectionPayload;

/*
 * #1094 — cross-language signing parity gate (PHP half).
 *
 * The workstation signs offline orders in Go; Cloud verifies in PHP. A one-byte
 * encoding divergence would reject HONEST orders in production, so both halves
 * are pinned to the SAME committed fixture and each repo fails loudly on drift.
 *
 * Twin test: workstation/internal/service/offline_signing_test.go
 */

function goldenFixture(): array
{
    $path = base_path('tests/Fixtures/offline_signing_golden.json');
    expect(file_exists($path))->toBeTrue("golden fixture missing at {$path}");

    $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($fixture['cases'])->not->toBeEmpty('an empty parity gate is worse than none');

    return $fixture;
}

/**
 * Rebuild a selection payload from the fixture's wire shape — via the SAME
 * production parser the replay endpoint uses (#1097), so the golden gate also
 * pins the HTTP wire mapping.
 */
function selectionFromWire(array $wire): OrderSelectionPayload
{
    return SelectionWire::parse($wire);
}

it('reproduces every golden digest and message — the PHP half of the Go↔PHP parity gate', function () {
    $fixture = goldenFixture();
    expect($fixture['version'])->toBe(Msg::VERSION);

    foreach ($fixture['cases'] as $case) {
        $selection = selectionFromWire($case['selection']);
        $digest = Msg::selectionDigest($selection);

        expect($digest)->toBe(
            $case['expected_selection_digest'],
            "selection digest drift on case '{$case['name']}' — Cloud would reject signatures the fleet is already producing",
        );

        $message = Msg::message(
            $case['envelope']['device_id'],
            $case['envelope']['issuer_id'],
            (int) $case['envelope']['catalog_revision'],
            $case['envelope']['issued_at'],
            $case['envelope']['expires_at'],
            $case['envelope']['key_id'],
            $digest,
        );

        expect(hash('sha256', $message))->toBe(
            $case['expected_message_sha256'],
            "signing message drift on case '{$case['name']}'",
        );
    }
});

it('keeps the workstation copy of the fixture byte-identical to Cloud (skipped when the submodule is absent)', function () {
    $ours = base_path('tests/Fixtures/offline_signing_golden.json');
    $theirs = base_path('../workstation/internal/service/testdata/offline_signing_golden.json');

    if (! file_exists($theirs)) {
        // A standalone backend checkout has no submodule — the Go repo's own
        // test still guards its side.
        //
        // This used to be `expect(true)->toBeTrue(); return;`, which reported
        // PASSED for a comparison that never ran (#2089). markTestSkipped is the
        // same decision stated honestly, and the whole-repo version of this
        // check — hard-failing on CI — is in
        // tests/Feature/Architecture/SharedFixturesAgreeTest.php.
        test()->markTestSkipped('nguồn workstation vắng mặt trong cây (in-tree từ #2306) — cổng parity bị bỏ qua');
    }

    expect(hash_file('sha256', $theirs))->toBe(
        hash_file('sha256', $ours),
        'the two golden fixtures diverged — regenerate and copy so both repos gate on the same bytes',
    );
});

it('is sensitive to every signed envelope field — an unsigned field is a forgeable field', function () {
    $selection = selectionFromWire(goldenFixture()['cases'][0]['selection']);
    $digest = Msg::selectionDigest($selection);
    $args = ['dev-1', 'iss-1', 7, '2026-07-26T10:00:00Z', '2026-07-29T10:00:00Z', 'key-1'];

    $base = Msg::message(...[...$args, $digest]);

    $variants = [
        'device' => ['dev-2', 'iss-1', 7, '2026-07-26T10:00:00Z', '2026-07-29T10:00:00Z', 'key-1'],
        'issuer' => ['dev-1', 'iss-2', 7, '2026-07-26T10:00:00Z', '2026-07-29T10:00:00Z', 'key-1'],
        'revision' => ['dev-1', 'iss-1', 8, '2026-07-26T10:00:00Z', '2026-07-29T10:00:00Z', 'key-1'],
        'issued_at' => ['dev-1', 'iss-1', 7, '2026-07-26T10:00:01Z', '2026-07-29T10:00:00Z', 'key-1'],
        'expires_at' => ['dev-1', 'iss-1', 7, '2026-07-26T10:00:00Z', '2026-07-29T10:00:01Z', 'key-1'],
        'key' => ['dev-1', 'iss-1', 7, '2026-07-26T10:00:00Z', '2026-07-29T10:00:00Z', 'key-2'],
    ];

    foreach ($variants as $field => $variantArgs) {
        expect(Msg::message(...[...$variantArgs, $digest]))->not->toBe(
            $base,
            "changing {$field} left the signed message unchanged — that field is forgeable",
        );
    }

    // …and the selection digest is bound in too.
    expect(Msg::message(...[...$args, str_repeat('0', 64)]))->not->toBe($base);
});

it('verifies a real Ed25519 signature and fails closed on every malformed input', function () {
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
    $secretKey = sodium_crypto_sign_secretkey($keypair);

    $message = 'tempo-offline-order-v1 test message';
    $signature = base64_encode(sodium_crypto_sign_detached($message, $secretKey));

    expect(Msg::verifySignature($message, $signature, $publicKey))->toBeTrue();

    // One flipped byte in the message.
    expect(Msg::verifySignature($message.'x', $signature, $publicKey))->toBeFalse();

    // Another device's key.
    $otherPublic = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    expect(Msg::verifySignature($message, $signature, $otherPublic))->toBeFalse();

    // Malformed inputs must return false, never throw (fail-closed, no 500).
    foreach ([
        'garbage signature' => ['!!!not base64!!!', $publicKey],
        'short signature' => [base64_encode('short'), $publicKey],
        'garbage key' => [$signature, '!!!not base64!!!'],
        'short key' => [$signature, base64_encode('short')],
        'empty both' => ['', ''],
    ] as $label => [$sig, $key]) {
        expect(Msg::verifySignature($message, $sig, $key))->toBeFalse($label.' must fail closed');
    }
});
