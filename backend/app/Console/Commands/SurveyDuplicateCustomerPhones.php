<?php

namespace App\Console\Commands;

use App\Services\Customer\CustomerLoginIdentifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * #1782 — số điện thoại nào đang ứng với NHIỀU tài khoản đăng nhập?
 *
 * ## Vì sao là một lệnh khảo sát, không phải một bản vá
 *
 * #1782 để treo đúng một câu hỏi: `customers.phone` không unique, vậy khi số
 * trùng thì unique hoá hay từ chối đăng nhập? Đăng nhập đã chốt **từ chối** —
 * không bao giờ xác thực một định danh mơ hồ, vì chọn bừa một hàng nghĩa là có
 * thể đăng nhập vào tài khoản người khác.
 *
 * Nhưng "từ chối" để lại một nhóm khách không dùng được SĐT mà không hiểu vì
 * sao. Nhóm đó lớn bao nhiêu thì **chỉ dữ liệu thật mới trả lời được**, và dữ
 * liệu thật nằm ở staging/production chứ không ở đây. Lệnh này chỉ ĐỌC, chạy
 * được ở mọi môi trường.
 *
 *     php artisan customers:survey-duplicate-phones
 *     php artisan customers:survey-duplicate-phones --json
 *
 * ## Chỉ đếm hàng ĐĂNG NHẬP ĐƯỢC
 *
 * `whereNotNull('password')` — cùng vị từ với `login()`. Hàng CRM không mật khẩu
 * trùng số là chuyện bình thường và vô hại (không ai đăng nhập bằng nó), nên gộp
 * chúng vào sẽ thổi phồng con số và biến một quyết định thành hoảng loạn.
 *
 * ## Giới hạn đã biết
 *
 * Nhóm theo GIÁ TRỊ THÔ trong cột, không chuẩn hoá. Nghĩa là `0901234567` và
 * `+84901234567` của cùng một người sẽ KHÔNG bị đếm là trùng ở đây, trong khi
 * `login()` — vốn tra theo biến thể — lại coi chúng là trùng và từ chối. Con số
 * dưới đây vì thế là **cận dưới**. Cột `theo_biến_thể` bù phần đó lại bằng cách
 * đếm đúng như lúc đăng nhập, nhưng nó quét toàn bảng nên chỉ chạy khi có
 * `--deep`.
 */
#[Signature('customers:survey-duplicate-phones {--json : Xuất JSON} {--deep : Đếm thêm theo BIẾN THỂ (quét toàn bảng, chậm)}')]
#[Description('#1782 — số điện thoại ứng với nhiều tài khoản đăng nhập; cần trước khi quyết unique hoá.')]
class SurveyDuplicateCustomerPhones extends Command
{
    public function handle(): int
    {
        $exact = $this->exactDuplicates();

        if ($this->option('json')) {
            $this->line(json_encode([
                'exact_duplicate_phones' => count($exact),
                'accounts_affected' => array_sum(array_column($exact, 'accounts')),
                'rows' => $exact,
                'variant_duplicate_phones' => $this->option('deep') ? count($this->variantDuplicates()) : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($exact === []) {
            $this->info('✔ Không có số nào ứng với nhiều tài khoản đăng nhập (so khớp THÔ).');
        } else {
            $this->warn(sprintf(
                '⚠ %d số ứng với nhiều tài khoản, tổng %d tài khoản bị ảnh hưởng.',
                count($exact),
                array_sum(array_column($exact, 'accounts')),
            ));
            $this->table(['Số', 'Số tài khoản'], array_map(
                static fn (array $r): array => [$r['phone'], $r['accounts']],
                array_slice($exact, 0, 50),
            ));
            if (count($exact) > 50) {
                $this->line(sprintf('  … và %d số nữa. Dùng --json để lấy đủ.', count($exact) - 50));
            }
        }

        if ($this->option('deep')) {
            $variant = $this->variantDuplicates();
            $this->newLine();
            $this->line(sprintf(
                'Theo BIẾN THỂ (đếm đúng như lúc đăng nhập): %d số bị coi là trùng.',
                count($variant),
            ));
            $this->line('  Con số này ≥ con số thô ở trên: `0901234567` và `+84901234567`');
            $this->line('  là hai giá trị khác nhau trong cột, nhưng đăng nhập coi chúng là một.');
        }

        // Luôn thoát 0: đây là khảo sát để QUYẾT ĐỊNH, không phải cổng chặn.
        // Thoát khác 0 sẽ khiến nó bị nhét vào CI rồi bị tắt.
        return self::SUCCESS;
    }

    /** @return list<array{phone: string, accounts: int}> */
    public function exactDuplicates(): array
    {
        return DB::table('customers')
            ->whereNotNull('password')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereNull('deleted_at')
            ->groupBy('phone')
            ->havingRaw('count(*) > 1')
            ->orderByRaw('count(*) desc')
            ->selectRaw('phone, count(*) as accounts')
            ->get()
            ->map(static fn ($r): array => ['phone' => (string) $r->phone, 'accounts' => (int) $r->accounts])
            ->all();
    }

    /**
     * Số bị coi là trùng khi tra THEO BIẾN THỂ — đúng cách `login()` tra.
     *
     * @return list<string>
     */
    public function variantDuplicates(): array
    {
        $rows = DB::table('customers')
            ->whereNotNull('password')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereNull('deleted_at')
            ->pluck('phone');

        $buckets = [];
        foreach ($rows as $phone) {
            // Khoá gom: dạng chỉ-chữ-số sau khi quy `+84`/`+81` về `0`, tức mẫu
            // số chung của các biến thể mà `login()` coi là một.
            $variants = CustomerLoginIdentifier::phoneVariants((string) $phone);
            $key = min(array_map(static fn (string $v): string => preg_replace('/\D+/', '', $v) ?? '', $variants) ?: ['']);
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }

        return array_keys(array_filter($buckets, static fn (int $n): bool => $n > 1));
    }
}
