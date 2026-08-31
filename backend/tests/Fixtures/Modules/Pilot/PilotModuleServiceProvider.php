<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\Pilot;

use App\Modules\Kernel\ModuleServiceProvider;

/** Module rỗng dùng để chứng minh exit criteria của #962 Phase 1 — nó BOOT được. */
final class PilotModuleServiceProvider extends ModuleServiceProvider
{
    public bool $registered = false;

    public function register(): void
    {
        $this->registered = true;
        $this->app->instance('pilot.module.marker', 'booted');
    }

    public function moduleName(): string
    {
        return 'Pilot';
    }
}
