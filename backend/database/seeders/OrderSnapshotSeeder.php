<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CustomerOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ảnh chụp ĐƠN HÀNG của tenant production, để `migrate:fresh` dựng lại được một
 * hệ đầy đủ mà không mất giao dịch nào.
 *
 * `CatalogSnapshotSeeder` khôi phục catalog/menu/SKU; seeder này khôi phục phần
 * còn lại của một hệ đang chạy: đơn, dòng đơn, và thanh toán.
 *
 * ## Vì sao UPSERT, và vì sao KHÔNG delete
 *
 * `CatalogSnapshotSeeder` xoá-rồi-dựng-lại vì catalog là dữ liệu **do fixture
 * làm chủ**: một món không có trong fixture nghĩa là món đó không còn.
 *
 * Đơn hàng thì NGƯỢC LẠI — nó là **chứng từ**, sinh ra bởi khách và thu ngân,
 * không bởi ai đó sửa file JSON. Một đơn không có trong fixture chỉ có nghĩa là
 * fixture cũ hơn, KHÔNG có nghĩa là đơn đó không tồn tại. Nên seeder này chỉ
 * `upsert` theo `id`: chạy trên DB rỗng thì dựng đủ, chạy trên DB đang sống thì
 * không xoá gì.
 *
 * Nếu ngày nào có người đổi nó thành xoá-rồi-dựng cho "giống catalog", đó là
 * lúc mất doanh thu thật.
 *
 * ## Thứ tự BẮT BUỘC
 *
 * `customers` → `customer_orders` → `customer_order_items` → `order_payments`.
 * Dòng đơn và thanh toán mang khoá ngoại tới đơn; đảo thứ tự là chết ràng buộc.
 * `CatalogSnapshotSeeder` phải chạy TRƯỚC, vì dòng đơn trỏ tới `product_skus`.
 *
 * ## Cập nhật fixture — chụp RỒI ẨN DANH, hai bước, không bỏ bước hai
 *
 *     php artisan tinker --execute='echo json_encode(
 *       DB::table("customer_orders")->orderBy("id")->get()->map(fn($r)=>(array)$r)->all(),
 *       JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);'
 *     php database/seeders/fixtures/orders/_scrub_orders.php
 *
 * Bản chụp NGUYÊN VẸN của production **không được commit** (#2220): lần đầu nó
 * mang 11 email nhân viên thật kèm 11 cặp access/refresh token còn sống của
 * `id.godx.jp` (`aud: betoya-tempo-production`), 28 tên + số điện thoại khách,
 * và 287 `qr_token` — thứ mà `CustomerOrder::$hidden` và `CustomerOrderResource`
 * cố tình không cho lọt ra API. `_scrub_orders.php` là bước hai; nếu quên chạy,
 * `SeederFixturesCarryNoProductionSecretsTest` đỏ.
 *
 * Giá trị của ảnh chụp là HÌNH DẠNG — 11 nhân viên · 235 bàn · 6 ca · 20 phiên
 * bàn · 52 đơn · 65 dòng · 5 thanh toán, đủ trạng thái và đủ ràng buộc chéo —
 * chứ không phải danh tính con người. Tiền/thuế/mốc thời gian/khoá ngoại giữ
 * nguyên, chỉ cột người và cột bí mật bị thay.
 *
 * `customers` rỗng là ĐÚNG với thực tế lúc chụp — tenant chưa có khách tự đăng
 * ký; giữ file rỗng thay vì xoá đi, để lần chụp sau thấy ngay chỗ cần điền.
 *
 * ## Không bao giờ chạy trên production
 *
 * Seeder này GHI vào `users`/`tables`/`customer_orders`/`order_payments` bằng
 * `upsert`, tức nó có thể đè chứng từ thật bằng dữ liệu demo. `DatabaseSeeder`
 * đã không gọi nó ở nhánh production, nhưng `db:seed --class=OrderSnapshotSeeder
 * --force` thì chỉ cách một dòng lịch sử shell — nên từ chối thẳng
 * ({@see RefusesToRunInProduction}) thay vì tin người gọi.
 */
class OrderSnapshotSeeder extends Seeder
{
    use RefusesToRunInProduction;

    private const FIXTURE_DIR = __DIR__.'/fixtures/orders';

    private const CHUNK = 200;

    /** Thứ tự phụ thuộc khoá ngoại — KHÔNG sắp lại. */
    private const TABLES = [
        'users',
        'customers',
        'till_sessions',
        'table_sessions',
        'customer_orders',
        'customer_order_items',
        'order_payments',
    ];

    public function run(): void
    {
        $this->guardAgainstProduction();

        $remap = $this->rootIdMap();

        $this->ensureParentTills($remap);
        $this->recodeSquattingDemoOrders();

        // `tables.current_order_id` trỏ tới đơn, mà đơn chưa được chèn — nên
        // việc nối lại để CUỐI cùng. Bàn do CatalogSnapshotSeeder sở hữu (xem
        // TABLES), nên chỉ đọc quan hệ này ra khỏi fixture chứ không ghi bàn.
        foreach ($this->fixture('tables') as $row) {
            if (($row['current_order_id'] ?? null) !== null) {
                $this->deferredTableOrders[(string) $row['id']] = (string) $row['current_order_id'];
            }
        }

        foreach (self::TABLES as $table) {
            $rows = array_map(
                fn (array $row): array => $this->applyRemap($row, $remap),
                $this->fixture($table),
            );

            if ($table === 'customer_order_items') {
                $rows = $this->resolveLineTaxTypes($rows, $remap);
            }

            if ($rows === []) {
                $this->command?->info(sprintf('  %-24s (fixture rỗng, bỏ qua)', $table));

                continue;
            }

            // Chỉ giữ cột CÓ THẬT trên DB đích.
            //
            // Fixture chụp từ production, và production có thể mang cột mà code
            // hiện tại không tạo (đo được: `users.is_standalone`) — ngược chiều
            // với #1769. Không lọc thì seeder chết bằng `Unknown column`, và lỗi
            // đó nói về seeder trong khi vấn đề là schema lệch.
            //
            // Bỏ qua âm thầm cũng sai, nên chênh lệch được IN RA.
            $existing = Schema::getColumnListing($table);
            $dropped = array_values(array_diff(array_keys($rows[0]), $existing));

            // #2472 — ba cột tiền của `customer_orders` đã chuyển sang
            // `order_conditions` ở #2041, nên chúng LUÔN nằm trong `$dropped`.
            // Với ba cột NÀY "bỏ đi" nghĩa là vứt tiền: 40/52 đơn của ảnh chụp
            // khai tổng ¥6.925 thuế, và sau khi nạp chúng đọc ra 0 trong khi
            // `total_amount` vẫn gồm phần thuế đó — sổ không còn cộng ra bằng
            // header. Giữ lại ở đây rồi chiếu vào sổ sau khi các dòng đã có.
            if ($table === 'customer_orders') {
                $this->snapshotMoney = $this->captureSnapshotMoney($rows);
            }
            if ($dropped !== []) {
                $this->command?->warn(sprintf(
                    '  %-24s bỏ %d cột không có trên DB này: %s',
                    $table, count($dropped), implode(', ', $dropped),
                ));
                $rows = array_map(
                    static fn (array $row): array => array_intersect_key($row, array_flip($existing)),
                    $rows,
                );
            }

            $rows = $this->dropRowsWithMissingParents($table, $rows);
            if ($rows === []) {
                continue;
            }

            $columns = array_keys($rows[0]);
            // Cập nhật mọi cột trừ khoá — khoá là thứ dùng để đối chiếu.
            $updates = array_values(array_diff($columns, ['id']));

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table($table)->upsert($chunk, ['id'], $updates);
            }

            $this->command?->info(sprintf('  %-24s %d', $table, count($rows)));
        }

        // Các dòng đã nằm trong DB ⇒ chiếu được tiền đầu đơn xuống sổ điều kiện.
        $this->projectSnapshotMoneyIntoConditions();

        // Giờ đơn đã có, gán lại ô bị hoãn. Chỉ gán khi đơn THỰC SỰ tồn tại —
        // một bàn trỏ tới đơn không có là cùng loại lỗi mà vòng này gây ra.
        $restored = 0;
        foreach ($this->deferredTableOrders as $tableId => $orderId) {
            if (DB::table('customer_orders')->where('id', $orderId)->exists()) {
                DB::table('tables')->where('id', $tableId)->update(['current_order_id' => $orderId]);
                $restored++;
            }
        }
        if ($this->deferredTableOrders !== []) {
            $this->command?->info(sprintf(
                '  %-24s %d/%d bàn nối lại đơn đang mở',
                'tables.current_order', $restored, count($this->deferredTableOrders),
            ));
        }
    }

    /** @var array<string, string> bàn → đơn, hoãn lại vì vòng khoá ngoại */
    private array $deferredTableOrders = [];

    /**
     * #2472 — tiền đầu đơn của ảnh chụp, giữ lại trước khi bộ lọc cột vứt nó.
     *
     * @var array<string, array{discount_amount: float, service_charge: float, tax_amount: float}>
     */
    private array $snapshotMoney = [];

    /**
     * Đơn KHÁC chiếm chỗ unique key production thì bị re-key trước (#2221).
     *
     * `upsert(..., ['id'], ...)` chỉ khai báo ý định đối chiếu theo id — MySQL
     * `ON DUPLICATE KEY UPDATE` thực tế khớp theo MỌI unique key. Hai đường
     * chiếm chỗ đã đo được trên docker `migrate:fresh`:
     *
     * 1. Đơn demo (`CustomerOrderSeeder` sinh `ORD-YYYY-NNNN` cùng định dạng
     *    production) trùng `order_code` — 36 code.
     * 2. Chính upsert của một LẦN CHẠY HỎNG TRƯỚC ĐÓ: khi khớp nhầm qua
     *    `order_code`, MySQL UPDATE đè đơn demo bằng TOÀN BỘ cột fixture, nên
     *    đơn demo nuốt luôn `qr_token` + `client_order_id` production. Lần
     *    chạy sau dù đã re-code vẫn khớp nhầm tiếp qua token — phải quét đủ
     *    mọi unique key thì seeder mới tự chữa được DB đã nhiễm.
     *
     * Chiều xử lý: hàng chiếm chỗ là dữ liệu tổng hợp/nhiễm, còn giá trị unique
     * thuộc về đơn production (chứng từ) — nên sửa hàng chiếm chỗ, không sửa
     * fixture: `order_code` thêm `-DEMO` (varchar(50) dư chỗ), ba key nullable
     * còn lại xoá về NULL. Trên DB production thật không có kẻ chiếm chỗ nên
     * toàn bộ bước này là no-op.
     */
    private function recodeSquattingDemoOrders(): void
    {
        $fixture = $this->fixture('customer_orders');
        $fixtureIds = array_column($fixture, 'id');

        $remedies = [
            'order_code' => static fn (string $code): array => ['order_code' => $code.'-DEMO'],
            'qr_token' => static fn (): array => ['qr_token' => null],
            'client_order_id' => static fn (): array => ['client_order_id' => null],
            'stripe_payment_intent_id' => static fn (): array => ['stripe_payment_intent_id' => null],
        ];

        foreach ($remedies as $column => $remedy) {
            $values = array_filter(array_column($fixture, $column));
            if ($values === []) {
                continue;
            }

            $squatters = DB::table('customer_orders')
                ->whereIn($column, $values)
                ->whereNotIn('id', $fixtureIds)
                ->pluck($column, 'id');

            foreach ($squatters as $id => $value) {
                DB::table('customer_orders')->where('id', $id)->update($remedy((string) $value));
            }

            if ($squatters->isNotEmpty()) {
                $this->command?->warn(sprintf(
                    '  %-24s %d hàng chiếm %s production → re-key',
                    'customer_orders', $squatters->count(), $column,
                ));
            }
        }
    }

    /**
     * Fixture không chụp bảng `tills` (snapshot chỉ theo dòng tiền), nhưng
     * `till_sessions.till_id` mang FK tới nó — trên DB `migrate:fresh` chưa có
     * till nào thì insert gãy ngay (#2221, đo trên docker). Dựng till cha TỐI
     * THIỂU từ chính dữ liệu session: đủ giữ trọn chứng từ, `till_code`
     * SNAPSHOT-xx để nhìn là biết hàng khôi phục. Chỉ INSERT khi thiếu — DB
     * đang sống giữ nguyên till thật; khi nào snapshot chụp thêm `tills.json`
     * thì thêm bảng đó vào TABLES và bước này tự thành no-op.
     *
     * @param  array<string, string>  $remap
     */
    private function ensureParentTills(array $remap): void
    {
        $byTill = [];
        foreach ($this->fixture('till_sessions') as $row) {
            $tillId = (string) ($row['till_id'] ?? '');
            if ($tillId === '' || isset($byTill[$tillId])) {
                continue;
            }
            $byTill[$tillId] = $this->applyRemap([
                'id' => $tillId,
                'till_code' => sprintf('SNAPSHOT-%02d', count($byTill) + 1),
                'default_currency_code' => $row['default_currency_code'] ?? 'JPY',
                'variance_tolerance_amount' => 0,
                'is_active' => 1,
                'branch_id' => $row['branch_id'] ?? null,
                'brand_id' => $row['brand_id'] ?? null,
                'organization_id' => $row['organization_id'] ?? null,
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ], $remap);
        }

        if ($byTill === []) {
            return;
        }

        $existing = DB::table('tills')->whereIn('id', array_keys($byTill))->pluck('id')->all();
        $missing = array_values(array_diff_key($byTill, array_flip($existing)));

        if ($missing === []) {
            return;
        }

        DB::table('tills')->insert($missing);
        $this->command?->warn(sprintf(
            '  %-24s %d till cha dựng tối thiểu (fixture không chụp tills)',
            'tills', count($missing),
        ));
    }

    /**
     * Ánh xạ id GỐC PLATFORM: id trong fixture (của production) → id trên DB này.
     *
     * `organizations`/`brands`/`branches` do Platform sở hữu và được sinh MỚI ở
     * mỗi `migrate:fresh`, nên id của chúng khác nhau giữa hai môi trường. Ghi
     * thẳng id production vào là gãy khoá ngoại — đúng lỗi
     * `customer_orders_branch_id_foreign` mà bản đầu của seeder này mắc phải.
     *
     * Đối chiếu theo **slug**, cùng cách `CatalogSnapshotSeeder::branchMap()` làm.
     * Slug là thứ ổn định giữa hai môi trường; id thì không.
     *
     * @return array<string, string>
     */
    /**
     * Gán `tax_type_id` cho từng dòng đơn theo RATE CỦA CHÍNH DÒNG ĐÓ.
     *
     * Bản cũ map id-thuế-production → id-local ở {@see rootIdMap()} bằng rate
     * của dòng ĐẦU TIÊN mang id đó (`$rateByTaxId[$id] ??= $row['tax_rate']`).
     * Nhưng một id production ứng với nhiều rate trên các dòng khác nhau: trong
     * fixture hiện tại, 6 dòng mang id REDUCED cũ và dòng đầu tiên có
     * `tax_rate = 10.00`, nên cả 6 — kể cả 5 dòng snapshot 8% — bị dán nhãn
     * STANDARD (#2430). Tiền vẫn đúng (rate/amount là snapshot bất biến trên
     * chính dòng đơn), nhưng nhãn thì sai, và dữ liệu dev/QA sinh ra từ đây
     * chính là thứ người ta dùng để mắt-thường soát báo cáo thuế.
     *
     * Đi theo rate từng dòng thì không nhập nhằng được, nhờ bất biến #1099
     * "một type = MỘT rate mỗi brand": trong một brand, rate → type là một hàm.
     *
     * Rate không có type tương ứng ⇒ NÉM LỖI, đúng luật của
     * `docs/guide/tenant-provisioning.md`: *"Một id không ánh xạ được là LỖI,
     * không phải null"*. Ghi bừa một id thuế sai còn tệ hơn dừng lại — và để id
     * production thô đi tiếp thì cũng chỉ vỡ ở FK `RESTRICT`, với một thông báo
     * nói về driver thay vì nói về ánh xạ.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $remap
     * @return list<array<string, mixed>>
     */
    private function resolveLineTaxTypes(array $rows, array $remap): array
    {
        $brandId = $remap['__brands'] ?? null;

        // `whereNull('deleted_at')`: TaxType dùng SoftDeletes, và một brand đã
        // retire một type rate 8 rồi dựng type mới cũng rate 8 sẽ có HAI hàng
        // khớp — đúng cái nhập nhằng mà bản sửa này diệt. Chỉ loại còn sống mới
        // là nhãn hợp lệ cho một dòng đơn.
        $idByRate = [];
        foreach (
            DB::table('tax_types')
                ->whereNull('deleted_at')
                ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
                ->orderBy('id')
                ->get(['id', 'rate']) as $type
        ) {
            $idByRate[$this->rateKey($type->rate)] = (string) $type->id;
        }

        return array_map(function (array $row) use ($idByRate): array {
            if (($row['tax_type_id'] ?? '') === '' || $row['tax_type_id'] === null) {
                return $row;
            }

            $rate = $row['tax_rate'] ?? null;
            $local = $rate === null ? null : ($idByRate[$this->rateKey($rate)] ?? null);

            if ($local === null) {
                throw new RuntimeException(sprintf(
                    'OrderSnapshotSeeder: dòng đơn %s mang tax_rate %s nhưng brand này không có tax_type nào rate đó '
                    .'(có: %s). Seed catalog trước, hoặc ảnh chụp đơn lệch pha với ảnh chụp catalog.',
                    (string) ($row['id'] ?? '?'),
                    var_export($rate, true),
                    $idByRate === [] ? 'không có type nào' : implode(', ', array_keys($idByRate)),
                ));
            }

            $row['tax_type_id'] = $local;

            return $row;
        }, $rows);
    }

    /** Chuẩn hoá rate để `10`, `10.0`, `'10.00'` là cùng một khoá. */
    private function rateKey(mixed $rate): string
    {
        return number_format((float) $rate, 2, '.', '');
    }

    /**
     * Bỏ hàng trỏ vào CHA KHÔNG TỒN TẠI — LƯỚI AN TOÀN, không phải nhánh tương thích.
     *
     * Sau khi `fixtures/orders/*` được dịch sang thế hệ id của `catalog/*`
     * (#2440), hàm này bỏ **0 hàng** — và phải giữ nguyên con số đó. Nó tồn tại
     * vì lần lệch pha vừa rồi cho thấy hậu quả: vài hàng lẻ trỏ vào bàn hoặc chi
     * nhánh không có làm `migrate:fresh --seed` chết ở
     * `table_sessions_table_id_foreign`, tức mất TOÀN BỘ hệ vì mấy hàng đó.
     *
     * Bộ seed này là init data của cả hệ (ベトや) nên "một lệnh lên trọn hệ" là
     * yêu cầu: một lần chụp lại lệch pha nữa không được phép chặn việc dựng hệ.
     * Nó chỉ được phép IN RA thứ nó đã bỏ — im lặng thì người ta tin ảnh chụp
     * đã vào đủ, và đó mới là cái hỏng thật.
     *
     * Kiểm theo DB THẬT, không theo fixture: thứ tự ở {@see TABLES} là thứ tự
     * phụ thuộc nên cha luôn đã được chèn trước khi con bị soi.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    /**
     * Giữ ba cột tiền của ảnh chụp trước khi bộ lọc cột vứt chúng (#2472).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{discount_amount: float, service_charge: float, tax_amount: float}>
     */
    private function captureSnapshotMoney(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $money = [
                'discount_amount' => (float) ($row['discount_amount'] ?? 0),
                'service_charge' => (float) ($row['service_charge'] ?? 0),
                'tax_amount' => (float) ($row['tax_amount'] ?? 0),
            ];

            if (array_sum(array_map(abs(...), $money)) > 0.0) {
                $out[$id] = $money;
            }
        }

        return $out;
    }

    /**
     * Chiếu tiền đầu đơn của ảnh chụp xuống `order_conditions` (#2472).
     *
     * `writeConditions()` là nguồn chân lý của sổ này và nó khai ba bất biến:
     *
     *     Σ(tax).amount            ==  order.tax_amount
     *     Σ(discount).amount       == −order.discount_amount
     *     Σ(service_charge).amount ==  order.service_charge
     *
     * Nhưng nó là model event, còn seeder cố ý đi `DB::table()->upsert()` cho cả
     * khối, nên phép dịch phải làm tay ở đây.
     *
     * Hai rào, cả hai đều là "thà mất chi tiết còn hơn sổ khai sai tổng":
     *
     * 1. **CHỈ THÊM, không bao giờ đè.** Đơn đã có dòng thuộc ba loại này thì bỏ
     *    qua NGUYÊN ĐƠN. Trên một DB đang sống, sổ do `writeConditions` ghi giàu
     *    hơn nhiều (một dòng mỗi mức kèm `taxable_base`); xoá nó để thay bằng
     *    một dòng phẳng của ảnh chụp cũ là đi lùi.
     * 2. **Tách theo mức CHỈ KHI cộng đúng.** Nhóm theo `tax_rate` của các dòng
     *    chưa huỷ; Σ khớp header thì ghi từng mức, lệch thì ghi MỘT dòng phẳng
     *    bằng đúng con số đầu đơn. Ảnh chụp hiện tại có 24/40 đơn lệch, nên
     *    nhánh phẳng không phải trường hợp hiếm — nó là đường chính.
     */
    private function projectSnapshotMoneyIntoConditions(): void
    {
        if ($this->snapshotMoney === []) {
            return;
        }

        $orderIds = array_keys($this->snapshotMoney);
        $morph = (new CustomerOrder)->getMorphClass();

        // Rào 1 — đơn nào đã có sổ thì không đụng tới.
        $alreadyLedgered = DB::table('order_conditions')
            ->where('conditionable_type', $morph)
            ->whereIn('conditionable_id', $orderIds)
            ->whereIn('type', ['tax', 'discount', 'service_charge'])
            ->distinct()
            ->pluck('conditionable_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $currencyByOrder = DB::table('customer_orders')
            ->leftJoin('shop_order_settings', 'shop_order_settings.branch_id', '=', 'customer_orders.branch_id')
            ->whereIn('customer_orders.id', $orderIds)
            ->select(['customer_orders.id as order_id', 'shop_order_settings.currency_code as currency_code'])
            ->pluck('currency_code', 'order_id')
            ->all();

        // Thuế theo dòng, chỉ tính dòng CHƯA HUỶ — dòng đã huỷ không còn là tiền.
        $lineTax = DB::table('customer_order_items')
            ->whereIn('customer_order_id', $orderIds)
            ->where('status', '!=', 'voided')
            ->selectRaw('customer_order_id, tax_rate, SUM(tax_amount) AS tax_sum')
            ->groupBy('customer_order_id', 'tax_rate')
            ->get();

        $byOrder = [];
        foreach ($lineTax as $row) {
            $byOrder[(string) $row->customer_order_id][] = [
                'rate' => (float) $row->tax_rate,
                'amount' => round((float) $row->tax_sum, 2),
            ];
        }

        $now = now();
        $rows = [];
        $flatOrders = 0;
        $splitOrders = 0;

        foreach ($this->snapshotMoney as $orderId => $money) {
            if (in_array($orderId, $alreadyLedgered, true)) {
                continue;
            }

            $currency = (string) ($currencyByOrder[$orderId] ?? 'JPY');
            $make = static function (string $type, string $label, ?float $rate, float $amount, array $meta) use (&$rows, $orderId, $morph, $currency, $now): void {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'conditionable_type' => $morph,
                    'conditionable_id' => $orderId,
                    'type' => $type,
                    'source' => 'snapshot',
                    'label' => $label,
                    'rate' => $rate,
                    'amount' => $amount,
                    'taxable_base' => null,
                    'currency_code' => $currency,
                    'meta' => json_encode($meta + ['seeded_from' => 'order_snapshot'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            };

            if ($money['tax_amount'] != 0.0) {
                $groups = $byOrder[$orderId] ?? [];
                $sum = round(array_sum(array_column($groups, 'amount')), 2);

                if ($groups !== [] && abs($sum - round($money['tax_amount'], 2)) < 0.005) {
                    $splitOrders++;
                    foreach ($groups as $g) {
                        $label = rtrim(rtrim(number_format($g['rate'], 2), '0'), '.').'%';
                        $make('tax', $label, $g['rate'], $g['amount'], ['rate_group' => (string) $g['rate']]);
                    }
                } else {
                    // Σ theo dòng KHÔNG khớp header. Ghi một dòng phẳng bằng đúng
                    // con số đầu đơn: sổ luôn phải cộng ra bằng tiền đã thu, và
                    // mất chi tiết mức rẻ hơn nhiều so với khai một tổng khác.
                    $flatOrders++;
                    $make('tax', 'Thuế (ảnh chụp)', null, round($money['tax_amount'], 2), ['unsplit_reason' => 'line_sum_mismatch']);
                }
            }

            if ($money['discount_amount'] != 0.0) {
                // Bất biến của sổ: Σ(discount).amount == −order.discount_amount.
                $make('discount', 'Giảm giá (ảnh chụp)', null, -1.0 * round($money['discount_amount'], 2), []);
            }

            if ($money['service_charge'] != 0.0) {
                $make('service_charge', 'Phí phục vụ (ảnh chụp)', null, round($money['service_charge'], 2), []);
            }
        }

        if ($rows === []) {
            // Im lặng ở đây là sai: lượt seed thứ hai KHÔNG ghi gì là hành vi
            // ĐÚNG (rào "chỉ thêm"), nhưng không nói ra thì nó trông y hệt lúc
            // phép chiếu hỏng.
            $this->command?->info(sprintf(
                '  %-24s 0 dòng — cả %d đơn đều đã có sổ, không đè',
                'order_conditions', count($this->snapshotMoney),
            ));

            return;
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('order_conditions')->insert($chunk);
        }

        $this->command?->info(sprintf(
            '  %-24s %d dòng cho %d đơn (%d tách theo mức, %d phẳng, %d đơn bỏ qua vì đã có sổ)',
            'order_conditions', count($rows), $splitOrders + $flatOrders,
            $splitOrders, $flatOrders, count($alreadyLedgered),
        ));
    }

    private function dropRowsWithMissingParents(string $table, array $rows): array
    {
        /** @var array<string, array<string, string>> cột => bảng cha */
        $foreignKeys = [
            'till_sessions' => ['branch_id' => 'branches', 'till_id' => 'tills'],
            'table_sessions' => ['branch_id' => 'branches', 'table_id' => 'tables'],
            'customer_orders' => ['branch_id' => 'branches', 'table_id' => 'tables'],
            'customer_order_items' => ['customer_order_id' => 'customer_orders', 'product_sku_id' => 'product_skus'],
            'order_payments' => ['customer_order_id' => 'customer_orders', 'branch_id' => 'branches'],
        ];

        $rules = $foreignKeys[$table] ?? [];
        if ($rules === []) {
            return $rows;
        }

        $alive = [];
        foreach ($rules as $column => $parentTable) {
            $values = array_values(array_unique(array_filter(
                array_map(static fn (array $r): string => (string) ($r[$column] ?? ''), $rows),
                static fn (string $v): bool => $v !== '',
            )));
            $alive[$column] = $values === []
                ? []
                : array_flip(DB::table($parentTable)->whereIn('id', $values)->pluck('id')->all());
        }

        $kept = [];
        $dropped = [];
        foreach ($rows as $row) {
            foreach ($rules as $column => $parentTable) {
                $value = (string) ($row[$column] ?? '');
                if ($value !== '' && ! isset($alive[$column][$value])) {
                    $dropped[$column] = ($dropped[$column] ?? 0) + 1;

                    continue 2;
                }
            }
            $kept[] = $row;
        }

        foreach ($dropped as $column => $count) {
            $this->command?->warn(sprintf(
                '  %-24s bỏ %d hàng: %s trỏ vào %s không có trên hệ này — ảnh chụp đơn lệch pha catalog (#2440)',
                $table, $count, $column, $rules[$column],
            ));
        }

        return $kept;
    }

    private function rootIdMap(): array
    {
        $map = [];

        $branchFixture = json_decode(
            (string) file_get_contents(__DIR__.'/fixtures/catalog/branches.json'),
            true,
        ) ?: [];
        $localBySlug = DB::table('branches')->pluck('id', 'slug');

        foreach ($branchFixture as $row) {
            $local = $localBySlug[$row['slug'] ?? ''] ?? null;
            if ($local !== null) {
                $map[(string) $row['id']] = (string) $local;
            }
        }

        // Chỉ có MỘT tổ chức và MỘT thương hiệu, nên lấy thẳng — không có slug
        // nào để đối chiếu cho chắc hơn.
        foreach (['organizations', 'brands'] as $rootTable) {
            $local = DB::table($rootTable)->value('id');
            if ($local === null) {
                continue;
            }
            foreach (DB::table($rootTable)->pluck('id') as $id) {
                $map[(string) $id] = (string) $id;
            }
            $map['__'.$rootTable] = (string) $local;
        }

        // tax_types KHÔNG map ở đây — xem resolveLineTaxTypes(). Một id thuế
        // production ứng với NHIỀU rate trên các dòng đơn khác nhau, nên map
        // theo id là sai từ trong thiết kế (#2430).

        // payment_methods: cũng sinh id mới mỗi fresh. Fixture không chụp bảng
        // này, nhưng settlement_snapshot của chính các ca trong fixture khai
        // toàn bộ 5 thanh toán là cash — nên pm id production nào không tồn tại
        // local thì map về method `cash`. Trên production id tồn tại → no-op.
        $pmIds = [];
        foreach ($this->fixture('order_payments') as $row) {
            $pmId = (string) ($row['payment_method_id'] ?? '');
            if ($pmId !== '') {
                $pmIds[$pmId] = true;
            }
        }
        if ($pmIds !== []) {
            $existing = DB::table('payment_methods')->whereIn('id', array_keys($pmIds))->pluck('id')->all();
            $localCash = DB::table('payment_methods')->where('code', 'cash')->value('id');
            foreach (array_keys(array_diff_key($pmIds, array_flip($existing))) as $missing) {
                if ($localCash !== null) {
                    $map[$missing] = (string) $localCash;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    private function applyRemap(array $row, array $map): array
    {
        foreach ($row as $column => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            if (isset($map[$value])) {
                $row[$column] = $map[$value];
            } elseif ($column === 'organization_id' && isset($map['__organizations'])) {
                $row[$column] = $map['__organizations'];
            } elseif ($column === 'brand_id' && isset($map['__brands'])) {
                $row[$column] = $map['__brands'];
            }
        }

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function fixture(string $name): array
    {
        $path = self::FIXTURE_DIR."/{$name}.json";

        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) ?? [];
    }
}
