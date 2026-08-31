<?php

namespace App\OpenApi\Shop;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'TempoFast API — Shop-scoped',
    description: <<<'DESC'
    ## TempoFast — Shop-scoped APIs

    APIs for shop-level operations (inventory, stock movements, warehouses, production).

    All endpoints under `/api/v1/shops/{shopSlug}/...` are scoped to a specific shop (branch).

    ### Resources
    | Resource | Endpoints |
    |----------|-----------|
    | Warehouses | `/api/v1/shops/{shopSlug}/warehouses` |
    | Stock Levels | `/api/v1/shops/{shopSlug}/stock-levels` |
    | Stock Transactions | `/api/v1/shops/{shopSlug}/stock-transactions` |
    | Stock Transfers | `/api/v1/shops/{shopSlug}/stock-transfers` |
    | Stock Counts | `/api/v1/shops/{shopSlug}/stock-counts` |
    | Stock Alerts | `/api/v1/shops/{shopSlug}/stock-alerts` |
    | Material Batches | `/api/v1/shops/{shopSlug}/material-batches` |
    | Production Orders | `/api/v1/shops/{shopSlug}/production-orders` |
    | Disposals | `/api/v1/shops/{shopSlug}/disposals` |
    | Zones | `/api/v1/shops/{shopSlug}/zones` |
    | Tables | `/api/v1/shops/{shopSlug}/tables` |
    | Shop Menus | `/api/v1/shops/{shopSlug}/menus` |

    ### Shop resolution
    The `{shopSlug}` URL parameter is resolved by `ResolveShopFromSlug` middleware,
    which loads the Branch model and verifies the authenticated user has access.

    ### Authentication
    All endpoints require Bearer token in `Authorization` header.
    DESC,
    contact: new OA\Contact(name: 'TempoFast'),
)]
#[OA\Server(
    url: 'http://localhost:5400',
    description: 'Local Development',
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Token',
    description: 'Bearer token from auth endpoints',
)]
#[OA\Tag(name: 'Warehouses', description: 'Warehouse management')]
#[OA\Tag(name: 'Stock Levels', description: 'Current stock levels')]
#[OA\Tag(name: 'Stock Transactions', description: 'Stock in/out transactions')]
#[OA\Tag(name: 'Stock Transfers', description: 'Inter-warehouse transfers')]
#[OA\Tag(name: 'Stock Counts', description: 'Physical inventory counts')]
#[OA\Tag(name: 'Stock Alerts', description: 'Low-stock alerts')]
#[OA\Tag(name: 'Material Batches', description: 'Material batch tracking')]
#[OA\Tag(name: 'Production Orders', description: 'Production order management')]
#[OA\Tag(name: 'Disposals', description: 'Stock disposal records')]
#[OA\Tag(name: 'Zones', description: 'Dining zones (areas) within a shop')]
#[OA\Tag(name: 'Tables', description: 'Dining tables grouped by zone, with runtime status and per-table QR token')]
#[OA\Tag(name: 'Shop Menus', description: 'Branch menus cloned from HQ master menus — read, item availability toggles, per-shop price overrides')]
#[OA\Tag(name: 'Customers', description: 'Customer CRUD scoped to a branch')]
#[OA\Tag(name: 'Orders', description: 'Customer order lifecycle — open, checkout, pay (split tender), close, void, table merge')]
#[OA\Tag(name: 'Order Payments', description: 'Payment management — split tender, confirm, refund')]
#[OA\Tag(name: 'Shop Dashboard', description: 'Shop-level KPIs, revenue trend, table status, top items, production queue, recent orders')]
#[OA\Tag(name: 'Kiosk', description: 'Kiosk device endpoints — table orders, payments, audit logs. Authenticated via device token (Bearer).')]
#[OA\Tag(
    name: 'Shop Schedule Overrides',
    description: 'Shop manager APIs for per-shop start/end-time overrides on HQ-defined menu schedule windows. Each `branch_schedule_overrides` row stores nullable start/end times that override the parent `menu_schedule` row when present; missing fields fall back to the HQ default at query time via COALESCE.',
)]

// Common reusable schemas

#[OA\Schema(
    schema: 'PaginationMeta',
    type: 'object',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer'),
        new OA\Property(property: 'last_page', type: 'integer'),
        new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'total', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'ValidationError',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))
        ),
    ]
)]
#[OA\Schema(
    schema: 'Warehouse',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StockLevel',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'material_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'unit', type: 'string'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StockTransaction',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'sub_type', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'reference_type', type: 'string', nullable: true),
        new OA\Property(property: 'reference_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StockTransfer',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'source_warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'destination_warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StockCount',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'scope', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StockAlert',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'MaterialBatch',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ProductionOrder',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'DisposalRecord',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'stock_transaction_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'disposal_reason', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Zone',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', maxLength: 50),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'display_order', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'tables_count', type: 'integer', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'TableStatusChange',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'from_status', type: 'string', enum: ['free', 'occupied', 'reserved', 'cleaning', 'out_of_service'], nullable: true),
        new OA\Property(property: 'to_status', type: 'string', enum: ['free', 'occupied', 'reserved', 'cleaning', 'out_of_service']),
        new OA\Property(property: 'changed_by_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'changed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'Table',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', maxLength: 50),
        new OA\Property(property: 'name', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'seat_count', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['free', 'occupied', 'reserved', 'cleaning', 'out_of_service']),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'qr_token', type: 'string', minLength: 32, maxLength: 32, description: 'Opaque random base62 token. Frontend renders the QR client-side from this string.'),
        new OA\Property(
            property: 'zone',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'code', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
        new OA\Property(
            property: 'last_status_change',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'from', type: 'string', nullable: true),
                new OA\Property(property: 'to', type: 'string'),
                new OA\Property(property: 'changed_by_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'changed_at', type: 'string', format: 'date-time'),
            ],
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'Menu',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['Draft', 'Pending', 'Approved', 'Active', 'Inactive', 'Rejected']),
        new OA\Property(property: 'priority', type: 'integer'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_master', type: 'boolean'),
        new OA\Property(property: 'master_menu_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'branch_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'organization_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'brand_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'menu_products_count', type: 'integer', nullable: true),
        new OA\Property(property: 'menu_products', type: 'array', items: new OA\Items(ref: '#/components/schemas/MenuProduct'), nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'MenuProduct',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'menu_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'display_order', type: 'integer'),
        new OA\Property(property: 'master_menu_product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'skus', type: 'array', items: new OA\Items(ref: '#/components/schemas/MenuProductSku'), nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'MenuProductSku',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'menu_product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'selling_price', type: 'number', format: 'float'),
        new OA\Property(property: 'is_price_overridden', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Customer',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'address', type: 'string', nullable: true),
        new OA\Property(property: 'tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'brand_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'branch_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'organization_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CustomerOrder',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'order_code', type: 'string'),
        new OA\Property(property: 'order_type', type: 'string', enum: ['dine_in', 'takeaway']),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'dining', 'checkout', 'paying', 'closed', 'voided']),
        new OA\Property(property: 'subtotal', type: 'number'),
        new OA\Property(property: 'discount_amount', type: 'number'),
        new OA\Property(property: 'service_charge', type: 'number'),
        new OA\Property(property: 'tax_amount', type: 'number'),
        new OA\Property(property: 'total_amount', type: 'number'),
        new OA\Property(property: 'paid_amount', type: 'number'),
        new OA\Property(property: 'total_tip', type: 'number'),
        new OA\Property(property: 'remaining_amount', type: 'number', description: 'Computed: total_amount - paid_amount'),
        new OA\Property(property: 'guest_count', type: 'integer', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'void_reason', type: 'string', nullable: true),
        new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'checkout_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'voided_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'customer', ref: '#/components/schemas/Customer', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerOrderItem')),
        new OA\Property(property: 'payments', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderPayment')),
        new OA\Property(property: 'tables', type: 'array', items: new OA\Items(ref: '#/components/schemas/TableRef')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CustomerOrderItem',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'customer_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'unit_price', type: 'number'),
        new OA\Property(property: 'subtotal', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'preparing', 'ready', 'served', 'voided']),
        new OA\Property(property: 'served_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'voided_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'void_reason', type: 'string', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'productSku', ref: '#/components/schemas/ProductSkuRef', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'OrderPayment',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payment_code', type: 'string'),
        new OA\Property(property: 'payment_method', ref: '#/components/schemas/PaymentMethodRef'),
        new OA\Property(property: 'amount', type: 'number', description: 'Positive = payment, Negative = refund'),
        new OA\Property(property: 'tip_amount', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'succeeded', 'failed', 'refunded']),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'tendered_amount', type: 'number', nullable: true, description: 'Cash only: amount customer handed over'),
        new OA\Property(property: 'change_amount', type: 'number', nullable: true, description: 'Cash only: tendered - amount - tip'),
        new OA\Property(property: 'reference_no', type: 'string', nullable: true, description: 'Card txn ID or bank transfer ref'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'refund_of_id', type: 'string', format: 'uuid', nullable: true, description: 'Set when this is a refund of another payment'),
        new OA\Property(property: 'received_by_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'PaymentMethodRef',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', description: 'cash, card, transfer, e_wallet'),
        new OA\Property(property: 'name', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'TableRef',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['free', 'occupied', 'reserved', 'cleaning', 'out_of_service']),
    ]
)]
#[OA\Schema(
    schema: 'ProductSkuRef',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'sku', type: 'string', nullable: true),
    ]
)]
class ShopApiInfo {}
