<?php

declare(strict_types=1);

namespace App\Modules\Kernel;

use Illuminate\Contracts\Foundation\Application;

/**
 * Boots the module providers that exist, and refuses ones that lie (#1359).
 *
 * Discovery is by CONVENTION over the ownership manifest rather than a second
 * hand-written list: for each module in `config/modules.php`, if the class
 * `App\Modules\<Name>\<Name>ModuleServiceProvider` exists, it is registered.
 *
 * That ordering matters. A module can be created without touching the kernel,
 * and a module that is deleted stops booting on its own — but nothing can boot
 * that the boundary checker does not already know about, because the manifest
 * is the same file Deptrac is generated from.
 */
final class ModuleRegistry
{
    /** @param array<string, mixed> $manifest `config('modules')` */
    public function __construct(
        private readonly Application $app,
        private readonly array $manifest,
    ) {}

    /**
     * @return list<string> the module names that actually booted
     */
    public function boot(): array
    {
        $booted = [];

        foreach (array_keys($this->manifest['modules'] ?? []) as $module) {
            $class = $this->providerClass($module);
            if (! class_exists($class)) {
                continue;   // module chưa có mã — không phải lỗi, chỉ là chưa tới lượt
            }

            $provider = $this->app->register($class);

            if (! $provider instanceof ModuleServiceProvider) {
                throw new \LogicException("{$class} phải kế thừa ".ModuleServiceProvider::class);
            }

            if ($provider->moduleName() !== $module) {
                throw new \LogicException(
                    "{$class}::moduleName() trả '{$provider->moduleName()}' nhưng nó nằm ở module '{$module}' —"
                    .' tên phải khớp config/modules.php, nếu không ranh giới runtime và ranh giới đo được sẽ lệch.'
                );
            }

            $booted[] = $module;
        }

        return $booted;
    }

    public function providerClass(string $module): string
    {
        return "App\\Modules\\{$module}\\{$module}ModuleServiceProvider";
    }
}
