<?php

declare(strict_types=1);

/**
 * The cross-repo gates must actually RUN in CI (#1293).
 *
 * Five suites read fixtures out of other parts of the tree and skip themselves
 * when the directory is absent — RendererPrimitivesParity, CatalogParity,
 * PullFieldContract (workstation/), Plan047AcceptanceAdminApiExtended
 * (web/admin/) and SchemaTypeVocabulary (schemas/). Skipping was right when
 * these were submodules and a partial checkout was normal.
 *
 * In CI it is not right, and the failure mode is the dangerous kind: a revoked
 * deploy key or a failed clone drops 37 tests and the run stays GREEN, carrying
 * only a `::warning::` that nobody reads in a passing build. That is not
 * hypothetical — the workflow's own comments record the 07-28 nightly losing
 * workstation-app exactly this way (thời còn submodule), and #1182 records the same suite reporting
 * 2 skipped on one runner and 39 on another.
 *
 * The workflow deliberately does NOT fail on a missing clone, and the reason is
 * sound: it did not want a missing checkout misreported as a parity failure.
 * This is the third option that argument leaves open — fail, but say plainly
 * that the checkout is what is missing. Nothing here changes how CI clones.
 *
 * What these gates protect is worth the noise: the offline-signing golden
 * fixture, the ESC/POS render primitives and the schema vocabulary are
 * cross-LANGUAGE contracts. Drift between the PHP and Go sides has no other
 * detector — that is the stated reason both repos gate on one shared fixture.
 */
/*
 * #2333 — ba khoá này TỪNG là tên thư mục submodule anh em. Monorepo (#2306)
 * đưa chúng vào cây với tên khác, và bài này không đi theo: nó sẽ đòi
 * `../workstation-app` và `../admin-web`, hai đường dẫn không còn tồn tại, nên
 * trên GitHub Actions nó tố "checkout hỏng" trong khi checkout hoàn toàn đúng.
 *
 * Bài vẫn giữ giá trị sau khi gộp: cây rỗng vì lý do khác (sparse checkout,
 * `actions/checkout` với `filter`, một lượt gộp làm mất thư mục) thì 37 test
 * parity vẫn im lặng biến mất — và đó vẫn là kiểu hỏng nguy hiểm nhất.
 */
$requiredForCrossRepoGates = [
    'workstation' => 'RendererPrimitivesParity, CatalogParity, PullFieldContract (37 tests)',
    'web/admin' => 'Plan047AcceptanceAdminApiExtended coverage registry',
    'schemas' => 'SchemaTypeVocabulary',
];

it('has every sibling repo the cross-repo gates read from', function () use ($requiredForCrossRepoGates) {
    if (getenv('GITHUB_ACTIONS') !== 'true') {
        // A partial checkout is ordinary locally; the suites' own skips cover it.
        test()->markTestSkipped('not running in CI — sibling checkouts are optional here');
    }

    $missing = [];
    foreach ($requiredForCrossRepoGates as $dir => $whatItGuards) {
        $path = base_path('../'.$dir);
        // A submodule that failed to clone leaves an EMPTY directory, not an
        // absent one, so is_dir() alone would pass while every fixture read
        // inside it fails.
        $populated = is_dir($path) && (glob($path.'/*') !== [] || glob($path.'/.??*') !== []);
        if (! $populated) {
            $missing[] = "{$dir}/ is missing or empty — silently disables: {$whatItGuards}";
        }
    }

    expect($missing)->toBe([], implode("\n  ", [
        'A sibling repo the cross-repo gates read from did not reach this runner.',
        'This is a CHECKOUT failure, not a parity failure — do not go looking for',
        'drift between the repos until the clone is fixed.',
        '',
        'Likely cause: a read-only deploy key was revoked or rotated. Check with',
        '  gh repo deploy-key list --repo godx-jp/godx-tempo-<sub>',
        'and the "Init sibling submodules" step in backend-tests.yml, which warns',
        'but deliberately does not fail — this test is what turns that warning',
        'into a signal.',
        '',
        ...$missing,
    ]));
});
