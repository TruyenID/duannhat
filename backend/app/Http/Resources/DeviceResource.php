<?php

/**
 * Device Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Device\Resources\DeviceResourceBase;
use Illuminate\Http\Request;

/**
 * DeviceResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class DeviceResource extends DeviceResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Never expose device_token in admin list/show endpoints
        unset($data['device_token']);

        return $data + $this->appVersionStatus();
    }

    /**
     * Three-state answer to "what version is this device running?" (#2142).
     *
     * `device_info` already carried `app_version`, but a raw value cannot say
     * where it came from — and the two origins are not equally trustworthy:
     *
     *   - `heartbeat` — reported on a live authenticated request. Current.
     *   - `pairing`   — the only value ever recorded was the pairing payload.
     *     The device may have been upgraded any number of times since; this
     *     number is a guess wearing the clothes of a measurement.
     *   - `unknown`   — nothing was ever reported.
     *
     * Collapsing `pairing`/`unknown` into `heartbeat` is the failure that
     * matters. #2041 step 3 drops three money columns once the count of
     * workstations too old to read the ledger reaches zero; a stale value read
     * as current makes that zero a fiction, and the columns go with it.
     *
     * Counting them as OLD instead is merely pessimistic — it delays a cleanup.
     * That asymmetry is why the unknown state is named rather than defaulted.
     *
     * @return array{app_version: ?string, app_version_source: string, app_version_seen_at: ?string}
     */
    private function appVersionStatus(): array
    {
        $info = is_array($this->device_info) ? $this->device_info : [];

        $version = $info['app_version'] ?? null;
        $version = is_string($version) && trim($version) !== '' ? trim($version) : null;

        $seenAt = $info['app_version_seen_at'] ?? null;
        $seenAt = is_string($seenAt) && $seenAt !== '' ? $seenAt : null;

        return [
            'app_version' => $version,
            'app_version_source' => match (true) {
                $version === null => 'unknown',
                $seenAt !== null => 'heartbeat',
                default => 'pairing',
            },
            'app_version_seen_at' => $seenAt,
        ];
    }
}
