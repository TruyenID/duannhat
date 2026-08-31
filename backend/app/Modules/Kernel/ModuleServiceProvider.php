<?php

declare(strict_types=1);

namespace App\Modules\Kernel;

use Illuminate\Support\ServiceProvider;

/**
 * Base class every module provider extends (#962 Phase 1, #1359).
 *
 * A module owns its own wiring: bindings, routes, migrations, listeners. The
 * application kernel does not know what any module contains — it only knows how
 * to ask one to register itself. That is the whole contract, and it is small on
 * purpose.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: relocate existing code into `app/Modules`.
 * Phase 0 + the paydown stages cut cross-module dependencies 3193 → 805 (−75%)
 * WITHOUT MOVING A SINGLE FILE — every gain came from declaring ownership
 * correctly in `config/modules.php` and letting Deptrac enforce it. Moving 2,657
 * classes would have produced the same number at enormous risk, so relocation
 * must now be justified per module against a measured benefit, not assumed as
 * the shape of the migration. See ../docs/explanation/module-boundaries.md
 * (umbrella repo) and ADR 0001 § 1b.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Module name — MUST match a key in `config/modules.php`.
     *
     * Same string as the ownership manifest so a module cannot exist at runtime
     * without existing to the boundary checker, and vice versa. Two registries
     * naming the same thing differently is how the old graph drifted.
     */
    abstract public function moduleName(): string;
}
