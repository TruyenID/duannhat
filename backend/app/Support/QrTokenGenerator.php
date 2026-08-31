<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Random\RandomException;
use RuntimeException;

/**
 * Đúc token base62 32 ký tự, an toàn cho URL, dùng cho QR (bàn và đơn hàng).
 *
 * Frontend tự render ảnh QR từ token này. Token thu hồi được: đúc lại là phát giá
 * trị mới, và bản cũ ngừng phân giải ngay khi dòng dữ liệu được cập nhật.
 *
 * Bàn và đơn hàng **dùng chung** bộ đúc này (nên chung một không gian tên base62
 * 32 ký tự) để endpoint resolve của kiosk định tuyến được một lần quét về đúng loại
 * mà không cần biết trước token thuộc kiểu nào.
 *
 * ## #962 — vì sao class này nằm ở `App\Support`, không phải `App\Services\Shop`
 *
 * Bên trong nó không có một mẩu nghiệp vụ Shop nào: sinh chuỗi ngẫu nhiên, kiểm
 * trùng, thử lại. Nhưng khi nó còn ở `App\Services\Shop` thì `App\Models\CustomerOrder`
 * (Ordering) phải import nó để đúc token lúc tạo đơn — tức **model của module này
 * phụ thuộc service của module kia**, cạnh mà deptrac bắt đúng.
 *
 * Chuyển sang SharedKernel là cách trung thực: đây vốn là hạ tầng dùng chung, không
 * phải dịch vụ của Shop. Cạnh biến mất mà không cần bịa ra một hợp đồng.
 *
 * ## `generateForTable()` đã bị bỏ khỏi đây — có chủ ý
 *
 * Phương thức đó nhận `App\Models\Table` và tự `update()`. Giữ lại thì SharedKernel
 * phải import model của Organization, tức đổi một cạnh xấu lấy một cạnh xấu hơn
 * (kernel không được biết model của module nào cả). Phần ghi đã chuyển về
 * `TableService`, nơi vốn đã cầm `Table`.
 */
class QrTokenGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const TOKEN_LENGTH = 32;

    private const MAX_ATTEMPTS = 5;

    /**
     * Đúc token base62 32 ký tự, bảo đảm không trùng trong bảng của model truyền vào.
     * Trùng thì thử lại tối đa MAX_ATTEMPTS lần.
     *
     * @param  class-string<Model>  $modelClass  bảng dùng để kiểm trùng
     * @param  string  $column  cột chứa token trên bảng đó
     *
     * @throws RuntimeException nếu MAX_ATTEMPTS lần vẫn không ra token duy nhất
     */
    public function generate(string $modelClass, string $column = 'qr_token'): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $token = $this->randomString();

            if (! $modelClass::where($column, $token)->exists()) {
                return $token;
            }
        }

        throw new RuntimeException(
            'Unable to mint a unique QR token after '.self::MAX_ATTEMPTS.' attempts.'
        );
    }

    /**
     * @throws RandomException
     */
    private function randomString(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $token = '';

        for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
            $token .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $token;
    }
}
