<?php

declare(strict_types=1);

/**
 * #1264 — `$fillable` is the list a developer consults to learn whether a field
 * exists. When it names a dropped column it does not merely fail to help: it
 * actively asserts something untrue, and the code written on that assertion dies
 * at the database.
 *
 * That is not hypothetical here. CancelOverdueTakeawayOrders' own docblock
 * records it: "the previous implementation ... wrote dropped
 * `payment_method`/`cancellation_reason` columns ... so it fataled on every run
 * and 16 overdue orders piled up" (#512). The job was fixed; the `$fillable`
 * entry that had described the column as writable was left behind, still
 * pointing at nothing.
 *
 * MySQL only. The suite runs on SQLite, where the schema is built from the same
 * migrations and the comparison is still meaningful — but a driver that reports
 * no columns at all would make this pass vacuously, so the count is asserted
 * before the result is trusted.
 */

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('names only real columns in $fillable', function () {
    $violations = [];
    $checked = 0;

    foreach (glob(base_path('app/Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        try {
            $model = new $class;
        } catch (Throwable) {
            continue;
        }

        $table = $model->getTable();
        if (! Schema::hasTable($table)) {
            continue;
        }

        $columns = array_flip(Schema::getColumnListing($table));

        foreach ($model->getFillable() as $attribute) {
            $checked++;

            if (isset($columns[$attribute])) {
                continue;
            }
            // A cast or an accessor can make a non-column mass-assignable on
            // purpose (an Attribute that writes several columns, for instance).
            if ($model->hasCast($attribute)) {
                continue;
            }
            if (method_exists($model, 'get'.Str::studly($attribute).'Attribute')) {
                continue;
            }
            if (method_exists($model, Str::camel($attribute))) {
                continue;
            }

            $violations[] = sprintf('%s $fillable[%s] — %s.%s does not exist', $class, $attribute, $table, $attribute);
        }
    }

    // A run that resolved no models would report zero violations and look
    // healthy, which is the shape of bug this file exists to catch.
    expect($checked)->toBeGreaterThan(500, 'almost no fillable attributes were read — the scan is broken, not the models');

    expect($violations)->toBe([], implode("\n  ", [
        'These are mass-assignable according to the model and absent from the table.',
        'Code written from this list dies at the database — see #512, where exactly that',
        'left 16 overdue orders unswept:',
        ...$violations,
    ]));
});
