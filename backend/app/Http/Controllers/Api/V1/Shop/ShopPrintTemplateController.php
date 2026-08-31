<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\PrintTemplate;
use App\Services\Print\BlockCatalog;
use App\Services\Print\DefinitionMerger;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Renderer\PreviewRequest;
use App\Services\Print\TemplateResolver;
use App\Services\Print\TemplateVersionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * plan-053 M2 (#1171) — the SHOP override surface (TASKS T2.3).
 *
 *   GET  /shops/{shopSlug}/print-templates            per-kind state + allow-list
 *   GET  /shops/{shopSlug}/print-templates/{kind}     resolved slip + what may be edited
 *   GET  /shops/{shopSlug}/print-templates/{kind}/preview   SVG preview (TR-32, M5)
 *   POST /shops/{shopSlug}/print-templates/{kind}/draft
 *   POST /shops/{shopSlug}/print-templates/{kind}/publish
 *
 * A shop may only touch what the brand delegated through `shop_editable`
 * (DESIGN §1). The response carries that allow-list so the UI can DISABLE the
 * other fields with a reason (TR-03 "defense in depth"), but the boundary
 * itself is enforced twice more: the publish validator rejects out-of-list
 * edits (422), and the resolver filters them out anyway (TR-04) — so a brand
 * narrowing the list silences an existing override without destroying it.
 *
 * TR-37: `shop.manage`, which a cashier does not hold — the surface is
 * invisible to them, not merely read-only.
 */
class ShopPrintTemplateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TemplateVersionService $versions,
        private readonly TemplateResolver $resolver,
        private readonly BlockCatalog $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manageShopOverride', PrintTemplate::class);
        [$brand, $shop] = $this->context($request);

        $data = [];
        foreach (PrintTemplateKind::cases() as $kind) {
            $brandLive = $this->versions->currentPublished($kind, PrintTemplateScope::Brand, $brand, null);
            $shopLive = $this->versions->currentPublished($kind, PrintTemplateScope::Shop, $brand, $shop);
            $resolved = $this->resolver->forBranch($kind, (string) $shop->id);

            $data[] = [
                'kind' => $kind->value,
                'shop_editable' => array_values((array) ($brandLive?->shop_editable ?? [])),
                // Empty allow-list = the brand locked this slip completely.
                'is_locked_by_brand' => ($brandLive?->shop_editable ?? []) === [],
                'brand_version' => $brandLive?->version,
                'override_version' => $shopLive?->version,
                // TR-02 — "this shop overrides 3 things", spelled out.
                'overridden_paths' => $resolved->shopOverriddenPaths,
                'effective_scope' => $resolved->sourceScope->value,
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $kind): JsonResponse
    {
        $this->authorize('manageShopOverride', PrintTemplate::class);
        [$brand, $shop] = $this->context($request);
        $kindEnum = $this->kind($kind);

        $brandLive = $this->versions->currentPublished($kindEnum, PrintTemplateScope::Brand, $brand, null);
        $draft = $this->versions->currentDraft($kindEnum, PrintTemplateScope::Shop, $brand, $shop);
        $resolved = $this->resolver->forBranch($kindEnum, (string) $shop->id);

        return response()->json([
            'data' => [
                'kind' => $kindEnum->value,
                'shop_editable' => array_values((array) ($brandLive?->shop_editable ?? [])),
                /*
                 * #2043 — the block catalog, on the SHOP surface.
                 *
                 * It was HQ-only (`GET /hq/{brand}/print-templates/{kind}`),
                 * which a shop manager holds no permission for, so admin-web
                 * kept a hand-copied mirror of `config/print_blocks.php` just
                 * for this screen. That mirror drifted four times, silently —
                 * a block with no switch, a param field with no checkbox.
                 *
                 * It rides this endpoint rather than a new route on purpose:
                 * the catalog is part of "what may I edit on this slip", the
                 * exact question this read already answers, and it needs no
                 * permission slug beyond the `shop.manage` already checked
                 * above (a new slug would have to be seeded into every live
                 * organization before it granted anything — plan-053 TR-37).
                 *
                 * Reading it is NOT the boundary: publish still validates
                 * against the same catalog server-side.
                 */
                'catalog' => $this->catalog->catalogFor($kindEnum),
                'resolved' => $resolved->toArray(),
                'draft' => $draft ? [
                    'id' => $draft->id,
                    'version' => $draft->version,
                    'definition' => $draft->definition,
                    'notes' => $draft->notes,
                    'updated_at' => $draft->updated_at?->toIso8601String(),
                    'lock_token' => TemplateVersionService::lockToken($draft),
                ] : null,
            ],
        ]);
    }

    public function saveDraft(Request $request, string $kind): JsonResponse
    {
        $this->authorize('manageShopOverride', PrintTemplate::class);
        [$brand, $shop] = $this->context($request);

        $data = $request->validate([
            'definition' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lock_token' => ['nullable', 'string'],
        ]);

        $draft = $this->versions->saveDraft(
            $this->kind($kind),
            PrintTemplateScope::Shop,
            $brand,
            $shop,
            $data['definition'],
            null,
            $data['notes'] ?? null,
            $data['lock_token'] ?? null,
            (string) $request->user()?->id,
        );

        return response()->json([
            'data' => [
                'id' => $draft->id,
                'version' => $draft->version,
                'status' => $draft->status->value,
                'definition' => $draft->definition,
                'updated_at' => $draft->updated_at?->toIso8601String(),
                'lock_token' => TemplateVersionService::lockToken($draft),
            ],
        ]);
    }

    public function publish(Request $request, string $kind): JsonResponse
    {
        $this->authorize('manageShopOverride', PrintTemplate::class);
        [$brand, $shop] = $this->context($request);

        $data = $request->validate([
            'effective_from' => ['nullable', 'string', 'max:25'],
            'notes' => ['nullable', 'string', 'max:500'],
            'parent_version_id' => ['nullable', 'string'],
        ]);

        $published = $this->versions->publish(
            $this->kind($kind),
            PrintTemplateScope::Shop,
            $brand,
            $shop,
            $data['effective_from'] ?? null,
            $data['notes'] ?? null,
            (string) $request->user()?->id,
            $data['parent_version_id'] ?? null,
        );

        return response()->json([
            'data' => [
                'id' => $published->id,
                'version' => $published->version,
                'status' => $published->status->value,
                'effective_from' => $published->effective_from,
                'definition' => $published->definition,
            ],
        ], 201);
    }

    /**
     * plan-053 M5 (#1171) TR-32 — SVG preview of the slip THIS SHOP prints.
     *
     * The shop-side preview deliberately renders the RESOLVED definition —
     * system → brand → shop already merged, with the brand's allow-list
     * applied — rather than the shop's override in isolation. An override is a
     * partial overlay; shown alone it is a handful of disconnected fields, and
     * the manager's actual question is "what comes out of my printer".
     *
     * Defaults to the draft overlay so a shop can see a change before
     * publishing it; `?source=published` shows what is currently in force.
     *
     * POSTing a `definition` previews the editor's UNSAVED state. The shop
     * editor holds the whole RESOLVED slip, not an overlay, so what arrives is
     * put through the SAME allow-list filter + merge that publish uses: a
     * preview must not show a shop an edit its own publish would strip.
     */
    public function preview(Request $request, string $kind): Response
    {
        $this->authorize('manageShopOverride', PrintTemplate::class);
        [$brand, $shop] = $this->context($request);
        $kindEnum = $this->kind($kind);
        $preview = PreviewRequest::fromRequest($request);

        $resolved = $this->resolver->forBranch($kindEnum, (string) $shop->id);
        $definition = $resolved->definition;
        $merger = app(DefinitionMerger::class);

        if ($preview->definition !== null) {
            $brandLive = $this->versions->currentPublished($kindEnum, PrintTemplateScope::Brand, $brand, null);
            $allowed = array_values((array) ($brandLive?->shop_editable ?? []));
            $definition = $merger->merge(
                $definition,
                $merger->filterToAllowList($preview->definition, $allowed),
            );
        } elseif ($preview->useDraft) {
            $draft = $this->versions->currentDraft($kindEnum, PrintTemplateScope::Shop, $brand, $shop);
            if ($draft !== null) {
                // Merge the draft the same way the resolver merges a published
                // override, so the preview cannot show a shop something its
                // publish would not actually produce.
                $definition = $merger->merge($definition, (array) $draft->definition);
            }
        }

        $svg = $preview->svg($definition, $kindEnum);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: Brand, 1: Branch} */
    private function context(Request $request): array
    {
        $shop = $request->attributes->get('shop');
        $brandId = $request->attributes->get('brand_id');

        if (! $shop instanceof Branch || ! $brandId) {
            abort(400, 'Shop context not resolved.');
        }

        $brand = Brand::find($brandId);
        if (! $brand instanceof Brand) {
            abort(400, 'This shop is not attached to a brand.');
        }

        return [$brand, $shop];
    }

    private function kind(string $kind): PrintTemplateKind
    {
        if (! $this->catalog->hasKind($kind)) {
            abort(response()->json([
                'message' => "Unknown print template kind [{$kind}].",
                'code' => 'PRINT_TEMPLATE_KIND_UNKNOWN',
            ], 422));
        }

        return PrintTemplateKind::from($kind);
    }
}
