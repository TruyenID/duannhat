---
title: Swagger / OpenAPI Documentation Rules
category: contributing
tags: [swagger, openapi, l5-swagger, api, documentation, annotations]
summary: Mandatory rules for documenting APIs with OpenAPI 3 attributes via L5-Swagger. Defines tag naming, security, parameters, request/response schemas for all dxs-product API controllers.
related: [api-development, controller, route]
---

# Swagger / OpenAPI Documentation Rules

> This document defines **mandatory rules** for adding OpenAPI 3 annotations to controllers in dxs-product. All API endpoints MUST be documented.

## Architecture: 3 Documentation Instances

dxs-product separates API docs into 4 instances by domain. Swagger UI lives at `/_docs/{name}`, the raw OpenAPI spec at `/_docs/{name}.json`:

| URL | Title | Scope | Annotation paths |
|-----|-------|-------|------------------|
| `GET /_docs/auth` (+ `.json`) | Auth API | Customer auth plus Platform SSO | `app/OpenApi/Standalone/`, `app/OpenApi/Console/` |
| `GET /_docs/hq` (+ `.json`) | HQ API | Product, ProductOption, ProductOptionValue, ProductSku, Category, Material, Recipe, Menu, ProductType, File | `app/OpenApi/HQ/`, `app/Http/Controllers/Api/V1/HQ/`, `FileController.php` |
| `GET /_docs/shop` (+ `.json`) | Shop API | Warehouse, Stock*, Production, MaterialBatch, Disposal | `app/OpenApi/Shop/`, `app/Http/Controllers/Api/V1/Inventory/` |
| `GET /_docs/customer` (+ `.json`) | Customer API | QR menu, takeaway browse | `app/OpenApi/Customer/`, `app/Http/Controllers/Api/V1/Customer/` |

Config: `config/l5-swagger.php` — 4 documentations: `auth`, `hq`, `shop`, `customer`.

## Core Rules

1. **Every public API endpoint MUST have an OpenAPI attribute** (`#[OA\Get]`, `#[OA\Post]`, etc.)
2. **Use PHP 8 attributes** (NOT docblock annotations) — `use OpenApi\Attributes as OA;`
3. **Tag every endpoint** with the resource name (e.g., `Products`, `Categories`)
4. **Document all parameters** — path, query, request body
5. **Document all response codes** — 200/201, 401, 403, 404, 422
6. **Reference reusable schemas** via `$ref` for request/response bodies (not inline)

## Imports

Always import OpenAPI attributes namespace at the top of controllers:

```php
use OpenApi\Attributes as OA;
```

## Endpoint Annotation Template

```php
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/products',
    summary: 'List products',
    description: 'Returns paginated list of products for the specified brand.',
    tags: ['Products'],
    security: [['sanctum' => []]],
    parameters: [
        new OA\Parameter(
            name: 'brandSlug',
            in: 'path',
            required: true,
            description: 'Brand slug',
            schema: new OA\Schema(type: 'string')
        ),
        new OA\Parameter(
            name: 'search',
            in: 'query',
            required: false,
            description: 'Search by name or SKU',
            schema: new OA\Schema(type: 'string')
        ),
        new OA\Parameter(
            name: 'status',
            in: 'query',
            required: false,
            description: 'Filter by status',
            schema: new OA\Schema(type: 'string', enum: ['draft', 'pending', 'approved', 'active', 'inactive', 'rejected'])
        ),
        new OA\Parameter(
            name: 'per_page',
            in: 'query',
            required: false,
            description: 'Items per page (default 25, max 100)',
            schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Paginated product list',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
                ]
            )
        ),
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 403, description: 'Forbidden'),
    ]
)]
public function index(Request $request): AnonymousResourceCollection
{
    // ...
}
```

## Tag Naming Convention

Tags group related endpoints in Swagger UI. Use **PascalCase plural** for resources:

| Controller | Tag |
|-----------|-----|
| `ProductController` | `Products` |
| `CategoryController` | `Categories` |
| `MaterialController` | `Materials` |
| `MenuController` | `Menus` |
| `WarehouseController` | `Warehouses` |
| `StockTransactionController` | `Stock Transactions` |
| `FileController` | `Files` |

Register tags in the `*ApiInfo.php` info file via `#[OA\Tag(name: '...', description: '...')]`.

## Security

All authenticated endpoints MUST declare:

```php
security: [['sanctum' => []]]
```

The `sanctum` security scheme is defined in the info file via `#[OA\SecurityScheme]`.

Public endpoints (no auth) omit the `security` field.

## Parameters

### Path parameters

```php
new OA\Parameter(
    name: 'brandSlug',
    in: 'path',
    required: true,
    description: 'Brand slug from URL',
    schema: new OA\Schema(type: 'string', example: 'phuc-long')
),
new OA\Parameter(
    name: 'product',
    in: 'path',
    required: true,
    description: 'Product UUID',
    schema: new OA\Schema(type: 'string', format: 'uuid')
),
```

### Query parameters

Common patterns for list endpoints:

```php
// Search
new OA\Parameter(
    name: 'search',
    in: 'query',
    description: 'Full-text search',
    schema: new OA\Schema(type: 'string')
),
// Filter by enum
new OA\Parameter(
    name: 'status',
    in: 'query',
    description: 'Filter by status',
    schema: new OA\Schema(type: 'string', enum: ['draft', 'active'])
),
// Pagination
new OA\Parameter(
    name: 'per_page',
    in: 'query',
    description: 'Items per page',
    schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)
),
// Sort
new OA\Parameter(
    name: 'sort',
    in: 'query',
    description: 'Sort field, prefix with - for desc',
    schema: new OA\Schema(type: 'string', example: '-created_at')
),
```

## Request Body

### JSON body

```php
requestBody: new OA\RequestBody(
    required: true,
    content: new OA\JsonContent(
        required: ['name', 'product_type_id'],
        properties: [
            new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Black Coffee'),
            new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true),
            new OA\Property(property: 'product_type_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        ]
    )
),
```

### Multipart/form-data (file uploads)

```php
requestBody: new OA\RequestBody(
    required: true,
    content: new OA\MediaType(
        mediaType: 'multipart/form-data',
        schema: new OA\Schema(
            required: ['file'],
            properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'collection', type: 'string', nullable: true),
            ]
        )
    )
),
```

## Response Codes

### Standard responses by HTTP method

| Method | Success | Error codes |
|--------|---------|-------------|
| `GET /resources` (index) | 200 paginated list | 401, 403 |
| `POST /resources` (store) | 201 created | 401, 403, 422 |
| `GET /resources/{id}` (show) | 200 single resource | 401, 403, 404 |
| `PUT /resources/{id}` (update) | 200 updated | 401, 403, 404, 422 |
| `DELETE /resources/{id}` (destroy) | 204 no content | 401, 403, 404 |
| `POST /resources/{id}/restore` | 200 restored | 401, 403, 404 |
| `POST /resources/bulk-delete` | 204 no content | 401, 403, 422 |

### Response template

```php
responses: [
    new OA\Response(
        response: 200,
        description: 'Success',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
            ]
        )
    ),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden — outside organization or missing policy'),
    new OA\Response(response: 404, description: 'Resource not found'),
    new OA\Response(
        response: 422,
        description: 'Validation failed',
        content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')
    ),
]
```

## Reusable Schemas

Define resource schemas in the `*ApiInfo.php` info file:

```php
#[OA\Schema(
    schema: 'Product',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
```

Reference via `$ref` in endpoints:

```php
content: new OA\JsonContent(ref: '#/components/schemas/Product')
```

## Common Schemas (define once in BrandApiInfo / ShopApiInfo)

### PaginationMeta

```php
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
```

### ValidationError

```php
#[OA\Schema(
    schema: 'ValidationError',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'errors', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
    ]
)]
```

## Generating Docs

```bash
# Auth docs
php artisan l5-swagger:generate auth

# Brand HQ docs
php artisan l5-swagger:generate brand

# Shop docs
php artisan l5-swagger:generate shop
```

Generated JSON files: `storage/api-docs/auth-console-api-docs.json`,
`brand-api-docs.json`, `shop-api-docs.json`, `customer-api-docs.json`,
`workstation-api-docs.json`.

### `auth-console-api-docs.json` — tên file mang CHẾ ĐỘ (#1499)

Trước #1499 nó là `auth-api-docs.json`: MỘT tên cho hai chế độ, còn nội dung do
`OMNIFY_AUTH_MODE` chọn. Repo giữ bản `console`, mặc định env là `standalone`,
nên một lượt `l5-swagger:generate` trần trên máy bất kỳ đổi `info.title` từ
"Console SSO" sang "Standalone Auth" — hai dòng, exit 0, lẫn trong PR về việc
khác. Nay chế độ nằm trong tên file nên hai bản không tranh nhau được, và mặc
định là `console` — đúng cái app này chạy (`bootstrap/app.php` đăng ký
`AuthenticateSso` vô điều kiện; không nơi nào đặt `OMNIFY_AUTH_MODE`).

URL phục vụ **không đổi**: nó đến từ `routes.docs` (`_docs/auth.json`).

### `workstation-api-docs.json` — bucket mới (#1499)

Trước đó **không bucket nào quét `Api/V1/Workstation`**, nên attribute OA ở đó
chưa từng vào tài liệu công bố nào, trong khi `tal docs-check` vẫn nhắc regen mỗi
lần ai chạm controller trong namespace — lời nhắc không bao giờ đúng được.

Đo sau khi thêm bucket: **62 route workstation, 17 có attribute operation, cả 17
nay được công bố** (16 path). 45 route còn lại chưa chú thích — việc tồn, không
phải lỗi cấu hình.

Không nhầm với Swagger của app workstation ở `localhost:8080/docs` (cái đó mô tả
API LAN do bản Go phục vụ); bucket này mô tả thứ Cloud cung cấp cho máy trạm.

Dựng bucket này làm lộ ngay một `$ref` treo (`#/components/schemas/Customer`) —
bằng chứng rằng các attribute ấy chưa từng đi qua một lượt generate nào. Mỗi
bucket l5-swagger là tài liệu độc lập nên schema phải khai trong chính nó; đã
khai lặp theo đúng khuôn `HQApiInfo`/`ShopApiInfo`.

Set `L5_SWAGGER_GENERATE_ALWAYS=true` in `.env` for development to auto-regenerate on every request.

## Workflow Endpoint Conventions

For workflow actions (approve, reject, activate, etc.):

```php
#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/products/{product}/approve',
    summary: 'Approve product',
    description: 'Transition product status from pending → approved. Requires manager role.',
    tags: ['Products'],
    security: [['sanctum' => []]],
    parameters: [
        new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 403, description: 'Forbidden'),
        new OA\Response(response: 422, description: 'Cannot approve in current status'),
    ]
)]
public function approve(Product $product): ProductResource { ... }
```

## Bulk Endpoints

```php
#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/products/bulk-delete',
    summary: 'Bulk delete products',
    tags: ['Products'],
    security: [['sanctum' => []]],
    parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 403, description: 'Forbidden'),
    ]
)]
```

## Import/Export Endpoints

CSV import (multipart):

```php
#[OA\Post(
    path: '/api/v1/hq/{brandSlug}/products/import',
    summary: 'Import products from CSV',
    tags: ['Products'],
    security: [['sanctum' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file'],
                properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')]
            )
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Import result with created/updated/failed counts'),
        new OA\Response(response: 422, description: 'Invalid CSV format'),
    ]
)]
```

CSV export (binary):

```php
#[OA\Get(
    path: '/api/v1/hq/{brandSlug}/products/export',
    summary: 'Export products to CSV',
    tags: ['Products'],
    security: [['sanctum' => []]],
    responses: [
        new OA\Response(
            response: 200,
            description: 'CSV file',
            content: new OA\MediaType(mediaType: 'text/csv')
        ),
    ]
)]
```

## Checklist (per controller method)

When adding/modifying a controller method:

- [ ] Add `#[OA\Get|Post|Put|Delete]` attribute above the method
- [ ] Set `path`, `summary`, `description`, `tags`, `security`
- [ ] Document all path parameters
- [ ] Document all query parameters (for index/list)
- [ ] Document `requestBody` for POST/PUT/PATCH
- [ ] Document all response codes (200/201/204, 401, 403, 404, 422)
- [ ] Use `$ref` for reusable schemas (not inline)
- [ ] Run `php artisan l5-swagger:generate brand` (or `shop`/`auth`)
- [ ] Open `/api/{instance}/documentation` to verify it renders
- [ ] Verify Swagger UI "Try it out" works with valid input

## Common Mistakes

❌ **Don't** use docblock comments — use PHP 8 attributes:
```php
// WRONG (old swagger-php style)
/** @OA\Get(path="/api/products") */

// CORRECT
#[OA\Get(path: '/api/v1/products')]
```

❌ **Don't** inline complex schemas — use `$ref`:
```php
// WRONG — duplicated in every endpoint
content: new OA\JsonContent(properties: [/* 20 fields */])

// CORRECT — defined once in *ApiInfo.php
content: new OA\JsonContent(ref: '#/components/schemas/Product')
```

❌ **Don't** forget security on protected endpoints:
```php
// WRONG — looks like public endpoint
#[OA\Get(path: '/api/v1/products')]

// CORRECT
#[OA\Get(path: '/api/v1/products', security: [['sanctum' => []]])]
```

❌ **Don't** use wrong tag — match the resource:
```php
// WRONG
tags: ['Misc']

// CORRECT
tags: ['Products']
```
