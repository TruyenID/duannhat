<?php

namespace App\Services\Topping;

use App\Models\ToppingGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ToppingGroupService
{
    public function __construct(
        private readonly ProductToppingGroupService $mutations,
    ) {}

    /**
     * @param  array{brand_id?: string, organization_id?: string, search?: string, is_active?: bool, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ToppingGroup::query()
            ->with('translations');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->whereTranslationLike('name', "%{$search}%");
        });

        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $allowed = ['created_at', 'sort_order', 'updated_at'];
        $column = in_array($column, $allowed) ? $column : 'created_at';
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): ToppingGroup
    {
        return ToppingGroup::with('translations')->findOrFail($id);
    }

    public function create(array $data): ToppingGroup
    {
        return $this->mutations->createGroup($data);
    }

    public function update(ToppingGroup $group, array $data): ToppingGroup
    {
        return $this->mutations->updateGroup($group, $data);
    }

    public function delete(ToppingGroup $group): bool
    {
        return $this->mutations->deleteGroup($group);
    }

    public function restore(ToppingGroup $group): ToppingGroup
    {
        return $this->mutations->restoreGroup($group);
    }

    /** @param  array<int, string>  $groupIds */
    public function reorder(string $brandId, array $groupIds): void
    {
        $this->mutations->reorderGroups($brandId, $groupIds);
    }

    /** @return array<int, array{id: string, name: string, items_count: int}> */
    public function lookup(string $brandId): array
    {
        $locale = app()->getLocale();

        return ToppingGroup::query()
            ->select([
                'topping_groups.id',
                DB::raw('COALESCE(NULLIF(tr_current.`name`, ""), NULLIF(tr_ja.`name`, ""), NULLIF(tr_en.`name`, ""), NULLIF(tr_vi.`name`, ""), NULLIF(topping_groups.`name`, "")) AS `name`'),
                DB::raw('(SELECT COUNT(*) FROM topping_group_items WHERE topping_group_items.topping_group_id = topping_groups.id AND topping_group_items.deleted_at IS NULL) AS items_count'),
            ])
            ->leftJoin('topping_group_translations as tr_current', function ($j) use ($locale) {
                $j->on('tr_current.topping_group_id', '=', 'topping_groups.id')
                    ->where('tr_current.locale', $locale);
            })
            ->leftJoin('topping_group_translations as tr_ja', function ($j) {
                $j->on('tr_ja.topping_group_id', '=', 'topping_groups.id')
                    ->where('tr_ja.locale', 'ja');
            })
            ->leftJoin('topping_group_translations as tr_en', function ($j) {
                $j->on('tr_en.topping_group_id', '=', 'topping_groups.id')
                    ->where('tr_en.locale', 'en');
            })
            ->leftJoin('topping_group_translations as tr_vi', function ($j) {
                $j->on('tr_vi.topping_group_id', '=', 'topping_groups.id')
                    ->where('tr_vi.locale', 'vi');
            })
            ->where('topping_groups.brand_id', $brandId)
            ->where('topping_groups.is_active', true)
            ->orderBy('topping_groups.sort_order')
            ->orderBy('topping_groups.created_at', 'desc')
            ->get()
            ->map(function ($row) {
                $attrs = $row->getAttributes();
                $attrs['items_count'] = (int) ($attrs['items_count'] ?? 0);

                return $attrs;
            })
            ->toArray();
    }
}
