<?php

/**
 * #1243 — an `exists:` / `unique:` rule naming a table or column that is not
 * there is invisible until a user hits that exact field, and then it is a 500,
 * not a validation message.
 *
 * Nothing in PHP checks these strings: they are resolved at request time, so a
 * typo or a column rename survives every static check, every deploy, and every
 * test that does not happen to post that field.
 *
 * Today 87 of 88 rules are sound. The one exception is inside a dead generated
 * module and no route reaches it (see the exclusion below). This test exists to
 * keep the next one from being written unnoticed.
 *
 * Known limit, stated so nobody reads more into a green run than it earns: this
 * scans STRING LITERALS. A rule assembled at runtime — a table name from a
 * variable, a rule built by a helper — is not covered. It catches the common
 * shape, not every shape.
 */
use Illuminate\Support\Facades\Schema;

/**
 * Rules that legitimately cannot resolve. Each needs a reason.
 */
const UNRESOLVABLE_RULES = [
    // app/Omnify/Modules/MenuItem/Requests/MenuItem{Store,Update}RequestBase.php
    // `menu_items` is the WORKSTATION's SQLite table; the Cloud module for it is
    // an orphan (no migration, no schema YAML) and no route reaches these
    // request classes. Tracked with the other phantom artefacts in #1216 —
    // the fix is to delete the module, not to repair the rule.
    'exists:menu_items,id' => 'phantom table in a dead generated module, unreachable by any route',
];

it('every exists/unique rule names a table and column that exist', function () {
    $rules = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // 'exists:table,column' / 'unique:table,column'
        preg_match_all("/'(exists|unique):([a-z_]+)(?:,([a-z_]+))?/", $source, $inline, PREG_SET_ORDER);

        // Rule::exists('table', 'column') / Rule::unique(...)
        preg_match_all("/Rule::(exists|unique)\(\s*'([a-z_]+)'\s*(?:,\s*'([a-z_]+)')?/", $source, $fluent, PREG_SET_ORDER);

        foreach ([...$inline, ...$fluent] as $m) {
            $key = "{$m[1]}:{$m[2]}".(isset($m[3]) && $m[3] !== '' ? ",{$m[3]}" : '');
            // Keep the first file that used it — enough to find it again.
            $rules[$key] ??= str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($rules)->not->toBeEmpty('the rule scanner found nothing, which means it is broken, not that the code is clean');

    $broken = [];
    $staleExclusions = [];

    foreach ($rules as $rule => $where) {
        [$kind, $rest] = explode(':', $rule, 2);
        $parts = explode(',', $rest);
        $table = $parts[0];
        $column = $parts[1] ?? null;

        $fails = ! Schema::hasTable($table)
            || ($column !== null && ! Schema::hasColumn($table, $column));

        if ($fails && ! array_key_exists($rule, UNRESOLVABLE_RULES)) {
            $broken[] = "{$rule}  ({$where})";
        }

        if (! $fails && array_key_exists($rule, UNRESOLVABLE_RULES)) {
            $staleExclusions[] = $rule;
        }
    }

    expect($broken)->toBe([], sprintf(
        "These validation rules name a table or column that does not exist:\n  %s\n".
        'At request time each is a 500, not a validation error.',
        implode("\n  ", $broken),
    ));

    expect($staleExclusions)->toBe([], sprintf(
        "These rules resolve now — remove them from UNRESOLVABLE_RULES:\n  %s",
        implode("\n  ", $staleExclusions),
    ));
});
