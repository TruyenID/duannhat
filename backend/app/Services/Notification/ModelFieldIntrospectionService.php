<?php

namespace App\Services\Notification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Plan-023 M7 T7.9 — model introspection for the rule editor's
 * FieldPicker.
 *
 * Returns the columns + relation aliases an admin can target in a
 * rule condition. Source:
 *   - $fillable + $casts on the model (the admin-facing schema)
 *   - BelongsTo / HasOne / HasMany relations declared on the model
 *     (limited to depth 1 — deeper traversal is the validator's
 *     MAX_FIELD_DEPTH cap)
 *
 * Morph aliases are whitelisted via `Relation::morphMap()`. Asking
 * for an unmapped class returns null so the controller can 404.
 */
final class ModelFieldIntrospectionService
{
    /**
     * @return array{model: string, fields: array<int, array{name: string, type: string, ops_supported: array<int, string>}>, relations: array<int, array{name: string, target_alias: string, type: string}>}|null
     */
    public function describe(string $morphAlias): ?array
    {
        $class = Relation::getMorphedModel($morphAlias);
        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $instance */
        $instance = new $class;

        return [
            'model' => $morphAlias,
            'fields' => $this->fieldsFor($instance),
            'relations' => $this->relationsFor($instance),
        ];
    }

    /**
     * @return array<int, array{name: string, type: string, ops_supported: array<int, string>}>
     */
    private function fieldsFor(Model $instance): array
    {
        $casts = $instance->getCasts();
        $fillable = $instance->getFillable();

        $columns = array_unique(array_merge(array_keys($casts), $fillable));
        sort($columns);

        return array_map(function (string $column) use ($casts) {
            $type = $casts[$column] ?? 'string';

            return [
                'name' => $column,
                'type' => $type,
                'ops_supported' => $this->opsFor($type),
            ];
        }, $columns);
    }

    /**
     * @return array<int, array{name: string, target_alias: string, type: string}>
     */
    private function relationsFor(Model $instance): array
    {
        $relations = [];

        $reflection = new \ReflectionClass($instance);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === Model::class) {
                continue;
            }
            if ($method->getNumberOfParameters() !== 0) {
                continue;
            }
            if (! $this->methodLooksLikeRelation($method)) {
                continue;
            }

            try {
                /** @var Relation $relation */
                $relation = $instance->{$method->getName()}();
            } catch (\Throwable) {
                continue;
            }

            if (! ($relation instanceof BelongsTo || $relation instanceof HasOne || $relation instanceof HasMany)) {
                continue;
            }

            $targetClass = $relation->getRelated()::class;
            $targetAlias = $this->aliasFor($targetClass);
            if ($targetAlias === null) {
                continue;
            }

            $relations[] = [
                'name' => $method->getName(),
                'target_alias' => $targetAlias,
                'type' => match (true) {
                    $relation instanceof BelongsTo => 'belongsTo',
                    $relation instanceof HasOne => 'hasOne',
                    $relation instanceof HasMany => 'hasMany',
                    default => 'unknown',
                },
            ];
        }

        usort($relations, fn ($a, $b) => $a['name'] <=> $b['name']);

        return $relations;
    }

    private function methodLooksLikeRelation(\ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();
        if (! $returnType instanceof \ReflectionNamedType) {
            return false;
        }
        $name = $returnType->getName();

        return is_subclass_of($name, Relation::class) || $name === Relation::class;
    }

    private function aliasFor(string $class): ?string
    {
        // Reverse lookup the morph map. If the class is registered,
        // morphMap() returns the alias.
        foreach (Relation::morphMap() as $alias => $mapped) {
            if ($mapped === $class) {
                return is_string($alias) ? $alias : null;
            }
        }

        return null;
    }

    /**
     * Per-type op list — same source the FieldPicker uses to populate
     * the op dropdown once a field is picked. Conservative defaults;
     * `string` types skip numeric comparators.
     *
     * @return array<int, string>
     */
    private function opsFor(string $type): array
    {
        $base = ['=', '!=', 'is_null', 'is_not_null', 'changed', 'changed_to', 'changed_from'];
        $numeric = ['>', '<', '>=', '<='];
        $text = ['matches'];
        $list = ['in', 'not_in'];

        return match ($type) {
            'int', 'integer', 'float', 'double', 'decimal' => array_values(array_merge($base, $numeric, $list)),
            'string', 'text' => array_values(array_merge($base, $text, $list)),
            'datetime', 'date', 'timestamp' => array_values(array_merge($base, $numeric)),
            'bool', 'boolean' => array_values($base),
            'array', 'json', 'collection' => array_values(array_merge($base, $list)),
            default => array_values(array_merge($base, $list)),
        };
    }
}
