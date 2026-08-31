<?php

namespace App\Services\Device;

use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceService
{
    /**
     * How stale `devices.last_seen_at` must be before a heartbeat rewrites it.
     *
     * The budget is set by the only consumer that acts on staleness:
     * `DeviceOfflineDetectionJob` runs every 5 minutes and alerts at
     * `notifications.device_offline_threshold_minutes` (default 15). A stamp up
     * to 60s late is two orders of magnitude inside that window, so throttling
     * cannot turn a live device into a false offline alert. Widening this past
     * the offline threshold WOULD — that is the line, not the number itself.
     */
    public const LAST_SEEN_THROTTLE_SECONDS = 60;

    /**
     * @param  array{organization_id?: string, status?: string, type?: string, branch_id?: string, search?: string, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Device::query()->with('branch');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $query->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId));

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('pairing_code', 'like', "%{$search}%");
            });
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Device
    {
        return Device::with('branch')->findOrFail($id);
    }

    public function create(array $data): Device
    {
        if (empty($data['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => __('Branch is required.'),
            ]);
        }

        // Guard the (branch_id, name) unique constraint here — the request-level
        // unique rule scopes on the request's branch_id, which is empty for
        // shop-scoped calls (the branch is resolved from the shop context after
        // validation), so it lets a duplicate through and the DB throws a raw
        // 1062 500. Fail with a friendly 422 instead.
        if (! empty($data['name']) && Device::query()
            ->where('branch_id', $data['branch_id'])
            ->where('name', $data['name'])
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => __('A device named ":name" already exists for this branch.', ['name' => $data['name']]),
            ]);
        }

        $data['status'] = DeviceStatusEnum::PendingActivation->value;
        $data['pairing_code'] = $this->generatePairingCode();
        $data['pairing_expires_at'] = now()->addMinutes(15);

        return Device::create($data)->load('branch');
    }

    public function update(Device $device, array $data): Device
    {
        // Same (branch_id, name) guard as create — renaming onto an existing
        // name in the same branch would otherwise raise a raw 1062 500.
        if (! empty($data['name']) && $data['name'] !== $device->name && Device::query()
            ->where('branch_id', $device->branch_id)
            ->where('name', $data['name'])
            ->whereKeyNot($device->getKey())
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => __('A device named ":name" already exists for this branch.', ['name' => $data['name']]),
            ]);
        }

        $device->update($data);

        return $device->load('branch');
    }

    public function delete(Device $device): bool
    {
        return $device->delete();
    }

    public function restore(Device $device): Device
    {
        $device->restore();

        return $device->load('branch');
    }

    /**
     * Regenerate pairing code. Only allowed when device is pending_activation or active.
     */
    public function regeneratePairingCode(Device $device): Device
    {
        if (! in_array($device->status, [DeviceStatusEnum::PendingActivation, DeviceStatusEnum::Active])) {
            throw ValidationException::withMessages([
                'status' => __('Pairing code can only be regenerated for pending or active devices.'),
            ]);
        }

        $device->update([
            'pairing_code' => $this->generatePairingCode(),
            'pairing_expires_at' => now()->addMinutes(15),
        ]);

        return $device->load('branch');
    }

    /**
     * Pair a device using a pairing code. Returns the device with its new token.
     *
     * @param  array{user_agent?: string, ip_address?: string, app_version?: string}  $deviceInfo
     * @param  list<string>|null  $expectedTypes  Device types the calling app accepts; null skips the check (legacy).
     */
    public function pair(string $pairingCode, array $deviceInfo = [], ?array $expectedTypes = null): Device
    {
        // `app_version_seen_at` is the ONLY thing separating a measured version
        // from a self-declared one (#2142), and `device_info` is validated as a
        // free-form array — so a client could post the key at pair time and
        // award itself the `heartbeat` label without ever having heartbeat.
        //
        // The version VALUE is self-declared either way, so this is not a
        // privilege issue. What it protects is the premise the whole three-state
        // design rests on: the PRESENCE of this timestamp proves the value
        // arrived on a live authenticated request. Evidence the client can write
        // is not evidence. Only `heartbeat()` may stamp it.
        unset($deviceInfo['app_version_seen_at']);

        return DB::transaction(function () use ($pairingCode, $deviceInfo, $expectedTypes) {
            $device = Device::where('pairing_code', $pairingCode)
                ->whereNotNull('pairing_expires_at')
                ->where('pairing_expires_at', '>', now())
                ->first();

            if (! $device) {
                throw ValidationException::withMessages([
                    'pairing_code' => __('Invalid or expired pairing code.'),
                ]);
            }

            // Gate on device type before mutating so a mismatch rolls back the
            // transaction and leaves the pairing code usable by the right app.
            if (! empty($expectedTypes) && ! in_array($device->type->value, $expectedTypes, true)) {
                throw ValidationException::withMessages([
                    'pairing_code' => [__('This pairing code belongs to a ":type" device and cannot be used by this app.', [
                        'type' => $device->type->label(),
                    ])],
                ]);
            }

            $device->update([
                'device_token' => Str::random(64),
                'status' => DeviceStatusEnum::Active->value,
                'paired_at' => now(),
                'last_seen_at' => now(),
                'device_info' => $deviceInfo,
                'pairing_code' => null,
                'pairing_expires_at' => null,
            ]);

            return $device->load('branch');
        });
    }

    /**
     * Revoke a device — clears token and sets status to revoked.
     */
    public function revoke(Device $device): Device
    {
        return DB::transaction(function () use ($device) {
            $device->update([
                'device_token' => null,
                'status' => DeviceStatusEnum::Revoked->value,
            ]);

            // #1093 BR-DSK03 — a revoked device's signing keys die with it:
            // offline orders signed by a revoked device must fail verification.
            app(DeviceSigningKeyService::class)->revokeAllFor($device, 'device_revoked');

            return $device->load('branch');
        });
    }

    /**
     * The device revokes ITSELF — operator taps Unpair on the workstation.
     *
     * Sibling of `revoke()` (the HQ admin action), deliberately not the same
     * call: unpairing also clears `paired_at` and stamps `last_seen_at`, which
     * an admin revocation does not — an admin killing a device from HQ keeps
     * the pairing record intact (`device.unpaired` is audited as the pair
     * status + paired_at, see AuditCoveragePrecheckCommand). The revoked_reason
     * differs for the same reason: `device_self_revoked` vs `device_revoked`.
     *
     * The transaction is the point (#1669). This ran as two unrelated writes in
     * `DeviceController::selfRevoke` — keys first, device second — so a failure
     * between them left the signing keys revoked while the device stayed
     * `active` holding a working `device_token`: it could still call every
     * /workstation/* endpoint, just not sign an offline order. #1093 BR-DSK03
     * requires revocation to be immediate AND retroactive, and nothing watches
     * for that half-state.
     */
    public function selfRevoke(Device $device): Device
    {
        return DB::transaction(function () use ($device) {
            $device->update([
                'status' => DeviceStatusEnum::Revoked->value,
                'device_token' => null,
                'paired_at' => null,
                'last_seen_at' => now(),
            ]);

            // #1093 BR-DSK03 — unpair invalidates every signing key immediately.
            app(DeviceSigningKeyService::class)->revokeAllFor($device, 'device_self_revoked');

            return $device;
        });
    }

    /**
     * Fire-and-forget liveness stamp (`last_seen_at`), plus an OPTIONAL
     * app-version refresh.
     *
     * `device_info.app_version` used to be written exactly once, at pairing, and
     * never again — so after any app upgrade the stored value was simply wrong,
     * with nothing marking it as stale. A version indicator reading that column
     * would answer confidently and incorrectly, which is worse than having no
     * indicator: it says "yes" to "is this measured?" (#2142).
     *
     * `app_version_seen_at` is what makes the difference legible. Its PRESENCE
     * means the version arrived on a live authenticated request; its absence
     * means the only value we ever had came from the pairing payload. Consumers
     * must be able to tell those apart — see `DeviceResource::app_version_source`
     * — because #2041 step 3 drops three money columns once "how many
     * workstations still cannot read the ledger?" reaches zero, and a stale
     * value counted as current would make that zero a fiction.
     *
     * The JSON column is only rewritten when the version actually CHANGES (or on
     * the first live report). Every authenticated device request lands here, so
     * an unconditional write would put a JSON update on the hot path to buy
     * nothing — `last_seen_at` already carries freshness.
     *
     * `last_seen_at` is now held to the same standard (#2714, parent #2711). An
     * idle workstation alone polls ~144 times a minute; two idle shops were
     * ~5 `UPDATE devices` a second with nothing happening in either of them.
     * The stamp is throttled to `LAST_SEEN_THROTTLE_SECONDS` — see the constant
     * for why 60s cannot make a live device look offline.
     */
    public function heartbeat(Device $device, ?string $appVersion = null): void
    {
        $attributes = [];

        $version = is_string($appVersion) ? trim($appVersion) : '';

        // Bounded AND well-formed: the header is client-supplied and lands in a
        // JSON column.
        //
        // The encoding check is not belt-and-braces. `device_info` is cast to
        // `array`, so Eloquent `json_encode`s it on save — and a byte in
        // 0x80–0xFF (valid in a header value as far as nginx is concerned)
        // makes that throw `JsonEncodingException` INSIDE the middleware,
        // before `$next($request)`. The whole request 500s.
        //
        // That would break the promise the call site states out loud: an
        // optional telemetry header must never be "a downgrade in service". A
        // build that somehow stamped an invalid `config.Version` (ldflags, a CI
        // variable) would 500 every sync-UP call from that workstation — orders
        // stop reaching Cloud because of a telemetry column.
        if ($version !== '' && mb_strlen($version) <= 64 && mb_check_encoding($version, 'UTF-8')) {
            $info = is_array($device->device_info) ? $device->device_info : [];

            $changed = ($info['app_version'] ?? null) !== $version;
            $neverConfirmedLive = ($info['app_version_seen_at'] ?? null) === null;

            if ($changed || $neverConfirmedLive) {
                $info['app_version'] = $version;
                $info['app_version_seen_at'] = now()->toISOString();
                $attributes['device_info'] = $info;
            }
        }

        // A version write is already paying for the row, and the row it writes
        // claims the device is live right now — so it carries the stamp with it
        // even inside the throttle window. `app_version_seen_at` must never be
        // newer than `last_seen_at`.
        if ($attributes !== [] || $this->lastSeenStampIsDue($device)) {
            $attributes['last_seen_at'] = now();
        }

        if ($attributes === []) {
            return;
        }

        $device->updateQuietly($attributes);
    }

    /**
     * Read the in-memory stamp on the already-loaded model — deliberately no
     * extra SELECT, since the whole point is to take work OFF the hot path.
     *
     * A device that has never been seen is stamped immediately: absence is not
     * freshness, and `DeviceResource`/the offline detector both read this column
     * to answer "is this thing alive?".
     *
     * A stamp in the FUTURE also counts as due. That is not a supported state,
     * but treating it as "fresh forever" would freeze the column permanently,
     * whereas re-stamping heals it on the next request.
     */
    private function lastSeenStampIsDue(Device $device): bool
    {
        $lastSeenAt = $device->last_seen_at;

        if ($lastSeenAt === null) {
            return true;
        }

        return $lastSeenAt->diffInSeconds(now(), absolute: true) >= self::LAST_SEEN_THROTTLE_SECONDS;
    }

    /**
     * Lightweight list for dropdown selects.
     *
     * @return array<int, array{id: string, name: string, type: string, branch_id: string}>
     */
    public function lookup(string $organizationId): array
    {
        return Device::where('organization_id', $organizationId)
            ->where('status', DeviceStatusEnum::Active)
            ->select(['id', 'name', 'type', 'branch_id'])
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    /**
     * Find a device by its bearer token (used by device auth middleware).
     */
    public function findByToken(string $token): ?Device
    {
        return Device::where('device_token', $token)->first();
    }

    private function generatePairingCode(): string
    {
        return strtoupper(Str::random(6));
    }
}
