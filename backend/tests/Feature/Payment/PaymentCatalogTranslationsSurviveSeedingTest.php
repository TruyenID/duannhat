<?php

use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tên phương thức thanh toán phải sống sót qua `migrate:fresh --seed`.
 *
 * ## Cái bẫy
 *
 * Astrotomic bền hoá bản dịch trong một hook `static::saved(...)`
 * (`Translatable.php:35` → `saveTranslations()`). `DatabaseSeeder`
 * `use WithoutModelEvents`, nên toàn bộ lượt seed chạy trong
 * `Model::withoutEvents()` và hook đó KHÔNG BAO GIỜ bắn — mọi
 * `translateOrNew(...)->name = ...` bị vứt lặng lẽ.
 *
 * Đo được trước bản sửa: `db:seed --class=PaymentGatewayCatalogSeeder` chạy
 * RIÊNG cho ra 15 dòng; chạy trong bộ đầy đủ cho ra **0**. Chạy riêng thì đúng
 * chính là thứ làm cái bẫy này ẩn trước mắt người đi thử lại bằng tay.
 *
 * ## Vì sao nó không phải chuyện thẩm mỹ
 *
 * `PosEffectivePaymentOptionEnricher` lấy `display_name` từ `$option->name` —
 * một thuộc tính DỊCH. Không có bản dịch thì nó rơi về `$option->code`, và thu
 * ngân thấy **`internal.cash.v1`** trên nút thanh toán thay vì
 * "Tiền mặt (sổ nội bộ)".
 *
 * Tệ hơn: bản dịch rỗng làm `display_name_i18n` thành `[]` thay vì `{}`, đủ để
 * giết TOÀN BỘ lượt giải mã feed ở máy trạm và làm POS báo "chưa cấu hình
 * phương thức thanh toán" — xem `EffectivePaymentOptionsI18nShapeTest`.
 *
 * ## Vì sao bài test gói trong `withoutEvents`
 *
 * Đó CHÍNH LÀ điều kiện mà `DatabaseSeeder` tạo ra. Chạy seeder trần ở đây sẽ
 * xanh kể cả khi lỗi còn nguyên — đúng cái bẫy đang được ghim.
 *
 * (`DatabaseSeeder` không chạy được trong suite này: `DashboardSeeder` dựng
 * `order_code` bằng `SUBSTRING_INDEX`, hàm MySQL mà sqlite `:memory:` của
 * `phpunit.xml` không có. Nên đây là phép mô phỏng gần nhất — và nó bắt đúng
 * cơ chế gây lỗi.)
 */
uses()->group('payment');

it('ghi bản dịch cho tuỳ chọn nội bộ NGAY CẢ khi model event bị tắt', function () {
    Model::withoutEvents(function () {
        app(PaymentGatewayCatalogSeeder::class)->seedInternal();
    });

    $cash = PaymentGatewayOption::query()
        ->where('code', PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)
        ->firstOrFail();

    $byLocale = $cash->translations()->pluck('name', 'locale');

    expect($byLocale['vi'] ?? null)->toBe('Tiền mặt (sổ nội bộ)')
        ->and($byLocale['ja'] ?? null)->toBe('現金（内部台帳）')
        ->and($byLocale['en'] ?? null)->toBe('Cash (internal ledger)');
});

it('không rơi về mã slug — thu ngân đọc được tên tiếng người', function () {
    Model::withoutEvents(function () {
        app(PaymentGatewayCatalogSeeder::class)->seedInternal();
    });

    $cash = PaymentGatewayOption::query()
        ->where('code', PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)
        ->firstOrFail();

    // Đây đúng là biểu thức `PosEffectivePaymentOptionEnricher` dùng cho
    // `display_name`. Trước bản sửa nó trả về `internal.cash.v1`.
    app()->setLocale('vi');
    expect((string) ($cash->fresh()->name ?? $cash->code))
        ->toBe('Tiền mặt (sổ nội bộ)')
        ->not->toBe($cash->code);
});

it('chạy lại seeder KHÔNG nhân đôi bản dịch — updateOrCreate, không insert mù', function () {
    Model::withoutEvents(function () {
        app(PaymentGatewayCatalogSeeder::class)->seedInternal();
        app(PaymentGatewayCatalogSeeder::class)->seedInternal();
    });

    $cash = PaymentGatewayOption::query()
        ->where('code', PaymentGatewayCatalogSeeder::INTERNAL_CASH_OPTION_CODE)
        ->firstOrFail();

    expect($cash->translations()->count())->toBe(3);
});

it('nhà cung cấp cũng giữ được tên + mô tả', function () {
    Model::withoutEvents(function () {
        app(PaymentGatewayCatalogSeeder::class)->run();
    });

    $provider = PaymentGatewayProvider::query()->firstOrFail();

    expect($provider->translations()->count())->toBeGreaterThan(0)
        ->and($provider->translations()->whereNull('name')->count())->toBe(0);
});
