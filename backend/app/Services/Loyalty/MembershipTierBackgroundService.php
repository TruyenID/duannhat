<?php

namespace App\Services\Loyalty;

use App\Models\Brand;
use App\Services\FileUploadService;
use App\Services\Loyalty\Contracts\MembershipTierBackgrounds;

/**
 * #1772 — ảnh nền thẻ thành viên, mỗi hạng một hình.
 *
 * Brand lưu MỘT map `{tier_key: file_id}` ở `brands.customer_tier_card_backgrounds`.
 * Lưu **id của File** chứ không lưu URL tuyệt đối, và đây là điểm khác có chủ ý
 * so với `customer_header_logo_url` bên cạnh: URL tuyệt đối ghim host của lúc
 * upload, nên khi staging xoay tunnel (hoặc prod đổi CDN) thì mọi ảnh đã lưu
 * gãy cùng lúc. `File::getUrl()` dựng lại đường dẫn từ cấu hình disk **lúc
 * đọc**, nên cùng một hàng DB phục vụ được cả staging lẫn prod.
 *
 * Khoá của map phải nằm trong thang hạng ở `config/loyalty.php`. Thang hạng là
 * chính sách của người vận hành và có thể đổi; khoá lạ bị lọc ở cả đường ghi
 * (validator HQ) lẫn đường đọc, nên một hạng bị gỡ khỏi config không để lại
 * ảnh mồ côi hiển thị cho khách.
 *
 * Hạng thiếu khoá, hoặc khoá trỏ tới File đã bị xoá, đều trả `null` — không
 * phải một URL mặc định. Customer-web phân biệt được "chưa cấu hình" với "đã
 * cấu hình" để rơi về gradient vàng sẵn có.
 */
class MembershipTierBackgroundService implements MembershipTierBackgrounds
{
    public function __construct(private readonly FileUploadService $files) {}

    /**
     * Các khoá hạng hợp lệ, theo đúng thứ tự khai báo ở config.
     *
     * @return list<string>
     */
    public function tierKeys(): array
    {
        return collect(config('loyalty.tiers', []))
            ->pluck('key')
            ->filter(fn ($key) => is_string($key) && $key !== '')
            ->values()
            ->all();
    }

    /**
     * Chuẩn hoá map trước khi ghi: bỏ khoá không thuộc thang hạng, bỏ giá trị
     * rỗng (đó là thao tác "xoá ảnh của hạng này"). Map rỗng ⇒ null để cột
     * quay về đúng trạng thái "chưa cấu hình" thay vì giữ lại `{}`.
     *
     * @return array<string, string>|null
     */
    public function sanitize(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $allowed = $this->tierKeys();
        $clean = [];

        foreach ($input as $key => $fileId) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                continue;
            }

            if (! is_string($fileId) || $fileId === '') {
                continue;
            }

            $clean[$key] = $fileId;
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Giữ lại các File được map trỏ tới.
     *
     * `/api/v1/files/upload` trả về file TẠM, hết hạn sau 12h và bị job dọn
     * xoá. Admin-web có gọi `make-permanent` nhưng đó là best-effort ở phía
     * client (chính nó ghi `console.error` rồi đi tiếp) — nếu chỉ dựa vào đó
     * thì ảnh nền biến mất sau một đêm, và triệu chứng rơi vào khách chứ không
     * rơi vào người vừa cấu hình. Chốt lại ở đường ghi của server.
     *
     * @param  array<string, string>|null  $map
     */
    public function retain(?array $map): void
    {
        if (! $map) {
            return;
        }

        // Qua service, KHÔNG tự `File::query()`: `File` là model của module
        // Platform, và Loyalty đọc thẳng nó là cạnh mà deptrac bắt được.
        $this->files->makePermanentByIds(array_values($map));
    }

    /**
     * URL ảnh nền theo hạng, giải ra lúc ĐỌC.
     *
     * @return array<string, string|null>
     */
    public function urls(?Brand $brand): array
    {
        $map = $this->sanitize($brand?->customer_tier_card_backgrounds);

        if (! $map) {
            return [];
        }

        // Qua service, KHÔNG tự `File::query()` — xem `retain()`.
        $urlById = $this->files->urlsByIds(array_values($map));

        $urls = [];

        foreach ($map as $tierKey => $fileId) {
            $urls[$tierKey] = $urlById[$fileId] ?? null;
        }

        return $urls;
    }

    /**
     * Gắn `background_image_url` vào một hạng trong payload membership.
     *
     * Null-safe vì `next_tier` là null với khách đã ở hạng cao nhất.
     *
     * @param  array<string, mixed>|null  $tier
     * @param  array<string, string|null>  $urls
     * @return array<string, mixed>|null
     */
    public function decorate(?array $tier, array $urls): ?array
    {
        if ($tier === null) {
            return null;
        }

        $key = $tier['key'] ?? null;
        $tier['background_image_url'] = is_string($key) ? ($urls[$key] ?? null) : null;

        return $tier;
    }
}
