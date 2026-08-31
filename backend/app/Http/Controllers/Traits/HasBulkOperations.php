<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait HasBulkOperations
{
    abstract protected function getModelClass(): string;

    /**
     * Override in subclasses to add extra scope filters to bulk delete queries.
     * For example, ToppingGroupController adds brand_id to prevent cross-brand deletes.
     *
     * @return array<string, mixed>
     */
    protected function getBulkDeleteExtraScope(): array
    {
        return [];
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $modelClass = $this->getModelClass();
        $orgId = $this->getOrganizationId();
        $extraScope = $this->getBulkDeleteExtraScope();
        $deleted = 0;
        $errors = [];

        foreach ($request->input('ids') as $id) {
            $model = $modelClass::where('organization_id', $orgId)
                ->when(! empty($extraScope), fn ($q) => $q->where($extraScope))
                ->find($id);

            if (! $model) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->authorize('delete', $model);
                $model->delete();
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $id,
                    'name' => $model->name ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "{$deleted} items deleted.",
            'deleted' => $deleted,
            'errors' => $errors,
        ]);
    }
}
