<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Mounts the co-located MCP routes (routes/mcp.php) — kept out of api.php so
 * the REST surface and the MCP surface stay independently reviewable.
 */
class McpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/mcp.php'));
    }
}
