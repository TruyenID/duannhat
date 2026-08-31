<?php

it('allows any localhost port via origin pattern', function () {
    config()->set('cors.allowed_origins', []);
    config()->set('cors.allowed_origins_patterns', [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
    ]);
    config()->set('cors.supports_credentials', true);

    $response = $this->call('OPTIONS', '/api/v1/hq/any', [], [], [], [
        'HTTP_ORIGIN' => 'http://localhost:9999',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:9999');
    expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
});

it('allows 127.0.0.1 with arbitrary port via origin pattern', function () {
    config()->set('cors.allowed_origins', []);
    config()->set('cors.allowed_origins_patterns', [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
    ]);
    config()->set('cors.supports_credentials', true);

    $response = $this->call('OPTIONS', '/api/v1/hq/any', [], [], [], [
        'HTTP_ORIGIN' => 'http://127.0.0.1:3001',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('http://127.0.0.1:3001');
});

it('rejects non-localhost origins', function () {
    config()->set('cors.allowed_origins', []);
    config()->set('cors.allowed_origins_patterns', [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
    ]);
    config()->set('cors.supports_credentials', true);

    $response = $this->call('OPTIONS', '/api/v1/hq/any', [], [], [], [
        'HTTP_ORIGIN' => 'https://evil.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});
