<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 0 (#1897) — bảng dispatch, đối ứng của `printKindPlans`
 * + `registerPrintKind` bên Go.
 *
 * Bên Go bảng là biến package nạp qua `init()`. Ở PHP nó là một service có
 * trạng thái, đăng ký trong container — vì `init()` ngầm không có đối ứng an
 * toàn ở PHP: một static nạp theo thứ tự require sẽ khác nhau giữa web request
 * và một lệnh artisan, và cách hỏng là "kind biến mất" chứ không phải lỗi.
 *
 * **Slice 0 KHÔNG đăng ký kind nào.** Đó là chủ đích: emitter là slice 1-3.
 * Cái slice này giao là CỖ MÁY, cộng với một cổng chứng minh rằng khi các kind
 * được đăng ký, tập của chúng khớp Go từng id (`PrintContractParityTest`).
 *
 * Một registry rỗng phải hỏng ỒN ÀO, không im lặng — xem
 * {@see PrintRenderer::render()}.
 */
final class PrintKindRegistry
{
    /** @var array<string, PrintKindPlan> */
    private array $plans = [];

    public function register(string $kind, PrintKindPlan $plan): void
    {
        $this->plans[$kind] = $plan;
    }

    public function planFor(string $kind): ?PrintKindPlan
    {
        return $this->plans[$kind] ?? null;
    }

    public function has(string $kind): bool
    {
        return isset($this->plans[$kind]);
    }

    /** @return list<string> tên kind đã đăng ký, đã sắp xếp */
    public function kinds(): array
    {
        $kinds = array_keys($this->plans);
        sort($kinds);

        return $kinds;
    }

    /**
     * Hình dạng hợp đồng của toàn bảng — cùng khuôn với khoá `kinds` trong
     * `print_contract_golden.json`, để cổng parity so trực tiếp.
     *
     * @return array<string, array{default_width: int, japanese_doc: bool, blocks: list<string>}>
     */
    public function toContractShape(): array
    {
        $out = [];

        foreach ($this->plans as $kind => $plan) {
            $out[$kind] = [
                'default_width' => $plan->defaultWidth,
                'japanese_doc' => $plan->japaneseDoc,
                'blocks' => $plan->blockIds(),
            ];
        }

        ksort($out);

        return $out;
    }
}
