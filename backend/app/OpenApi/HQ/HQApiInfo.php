<?php

namespace App\OpenApi\HQ;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'TempoFast API — HQ (brand-scoped)',
    description: <<<'DESC'
    ## TempoFast — HQ (brand-scoped) APIs

    APIs for managing brand-level entities (HQ — brand headquarters) (products, categories, materials, recipes, menus).

    All endpoints under `/api/v1/hq/{brandSlug}/...` are scoped to a specific brand.

    ### Resources
    | Resource | Endpoints |
    |----------|-----------|
    | Products | `/api/v1/hq/{brandSlug}/products` |
    | Product Types | `/api/v1/hq/{brandSlug}/product-types` |
    | Product SKUs | `/api/v1/hq/{brandSlug}/skus` |
    | SKU Units | `/api/v1/hq/{brandSlug}/sku-units` |
    | Categories | `/api/v1/hq/{brandSlug}/categories` |
    | Materials | `/api/v1/hq/{brandSlug}/materials` |
    | Recipes | `/api/v1/hq/{brandSlug}/recipes` |
    | Menus | `/api/v1/hq/{brandSlug}/menus` |
    | Menu Sections | `/api/v1/hq/{brandSlug}/menu-sections` |
    | Files (upload) | `/api/v1/files/*` |

    ### Brand resolution
    The `{brandSlug}` URL parameter is resolved by `ResolveBrandFromSlug` middleware,
    which loads the Brand model and verifies the authenticated user has access.

    ### Authentication
    All endpoints require Bearer token in `Authorization` header (SSO or standalone).

    ### Common query params
    - `?search=keyword` — full-text search across configured searchable fields
    - `?sort=-created_at` — sort by field, prefix `-` for desc
    - `?per_page=15` — pagination
    - `?status=active` — filter by status (varies per resource)
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
#[OA\Tag(name: 'Products', description: 'Product CRUD + workflow + import/export')]
#[OA\Tag(name: 'Categories', description: 'Category management')]
#[OA\Tag(name: 'Materials', description: 'Raw material management')]
#[OA\Tag(name: 'Recipes', description: 'Recipe definitions')]
#[OA\Tag(name: 'Menus', description: 'Menu CRUD + workflow + items')]
#[OA\Tag(name: 'Menu Sections', description: 'Menu section (category grouping) management')]
#[OA\Tag(name: 'Files', description: 'File upload (temp/permanent flow)')]
#[OA\Tag(name: 'HQ Customers', description: 'Cross-branch customer view (read-only)')]
#[OA\Tag(name: 'HQ Orders', description: 'Cross-branch order report with aggregates (read-only)')]
#[OA\Tag(name: 'HQ Dashboard', description: 'Brand-level KPIs, revenue charts, category sales, shop performance, top products, recent orders')]
#[OA\Tag(name: 'Payment Methods', description: 'Payment method management')]

// Common reusable schemas

#[OA\Schema(
    schema: 'PaginationMeta',
    type: 'object',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer'),
        new OA\Property(property: 'last_page', type: 'integer'),
        new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'from', type: 'integer', nullable: true),
        new OA\Property(property: 'to', type: 'integer', nullable: true),
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
    schema: 'File',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'collection', type: 'string'),
        new OA\Property(property: 'original_name', type: 'string'),
        new OA\Property(property: 'mime_type', type: 'string'),
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['temporary', 'permanent']),
        new OA\Property(property: 'url', type: 'string', nullable: true),
        new OA\Property(property: 'is_permanent', type: 'boolean'),
        new OA\Property(property: 'is_expired', type: 'boolean'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Product',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'organization_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'brand_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'inactive']),
        new OA\Property(property: 'is_hidden', type: 'boolean'),
        new OA\Property(property: 'created_by_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'updated_by_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Category',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'sku', type: 'string', nullable: true),
        new OA\Property(property: 'slug', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Material',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Recipe',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'Menu',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['Draft', 'Pending', 'Approved', 'Active', 'Inactive', 'Rejected']),
        new OA\Property(property: 'is_master', type: 'boolean'),
        new OA\Property(property: 'menuSections', type: 'array', items: new OA\Items(ref: '#/components/schemas/MenuSection'), nullable: true, description: 'Loaded on show endpoint'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'MenuSection',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'menus_count', type: 'integer', nullable: true, description: 'Number of menus using this section'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'MenuSchedule',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'menu_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_time', type: 'string', example: '06:00:00'),
        new OA\Property(property: 'end_time', type: 'string', example: '10:30:00'),
        new OA\Property(property: 'days_of_week', type: 'integer', minimum: 1, maximum: 127, description: 'Bitmask: bit0=Sun … bit6=Sat'),
        new OA\Property(property: 'days_of_week_labels', type: 'array', items: new OA\Items(type: 'string', enum: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'priority', type: 'integer', minimum: 0),
        new OA\Property(property: 'created_by_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProductType',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'product_form', type: 'string', enum: ['physical', 'digital']),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    // plan-043 — brand-scoped consumption-tax type. Carries TWO rates so the
    // final rate is chosen at order time by order_type (spot/dine_in →
    // single rate — #1099). See docs/guide/tax-types.md.
    schema: 'TaxType',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', maxLength: 50, description: 'Unique per brand, immutable after create.'),
        new OA\Property(property: 'name', type: 'string', description: 'Translatable (ja/en/vi) — resolved to the request locale.'),
        new OA\Property(property: 'rate', type: 'string', description: 'THE tax rate (%). Decimal(5,2) as string. Context (dine-in/takeaway) is a menu concern (#1099).'),
        new OA\Property(property: 'is_default', type: 'boolean', description: 'Exactly one per brand — the tier-4 fallback in the resolve chain.'),
        new OA\Property(property: 'is_active', type: 'boolean', description: 'Inactive types block new assignment but keep existing references valid.'),
        new OA\Property(property: 'products_count', type: 'integer', nullable: true, description: 'Present only when the products relation is counted.'),
        new OA\Property(property: 'organization_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'brand_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProductSku',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ProductOption',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'key', type: 'string', maxLength: 60, description: 'Lowercase key (a-z, 0-9, _)'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120),
        new OA\Property(property: 'position', type: 'integer', enum: [1, 2, 3]),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'values', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductOptionValue'), nullable: true, description: 'Loaded when relation requested'),
        new OA\Property(property: 'values_count', type: 'integer', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProductOptionValue',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_option_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'value', type: 'string', maxLength: 60),
        new OA\Property(property: 'label', type: 'string', maxLength: 120),
        new OA\Property(property: 'position', type: 'integer', nullable: true),
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
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'completed', 'cancelled']),
        new OA\Property(property: 'subtotal', type: 'number'),
        new OA\Property(property: 'discount_amount', type: 'number'),
        new OA\Property(property: 'total_amount', type: 'number'),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'card', 'transfer', 'other'], nullable: true),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'customer', ref: '#/components/schemas/Customer', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/CustomerOrderItem')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CustomerOrderItem',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_sku_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'unit_price', type: 'number'),
        new OA\Property(property: 'subtotal', type: 'number'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'Allergen',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'jurisdiction', type: 'string', enum: ['jp', 'eu', 'us']),
        new OA\Property(property: 'severity', type: 'string', enum: ['mandatory', 'recommended']),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'PaymentMethod',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'is_auto_confirm', type: 'boolean'),
        new OA\Property(property: 'requires_tendered', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'branch_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class HQApiInfo {}
