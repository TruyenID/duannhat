<?php

use App\Exceptions\ProductIdempotencyConflict;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Commands\CreateCategoryCommand;
use App\Services\Product\Commands\CreateProductSkuCommand;
use App\Services\Product\Commands\CreateProductTypeCommand;
use App\Services\Product\Commands\CreateVariantUnitCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ValueObjects\CategoryPayload;
use App\Services\Product\ValueObjects\ProductSkuPayload;
use App\Services\Product\ValueObjects\ProductTypePayload;
use App\Services\Product\ValueObjects\VariantUnitPayload;
use Illuminate\Support\Str;

/*
 * #1744 — id do NGƯỜI GỌI cấp phải là id được ghi xuống.
 *
 * ## Tính chất, không phải danh sách bảng
 *
 * Mọi lệnh CREATE của plan-047 mang sẵn id: client sinh uuid, gửi lệnh, và nếu
 * mạng đứt thì gửi LẠI cùng uuid ấy — lượt hai phải trúng hàng cũ, không tạo
 * hàng thứ hai. Toàn bộ tính chống-trùng nằm ở chỗ id được tôn trọng.
 *
 * Mọi model ở đây đều `guarded = ['*']` và KHÔNG có `id` trong `$fillable`, nên
 * `Model::create(['id' => …])` lặng lẽ bỏ khoá đó và sinh uuid mới. Không
 * exception, `MutationResult.changed = true`, mọi kiểm tra hình dạng đều xanh —
 * lượt gửi lại tạo bản ghi trùng, và `MutationResult` trả về một id người gọi
 * chưa từng thấy.
 *
 * ## Vì sao là test HÀNH VI chứ không phải máy quét tĩnh
 *
 * Bản đầu của rào này là một máy quét AST tìm `->create(['id' => …])` nằm ngoài
 * vùng `unguarded`. Nó báo 8 chỗ. Kiểm từng chỗ thì **quá nửa là dương tính
 * giả**: `EloquentProductPersistence::create()` (hàm cục bộ) `forceFill` id ngay
 * sau `fill`, và các service option/value/sku cũng vậy — máy quét không đọc
 * được xuyên qua một lời gọi hàm. Một rào báo 8 chỗ mà 5 chỗ không phải lỗi thì
 * lần sau người ta bỏ qua nó.
 *
 * Tính chất "id trả về == id yêu cầu, và hàng nằm đúng ở id đó" thì kiểm được
 * mà không cần biết đường ghi bên dưới trông thế nào.
 */

beforeEach(function () {
    $orgId = (string) Str::uuid();
    $this->organization = Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->organization->id]);
    $this->actor = User::factory()->create(['console_organization_id' => $this->organization->id]);
    grantOrgAccess($this->actor, $this->organization->id);
    $this->mutations = app(ProductMutationFacade::class);
    $this->context = new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid());
});

it('#1744 createProductType ghi ĐÚNG id của lệnh', function () {
    $id = (string) Str::uuid();
    $payload = new ProductTypePayload('Đồ uống', null, null, 'physical', false, true, null, true);

    $result = $this->mutations->createProductType(
        new CreateProductTypeCommand($this->context, $id, $this->brand->id, $payload, $payload->fingerprint()),
    );

    expect($result->aggregateId)->toBe($id)
        ->and(ProductType::find($id))->not->toBeNull();
});

it('#1744 createCategory ghi ĐÚNG id của lệnh', function () {
    $id = (string) Str::uuid();
    $payload = new CategoryPayload('Cà phê', null, null, null, null, true);

    $result = $this->mutations->createCategory(
        new CreateCategoryCommand($this->context, $id, $this->brand->id, $payload, $payload->fingerprint()),
    );

    expect($result->aggregateId)->toBe($id)
        ->and(Category::find($id))->not->toBeNull();
});

it('#1744 createSku ĐỘC LẬP ghi đúng id của lệnh', function () {
    $type = ProductType::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'product_type_id' => $type->id]);
    $id = (string) Str::uuid();
    $payload = new ProductSkuPayload($id, 'SKU-'.Str::upper(Str::random(6)), 500);

    $result = $this->mutations->createSku(
        new CreateProductSkuCommand($this->context, $product->id, $this->brand->id, $payload, $payload->fingerprint()),
    );

    expect($result->aggregateId)->toBe($id)
        ->and(ProductSku::find($id))->not->toBeNull();
});

it('#1744 createVariantUnit ghi ĐÚNG id của lệnh', function () {
    $type = ProductType::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'product_type_id' => $type->id]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);
    $id = (string) Str::uuid();
    $payload = new VariantUnitPayload('thùng', '24', 'VU-'.Str::upper(Str::random(6)), null, '12000', false, true);

    $result = $this->mutations->createVariantUnit(
        new CreateVariantUnitCommand($this->context, $id, $sku->id, $this->brand->id, $payload, $payload->fingerprint()),
    );

    expect($result->aggregateId)->toBe($id)
        ->and(VariantUnit::find($id))->not->toBeNull();
});

it('#1744 gửi LẠI cùng một lệnh không tạo hàng thứ hai', function () {
    // Đây mới là lý do id-do-người-gọi-cấp tồn tại. Nếu id bị rơi thì hai lượt
    // gọi sinh hai uuid khác nhau và test này thấy 2 hàng — nên nó là phép đo
    // trực tiếp của hệ quả, không phải của cách cài đặt.
    $id = (string) Str::uuid();
    $payload = new ProductTypePayload('Đồ uống', null, null, 'physical', false, true, null, true);
    $command = new CreateProductTypeCommand($this->context, $id, $this->brand->id, $payload, $payload->fingerprint());

    $first = $this->mutations->createProductType($command);
    $second = $this->mutations->createProductType($command);

    // KHÔNG đếm tổng số product type của brand: `Brand::factory()` tự dựng sẵn
    // một cái, nên phép đếm đó bằng 2 kể cả khi lượt gửi lại hoàn toàn đúng —
    // bản đầu của test này đỏ vì lý do ấy, không phải vì mã sai.
    expect(ProductType::query()->whereKey($id)->count())->toBe(1)
        ->and($first->aggregateId)->toBe($id)
        ->and($second->aggregateId)->toBe($id)
        ->and($first->changed)->toBeTrue()
        ->and($second->changed)->toBeFalse();
});

it('#1744 đo lượt GỬI LẠI của từng lệnh — chỗ nào idempotent, chỗ nào ném', function () {
    $type = ProductType::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'product_type_id' => $type->id]);
    // Sản phẩm RIÊNG cho lượt tạo SKU: `$sku` bên dưới là SKU của biến thể, và
    // nếu đặt chung sản phẩm thì `createSku` ném ngay ở lượt ĐẦU vì trùng tổ hợp
    // tuỳ chọn rỗng — bản đầu của phép đo này mắc đúng lỗi đó và báo nhầm rằng
    // đường gửi-lại của SKU hỏng.
    $skuProduct = Product::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'product_type_id' => $type->id]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    $ptPayload = new ProductTypePayload('X', null, null, 'physical', false, true, null, true);
    $catPayload = new CategoryPayload('Y', null, null, null, null, true);
    $skuPayload = new ProductSkuPayload((string) Str::uuid(), 'S-'.Str::upper(Str::random(6)), 500);
    $vuPayload = new VariantUnitPayload('thùng', '24', 'V-'.Str::upper(Str::random(6)), null, '12000', false, true);

    // MỘT command cho mỗi lệnh, gửi HAI lần — đúng kịch bản mạng đứt rồi gửi
    // lại. Bản đầu của phép đo này dựng command trong closure nên mỗi lượt gọi
    // sinh uuid MỚI: nó đo hai lệnh khác nhau và báo "changed=true" cho cả bốn,
    // tức trả lời "có" cho một câu chưa từng hỏi.
    $commands = [
        'createProductType' => [fn ($c) => $this->mutations->createProductType($c), new CreateProductTypeCommand($this->context, (string) Str::uuid(), $this->brand->id, $ptPayload, $ptPayload->fingerprint())],
        'createCategory' => [fn ($c) => $this->mutations->createCategory($c), new CreateCategoryCommand($this->context, (string) Str::uuid(), $this->brand->id, $catPayload, $catPayload->fingerprint())],
        'createSku' => [fn ($c) => $this->mutations->createSku($c), new CreateProductSkuCommand($this->context, $skuProduct->id, $this->brand->id, $skuPayload, $skuPayload->fingerprint())],
        'createVariantUnit' => [fn ($c) => $this->mutations->createVariantUnit($c), new CreateVariantUnitCommand($this->context, (string) Str::uuid(), $sku->id, $this->brand->id, $vuPayload, $vuPayload->fingerprint())],
    ];

    $report = [];
    foreach ($commands as $name => [$send, $command]) {
        try {
            $send($command);
            $second = $send($command);
            $report[$name] = $second->changed ? 'GHI LẠI (changed=true)' : 'idempotent (changed=false)';
        } catch (Throwable $e) {
            $report[$name] = 'NÉM: '.class_basename($e).' — '.mb_substr($e->getMessage(), 0, 120);
        }
    }

    expect($report)->toBe([
        'createProductType' => 'idempotent (changed=false)',
        'createCategory' => 'idempotent (changed=false)',
        'createSku' => 'idempotent (changed=false)',
        'createVariantUnit' => 'idempotent (changed=false)',
    ]);
});

it('#1744 cùng id nhưng KHÁC nội dung thì báo xung đột, không nuốt', function () {
    // Idempotent không có nghĩa là "im lặng chấp nhận mọi thứ". Cùng id mà khác
    // payload là lỗi phía gọi; nuốt nó đi thì lượt sửa của client biến mất mà
    // không ai biết.
    $id = (string) Str::uuid();
    $first = new ProductTypePayload('Đồ uống', null, null, 'physical', false, true, null, true);
    $second = new ProductTypePayload('Món ăn', null, null, 'physical', false, true, null, true);

    $this->mutations->createProductType(new CreateProductTypeCommand($this->context, $id, $this->brand->id, $first, $first->fingerprint()));

    expect(fn () => $this->mutations->createProductType(
        new CreateProductTypeCommand($this->context, $id, $this->brand->id, $second, $second->fingerprint()),
    ))->toThrow(ProductIdempotencyConflict::class);
});
