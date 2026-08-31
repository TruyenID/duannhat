<?php

namespace App\Console\Commands;

use App\Services\Payment\Observation\JsonKeyDebtThresholds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:json-key-debt-status
    {--since-days= : Cửa sổ định nghĩa "chi nhánh đang bán" (mặc định 30)}
    {--json : Xuất JSON thay vì bảng}
    {--strict : Thoát khác 0 khi ĐÃ tới ngưỡng}')]
#[Description('#2902 — đã tới lúc rút khoá tiền ra khỏi cột JSON không index chưa?')]
final class PaymentJsonKeyDebtStatus extends Command
{
    public function handle(JsonKeyDebtThresholds $thresholds): int
    {
        $sinceDays = $this->option('since-days') !== null
            ? max(1, (int) $this->option('since-days'))
            : null;

        $report = $thresholds->report($sinceDays);
        $strict = (bool) $this->option('strict');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $strict && $report['actionable'] ? self::FAILURE : self::SUCCESS;
        }

        $this->info('#2902 — khoá tiền trong cột JSON không index');
        $this->line('  Đo lúc: '.$report['generated_at']);
        $this->newLine();

        $this->table(
            ['ngưỡng', 'trạng thái', 'phép đo'],
            array_map(static fn (array $g): array => [
                $g['key'],
                $g['condition_met'] ? 'ĐÃ TỚI' : 'chưa',
                $g['measurement'],
            ], $report['gates']),
        );

        if (! $report['actionable']) {
            $this->newLine();
            $this->info('Chưa ngưỡng nào tới — #2902 nói KHÔNG sửa, và đó vẫn là câu trả lời đúng.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('ĐÃ tới ít nhất một ngưỡng. Việc phải làm nằm ở #2902:');
        foreach ($report['gates'] as $gate) {
            if ($gate['condition_met']) {
                $this->line(sprintf('  - %s: %s', $gate['key'], $gate['why']));
            }
        }
        $this->newLine();
        $this->line('  1. `stripe_refund_id` thành CỘT THẬT + UNIQUE — hiện chỉ được bảo đảm');
        $this->line('     duy nhất bằng kỷ luật của code (lockForUpdate + kiểm lại).');
        $this->line('  2. Index cho các JSON-path còn lại (generated column + index).');
        $this->line('  Đi qua schema YAML + `npm run omnify:gen`. `omnify:reset` CẤM (#2872).');

        return $strict ? self::FAILURE : self::SUCCESS;
    }
}
