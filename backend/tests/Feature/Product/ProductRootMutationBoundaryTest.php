<?php

use App\Exceptions\ProductIdempotencyConflict;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Commands\CreateProductCommand;
use App\Services\Product\Commands\CreateProductOptionCommand;
use App\Services\Product\Commands\CreateProductOptionValueCommand;
use App\Services\Product\Commands\ImportProductsCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ValueObjects\ProductImportPayload;
use App\Services\Product\ValueObjects\ProductImportRow;
use App\Services\Product\ValueObjects\ProductOptionPayload;
use App\Services\Product\ValueObjects\ProductOptionValuePayload;
use App\Services\Product\ValueObjects\ProductPayload;
use App\Services\Product\ValueObjects\ProductSkuPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function rootProductCommand(MutationContext $context, string $productId, string $brandId, ProductPayload $payload): CreateProductCommand
{
    return new CreateProductCommand($context, $productId, $brandId, $payload, $payload->fingerprint());
}

beforeEach(function () {
    // Pin id == console_organization_id. The factory otherwise randomises them,
    // and this suite links the brand by $this->organization->id (a local PK)
    // while `brands` is keyed by console_organization_id. Once plan-047 added
    // assertProductBrandContext — which resolves the org PK to its
    // console_organization_id before matching the brand — that divergence became
    // a hard "brand must belong to the mutation organization" failure. Equal
    // keys are the convention the rest of the org-scoped suite already uses.
    $orgId = (string) Str::uuid();
    $this->organization = Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->organization->id]);
    $this->actor = User::factory()->create(['console_organization_id' => $this->organization->id]);
    $this->type = ProductType::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id]);
    grantOrgAccess($this->actor, $this->organization->id);
    $this->mutations = app(ProductMutationFacade::class);
});

it('force-persists command ids for the complete nested product graph', function () {
    $productId = (string) Str::uuid();
    $optionId = (string) Str::uuid();
    $valueId = (string) Str::uuid();
    $skuId = (string) Str::uuid();
    $payload = new ProductPayload('Coffee', null, [new ProductSkuPayload($skuId, 'COFFEE', 500, [$valueId])], productTypeId: $this->type->id, options: [
        new ProductOptionPayload($optionId, 'Size', [new ProductOptionValuePayload($valueId, 'Small', 'small', 1)], 'size', 1),
    ]);

    $this->mutations->create(rootProductCommand(
        new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid()),
        $productId,
        $this->brand->id,
        $payload,
    ));

    expect(Product::findOrFail($productId)->created_by_id)->toBe($this->actor->id)
        ->and(ProductOption::findOrFail($optionId)->product_id)->toBe($productId)
        ->and(ProductOptionValue::findOrFail($valueId)->option_id)->toBe($optionId)
        ->and(ProductSku::findOrFail($skuId)->option_value1_id)->toBe($valueId);
});

it('replays the same create command id without duplicating the graph', function () {
    $productId = (string) Str::uuid();
    $skuId = (string) Str::uuid();
    $payload = new ProductPayload('Coffee', null, [new ProductSkuPayload($skuId, 'COFFEE', 500)], $this->type->id);
    $context = new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid());

    $first = $this->mutations->create(rootProductCommand($context, $productId, $this->brand->id, $payload));
    $replay = $this->mutations->create(rootProductCommand($context, $productId, $this->brand->id, $payload));

    expect($first->changed)->toBeTrue()->and($first->version)->toBeNull()
        ->and($replay->changed)->toBeFalse()->and($replay->version)->toBeNull()
        ->and(Product::whereKey($productId)->count())->toBe(1)
        ->and(ProductSku::whereKey($skuId)->count())->toBe(1);
});

it('restores exact option and value command ids from tombstones', function () {
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'product_type_id' => $this->type->id]);
    $context = new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid());
    $option = new ProductOptionPayload((string) Str::uuid(), 'Size', [], 'size', 1);
    $optionCommand = new CreateProductOptionCommand($context, $product->id, $this->brand->id, $option, $option->fingerprint());
    $this->mutations->createOption($optionCommand);

    $value = new ProductOptionValuePayload((string) Str::uuid(), 'Small', 'small', 1);
    $valueCommand = new CreateProductOptionValueCommand($context, $option->optionId, $this->brand->id, $value, $value->fingerprint());
    $this->mutations->createOptionValue($valueCommand);
    ProductOptionValue::findOrFail($value->valueId)->delete();
    ProductOption::findOrFail($option->optionId)->delete();

    $optionReplay = $this->mutations->createOption($optionCommand);
    $valueReplay = $this->mutations->createOptionValue($valueCommand);

    expect($optionReplay->changed)->toBeTrue()->and($optionReplay->version)->toBeNull()
        ->and($valueReplay->changed)->toBeTrue()->and($valueReplay->version)->toBeNull()
        ->and(ProductOption::find($option->optionId))->not->toBeNull()
        ->and(ProductOptionValue::find($value->valueId))->not->toBeNull();
});

it('rejects a fresh command id for a tombstoned option natural key', function () {
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'product_type_id' => $this->type->id]);
    $context = new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid());
    $original = new ProductOptionPayload((string) Str::uuid(), 'Size', [], 'size', 1);
    $this->mutations->createOption(new CreateProductOptionCommand($context, $product->id, $this->brand->id, $original, $original->fingerprint()));
    ProductOption::findOrFail($original->optionId)->delete();
    $replacement = new ProductOptionPayload((string) Str::uuid(), 'Size', [], 'size', 1);

    expect(fn () => $this->mutations->createOption(new CreateProductOptionCommand(
        $context,
        $product->id,
        $this->brand->id,
        $replacement,
        $replacement->fingerprint(),
    )))->toThrow(ValidationException::class);

    expect(ProductOption::withTrashed()->where('product_id', $product->id)->where('key', 'size')->value('id'))->toBe($original->optionId)
        ->and(ProductOption::find($original->optionId))->toBeNull();
});

it('rejects reusing a product command id with a different payload', function () {
    $productId = (string) Str::uuid();
    $context = new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid());
    $original = new ProductPayload('Coffee', null, [new ProductSkuPayload((string) Str::uuid(), null, 0)], $this->type->id);
    $this->mutations->create(rootProductCommand($context, $productId, $this->brand->id, $original));
    $different = new ProductPayload('Tea', null, [new ProductSkuPayload((string) Str::uuid(), null, 0)], $this->type->id);

    expect(fn () => $this->mutations->create(rootProductCommand($context, $productId, $this->brand->id, $different)))->toThrow(ProductIdempotencyConflict::class);
    expect(Product::whereKey($productId)->value('name'))->toBe('Coffee');
});

it('rejects replay with the same nested ids but changed nested data', function () {
    $productId = (string) Str::uuid();
    $skuId = (string) Str::uuid();
    $context = new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid());
    $original = new ProductPayload('Coffee', null, [new ProductSkuPayload($skuId, 'COFFEE', 500)], $this->type->id);
    $this->mutations->create(rootProductCommand($context, $productId, $this->brand->id, $original));
    $different = new ProductPayload('Coffee', null, [new ProductSkuPayload($skuId, 'COFFEE', 700)], $this->type->id);

    expect(fn () => $this->mutations->create(rootProductCommand($context, $productId, $this->brand->id, $different)))->toThrow(ProductIdempotencyConflict::class);
    expect(ProductSku::whereKey($skuId)->value('selling_price'))->toBe('500.00');
});

it('replays option and option-value command ids without duplicate writes', function () {
    $product = Product::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->brand->id, 'product_type_id' => $this->type->id]);
    $context = new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid());
    $option = new ProductOptionPayload((string) Str::uuid(), 'Size', [], 'size', 1);
    $optionCommand = new CreateProductOptionCommand($context, $product->id, $this->brand->id, $option, $option->fingerprint());

    expect($this->mutations->createOption($optionCommand)->changed)->toBeTrue()
        ->and($this->mutations->createOption($optionCommand)->changed)->toBeFalse();

    $value = new ProductOptionValuePayload((string) Str::uuid(), 'Small', 'small', 1);
    $valueCommand = new CreateProductOptionValueCommand($context, $option->optionId, $this->brand->id, $value, $value->fingerprint());
    expect($this->mutations->createOptionValue($valueCommand)->changed)->toBeTrue()
        ->and($this->mutations->createOptionValue($valueCommand)->changed)->toBeFalse()
        ->and(ProductOption::whereKey($option->optionId)->count())->toBe(1)
        ->and(ProductOptionValue::whereKey($value->valueId)->count())->toBe(1);
});

it('rejects sibling-brand references before any product graph is written', function () {
    $sibling = Brand::factory()->create(['console_organization_id' => $this->organization->id]);
    $category = Category::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $sibling->id]);
    $productId = (string) Str::uuid();
    $skuId = (string) Str::uuid();
    $payload = new ProductPayload('Coffee', null, [new ProductSkuPayload($skuId, null, 0)], $this->type->id, categoryIds: [$category->id]);

    expect(fn () => $this->mutations->create(rootProductCommand(
        new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid()),
        $productId,
        $this->brand->id,
        $payload,
    )))->toThrow(ValidationException::class);

    expect(Product::find($productId))->toBeNull()->and(ProductSku::find($skuId))->toBeNull();
});

it('rejects a command actor outside the organization before writing', function () {
    $foreign = User::factory()->create();
    $productId = (string) Str::uuid();
    $payload = new ProductPayload('Coffee', null, [new ProductSkuPayload((string) Str::uuid(), null, 0)], $this->type->id);

    expect(fn () => $this->mutations->create(rootProductCommand(
        new MutationContext($this->organization->id, $foreign->id, (string) Str::uuid(), (string) Str::uuid()),
        $productId,
        $this->brand->id,
        $payload,
    )))->toThrow(AuthorizationException::class);

    expect(Product::find($productId))->toBeNull();
});

it('uses the command audit actor without ambient authentication', function () {
    Auth::logout();
    $productId = (string) Str::uuid();
    $payload = new ProductPayload('Coffee', null, [new ProductSkuPayload((string) Str::uuid(), null, 0)], $this->type->id);

    $this->mutations->create(rootProductCommand(
        new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid()),
        $productId,
        $this->brand->id,
        $payload,
    ));

    expect(AuditLog::where('auditable_type', (new Product)->getMorphClass())->where('auditable_id', $productId)->where('action', 'created')->value('user_id'))->toBe($this->actor->id);
});

it('does not leak an ambient actor into an explicit system command', function () {
    Auth::login($this->actor);
    $productId = (string) Str::uuid();
    $payload = new ProductPayload('Coffee', null, [new ProductSkuPayload((string) Str::uuid(), null, 0)], $this->type->id);

    $this->mutations->create(rootProductCommand(
        new MutationContext($this->organization->id, null, (string) Str::uuid(), (string) Str::uuid()),
        $productId,
        $this->brand->id,
        $payload,
    ));

    expect(AuditLog::where('auditable_type', (new Product)->getMorphClass())->where('auditable_id', $productId)->where('action', 'created')->value('user_id'))->toBeNull();
});

it('imports valid rows, isolates validation rejects, and stamps the explicit audit actor', function () {
    Auth::logout();
    $validId = (string) Str::uuid();
    $invalidId = (string) Str::uuid();
    $foreignCategory = Category::factory()->create();
    $valid = new ProductPayload('Imported coffee', null, [new ProductSkuPayload((string) Str::uuid(), 'IMPORTED-COFFEE', 500)], $this->type->id);
    $invalid = new ProductPayload('Invalid coffee', null, [new ProductSkuPayload((string) Str::uuid(), 'INVALID-COFFEE', 500)], $this->type->id, categoryIds: [$foreignCategory->id]);
    $payload = new ProductImportPayload([
        new ProductImportRow(2, $validId, $valid),
        new ProductImportRow(3, $invalidId, $invalid),
    ]);

    $result = $this->mutations->import(new ImportProductsCommand(
        new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid()),
        $this->brand->id,
        'test',
        $payload,
        $payload->fingerprint(),
    ));

    expect($result->rows[0]->imported)->toBeTrue()
        ->and($result->rows[1]->imported)->toBeFalse()
        ->and($result->rows[1]->errorCode)->toBe('CATEGORY_IDS_INVALID')
        ->and(Product::find($validId))->not->toBeNull()
        ->and(Product::find($invalidId))->toBeNull()
        ->and(AuditLog::where('auditable_id', $validId)->where('action', 'created')->value('user_id'))->toBe($this->actor->id);
});

it('rolls back a typed product import during dry run', function () {
    $productId = (string) Str::uuid();
    $rowPayload = new ProductPayload('Preview coffee', null, [new ProductSkuPayload((string) Str::uuid(), 'PREVIEW-COFFEE', 500)], $this->type->id);
    $payload = new ProductImportPayload([new ProductImportRow(2, $productId, $rowPayload)]);

    $result = $this->mutations->import(new ImportProductsCommand(
        new MutationContext($this->organization->id, $this->actor->id, (string) Str::uuid(), (string) Str::uuid()),
        $this->brand->id,
        'test',
        $payload,
        $payload->fingerprint(),
        true,
    ));

    expect($result->rows[0]->imported)->toBeTrue()->and(Product::find($productId))->toBeNull();
});

it('rejects duplicate aggregate ids in a direct product import payload', function () {
    $productId = (string) Str::uuid();
    $first = new ProductPayload('First', null, [new ProductSkuPayload((string) Str::uuid(), 'FIRST', 500)], $this->type->id);
    $second = new ProductPayload('Second', null, [new ProductSkuPayload((string) Str::uuid(), 'SECOND', 500)], $this->type->id);

    expect(fn () => new ProductImportPayload([
        new ProductImportRow(2, $productId, $first),
        new ProductImportRow(3, $productId, $second),
    ]))->toThrow(InvalidArgumentException::class, 'Import product IDs must be unique.');
});
