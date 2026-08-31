<?php

use App\Models\Table;
use App\Support\QrTokenGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('generates a 32-char URL-safe base62 token (no +, /, =)', function () {
    $generator = new QrTokenGenerator;

    for ($i = 0; $i < 10; $i++) {
        $token = $generator->generate(Table::class);
        expect($token)
            ->toBeString()
            ->toHaveLength(32)
            ->toMatch('/^[A-Za-z0-9]{32}$/');
    }
});
