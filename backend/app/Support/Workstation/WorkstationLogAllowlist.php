<?php

declare(strict_types=1);

namespace App\Support\Workstation;

/**
 * #2901 — lớp lọc THỨ HAI của Cloud cho log máy trạm.
 *
 * Máy trạm đã lọc theo cùng bảng này TRƯỚC khi gửi. Lớp ở đây tồn tại vì hợp
 * đồng nói thẳng: **Cloud không tin máy trạm đã lọc đúng**. Một máy trạm bản
 * cũ, một bản build lỗi, hay một thiết bị bị chiếm quyền đều gửi được thứ mà
 * bộ lọc ở nguồn lẽ ra đã chặn — và PII đi qua ranh giới hệ thống thì không
 * thu hồi được (#2220: 11 cặp token còn sống + 287 `qr_token` vào git, revert
 * KHÔNG lấy lại được).
 *
 * ## Hai mức từ chối, cố ý khác nhau
 *
 * - `message` không khai ⇒ **bỏ cả dòng**, đếm vào `rejected`. Đó chỉ là một
 *   dòng chưa ai khai, nên nó không được làm rơi những dòng đã khai đi cùng
 *   lô — hợp đồng ghi rõ lô vẫn 202.
 * - attr không khai ⇒ **bỏ attr, giữ dòng**. Một dòng thiếu một trường vẫn trả
 *   lời được câu hỏi vận hành; vứt nó đi thì không.
 *
 * `level: "debug"` KHÔNG đi qua đây — nó là 422 ở tầng `validate()`. Lý do
 * khác hẳn: một dòng debug tới nơi nghĩa là bộ lọc ở NGUỒN đã hỏng, nên cả lô
 * đáng ngờ chứ không phải riêng dòng đó.
 *
 * ## Nguồn dữ liệu
 *
 * `config/workstation_log_allowlist.php`, được
 * `tests/Feature/Architecture/WorkstationLogAllowlistMatchesDocTest.php` ghim
 * bằng nhau với bản khai `docs/reference/workstation-log-allowlist.md` — bảng
 * mà máy trạm cũng đọc. Vì sao không đọc thẳng markdown lúc chạy: `docs/` ở
 * gốc repo không được deploy (rsync chỉ lấy `backend/`), và một allowlist rỗng
 * trên production sẽ từ chối mọi dòng trong im lặng.
 */
final class WorkstationLogAllowlist
{
    /**
     * Bảng đã nạp, giữ trong bộ nhớ cho suốt vòng đời request.
     *
     * Một lô tối đa 500 dòng đều gọi vào đây, nên đọc `config()` từng dòng là
     * 500 lượt tra container cho một mảng không đổi.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $messages = null;

    /**
     * Lọc MỘT dòng.
     *
     * @param  array<string, mixed>  $attrs  attr NGUYÊN VĂN thiết bị gửi
     * @return array{0: bool, 1: array<string, mixed>|null} [dòng có được giữ không, attr đã lọc]
     */
    public function filter(string $message, array $attrs): array
    {
        $allowed = $this->messages()[$message] ?? null;

        if ($allowed === null) {
            return [false, null];
        }

        $kept = [];

        foreach ($allowed as $key) {
            // `array_key_exists` chứ không `isset`: một attr gửi lên với giá
            // trị `null` vẫn là một sự thật ("trường này rỗng ở lượt đó"), và
            // `isset` sẽ âm thầm nuốt nó.
            if (array_key_exists($key, $attrs)) {
                $kept[$key] = $attrs[$key];
            }
        }

        // `null` chứ không `[]` khi không giữ được attr nào: cột `attrs` là
        // nullable và một object JSON rỗng đọc lên trông như "đã có dữ liệu
        // nhưng bị xoá sạch", trong khi sự thật là message ấy không khai attr
        // nào hoặc thiết bị không gửi cái nào khớp.
        return [true, $kept === [] ? null : $kept];
    }

    /**
     * Message này có được khai không.
     */
    public function allowsMessage(string $message): bool
    {
        return array_key_exists($message, $this->messages());
    }

    /**
     * Số message đang khai — dùng cho rào và cho lệnh chẩn đoán.
     *
     * Không phải trang trí: **0 là một khẳng định, không phải mặc định**. Nếu
     * con số này bằng 0 trên production thì allowlist đã không nạp được, và
     * mọi dòng bị từ chối trong im lặng — đúng chế độ hỏng khó thấy nhất của
     * một cơ chế fail-closed.
     */
    public function size(): int
    {
        return count($this->messages());
    }

    /**
     * @return array<string, list<string>>
     */
    private function messages(): array
    {
        if ($this->messages === null) {
            /** @var array<string, list<string>> $messages */
            $messages = config('workstation_log_allowlist.messages', []);
            $this->messages = $messages;
        }

        return $this->messages;
    }
}
