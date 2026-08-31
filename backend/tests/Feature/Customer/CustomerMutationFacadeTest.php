<?php

declare(strict_types=1);

/**
 * #1550 — ranh giới Customer, xây thật.
 *
 * 15 method facade + bốn cổng (query · xác thực · xác minh thẩm quyền ·
 * persistence) từng là interface không ai implement.
 *
 * ## Tính chất quan trọng nhất ở đây KHÁC Menu
 *
 * Menu chỉ cần "ghi thật". Customer còn phải chứng minh **không có đường vòng**:
 * persistence nhận `VerifiedCustomerMutation`, một vật thể chỉ cổng xác minh
 * đóng dấu được, và `VerificationAuthority` fail-closed nếu cổng đó chưa được
 * liệt kê trong `config/domain_mutation.php`.
 */

use App\Models\Branch;
use App\Models\BranchReview;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerPointEntry;
use App\Models\Organization;
use App\Models\ProductReview;
use App\Services\Customer\Commands\CustomerLifecycleCommand;
use App\Services\Customer\Commands\MergeCustomersCommand;
use App\Services\Customer\Commands\RegisterCustomerCommand;
use App\Services\Customer\Contracts\CustomerMutationFacade;
use App\Services\Customer\Contracts\CustomerPersistencePort;
use App\Services\Customer\Contracts\CustomerQueryPort;
use App\Services\Customer\Enums\CustomerLifecycleAction;
use App\Services\Customer\Enums\CustomerScopeKind;
use App\Services\Customer\Internal\EloquentCustomerAuthorityVerification;
use App\Services\Customer\ValueObjects\CustomerScopeEvidence;
use App\Services\Customer\ValueObjects\TenantCustomerProfilePayload;
use App\Services\DomainMutation\MutationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->facade = app(CustomerMutationFacade::class);
});

function cmfContext(?string $orgId = null, ?int $version = 1): MutationContext
{
    return new MutationContext(
        $orgId ?? test()->orgId,
        (string) Str::uuid(),
        'corr-'.Str::random(8),
        'idem-'.Str::random(12),
        $version,
    );
}

function cmfScope(): CustomerScopeEvidence
{
    return new CustomerScopeEvidence(
        CustomerScopeKind::TenantCrm,
        test()->orgId,
        (string) test()->brand->id,
        (string) test()->branch->id,
        test()->orgId,
        (string) test()->brand->id,
    );
}

it('#1550 register GHI THẬT — khách xuất hiện với id do người gọi cấp', function () {
    $customerId = (string) Str::uuid();
    $payload = new TenantCustomerProfilePayload('Lan', 'Nguyễn', 'lan@example.com', '0900000001');

    $result = $this->facade->register(new RegisterCustomerCommand(
        cmfContext(),
        $customerId,
        (string) $this->branch->id,
        (string) $this->brand->id,
        $payload,
        $payload->fingerprint(),
        cmfScope(),
    ));

    // Id do NGƯỜI GỌI cấp — `'id'` không nằm trong `$fillable` của `Customer`,
    // nên nếu không bọc `unguarded()` thì hàng sinh ra mang uuid khác và lượt
    // gửi lại sẽ tạo bản ghi thứ hai (#1744).
    expect($result->customerId)->toBe($customerId);

    $row = Customer::query()->find($customerId);
    expect($row)->not->toBeNull()
        ->and($row->first_name)->toBe('Lan')
        ->and((string) $row->organization_id)->toBe($this->orgId);
});

it('#1550 persistence KHÔNG nhận Command trần — không có đường vòng qua khâu xác minh', function () {
    // Đây là tính chất riêng của ranh giới này. Chữ ký của `archive()` ở
    // persistence là `VerifiedCustomerMutation`, nên gọi thẳng bằng Command sẽ
    // không qua được kiểu — "quên xác minh" không phải lỗi cần nhớ để tránh.
    $port = new ReflectionClass(CustomerPersistencePort::class);

    $verified = 0;
    foreach ($port->getMethods() as $m) {
        $type = $m->getParameters()[0]->getType();
        if ($type !== null && str_contains((string) $type, 'VerifiedCustomerMutation')) {
            $verified++;
        }
    }

    expect($verified)->toBe(11);
});

it('#1550 cổng xác minh FAIL-CLOSED nếu không có tên trong config', function () {
    // `VerificationAuthority` chỉ cấp quyền đóng dấu cho class được liệt kê
    // tường minh. Bỏ mục đó đi thì MỌI thao tác ghi khách dừng — kể cả khi class
    // vẫn implement đúng interface.
    config(['domain_mutation.issuance_adapters' => []]);

    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    // `toThrow(Throwable::class)` KHÔNG dùng được: `Throwable` là interface và
    // Pest đọc chuỗi đó thành THÔNG ĐIỆP cần khớp, nên test đỏ với "Expected …
    // To contain: Throwable" kể cả khi ngoại lệ đã ném đúng. Bắt tay.
    $threw = null;
    try {
        $this->facade->archive(new CustomerLifecycleCommand(
            cmfContext(),
            (string) $customer->id,
            CustomerScopeKind::TenantCrm,
            CustomerLifecycleAction::Archive,
            'auth-event-'.Str::random(6),
        ));
    } catch (Throwable $e) {
        $threw = $e;
    }

    expect($threw)->not->toBeNull('cổng KHÔNG fail-closed — thao tác ghi vẫn chạy khi verifier không có trong config')
        ->and($threw->getMessage())->toContain('issuance authority');

    expect(Customer::query()->find($customer->id))->not->toBeNull();
});

it('#1550 archive rồi restore — restore là thao tác DUY NHẤT thấy hàng đã xoá mềm', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->facade->archive(new CustomerLifecycleCommand(
        cmfContext(), (string) $customer->id, CustomerScopeKind::TenantCrm,
        CustomerLifecycleAction::Archive, 'auth-'.Str::random(6),
    ));
    expect(Customer::query()->find($customer->id))->toBeNull();

    $this->facade->restore(new CustomerLifecycleCommand(
        cmfContext(), (string) $customer->id, CustomerScopeKind::TenantCrm,
        CustomerLifecycleAction::Restore, 'auth-'.Str::random(6),
    ));
    expect(Customer::query()->find($customer->id))->not->toBeNull();
});

it('#1550 merge CHUYỂN tham chiếu sang bên giữ lại, không bỏ lại đơn nào', function () {
    $keep = Customer::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->branch->id]);
    $drop = Customer::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->branch->id]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'customer_id' => $drop->id,
    ]);

    // Một dòng cho MỖI bảng trong kế hoạch gộp — nếu không thì phép đếm cuối
    // hàm đếm số 0 trên bảng rỗng và trả lời "đạt" cho một câu chưa từng hỏi.
    Coupon::factory()->create(['customer_id' => $drop->id]);
    CouponRedemption::factory()->create(['customer_id' => $drop->id]);
    CustomerPointEntry::factory()->create(['customer_id' => $drop->id]);
    BranchReview::factory()->create(['customer_id' => $drop->id]);
    ProductReview::factory()->create(['customer_id' => $drop->id]);

    foreach (EloquentCustomerAuthorityVerification::MERGE_REFERENCES as $table) {
        expect(DB::table($table)->where('customer_id', $drop->id)->count())
            ->toBeGreaterThan(0, "chưa dựng dòng nào ở {$table} — phép đo sau sẽ rỗng");
    }

    $result = $this->facade->merge(new MergeCustomersCommand(
        cmfContext(),
        (string) $drop->id,
        (string) $keep->id,
        'auth-event-'.Str::random(8),
    ));

    expect($result->merged)->toBeTrue()
        // Đơn phải theo khách được GIỮ LẠI. Bỏ sót bảng nào trong kế hoạch gộp
        // là dữ liệu khách bị bỏ lại sau lưng, âm thầm.
        ->and((string) $order->fresh()->customer_id)->toBe((string) $keep->id)
        ->and(Customer::query()->find($drop->id))->toBeNull()
        ->and(Customer::query()->find($keep->id))->not->toBeNull();

    // CẢ SÁU bảng, không riêng đơn hàng. Test này trước chỉ dựng một đơn, và
    // phép gỡ đo được: xoá hẳn lệnh ghi `coupons` khỏi cổng Pricing thì toàn bộ
    // suite VẪN XANH — tức lượt gộp có thể bỏ lại coupon của khách mà không rào
    // nào kêu. Vòng lặp dưới đây đọc chính hằng số kiểm toán, nên thêm bảng vào
    // hằng số mà quên ghi sẽ đỏ ở ĐÂY, chứ không đợi ai đó phát hiện lúc vận hành.
    foreach (EloquentCustomerAuthorityVerification::MERGE_REFERENCES as $table) {
        expect(DB::table($table)->where('customer_id', $drop->id)->count())
            ->toBe(0, "gộp bỏ lại dòng ở {$table} cho khách đã biến mất");
    }
});

it('#1550 kế hoạch gộp khớp KHOÁ NGOẠI thật — không phải danh sách chép tay', function () {
    // Chép tay là danh sách cũ đi ngay lần ai thêm bảng có `customer_id`, và
    // triệu chứng là dữ liệu bị bỏ lại sau khi gộp. Test này đối chiếu hằng số
    // với schema đang chạy.
    foreach (EloquentCustomerAuthorityVerification::MERGE_REFERENCES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("bảng {$table} không tồn tại");
        expect(Schema::hasColumn($table, 'customer_id'))->toBeTrue("{$table} không có cột customer_id");
    }
});

it('#1550 không gộp khách vào chính nó', function () {
    $c = Customer::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->branch->id]);

    expect(fn () => $this->facade->merge(new MergeCustomersCommand(
        cmfContext(), (string) $c->id, (string) $c->id, 'auth-'.Str::random(8),
    )))->toThrow(InvalidArgumentException::class);
});

it('#1550 cổng đọc phân biệt TÀI KHOẢN TOÀN CỤC với bản ghi CRM', function () {
    // Hai loại khách sống chung một bảng. Tra tài khoản đăng nhập mà không lọc
    // `organization_id IS NULL` sẽ trúng bản ghi CRM trùng email — và ngược lại.
    $crm = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'email' => 'trung@example.com',
        'password' => null,
    ]);

    $query = app(CustomerQueryPort::class);

    expect($query->findGlobalAccountByEmail('trung@example.com'))->toBeNull()
        ->and($query->findTenantCustomerById($this->orgId, (string) $this->branch->id, (string) $crm->id))->not->toBeNull();
});

/*
 * #1550 — neo danh sách kiểm toán vào các lệnh ghi thật.
 *
 * `MERGE_REFERENCES` cố ý KHÔNG được lặp (tên bảng động thì
 * `architecture:domain-writers` chỉ đọc được `dynamic-table`), nên hai thứ có
 * thể trôi khỏi nhau: thêm một bảng vào hằng số mà quên viết lệnh ghi thì lượt
 * gộp bỏ sót dữ liệu của khách — im lặng, đúng kiểu hỏng mà hằng số này sinh ra
 * để chặn. Test đọc mã nguồn vì đó là chỗ duy nhất sự thật nằm.
 */
it('writes every table named in the merge audit list', function () {
    $src = file_get_contents(app_path('Services/Customer/Internal/EloquentCustomerPersistence.php'));
    $body = substr($src, strpos($src, 'public function mergeCustomers'));
    $body = substr($body, 0, strpos($body, "\n    }\n"));

    preg_match_all("/DB::table\('([a-z_]+)'\)/", $body, $m);
    $written = $m[1];

    // Hai bảng có chủ đi qua cổng của aggregate sở hữu, không qua DB::table.
    $viaPorts = ['customer_orders', 'coupon_redemptions', 'coupons'];
    foreach ($viaPorts as $table) {
        expect($written)->not->toContain($table);
    }
    expect($body)->toContain('orderReassignment->reassignCustomer')
        ->and($body)->toContain('couponReassignment->reassignCustomer');

    $covered = array_merge($written, $viaPorts);
    sort($covered);
    $expected = EloquentCustomerAuthorityVerification::MERGE_REFERENCES;
    sort($expected);

    expect($covered)->toBe($expected);

    // Và không có tên bảng ĐỘNG nào lọt lại vào đường ghi. Bỏ dòng chú thích
    // trước đã: khối giải thích ngay trên chỗ ghi có nhắc `DB::table($biến)`
    // theo đúng nghĩa đen, và bản đầu của chính test này bắt phải văn xuôi đó.
    $code = preg_replace('/^\s*\/\/.*$/m', '', $body);
    expect($code)->not->toMatch('/DB::table\(\$/');
});
