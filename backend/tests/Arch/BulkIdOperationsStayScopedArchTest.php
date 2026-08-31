<?php

declare(strict_types=1);

/**
 * A bulk endpoint takes a list of ids from the caller. If it authorizes at CLASS
 * level — `$this->authorize('delete', Branch::class)` — the policy never sees a
 * row, so it can only answer "may this user delete shops at all", not "may they
 * delete THESE shops". The scoping has to happen in the query, and if it does
 * not, an HQ admin of one organization can pass ids belonging to another and the
 * request succeeds.
 *
 * Every such method in the tree is scoped today; this keeps it that way. Bulk
 * handlers are written by copying the neighbouring one, so the day someone
 * copies a scoped body and edits the query is the day this matters.
 *
 * Instance-level authorize (`authorize('delete', $shop)`) is deliberately not
 * flagged: there the policy receives the row and can make the ownership call
 * itself, which is the stronger arrangement.
 */
it('scopes every class-level-authorized bulk id operation to the caller org or brand', function () {
    $scopeTokens = '/organization_id|console_organization|brand_id|->where\(\'brand|forBrand|scopeBrand|whereHas\(/';
    $classLevelAuthorize = '/authorize\(\'[a-zA-Z]+\',\s*\w+::class\)/';

    $violations = [];
    $directory = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app/Http/Controllers')),
    );

    foreach ($directory as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $lines = file($file->getPathname()) ?: [];
        $starts = [];
        foreach ($lines as $number => $line) {
            if (preg_match('/^\s*public function (\w+)\(/', $line, $m) === 1) {
                $starts[] = [$number, $m[1]];
            }
        }

        foreach ($starts as $i => [$start, $name]) {
            $end = $starts[$i + 1][0] ?? count($lines);
            $body = implode('', array_slice($lines, $start, $end - $start));

            // A bulk id handler: validates an `ids` array off the request.
            if (! str_contains($body, "'ids'") || ! str_contains($body, 'validate')) {
                continue;
            }
            if (preg_match($classLevelAuthorize, $body) !== 1) {
                continue;
            }
            if (preg_match($scopeTokens, $body) === 1) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d %s()',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                $start + 1,
                $name,
            );
        }
    }

    expect($violations)->toBe([], implode("\n  ", [
        'These take caller-supplied ids, authorize at class level (so the policy never sees a row),',
        'and do not scope the lookup — ids from another organization would be accepted:',
        ...$violations,
    ]));
});
