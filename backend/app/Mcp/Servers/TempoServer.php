<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ShopListTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * MCP server co-located with tempo (MCP Service Contract) — reachable at
 * `/mcp` is protected by the same Platform bearer validation as the REST API.
 * Tools are EXPLICIT and curated — never auto-generated from REST.
 */
#[Name('tempo')]
#[Version('0.1.0')]
#[Instructions('Tempo (F&B/POS) tools. Every call is authorized by Platform permissions; missing ability is denied. Results are scoped to the principal\'s organizations — cross-tenant access is never possible.')]
class TempoServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ShopListTool::class,
    ];
}
