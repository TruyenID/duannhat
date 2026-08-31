<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * #1597 — liệt kê class ĐỦ ĐIỀU KIỆN công bố mà chưa được khai.
 *
 * Vì sao cần một lệnh chứ không phải một lần tìm bằng tay: mẫu hình "tiêu thụ
 * KẾT QUẢ" (#1609) chỉ lộ ra khi đối chiếu HAI thứ mà không báo cáo nào ghép
 * sẵn — Deptrac biết class nào bị đọc xuyên module, còn nguồn biết class nào là
 * value object thuần. Ghép tay thì mỗi lần phải nhớ lại là có mẫu đó.
 *
 * Điều kiện, giống hệt luật của layer `PublishedContracts`:
 *
 *   1. có cạnh xuyên module trỏ vào nó (tức đang bị tính là nợ);
 *   2. KHÔNG import gì ngoài `App\Services\DomainMutation` và `App\Omnify\Enums`
 *      (hai thứ đã là `shared`) — nói cách khác, không rò model của chủ sở hữu;
 *   3. chưa nằm trong `published_contracts` / `published_contract_namespaces`.
 *
 * Lệnh này KHÔNG tự khai. Công bố là một hành động có người chịu trách nhiệm
 * (xem `docs/explanation/published-api-boundary.md`) — nó chỉ nói "chỗ này đủ
 * điều kiện", còn có nên công bố hay không thì người đọc quyết.
 *
 * Chạy trên `dev` ngày 2026-08-02: **0 ứng viên**. #1609 đã lấy hết — mọi mục
 * tiêu xuyên module còn lại đều là model Eloquent hoặc service có import model.
 * Đó là một cột mốc của #962, không phải một lệnh chạy hỏng.
 */
final class PublishableContractCandidates extends Command
{
    protected $signature = 'architecture:publishable-candidates {--json : Xuất JSON}';

    protected $description = 'Liệt kê value object bị đọc xuyên module mà chưa công bố (#962)';

    /** Namespace đã là `shared`, nên import chúng KHÔNG làm mất tư cách công bố. */
    private const NEUTRAL_PREFIXES = [
        'App\\Services\\DomainMutation',
        'App\\Omnify\\Enums',
    ];

    public function handle(): int
    {
        $out = tempnam(sys_get_temp_dir(), 'deptrac-cand-').'.json';

        // `--report-skipped` BẮT BUỘC: thiếu nó thì baseline che hết vi phạm,
        // JSON ra rỗng, và lệnh này báo "0 ứng viên" — xanh, và sai.
        $process = new Process(
            ['vendor/bin/deptrac', 'analyse', '--no-progress', '--report-skipped', '--formatter=json', '--output='.$out],
            base_path(),
            null,
            null,
            300.0,
        );
        $process->run();

        if (! is_file($out)) {
            $this->error('deptrac không sinh được báo cáo: '.$process->getErrorOutput());

            return self::FAILURE;
        }

        /** @var array{files?: array<string, array{messages: list<array{message: string}>}>} $report */
        $report = json_decode((string) file_get_contents($out), true) ?: [];
        @unlink($out);

        $inbound = [];
        foreach ($report['files'] ?? [] as $messages) {
            foreach ($messages['messages'] ?? [] as $message) {
                if (preg_match('/^(.+?) (?:must|should) not depend on (.+?) \(.+? on .+?\)$/', (string) $message['message'], $m) !== 1) {
                    continue;
                }
                $inbound[$m[2]] = ($inbound[$m[2]] ?? 0) + 1;
            }
        }
        arsort($inbound);

        $manifest = require base_path('config/modules.php');
        $declared = $manifest['published_contracts'] ?? [];
        $declaredNs = $manifest['published_contract_namespaces'] ?? [];

        $candidates = [];
        foreach ($inbound as $fqn => $count) {
            if (! str_starts_with($fqn, 'App\\') || str_contains($fqn, '\\Models\\')) {
                continue;
            }
            if (in_array($fqn, $declared, true)) {
                continue;
            }
            foreach ($declaredNs as $ns) {
                if (str_starts_with($fqn, $ns.'\\')) {
                    continue 2;
                }
            }

            $path = app_path(str_replace('\\', '/', substr($fqn, strlen('App\\'))).'.php');
            if (! is_file($path)) {
                continue;
            }

            preg_match_all('/^use ([^;]+);/m', (string) file_get_contents($path), $uses);
            $foreign = array_values(array_filter(
                $uses[1] ?? [],
                static function (string $import): bool {
                    foreach (self::NEUTRAL_PREFIXES as $neutral) {
                        if (str_starts_with($import, $neutral)) {
                            return false;
                        }
                    }

                    return true;
                },
            ));

            if ($foreign !== []) {
                continue;
            }

            $candidates[] = ['class' => $fqn, 'inbound_edges' => $count];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['candidates' => $candidates], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($candidates === []) {
            $this->info('0 ứng viên — mọi mục tiêu xuyên module còn lại đều là model hoặc có import model.');
            $this->line('Nghĩa là không còn cạnh nào trả được bằng KHAI BÁO; phần còn lại cần cổng hoặc chuyển hành vi.');

            return self::SUCCESS;
        }

        $this->table(
            ['class', 'cạnh vào'],
            array_map(static fn (array $c): array => [$c['class'], $c['inbound_edges']], $candidates),
        );
        $this->line('Khai vào `published_contracts` nếu ĐÚNG là kiểu kết quả của module sở hữu.');

        return self::SUCCESS;
    }
}
