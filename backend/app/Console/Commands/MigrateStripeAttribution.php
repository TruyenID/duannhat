<?php

namespace App\Console\Commands;

use App\Services\Payment\Settlement\Exceptions\SettlementAttributionRefused;
use App\Services\Payment\Settlement\SettlementAttributionMigrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * #2893 — vỏ CLI cho {@see SettlementAttributionMigrator}.
 *
 * Luật nghiệp vụ nằm ở service; ở đây chỉ có tham số và cách in — cùng khuôn
 * `settlements:mark-foreign` (#2864), và cùng lý do: `architecture:domain-writers`
 * chỉ công nhận writer của aggregate `payment` ở tầng service.
 *
 * **Thứ tự bắt buộc: deploy mã TRƯỚC, chạy lệnh này SAU.** Ngược lại thì trong
 * cửa sổ giữa hai bước, webhook vẫn ghi vào hàng tổng hợp — dọn xong lại bẩn.
 *
 * Mặc định là DRY-RUN. Phải truyền `--apply` mới ghi.
 */
#[Signature('payments:migrate-stripe-attribution {--to= : Connection đích (mặc định: tự suy ra khi chỉ có một ứng viên)} {--apply : Ghi thật (mặc định chỉ báo cáo)}')]
#[Description('Chuyển quy thuộc settlement/provider-event/payout Stripe sang connection THẬT và ngưng dùng hàng tổng hợp (#2893)')]
final class MigrateStripeAttribution extends Command
{
    public function handle(SettlementAttributionMigrator $migrator): int
    {
        $apply = (bool) $this->option('apply');
        $to = $this->option('to') !== null && $this->option('to') !== ''
            ? (string) $this->option('to')
            : null;

        try {
            $result = $migrator->migrate($to, $apply);
        } catch (SettlementAttributionRefused $refused) {
            $this->error('TỪ CHỐI — '.$refused->getMessage());

            return self::FAILURE;
        }

        if ($result['source_present'] === false) {
            $this->info('Không có hàng connection tổng hợp nào trong DB này — không có gì để chuyển.');

            return self::SUCCESS;
        }

        $this->line(sprintf('  từ   %s  (ngưng dùng)', $result['retired_connection_id']));
        $this->line(sprintf('  tới  %s', (string) $result['target_connection_id']));
        $this->line(sprintf(
            '  merchant_account_id: %s%s',
            (string) $result['merchant_account']['before'],
            $result['merchant_account']['stamped']
                ? ' → '.(string) $result['platform_account_id'].($apply ? '' : ' (dry-run)')
                : ' (giữ nguyên)',
        ));

        $counts = $apply ? $result['moved'] : $result['planned'];

        $this->newLine();
        $this->table(
            ['bảng', 'trước', $apply ? 'đã chuyển' : 'sẽ chuyển', 'còn lại'],
            [
                ['payment_settlements', $result['before']['payment_settlements'], $counts['payment_settlements'], $result['after']['payment_settlements']],
                ['payment_provider_events', $result['before']['payment_provider_events'], $counts['payment_provider_events'], $result['after']['payment_provider_events']],
                ['gateway_payouts', $result['before']['gateway_payouts'], $counts['gateway_payouts'], $result['after']['gateway_payouts']],
            ],
        );

        if ($result['skipped_provider_events'] > 0) {
            $this->warn(sprintf(
                '%d provider event ở lại chỗ cũ: cùng provider_event_id đã tồn tại ở connection đích (UNIQUE connection+environment+event).',
                $result['skipped_provider_events'],
            ));
        }

        // `order_payments` cố ý đứng ngoài phạm vi — nhưng đứng ngoài thì phải
        // ĐO được, không phải tin là 0.
        $residual = $result['residual'];
        $this->line(sprintf(
            '  còn trỏ vào hàng ngưng dùng (KHÔNG thuộc phạm vi lệnh này): order_payments=%d · payment_attempts=%d · payment_refunds=%d',
            $residual['order_payments'],
            $residual['payment_attempts'],
            $residual['payment_refunds'],
        ));

        $this->newLine();
        $this->info(sprintf(
            '%s — %s',
            $apply ? 'ĐÃ GHI' : 'DRY-RUN',
            $result['retired'] ? 'hàng tổng hợp đã ngưng dùng (is_active=false)' : 'hàng tổng hợp CHƯA ngưng dùng',
        ));

        if (! $apply) {
            $this->comment('Chạy lại với --apply để ghi.');
        }

        return self::SUCCESS;
    }
}
