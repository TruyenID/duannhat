<?php

declare(strict_types=1);

namespace App\Services\Workstation;

/**
 * #1175 phase 3 — broadcast connection details for the workstation's poke
 * client, mirrored down via the branch feed's settings KV flatten. Key names
 * are the FROZEN Go-side contract: broadcast_app_key / _host / _port / _scheme.
 * Any required piece missing → keys omitted → poke silently off (the
 * non-negotiable invariant: pull never depends on this). Provider swap stays a
 * pure BROADCAST_CONNECTION config change.
 *
 * Lives OUTSIDE BranchController because two consumers must agree byte-for-byte:
 * the branch feed payload (BranchController) and the branch_settings feed
 * version (SyncManifestService). These values derive from Laravel CONFIG, not
 * rows — a manifest that hashes only DB state serves 304 forever after a config
 * or mapping change, and the fleet never hears about it. Measured 2026-08-18:
 * the api-→ws- host fix below deployed, and every already-synced workstation
 * kept the broken host until its manifest cursor was cleared by hand.
 */
class BroadcastPokeSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function resolve(): array
    {
        $driver = (string) config('broadcasting.default');
        $conn = (array) config("broadcasting.connections.{$driver}", []);
        $key = (string) ($conn['key'] ?? '');
        $options = (array) ($conn['options'] ?? []);
        $host = self::webSocketHost($driver, $options);

        if ($key === '' || $host === '') {
            // log/null, or pusher without a cluster, simply don't advertise poke.
            return [];
        }

        return [
            'broadcast_app_key' => $key,
            'broadcast_host' => $host,
            'broadcast_port' => $options['port'] ?? null,
            'broadcast_scheme' => ($options['useTLS'] ?? (($options['scheme'] ?? 'https') === 'https')) ? 'https' : 'http',
        ];
    }

    /**
     * Fingerprint for the sync manifest: config-derived state has no
     * updated_at row to hash, so the feed version carries this instead.
     */
    public static function fingerprint(): string
    {
        return md5((string) json_encode(self::resolve()));
    }

    /**
     * Laravel's pusher `options.host` is the HTTP *API* host
     * (`api-{cluster}.pusher.com`). The Go poke client dials WebSocket at
     * `ws-{cluster}.pusher.com`. Advertising the API host would make every
     * workstation fail the handshake while PHP broadcasts succeed.
     *
     * @param  array<string, mixed>  $options
     */
    private static function webSocketHost(string $driver, array $options): string
    {
        $host = (string) ($options['host'] ?? '');
        $cluster = (string) ($options['cluster'] ?? '');

        if ($driver === 'pusher' && $cluster !== '') {
            $isPusherHttpApi = $host === ''
                || (str_starts_with($host, 'api-') && str_ends_with($host, '.pusher.com'));
            if ($isPusherHttpApi) {
                return 'ws-'.$cluster.'.pusher.com';
            }
        }

        return $host;
    }
}
