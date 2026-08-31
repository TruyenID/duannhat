<?php

namespace App\Providers;

use App\Services\Customer\Contracts\CustomerAuthorityVerificationPort;
use App\Services\Customer\Contracts\CustomerDirectory;
use App\Services\Customer\Contracts\CustomerMutationFacade;
use App\Services\Customer\Contracts\CustomerPersistencePort;
use App\Services\Customer\Contracts\CustomerQueryPort;
use App\Services\Customer\CustomerMutationService;
use App\Services\Customer\Internal\EloquentCustomerAuthorityVerification;
use App\Services\Customer\Internal\EloquentCustomerDirectory;
use App\Services\Customer\Internal\EloquentCustomerPersistence;
use App\Services\Customer\Internal\EloquentCustomerQuery;
use Illuminate\Support\ServiceProvider;

/**
 * #1550 — ranh giới đột biến của CustomerEngagement.
 *
 * Có provider RIÊNG chứ không nằm trong `AppServiceProvider` vì
 * `DomainMutationContractsTest` cấm thẳng: `AppServiceProvider` không được chứa
 * chuỗi `MutationFacade` / `PersistencePort` / `QueryPort`. Bản dựng đầu bind ở
 * đó và bị bắt — luật này tồn tại để hợp đồng chuẩn của plan-047 nằm cạnh module
 * sở hữu chúng, thay vì dồn hết vào một tệp mà không ai đọc nổi ranh giới nào
 * thuộc về ai. Menu đi cùng đường, vào {@see ProductServiceProvider}.
 *
 * `CustomerAuthorityVerificationPort` bind ở đây, nhưng quyền PHÁT HÀNH của nó
 * không đến từ chỗ bind: `config/domain_mutation.php` mới là allowlist, và một
 * lớp chỉ vì implement interface thì không tự có quyền — không có mục trong
 * config nghĩa là fail-closed.
 */
final class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Thứ tự đọc: đọc → xác minh thẩm quyền → ghi → mặt tiền. Lớp ghi KHÔNG
        // nhận Command trần, nên không có đường nào ghi lên khách mà bỏ qua khâu
        // xác minh — cưỡng chế bằng KIỂU, không bằng quy ước.
        $this->app->bind(CustomerQueryPort::class, EloquentCustomerQuery::class);
        $this->app->bind(CustomerAuthorityVerificationPort::class, EloquentCustomerAuthorityVerification::class);
        $this->app->bind(CustomerPersistencePort::class, EloquentCustomerPersistence::class);
        $this->app->bind(CustomerMutationFacade::class, CustomerMutationService::class);

        // #1993 — cổng ĐỌC hiển thị, không thuộc chu trình đột biến ở trên: sổ nợ
        // cần gọi tên người đang thiếu tiền. Nằm ở đây vì cách gọi tên một khách
        // là định nghĩa của CustomerEngagement, không phải của màn hình đọc nó.
        $this->app->bind(CustomerDirectory::class, EloquentCustomerDirectory::class);
    }
}
