<?php

namespace App\Services\Notification;

use App\Contracts\Notifiable;
use App\Exceptions\NotificationException;
use App\Jobs\DispatchScheduledNotificationJob;
use App\Jobs\NotificationChannelJob;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Omnify\Enums\NotificationChannelEnum;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Omnify\Enums\NotificationPriorityEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * NotificationService — the single programmatic entry point for every
 * emitter (domain services + admin broadcast composer in Phase D).
 *
 * Phase A surface: dispatch + own-inbox read + interaction mutations. Audience
 * resolution (Phase A T12.x), templates (Phase B), channel routing + delivery
 * rows (Phase C), real-time broadcast (Phase D) layer on additively.
 *
 * Follows docs/contributing/service.md:
 *   - dispatch wrapped in DB::transaction (parent + N recipient rows atomic)
 *   - list queries eager-load `actor` + `subject` to avoid N+1
 */
class NotificationService
{
    public function __construct(
        private readonly EffectiveChannelService $effectiveChannels = new EffectiveChannelService,
        private readonly TemplateRenderer $renderer = new TemplateRenderer,
    ) {}

    /**
     * Reserved key under `notifications.params` that holds the per-locale
     * title/body snapshot captured at dispatch time (issue #179 part B).
     *
     * Plan-008 Decision 15 made the original choice to render against the
     * current template at every fetch — readers see live edits. The snapshot
     * is an audit-trail addendum: it lets compliance / legal queries answer
     * "what content was sent on day X" even after a template is edited or
     * deleted, without changing the default behaviour for new product code.
     *
     * Shape: `{ '__snapshot': { '<locale>': { title, body } } }`
     */
    public const SNAPSHOT_KEY = '__snapshot';

    /**
     * Default priority mapping per notification type. Emitters may override
     * via `priority` in the dispatch input; unknown types fall back to normal.
     */
    private const DEFAULT_PRIORITIES = [
        'stock.alert.out' => NotificationPriorityEnum::High,
        'stock.alert.low' => NotificationPriorityEnum::Normal,
        'order.paid' => NotificationPriorityEnum::Normal,
        'order.status_changed' => NotificationPriorityEnum::Normal,
        'recipe.approved' => NotificationPriorityEnum::Normal,
        'recipe.rejected' => NotificationPriorityEnum::Normal,
        'system.critical' => NotificationPriorityEnum::Urgent,
        // #2697 — tiền đã thu và sổ kho lệch. Cao, không khẩn: việc phải làm là
        // một lượt điều chỉnh kho, không phải dựng ai đó dậy lúc nửa đêm.
        'inventory.stock_drift' => NotificationPriorityEnum::High,
        // #2696 — đơn còn treo tiền lúc mở ca kế. Cao: thu ngân đang đứng ở
        // quầy, nhưng không khẩn tới mức vượt quiet hours — ca vẫn mở được
        // (fail-open), chuông là để quản lý thấy, không phải chặn bán.
        'till.unresolved_orders' => NotificationPriorityEnum::High,
        // #2697 — khách có thể vừa trả tiền vào chỗ không có gì hứng. Khẩn:
        // người đó đang đứng ở quầy, và `urgent` là mức duy nhất vượt qua
        // master_mute + quiet hours.
        'payment.paypay_qr_unbookable' => NotificationPriorityEnum::Urgent,
    ];

    // =========================================================================
    //  Dispatch
    // =========================================================================

    /**
     * @param  array{
     *   type: string,
     *   template_key?: string,
     *   params?: array<string, mixed>,
     *   recipients: iterable<Model>,
     *   actor?: Model|null,
     *   subject?: Model|null,
     *   organization_id: string,
     *   idempotency_key?: string|null,
     *   priority?: NotificationPriorityEnum|string|null,
     *   aggregation_key?: string|null,
     * }  $input
     */
    public function dispatch(array $input): Notification
    {
        $rawRecipients = $input['recipients'] ?? [];
        $resolvedVia = $input['resolved_via'] ?? [];

        if ($rawRecipients instanceof Audience) {
            $brand = $input['brand'] ?? null;
            if (! $brand instanceof Brand) {
                throw NotificationException::audienceRequiresBrand();
            }

            $resolution = app(AudienceResolverService::class)->resolveWithTrace($rawRecipients->toRule(), $brand);
            $rawRecipients = $resolution['recipients'];
            // Trace key is "{morphClass}:{primaryKey}" — plumb the pk → trace
            // map into resolved_via so the per-recipient insert in the
            // transaction body can stamp notification_recipients.resolved_via.
            foreach ($resolution['trace'] as $key => $trace) {
                $parts = explode(':', (string) $key, 2);
                if (count($parts) === 2) {
                    $resolvedVia[$parts[1]] = $trace;
                }
            }
        }

        $recipients = $this->normalizeRecipients($rawRecipients);
        $input['resolved_via'] = $resolvedVia;

        if ($recipients->isEmpty()) {
            throw NotificationException::recipientsEmpty();
        }

        $this->validateActorColumns($input['actor'] ?? null);
        $this->validateSubjectColumns($input['subject'] ?? null);

        $attributes = $this->buildNotificationAttributes($input);

        $scheduled = $this->parseScheduledFor($attributes['scheduled_for'] ?? null);
        $isScheduled = $scheduled !== null && $scheduled->greaterThan(now());
        if ($isScheduled) {
            // Mark the parent row as not-yet-dispatched; the delayed job
            // fans out deliveries when the time comes.
            $attributes['is_dispatched'] = false;
        }

        $result = DB::transaction(function () use ($attributes, $recipients, $input, $isScheduled): array {
            // Idempotent path — when an idempotency key is present, second
            // dispatch with the same key returns the first Notification row
            // without writing new recipient rows.
            //
            // `lockForUpdate()` makes the check atomic with the subsequent
            // create() — without it, two concurrent transactions can both
            // miss the SELECT, both call create(), and the loser hits a
            // UNIQUE-constraint QueryException instead of the clean idempotent
            // return the API contract promises (issue #172).
            if (! empty($attributes['idempotency_key'])) {
                $existing = Notification::where('idempotency_key', $attributes['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return ['notification' => $existing, 'deliveryIds' => [], 'fresh' => false, 'scheduled' => false];
                }
            }

            $notification = Notification::create($attributes);

            // Snapshot the rendered title/body for every locale the template
            // defines, into `params.__snapshot`. Reader uses this to answer
            // "what was sent on day X" even after the template row is later
            // edited or deleted. Old notifications without a snapshot fall
            // back to live render (backwards-compatible). Issue #179 part B.
            $this->snapshotTemplateContent($notification);

            $recipientRows = [];
            $recipientIdByKey = [];
            foreach ($recipients as $recipient) {
                /** @var Model $recipient */
                $rid = (string) Str::uuid();
                $key = $recipient->getMorphClass().':'.$recipient->getKey();
                $recipientIdByKey[$key] = $rid;
                $recipientRows[] = [
                    'id' => $rid,
                    'notification_id' => $notification->id,
                    'recipient_type' => $recipient->getMorphClass(),
                    'recipient_id' => $recipient->getKey(),
                    'resolved_via' => $input['resolved_via'][$recipient->getKey()] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            NotificationRecipient::insert($recipientRows);

            // Scheduled notifications defer fan-out to
            // DispatchScheduledNotificationJob at `scheduled_for`. The recipient
            // rows still land now so the HQ audit list shows the pending send.
            if ($isScheduled) {
                return ['notification' => $notification, 'deliveryIds' => [], 'fresh' => true, 'scheduled' => true];
            }

            // Fan out delivery rows per (recipient × effective channel) inside
            // the same transaction so the inbox read always sees a consistent
            // parent+recipient+delivery tuple.
            $deliveryIds = $this->writeDeliveries(
                $notification,
                $recipients,
                $recipientIdByKey,
                $input['channels'] ?? null,
            );

            return ['notification' => $notification, 'deliveryIds' => $deliveryIds, 'fresh' => true, 'scheduled' => false];
        });

        if ($result['fresh'] && ! empty($result['scheduled']) && $scheduled !== null) {
            DispatchScheduledNotificationJob::dispatch($result['notification']->id)->delay($scheduled);
        }

        if ($result['fresh'] && empty($result['scheduled'])) {
            // Run channel sends inline. The original code dispatched the job
            // (queue=redis), but with QUEUE_CONNECTION=sync the realtime path
            // benefits from running in-request so the WS event fires before
            // the HTTP response returns. Wrap each handle in try/catch to
            // keep one channel failure from blocking the others — matches
            // the queue-based path's per-job retry boundary.
            $resolver = app(NotificationChannelResolver::class);
            foreach ($result['deliveryIds'] as $deliveryId) {
                try {
                    (new NotificationChannelJob($deliveryId))->handle($resolver);
                } catch (Throwable $e) {
                    Log::warning('notification.channel.inline_failed', [
                        'delivery_id' => $deliveryId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $result['notification'];
    }

    private function parseScheduledFor(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return CarbonImmutable::parse((string) $value);
    }

    /**
     * Compute effective channels per recipient and insert notification_deliveries
     * rows in one INSERT. Returns the generated delivery ids in insertion order.
     *
     * @param  Collection<int, Model>  $recipients
     * @param  array<string, string>  $recipientIdByKey
     * @return array<int, string>
     */
    private function writeDeliveries(Notification $notification, Collection $recipients, array $recipientIdByKey, ?array $channelOverride = null): array
    {
        $rows = [];
        $deliveryIds = [];

        $override = $channelOverride === null
            ? null
            : collect($channelOverride)
                ->map(fn ($v) => $v instanceof NotificationChannelEnum
                    ? $v
                    : NotificationChannelEnum::tryFrom((string) $v))
                ->filter()
                ->values();

        $channelsByRecipient = $override === null
            ? $this->effectiveChannels->effectiveChannelsForBatch($notification, $recipients)
            : null;

        foreach ($recipients as $recipient) {
            $key = $recipient->getMorphClass().':'.$recipient->getKey();
            $recipientRowId = $recipientIdByKey[$key] ?? null;
            if ($recipientRowId === null) {
                continue;
            }

            $channels = $override ?? ($channelsByRecipient[$key] ?? collect());
            foreach ($channels as $channel) {
                $id = (string) Str::uuid();
                $deliveryIds[] = $id;
                $rows[] = [
                    'id' => $id,
                    'notification_recipient_id' => $recipientRowId,
                    'channel' => $channel->value,
                    'status' => NotificationDeliveryStatusEnum::Pending->value,
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            NotificationDelivery::insert($rows);
        }

        return $deliveryIds;
    }

    /**
     * Default priority for a dot-namespaced type string. Unknown types → normal.
     */
    public function defaultPriorityFor(string $type): NotificationPriorityEnum
    {
        return self::DEFAULT_PRIORITIES[$type] ?? NotificationPriorityEnum::Normal;
    }

    /**
     * Effective channels for a (notification × recipient) pair. Thin pass-through
     * that keeps the filter chain behind the service boundary — the resolver
     * in `EffectiveChannelService` owns the decision logic.
     *
     * @return Collection<int, NotificationChannelEnum>
     */
    public function effectiveChannelsFor(Notification $notification, Notifiable $recipient): Collection
    {
        return $this->effectiveChannels->effectiveChannelsFor($notification, $recipient);
    }

    // =========================================================================
    //  Own-inbox read
    // =========================================================================

    /**
     * @param  array{
     *   status?: 'unread'|'read'|'all',
     *   type?: string,
     *   priority?: string,
     *   since?: string|\DateTimeInterface,
     *   per_page?: int,
     *   include_dismissed?: bool,
     * }  $filters
     */
    public function listInbox(Notifiable $recipient, array $filters = []): LengthAwarePaginator
    {
        $query = NotificationRecipient::query()
            ->forRecipient($recipient)
            ->with(['notification.actor', 'notification.subject', 'notification.template']);

        // Status filter.
        $status = $filters['status'] ?? 'all';
        if ($status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        }

        // Default hide dismissed unless caller asks for all including dismissed.
        if (! ($filters['include_dismissed'] ?? false)) {
            $query->whereNull('dismissed_at');
        }

        // Notification-level filters via whereHas to keep the join indexed.
        $query->whereHas('notification', function ($q) use ($filters) {
            if (! empty($filters['type'])) {
                $q->where('type', $filters['type']);
            }
            if (! empty($filters['priority'])) {
                $q->where('priority', $filters['priority']);
            }
            if (! empty($filters['since'])) {
                $q->where('created_at', '>=', $filters['since']);
            }
        });

        $query->orderByDesc('created_at');

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }

    /**
     * Unread count + per-priority breakdown for the bell badge.
     *
     * @return array{unread_count: int, priority_breakdown: array<string, int>, latest_created_at: ?string}
     */
    public function summaryFor(Notifiable $recipient): array
    {
        $base = NotificationRecipient::query()
            ->forRecipient($recipient)
            ->unread()
            ->notDismissed();

        $unreadCount = (clone $base)->count();

        $breakdown = (clone $base)
            ->join('notifications', 'notification_recipients.notification_id', '=', 'notifications.id')
            ->selectRaw('notifications.priority as priority, count(*) as total')
            ->groupBy('notifications.priority')
            ->pluck('total', 'priority')
            ->toArray();

        $priorityBreakdown = [
            NotificationPriorityEnum::Low->value => (int) ($breakdown['low'] ?? 0),
            NotificationPriorityEnum::Normal->value => (int) ($breakdown['normal'] ?? 0),
            NotificationPriorityEnum::High->value => (int) ($breakdown['high'] ?? 0),
            NotificationPriorityEnum::Urgent->value => (int) ($breakdown['urgent'] ?? 0),
        ];

        $latest = NotificationRecipient::query()
            ->forRecipient($recipient)
            ->notDismissed()
            ->join('notifications', 'notification_recipients.notification_id', '=', 'notifications.id')
            ->max('notifications.created_at');

        return [
            'unread_count' => $unreadCount,
            'priority_breakdown' => $priorityBreakdown,
            'latest_created_at' => $latest,
        ];
    }

    // =========================================================================
    //  Interaction mutations (idempotent)
    // =========================================================================

    public function markSeen(NotificationRecipient $row): void
    {
        $row->markSeen();
    }

    public function markRead(NotificationRecipient $row): void
    {
        $row->markRead();
    }

    public function markDismissed(NotificationRecipient $row): void
    {
        $row->markDismissed();
    }

    /**
     * Bulk mark-as-read. Exactly one of a non-empty id list OR `$allUnread`
     * must be supplied.
     *
     * @param  array<int, string>|null  $ids
     */
    public function bulkMarkRead(Notifiable $recipient, ?array $ids, bool $allUnread): int
    {
        $this->assertExactlyOne($ids, $allUnread);

        $query = NotificationRecipient::query()
            ->forRecipient($recipient)
            ->whereNull('read_at');

        if ($ids !== null && $ids !== []) {
            // Enforce "all caller's rows or nothing" — if any id is not owned
            // by the caller, throw.
            $ownedCount = NotificationRecipient::query()
                ->forRecipient($recipient)
                ->whereIn('notification_id', $ids)
                ->count();

            if ($ownedCount !== count($ids)) {
                throw NotificationException::bulkIdNotForCaller();
            }

            $query->whereIn('notification_id', $ids);
        }

        return $query->update([
            'seen_at' => DB::raw(DB::getDriverName() === 'sqlite' ? "COALESCE(seen_at, datetime('now'))" : 'COALESCE(seen_at, NOW())'),
            'read_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Bulk mark-as-dismissed. Exactly one of `$ids`, `$allRead`, or `$all`
     * must be truthy.
     *
     * @param  array<int, string>|null  $ids
     */
    public function bulkMarkDismissed(
        Notifiable $recipient,
        ?array $ids,
        bool $allRead,
        bool $all,
    ): int {
        $flagsSet = (int) ($ids !== null && $ids !== [])
            + (int) $allRead
            + (int) $all;

        if ($flagsSet !== 1) {
            throw NotificationException::bulkOperationMismatch();
        }

        $query = NotificationRecipient::query()
            ->forRecipient($recipient)
            ->whereNull('dismissed_at');

        if ($ids !== null && $ids !== []) {
            $ownedCount = NotificationRecipient::query()
                ->forRecipient($recipient)
                ->whereIn('notification_id', $ids)
                ->count();

            if ($ownedCount !== count($ids)) {
                throw NotificationException::bulkIdNotForCaller();
            }

            $query->whereIn('notification_id', $ids);
        } elseif ($allRead) {
            $query->whereNotNull('read_at');
        }
        // If $all === true, no extra filter — dismiss everything not yet dismissed.

        return $query->update([
            'dismissed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * GDPR right-to-be-forgotten — purge a recipient's entire inbox state
     * (issue #179 part C). Hard-deletes every NotificationRecipient row for
     * the caller plus the cascading NotificationDelivery children.
     *
     * Does NOT delete the parent Notification row — other recipients may
     * still need it (a stock alert sent to 5 managers shouldn't disappear
     * for everyone just because one of them purged their inbox).
     *
     * Returns the number of recipient rows removed (NOT the delivery count
     * — deliveries cascade and don't have a per-row meaning to the user).
     */
    public function forgetRecipient(Notifiable $recipient): int
    {
        return DB::transaction(function () use ($recipient): int {
            $recipientIds = NotificationRecipient::query()
                ->forRecipient($recipient)
                ->pluck('id')
                ->all();

            if ($recipientIds === []) {
                return 0;
            }

            // Delete deliveries explicitly first; the FK is set to cascade
            // on most stacks, but doing it in one transaction keeps the
            // delete atomic across drivers (e.g. SQLite without FK enforce).
            NotificationDelivery::query()
                ->whereIn('notification_recipient_id', $recipientIds)
                ->delete();

            return NotificationRecipient::query()
                ->whereIn('id', $recipientIds)
                ->delete();
        });
    }

    // =========================================================================
    //  Admin audit (HQ, brand-scoped)
    // =========================================================================

    /**
     * Audit list of every notification in the brand's organizations.
     *
     * @param  array{
     *   type?: string|array<int, string>,
     *   actor_type?: string,
     *   actor_id?: string,
     *   recipient_type?: string,
     *   recipient_id?: string,
     *   priority?: string,
     *   from?: string|\DateTimeInterface,
     *   to?: string|\DateTimeInterface,
     *   organization_id?: string,
     *   search?: string,
     *   sort?: string,
     *   per_page?: int,
     * }  $filters
     */
    public function listForBrand(Brand $brand, array $filters = []): LengthAwarePaginator
    {
        $orgIds = $this->brandOrganizationIds($brand);

        $query = Notification::query()
            ->whereIn('organization_id', $orgIds)
            ->with(['actor', 'subject', 'organization', 'recipients'])
            ->withCount([
                'recipients as recipients_total',
                'recipients as recipients_seen' => fn ($q) => $q->whereNotNull('seen_at'),
                'recipients as recipients_read' => fn ($q) => $q->whereNotNull('read_at'),
                'recipients as recipients_dismissed' => fn ($q) => $q->whereNotNull('dismissed_at'),
            ]);

        if (! empty($filters['type'])) {
            $types = (array) $filters['type'];
            $query->whereIn('type', $types);
        }

        if (array_key_exists('actor_type', $filters)) {
            // 'null' literal string → system events
            if ($filters['actor_type'] === 'null' || $filters['actor_type'] === null) {
                $query->whereNull('actor_type');
            } else {
                $query->where('actor_type', $filters['actor_type']);
                if (! empty($filters['actor_id'])) {
                    $query->where('actor_id', $filters['actor_id']);
                }
            }
        }

        if (! empty($filters['recipient_type']) && ! empty($filters['recipient_id'])) {
            $query->whereHas('recipients', fn ($q) => $q
                ->where('recipient_type', $filters['recipient_type'])
                ->where('recipient_id', $filters['recipient_id']),
            );
        }

        $query->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p));
        $query->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d));
        $query->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d));
        $query->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id));

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q
                ->where('type', 'like', $term)
                ->orWhere('params', 'like', $term),
            );
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * Cancel a not-yet-dispatched broadcast (#1666, extracted from
     * `NotificationAdminController::destroy`).
     *
     * Same freeze window as {@see NotificationScheduleCanceller}, and it reads
     * that class's constant rather than repeating `60`: the two paths cancel the
     * same kind of thing for the same reason — once the worker starts
     * materialising, the notification is in recipient inboxes and cannot be
     * pulled back — so they must not be able to drift apart by one edit.
     */
    public function cancelScheduled(Notification $notification, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        if ($notification->is_dispatched) {
            throw new NotificationException('already_dispatched', 'Notification has already been dispatched.');
        }

        if ($notification->scheduled_for === null) {
            throw new NotificationException('not_scheduled', 'Notification is not scheduled.');
        }

        $remaining = $notification->scheduled_for->getTimestamp() - $now->getTimestamp();
        if ($remaining < NotificationScheduleCanceller::FREEZE_WINDOW_SECONDS) {
            throw NotificationException::withinFreezeWindow(max($remaining, 0));
        }

        $notification->delete();
    }

    /**
     * Detail endpoint — full fan-out with recipient state. 404 when the
     * notification does not belong to this brand's orgs.
     */
    public function showForBrand(Brand $brand, string $notificationId): Notification
    {
        $orgIds = $this->brandOrganizationIds($brand);

        return Notification::query()
            ->whereIn('organization_id', $orgIds)
            ->with([
                'actor',
                'subject',
                'organization',
                'recipients.recipient',
            ])
            ->findOrFail($notificationId);
    }

    /**
     * Distinct types currently in use within the brand — for the admin list
     * filter dropdown.
     *
     * @return Collection<int, array{type: string, count: int, last_at: string|null}>
     */
    public function typesForBrand(Brand $brand): Collection
    {
        $orgIds = $this->brandOrganizationIds($brand);

        return Notification::query()
            ->whereIn('organization_id', $orgIds)
            ->selectRaw('type, count(*) as count, max(created_at) as last_at')
            ->groupBy('type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->type,
                'count' => (int) $row->count,
                'last_at' => $row->last_at,
            ]);
    }

    /**
     * Quick header counts for the brand notifications page (TC-NOTIF-ROOT04):
     *   - sent:      notifications already dispatched
     *   - scheduled: future broadcasts not yet dispatched
     *   - failed:    delivery attempts that failed across the brand
     *
     * @return array{sent: int, scheduled: int, failed: int}
     */
    public function statsForBrand(Brand $brand): array
    {
        $orgIds = $this->brandOrganizationIds($brand);

        $sent = Notification::query()
            ->whereIn('organization_id', $orgIds)
            ->where('is_dispatched', true)
            ->count();

        $scheduled = Notification::query()
            ->whereIn('organization_id', $orgIds)
            ->where('is_dispatched', false)
            ->whereNotNull('scheduled_for')
            ->count();

        $failed = NotificationDelivery::query()
            ->where('notification_deliveries.status', NotificationDeliveryStatusEnum::Failed->value)
            ->whereExists(function ($q) use ($orgIds) {
                $q->select(DB::raw(1))
                    ->from('notification_recipients as nr')
                    ->join('notifications as n', 'n.id', '=', 'nr.notification_id')
                    ->whereColumn('nr.id', 'notification_deliveries.notification_recipient_id')
                    ->whereIn('n.organization_id', $orgIds);
            })
            ->count();

        return [
            'sent' => $sent,
            'scheduled' => $scheduled,
            'failed' => $failed,
        ];
    }

    /**
     * Resolve the local organization ids that belong to a brand — shared
     * scope for all listForBrand / showForBrand / typesForBrand queries.
     *
     * @return array<int, string>
     */
    private function brandOrganizationIds(Brand $brand): array
    {
        return Organization::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->pluck('id')
            ->all();
    }

    // =========================================================================
    //  Internal helpers
    // =========================================================================

    /**
     * Normalize + deduplicate the recipient input.
     *
     * @param  iterable<Model>  $recipients
     * @return Collection<int, Model>
     */
    private function normalizeRecipients(iterable $recipients): Collection
    {
        $seen = [];
        $out = collect();

        foreach ($recipients as $recipient) {
            if (! $recipient instanceof Model) {
                throw NotificationException::notNotifiable(
                    is_object($recipient) ? $recipient::class : gettype($recipient),
                );
            }

            if (! $recipient instanceof Notifiable) {
                throw NotificationException::notNotifiable($recipient::class);
            }

            $morphClass = $recipient->getMorphClass();
            if (Relation::getMorphedModel($morphClass) === null
                && ! class_exists($morphClass)) {
                throw NotificationException::unregisteredMorphType($morphClass);
            }

            $key = $morphClass.':'.$recipient->getKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out->push($recipient);
        }

        return $out;
    }

    private function validateActorColumns(?Model $actor): void
    {
        // actor is optional; when provided must be a model.
        // Column invariant (both-null or both-non-null) is guaranteed by the
        // attribute builder — no separate check needed here.
    }

    private function validateSubjectColumns(?Model $subject): void
    {
        // Same as actor — builder enforces the null-together invariant.
    }

    /**
     * Translate the dispatch input into a row shape for Notification::create.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildNotificationAttributes(array $input): array
    {
        $actor = $input['actor'] ?? null;
        $subject = $input['subject'] ?? null;

        $priority = $input['priority'] ?? null;
        if ($priority instanceof NotificationPriorityEnum) {
            $priority = $priority->value;
        } elseif ($priority === null) {
            $priority = $this->defaultPriorityFor($input['type'])->value;
        }

        return [
            'organization_id' => $input['organization_id'],
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'type' => $input['type'],
            'template_key' => $input['template_key'] ?? $input['type'],
            'params' => $input['params'] ?? [],
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'priority' => $priority,
            'aggregation_key' => $input['aggregation_key'] ?? null,
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'audience_id' => $input['audience_id'] ?? null,
            'scheduled_for' => $input['scheduled_for'] ?? null,
            'is_dispatched' => $input['is_dispatched'] ?? true,
        ];
    }

    /**
     * @param  array<int, string>|null  $ids
     */
    private function assertExactlyOne(?array $ids, bool $allFlag): void
    {
        $hasIds = $ids !== null && $ids !== [];
        if ($hasIds === $allFlag) {
            throw NotificationException::bulkOperationMismatch();
        }
    }

    /**
     * Render the notification's template for every locale it defines and
     * store the result under `params.__snapshot`. No-op when the template
     * row can't be resolved (inline / on-the-fly broadcasts have no
     * persisted template — and that's fine, the renderer falls back to
     * the live `template_inline` content at read time anyway).
     */
    private function snapshotTemplateContent(Notification $notification): void
    {
        $template = $notification->template;
        if ($template === null) {
            return;
        }

        $rendered = $this->renderer->renderAll($template, (array) $notification->params);
        if ($rendered === []) {
            return;
        }

        $params = (array) $notification->params;
        $params[self::SNAPSHOT_KEY] = $rendered;
        $notification->forceFill(['params' => $params])->save();
    }
}
