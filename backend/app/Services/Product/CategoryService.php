<?php

namespace App\Services\Product;

use App\Models\Category;
use App\Traits\GeneratesSku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    use GeneratesSku;

    protected string $skuPrefix = 'CA';

    protected string $skuModel = Category::class;

    /**
     * Priority order used when picking the top-level mirror value for a
     * translatable field. The first locale with a non-empty value wins.
     */
    private const TRANSLATABLE_PRIORITY = ['ja', 'en', 'vi'];

    /**
     * Translatable fields that mirror into a base column on `categories`.
     * Must stay in sync with the schema's translatable list.
     */
    private const TRANSLATABLE_FIELDS = ['name', 'description'];

    /**
     * @param  array{organization_id: string, search?: string, is_active?: bool, parent_id?: string, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Category::query()
            ->with('parent:id,name')
            ->withCount(['children', 'products']);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->whereTranslationLike('name', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        });

        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        $query->when(
            array_key_exists('parent_id', $filters),
            fn ($q) => $q->where('parent_id', $filters['parent_id'])
        );

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Category
    {
        return Category::with(['parent:id,name', 'children:id,name,sku,is_active,parent_id'])
            ->withCount(['children', 'products'])
            ->findOrFail($id);
    }

    public function create(array $data): Category
    {
        if (! empty($data['parent_id'])) {
            $parent = Category::find($data['parent_id']);
            if (! $parent
                || $parent->organization_id !== ($data['organization_id'] ?? null)
                || $parent->brand_id !== ($data['brand_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'parent_id' => __('Parent category must belong to the same brand.'),
                ]);
            }
        }

        $data = $this->deriveTranslatableTopLevels($data);

        return DB::transaction(function () use ($data) {
            if (empty($data['sku'])) {
                $data['sku'] = $this->generateUniqueSku(
                    additionalWhere: ['organization_id' => $data['organization_id']]
                );
            }

            if (empty($data['slug']) && ! empty($data['name'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            $id = $data['id'] ?? null;
            unset($data['id']);
            $category = new Category;
            $category->fill($data);
            if ($id !== null) {
                $category->forceFill(['id' => $id]);
            }
            $category->save();

            return $category
                ->load(['parent:id,name', 'translations'])
                ->loadCount(['children', 'products']);
        });
    }

    public function update(Category $category, array $data): Category
    {
        if (isset($data['parent_id']) && $data['parent_id'] === $category->id) {
            throw ValidationException::withMessages([
                'parent_id' => __('A category cannot be its own parent.'),
            ]);
        }

        if (isset($data['parent_id']) && $this->isDescendant($category, $data['parent_id'])) {
            throw ValidationException::withMessages([
                'parent_id' => __('Cannot set a descendant as parent (circular reference).'),
            ]);
        }

        if (isset($data['parent_id']) && ! $this->parentBelongsToSameOrganization($category, $data['parent_id'])) {
            throw ValidationException::withMessages([
                'parent_id' => __('Parent category must belong to the same brand.'),
            ]);
        }

        $data = $this->deriveTranslatableTopLevels($data);

        if (isset($data['name']) && ! isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);

        return $category
            ->load(['parent:id,name', 'translations'])
            ->loadCount(['children', 'products']);
    }

    /**
     * Fill top-level translatable mirror fields (name, description) from
     * the nested per-locale arrays when the top-level is missing or empty.
     * Priority order: ja → en → vi (first non-empty wins).
     *
     * Lets users save a category while only entering one or two locales;
     * the base column still gets a sensible value picked in a deterministic
     * order rather than whichever locale Eloquent happened to fill first.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function deriveTranslatableTopLevels(array $data): array
    {
        foreach (self::TRANSLATABLE_FIELDS as $field) {
            $topLevel = $data[$field] ?? null;
            if (is_string($topLevel) && trim($topLevel) !== '') {
                continue;
            }

            foreach (self::TRANSLATABLE_PRIORITY as $locale) {
                $value = $data[$locale][$field] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $data[$field] = $value;
                    break;
                }
            }
        }

        return $data;
    }

    public function delete(Category $category): bool
    {
        return $category->delete();
    }

    public function restore(Category $category): Category
    {
        $category->restore();

        return $category
            ->load(['parent:id,name', 'translations'])
            ->loadCount(['children', 'products']);
    }

    /**
     * @return array<int, array{id: string, name: string, sku: string|null, parent_id: string|null}>
     */
    public function lookup(string $organizationId, ?string $brandId = null): array
    {
        return Category::where('organization_id', $organizationId)
            ->when($brandId, fn ($query) => $query->where('brand_id', $brandId))
            ->where('is_active', true)
            ->get(['id', 'name', 'sku', 'parent_id'])
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'sku' => $c->sku,
                'parent_id' => $c->parent_id,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Check that $parentId belongs to the same organization as $category.
     */
    private function parentBelongsToSameOrganization(Category $category, string $parentId): bool
    {
        $parent = Category::find($parentId);

        return $parent !== null
            && $parent->organization_id === $category->organization_id
            && $parent->brand_id === $category->brand_id;
    }

    /**
     * Check if $targetId is a descendant of $category (prevents circular parent).
     */
    private function isDescendant(Category $category, string $targetId): bool
    {
        $children = Category::where('parent_id', $category->id)->pluck('id');

        if ($children->contains($targetId)) {
            return true;
        }

        foreach ($children as $childId) {
            $child = Category::find($childId);
            if ($child && $this->isDescendant($child, $targetId)) {
                return true;
            }
        }

        return false;
    }
}
