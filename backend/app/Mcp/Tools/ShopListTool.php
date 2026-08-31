<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Shop\UserShopDirectoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Thin adapter over {@see UserShopDirectoryService} — the same use-case
 * behind GET /api/v1/me/shops. Authorization runs through the Gate
 * (`shop.view`), i.e. the godx PDP in delegate mode and local IAM otherwise —
 * ONE ability manifest for REST + MCP.
 *
 * 🔴 IDOR guard (MCP Service Contract §7): the org scope comes from the
 * principal's IAM assignments — a client-supplied tenant/org is NEVER trusted.
 */
#[Name('shop_list')]
#[Description('List the shops (branches) the authenticated principal can access, scoped to their organizations. Requires the shop.view ability. Optional search filters by name or slug.')]
class ShopListTool extends Tool
{
    public function __construct(private readonly UserShopDirectoryService $shops) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        if ($user === null || ! $user->can('shop.view')) {
            return Response::error('Forbidden: the shop.view ability is required.');
        }

        return Response::json([
            'shops' => $this->shops->listFor(
                $user,
                $validated['search'] ?? null,
                (int) ($validated['limit'] ?? 25),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Optional keyword — filters shops by name or slug.'),
            'limit' => $schema->integer()->description('Max results (1–100, default 25).'),
        ];
    }
}
