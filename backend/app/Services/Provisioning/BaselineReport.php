<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

/**
 * Kết quả một lượt reconcile baseline cho MỘT chủ thể (brand hoặc branch).
 *
 * Một bản ghi = một mục baseline. Bốn trạng thái, và sự khác nhau giữa chúng là
 * thứ quyết định người vận hành phải làm gì:
 *
 *   · `satisfied` — đã đúng, không đụng vào;
 *   · `missing`   — thiếu, và lượt này KHÔNG sửa (chế độ `plan`/`--dry-run`);
 *   · `applied`   — thiếu, và lượt này đã sửa;
 *   · `skipped`   — không kiểm/không sửa được vì thiếu tiền đề (ví dụ brand chưa
 *     có organization), tức **chưa biết** chứ không phải **đã đúng**.
 *
 * `skipped` cố ý KHÔNG gộp vào `satisfied`: gộp lại thì một brand chưa đồng bộ
 * org sẽ báo "sẵn sàng", đúng kiểu im lặng mà issue #2320 đang chữa.
 */
final class BaselineReport
{
    /** @var list<array{key: string, state: string, detail: string}> */
    private array $entries = [];

    public function __construct(public readonly string $subject) {}

    public function satisfied(string $key, string $detail = ''): void
    {
        $this->entries[] = ['key' => $key, 'state' => 'satisfied', 'detail' => $detail];
    }

    public function missing(string $key, string $detail): void
    {
        $this->entries[] = ['key' => $key, 'state' => 'missing', 'detail' => $detail];
    }

    public function applied(string $key, string $detail): void
    {
        $this->entries[] = ['key' => $key, 'state' => 'applied', 'detail' => $detail];
    }

    public function skipped(string $key, string $detail): void
    {
        $this->entries[] = ['key' => $key, 'state' => 'skipped', 'detail' => $detail];
    }

    /** @return list<array{key: string, state: string, detail: string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<array{key: string, state: string, detail: string}> */
    public function entriesInState(string $state): array
    {
        return array_values(array_filter($this->entries, fn (array $e): bool => $e['state'] === $state));
    }

    /** Lượt này có ghi gì xuống DB không. */
    public function changed(): bool
    {
        return $this->entriesInState('applied') !== [];
    }

    /**
     * Sẵn sàng vận hành: không mục nào thiếu, và không mục nào chưa kiểm được.
     */
    public function isReady(): bool
    {
        return $this->entriesInState('missing') === []
            && $this->entriesInState('skipped') === [];
    }

    public function summary(): string
    {
        $counts = [];
        foreach (['applied', 'missing', 'skipped', 'satisfied'] as $state) {
            $n = count($this->entriesInState($state));
            if ($n > 0) {
                $counts[] = "{$state}={$n}";
            }
        }

        return $this->subject.': '.($counts === [] ? 'không có mục nào' : implode(' ', $counts));
    }
}
