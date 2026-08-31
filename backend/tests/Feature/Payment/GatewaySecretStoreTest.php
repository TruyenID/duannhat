<?php

namespace Tests\Feature\Payment;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\Secret\DatabaseGatewaySecretStore;
use App\Services\Payment\Secret\Enums\GatewaySecretPurpose;
use App\Services\Payment\Secret\Exceptions\GatewaySecretResolutionFailed;
use App\Services\Payment\Secret\Exceptions\InvalidGatewaySecretConfiguration;
use App\Services\Payment\Secret\FileGatewayMasterKeyProvider;
use App\Services\Payment\Secret\GatewayConnectionSecretResolver;
use App\Services\Payment\Secret\GatewaySecretAuditProtection;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretAccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class GatewaySecretStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $keyringPath;

    private GatewayConnectionSecretResolver $resolver;

    private PaymentGatewayConnection $connection;

    private GatewaySecretAccessContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyringPath = realpath(sys_get_temp_dir()).'/tempo-payment-keyring-'.Str::uuid().'.json';
        file_put_contents($this->keyringPath, json_encode([
            'active_key_id' => 'payment-master-test-a',
            'keys' => [
                'payment-master-test-a' => 'base64:'.base64_encode(str_repeat('A', 32)),
            ],
        ], JSON_THROW_ON_ERROR));
        chmod($this->keyringPath, 0600);

        $organization = Organization::factory()->create();
        $brand = Brand::factory()->create([
            'console_organization_id' => $organization->console_organization_id,
        ]);
        $provider = PaymentGatewayProvider::factory()->create([
            'code' => PaymentGatewayProviderCodeEnum::Stripe,
            'is_active' => true,
        ]);
        $this->connection = PaymentGatewayConnection::factory()->create([
            'provider_id' => $provider->id,
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
            'owner_branch_id' => null,
            'owner_scope' => 'hq',
            'environment' => PaymentGatewayEnvironmentEnum::Test,
            'merchant_account_id' => 'acct_secret_store_test',
            'secret_ref' => null,
            'webhook_secret_ref' => null,
            'secret_version' => null,
            'key_fingerprint' => null,
        ]);
        $this->context = new GatewaySecretAccessContext(
            $organization->id,
            $this->connection->id,
            PaymentGatewayProviderCodeEnum::Stripe,
            PaymentGatewayEnvironmentEnum::Test,
            'operator:test-secret-admin',
            'secret-store:test:1',
        );
        $keys = new FileGatewayMasterKeyProvider($this->keyringPath, [base_path(), public_path()]);
        $auditProtection = new GatewaySecretAuditProtection(DB::connection());
        $auditProtection->install();
        $this->resolver = new GatewayConnectionSecretResolver(new DatabaseGatewaySecretStore(
            DB::connection(),
            $keys,
            $auditProtection,
        ));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        if (isset($this->keyringPath) && is_file($this->keyringPath)) {
            unlink($this->keyringPath);
        }

        parent::tearDown();
    }

    public function test_api_rotation_encrypts_at_rest_resolves_only_ephemerally_and_writes_immutable_redacted_audit(): void
    {
        $secret = 'sk_test_contract_NEVER_PERSIST_PLAINTEXT';
        $rotation = $this->resolver->rotateApi($this->context, new EphemeralSecret($secret));

        self::assertSame(GatewaySecretPurpose::Api, $rotation->purpose);
        self::assertSame(1, $rotation->version);
        self::assertNull($rotation->overlapUntil);
        self::assertSame(64, strlen($rotation->fingerprint));

        $row = DB::table('payment_gateway_secret_versions')->sole();
        $connection = DB::table('payment_gateway_connections')->where('id', $this->connection->id)->first();
        $audit = DB::table('payment_gateway_secret_audits')->sole();
        self::assertSame($row->id, $connection->secret_ref);
        self::assertSame('1', $connection->secret_version);
        self::assertSame($rotation->fingerprint, $connection->key_fingerprint);
        self::assertSame('active', $row->state);
        self::assertNotSame($secret, $row->ciphertext);
        self::assertStringNotContainsString($secret, print_r([
            'ciphertext' => $row->ciphertext,
            'nonce' => $row->nonce,
            'audit' => $audit,
            'connection' => $connection,
        ], true));
        self::assertNotSame($row->id, $audit->new_ref_hash);
        self::assertSame(hash('sha256', $row->id), $audit->new_ref_hash);
        self::assertSame('created', $audit->action);
        self::assertNull($audit->old_fingerprint);
        self::assertSame($rotation->fingerprint, $audit->new_fingerprint);

        $resolved = $this->resolver->api($this->context);
        self::assertSame(hash('sha256', $secret), $resolved->use(fn (string $value): string => hash('sha256', $value)));
        self::assertStringNotContainsString($secret, print_r($resolved, true).var_export($resolved, true).json_encode($resolved, JSON_THROW_ON_ERROR));
        $this->expectException(LogicException::class);
        serialize($resolved);
    }

    public function test_secret_audit_rows_are_database_enforced_append_only(): void
    {
        $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_append_only'));
        $auditId = DB::table('payment_gateway_secret_audits')->value('id');

        try {
            DB::table('payment_gateway_secret_audits')->where('id', $auditId)->update(['action' => 'tampered']);
            self::fail('Secret rotation audit must reject updates.');
        } catch (QueryException $error) {
            self::assertStringContainsString('append-only', $error->getMessage());
        }

        try {
            DB::table('payment_gateway_secret_audits')->where('id', $auditId)->delete();
            self::fail('Secret rotation audit must reject deletes.');
        } catch (QueryException $error) {
            self::assertStringContainsString('append-only', $error->getMessage());
        }

        self::assertDatabaseHas('payment_gateway_secret_audits', ['id' => $auditId, 'action' => 'created']);
    }

    public function test_tenant_connection_provider_and_environment_mismatches_all_fail_closed(): void
    {
        $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_authorized_only'));
        $mismatches = [
            new GatewaySecretAccessContext((string) Str::uuid(), $this->connection->id, PaymentGatewayProviderCodeEnum::Stripe, PaymentGatewayEnvironmentEnum::Test, 'operator:test', 'secret:mismatch:tenant'),
            new GatewaySecretAccessContext($this->context->organizationId, (string) Str::uuid(), PaymentGatewayProviderCodeEnum::Stripe, PaymentGatewayEnvironmentEnum::Test, 'operator:test', 'secret:mismatch:connection'),
            new GatewaySecretAccessContext($this->context->organizationId, $this->connection->id, PaymentGatewayProviderCodeEnum::Paypay, PaymentGatewayEnvironmentEnum::Test, 'operator:test', 'secret:mismatch:provider'),
            new GatewaySecretAccessContext($this->context->organizationId, $this->connection->id, PaymentGatewayProviderCodeEnum::Stripe, PaymentGatewayEnvironmentEnum::Live, 'operator:test', 'secret:mismatch:environment'),
        ];

        foreach ($mismatches as $mismatch) {
            try {
                $this->resolver->api($mismatch);
                self::fail('Secret authorization mismatch must fail closed.');
            } catch (GatewaySecretResolutionFailed $error) {
                self::assertSame('PAYMENT_SECRET_RESOLUTION_FAILED', $error->errorCode);
                self::assertSame($mismatch->correlationId, $error->correlationId);
                self::assertStringNotContainsString('sk_test_authorized_only', $error->getMessage());
            }
        }
    }

    public function test_api_rotation_immediately_revokes_the_previous_version(): void
    {
        $first = $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_first'));
        $second = $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_second'));

        self::assertSame(1, $first->version);
        self::assertSame(2, $second->version);
        self::assertDatabaseHas('payment_gateway_secret_versions', ['version' => 1, 'state' => 'revoked']);
        self::assertDatabaseHas('payment_gateway_secret_versions', ['version' => 2, 'state' => 'active']);
        self::assertSame(hash('sha256', 'sk_test_second'), $this->resolver->api($this->context)->use(fn (string $value) => hash('sha256', $value)));

        $audit = DB::table('payment_gateway_secret_audits')->where('action', 'rotated')->sole();
        self::assertSame($first->fingerprint, $audit->old_fingerprint);
        self::assertSame($second->fingerprint, $audit->new_fingerprint);
    }

    public function test_fingerprint_ciphertext_and_authenticated_scope_tampering_fail_closed(): void
    {
        $rotation = $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_tamper_evidence'));
        $activeReference = DB::table('payment_gateway_connections')->where('id', $this->connection->id)->value('secret_ref');
        $row = DB::table('payment_gateway_secret_versions')->where('id', $activeReference)->sole();

        $tampering = [
            ['fingerprint' => str_repeat('0', 64)],
            ['ciphertext' => base64_encode('forged-ciphertext')],
            ['environment' => 'live'],
        ];

        foreach ($tampering as $change) {
            DB::table('payment_gateway_secret_versions')->where('id', $activeReference)->update([
                'fingerprint' => $rotation->fingerprint,
                'ciphertext' => $row->ciphertext,
                'environment' => 'test',
                ...$change,
            ]);

            try {
                $this->resolver->api($this->context);
                self::fail('Authenticated secret metadata and ciphertext tampering must fail closed.');
            } catch (GatewaySecretResolutionFailed $error) {
                self::assertSame('PAYMENT_SECRET_RESOLUTION_FAILED', $error->errorCode);
                self::assertStringNotContainsString('sk_test_tamper_evidence', $error->getMessage());
            }
        }
    }

    public function test_repeated_webhook_rotation_keeps_only_active_and_immediate_predecessor_during_overlap(): void
    {
        CarbonImmutable::setTestNow('2026-07-22T10:00:00+00:00');
        $this->resolver->rotateWebhook($this->context, new EphemeralSecret('whsec_first'), 0);
        $this->resolver->rotateWebhook($this->context, new EphemeralSecret('whsec_second'), 300);
        $rotation = $this->resolver->rotateWebhook($this->context, new EphemeralSecret('whsec_third'), 300);

        self::assertSame('2026-07-22T10:05:00+00:00', $rotation->overlapUntil?->toIso8601String());
        $duringOverlap = $this->resolver->webhookCandidates($this->context);
        self::assertSame([3, 2], array_map(fn ($secret) => $secret->version, $duringOverlap));
        self::assertSame([
            hash('sha256', 'whsec_third'),
            hash('sha256', 'whsec_second'),
        ], array_map(fn ($secret) => $secret->use(fn (string $value) => hash('sha256', $value)), $duringOverlap));
        self::assertDatabaseHas('payment_gateway_secret_versions', ['version' => 1, 'state' => 'revoked']);

        CarbonImmutable::setTestNow('2026-07-22T10:05:01+00:00');
        $afterOverlap = $this->resolver->webhookCandidates($this->context);
        self::assertSame([3], array_map(fn ($secret) => $secret->version, $afterOverlap));
    }

    public function test_revoke_removes_the_active_reference_and_all_resolvable_versions(): void
    {
        $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_revoke'));
        $this->resolver->revokeApi($this->context);

        self::assertDatabaseHas('payment_gateway_secret_versions', ['version' => 1, 'state' => 'revoked']);
        self::assertDatabaseHas('payment_gateway_secret_audits', ['action' => 'revoked', 'old_version' => 1]);
        $connection = DB::table('payment_gateway_connections')->where('id', $this->connection->id)->first();
        self::assertNull($connection->secret_ref);
        self::assertNull($connection->secret_version);
        self::assertNull($connection->key_fingerprint);

        $this->expectException(GatewaySecretResolutionFailed::class);
        $this->resolver->api($this->context);
    }

    public function test_webhook_overlap_is_rejected_for_api_and_capped_at_24_hours(): void
    {
        foreach ([
            [GatewaySecretPurpose::Api, 1],
            [GatewaySecretPurpose::Webhook, -1],
            [GatewaySecretPurpose::Webhook, 86_401],
        ] as [$purpose, $overlap]) {
            try {
                $store = new DatabaseGatewaySecretStore(
                    DB::connection(),
                    new FileGatewayMasterKeyProvider($this->keyringPath, [base_path(), public_path()]),
                    new GatewaySecretAuditProtection(DB::connection()),
                );
                $store->rotate($this->context, $purpose, new EphemeralSecret('test-secret'), $overlap);
                self::fail('Invalid overlap must fail before writes.');
            } catch (\InvalidArgumentException) {
                self::assertDatabaseCount('payment_gateway_secret_versions', 0);
                self::assertDatabaseCount('payment_gateway_secret_audits', 0);
            }
        }
    }

    public function test_rotation_refuses_a_dangling_active_reference_without_overwriting_evidence(): void
    {
        $danglingReference = (string) Str::uuid();
        DB::table('payment_gateway_connections')->where('id', $this->connection->id)->update([
            'secret_ref' => $danglingReference,
            'secret_version' => '9',
        ]);

        try {
            $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_must_not_replace_dangling'));
            self::fail('Rotation must not conceal a dangling active reference.');
        } catch (GatewaySecretResolutionFailed $error) {
            self::assertSame('PAYMENT_SECRET_RESOLUTION_FAILED', $error->errorCode);
        }

        self::assertSame($danglingReference, DB::table('payment_gateway_connections')->where('id', $this->connection->id)->value('secret_ref'));
        self::assertDatabaseCount('payment_gateway_secret_versions', 0);
        self::assertDatabaseCount('payment_gateway_secret_audits', 0);
    }

    public function test_secret_mutations_fail_closed_when_database_audit_protection_is_missing(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payment_gateway_secret_audits_no_update');
        DB::statement('DROP TRIGGER IF EXISTS payment_gateway_secret_audits_no_delete');

        try {
            $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_requires_audit_protection'));
            self::fail('Secret mutation must not run without append-only audit protection.');
        } catch (InvalidGatewaySecretConfiguration $error) {
            self::assertSame('audit_protection_missing', $error->reason);
        }

        self::assertDatabaseCount('payment_gateway_secret_versions', 0);
        self::assertDatabaseCount('payment_gateway_secret_audits', 0);
    }

    public function test_operational_command_idempotently_installs_database_audit_protection(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payment_gateway_secret_audits_no_update');
        DB::statement('DROP TRIGGER IF EXISTS payment_gateway_secret_audits_no_delete');

        $this->artisan('payments:install-gateway-secret-audit-protection')
            ->expectsOutputToContain('Payment gateway secret audit protection is installed.')
            ->assertSuccessful();
        $this->artisan('payments:install-gateway-secret-audit-protection')->assertSuccessful();

        $this->resolver->rotateApi($this->context, new EphemeralSecret('sk_test_command_installed'));
        self::assertDatabaseCount('payment_gateway_secret_audits', 1);
    }
}
