<?php

namespace App\Console\Commands;

use App\Services\Payment\Settlement\StripeWebhookSubscriptionAuditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Plan-050 T2.4 (#1978) — report the difference between the webhook endpoints
 * registered at Stripe and the events the settlement layer needs.
 *
 * Deliberately SEPARATE from `settlements:reconcile`: that sweep is pure DB
 * and must keep running when the gateway is unreachable, whereas this one
 * talks to the Stripe API on every invocation. Folding a live API call into
 * the daily reconciliation would make a gateway outage look like a broken
 * reconciliation.
 *
 * Read-only — it never creates or edits a webhook endpoint. Registering the
 * missing events stays a human act in the Stripe dashboard, because an agent
 * silently subscribing an endpoint to money events is not a thing an audit
 * should be able to do.
 */
#[Signature('settlements:audit-webhooks {--connection= : Limit to one payment gateway connection id} {--json : Emit machine-readable JSON instead of tables} {--strict : Exit non-zero when any connection has a gap or an error}')]
#[Description('Audit registered Stripe webhook endpoints against the events settlement needs (plan-050 T2.4)')]
final class AuditStripeWebhookSubscriptions extends Command
{
    public function handle(StripeWebhookSubscriptionAuditService $audit): int
    {
        $connectionId = $this->option('connection') !== null && $this->option('connection') !== ''
            ? (string) $this->option('connection')
            : null;

        $report = $audit->audit($connectionId);

        $gaps = array_values(array_filter($report, static fn (array $row): bool => $row['status'] === 'gap'));
        $errors = array_values(array_filter($report, static fn (array $row): bool => $row['status'] === 'error'));
        // `partial` = mọi yêu cầu đều khớp, nhưng một yêu cầu dạng HỌ chỉ khớp
        // bởi vài thành viên. Không phải `gap` (không thiếu cái nào đã nêu tên),
        // cũng KHÔNG được gọi là `ok` — người vận hành phải tự xét họ đó đủ chưa.
        $partials = array_values(array_filter($report, static fn (array $row): bool => $row['status'] === 'partial'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'connections' => $report,
                'gap_count' => count($gaps),
                'partial_count' => count($partials),
                'error_count' => count($errors),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($gaps, $partials, $errors);
        }

        if ($report === []) {
            $this->warn('No active Stripe connection in scope — nothing to audit.');

            return self::SUCCESS;
        }

        foreach ($report as $row) {
            $this->newLine();
            $this->info(sprintf(
                'Connection %s  (%s, account scope %s)',
                $row['connection_id'],
                $row['environment'],
                $row['account_scope'],
            ));

            if ($row['status'] === 'error') {
                $this->error('  Could not list webhook endpoints: '.$row['error']);
                $this->line('  Coverage UNKNOWN for this connection — not reported as missing.');

                continue;
            }

            if ($row['endpoints'] === []) {
                $this->error('  No webhook endpoint registered at all.');
            } else {
                $this->table(
                    ['Endpoint', 'URL', 'Status', 'Subscribed events'],
                    array_map(static fn (array $endpoint): array => [
                        $endpoint['id'],
                        $endpoint['url'],
                        $endpoint['status'],
                        $endpoint['enabled_events'] === []
                            ? '(none)'
                            : implode(', ', $endpoint['enabled_events']),
                    ], $row['endpoints']),
                );
            }

            if ($row['disabled_endpoint_ids'] !== []) {
                $this->warn('  Disabled endpoints delivering nothing: '.implode(', ', $row['disabled_endpoint_ids']));
            }

            $partialFamilies = $row['partial_families'] ?? [];

            if ($row['missing_events'] === [] && $partialFamilies === []) {
                $this->line('  <info>All required events are subscribed.</info>');

                continue;
            }

            if ($row['missing_events'] !== []) {
                $this->error('  Missing required events: '.implode(', ', $row['missing_events']));
            }

            foreach ($partialFamilies as $family => $members) {
                // Nói ĐÃ THẤY GÌ, không phán họ đã đủ chưa: danh sách event của
                // Stripe đổi theo thời gian, nên khẳng định "đủ" ở đây sẽ là đoán.
                $this->warn(sprintf(
                    '  Family %s matched only by: %s — check whether that is the whole family you need.',
                    $family,
                    implode(', ', $members),
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Audited %d Stripe connection(s): %d with missing events, %d partially covered, %d unreachable.',
            count($report),
            count($gaps),
            count($partials),
            count($errors),
        ));

        return $this->exitCode($gaps, $partials, $errors);
    }

    /**
     * `--strict` đỏ cả với `partial`, không chỉ `gap`/`error`.
     *
     * `--strict` tồn tại để một cron/CI CHỨNG MINH đăng ký webhook là đủ. Một họ
     * khớp một phần thì chưa chứng minh được điều đó — nó chỉ nói "không thiếu
     * cái nào tôi biết tên". Cho nó đi qua nghĩa là để `--strict` xanh trên một
     * trạng thái chưa ai xét, và đó đúng là loại xanh vô nghĩa mà công cụ này
     * sinh ra để chống.
     *
     * Không strict thì `partial` chỉ hiện ra để người đọc xét, không làm đỏ.
     *
     * @param  list<array<string, mixed>>  $gaps
     * @param  list<array<string, mixed>>  $partials
     * @param  list<array<string, mixed>>  $errors
     */
    private function exitCode(array $gaps, array $partials, array $errors): int
    {
        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return $gaps === [] && $partials === [] && $errors === []
            ? self::SUCCESS
            : self::FAILURE;
    }
}
