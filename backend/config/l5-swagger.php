<?php

use L5Swagger\Generator;

/*
 |--------------------------------------------------------------------------
 | The auth doc is per-MODE, and the filename says which (#1499)
 |--------------------------------------------------------------------------
 |
 | It used to be one filename for both modes while the CONTENT was chosen by
 | `OMNIFY_AUTH_MODE`. So the committed artifact depended on the env of whoever
 | ran the generator last: the repo held the `console` build, the env default was
 | `standalone`, and a bare `l5-swagger:generate` on any ordinary machine
 | rewrote `info.title` from "Console SSO" to "Standalone Auth". Two lines,
 | exit 0, buried in a PR about something else. It happened at #1339 and had to
 | be reverted by hand.
 |
 | Two changes close it:
 |
 |   1. the filename carries the mode — `auth-console-api-docs.json` — so the
 |      other mode CANNOT overwrite this one, whatever env it runs under;
 |   2. the default is `console`, which is what this application actually is.
 |      There is no runtime switch: `bootstrap/app.php` registers
 |      `AuthenticateSso` unconditionally, and nothing in `config/` or the deploy
 |      workflow ever sets `OMNIFY_AUTH_MODE`. A default of `standalone` was
 |      therefore wrong for every deployment this repo has.
 |
 | The served URL is unchanged: it comes from `routes.docs` (`_docs/auth.json`),
 | not from the filename.
 |
 |   php artisan l5-swagger:generate shop      ← the doc you actually changed
 |   php artisan l5-swagger:generate --all     ← now safe: modes cannot collide
 |
 | The two annotation directories hold ONE file each (`ConsoleApiInfo`,
 | `StandaloneApiInfo`) — only the `info` block differs. The 19 paths are scanned
 | from the same controllers either way, which is why the drift looked like a
 | cosmetic two-line diff and got waved through.
 */

$mode = env('OMNIFY_AUTH_MODE', 'console');

/**
 * Auth documentation — SSO/standalone authentication endpoints.
 */
$authAnnotations = $mode === 'console'
    ? [
        base_path('app/OpenApi/Console'),
        base_path('app/Http/Controllers/Api/V1/UserContextController.php'),
        base_path('app/Http/Controllers/Api/V1/LocaleController.php'),
        base_path('app/Http/Controllers/Api/V1/Me'),
    ]
    : [
        base_path('app/OpenApi/Standalone'),
        base_path('app/Http/Controllers/Api/V1/UserContextController.php'),
        base_path('app/Http/Controllers/Api/V1/LocaleController.php'),
        base_path('app/Http/Controllers/Api/V1/Me'),
    ];

$authDocumentation = [
    'api' => [
        'title' => 'TempoFast API — Auth ('.ucfirst($mode).')',
    ],
    'routes' => [
        'api' => '_docs/auth',
        'docs' => '_docs/auth.json',
        'oauth2_callback' => '_docs/auth/oauth2-callback',
    ],
    'paths' => [
        'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
        'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
        // #1499 — mode in the NAME: the other mode writes a different file
        // instead of silently overwriting this one.
        'docs_json' => "auth-{$mode}-api-docs.json",
        'docs_yaml' => "auth-{$mode}-api-docs.yaml",
        'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
        'annotations' => $authAnnotations,
    ],
];

/**
 * HQ documentation — brand HQ APIs (Product, Category, Material, Menu, etc.)
 * Mounted under /api/v1/hq/{brandSlug}/...
 */
$hqDocumentation = [
    'api' => [
        'title' => 'TempoFast API — HQ (brand-scoped)',
    ],
    'routes' => [
        'api' => '_docs/hq',
        'docs' => '_docs/hq.json',
        'oauth2_callback' => '_docs/hq/oauth2-callback',
    ],
    'paths' => [
        'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
        'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
        'docs_json' => 'hq-api-docs.json',
        'docs_yaml' => 'hq-api-docs.yaml',
        'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
        'annotations' => [
            base_path('app/OpenApi/HQ'),
            // Controllers
            base_path('app/Http/Controllers/Api/V1/HQ'),
            base_path('app/Http/Controllers/Api/V1/FileController.php'),
        ],
    ],
];

/**
 * Shop documentation — shop-scoped APIs (Inventory, Stock, Warehouse, POS, etc.)
 *
 * POS lives here rather than in a doc of its own (#1508): it is a shop-level
 * surface, and `Kiosk` — likewise a device-token surface — is already scanned
 * from this doc. One place to look for "everything a shop's terminals call".
 */
$shopDocumentation = [
    'api' => [
        'title' => 'TempoFast API — Shop-scoped',
    ],
    'routes' => [
        'api' => '_docs/shop',
        'docs' => '_docs/shop.json',
        'oauth2_callback' => '_docs/shop/oauth2-callback',
    ],
    'paths' => [
        'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
        'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
        'docs_json' => 'shop-api-docs.json',
        'docs_yaml' => 'shop-api-docs.yaml',
        'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
        'annotations' => [
            base_path('app/OpenApi/Shop'),
            base_path('app/Http/Controllers/Api/V1/Inventory'),
            base_path('app/Http/Controllers/Api/V1/Shop'),
            base_path('app/Http/Controllers/Api/V1/Kiosk'),
            base_path('app/Http/Controllers/Api/V1/Branch'),
            base_path('app/Http/Controllers/Api/V1/Pos'),
        ],
    ],
];

/**
 * Customer documentation — public customer-facing APIs (QR menu, takeaway browse).
 * Mounted under /api/v1/customer/...
 */
$customerDocumentation = [
    'api' => [
        'title' => 'TempoFast API — Customer-facing',
    ],
    'routes' => [
        'api' => '_docs/customer',
        'docs' => '_docs/customer.json',
        'oauth2_callback' => '_docs/customer/oauth2-callback',
    ],
    'paths' => [
        'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
        'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
        'docs_json' => 'customer-api-docs.json',
        'docs_yaml' => 'customer-api-docs.yaml',
        'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
        'annotations' => [
            base_path('app/OpenApi/Customer'),
            base_path('app/Http/Controllers/Api/V1/Customer'),
            // #1510 — `Api/V1/Notifications` chỉ có `EmailUnsubscribeController`:
            // đường dẫn KHÁCH bấm trong email. Đối tượng đọc là khách, nên nó
            // thuộc doc khách chứ không phải doc console.
            base_path('app/Http/Controllers/Api/V1/Notifications'),
        ],
    ],
];

/**
 * Workstation — the CLOUD side of the workstation contract (#1499).
 *
 * 61 routes under `/api/v1/workstation/*` carry 124 `#[OA\...]` attributes, and
 * before this bucket existed NO documentation scanned that namespace: the
 * attributes were decoration, and `tal docs-check` kept telling people to
 * "regen swagger" after touching those controllers — advice that could not come
 * true, because no bucket would ever pick the changes up.
 *
 * Not to be confused with the workstation app's OWN Swagger at
 * `localhost:8080/docs`: that documents the LAN API the Go binary serves. This
 * one documents what Cloud offers it, which is the contract the workstation
 * team consumes.
 */
$workstationDocumentation = [
    'api' => [
        'title' => 'TempoFast API — Workstation (Cloud side)',
    ],
    'routes' => [
        'api' => '_docs/workstation',
        'docs' => '_docs/workstation.json',
        'oauth2_callback' => '_docs/workstation/oauth2-callback',
    ],
    'paths' => [
        'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
        'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
        'docs_json' => 'workstation-api-docs.json',
        'docs_yaml' => 'workstation-api-docs.yaml',
        'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
        'annotations' => [
            base_path('app/OpenApi/Workstation'),
            base_path('app/Http/Controllers/Api/V1/Workstation'),
            // #1510 — `Api/V1/Device` (ghép nối, /me, broadcast auth, cấu hình
            // Reverb) là bề mặt CHUNG của mọi thiết bị, và workstation ghép nối
            // qua đúng `POST /devices/pair` như kiosk/tms/kds. Nó xuất hiện ở
            // cả bucket này lẫn bucket KDS: trùng lặp trong tài liệu là ĐÚNG khi
            // hai đối tượng đọc đều cần cùng một endpoint — bắt người đọc nhảy
            // sang doc khác để tra bước đầu tiên của quy trình mới là lỗi.
            base_path('app/Http/Controllers/Api/V1/Device'),
        ],
    ],
];

/**
 * KDS — CLOUD side of the kitchen-display contract (#1510).
 *
 * Cùng khuôn `$workstationDocumentation` (#1499) và cùng một ruling: với bề mặt
 * device-token, **swagger là nguồn chân lý cho phía Cloud**, còn file md trong
 * repo con mô tả phía client. Ruling đó không hiển nhiên nên ghi lại lý do:
 * `workstation/docs/CLOUD_API.md` hiện đang đặc tả những endpoint **chưa bao
 * giờ được cài** (#1323), tức một file md viết tay trôi khỏi hiện thực mà không
 * ai phát hiện. Attribute nằm cạnh chính controller thì không trôi được như vậy.
 *
 * Không nhầm với Swagger RIÊNG của workstation ở `localhost:8080/docs` — cái đó
 * mô tả API LAN do binary Go phục vụ.
 */
$kdsDocumentation = [
    'api' => [
        'title' => 'TempoFast API — KDS (Cloud side)',
    ],
    'routes' => [
        'api' => '_docs/kds',
        'docs' => '_docs/kds.json',
        'oauth2_callback' => '_docs/kds/oauth2-callback',
    ],
    'paths' => [
        'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
        'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
        'docs_json' => 'kds-api-docs.json',
        'docs_yaml' => 'kds-api-docs.yaml',
        'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
        'annotations' => [
            base_path('app/OpenApi/Kds'),
            base_path('app/Http/Controllers/Api/V1/Kds'),
            // Ghép nối thiết bị — xem chú thích cùng dòng ở bucket workstation.
            base_path('app/Http/Controllers/Api/V1/Device'),
        ],
    ],
];

/**
 * Webhooks — chiều VÀO từ bên ngoài (#1510).
 *
 * Bucket RIÊNG chứ không nhét vào doc console, vì đối tượng đọc khác hẳn: đây
 * không phải API cho client của mình gọi, mà là hợp đồng mình cam kết NHẬN từ
 * Stripe / PayPay / nhà cung cấp mail.
 *
 * Issue nêu đúng rằng công khai trang doc cho bề mặt này là "quyết định có yếu
 * tố an ninh". Đã cân nhắc và chốt là CÓ tài liệu: những endpoint này được bảo
 * vệ bằng **xác minh chữ ký**, không bằng việc không ai biết đường dẫn — và hình
 * dạng payload thì chính nhà cung cấp đã công bố công khai. Giấu đi chỉ làm khó
 * người trực sự cố lúc 2 giờ sáng, không làm khó kẻ tấn công.
 */
$webhooksDocumentation = [
    'api' => [
        'title' => 'TempoFast API — Webhooks (inbound)',
    ],
    'routes' => [
        'api' => '_docs/webhooks',
        'docs' => '_docs/webhooks.json',
        'oauth2_callback' => '_docs/webhooks/oauth2-callback',
    ],
    'paths' => [
        'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
        'swagger_ui_assets_path' => env('L5_SWAGGER_UI_ASSETS_PATH', 'vendor/swagger-api/swagger-ui/dist/'),
        'docs_json' => 'webhooks-api-docs.json',
        'docs_yaml' => 'webhooks-api-docs.yaml',
        'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
        'annotations' => [
            base_path('app/OpenApi/Webhooks'),
            base_path('app/Http/Controllers/Api/V1/Webhooks'),
        ],
    ],
];

return [
    'default' => 'auth',
    'documentations' => [
        'auth' => $authDocumentation,
        'hq' => $hqDocumentation,
        'shop' => $shopDocumentation,
        'customer' => $customerDocumentation,
        'workstation' => $workstationDocumentation,
        'kds' => $kdsDocumentation,
        'webhooks' => $webhooksDocumentation,
    ],
    'defaults' => [
        'routes' => [
            'docs' => 'docs',
            'oauth2_callback' => 'api/oauth2-callback',
            'middleware' => [
                'api' => [],
                'asset' => [],
                'docs' => [],
                'oauth2_callback' => [],
            ],
            'group_options' => [],
        ],

        'paths' => [
            'docs' => storage_path('api-docs'),
            'views' => base_path('resources/views/vendor/l5-swagger'),
            'base' => env('L5_SWAGGER_BASE_PATH', null),
            'excludes' => [],
        ],

        'scanOptions' => [
            'default_processors_configuration' => [],
            'analyser' => null,
            'analysis' => null,
            'processors' => [],
            'pattern' => null,
            'exclude' => [],
            'open_api_spec_version' => env('L5_SWAGGER_OPEN_API_SPEC_VERSION', Generator::OPEN_API_DEFAULT_SPEC_VERSION),
        ],

        'securityDefinitions' => [
            'securitySchemes' => [
                'sanctum' => [
                    'type' => 'http',
                    'description' => 'Bearer token from login or SSO callback',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'Token',
                ],
            ],
            'security' => [],
        ],

        'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false),
        'generate_yaml_copy' => env('L5_SWAGGER_GENERATE_YAML_COPY', false),
        'proxy' => false,
        'additional_config_url' => null,
        'operations_sort' => env('L5_SWAGGER_OPERATIONS_SORT', 'alpha'),
        'validator_url' => null,

        'ui' => [
            'display' => [
                'dark_mode' => env('L5_SWAGGER_UI_DARK_MODE', false),
                'doc_expansion' => env('L5_SWAGGER_UI_DOC_EXPANSION', 'list'),
                'filter' => env('L5_SWAGGER_UI_FILTERS', true),
            ],
            'authorization' => [
                'persist_authorization' => env('L5_SWAGGER_UI_PERSIST_AUTHORIZATION', true),
                'oauth2' => [
                    'use_pkce_with_authorization_code_grant' => false,
                ],
            ],
        ],

        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env('L5_SWAGGER_CONST_HOST', env('APP_URL', 'http://localhost:5400')),
        ],
    ],
];
