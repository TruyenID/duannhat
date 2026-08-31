<?php

use App\Models\Branch;
use App\Models\DisposalRecord;
use App\Models\ProductSku;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->variant = ProductSku::factory()->create();

    $this->baseUrl = "/api/v1/shops/{$this->shop->slug}";

    $this->actingAs($this->user);
});

// =========================================================================
//  Authentication
// =========================================================================

it('returns 401 when not authenticated', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/disposals")
        ->assertUnauthorized();
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists disposals for the user organization', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);

        // Each disposal needs a unique stock_transaction_item (unique constraint)
        $items = StockTransactionItem::factory()->count(3)->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $items->each(function ($item) {
            DisposalRecord::factory()->create([
                'stock_transaction_item_id' => $item->id,
            ]);
        });

        $this->getJson("{$this->baseUrl}/disposals")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('does not show disposals from other organizations', function () {
        // Own org disposal
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);
        $item = StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);
        DisposalRecord::factory()->create([
            'stock_transaction_item_id' => $item->id,
        ]);

        // Other org disposal
        $otherTxn = StockTransaction::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);
        $otherItem = StockTransactionItem::factory()->create([
            'stock_transaction_id' => $otherTxn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);
        DisposalRecord::factory()->create([
            'stock_transaction_item_id' => $otherItem->id,
        ]);

        $this->getJson("{$this->baseUrl}/disposals")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters disposals by disposal_reason', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);
        $item1 = StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);
        $item2 = StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        DisposalRecord::factory()->create([
            'stock_transaction_item_id' => $item1->id,
            'disposal_reason' => 'expired',
        ]);
        DisposalRecord::factory()->create([
            'stock_transaction_item_id' => $item2->id,
            'disposal_reason' => 'damaged',
        ]);

        $this->getJson("{$this->baseUrl}/disposals?disposal_reason=expired")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters disposals by stock_transaction_item_id', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);
        $item1 = StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);
        $item2 = StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        DisposalRecord::factory()->create([
            'stock_transaction_item_id' => $item1->id,
        ]);
        DisposalRecord::factory()->create([
            'stock_transaction_item_id' => $item2->id,
        ]);

        $this->getJson("{$this->baseUrl}/disposals?stock_transaction_item_id={$item1->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('returns paginated results with meta', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);

        $items = StockTransactionItem::factory()->count(3)->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);

        $items->each(function ($item) {
            DisposalRecord::factory()->create([
                'stock_transaction_item_id' => $item->id,
            ]);
        });

        $this->getJson("{$this->baseUrl}/disposals?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'total'],
            ]);
    });

    it('returns empty list when no disposals exist', function () {
        $this->getJson("{$this->baseUrl}/disposals")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a disposal record with relations', function () {
        $txn = StockTransaction::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
        ]);
        $item = StockTransactionItem::factory()->create([
            'stock_transaction_id' => $txn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);
        $disposal = DisposalRecord::factory()->create([
            'stock_transaction_item_id' => $item->id,
            'disposal_reason' => 'expired',
        ]);

        $this->getJson("{$this->baseUrl}/disposals/{$disposal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $disposal->id)
            ->assertJsonPath('data.disposal_reason', 'expired');
    });

    it('returns 403 for a disposal from another organization', function () {
        $otherTxn = StockTransaction::factory()->create([
            'organization_id' => fake()->uuid(),
            'warehouse_id' => Warehouse::factory()->create()->id,
        ]);
        $otherItem = StockTransactionItem::factory()->create([
            'stock_transaction_id' => $otherTxn->id,
            'product_sku_id' => $this->variant->id,
            'material_id' => null,
        ]);
        $disposal = DisposalRecord::factory()->create([
            'stock_transaction_item_id' => $otherItem->id,
        ]);

        $this->getJson("{$this->baseUrl}/disposals/{$disposal->id}")
            ->assertForbidden();
    });

    it('returns 404 for non-existent disposal', function () {
        $this->getJson("{$this->baseUrl}/disposals/".fake()->uuid())
            ->assertNotFound();
    });
});
