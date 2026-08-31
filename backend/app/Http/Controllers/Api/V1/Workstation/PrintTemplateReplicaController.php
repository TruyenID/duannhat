<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\ResolvedTemplate;
use App\Services\Print\TemplateResolver;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * plan-053 M3 (#1171) — sync DOWN, DESIGN §5 / TASKS T3.3.
 *
 *   GET /api/v1/workstation/print-templates?since=
 *   GET /api/v1/workstation/print-templates/{kind}/versions/{version}
 *
 * The payload carries definitions **already resolved for this device's
 * branch** — system → brand → shop merged here, once, on the server. The
 * workstation must not re-implement the three-layer merge: a second
 * implementation of TR-02/TR-04 in Go is a guaranteed source of "the preview
 * and the slip disagree" bugs, and the shop that suffers it is the one that
 * cannot see the code.
 *
 * Each entry also carries `checksum`, which is how the workstation decides a
 * download is complete and trustworthy before it is allowed to replace a
 * working cache (TR-24 — never overwrite a good cache with unverified bytes).
 *
 * `branch_wall_clock` is included deliberately: `effective_from` is a
 * BRANCH-LOCAL wall clock (#1091), and a workstation whose own clock has
 * drifted can compare against Cloud's answer while it is online (TR-25).
 *
 * Auth is the standard `device.auth:workstation` device token; the branch is
 * taken from the paired device, never from the client. An explicit
 * `?branch_id=` that disagrees with the device is a 403 (S3).
 */
class PrintTemplateReplicaController extends Controller
{
    public function __construct(private readonly TemplateResolver $resolver) {}

    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if (! $branchId) {
            return response()->json(['data' => [], 'generated_at' => now()->toIso8601String()]);
        }

        // S3 — a device may only ever pull ITS branch's templates. The device
        // is pinned at pairing, so an explicit mismatch is an attempt, not a
        // mistake.
        $requestedBranch = $request->query('branch_id');
        if (is_string($requestedBranch) && $requestedBranch !== '' && $requestedBranch !== (string) $branchId) {
            return response()->json([
                'message' => 'Device not authorized for this branch.',
                'code' => 'BRANCH_MISMATCH',
            ], 403);
        }

        $since = $request->query('since');
        $since = is_string($since) && $since !== '' ? $since : null;

        $data = [];
        foreach ($this->resolver->allForBranch((string) $branchId) as $resolved) {
            // Delta (S1): with `?since=` the device already holds a good copy,
            // so send only what changed. The system default has no updated_at
            // — it ships in the binary and is therefore never "newer".
            if ($since !== null) {
                if ($resolved->updatedAt === null || $resolved->updatedAt <= $since) {
                    continue;
                }
            }

            $data[] = $this->entry($resolved);
        }

        return response()->json([
            'data' => $data,
            'branch_timezone' => BusinessClock::timezoneForBranch((string) $branchId),
            'branch_wall_clock' => BusinessClock::now((string) $branchId)->format('Y-m-d H:i:s'),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * A specific historical version — the reprint path (TR-28).
     *
     * Soft-deleted rows are included: a brand or branch that has been removed
     * must not make 再発行 of last month's invoice impossible (TR-39/S4). A
     * version that is genuinely gone returns 404 so the caller can print the
     * current one WITH the "template has changed" marker (TR-29) instead of
     * silently substituting it.
     */
    public function showVersion(Request $request, string $kind, int $version): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;

        if (! $branchId || PrintTemplateKind::tryFrom($kind) === null) {
            return response()->json([
                'message' => "Unknown print template kind [{$kind}].",
                'code' => 'PRINT_TEMPLATE_KIND_UNKNOWN',
            ], 422);
        }

        $resolved = $this->resolver->forVersion($kind, (string) $branchId, $version);

        if ($resolved === null) {
            return response()->json([
                'message' => 'That template version no longer exists.',
                'code' => 'PRINT_TEMPLATE_VERSION_GONE',
            ], 404);
        }

        return response()->json(['data' => $this->entry($resolved)]);
    }

    /** @return array<string, mixed> */
    private function entry(ResolvedTemplate $resolved): array
    {
        return [
            'kind' => $resolved->kind->value,
            'scope' => $resolved->sourceScope->value,
            'version' => $resolved->version,
            'effective_from' => $resolved->effectiveFrom,
            'checksum' => $resolved->checksum(),
            'is_system_default' => $resolved->isSystemDefault(),
            'definition' => $resolved->definition,
            'updated_at' => $resolved->updatedAt,
        ];
    }
}
