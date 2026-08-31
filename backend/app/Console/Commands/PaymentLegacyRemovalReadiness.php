<?php

namespace App\Console\Commands;

use App\Services\Payment\Observation\LegacyRemovalReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:legacy-removal-readiness
    {--branch-limit= : Stop the Stripe connection probe after this many active branches}
    {--since-days= : Window for the gateway_option_id rollout metric (default 7)}
    {--json : Emit machine-readable JSON instead of a table}
    {--strict : Exit non-zero when a gate is MET while its code is still present}')]
#[Description('Plan-047 T7.6 — measure whether each legacy payment shim can be deleted yet (#1808)')]
final class PaymentLegacyRemovalReadiness extends Command
{
    public function handle(LegacyRemovalReadiness $readiness): int
    {
        $branchLimit = $this->option('branch-limit') !== null
            ? max(1, (int) $this->option('branch-limit'))
            : null;

        $sinceDays = $this->option('since-days') !== null
            ? max(1, (int) $this->option('since-days'))
            : LegacyRemovalReadiness::DEFAULT_ROLLOUT_WINDOW_DAYS;

        $report = $readiness->report($branchLimit, $sinceDays);
        $strict = (bool) $this->option('strict');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $strict && $report['actionable'] ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Plan-047 T7.6 — legacy payment shim removal readiness');
        $this->line('  Generated at: '.$report['generated_at']);
        $this->newLine();

        $rows = [];
        foreach ($report['gates'] as $gate) {
            $rows[] = [
                $gate['key'],
                $gate['condition_met'] ? 'MET' : 'not met',
                $gate['measurement'],
                $gate['code_present'] ? (string) count($gate['call_sites']) : 'gone',
                $this->verdict($gate),
            ];
        }

        $this->table(['Gate', 'Condition', 'Measurement', 'Call sites', 'Verdict'], $rows);

        foreach ($report['gates'] as $gate) {
            $this->newLine();
            $this->comment($gate['key']);
            $this->line('  condition: '.$gate['condition']);
            $this->line('  note:      '.$gate['note']);

            if ($gate['call_sites'] !== []) {
                $this->line('  call sites still to migrate:');
                foreach ($gate['call_sites'] as $site) {
                    $this->line('    - '.$site);
                }
            }

            // plan-055 T2.3 (#1838) — print the UNMET preconditions.
            //
            // They were computed and then rendered nowhere: the table shows only
            // `condition_met`, so the coverage number and the list of branches
            // that cannot take a payment were visible ONLY under `--json`. The
            // whole premise of this command is that it is where a human reads
            // the state before flipping a flag, and the one number that decides
            // "is it safe" was the one number they could not see.
            $unmet = array_values(array_filter(
                $gate['preconditions'] ?? [],
                static fn (array $p): bool => ($p['met'] ?? false) !== true,
            ));

            foreach ($unmet as $precondition) {
                $this->line('  precondition NOT met — '.$precondition['key'].':');
                $this->line('    '.$precondition['measurement']);

                foreach ($precondition['organizations_incomplete'] ?? [] as $org) {
                    $this->line(sprintf(
                        '    · %s — %d/%d branch sẵn sàng (%d có revision)',
                        $org['organization'],
                        $org['ready'],
                        $org['total'],
                        $org['covered'],
                    ));
                }

                $unresolvable = $precondition['payments_with_unresolvable_channel'] ?? [];
                if ($unresolvable !== []) {
                    $this->line(sprintf(
                        '    ⚠ %d branch có payment không xác định được channel (tổng %d payment) — gate có thể đang BỎ SÓT channel. CHỈ tính branch đã có revision; danh sách rỗng KHÔNG có nghĩa là sạch.',
                        count($unresolvable),
                        array_sum($unresolvable),
                    ));
                }

                foreach ($precondition['branches_without_effective_option'] ?? [] as $branch) {
                    $this->line(sprintf(
                        '    · branch %s (org %s) — %s',
                        $branch['branch_id'],
                        $branch['organization_id'],
                        $branch['reason'],
                    ));
                }
            }
        }

        $this->newLine();

        if ($report['actionable']) {
            $this->warn('At least one gate is MET while its code is still present — that legacy shim can be deleted now.');

            return $strict ? self::FAILURE : self::SUCCESS;
        }

        if ($report['work_remaining'] === []) {
            $this->info('KHÔNG CÒN VIỆC. Mọi cổng loại `work` đã xong hoặc đã xoá.');

            if ($report['scheduled_pending'] !== []) {
                $this->line('  Còn ĐỢI LỊCH (không phải việc): '.implode(', ', $report['scheduled_pending']));
                $this->line('  → plan-047 T7.6 đóng được; phần hẹn giờ theo dõi riêng ở #1895.');
            }

            return self::SUCCESS;
        }

        $this->info('Còn VIỆC ở: '.implode(', ', $report['work_remaining']).'.');

        if ($report['scheduled_pending'] !== []) {
            $this->line('  (Ngoài ra còn đợi lịch, KHÔNG tính là việc: '.implode(', ', $report['scheduled_pending']).')');
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $gate */
    private function verdict(array $gate): string
    {
        if (! $gate['code_present']) {
            return 'already removed';
        }

        return $gate['condition_met'] ? 'DELETE NOW' : 'blocked';
    }
}
