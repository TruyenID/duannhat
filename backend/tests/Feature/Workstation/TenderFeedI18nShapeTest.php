<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\TillTenderType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Anh em song sinh của `EffectivePaymentOptionsI18nShapeTest`, ở feed thứ hai.
 *
 * PR #2471 vá `display_name_i18n` trong feed effective-payment-options. Nhưng
 * cùng một cái bẫy nằm ở `name_i18n` của feed till-tender-types, và nó CHƯA
 * được vá — cùng hình dạng, cùng nguyên nhân, cùng hậu quả.
 *
 * ## Cùng một cơ chế
 *
 * `sync_pull_pos.go:1740` giải mã trường này vào `map[string]string`:
 *
 *     NameI18n map[string]string `json:"name_i18n"`
 *
 * `json_encode([])` của PHP cho ra `[]`, Go từ chối mảng vào map, và vì cả
 * `resp.Data` là MỘT lượt `Unmarshal`, một hàng hỏng làm hỏng **toàn bộ** lượt
 * giải mã — không phải bỏ qua một hàng.
 *
 * `PullTenderTypes` khi đó trả lỗi trước cả khi vào `p.atomic(...)`, nên
 * `till_tender_types` của máy trạm không bao giờ được nạp. Trên một máy trạm
 * mới, bảng đó ở lại **rỗng**: quầy không có phím tender nào để bấm.
 *
 * ## Ca kích hoạt lại là ca MẶC ĐỊNH, và mã nguồn đã biết trước
 *
 * `TillController::tenderTypes` xử lý sẵn ca không có bản dịch ngay hai dòng
 * phía trên chỗ phát trường này:
 *
 *     $fallbackName = $translations === [] ? null : reset($translations);
 *
 * Tức tập rỗng là trạng thái đã được lường trước và coi là hợp lệ. Nó chỉ chưa
 * bao giờ được lường ở tầng JSON.
 *
 * Và nó xảy ra thật: `TillTenderTypeSeeder` `use WithoutModelEvents`, mà
 * Astrotomic bền hoá bản dịch trong hook `saved` — nên mọi `name:ja/en/vi` của
 * seeder bị vứt lặng lẽ và mọi tender vừa seed ra đều KHÔNG có bản dịch. Đây
 * chính là lỗi #4 mà PR này mô tả, chỉ khác là ở seeder mà PR không đụng tới.
 *
 * ## Vì sao phải đo trên chuỗi JSON THÔ
 *
 * `$response->json()` giải mã xong thì `[]` và `{}` đều thành `array` rỗng của
 * PHP — tức nó xoá đúng cái khác biệt duy nhất cần đo. Chỉ có `getContent()`
 * mới còn giữ dấu ngoặc.
 */
uses()->group('workstation', 'pos');

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->rawFeed = fn (): string => $this->withHeaders([
        'Authorization' => 'Bearer '.$this->wsToken,
    ])->getJson('/api/v1/workstation/till-tender-types')->assertOk()->getContent();

    /** Một tender KHÔNG có bản dịch nào — trạng thái sau mỗi lượt seed đầy đủ. */
    $this->untranslatedTender = function (string $key = 'credit'): TillTenderType {
        $tender = TillTenderType::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => null,
            'tender_key' => $key,
            'category' => 'card',
            'is_active' => true,
        ]);
        DB::table('till_tender_type_translations')
            ->where('till_tender_type_id', $tender->id)
            ->delete();

        return $tender->fresh();
    };
});

it('phát `name_i18n` là OBJECT rỗng, không phải mảng rỗng — một dấu ngoặc giết cả feed', function () {
    ($this->untranslatedTender)();

    $raw = ($this->rawFeed)();

    expect($raw)->toContain('"name_i18n":{}')
        ->and($raw)->not->toContain('"name_i18n":[]');
});

it('một hàng không dịch làm hỏng CẢ lượt giải mã, không phải riêng hàng đó', function () {
    // Đây là điều khiến lỗi đắt hơn vẻ ngoài của nó. Go `Unmarshal` cả
    // `resp.Data` một lượt, nên một hàng sai kiểu không "rơi mất một tender" —
    // nó ném bỏ mọi tender khác cùng feed, kể cả những hàng dịch đầy đủ.
    $translated = ($this->untranslatedTender)('cash');
    DB::table('till_tender_type_translations')->insert([
        'till_tender_type_id' => $translated->id,
        'locale' => 'ja',
        'name' => '現金',
    ]);
    ($this->untranslatedTender)('credit');

    $raw = ($this->rawFeed)();

    expect($raw)->not->toContain('"name_i18n":[]');
    // Hàng có dịch vẫn phải nguyên vẹn — bản vá không được đánh đổi nó.
    // Nội dung kiểm SAU khi giải mã: `json_encode` thoát 現金 thành
    // `現金`, nên so trên chuỗi thô sẽ trượt vì lý do không liên quan
    // gì tới thứ đang đo. Chỉ dấu ngoặc mới cần chuỗi thô.
    $rows = collect(json_decode($raw, true)['data'])->keyBy('tender_key');
    expect($rows['cash']['name_i18n'])->toBe(['ja' => '現金'])
        ->and($rows['credit']['name_i18n'])->toBe([]);
});

it('có bản dịch thì vẫn là object bình thường — bản vá chỉ chạm ca rỗng', function () {
    $tender = ($this->untranslatedTender)();
    foreach (['ja' => 'クレジット', 'en' => 'Credit', 'vi' => 'Tín dụng'] as $locale => $name) {
        DB::table('till_tender_type_translations')->insert([
            'till_tender_type_id' => $tender->id,
            'locale' => $locale,
            'name' => $name,
        ]);
    }

    $raw = ($this->rawFeed)();

    expect($raw)->not->toContain('"name_i18n":[]');
    expect(json_decode($raw, true)['data'][0]['name_i18n'])
        ->toBe(['en' => 'Credit', 'ja' => 'クレジット', 'vi' => 'Tín dụng']);
});

it('bản dịch RỖNG bị lọc ra vẫn phải cho object, không phải mảng', function () {
    // Đường tinh vi hơn: hàng dịch CÓ tồn tại nhưng `name` rỗng, nên
    // `translationsOf` lọc sạch và trả về `[]` — cùng đích đến, khác lối vào.
    // Ép kiểu đặt sai chỗ (ví dụ chỉ khi `translations` rỗng thay vì khi KẾT
    // QUẢ rỗng) sẽ lọt đúng ca này.
    $tender = ($this->untranslatedTender)();
    DB::table('till_tender_type_translations')->insert([
        'till_tender_type_id' => $tender->id,
        'locale' => 'ja',
        'name' => '',
    ]);

    $raw = ($this->rawFeed)();

    expect($raw)->toContain('"name_i18n":{}')
        ->and($raw)->not->toContain('"name_i18n":[]');
});

it('không có tender nào → `data` là MẢNG rỗng, và đó là đúng', function () {
    // Đối trọng của các bài trên, để bản vá không bị áp nhầm chỗ. Go giải mã
    // `Data` vào `[]struct{...}`, nên ở ĐÂY `[]` mới là hình dạng đúng và `{}`
    // sẽ là thứ làm hỏng giải mã. "Luôn phát object" là một luật sai; luật đúng
    // là "khớp kiểu bên Go nhận".
    $raw = ($this->rawFeed)();

    expect($raw)->toContain('"data":[]');
});
