<?php

declare(strict_types=1);

namespace App\Architecture;

use Symfony\Component\Yaml\Yaml;

/**
 * Phát hiện chu trình giữa các layer của module (#1560, epic #962).
 *
 * ADR 0001 § 2 nói đồ thị phụ thuộc PHẢI phi chu trình. Cho tới nay đó là một
 * câu trong tài liệu: Deptrac không phát hiện chu trình (xem `deptrac analyse
 * --help` — không có formatter nào cho việc này), và bộ đo SCC tự viết đã bị bỏ
 * cùng bánh cóc cũ ở #1532. Hệ quả: tài liệu phải ghi *"kết luận cả chín module
 * nằm trong MỘT thành phần liên thông mạnh là số của dụng cụ cũ, chưa chạy lại"*.
 *
 * **KHÔNG dựng bộ đo thứ hai.** Lớp này đọc output của chính Deptrac. Hai bộ đo
 * cho cùng một thứ là đúng cách bản đồ phụ thuộc cũ trôi mất, và epic này đã trả
 * giá ba lần cho lỗi đo (#1534, #1537, #1539) — mỗi lần chỉ lộ ra vì có MỘT phép
 * đo đủ tin để mâu thuẫn.
 *
 * ## Đồ thị được dựng từ HAI nguồn, và thiếu nguồn nào cũng ra kết luận sai
 *
 * 1. **Cạnh vi phạm** — `deptrac analyse --report-skipped --formatter=json`.
 *    Bắt buộc có `--report-skipped`: baseline che 805 vi phạm, thiếu cờ này thì
 *    JSON rỗng và đồ thị trông sạch một cách giả tạo. (Vi phạm bị che dùng chữ
 *    *"should not depend"* thay vì *"must not depend"*, nên khớp cả hai.)
 *
 * 2. **Cạnh ĐƯỢC PHÉP** — đọc từ `ruleset` trong `deptrac.yaml`. Bỏ nguồn này là
 *    bỏ sót đúng loại chu trình nguy hiểm nhất: `SharedKernel → Inventory` là vi
 *    phạm, còn `Inventory → SharedKernel` là được phép — hai cạnh khép thành chu
 *    trình mà nhìn riêng danh sách vi phạm sẽ không thấy.
 *
 * Chu trình module↔module thì nằm trọn trong tập vi phạm (ruleset chỉ cho phép
 * module → hai kernel, không cho module → module), nhưng chu trình có kernel thì
 * cần cả hai. Đó là lý do lớp này không đi tắt.
 */
final class LayerCycleDetector
{
    /** @var array<string, list<string>> */
    private array $graph = [];

    /** @var array<string, list<array{from: string, to: string, kind: string}>> */
    private array $edgeKind = [];

    /**
     * @param  string  $deptracJson  nội dung JSON của `deptrac analyse --report-skipped --formatter=json`
     * @param  string  $deptracYamlPath  đường dẫn `deptrac.yaml` để đọc ruleset
     */
    public function __construct(string $deptracJson, string $deptracYamlPath)
    {
        $edges = [];

        foreach ($this->violationEdges($deptracJson) as [$from, $to]) {
            $edges[$from][$to] = 'vi phạm';
        }

        foreach ($this->rulesetEdges($deptracYamlPath) as [$from, $to]) {
            // Cạnh vi phạm mang nhiều thông tin hơn — đừng để cạnh "được phép"
            // ghi đè nhãn của nó.
            $edges[$from][$to] ??= 'được phép';
        }

        foreach ($edges as $from => $tos) {
            $this->graph[$from] = array_keys($tos);
            foreach ($tos as $to => $kind) {
                $this->edgeKind[$from][] = ['from' => $from, 'to' => $to, 'kind' => $kind];
            }
        }
    }

    /**
     * Thành phần liên thông mạnh có nhiều hơn một node, lớn nhất trước.
     *
     * @return list<list<string>>
     */
    public function cycles(): array
    {
        $index = [];
        $low = [];
        $stack = [];
        $onStack = [];
        $components = [];
        $counter = 0;

        $nodes = array_unique(array_merge(
            array_keys($this->graph),
            array_merge(...array_values($this->graph) ?: [[]]),
        ));
        sort($nodes);

        // Tarjan, viết vòng lặp thay vì đệ quy: đồ thị này nhỏ, nhưng một stack
        // overflow trong CI là loại lỗi mất nửa ngày mới đọc ra.
        foreach ($nodes as $root) {
            if (isset($index[$root])) {
                continue;
            }

            $work = [[$root, 0]];

            while ($work !== []) {
                [$v, $pi] = array_pop($work);

                if ($pi === 0) {
                    $index[$v] = $low[$v] = $counter++;
                    $stack[] = $v;
                    $onStack[$v] = true;
                }

                $recursed = false;
                $successors = $this->graph[$v] ?? [];
                sort($successors);

                for ($i = $pi; $i < count($successors); $i++) {
                    $w = $successors[$i];
                    if (! isset($index[$w])) {
                        $work[] = [$v, $i + 1];
                        $work[] = [$w, 0];
                        $recursed = true;
                        break;
                    }
                    if ($onStack[$w] ?? false) {
                        $low[$v] = min($low[$v], $index[$w]);
                    }
                }

                if ($recursed) {
                    continue;
                }

                if ($low[$v] === $index[$v]) {
                    $component = [];
                    do {
                        $w = array_pop($stack);
                        unset($onStack[$w]);
                        $component[] = $w;
                    } while ($w !== $v);
                    sort($component);
                    $components[] = $component;
                }

                if ($work !== []) {
                    [$parent] = $work[count($work) - 1];
                    $low[$parent] = min($low[$parent] ?? PHP_INT_MAX, $low[$v]);
                }
            }
        }

        $cycles = array_values(array_filter($components, static fn (array $c): bool => count($c) > 1));
        usort($cycles, static fn (array $a, array $b): int => count($b) <=> count($a));

        return $cycles;
    }

    /**
     * Cạnh nằm TRONG một thành phần — tức là các cạnh khép chu trình.
     *
     * Báo cáo chỉ nói "có chu trình" thì không hành động được; phải nói cắt cạnh
     * nào thì phá được nó.
     *
     * @param  list<string>  $component
     * @return list<array{from: string, to: string, kind: string}>
     */
    public function edgesWithin(array $component): array
    {
        $inside = array_flip($component);
        $out = [];

        foreach ($component as $node) {
            foreach ($this->edgeKind[$node] ?? [] as $edge) {
                if (isset($inside[$edge['to']])) {
                    $out[] = $edge;
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        return $out;
    }

    /** @return list<array{0: string, 1: string}> */
    private function violationEdges(string $json): array
    {
        /** @var array{files?: array<string, array{messages?: list<array{message?: string}>}>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $seen = [];
        foreach ($data['files'] ?? [] as $file) {
            foreach ($file['messages'] ?? [] as $message) {
                $text = trim((string) ($message['message'] ?? ''));
                // `--report-skipped` phát "should not depend" (warning) cho vi phạm
                // đã nằm trong baseline; vi phạm mới thì "must not depend" (error).
                if (preg_match('/\((\w+) on (\w+)\)$/', $text, $m) !== 1) {
                    continue;
                }
                if ($m[1] === $m[2]) {
                    continue;
                }
                $seen[$m[1].'>'.$m[2]] = [$m[1], $m[2]];
            }
        }

        return array_values($seen);
    }

    /** @return list<array{0: string, 1: string}> */
    private function rulesetEdges(string $yamlPath): array
    {
        /** @var array{deptrac?: array{ruleset?: array<string, list<string>|null>}} $config */
        $config = Yaml::parseFile($yamlPath);

        $out = [];
        foreach ($config['deptrac']['ruleset'] ?? [] as $from => $tos) {
            foreach ($tos ?? [] as $to) {
                if ($from !== $to) {
                    $out[] = [(string) $from, (string) $to];
                }
            }
        }

        return $out;
    }
}
