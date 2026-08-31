<?php

/**
 * #1240 — a factory that cannot create a row is a trap, not a helper.
 *
 * `FloatingSectionProductFactory` still set `selling_price` and
 * `is_price_overridden`, columns that moved to `floating_section_product_skus`
 * long ago. Every call died with "no such column". Nothing noticed, because
 * nothing used it — which is exactly how it rotted, and how the next one will.
 * `StockTransferFactory` was worse: `fake()->words(3, true)` into a backed enum
 * column, so it could never have worked at all, and it took
 * `StockTransferItemFactory` down with it.
 *
 * This test is the gate. It is deliberately a MEASURED one: the factories that
 * are broken today are listed below with a reason each, so the debt is visible
 * and shrinks rather than hiding. Adding a name here is allowed; adding one
 * without a reason is not.
 *
 * If a factory you just wrote fails this test, the factory is wrong — not the
 * test. Fix the factory rather than extending the list.
 */
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Known-broken factories. Each entry MUST say why.
 *
 * Two distinct kinds are mixed here on purpose, because the fix differs:
 *
 *   "phantom table"  — the factory names a table no migration creates. Either
 *                      the table was dropped and the factory outlived it, or it
 *                      belongs to another runtime entirely (menu_items is the
 *                      WORKSTATION's SQLite table, not a Cloud one). These are
 *                      dead code and should be deleted, not repaired. They also
 *                      overlap the schema drift tracked in #1216.
 *
 *   "needs context"  — a pivot or scoped row that genuinely cannot stand alone
 *                      (both sides must pre-exist, or a uniqueness scope must be
 *                      chosen by the caller). These want a factory state, not a
 *                      standalone default.
 */
const KNOWN_BROKEN_FACTORIES = [
    // phantom table — no migration creates these
    'AllergenMaterialFactory' => 'phantom table: allergen_materials',
    'CategoryProductFactory' => 'phantom table: category_products',
    'CouponBranchFactory' => 'phantom table: coupon_branches',
    'MenuItemFactory' => 'phantom table: menu_items is the WORKSTATION SQLite table, not a Cloud one',
    'MenuPromotionCategoryFactory' => 'phantom table: menu_promotion_categories',
    'MenuPromotionProductFactory' => 'phantom table: menu_promotion_products',
    'PostPostTagFactory' => 'phantom table: post_post_tags',

    // needs context — cannot stand alone
    'FloatingSectionProductSkuFactory' => 'needs context: NOT NULL parent floating_section_product_id',
    'MenuMenuSectionFactory' => 'needs context: pivot, both sides must pre-exist',
    // PaymentGatewayProviderFactory đã RỜI danh sách (#2318): nó hỏng vì mọi giá
    // trị enum của cột UNIQUE `code` đã được data migration
    // 2026_07_26_100000 seed sẵn trong lúc `migrate`. Migration đó bị xoá —
    // catalog giờ chỉ đến từ PaymentGatewayCatalogSeeder, mà RefreshDatabase
    // không chạy seeder — nên bảng rỗng và factory tự đứng được.
    'ProductToppingGroupFactory' => 'needs context: NOT NULL parent',
    'RoleFactory' => 'needs context: roles.name NOT NULL with no default',
    'RolePermissionFactory' => 'needs context: pivot, both sides must pre-exist',
    'RoleUserPivotFactory' => 'needs context: Role has no HasFactory trait',
];

it('every factory can create a row, except the ones we have written down', function () {
    $newlyBroken = [];
    $fixedButStillListed = [];
    $seen = [];

    foreach (glob(database_path('factories/*Factory.php')) as $file) {
        $name = basename($file, '.php');
        $seen[] = $name;
        $class = 'Database\\Factories\\'.$name;

        if (! class_exists($class) || ! is_subclass_of($class, Factory::class)) {
            continue;
        }

        $failed = false;

        // Each factory is created inside its own transaction and rolled back.
        // Without this the factories collide with EACH OTHER — one that seeds a
        // unique row makes a later one fail — and the gate's verdict then
        // depends on iteration order, which is the one thing a gate must not do.
        DB::beginTransaction();

        try {
            (new $class)->create();
        } catch (Throwable) {
            // The message is not asserted on purpose — it varies with the DB
            // driver, and what matters is only whether a row can be made.
            $failed = true;
        } finally {
            DB::rollBack();
        }

        if ($failed && ! array_key_exists($name, KNOWN_BROKEN_FACTORIES)) {
            $newlyBroken[] = $name;
        }

        if (! $failed && array_key_exists($name, KNOWN_BROKEN_FACTORIES)) {
            $fixedButStillListed[] = $name;
        }
    }

    expect($newlyBroken)->toBe([], sprintf(
        "These factories cannot create a row and are not on the known-broken list:\n  %s\n".
        'Fix the factory. Only add a name to KNOWN_BROKEN_FACTORIES with a stated reason.',
        implode("\n  ", $newlyBroken),
    ));

    // The list must shrink, and must not lie. A repaired factory left on it
    // would quietly re-open the hole for its neighbours.
    expect($fixedButStillListed)->toBe([], sprintf(
        "These factories now work — remove them from KNOWN_BROKEN_FACTORIES:\n  %s",
        implode("\n  ", $fixedButStillListed),
    ));

    // #3195 — LỖ CỦA CHÍNH BÁNH CÓC Ở TRÊN. Vòng lặp chỉ ghé những factory
    // CÒN TỒN TẠI, nên một entry trỏ vào factory đã bị XOÁ không bao giờ được
    // ghé, và `$fixedButStillListed` không thể phát hiện ra nó. Entry ấy sống
    // mãi — và nó là giấy phép cấp sẵn cho đúng cái tên đó nếu ai dựng lại một
    // factory cùng tên sau này.
    //
    // Cùng lớp lỗi với `allowedDuplicates = {40: true}` (#3184): danh sách chỉ
    // có thể dài ra khi bánh cóc chỉ nhìn thấy hiện tại.
    $vanished = array_diff(array_keys(KNOWN_BROKEN_FACTORIES), $seen);
    expect($vanished)->toBe([], sprintf(
        "KNOWN_BROKEN_FACTORIES nêu factory KHÔNG còn tồn tại:\n  %s\n".
        'Gỡ entry đi — nó không bảo vệ gì nữa, chỉ cấp sẵn giấy phép cho một factory cùng tên trong tương lai.',
        implode("\n  ", $vanished),
    ));

    // Mẫu số bằng không có ba nguồn, và một trong số đó là "không hàng nào
    // thuộc diện được hỏi". Không có phép đếm này thì một lần đổi bố cục
    // `database/factories/` sẽ làm cả bài IM thay vì đỏ.
    expect(count($seen))->toBeGreaterThan(50, sprintf(
        'chỉ quét ra %d factory — bố cục đã đổi, sửa bài test chứ đừng xoá nó.',
        count($seen),
    ));
});
