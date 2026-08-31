<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * PaymentGatewayConnection Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<PaymentGatewayConnection>
 */
class PaymentGatewayConnectionFactory extends Factory
{
    protected $model = PaymentGatewayConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_id' => PaymentGatewayProvider::query()->inRandomOrder()->first()?->id ?? PaymentGatewayProvider::factory()->create()->id,
            // #3074 — tenant RIÊNG cho mỗi connection, không tái dùng hàng có sẵn.
            //
            // `payment_gateway_connections` nay UNIQUE trên khoá tự nhiên
            // (provider · environment · organization · brand · owner_scope ·
            // owner_branch_key). Với `inRandomOrder()->first()`, hai connection
            // dựng trong cùng một bài rất dễ rơi trúng cùng tổ chức và đâm khoá
            // — một factory tự đâm chính nó là factory hỏng, và nó hỏng theo
            // kiểu NGẪU NHIÊN, tức bài đỏ lúc có lúc không.
            'organization_id' => Organization::factory()->create()->id,
            'brand_id' => Brand::factory()->create()->id,
            // HQ là mặc định TẤT ĐỊNH; nhượng quyền đã có state `franchise()`
            // riêng. Random hai vai ở đây từng làm khoá tự nhiên đổi theo lượt
            // chạy, thứ không ai muốn gỡ lúc 2 giờ sáng.
            'owner_branch_id' => null,
            'identity_brand_id' => (string) Str::uuid(),
            'owner_scope' => 'hq',
            'brand_owner_org_unit_id' => (string) Str::uuid(),
            'operator_org_unit_id' => (string) Str::uuid(),
            'ownership_revision' => '1',
            'environment' => fake()->randomElement(['local', 'sandbox', 'test', 'live']),
            'merchant_account_id' => 'acct_'.Str::lower(Str::random(12)),
            'merchant_store_id' => fake()->sentence(),
            'merchant_terminal_id' => fake()->sentence(),
            'merchant_display_name' => fake()->name(),
            'charge_model' => fake()->randomElement(['direct', 'destination', 'separate_charges_and_transfers', 'provider_native']),
            'secret_ref' => (string) Str::uuid(),
            'webhook_secret_ref' => (string) Str::uuid(),
            'secret_version' => '1',
            'key_fingerprint' => hash('sha256', 'factory-fingerprint'),
            'health' => fake()->randomElement(['pending_verification', 'ready', 'degraded', 'unavailable', 'restricted', 'revoked']),
            'health_reason_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
            'last_validated_at' => fake()->dateTime(),
            'is_active' => fake()->boolean(),
        ];
    }

    public function hq(): static
    {
        return $this->state(fn (): array => [
            'owner_scope' => 'hq',
            'owner_branch_id' => null,
        ]);
    }

    public function franchise(): static
    {
        return $this->state(function (): array {
            $branch = Branch::factory()->create();

            return [
                'owner_scope' => 'franchise',
                'owner_branch_id' => $branch->id,
                'organization_id' => $branch->organization_id,
            ];
        });
    }
}
