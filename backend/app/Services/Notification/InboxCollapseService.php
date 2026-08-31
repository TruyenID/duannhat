<?php

namespace App\Services\Notification;

use App\Contracts\Notifiable;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Plan-023 M5 T5.1 — fold a Notifiable's inbox into one entry per
 * `aggregation_key`. Rows with a NULL key never collapse; they pass
 * through as singleton buckets.
 *
 * v1 fetches all matching rows in the requested page window into PHP
 * and groups in-memory. Acceptable up to ~500 row mailboxes (typical
 * unread inbox under 200). Larger orgs will want a SQL-side window
 * function — open as a follow-up plan when a real customer crosses
 * the threshold.
 *
 * Brand boundary preserved: `NotificationRecipient::forRecipient` is
 * pivot-keyed on (recipient_type, recipient_id), so no cross-tenant
 * leakage is possible. The arch test in T5.10 enforces that this
 * service never breaks the boundary.
 */
final class InboxCollapseService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Return a paginator of `CollapsedInboxEntry` arrays. Each entry is
     * either:
     *   - collapsed: { id: "agg:<key>", is_collapsed: true, count, latest, sample[≤3] }
     *   - singleton: { id: <recipient_id>, is_collapsed: false, notification: ... }
     *
     * @param  array<string, mixed>  $filters
     */
    public function collapseFor(Notifiable $recipient, array $filters = []): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 25), 100);
        $page = max(1, (int) ($filters['page'] ?? 1));

        // Fetch a generous window — collapse can compress many rows into
        // one bucket, so pulling exactly $perPage would under-fill the
        // result. The 5x multiplier is empirical; tune via the follow-up
        // arch test that measures actual ratios per org.
        $windowSize = $perPage * 5;
        $rows = $this->buildQuery($recipient, $filters)
            ->orderByDesc('notifications.created_at')
            ->limit($windowSize)
            ->get();

        $buckets = $this->bucketize($rows);
        $total = $buckets->count();
        $offset = ($page - 1) * $perPage;
        $items = $buckets->slice($offset, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
        );
    }

    /**
     * Same query shape as `NotificationService::listInbox` but joined
     * to notifications so we can ORDER BY + filter on parent fields
     * without N+1 in the grouping pass.
     *
     * @param  array<string, mixed>  $filters
     */
    private function buildQuery(Notifiable $recipient, array $filters)
    {
        $query = NotificationRecipient::query()
            ->forRecipient($recipient)
            ->join('notifications', 'notifications.id', '=', 'notification_recipients.notification_id')
            ->select('notification_recipients.*')
            ->with(['notification.actor', 'notification.subject', 'notification.template']);

        $status = $filters['status'] ?? 'all';
        if ($status === 'unread') {
            $query->whereNull('notification_recipients.read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('notification_recipients.read_at');
        }

        if (! ($filters['include_dismissed'] ?? false)) {
            $query->whereNull('notification_recipients.dismissed_at');
        }

        if (! empty($filters['type'])) {
            $query->where('notifications.type', $filters['type']);
        }
        if (! empty($filters['priority'])) {
            $query->where('notifications.priority', $filters['priority']);
        }
        if (! empty($filters['since'])) {
            $query->where('notifications.created_at', '>=', $filters['since']);
        }
        if (! empty($filters['aggregation_key'])) {
            $query->where('notifications.aggregation_key', $filters['aggregation_key']);
        }

        return $query;
    }

    /**
     * Group rows by aggregation_key. NULL key becomes its own bucket
     * (`single:{notification_id}`) so flat notifications pass through
     * unchanged. The bucket order matches the input order (sorted by
     * notifications.created_at DESC at the query layer).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, NotificationRecipient>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function bucketize($rows): Collection
    {
        $buckets = [];
        $order = [];

        foreach ($rows as $row) {
            /** @var Notification $n */
            $n = $row->notification;
            if ($n === null) {
                continue;
            }
            $key = $n->aggregation_key ?: "single:{$n->id}";
            if (! isset($buckets[$key])) {
                $order[] = $key;
                $buckets[$key] = [
                    'rows' => collect(),
                    'is_collapsed' => $n->aggregation_key !== null,
                    'aggregation_key' => $n->aggregation_key,
                ];
            }
            $buckets[$key]['rows']->push($row);
        }

        return collect($order)->map(function (string $key) use ($buckets) {
            $bucket = $buckets[$key];
            $rows = $bucket['rows'];
            /** @var NotificationRecipient $latestRow */
            $latestRow = $rows->first();
            $latest = $latestRow->notification;

            return [
                'id' => $bucket['is_collapsed'] ? "agg:{$key}" : $latestRow->id,
                'is_collapsed' => $bucket['is_collapsed'],
                'aggregation_key' => $bucket['aggregation_key'],
                'count' => $rows->count(),
                'first_at' => $rows->last()->notification?->created_at,
                'last_at' => $latest?->created_at,
                'latest_recipient_id' => $latestRow->id,
                'latest' => $latestRow,
                'sample' => $rows->take(3)->values(),
            ];
        });
    }
}
