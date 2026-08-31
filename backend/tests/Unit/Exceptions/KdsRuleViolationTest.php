<?php

use App\Exceptions\KdsRuleViolation;

describe('KdsRuleViolation', function () {
    it('produces RFC 7807 shape', function () {
        $exception = new KdsRuleViolation('KDS_E001', 'Đơn đã đóng', ['order_id' => 'abc']);
        $response = $exception->render();
        $data = $response->getData(true);

        expect($data)->toHaveKeys(['type', 'title', 'status', 'code', 'detail', 'context', 'remediation']);
    });

    it('looks up catalog entry by code', function () {
        $catalog = config('kds-errors');
        $exception = new KdsRuleViolation('KDS_E001', 'Đơn đã đóng');
        $response = $exception->render();
        $data = $response->getData(true);

        expect($data['type'])->toBe($catalog['KDS_E001']['type'])
            ->and($data['title'])->toBe($catalog['KDS_E001']['title'])
            ->and($data['status'])->toBe($catalog['KDS_E001']['status'])
            ->and($data['code'])->toBe('KDS_E001');
    });

    it('falls back to DEFAULT for unknown code', function () {
        $catalog = config('kds-errors');
        $exception = new KdsRuleViolation('KDS_E999', 'Unknown error');
        $response = $exception->render();
        $data = $response->getData(true);

        expect($data['type'])->toBe($catalog['DEFAULT']['type'])
            ->and($data['title'])->toBe($catalog['DEFAULT']['title'])
            ->and($data['status'])->toBe($catalog['DEFAULT']['status'])
            ->and($response->getStatusCode())->toBe(500);
    });

    it('uses custom remediation when provided', function () {
        $exception = new KdsRuleViolation('KDS_E001', 'Đơn đã đóng', [], 'custom remediation');
        $response = $exception->render();
        $data = $response->getData(true);

        expect($data['remediation'])->toBe('custom remediation');
    });

    it('falls back to catalog remediation when empty', function () {
        $catalog = config('kds-errors');
        $exception = new KdsRuleViolation('KDS_E001', 'Đơn đã đóng');
        $response = $exception->render();
        $data = $response->getData(true);

        expect($data['remediation'])->toBe($catalog['KDS_E001']['remediation']);
    });

    it('preserves context in response', function () {
        $exception = new KdsRuleViolation('KDS_E001', 'Đơn đã đóng', ['order_id' => 'abc', 'order_status' => 'cancelled']);
        $response = $exception->render();
        $data = $response->getData(true);

        expect($data['context']['order_id'])->toBe('abc')
            ->and($data['context']['order_status'])->toBe('cancelled');
    });

    it('HTTP status matches catalog for each code', function () {
        $catalog = config('kds-errors');

        $codes = array_keys(array_filter($catalog, fn ($key) => str_starts_with($key, 'KDS_'), ARRAY_FILTER_USE_KEY));

        foreach ($codes as $code) {
            $exception = new KdsRuleViolation($code, 'test detail');
            $response = $exception->render();

            expect($response->getStatusCode())
                ->toBe($catalog[$code]['status'], "Status code mismatch for {$code}");
        }
    });

    it('empty context renders as object not array', function () {
        $exception = new KdsRuleViolation('KDS_E001', 'Đơn đã đóng');
        $response = $exception->render();

        // The raw JSON string must have "context":{} not "context":[]
        $json = $response->getContent();
        expect($json)->toContain('"context":{}');
    });
});
