<?php

/**
 * HQ Controller Arch Test
 *
 * Enforces the layered architecture rule: HQ controllers must not touch
 * Eloquent directly — every read or write must go through a Service. This
 * test catches accidental regressions where a one-line "quick fix" reaches
 * into the model layer and bypasses authorization, transaction, or
 * organization-scoping logic that lives in the Service.
 *
 * ── Vì sao luật này được viết lại (#1920) ────────────────────────────────
 *
 * Bản cũ khai đúng ý định trên nhưng thực thi một thứ khác hẳn: nó cấm
 * **import** `Illuminate\Database\...\Builder`. Đo trên `dev` ngày 2026-08-05:
 *
 *     controller HQ truy vấn Eloquent thẳng : 29 / 66
 *     controller HQ bị luật cũ BẮT          :  1
 *
 * Một controller viết `Model::query()->where(...)->get()` mà không type-hint
 * `Builder` thì **qua cổng**. Còn #1881 khai `@return Builder<TillTenderType>`
 * cho một private helper — tức khai kiểu tường minh, thứ đáng khuyến khích —
 * và trở thành file DUY NHẤT bị bắt, làm `dev` đỏ.
 *
 * Luật cũ thưởng cho việc không khai kiểu. Nên nó bị thay, không phải nới.
 *
 * ── Luật mới đo cái gì ───────────────────────────────────────────────────
 *
 * Gọi tĩnh một phương thức truy vấn trên class import từ `App\Models\*`,
 * đếm trong THÂN file (comment/docblock bị loại bằng `token_get_all` — một
 * ví dụ trong docblock không phải một truy vấn).
 *
 * Ngoại lệ giữ nguyên như bản cũ: `Model::find()` / `::findOrFail()` trần,
 * dùng trong private resolver để đổi route param thành instance cho phân
 * quyền. Chúng KHÔNG mang điều kiện phạm vi nên không giấu được gì.
 * `Model::where('organization_id', …)->find($id)` thì bị bắt qua `where` —
 * đúng như phải thế: điều kiện phạm vi là thứ dễ mất nhất khi dời tầng.
 *
 * ── Vì sao có danh sách ân xá ────────────────────────────────────────────
 *
 * Luật mới đỏ 29 file cùng lúc. Một luật đỏ 29 chỗ mà không ai tắt được sẽ
 * bị tắt — nên nợ được khai TƯỜNG MINH kèm số đếm, cùng khuôn
 * `BUSINESS_TIME_GRANDFATHERED`. Danh sách chỉ được **co lại**: dời một
 * controller sang Service rồi hạ/xoá dòng của nó. Bộ đếm chặn cả hai chiều —
 * thêm truy vấn mới là đỏ, và sửa xong mà quên hạ số cũng đỏ.
 */

/**
 * Phương thức truy vấn — gọi tĩnh cái nào trong số này là đang truy vấn.
 *
 * `withTrashed` có mặt vì nó mở một query builder y như `query()`; bỏ nó ra
 * thì 11 controller đang lọc soft-delete ở tầng controller sẽ vô hình.
 */
const HQ_QUERY_METHODS = [
    'query', 'where', 'whereIn', 'whereNull', 'whereNotNull', 'whereHas',
    'first', 'firstOrFail', 'get', 'all', 'count', 'create', 'updateOrCreate',
    'firstOrCreate', 'insert', 'destroy', 'pluck', 'exists', 'with', 'select',
    'latest', 'orderBy', 'withTrashed', 'sum', 'max',
];

/**
 * Nợ đã tồn tại lúc luật được viết lại (#1920), khai theo SỐ ĐẾM mỗi file.
 *
 * Chỉ được co lại. Mỗi PR dời một controller sang Service thì hạ hoặc xoá
 * dòng tương ứng — xem phần 2 của #1920.
 */
const HQ_DIRECT_QUERY_GRANDFATHER = [
    'app/Http/Controllers/Api/V1/HQ/AllergenController.php' => 2,
    'app/Http/Controllers/Api/V1/HQ/CategoryController.php' => 2,
    'app/Http/Controllers/Api/V1/HQ/CouponController.php' => 2,
    'app/Http/Controllers/Api/V1/HQ/DeviceController.php' => 4,
    'app/Http/Controllers/Api/V1/HQ/EmployeeAdminController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/Iam/IamMemberController.php' => 4,
    'app/Http/Controllers/Api/V1/HQ/Iam/IamPermissionController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/Iam/IamRoleController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/MaterialController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/MaterialLotController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/NotificationAudienceAdminController.php' => 5,
    'app/Http/Controllers/Api/V1/HQ/NotificationBroadcastController.php' => 3,
    'app/Http/Controllers/Api/V1/HQ/NotificationChannelRouteAdminController.php' => 3,
    'app/Http/Controllers/Api/V1/HQ/NotificationCoverageController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/NotificationEmailSuppressionAdminController.php' => 8,
    'app/Http/Controllers/Api/V1/HQ/NotificationRuleAdminController.php' => 9,
    'app/Http/Controllers/Api/V1/HQ/NotificationScheduleAdminController.php' => 5,
    'app/Http/Controllers/Api/V1/HQ/NotificationTemplateAdminController.php' => 7,
    'app/Http/Controllers/Api/V1/HQ/ProductOptionController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/ProductTypeController.php' => 2,
    'app/Http/Controllers/Api/V1/HQ/RecipeController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/SettlementController.php' => 7,
    'app/Http/Controllers/Api/V1/HQ/ShopController.php' => 3,
    'app/Http/Controllers/Api/V1/HQ/TableTemplateController.php' => 2,
    'app/Http/Controllers/Api/V1/HQ/TaxTypeController.php' => 2,
    'app/Http/Controllers/Api/V1/HQ/ToppingGroupController.php' => 2,
    'app/Http/Controllers/Api/V1/HQ/VoidReasonController.php' => 1,
    'app/Http/Controllers/Api/V1/HQ/ZoneTemplateController.php' => 2,
];

/**
 * Thân file, đã loại comment và docblock.
 *
 * Không có bước này thì một ví dụ `Model::query()` viết trong docblock để
 * giải thích luật sẽ bị chính luật đếm là vi phạm.
 */
function hqControllerCodeOnly(string $contents): string
{
    $code = '';

    foreach (token_get_all($contents) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

/**
 * Đếm truy vấn Eloquent trực tiếp trong một controller.
 *
 * Chỉ tính class thật sự được import từ `App\Models\*`. Đây là chỗ phân biệt
 * `Model::where(...)` với `Str::where(...)` hay `$service->where(...)` — cùng
 * hình dạng, khác hẳn về nghĩa, và đếm nhầm chúng là điều hướng công việc sai.
 *
 * @return array<string, int> tên phương thức => số lần
 */
function hqDirectQueryCalls(string $contents): array
{
    preg_match_all('/^use (App\\\\Models\\\\[A-Za-z0-9_\\\\]+);/m', $contents, $matches);

    $models = [];
    foreach ($matches[1] as $fqcn) {
        $models[] = substr($fqcn, strrpos($fqcn, '\\') + 1);
    }

    if ($models === []) {
        return [];
    }

    $code = hqControllerCodeOnly($contents);
    $calls = [];

    foreach (array_unique($models) as $model) {
        $pattern = '/\b'.preg_quote($model, '/').'::([a-zA-Z_]+)\s*\(/';

        if (preg_match_all($pattern, $code, $found)) {
            foreach ($found[1] as $method) {
                if (in_array($method, HQ_QUERY_METHODS, true)) {
                    $calls[$method] = ($calls[$method] ?? 0) + 1;
                }
            }
        }
    }

    return $calls;
}

/** @return array<string, int> đường dẫn tương đối => tổng số truy vấn */
function hqDirectQueryViolations(): array
{
    $root = app_path('Http/Controllers/Api/V1/HQ');
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $calls = hqDirectQueryCalls((string) file_get_contents($file->getPathname()));

        if ($calls !== []) {
            $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            $violations[$relative] = array_sum($calls);
        }
    }

    ksort($violations);

    return $violations;
}

it('HQ controllers do not query Eloquent directly', function () {
    $violations = hqDirectQueryViolations();

    $newOffenders = [];
    foreach ($violations as $file => $count) {
        $allowed = HQ_DIRECT_QUERY_GRANDFATHER[$file] ?? 0;

        if ($count > $allowed) {
            $newOffenders[] = "{$file}: {$count} (ân xá: {$allowed})";
        }
    }

    expect($newOffenders)->toBe([], implode("\n", [
        'Truy vấn Eloquent trực tiếp trong controller HQ. Mọi đọc/ghi phải đi qua Service —',
        'điều kiện phạm vi (organization_id, branch_id) sống ở đó, và mất nó thì dữ liệu',
        'của tổ chức khác rò ra mà không có gì đỏ. Xem #1920.',
        ...$newOffenders,
    ]));
});

it('HQ grandfather list only ever shrinks', function () {
    $violations = hqDirectQueryViolations();

    $stale = [];
    foreach (HQ_DIRECT_QUERY_GRANDFATHER as $file => $allowed) {
        $actual = $violations[$file] ?? 0;

        if ($actual < $allowed) {
            $stale[] = "{$file}: giờ còn {$actual}, đang ân xá {$allowed} — hạ dòng này xuống";
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'Danh sách ân xá đã cũ. Dời controller sang Service rồi thì phải hạ số,',
        'nếu không nợ sẽ lặng lẽ mọc lại vào đúng chỗ vừa dọn:',
        ...$stale,
    ]));
});

arch('HQ controllers always extend Controller')
    ->expect('App\Http\Controllers\Api\V1\HQ')
    ->classes()
    ->toExtend('App\Http\Controllers\Controller');
