<?php

namespace App\OpenApi\Customer;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'TempoFast API — Customer-facing',
    description: <<<'DESC'
    ## TempoFast — Customer-facing APIs

    Public APIs consumed by the customer ordering surfaces (QR menu, takeaway, delivery)
    under the `/api/v1/customer/...` prefix.

    Most read endpoints are public (no authentication required) so a guest customer can
    scan a QR code and immediately view the menu. Order mutations and customer self-service
    endpoints are guarded by the `customer` guard (Sanctum personal access token issued by
    `POST /api/v1/customer/auth/login`).

    ### Resources
    | Resource | Endpoints |
    |----------|-----------|
    | Customer Menu | `/api/v1/customer/branches/{branchSlug}/menu`, `/api/v1/customer/tables/{qrToken}/menu` |
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
    description: 'Bearer token issued by /api/v1/customer/auth/login',
)]
#[OA\Tag(
    name: 'Customer Menu',
    description: 'Public menu lookup for guest customers — resolves the active menu for a branch (by slug or via a table QR token), filtered by server-side time-of-day rules.',
)]

// Customer menu response schemas — shape produced by CustomerMenuService::transformMenu().

#[OA\Schema(
    schema: 'CustomerMenuVariant',
    type: 'object',
    description: 'A single selectable variant of a product option (e.g. "Large"). Price is stored as a DELTA from the parent item price — frontend computes unit price as `item.price + Σ variant.price`.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'price', type: 'number', format: 'float', description: 'Price delta vs. parent item price.'),
        new OA\Property(property: 'image', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'default', type: 'boolean'),
    ],
)]
#[OA\Schema(
    schema: 'CustomerMenuOption',
    type: 'object',
    description: 'A configurable option group on a menu item (e.g. "Size") with one or more selectable variants.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['single']),
        new OA\Property(property: 'required', type: 'boolean'),
        new OA\Property(
            property: 'variants',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/CustomerMenuVariant'),
        ),
    ],
)]
#[OA\Schema(
    schema: 'CustomerMenuItem',
    type: 'object',
    description: 'A single menu item (product) shown in the customer menu.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'menu_product id'),
        new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', nullable: true, description: 'Default product_sku id.'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'price', type: 'number', format: 'float', description: 'Default SKU selling price.'),
        new OA\Property(property: 'image', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['available', 'unavailable']),
        new OA\Property(
            property: 'options',
            type: 'array',
            nullable: true,
            items: new OA\Items(ref: '#/components/schemas/CustomerMenuOption'),
        ),
    ],
)]
#[OA\Schema(
    schema: 'CustomerMenuCategory',
    type: 'object',
    description: 'A menu section (category) grouping one or more items.',
    properties: [
        new OA\Property(property: 'id', type: 'string', description: 'menu_section id, or the literal string "uncategorized".'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/CustomerMenuItem'),
        ),
    ],
)]
#[OA\Schema(
    schema: 'CustomerMenu',
    type: 'object',
    description: 'The active branch menu transformed for the customer ordering surface.',
    properties: [
        new OA\Property(
            property: 'categories',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/CustomerMenuCategory'),
        ),
    ],
)]
class CustomerApiInfo {}
