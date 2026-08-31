<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait GeneratesSku
{
    /**
     * Generate a unique SKU with the configured prefix.
     *
     * @param  string  $column  Column to check uniqueness against (default: 'sku')
     * @param  array<string, mixed>  $additionalWhere  Extra where conditions (e.g., organization_id)
     */
    public function generateUniqueSku(string $column = 'sku', array $additionalWhere = []): string
    {
        $maxAttempts = 10;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = $this->skuPrefix.strtoupper(Str::random(8 - strlen($this->skuPrefix)));

            $query = $this->skuModel::withTrashed()->where($column, $code);

            foreach ($additionalWhere as $key => $value) {
                $query->where($key, $value);
            }

            if (! $query->exists()) {
                return $code;
            }
        }

        // Fallback with timestamp
        return $this->skuPrefix.strtoupper(Str::random(12 - strlen($this->skuPrefix)));
    }
}
