<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait AuditsActivity
{
    protected static bool $auditingEnabled = true;

    protected static ?string $auditActorId = null;

    protected static bool $auditActorOverrideActive = false;

    public static function bootAuditsActivity(): void
    {
        static::created(function ($model) {
            if (static::$auditingEnabled) {
                $model->logAudit('created');
            }
        });

        static::updated(function ($model) {
            if (static::$auditingEnabled) {
                $changes = $model->getChanges();
                $excluded = $model->auditExclude ?? [];
                $changes = array_diff_key($changes, array_flip($excluded));
                unset($changes['updated_at']);

                if (! empty($changes)) {
                    $model->logAudit('updated', [
                        'changes' => $changes,
                        'original' => array_intersect_key($model->getOriginal(), $changes),
                    ]);
                }
            }
        });

        static::deleted(function ($model) {
            if (static::$auditingEnabled) {
                $action = $model->isForceDeleting() ? 'force_deleted' : 'deleted';
                $model->logAudit($action);
            }
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                if (static::$auditingEnabled) {
                    $model->logAudit('restored');
                }
            });
        }
    }

    /**
     * Log a custom audit action.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logAudit(string $action, array $metadata = []): void
    {
        if (! class_exists(AuditLog::class)) {
            return;
        }

        try {
            // `+`, not `array_merge`: both keep the caller's value on a key
            // collision, but `array_merge` RENUMBERS integer keys. Metadata is
            // string-keyed today and a renumber would be silent if that ever
            // stopped being true.
            $metadata += $this->auditRequestFingerprint();

            AuditLog::create([
                'auditable_type' => $this->getMorphClass(),
                'auditable_id' => $this->getKey(),
                'action' => $action,
                'user_id' => static::$auditActorOverrideActive ? static::$auditActorId : Auth::id(),
                'metadata' => ! empty($metadata) ? $metadata : null,
            ]);
        } catch (\Throwable) {
            // Silently fail — audit should never break business logic
        }
    }

    /**
     * Who made the HTTP call this audit row belongs to (#2522).
     *
     * WHY. The C-6 investigation at 人形町店 could establish that four separate
     * requests each added a bowl of pho, and could NOT establish whether they
     * came from one phone or four — which is the difference between "a customer
     * tapped confirm repeatedly" and "four people at one table each ordered".
     * That question decided a product ruling (whether to merge same-SKU adds
     * and kitchen slips), and nothing in the system could answer it: there is
     * no HTTP access log on the host, and `table_sessions` records no devices.
     * This is the one chokepoint every audit row already passes through.
     *
     * `user_agent` is the field that actually discriminates. Four phones on a
     * shop's wifi share ONE public IP, so `ip` alone would have answered
     * nothing for the very case that prompted this.
     *
     * WHY THERE IS NO `ip` HERE (#2554). It was recorded for one commit and
     * then removed. Two facts together made it the wrong trade: `audit_logs`
     * has **no prune or archival job at all** (see
     * `docs/explanation/observability.md` §PCI Req 10.6), and this trait is the
     * chokepoint for EVERY audited write — including guest customers scanning a
     * table QR. So the field was personal data accumulating in an unbounded
     * table, in exchange for a value that, by the argument above, does not even
     * answer the question it was added for.
     *
     * A security investigation genuinely does want an address, and that is a
     * fair need — but it has to arrive together with a retention policy, not
     * before one. Tracked as #2555; revisit this field only after that lands.
     *
     * Never overwrites an explicit key: `logAudit()` unions these UNDER the
     * caller's metadata, so a caller that already resolved something better
     * (a relay, a webhook forwarder) keeps its own.
     *
     * @return array<string, string>
     */
    private function auditRequestFingerprint(): array
    {
        if (! app()->bound('request')) {
            return [];
        }

        $request = request();
        $requestId = $request->attributes->get('request_id');
        $hasRequestId = is_string($requestId) && $requestId !== '';

        // A console run STILL has a bound `request`, and it answers
        // `userAgent() === 'Symfony'` (and `ip() === '127.0.0.1'`, back when ip
        // was recorded). Measured with `artisan tinker`, not assumed. Writing
        // that would stamp every scheduled command and queue job with a caller
        // that does not exist — and "Symfony" reads like a real client, so
        // nobody would question it.
        //
        // `request_id` is the discriminator because `EnsureRequestId` is
        // prepended to the API middleware group, so the attribute exists only
        // when an actual request went through the HTTP stack. It is also what
        // keeps this testable: PHPUnit reports `runningInConsole() === true`
        // even while driving `postJson()`, so gating on that alone would
        // disable the fingerprint in every test and leave it unproven in the
        // one place it is checked.
        if (app()->runningInConsole() && ! $hasRequestId) {
            return [];
        }

        $fingerprint = [];

        if ($hasRequestId) {
            $fingerprint['request_id'] = $requestId;
        }

        // Truncated because a User-Agent is attacker-controlled free text and
        // this lands in a JSON column on every audited write. 512 keeps every
        // real browser/app UA intact — the longest in the wild are ~200 — while
        // refusing to let one request bloat the table.
        $userAgent = $request->userAgent();
        if (is_string($userAgent) && $userAgent !== '') {
            $fingerprint['user_agent'] = mb_strcut($userAgent, 0, 512);
        }

        return $fingerprint;
    }

    public static function withoutAuditing(callable $callback): mixed
    {
        static::$auditingEnabled = false;

        try {
            return $callback();
        } finally {
            static::$auditingEnabled = true;
        }
    }

    public static function withAuditActor(?string $actorId, callable $callback): mixed
    {
        $previous = static::$auditActorId;
        $previousActive = static::$auditActorOverrideActive;
        static::$auditActorId = $actorId;
        static::$auditActorOverrideActive = true;

        try {
            return $callback();
        } finally {
            static::$auditActorId = $previous;
            static::$auditActorOverrideActive = $previousActive;
        }
    }
}
