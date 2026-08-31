<?php

namespace App\Services\Product;

use App\Models\Menu;
use App\Models\MenuSection;
use App\Services\Catalog\MenuSectionPivotWriter;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MenuSectionService
{
    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, brand_id?: string, search?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = MenuSection::query()
            ->withCount('menus');

        $query->when($filters['organization_id'] ?? null, function ($q, $orgId) {
            $q->where('organization_id', $orgId);
        });

        $query->when($filters['brand_id'] ?? null, function ($q, $brandId) {
            $q->where('brand_id', $brandId);
        });

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where('name', 'like', "%{$search}%");
        });

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): MenuSection
    {
        return MenuSection::with(['menus'])
            ->withCount('menuProducts')
            ->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): MenuSection
    {
        $data = $this->normalizeTranslations($data);
        $section = MenuSection::create($data);

        return $section->loadCount('menus');
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(MenuSection $section, array $data): MenuSection
    {
        $data = $this->normalizeTranslations($data);
        $expectedUpdatedAt = $data['updated_at'] ?? null;
        unset($data['updated_at']);

        return DB::transaction(function () use ($section, $data, $expectedUpdatedAt) {
            $section = MenuSection::query()->lockForUpdate()->findOrFail($section->getKey());
            if ($expectedUpdatedAt !== null
                && ! Carbon::parse($expectedUpdatedAt)->equalTo($section->updated_at)) {
                throw new ConflictHttpException('This menu section was changed by another user. Reload and try again.');
            }

            $section->update($data);

            return $section->loadCount(['menus', 'menuProducts']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTranslations(array $data): array
    {
        foreach (['ja', 'en', 'vi'] as $locale) {
            if (array_key_exists('name', $data[$locale] ?? [])) {
                $data["name:{$locale}"] = $data[$locale]['name'];
            }
            unset($data[$locale]);
        }

        return $data;
    }

    // =========================================================================
    //  Delete
    // =========================================================================

    public function delete(MenuSection $section): bool
    {
        return $section->delete();
    }

    // =========================================================================
    //  Menu attachment (N:N pivot)
    // =========================================================================

    /**
     * Sync sections for a given menu.
     *
     * @param  array<int, array{id: string, display_order?: int}>  $sections
     */
    public function syncMenuSections(Menu $menu, array $sections): void
    {
        $pivotData = [];

        foreach ($sections as $index => $section) {
            $id = is_string($section) ? $section : ($section['id'] ?? $section);
            $order = is_array($section) ? ($section['display_order'] ?? $index + 1) : $index + 1;
            $pivotData[$id] = ['display_order' => $order];
        }

        app(MenuSectionPivotWriter::class)->sync($menu, $pivotData);
    }
}
