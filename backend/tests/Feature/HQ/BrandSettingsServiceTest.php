<?php

use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\File;
use App\Models\Organization;
use App\Omnify\Enums\FileStatusEnum;
use App\Services\Shop\BrandSettingsService;
use Illuminate\Support\Str;

/**
 * Plan-047 thin-controller/fat-service — the brand-settings write logic moved
 * from HqBrandSettingsController::update into BrandSettingsService. The HTTP
 * surface stays covered by HqBrandSettingsControllerTest; these hit the service.
 */
beforeEach(function () {
    $consoleOrgId = (string) Str::uuid();
    $this->organization = Organization::factory()->create([
        'console_organization_id' => $consoleOrgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $consoleOrgId]);
    $this->service = app(BrandSettingsService::class);
});

it('defaults the payload (3 / free / false) when no policy row exists', function () {
    $payload = $this->service->settingsPayload($this->brand);

    expect($payload['default_confirmation_timeout_minutes'])->toBe(3)
        ->and($payload['default_table_status_after_payment'])->toBe('free')
        ->and($payload['allow_shop_edit_hq_tables'])->toBeFalse();
});

it('updates the brand columns and echoes them', function () {
    $payload = $this->service->update($this->brand, [
        'cart_timeout_minutes' => 45,
        'takeaway_payment_timeout_minutes' => 20,
        'customer_header_logo_url' => 'https://cdn.example.test/header-logo.png',
        'customer_order_logo_url' => 'https://cdn.example.test/order-logo.png',
        'customer_order_subtitle' => 'Choose your order type',
    ]);

    expect($payload['cart_timeout_minutes'])->toBe(45)
        ->and($payload['takeaway_payment_timeout_minutes'])->toBe(20)
        ->and($payload['customer_header_logo_url'])->toBe('https://cdn.example.test/header-logo.png')
        ->and($payload['customer_order_logo_url'])->toBe('https://cdn.example.test/order-logo.png')
        ->and($payload['customer_order_subtitle'])->toBe('Choose your order type')
        ->and($this->brand->fresh()->cart_timeout_minutes)->toBe(45)
        ->and($this->brand->fresh()->customer_header_logo_url)->toBe('https://cdn.example.test/header-logo.png')
        ->and($this->brand->fresh()->customer_order_logo_url)->toBe('https://cdn.example.test/order-logo.png')
        ->and($this->brand->fresh()->customer_order_subtitle)->toBe('Choose your order type');
});

it('creates the policy row with the LOCAL org id resolved from console_organization_id', function () {
    $this->service->update($this->brand, ['default_confirmation_timeout_minutes' => 12]);

    $policy = BrandOrderPolicy::where('brand_id', $this->brand->id)->firstOrFail();

    expect((int) $policy->default_confirmation_timeout_minutes)->toBe(12)
        // FKs into the LOCAL organizations.id, not the SSO console id.
        ->and($policy->organization_id)->toBe($this->organization->id);
});

it('upserts each policy field independently (a later call keeps prior fields)', function () {
    $this->service->update($this->brand, ['default_confirmation_timeout_minutes' => 7]);
    $this->service->update($this->brand, ['default_table_status_after_payment' => 'cleaning']);
    $payload = $this->service->update($this->brand, ['allow_shop_edit_hq_tables' => true]);

    // All three survive on the single policy row.
    expect($payload['default_confirmation_timeout_minutes'])->toBe(7)
        ->and($payload['default_table_status_after_payment'])->toBe('cleaning')
        ->and($payload['allow_shop_edit_hq_tables'])->toBeTrue()
        ->and(BrandOrderPolicy::where('brand_id', $this->brand->id)->count())->toBe(1);
});

it('updates an existing policy row rather than creating a second', function () {
    $this->service->update($this->brand, ['default_confirmation_timeout_minutes' => 5]);
    $this->service->update($this->brand, ['default_confirmation_timeout_minutes' => 25]);

    expect(BrandOrderPolicy::where('brand_id', $this->brand->id)->count())->toBe(1)
        ->and((int) BrandOrderPolicy::where('brand_id', $this->brand->id)->value('default_confirmation_timeout_minutes'))->toBe(25);
});

/*
 * #2047 — hai cột logo chuyển từ chuỗi URL sang id File.
 *
 * Lỗi gốc: admin-web lưu URL TUYỆT ĐỐI trỏ vào `uploads/temp/…` rồi gọi
 * make-permanent kiểu "best-effort" (nuốt lỗi), nên dòng `files` ở lại
 * `status=temporary` và `omnify:cleanup-files` xoá file vật lý sau 12h —
 * logo vỡ vĩnh viễn mà không có gì báo. Giữ file giờ là việc của SERVER.
 */
it('makes the logo file permanent on write so the cleanup job cannot sweep it', function () {
    $file = File::factory()->create([
        'collection' => 'customer_header_logo',
    ]);

    expect($file->status)->toBe(FileStatusEnum::Temporary);

    $this->service->update($this->brand, [
        'customer_header_logo_file_id' => $file->id,
    ]);

    expect($file->fresh()->status)->toBe(FileStatusEnum::Permanent)
        ->and($file->fresh()->expires_at)->toBeNull()
        ->and($this->brand->fresh()->customer_header_logo_file_id)->toBe($file->id);
});

it('resolves the logo URL from the file id at read time', function () {
    config(['app.url' => 'https://tempo.godx.jp']);

    $file = File::factory()->create([
        'disk' => 'local',
        'path' => 'uploads/temp/019fcffa/logo.png',
    ]);

    $payload = $this->service->update($this->brand, [
        'customer_header_logo_file_id' => $file->id,
    ]);

    // Tuyệt đối, và giữ nguyên tên trường `*_logo_url` — customer-web tiêu thụ
    // URL và không cần biết nguồn, nên nó không phải đổi gì.
    expect($payload['customer_header_logo_url'])
        ->toBe('https://tempo.godx.jp/storage/uploads/temp/019fcffa/logo.png')
        ->and($payload['customer_header_logo_file_id'])->toBe($file->id);
});

it('falls back to the legacy URL column for a brand not yet migrated', function () {
    $this->brand->update([
        'customer_header_logo_url' => 'https://cdn.example.test/legacy.png',
        'customer_header_logo_file_id' => null,
    ]);

    $payload = $this->service->settingsPayload($this->brand->fresh());

    expect($payload['customer_header_logo_url'])->toBe('https://cdn.example.test/legacy.png');
});

/*
 * Cố ý KHÔNG rơi ngược về cột URL cũ khi id trỏ tới File đã bị xoá: cột cũ gần
 * như chắc chắn trỏ tới đúng file vừa mất đó, nên fallback chỉ đổi một ô trống
 * lấy một ảnh vỡ — đúng triệu chứng đang thấy trên production.
 */
it('returns null (not the stale legacy URL) when the file id points at a deleted file', function () {
    $file = File::factory()->create();

    $this->brand->update([
        'customer_header_logo_url' => 'https://cdn.example.test/already-swept.png',
        'customer_header_logo_file_id' => $file->id,
    ]);

    $file->forceDelete();

    $payload = $this->service->settingsPayload($this->brand->fresh());

    expect($payload['customer_header_logo_url'])->toBeNull();
});
