<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Kernel\ModuleServiceProvider;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Infrastructure\ServiceNotificationDispatcher;

/**
 * The first module to actually contain something (#1360, #962 Phase 2).
 *
 * Notifications was chosen by measurement, not by the epic's guess. Re-measured
 * with Deptrac after the six paydown stages, it carries 16 inbound and 13
 * outbound cross-module dependencies — the next-lowest module has 44, and the
 * highest has 460. It is also almost purely an event EMITTER, so its inbound
 * edges are the kind a published API can absorb.
 *
 * The module owns its own wiring. Nothing outside it registers these bindings,
 * and `ModuleRegistry` finds this class by convention from `config/modules.php`
 * — no kernel edit was needed to bring the module online.
 */
final class NotificationsModuleServiceProvider extends ModuleServiceProvider
{
    public function moduleName(): string
    {
        return 'Notifications';
    }

    public function register(): void
    {
        $this->app->bind(NotificationDispatcher::class, ServiceNotificationDispatcher::class);
    }
}
