<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Customer;

use App\Models\Branch;
use App\Services\Customer\PayPayAvailabilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * plan-054 M3 — whether customer-web offers the PayPay QR flow for a branch.
 *
 * Every case here is a "fails closed AND says why" case: the defect this
 * replaces was PayPay silently not appearing with no error recorded anywhere.
 */
final class PayPayAvailabilityServiceTest extends TestCase
{
    private function branch(string $currency = 'JPY'): Branch
    {
        $branch = new Branch;
        $branch->currency = $currency;

        return $branch;
    }

    private function configureCredentials(): void
    {
        config([
            'services.paypay.api_key' => 'a_key',
            'services.paypay.api_secret' => 'a_secret',
            'services.paypay.merchant_id' => '991602796635988897',
        ]);
    }

    public function test_enabled_when_credentials_and_currency_line_up(): void
    {
        $this->configureCredentials();

        self::assertSame(
            ['enabled' => true, 'reason' => null],
            (new PayPayAvailabilityService)->forBranch($this->branch()),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingCredentialProvider(): array
    {
        return [
            'no api key' => ['api_key'],
            'no api secret' => ['api_secret'],
            // Required alongside the key pair: it is the assume-merchant identity
            // every OPA call carries, so half-configured must not advertise PayPay.
            'no merchant id' => ['merchant_id'],
        ];
    }

    #[DataProvider('missingCredentialProvider')]
    public function test_disabled_when_any_credential_is_missing(string $missing): void
    {
        $this->configureCredentials();
        config(["services.paypay.{$missing}" => '']);

        self::assertSame(
            ['enabled' => false, 'reason' => 'credentials_missing'],
            (new PayPayAvailabilityService)->forBranch($this->branch()),
        );
    }

    public function test_disabled_on_a_branch_that_does_not_settle_in_yen(): void
    {
        $this->configureCredentials();

        // Checked here rather than left to the policy engine, whose request
        // hardcodes 'JPY' regardless of the branch — a VND branch would be told
        // PayPay is available and then have a VND figure submitted as yen.
        self::assertSame(
            ['enabled' => false, 'reason' => 'currency_unsupported'],
            (new PayPayAvailabilityService)->forBranch($this->branch('VND')),
        );
    }

    public function test_currency_comparison_is_case_insensitive(): void
    {
        $this->configureCredentials();

        self::assertTrue((new PayPayAvailabilityService)->forBranch($this->branch('jpy'))['enabled']);
    }

    public function test_credentials_are_checked_before_currency(): void
    {
        config([
            'services.paypay.api_key' => '',
            'services.paypay.api_secret' => '',
            'services.paypay.merchant_id' => '',
        ]);

        // A deployment with no PayPay at all should say so, rather than blaming
        // the branch for a currency that was never going to be reached.
        self::assertSame(
            'credentials_missing',
            (new PayPayAvailabilityService)->forBranch($this->branch('VND'))['reason'],
        );
    }
}
