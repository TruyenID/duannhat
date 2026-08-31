<?php

declare(strict_types=1);

use App\Architecture\DomainMutationFinding;
use App\Architecture\DomainMutationGuard;
use Symfony\Component\Process\Process;

function domainGuardConfig(): array
{
    return [
        'product' => [
            'models' => ['Category', 'Product', 'ProductSku', 'ProductTranslation', 'VariantUnit'],
            'tables' => ['products', 'product_skus', 'product_category', 'variant_units'],
            'boundaries' => ['app/Services/Product/Persistence/ProductPersistence.php'],
        ],
        'order' => [
            'models' => ['CustomerOrder', 'OrderCodeCounter'],
            'tables' => ['customer_orders', 'order_code_counters'],
            'boundaries' => [],
        ],
        'menu' => [
            'models' => ['Menu', 'MenuPromotion', 'MenuPromotionTranslation'],
            'tables' => ['menus', 'menu_menu_sections', 'menu_promotion_category', 'menu_promotion_product'],
            'boundaries' => [],
        ],
        'customer' => [
            'models' => ['Customer'],
            'tables' => ['customers'],
            'boundaries' => [],
        ],
        'payment' => [
            // PaymentMethod is one of the few modules whose Omnify service base
            // still ships (service.enable: false killed most of them), so it is
            // the only realistic fixture for generated-service consumer checks —
            // referencing a dead module's base would trip DeadOmnifyServicesArchTest.
            'models' => ['OrderPayment', 'PaymentMethod', 'PaymentMethodTranslation'],
            'tables' => ['order_payments'],
            'boundaries' => [],
        ],
    ];
}

function domainGuard(): DomainMutationGuard
{
    return new DomainMutationGuard(domainGuardConfig());
}

function domainFindingSignature(DomainMutationFinding $finding): string
{
    return implode('|', [$finding->kind, $finding->symbol, $finding->target, $finding->site]);
}

it('attributes a model writer to every protected aggregate with exact evidence', function (string $aggregate, string $model) {
    $source = str_replace(['MODEL', 'PATH'], [$model, ucfirst($aggregate)], <<<'PHP'
        <?php
        namespace App\Fixture;
        use App\Models\MODEL;
        final class PATHWriter {
            public function run(): void { MODEL::create([]); }
        }
        PHP);
    $findings = scanDomainFixture(["app/Fixture/{$model}Writer.php" => $source]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->aggregate)->toBe($aggregate)
        ->and($findings[0]->path)->toBe("app/Fixture/{$model}Writer.php")
        ->and($findings[0]->line)->toBe(5)
        ->and($findings[0]->symbol)->toBe('create')
        ->and($findings[0]->target)->toBe($model);
})->with([
    'product' => ['product', 'Product'],
    'menu' => ['menu', 'Menu'],
    'customer' => ['customer', 'Customer'],
    'order' => ['order', 'CustomerOrder'],
    'payment' => ['payment', 'OrderPayment'],
]);

function scanDomainFixture(array $files): array
{
    $root = sys_get_temp_dir().'/domain-guard-'.bin2hex(random_bytes(8));

    // #3027 — nói ra NGAY nếu không dựng được thư mục tạm. Bản cũ không kiểm,
    // nên một lượt `mkdir` hỏng chỉ lộ ra ba bước sau, dưới một cái tên khác
    // hẳn (`UnexpectedValueException` từ khối dọn dẹp).
    if (! mkdir($root.'/app', 0777, true) && ! is_dir($root.'/app')) {
        throw new RuntimeException("không dựng được thư mục tạm cho fixture: {$root}/app");
    }

    foreach ($files as $path => $contents) {
        $absolute = $root.'/'.$path;
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, $contents);
    }

    try {
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $guard = new App\Architecture\DomainMutationGuard(json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR));
            echo json_encode(array_map(fn ($finding) => $finding->toArray(), $guard->scan($argv[2])), JSON_THROW_ON_ERROR);
            PHP;
        $process = new Process([
            PHP_BINARY,
            '-r',
            $script,
            json_encode(domainGuardConfig(), JSON_THROW_ON_ERROR),
            $root,
        ], dirname(__DIR__, 3));
        $process->mustRun();

        return array_map(
            fn (array $finding) => new DomainMutationFinding(...array_values($finding)),
            json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        );
    } finally {
        removeFixtureTree($root);
    }
}

/**
 * Dọn cây fixture tạm. Hàm RIÊNG vì nó phải test được thẳng (#3027).
 *
 * **DỌN DẸP KHÔNG ĐƯỢC LÀ THỨ BÁO LỖI.** Ngoại lệ ném từ `finally` **thay thế**
 * ngoại lệ đang bay. Bản cũ mở `RecursiveDirectoryIterator($root)` vô điều
 * kiện, nên khi `$root` không còn thì `ProcessFailedException` — thứ mang
 * stderr của tiến trình con, tức nguyên nhân THẬT — bị vứt đi và người đọc log
 * nhận một câu về thư mục tạm.
 *
 * Đã xảy ra: `arch-gate` đỏ trên PR #3018, stack trỏ vào `Process.php:295`
 * trong khi loại ngoại lệ báo ra lại là `UnexpectedValueException`. Phải chạy
 * lại CI mới phân biệt được flake với hỏng thật.
 *
 * Không có gì để dọn thì IM — đường này chỉ dọn rác, nó không có tư cách phát
 * biểu về lượt chạy.
 */
function removeFixtureTree(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

it('detects static and inferred instance model mutations', function () {
    $findings = scanDomainFixture(['app/Http/Writer.php' => <<<'PHP'
        <?php
        namespace App\Http;
        use App\Models\Product;
        final class Writer {
            public function run(Product $product): void {
                Product::create([]);
                $product->save();
            }
        }
        PHP]);

    expect(array_column($findings, 'symbol'))->toEqualCanonicalizing(['create', 'save'])
        ->and(array_unique(array_column($findings, 'kind')))->toBe(['model']);
});

it('tracks duplicate same-shaped writers by independent call site', function () {
    $findings = scanDomainFixture(['app/DuplicateWriter.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        Product::create([]);
        Product::create([]);
        PHP]);
    $entry = [
        'aggregate' => 'product',
        'path' => 'app/DuplicateWriter.php',
        'signatures' => [domainFindingSignature($findings[0]) => 1],
        'owner' => 'plan-047',
        'removal_task' => 'T2.9/T4.12',
        'expires_at_gate' => 4,
        'reason' => 'Legacy duplicate fixture.',
    ];

    $underCounted = domainGuard()->compare($findings, [$entry]);
    $entry['signatures'][domainFindingSignature($findings[1])] = 1;
    $exact = domainGuard()->compare($findings, [$entry]);

    expect($findings)->toHaveCount(2)
        ->and(array_column($findings, 'line'))->toBe([3, 4])
        ->and($underCounted['known'])->toHaveCount(1)
        ->and($underCounted['new'])->toHaveCount(1)
        ->and($exact['known'])->toHaveCount(2)
        ->and($exact['new'])->toBe([]);
});

it('detects every required Laravel model and query mutator', function () {
    $instanceMutators = [
        'createMany', 'createManyQuietly', 'saveMany', 'saveManyQuietly', 'createQuietly',
        'createOrFirst', 'forceCreateMany', 'forceCreateManyQuietly', 'forceCreateQuietly', 'updateOrFail',
        'updateQuietly', 'saveOrFail', 'push', 'pushQuietly', 'deleteOrFail',
        'forceDeleteQuietly', 'restoreQuietly', 'incrementOrCreate', 'incrementQuietly',
        'decrementQuietly', 'incrementEach', 'decrementEach', 'touchQuietly', 'markEmailAsVerified',
        'attachOrFail', 'detachOrFail', 'syncOrFail', 'syncWithoutDetachingOrFail',
        'syncWithPivotValuesOrFail', 'toggleOrFail', 'updateExistingPivotOrFail',
    ];
    $queryMutators = ['updateOrInsert', 'insertUsing', 'insertOrIgnoreUsing'];
    $body = implode("\n", array_map(static fn (string $method): string => "        \$product->{$method}([]);", $instanceMutators));
    $body .= "\n".implode("\n", array_map(static fn (string $method): string => "        Product::query()->{$method}([]);", $queryMutators));
    $source = <<<PHP
        <?php
        namespace App\Fixture;
        use App\Models\Product;
        final class Writer {
            public function run(Product \$product): void {
        {$body}
            }
        }
        PHP;

    $findings = scanDomainFixture(['app/Fixture/Writer.php' => $source]);

    expect(array_column($findings, 'symbol'))->toEqualCanonicalizing([...$instanceMutators, ...$queryMutators]);
});

it('detects force create many relationship variants', function () {
    $findings = scanDomainFixture(['app/RelationshipWriter.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        function write(Product $product): void {
            $product->skus()->forceCreateMany([]);
            $product->skus()->forceCreateManyQuietly([]);
            $product->skus()->createManyQuietly([]);
        }
        PHP]);

    expect(array_column($findings, 'kind'))->toBe(['relationship', 'relationship', 'relationship'])
        ->and(array_column($findings, 'symbol'))->toEqualCanonicalizing([
            'forceCreateMany', 'forceCreateManyQuietly', 'createManyQuietly',
        ]);
});

it('emits every possible aggregate for a conditional model union', function () {
    $findings = scanDomainFixture(['app/ConditionalWriter.php' => <<<'PHP'
        <?php
        use App\Models\Menu;
        use App\Models\Product;
        function write(bool $flag, Product $product, Menu $menu): void {
            $selected = $flag ? $product : $menu;
            $selected->save();
        }
        PHP]);

    expect($findings)->toHaveCount(2)
        ->and(array_column($findings, 'aggregate'))->toEqualCanonicalizing(['product', 'menu'])
        ->and(array_unique(array_column($findings, 'symbol')))->toBe(['save']);
});

it('does not mistake in-memory collection mutations for database writes', function () {
    $findings = scanDomainFixture(['app/CollectionWriter.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        function collect(Product $product): void {
            Product::query()->get()->push($product);
            Product::all()->push($product);
            Product::query()->pluck('id')->push('id');
        }
        PHP]);

    expect($findings)->toBe([]);
});

it('tracks model extraction and higher order writes from Eloquent collections', function () {
    $findings = scanDomainFixture(['app/CollectionItemWriter.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        function mutateCollectionItems(): void {
            Product::all()->get('id')->update([]);
            Product::all()->first()->forceDelete();
            Product::all()->last()->delete();
            Product::all()->pop()->save();
            Product::all()->shift()->touch();
            Product::all()->random()->restore();
            Product::all()->find('id')->saveQuietly();
            Product::all()->each->delete();
            Product::all()->map->save();
        }
        PHP]);

    expect($findings)->toHaveCount(9)
        ->and(array_unique(array_column($findings, 'aggregate')))->toBe(['product'])
        ->and(array_column($findings, 'symbol'))->toEqualCanonicalizing([
            'update', 'forceDelete', 'delete', 'save', 'touch', 'restore', 'saveQuietly', 'delete', 'save',
        ]);
});

it('tracks a sole collection item mutation without flagging a sole read', function () {
    $findings = scanDomainFixture(['app/SoleCollectionWriter.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        function mutateSoleItem(): void {
            Product::all()->sole()->delete();
            $product = Product::all()->sole();
            $name = $product->name;
        }
        PHP]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->aggregate)->toBe('product')
        ->and($findings[0]->kind)->toBe('model')
        ->and($findings[0]->symbol)->toBe('delete');
});

it('inventories framework email verification as a customer mutation', function () {
    $findings = scanDomainFixture(['app/Http/CustomerAuthController.php' => <<<'PHP'
        <?php
        use App\Models\Customer;
        final class CustomerAuthController {
            public function verify(string $id): void {
                $customer = Customer::findOrFail($id);
                $customer->markEmailAsVerified();
            }
        }
        PHP]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->aggregate)->toBe('customer')
        ->and($findings[0]->kind)->toBe('model')
        ->and($findings[0]->symbol)->toBe('markEmailAsVerified');
});

it('binds allowlisted writers to formatting-stable structural call sites', function () {
    $original = scanDomainFixture(['app/Writer.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        final class Writer {
            public function run(Product $product): void {
                $product->save();
                $unrelated = true;
            }
        }
        PHP]);
    $formatted = scanDomainFixture(['app/Writer.php' => <<<'PHP'
        <?php

        use App\Models\Product;

        final class Writer
        {
            public function run(Product $product): void
            {
                // Formatting and comments do not redefine this call site.
                $product -> save( );
                $unrelated = true;
            }
        }
        PHP]);
    $moved = scanDomainFixture(['app/Writer.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        final class Writer {
            public function run(Product $product): void {
                $unrelated = true;
                $product->save();
            }
        }
        PHP]);
    $replaced = scanDomainFixture(['app/Writer.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        final class Writer {
            public function __construct(private Product $product) {}
            public function run(Product $product): void {
                $this->product->save();
                $unrelated = true;
            }
        }
        PHP]);
    $entry = [
        'aggregate' => 'product',
        'path' => 'app/Writer.php',
        'signatures' => [domainFindingSignature($original[0]) => 1],
        'owner' => 'plan-047',
        'removal_task' => 'T2.9',
        'expires_at_gate' => 4,
        'reason' => 'Structural identity fixture.',
    ];

    expect($formatted[0]->site)->toBe($original[0]->site)
        ->and(domainGuard()->compare($formatted, [$entry])['new'])->toBe([])
        ->and(domainGuard()->compare($moved, [$entry])['new'])->toHaveCount(1)
        ->and(domainGuard()->compare($moved, [$entry])['stale'])->toHaveCount(1)
        ->and(domainGuard()->compare($replaced, [$entry])['new'])->toHaveCount(1)
        ->and(domainGuard()->compare($replaced, [$entry])['stale'])->toHaveCount(1);
});

it('binds nested closure writers to their outer method context', function () {
    $firstMethod = scanDomainFixture(['app/ClosureWriter.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        final class ClosureWriter {
            public function first(): void {
                $callback = function (): void { Product::create([]); };
            }
            public function second(): void {}
        }
        PHP]);
    $secondMethod = scanDomainFixture(['app/ClosureWriter.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        final class ClosureWriter {
            public function first(): void {}
            public function second(): void {
                $callback = function (): void { Product::create([]); };
            }
        }
        PHP]);
    $entry = [
        'aggregate' => 'product',
        'path' => 'app/ClosureWriter.php',
        'signatures' => [domainFindingSignature($firstMethod[0]) => 1],
        'owner' => 'plan-047',
        'removal_task' => 'T2.9',
        'expires_at_gate' => 4,
        'reason' => 'Nested closure identity fixture.',
    ];
    $comparison = domainGuard()->compare($secondMethod, [$entry]);

    expect($secondMethod[0]->site)->not->toBe($firstMethod[0]->site)
        ->and($comparison['new'])->toHaveCount(1)
        ->and($comparison['stale'])->toHaveCount(1);
});

it('tracks collection lookups coalescing array slots and quiet restore flows', function () {
    $findings = scanDomainFixture(['app/Importers/LegacyImporters.php' => <<<'PHP'
        <?php
        namespace App\Importers;
        use App\Models\Category;
        use App\Models\Product;
        use App\Models\ProductSku;
        final class ProductImporter {
            private $products;
            public function load(): void { $this->products = Product::query()->get()->keyBy('code'); }
            public function import(Product $fallback): void {
                $product = $this->products->get('sku') ?? $fallback;
                $product->update([]);
                $product->categories()->sync([]);
            }
        }
        final class CategoryImporter {
            private array $processed = [];
            public function process(): void {
                $categories = Category::withTrashed()->get();
                foreach ($categories as $category) { $this->processed[] = ['category' => $category]; }
                foreach ($this->processed as $item) { $item['category']->update([]); }
            }
        }
        final class ProductSkuService {
            private $deleted;
            public function load(): void { $this->deleted = ProductSku::onlyTrashed()->get()->keyBy('signature'); }
            public function restore(): void {
                $sku = $this->deleted['signature'];
                $sku->restoreQuietly();
                $sku->saveQuietly();
            }
        }
        PHP]);

    expect(array_column($findings, 'symbol'))->toEqualCanonicalizing([
        'update', 'sync', 'update', 'restoreQuietly', 'saveQuietly',
    ]);
});

it('keeps product csv transport behind the typed bulk mutation command', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/app/Services/Import/ProductImporter.php');

    expect($source)->toContain('ImportProductsCommand', '$this->mutations->import(')
        ->not->toContain('CreateProductCommand', 'ReviseProductCommand', '$this->mutations->create(', '$this->mutations->revise(', 'DB::beginTransaction');
});

it('detects nullsafe writes and container resolved generated services', function () {
    $findings = scanDomainFixture(['app/Http/NullsafeWriter.php' => <<<'PHP'
        <?php
        namespace App\Http;
        use App\Models\Product;
        use App\Omnify\Modules\PaymentMethod\Services\PaymentMethodServiceBase as Methods;
        final class NullsafeWriter {
            public function run(?Product $product): void {
                $product?->save();
                app(Methods::class)?->create([]);
                resolve(\App\Services\Omnify\ProductService::class)->delete('id');
            }
            public function dynamic(string $service): void { app($service)->create([]); }
        }
        PHP]);

    expect(array_column($findings, 'kind'))->toEqualCanonicalizing([
        'model', 'generated_service_consumer', 'generated_service_consumer', 'unknown',
    ])->and(array_column($findings, 'symbol'))->toEqualCanonicalizing(['save', 'create', 'delete', 'create']);
});

it('detects writes through DB query builders without an explicit table call', function () {
    $findings = scanDomainFixture(['app/Jobs/ConnectionQueryWriter.php' => <<<'PHP'
        <?php
        namespace App\Jobs;
        use Illuminate\Support\Facades\DB;
        final class ConnectionQueryWriter {
            public function run(string $table): void {
                DB::query()->from('products')->update([]);
                DB::connection('tenant')->query()->from('customer_orders')->delete();
                DB::query()->from($table)->insert([]);
            }
        }
        PHP]);

    expect(array_column($findings, 'kind'))->toEqualCanonicalizing(['query_builder', 'query_builder', 'unknown'])
        ->and(array_column($findings, 'symbol'))->toEqualCanonicalizing(['update', 'delete', 'insert']);
});

it('discovers application services inheriting generated CRUD bases', function () {
    $findings = scanDomainFixture([
        'app/Services/Payment/ShopPaymentMethodService.php' => <<<'PHP'
            <?php
            namespace App\Services\Payment;
            use App\Omnify\Modules\PaymentMethod\Services\PaymentMethodServiceBase;
            final class ShopPaymentMethodService extends PaymentMethodServiceBase {}
            PHP,
        'app/Http/PaymentMethodController.php' => <<<'PHP'
            <?php
            namespace App\Http;
            use App\Services\Payment\ShopPaymentMethodService;
            final class PaymentMethodController {
                public function __construct(private ShopPaymentMethodService $service) {}
                public function store(): void { $this->service->create([]); }
                public function destroy(): void { $this->service->forceDelete('id'); }
            }
            PHP,
    ]);

    expect($findings)->toHaveCount(2)
        ->and(array_unique(array_column($findings, 'kind')))->toBe(['generated_service_consumer'])
        ->and(array_column($findings, 'symbol'))->toEqualCanonicalizing(['create', 'forceDelete']);
});

it('retains protected origins across conditional assignments setters and array slots', function () {
    $findings = scanDomainFixture(['app/Services/ConservativeWriter.php' => <<<'PHP'
        <?php
        namespace App\Services;
        use App\Models\Product;
        final class ConservativeWriter {
            private $product;
            public function setProduct(Product $product): void { $this->product = $product; }
            public function acceptUnknown(object $dto): void { $this->product = $dto; }
            public function write(bool $replace, object $dto, Product $product): void {
                if ($replace) { $product = $dto; }
                $product->save();
                $items = [];
                $items[] = $this->product;
                $items[0]->touchQuietly();
                $selected = $replace ? $product : $this->product;
                $selected->saveQuietly();
            }
        }
        PHP]);

    expect(array_column($findings, 'symbol'))->toEqualCanonicalizing(['save', 'touchQuietly', 'saveQuietly']);
});

it('detects raw mutations issued through an explicit DB connection', function () {
    $findings = scanDomainFixture(['app/ConnectionWriter.php' => <<<'PHP'
        <?php
        use Illuminate\Support\Facades\DB;
        DB::connection('tenant')->insert('INSERT INTO products (id) VALUES (?)', ['x']);
        DB::connection()->update('UPDATE products SET name = ?', ['x']);
        DB::connection()->delete('DELETE FROM products');
        DB::connection()->statement('UPDATE products SET name = ?', ['x']);
        DB::connection()->unprepared('DELETE FROM products');
        DB::connection()->affectingStatement('UPDATE products SET name = ?', ['x']);
        PHP]);

    expect(array_column($findings, 'symbol'))->toEqualCanonicalizing([
        'insert', 'update', 'delete', 'statement', 'unprepared', 'affectingStatement',
    ])->and(array_unique(array_column($findings, 'kind')))->toBe(['raw_table']);
});

it('fails closed for dynamic model query and DB connection calls', function () {
    $findings = scanDomainFixture(['app/DynamicCalls.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        use Illuminate\Support\Facades\DB;
        function run(Product $product, string $method): void {
            $product->{$method}([]);
            Product::{$method}([]);
            DB::table('products')->{$method}([]);
            DB::connection()->{$method}('UPDATE products SET name = ?', ['x']);
        }
        PHP]);

    expect($findings)->toHaveCount(4)
        ->and(array_unique(array_column($findings, 'aggregate')))->toBe(['unknown'])
        ->and(array_unique(array_column($findings, 'kind')))->toBe(['unknown']);
});

it('keeps dataflow scoped ordered and FQCN-safe while learning untyped constructor properties', function () {
    $findings = scanDomainFixture(['app/ScopedWriter.php' => <<<'PHP'
        <?php
        namespace Vendor\DTO { final class Product { public function save(): void {} } }
        namespace App\Fixture {
            use App\Models\Product as ProductModel;
            use Vendor\DTO\Product;
            final class ModelHolder { private ProductModel $shared; }
            final class DtoHolder {
                private $shared;
                public function __construct(Product $product) { $this->shared = $product; }
                public function save(): void { $this->shared->save(); }
            }
            final class ScopedWriter {
                private $owned;
                public function __construct(ProductModel $product) { $this->owned = $product; }
                public function writes(): void { $this->owned->pushQuietly(); }
                public function collision(Product $product): void { $product->save(); }
                public function ordered(ProductModel $product): void {
                    $value = new Product();
                    $value->save();
                    $later->save();
                    $later = $product;
                    $value = $product;
                    $value = new Product();
                    $value->save();
                }
                public function fqcn(): void { \App\Models\Product::create([]); }
            }
        }
        PHP]);

    expect($findings)->toHaveCount(2)
        ->and(array_column($findings, 'symbol'))->toEqualCanonicalizing(['pushQuietly', 'create']);
});

it('detects relationship mutations without treating read-only chains as writes', function () {
    $findings = scanDomainFixture(['app/Http/Writer.php' => <<<'PHP'
        <?php
        namespace App\Http;
        use App\Models\Product;
        final class Writer {
            public function run(Product $product): void {
                $product->categories()->sync(['category']);
                $product->categories()->where('active', true)->get();
            }
        }
        PHP]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->kind)->toBe('relationship')
        ->and($findings[0]->symbol)->toBe('sync');
});

it('detects model query builder, table builder, and raw SQL writes', function () {
    $findings = scanDomainFixture(['app/Jobs/Writer.php' => <<<'PHP'
        <?php
        namespace App\Jobs;
        use App\Models\CustomerOrder;
        use Illuminate\Support\Facades\DB;
        final class Writer {
            public function run(): void {
                CustomerOrder::query()->where('id', 'x')->update(['status' => 'closed']);
                DB::table('customer_orders')->where('id', 'x')->delete();
                DB::statement('UPDATE customer_orders SET status = ?', ['closed']);
            }
        }
        PHP]);

    expect(array_column($findings, 'kind'))->toEqualCanonicalizing(['query_builder', 'query_builder', 'raw_table'])
        ->and(array_column($findings, 'symbol'))->toEqualCanonicalizing(['update', 'delete', 'statement']);
});

it('detects the exact DDL pivot table names', function () {
    $findings = scanDomainFixture(['app/Jobs/PivotWriter.php' => <<<'PHP'
        <?php
        namespace App\Jobs;
        use Illuminate\Support\Facades\DB;
        final class PivotWriter {
            public function run(): void {
                DB::statement('INSERT INTO product_category (product_id) VALUES (?)', ['x']);
                DB::statement('UPDATE menu_menu_sections SET position = 1');
                DB::statement('DELETE FROM menu_promotion_category WHERE promotion_id = ?', ['x']);
                DB::statement('UPDATE menu_promotion_product SET position = 1');
            }
        }
        PHP]);

    expect(array_column($findings, 'target'))->toEqualCanonicalizing([
        'product_category',
        'menu_menu_sections',
        'menu_promotion_category',
        'menu_promotion_product',
    ])->and(array_unique(array_column($findings, 'kind')))->toBe(['raw_table']);
});

it('fails closed for dynamic write targets while permitting dynamic table reads', function () {
    $findings = scanDomainFixture(['app/Jobs/DynamicWriter.php' => <<<'PHP'
        <?php
        namespace App\Jobs;
        use Illuminate\Support\Facades\DB;
        final class DynamicWriter {
            public function run(string $table, string $sql, string $suffix): void {
                DB::table($table)->where('id', 'x')->get();
                DB::table($table)->where('id', 'x')->update([]);
                $builder = DB::table($table);
                $builder->delete();
                DB::statement($sql);
                DB::unprepared('UPDATE products SET name = '.$suffix);
            }
        }
        PHP]);

    expect($findings)->toHaveCount(4)
        ->and(array_unique(array_column($findings, 'aggregate')))->toBe(['unknown'])
        ->and(array_unique(array_column($findings, 'kind')))->toBe(['unknown'])
        ->and(array_column($findings, 'target'))->toEqualCanonicalizing([
            'dynamic-table',
            'dynamic-table',
            'dynamic-sql',
            'dynamic-sql',
        ]);

    $entry = [
        'aggregate' => 'unknown', 'path' => 'app/Jobs/DynamicWriter.php',
        'signatures' => ['unknown|update|dynamic-table|0000000000000000' => 1],
        'owner' => 'plan-047', 'removal_task' => 'T2.9', 'expires_at_gate' => 4,
        'reason' => 'Dynamic mutation targets must never be allowlisted.',
    ];
    $comparison = domainGuard()->compare($findings, [$entry]);

    expect($comparison['new'])->toHaveCount(4)
        ->and($comparison['errors'])->not->toBe([]);
});

it('permits read-only Eloquent and query-builder access', function () {
    $findings = scanDomainFixture(['app/Reports/Reader.php' => <<<'PHP'
        <?php
        namespace App\Reports;
        use App\Models\CustomerOrder;
        use Illuminate\Support\Facades\DB;
        final class Reader {
            public function run(): void {
                CustomerOrder::query()->where('status', 'paid')->get();
                DB::table('customer_orders')->select('id')->get();
            }
        }
        PHP]);

    expect($findings)->toBe([]);
});

it('inventories owned Omnify generated CRUD services', function () {
    $findings = scanDomainFixture([
        'app/Omnify/Modules/Product/Services/ProductServiceBase.php' => <<<'PHP'
            <?php
            /** @generated by omnify */
            class ProductServiceBase {}
            PHP,
        'app/Services/Omnify/ProductService.php' => <<<'PHP'
            <?php
            class ProductService extends ProductServiceBase {}
            PHP,
        'app/Omnify/Modules/ProductTranslation/Services/ProductTranslationServiceBase.php' => <<<'PHP'
            <?php
            /** @generated by omnify */
            class ProductTranslationServiceBase {}
            PHP,
        'app/Omnify/Modules/MenuPromotionTranslation/Services/MenuPromotionTranslationServiceBase.php' => <<<'PHP'
            <?php
            /** @generated by omnify */
            class MenuPromotionTranslationServiceBase {}
            PHP,
        'app/Omnify/Modules/PaymentMethodTranslation/Services/PaymentMethodTranslationServiceBase.php' => <<<'PHP'
            <?php
            /** @generated by omnify */
            class PaymentMethodTranslationServiceBase {}
            PHP,
    ]);

    expect($findings)->toHaveCount(5)
        ->and($findings[0]->kind)->toBe('generated_service')
        ->and(array_column($findings, 'symbol'))->toEqualCanonicalizing([
            'generic-crud',
            'generic-crud',
            'generic-crud',
            'generic-crud',
            'compatibility-facade',
        ]);
});

it('detects indirect Omnify CRUD calls through aliases FQCNs and untyped properties', function () {
    $findings = scanDomainFixture([
        'app/Http/ProductController.php' => <<<'PHP'
            <?php
            namespace App\Http;
            use App\Services\Omnify\ProductService as GenericProducts;
            final class ProductController {
                private $products;
                public function __construct(GenericProducts $products) { $this->products = $products; }
                public function store(): void { $this->products->create([]); }
            }
            PHP,
        'app/Jobs/DeleteProduct.php' => <<<'PHP'
            <?php
            namespace App\Jobs;
            final class DeleteProduct {
                public function __construct(
                    private \App\Omnify\Modules\PaymentMethod\Services\PaymentMethodServiceBase $service,
                ) {}
                public function handle(): void {
                    $this->service->forceDelete(new \App\Models\Product());
                }
            }
            PHP,
    ]);

    expect($findings)->toHaveCount(2)
        ->and(array_unique(array_column($findings, 'kind')))->toBe(['generated_service_consumer'])
        ->and(array_column($findings, 'symbol'))->toEqualCanonicalizing(['create', 'forceDelete']);
});

it('exempts only an explicitly approved persistence implementation', function () {
    $findings = scanDomainFixture(['app/Services/Product/Persistence/ProductPersistence.php' => <<<'PHP'
        <?php
        namespace App\Services\Product\Persistence;
        use App\Models\Product;
        final class ProductPersistence { public function run(): void { Product::create([]); } }
        PHP]);

    expect($findings)->toBe([]);
});

it('treats a legacy facade candidate as debt and rejects a new unallowlisted writer', function () {
    $findings = scanDomainFixture(['app/Services/Product/ProductService.php' => <<<'PHP'
        <?php
        namespace App\Services\Product;
        use App\Models\Product;
        final class ProductService { public function run(): void { Product::create([]); } }
        PHP]);

    $unallowlisted = domainGuard()->compare($findings, []);
    $entry = [
        'aggregate' => 'product',
        'path' => 'app/Services/Product/ProductService.php',
        'signatures' => [domainFindingSignature($findings[0]) => 1],
        'owner' => 'plan-047',
        'removal_task' => 'T2.9',
        'expires_at_gate' => 4,
        'reason' => 'Legacy facade candidate has not been cut over to an internal persistence boundary.',
    ];
    $allowlisted = domainGuard()->compare($findings, [$entry]);

    expect($findings)->toHaveCount(1)
        ->and($unallowlisted['new'])->toHaveCount(1)
        ->and($allowlisted['known'])->toHaveCount(1)
        ->and($allowlisted['new'])->toBe([]);
});

it('reports known debt and fails closed for new and stale findings', function () {
    $findings = scanDomainFixture(['app/Writer.php' => <<<'PHP'
        <?php
        use App\Models\Product;
        Product::create([]);
        PHP]);
    $entry = [
        'aggregate' => $findings[0]->aggregate,
        'path' => $findings[0]->path,
        'signatures' => [domainFindingSignature($findings[0]) => 1],
        'owner' => 'plan-047', 'removal_task' => 'T2.9', 'expires_at_gate' => 4, 'reason' => 'legacy writer',
    ];

    $known = domainGuard()->compare($findings, [$entry]);
    $new = domainGuard()->compare($findings, []);
    $stale = domainGuard()->compare([], [$entry]);

    expect($known['known'])->toHaveCount(1)->and($known['new'])->toBe([])->and($known['stale'])->toBe([])
        ->and($new['new'])->toHaveCount(1)
        ->and($stale['stale'])->toHaveCount(1);
});

it('rejects malformed duplicate unknown and expired allowlist metadata', function () {
    $invalid = [
        'aggregate' => 'unknown', 'path' => 'app/Writer.php', 'signatures' => ['model|save|Product|0000000000000000' => 1],
        'owner' => 'plan-047', 'removal_task' => 'T2.9', 'expires_at_gate' => 1,
        'reason' => 'legacy writer',
    ];
    $result = domainGuard()->compare([], [$invalid, $invalid]);

    expect($result['errors'])->not->toBe([]);
});

it('strictly validates allowlist governance metadata and paths', function () {
    $valid = [
        'aggregate' => 'product',
        'path' => 'app/Models/Product.php',
        'signatures' => ['model|create|Product|0000000000000000' => 1],
        'owner' => 'plan-047',
        'removal_task' => 'T2.9-T2.10/T4.12',
        'expires_at_gate' => 5,
        'reason' => 'Legacy fixture awaiting migration.',
    ];

    $root = dirname(__DIR__, 3);
    expect(domainGuard()->compare([], [$valid], 4, $root)['errors'])->toBe([]);

    $invalidEntries = [
        [...$valid, 'signatures' => []],
        [...$valid, 'owner' => ' plan-047'],
        [...$valid, 'reason' => 123],
        [...$valid, 'path' => 'app/../app/Models/Product.php'],
        [...$valid, 'path' => 'app/Missing.php'],
        [...$valid, 'removal_task' => 'issue someday'],
        [...$valid, 'expires_at_gate' => 4],
        [...$valid, 'signatures' => [' model|create|Product|0000000000000000' => 1]],
        [...$valid, 'signatures' => ['model|create|Product|0000000000000000' => '1']],
        [...$valid, 'signatures' => ['model|create|Product|0000000000000000' => 2]],
    ];
    $result = domainGuard()->compare([], $invalidEntries, 4, $root);

    expect(count($result['errors']))->toBeGreaterThanOrEqual(10)
        ->and(implode("\n", $result['errors']))
        ->toContain('non-empty signature-to-occurrence map')
        ->toContain('nonexistent or noncanonical path')
        ->toContain('malformed removal task')
        ->toContain('expires at or before gate 4');
});

/*
 * #3027 — khối dọn dẹp KHÔNG được che ngoại lệ thật.
 *
 * `finally` ném thì ngoại lệ của nó THAY THẾ ngoại lệ đang bay. Bản cũ mở
 * `RecursiveDirectoryIterator($root)` vô điều kiện, nên một lượt hỏng thật
 * (`ProcessFailedException`, mang stderr của tiến trình con) đi ra ngoài dưới
 * dạng `UnexpectedValueException` về thư mục tạm — và người đọc log đi tìm sai
 * chỗ. Đã xảy ra ở `arch-gate` trên PR #3018.
 *
 * Hai ca dưới đo ĐÚNG hành vi đó, không đo cách cài đặt.
 */

it('#3027 dọn dẹp thư mục KHÔNG còn tồn tại ⇒ IM, không ném', function () {
    // Đây là vế bắt được lỗi thật. Trước bản vá, gọi với một đường dẫn không
    // tồn tại ném `UnexpectedValueException` — và vì nó được gọi trong
    // `finally`, ngoại lệ ấy THAY THẾ nguyên nhân thật đang bay ra.
    $gone = sys_get_temp_dir().'/domain-guard-khong-ton-tai-'.bin2hex(random_bytes(8));

    // try/catch TƯỜNG MINH, không dùng `->not->toThrow()`: bản đầu của test này
    // viết như vậy và nó XANH cả khi đã gỡ rào — matcher đó không gọi closure,
    // nên nó khẳng định về một lượt chạy chưa từng xảy ra.
    $thrown = null;
    try {
        removeFixtureTree($gone);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull(
        'dọn dẹp đã ném '.($thrown ? get_class($thrown) : '').' — trong `finally` nó sẽ '
        .'THAY THẾ nguyên nhân thật đang bay ra'
    );
});

it('#3027 đường chạy bình thường vẫn dọn sạch, không để lại rác', function () {
    // Rào phải biết IM. Bỏ qua khi không có gì để dọn KHÔNG được biến thành
    // bỏ qua khi CÓ — nếu không mỗi lượt chạy để lại một thư mục trong /tmp.
    $before = glob(sys_get_temp_dir().'/domain-guard-*') ?: [];

    scanDomainFixture(['app/Http/Clean.php' => <<<'PHP'
        <?php
        namespace App\Http;
        final class Clean { public function run(): void {} }
        PHP]);

    $after = glob(sys_get_temp_dir().'/domain-guard-*') ?: [];

    expect(count($after))->toBe(count($before));
});
