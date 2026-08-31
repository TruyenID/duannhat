<?php

use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\PrintJobRegistry;

/**
 * plan-052 P-05 / P-06 (#1166).
 *
 * The retry matrix is the single rule standing between "a printer hiccuped"
 * and "the shop handed a customer a second original of an インボイス"
 * (RISKS PR1). Every cell is locked here on purpose: if someone later adds
 * auto-retry to a money document, this file goes red before production does.
 */
beforeEach(function () {
    $this->registry = new PrintJobRegistry;
});

describe('P-05 retry matrix — per kind', function () {
    it('auto-retries the kinds where a duplicate is harmless', function (string $kind) {
        expect($this->registry->allowsAutoRetry($kind))->toBeTrue()
            ->and($this->registry->maxAttempts($kind))->toBeGreaterThan(1);
    })->with(['kitchen', 'bar', 'label']);

    it('NEVER auto-retries a money document', function (string $kind) {
        expect($this->registry->allowsAutoRetry($kind))->toBeFalse()
            ->and($this->registry->maxAttempts($kind))->toBe(1);
    })->with(['receipt', 'red_invoice', 'debt_slip']);

    it('gives a report exactly one more try', function () {
        expect($this->registry->allowsAutoRetry('report'))->toBeTrue()
            ->and($this->registry->maxAttempts('report'))->toBe(2);
    });

    it('lets the wizard diagnostic sheet retry — it is not a money document', function () {
        expect($this->registry->allowsAutoRetry('diagnostic'))->toBeTrue()
            ->and(PrintJobKind::Diagnostic->isMoneyDocument())->toBeFalse();
    });

    it('refuses to auto-retry an unknown kind rather than guessing', function () {
        expect($this->registry->allowsAutoRetry('some_future_document'))->toBeFalse()
            ->and($this->registry->maxAttempts('some_future_document'))->toBe(1);
    });
});

describe('P-05 shouldAutoRetry — kind × status × attempts', function () {
    it('retries a failed kitchen ticket until the attempt budget runs out', function () {
        expect($this->registry->shouldAutoRetry(PrintJobKind::Kitchen, PrintJobStatus::Failed, 1))->toBeTrue()
            ->and($this->registry->shouldAutoRetry(PrintJobKind::Kitchen, PrintJobStatus::Failed, 3))->toBeTrue()
            ->and($this->registry->shouldAutoRetry(PrintJobKind::Kitchen, PrintJobStatus::Failed, 4))->toBeFalse();
    });

    it('holds a receipt still even from needs_attention — ACK-lost is a human decision', function () {
        expect($this->registry->shouldAutoRetry(PrintJobKind::Receipt, PrintJobStatus::NeedsAttention, 0))->toBeFalse()
            ->and($this->registry->shouldAutoRetry(PrintJobKind::Receipt, PrintJobStatus::Failed, 0))->toBeFalse()
            ->and($this->registry->shouldAutoRetry(PrintJobKind::RedInvoice, PrintJobStatus::NeedsAttention, 0))->toBeFalse()
            ->and($this->registry->shouldAutoRetry(PrintJobKind::DebtSlip, PrintJobStatus::NeedsAttention, 0))->toBeFalse();
    });

    it('never retries from a terminal or in-flight state', function () {
        expect($this->registry->shouldAutoRetry(PrintJobKind::Kitchen, PrintJobStatus::Printed, 0))->toBeFalse()
            ->and($this->registry->shouldAutoRetry(PrintJobKind::Kitchen, PrintJobStatus::Expired, 0))->toBeFalse()
            ->and($this->registry->shouldAutoRetry(PrintJobKind::Kitchen, PrintJobStatus::Delivering, 0))->toBeFalse()
            ->and($this->registry->shouldAutoRetry(PrintJobKind::Kitchen, PrintJobStatus::Queued, 0))->toBeFalse();
    });

    it('walks the backoff schedule and then holds the last interval', function () {
        expect($this->registry->backoffSeconds(PrintJobKind::Kitchen, 1))->toBe(5)
            ->and($this->registry->backoffSeconds(PrintJobKind::Kitchen, 2))->toBe(15)
            ->and($this->registry->backoffSeconds(PrintJobKind::Kitchen, 3))->toBe(60)
            ->and($this->registry->backoffSeconds(PrintJobKind::Kitchen, 9))->toBe(60);
    });

    it('has no backoff at all for a kind that never retries', function () {
        expect($this->registry->backoffSeconds(PrintJobKind::Receipt, 1))->toBe(0);
    });
});

describe('P-06 TTL per kind', function () {
    it('expires a kitchen ticket in 15 minutes — a cold dish is not a late ticket', function () {
        expect($this->registry->ttlSeconds(PrintJobKind::Kitchen))->toBe(900)
            ->and($this->registry->ttlSeconds(PrintJobKind::Bar))->toBe(900);
    });

    it('keeps a receipt printable for 24h', function () {
        expect($this->registry->ttlSeconds(PrintJobKind::Receipt))->toBe(86400)
            ->and($this->registry->ttlSeconds(PrintJobKind::RedInvoice))->toBe(86400)
            ->and($this->registry->ttlSeconds(PrintJobKind::DebtSlip))->toBe(86400);
    });

    it('computes expiry from the issue time, not from now', function () {
        $issued = now()->subHours(3);

        expect($this->registry->expiresAt(PrintJobKind::Kitchen, $issued)->timestamp)
            ->toBe($issued->copy()->addMinutes(15)->timestamp);
    });

    it('reports a stale kitchen ticket as expired and a same-age receipt as live', function () {
        $issued = now()->subMinutes(30);

        expect($this->registry->isExpired(PrintJobKind::Kitchen, $issued))->toBeTrue()
            ->and($this->registry->isExpired(PrintJobKind::Receipt, $issued))->toBeFalse();
    });

    it('does not expire a job the instant before its TTL', function () {
        $issued = now()->subMinutes(14);

        expect($this->registry->isExpired(PrintJobKind::Kitchen, $issued))->toBeFalse();
    });
});
