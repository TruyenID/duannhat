<?php

namespace Tests\Feature\Payment;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentPolicyRevision;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Services\Payment\Policy\Contracts\PaymentPolicyPublicationClock;
use App\Services\Payment\Policy\Contracts\PaymentPolicyRevisionPersistence;
use App\Services\Payment\Policy\Enums\PaymentPolicyPublicationSource;
use App\Services\Payment\Policy\Enums\PolicyDecision;
use App\Services\Payment\Policy\Enums\PolicyLayer;
use App\Services\Payment\Policy\Enums\PolicyReasonCode;
use App\Services\Payment\Policy\PaymentPolicyRevisionPublisher;
use App\Services\Payment\Policy\Persistence\EloquentPaymentPolicyRevisionPersistence;
use App\Services\Payment\Policy\ValueObjects\EffectivePaymentOption;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicySnapshotInput;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyTraceEntry;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class PaymentPolicyRevisionPublisherTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Brand $brand;

    private Branch $branch;

    private FrozenPaymentPolicyPublicationClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'id' => (string) Str::uuid(),
            'console_organization_id' => (string) Str::uuid(),
        ]);
        $this->brand = Brand::factory()->create([
            'console_organization_id' => $this->organization->console_organization_id,
        ]);
        $this->branch = Branch::factory()->create([
            'console_organization_id' => $this->organization->console_organization_id,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);
        $this->clock = new FrozenPaymentPolicyPublicationClock(
            new DateTimeImmutable('2026-07-22T01:02:03+00:00'),
        );
        $this->app->instance(PaymentPolicyPublicationClock::class, $this->clock);
    }

    public function test_default_bindings_publish_idempotent_monotonic_append_only_branch_revisions(): void
    {
        self::assertNotSame($this->organization->id, $this->organization->console_organization_id);
        self::assertInstanceOf(
            EloquentPaymentPolicyRevisionPersistence::class,
            $this->app->make(PaymentPolicyRevisionPersistence::class),
        );
        $publisher = $this->app->make(PaymentPolicyRevisionPublisher::class);

        $first = $publisher->publish($this->input('configuration-a'), PaymentPolicyPublicationSource::ShopPolicyChanged);
        $this->clock->at = new DateTimeImmutable('2026-07-22T02:00:00+00:00');
        $replay = $publisher->publish($this->input('configuration-a'), PaymentPolicyPublicationSource::DevicePolicyChanged);
        $second = $publisher->publish($this->input('configuration-b'), PaymentPolicyPublicationSource::DevicePolicyChanged);
        $reversion = $publisher->publish($this->input('configuration-a'), PaymentPolicyPublicationSource::ShopPolicyChanged);

        self::assertTrue($first->created);
        self::assertSame(1, $first->revision);
        self::assertFalse($replay->created);
        self::assertSame($first->id, $replay->id);
        self::assertSame($first->source, $replay->source);
        self::assertEquals($first->publishedAt, $replay->publishedAt);
        self::assertTrue($second->created);
        self::assertSame(2, $second->revision);
        self::assertTrue($reversion->created);
        self::assertSame(3, $reversion->revision);
        self::assertSame($first->snapshotHash, $reversion->snapshotHash);
        self::assertSame(3, PaymentPolicyRevision::query()->where('branch_id', $this->branch->id)->count());
    }

    public function test_revisions_are_isolated_per_branch_and_unique_constraint_is_the_race_backstop(): void
    {
        $otherBranch = Branch::factory()->create([
            'console_organization_id' => $this->organization->console_organization_id,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);
        $publisher = $this->app->make(PaymentPolicyRevisionPublisher::class);

        $first = $publisher->publish($this->input('configuration-a'), PaymentPolicyPublicationSource::Initial);
        $other = $publisher->publish($this->input('configuration-a', $otherBranch), PaymentPolicyPublicationSource::Initial);

        self::assertSame(1, $first->revision);
        self::assertSame(1, $other->revision);
        self::assertNotSame($first->snapshotHash, $other->snapshotHash);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('payment_policy_revisions')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'revision' => 1,
            'ownership_revision' => 'ownership-revision-1',
            'snapshot_hash' => str_repeat('f', 64),
            'snapshot' => '{}',
            'effective_option_count' => 0,
            'source' => 'concurrent_writer',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_model_rejects_update_and_delete_apis_after_publication(): void
    {
        $published = $this->app->make(PaymentPolicyRevisionPublisher::class)
            ->publish($this->input('configuration-a'), PaymentPolicyPublicationSource::Initial);
        $record = PaymentPolicyRevision::query()->findOrFail($published->id);

        try {
            $record->update(['source' => 'mutated']);
            self::fail('Published revisions must reject model updates.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $record->delete();
    }

    public function test_scope_mismatch_and_corrupt_latest_snapshot_fail_closed(): void
    {
        $publisher = $this->app->make(PaymentPolicyRevisionPublisher::class);
        $foreignBrand = Brand::factory()->create([
            'console_organization_id' => (string) Str::uuid(),
        ]);

        try {
            $publisher->publish(new PaymentPolicySnapshotInput(
                (string) $this->organization->id,
                (string) $foreignBrand->id,
                (string) $this->branch->id,
                'ownership-revision-1',
                hash('sha256', 'configuration-a'),
                [$this->option()],
            ), PaymentPolicyPublicationSource::ManualRepublish);
            self::fail('Foreign brand scope must be rejected.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('scope is invalid', $exception->getMessage());
        }

        $otherBrandInOrganization = Brand::factory()->create([
            'console_organization_id' => $this->organization->console_organization_id,
        ]);
        try {
            $publisher->publish(new PaymentPolicySnapshotInput(
                (string) $this->organization->id,
                (string) $otherBrandInOrganization->id,
                (string) $this->branch->id,
                'ownership-revision-1',
                hash('sha256', 'configuration-a'),
                [$this->option()],
            ), PaymentPolicyPublicationSource::ManualRepublish);
            self::fail('A branch from another brand must be rejected.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('scope is invalid', $exception->getMessage());
        }

        $published = $publisher->publish($this->input('configuration-a'), PaymentPolicyPublicationSource::Initial);
        DB::table('payment_policy_revisions')->where('id', $published->id)->update([
            'snapshot' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(RuntimeException::class);
        $publisher->publish($this->input('configuration-a'), PaymentPolicyPublicationSource::ManualRepublish);
    }

    private function input(string $configuration, ?Branch $branch = null): PaymentPolicySnapshotInput
    {
        return new PaymentPolicySnapshotInput(
            (string) $this->organization->id,
            (string) $this->brand->id,
            (string) ($branch ?? $this->branch)->id,
            'ownership-revision-1',
            hash('sha256', $configuration),
            [$this->option()],
        );
    }

    private function option(): EffectivePaymentOption
    {
        return new EffectivePaymentOption(
            '00000000-0000-4000-8000-000000000601',
            'policy:publisher:test',
            true,
            PolicyReasonCode::Effective,
            '00000000-0000-4000-8000-000000000701',
            '00000000-0000-4000-8000-000000000801',
            '00000000-0000-4000-8000-000000000901',
            PaymentConnectionOwnerScopeEnum::Hq,
            '00000000-0000-4000-8000-000000000401',
            'ownership-revision-1',
            [new PaymentPolicyTraceEntry(
                PolicyLayer::Runtime,
                PolicyDecision::Allowed,
                PolicyReasonCode::RuntimeAvailable,
            )],
        );
    }
}

final class FrozenPaymentPolicyPublicationClock implements PaymentPolicyPublicationClock
{
    public function __construct(public DateTimeImmutable $at) {}

    public function now(): DateTimeImmutable
    {
        return $this->at;
    }
}
