<?php

namespace App\Services\PeripheralDevice;

use App\Models\PeripheralDevice;
use App\Services\Till\Contracts\OrgTenderVocabulary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PeripheralDeviceService
{
    /**
     * plan-052 T1.5 / DESIGN §5 (#1166) — printers are GONE from this list.
     *
     * A printer used to be registrable in two unrelated places: here, and in
     * `printers`. Two registries for one physical machine means a shop can
     * configure the same printer twice with different addresses and neither
     * screen shows the other's row. `printers` is now the single door;
     * 周辺機器 is payment hardware and the terminals around it.
     */
    public const ALLOWED_TYPES = [
        'payment_terminal',
        'coin_changer',
        'pos',
        'workstation',
        'kiosk',
    ];

    /**
     * The three types retired by plan-052. Kept as a named constant because
     * the P-18 migration guard and the ops purge command both have to know
     * exactly what to look for — and because a reader of this file deserves to
     * see WHAT was removed, not just that something was.
     */
    public const RETIRED_PRINTER_TYPES = [
        'receipt_printer',
        'kitchen_printer',
        'bar_printer',
    ];

    /**
     * Types that connect over the LAN and therefore require a network address in
     * `metadata.host` (+ optional `metadata.port`). The workstation resolves this
     * address per request when driving the device (Verifone P400 card terminal,
     * Glory 釣銭機 cash changer), so registration must supply it.
     */
    public const NETWORK_TYPES = [
        'payment_terminal',
        'coin_changer',
    ];

    /**
     * @param  array{organization_id?: string, branch_id?: string, search?: string, type?: string, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = PeripheralDevice::query();

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('type', 'like', '%'.$filters['search'].'%');
            });
        }

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): PeripheralDevice
    {
        return PeripheralDevice::query()->findOrFail($id);
    }

    /**
     * Validation rules for the `metadata` field, given the (effective) device
     * type. LAN devices (P400 / 釣銭機) must carry a reachable address so the
     * workstation can drive them; other types accept any metadata array. Shared
     * by the shop (SSO) and workstation (device-token) FormRequests so both
     * registration paths enforce the same contract.
     *
     * #1156 — payment terminals / coin changers may also declare
     * `metadata.accepts`: the subset of the org's tender vocabulary
     * (`till_tender_types.tender_key`, org-level rows) this physical device
     * accepts under the shop's acquirer contract. Each key is validated
     * against the org's ACTIVE org-wide vocabulary when the caller supplies
     * `$organizationId` — an unknown or inactive key is a validation error,
     * because a 精算 manifest section anchored to a key the org cannot
     * reconcile would be noise at best and a silent mis-bucket at worst.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function metadataRulesFor(?string $type, ?string $organizationId = null): array
    {
        if (in_array($type, self::NETWORK_TYPES, true)) {
            $rules = [
                'metadata' => ['required', 'array'],
                'metadata.host' => ['required', 'string', 'max:255'],
                'metadata.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'metadata.accepts' => ['sometimes', 'nullable', 'array'],
                'metadata.accepts.*' => array_merge(
                    ['string', 'max:50', 'distinct'],
                    $organizationId !== null ? [self::tenderKeyExistsRule($organizationId)] : [],
                ),
            ];

            // #2422 — 釣銭機 only. On timeout the machine KEEPS the customer's
            // cash, so how long it waits is a per-shop operational decision, not
            // a constant. Absent/null = the workstation's 300s default.
            //
            // Bounds are deliberate, not decoration: the Glory API accepts
            // 0..86400 and treats **0 as "wait forever"**, which would leave a
            // machine holding cash with no terminal state for the POS to clear —
            // so the floor here is 30s, not 0. A shop wanting "effectively no
            // timeout" gets 86400 (24h), which still terminates.
            if ($type === 'coin_changer') {
                $rules['metadata.deposit_timeout_seconds'] = [
                    'sometimes', 'nullable', 'integer', 'min:30', 'max:86400',
                ];
            }

            return $rules;
        }

        return ['metadata' => ['nullable', 'array']];
    }

    /**
     * Closure rule: the value must be an existing ACTIVE org-level
     * (branch_id NULL) tender key of the given organization.
     */
    private static function tenderKeyExistsRule(string $organizationId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($organizationId): void {
            // #962 — `till_tender_types` thuộc Payments; hỏi qua cổng.
            $exists = app(OrgTenderVocabulary::class)
                ->hasActiveOrgKey($organizationId, (string) $value);

            if (! $exists) {
                $fail(__("The tender key ':key' is not part of this organization's tender vocabulary.", ['key' => (string) $value]));
            }
        };
    }

    public function create(array $data): PeripheralDevice
    {
        if (empty($data['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => __('Branch is required.'),
            ]);
        }

        $data = $this->prefillAcceptsFromTemplate($data);

        if (! empty($data['name']) && PeripheralDevice::query()
            ->where('branch_id', $data['branch_id'])
            ->where('name', $data['name'])
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => __('A peripheral device named ":name" already exists for this branch.', ['name' => $data['name']]),
            ]);
        }

        $device = new PeripheralDevice;
        // Honor a client-supplied id (workstation offline sync-UP) so an
        // offline-created row keeps its id across the sync. HasUuids only
        // generates one when the key is empty, so this wins; `id` stays out of
        // $fillable so it can't be mass-assigned on the shop (SSO) path.
        if (! empty($data['id'])) {
            $device->id = $data['id'];
        }
        $device->fill($data)->save();

        return $device;
    }

    /**
     * Create, or update-in-place when a row with the supplied id already exists
     * in the given branch. Used by the workstation sync-UP path so an
     * offline-created peripheral converges to one Cloud row on its client id
     * (idempotent replays never duplicate). Returns [device, wasCreated].
     *
     * @param  array<string, mixed>  $data  must include id, branch_id, organization_id
     * @return array{0: PeripheralDevice, 1: bool}
     */
    public function upsertForBranch(array $data): array
    {
        // Look the id up UNSCOPED. Scoping the lookup to the caller's branch made
        // a foreign id miss, fall through to create(), and collide on the primary
        // key — an unhandled QueryException (500) that the workstation classifies
        // as retryable, so the queued row would re-attempt the same doomed INSERT
        // forever. A 409 is non-retryable, so the row parks instead.
        $existing = ! empty($data['id'])
            ? PeripheralDevice::withTrashed()->find($data['id'])
            : null;

        if ($existing && (string) $existing->branch_id !== (string) ($data['branch_id'] ?? '')) {
            throw new ConflictHttpException('This peripheral id belongs to another branch.');
        }

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return [$this->update($existing, $data), false];
        }

        return [$this->create($data), true];
    }

    /**
     * #1156 — Template prefill for payment terminals.
     *
     * When a `payment_terminal` is registered WITHOUT `metadata.accepts` but
     * WITH a `metadata.model` matching a vendor template
     * (config/tender_templates.php via TenderTemplateService), the template's
     * tender keys are prefilled onto `metadata.accepts`. The prefill is
     * intersected with the organization's ACTIVE org-level tender vocabulary
     * so it always satisfies the same invariant an explicit `accepts` payload
     * is validated against. An explicit `accepts` (even an empty array — "this
     * terminal's brand list is managed by hand") always wins; update() never
     * prefills, so an operator clearing the list later is respected.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prefillAcceptsFromTemplate(array $data): array
    {
        if (($data['type'] ?? null) !== 'payment_terminal') {
            return $data;
        }

        $metadata = $data['metadata'] ?? null;
        if (! is_array($metadata) || array_key_exists('accepts', $metadata)) {
            return $data;
        }

        $model = $metadata['model'] ?? null;
        $template = app(TenderTemplateService::class)
            ->acceptsForModel(is_string($model) ? $model : null);

        if ($template === null) {
            return $data;
        }

        $organizationId = $data['organization_id'] ?? null;
        $orgKeys = is_string($organizationId) && $organizationId !== ''
            ? app(OrgTenderVocabulary::class)->activeOrgKeysAmong($organizationId, array_values($template))
            : [];

        // Keep the template's declared order (matches the vendor 日計 layout),
        // dropping keys the org's vocabulary does not carry.
        $data['metadata']['accepts'] = array_values(array_intersect($template, $orgKeys));

        return $data;
    }

    public function update(PeripheralDevice $device, array $data): PeripheralDevice
    {
        if (! empty($data['name']) && $data['name'] !== $device->name && PeripheralDevice::query()
            ->where('branch_id', $device->branch_id)
            ->where('name', $data['name'])
            ->whereKeyNot($device->getKey())
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => __('A peripheral device named ":name" already exists for this branch.', ['name' => $data['name']]),
            ]);
        }

        $device->update($data);

        return $device->fresh() ?? $device;
    }

    public function delete(PeripheralDevice $device): bool
    {
        return $device->delete();
    }

    public function restore(PeripheralDevice $device): PeripheralDevice
    {
        $device->restore();

        return $device->fresh() ?? $device;
    }
}
