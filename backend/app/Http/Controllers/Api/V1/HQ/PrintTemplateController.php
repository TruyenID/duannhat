<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Brand;
use App\Models\PrintTemplate;
use App\Policies\PrintTemplatePolicy;
use App\Services\Compliance\ComplianceProfileResolver;
use App\Services\Print\BlockCatalog;
use App\Services\Print\DefinitionDiff;
use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\Enums\PrintTemplateScope;
use App\Services\Print\Renderer\PreviewRequest;
use App\Services\Print\SystemTemplateDefaults;
use App\Services\Print\TemplateVersionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * plan-053 M2 (#1171) — the HQ (brand-layer) template surface, TASKS T2.2.
 *
 *   GET    /hq/{brandSlug}/print-templates                      catalog + state per kind
 *   GET    /hq/{brandSlug}/print-templates/{kind}                draft + live + history
 *   POST   /hq/{brandSlug}/print-templates/{kind}/draft          upsert the draft (TR-09 lock)
 *   PATCH  /hq/{brandSlug}/print-templates/{kind}/versions/{v}   edit — 409 unless draft (TR-08)
 *   POST   /hq/{brandSlug}/print-templates/{kind}/publish        validate + publish (TR-10 rebase)
 *   POST   .../versions/{v}/retire                               out of service for new prints
 *   POST   .../versions/{v}/rollback                             republish an old definition
 *   GET    .../history                                           who/when/notes (TR-31)
 *   GET    .../diff?from=&to=                                    version diff (TR-31)
 *   GET    .../preview?locale=&paper=&source=                    SVG preview (TR-32, M5)
 *
 * Authorization is TR-37 via {@see PrintTemplatePolicy}: reading
 * needs `menu.manage`, every write needs `catalog.approve` (HQ roles only, so
 * a shop-manager gets 403 here and works on the shop surface instead).
 */
class PrintTemplateController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly TemplateVersionService $versions,
        private readonly SystemTemplateDefaults $defaults,
        private readonly BlockCatalog $catalog,
        private readonly DefinitionDiff $diff,
        private readonly ComplianceProfileResolver $compliance,
    ) {}

    /** Every kind with its current brand-layer state (admin list screen). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PrintTemplate::class);
        $brand = $this->brand($request);

        // #1445 — chứng từ đi theo QUỐC GIA NƠI SHOP TỒN TẠI. Hoá đơn GTGT và hoá
        // đơn đỏ là chứng từ luật định Việt Nam; một brand ở Nhật không được phát
        // chúng, và cũng không nhận bản dịch của chúng — nó nhận chứng từ Nhật khi
        // kind đó có (適格簡易請求書, plan riêng).
        //
        // Nguồn quốc gia là `ComplianceProfileResolver`, KHÔNG phải một cách đọc
        // thứ hai của `organizations.operating_country`: hai đường đọc là hai lần
        // cơ hội lệch nhau. Resolver đã fail-safe về JP cho org chưa mirror quốc
        // gia (#1153) — nên org đó cũng mất hai kind này ở đây, đúng bằng posture
        // mà nó đang nhận ở mọi chỗ khác trong hệ thống.
        $country = $this->compliance->forOrganization($brand->console_organization_id)->country();

        $data = [];
        foreach (PrintTemplateKind::availableFor($country) as $kind) {
            $live = $this->versions->currentPublished($kind, PrintTemplateScope::Brand, $brand, null);
            $draft = $this->versions->currentDraft($kind, PrintTemplateScope::Brand, $brand, null);

            $data[] = [
                'kind' => $kind->value,
                // TR-01 — admin shows "using the system template" when the
                // brand has published nothing.
                'is_system_default' => $live === null,
                'published_version' => $live?->version,
                'published_at' => $live?->published_at?->toIso8601String(),
                'effective_from' => $live?->effective_from,
                'has_draft' => $draft !== null,
                'shop_editable' => array_values((array) ($live?->shop_editable ?? [])),
                'required_blocks' => $this->catalog->requiredBlocks($kind),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /** Full state of one kind: the block catalog, the system default, the draft and the live version. */
    public function show(Request $request, string $kind): JsonResponse
    {
        $this->authorize('viewAny', PrintTemplate::class);
        $brand = $this->brand($request);
        $kindEnum = $this->kind($kind);

        $live = $this->versions->currentPublished($kindEnum, PrintTemplateScope::Brand, $brand, null);
        $draft = $this->versions->currentDraft($kindEnum, PrintTemplateScope::Brand, $brand, null);

        return response()->json([
            'data' => [
                'kind' => $kindEnum->value,
                // #2043 — one assembly, shared with the shop surface, so the
                // two cannot describe the same catalog differently.
                'catalog' => $this->catalog->catalogFor($kindEnum),
                'system_default' => $this->defaults->forKind($kindEnum),
                'published' => $live ? $this->version($live) : null,
                'draft' => $draft ? $this->version($draft) : null,
            ],
        ]);
    }

    /** Upsert the single draft of this kind (DESIGN §2). */
    public function saveDraft(Request $request, string $kind): JsonResponse
    {
        $this->authorize('manageBrand', PrintTemplate::class);
        $brand = $this->brand($request);
        $kindEnum = $this->kind($kind);

        $data = $request->validate([
            'definition' => ['required', 'array'],
            'shop_editable' => ['sometimes', 'array'],
            'shop_editable.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:500'],
            // TR-09 — required once a draft exists; the service 409s when it
            // does not match.
            'lock_token' => ['nullable', 'string'],
        ]);

        $draft = $this->versions->saveDraft(
            $kindEnum,
            PrintTemplateScope::Brand,
            $brand,
            null,
            $data['definition'],
            $data['shop_editable'] ?? null,
            $data['notes'] ?? null,
            $data['lock_token'] ?? null,
            (string) $request->user()?->id,
        );

        return response()->json(['data' => $this->version($draft)], 200);
    }

    /**
     * Edit a version by id. Exists to make TR-08 explicit and loud: a PATCH
     * against a published version is a 409, not a silent no-op.
     */
    public function updateVersion(Request $request, string $kind, PrintTemplate $printTemplate): JsonResponse
    {
        $this->authorize('update', $printTemplate);
        $this->authorizeBrand($printTemplate);
        $this->versions->assertEditable($printTemplate);

        $data = $request->validate([
            'definition' => ['sometimes', 'array'],
            'shop_editable' => ['sometimes', 'array'],
            'shop_editable.*' => ['string'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lock_token' => ['nullable', 'string'],
        ]);

        $brand = $this->brand($request);

        $draft = $this->versions->saveDraft(
            $this->kind($kind),
            PrintTemplateScope::Brand,
            $brand,
            null,
            $data['definition'] ?? (array) $printTemplate->definition,
            $data['shop_editable'] ?? null,
            $data['notes'] ?? null,
            $data['lock_token'] ?? TemplateVersionService::lockToken($printTemplate),
            (string) $request->user()?->id,
        );

        return response()->json(['data' => $this->version($draft)]);
    }

    /** Validate + publish the draft. */
    public function publish(Request $request, string $kind): JsonResponse
    {
        $this->authorize('manageBrand', PrintTemplate::class);
        $brand = $this->brand($request);

        $data = $request->validate([
            // BRANCH-LOCAL wall clock (#1091), not an instant — 'Y-m-d' or
            // 'Y-m-d H:i(:s)'. Each branch flips at ITS own local time.
            'effective_from' => ['nullable', 'string', 'max:25'],
            'notes' => ['nullable', 'string', 'max:500'],
            'parent_version_id' => ['nullable', 'string'],
        ]);

        $published = $this->versions->publish(
            $this->kind($kind),
            PrintTemplateScope::Brand,
            $brand,
            null,
            $data['effective_from'] ?? null,
            $data['notes'] ?? null,
            (string) $request->user()?->id,
            $data['parent_version_id'] ?? null,
        );

        return response()->json(['data' => $this->version($published)], 201);
    }

    public function retire(Request $request, string $kind, PrintTemplate $printTemplate): JsonResponse
    {
        $this->authorize('update', $printTemplate);
        $this->authorizeBrand($printTemplate);

        return response()->json([
            'data' => $this->version($this->versions->retire($printTemplate, (string) $request->user()?->id)),
        ]);
    }

    public function rollback(Request $request, string $kind, PrintTemplate $printTemplate): JsonResponse
    {
        $this->authorize('update', $printTemplate);
        $this->authorizeBrand($printTemplate);

        $data = $request->validate(['effective_from' => ['nullable', 'string', 'max:25']]);

        return response()->json([
            'data' => $this->version($this->versions->rollback(
                $printTemplate,
                (string) $request->user()?->id,
                $data['effective_from'] ?? null,
            )),
        ], 201);
    }

    /** TR-31 — who changed what, when, with notes. */
    public function history(Request $request, string $kind): JsonResponse
    {
        $this->authorize('viewAny', PrintTemplate::class);
        $brand = $this->brand($request);

        $rows = $this->versions->history($this->kind($kind), PrintTemplateScope::Brand, $brand, null);

        return response()->json([
            'data' => $rows->map(fn (PrintTemplate $row): array => $this->version($row, withDefinition: false))->all(),
        ]);
    }

    /** TR-31 — "what is different between June's receipt and July's". */
    public function diff(Request $request, string $kind): JsonResponse
    {
        $this->authorize('viewAny', PrintTemplate::class);
        $brand = $this->brand($request);
        $kindEnum = $this->kind($kind);

        $data = $request->validate([
            'from' => ['nullable', 'integer', 'min:0'],
            'to' => ['nullable', 'integer', 'min:1'],
        ]);

        $rows = $this->versions->history($kindEnum, PrintTemplateScope::Brand, $brand, null);
        $to = isset($data['to'])
            ? $rows->firstWhere('version', $data['to'])
            : $rows->first();

        // Version 0 (or an absent `from`) means "compare against the system
        // default" — the honest baseline for a brand's very first publish.
        $from = isset($data['from']) && $data['from'] > 0
            ? $rows->firstWhere('version', $data['from'])
            : null;

        return response()->json([
            'data' => [
                'from_version' => $from?->version ?? 0,
                'to_version' => $to?->version,
                'changes' => $this->diff->between(
                    $from ? (array) $from->definition : $this->defaults->forKind($kindEnum),
                    $to ? (array) $to->definition : $this->defaults->forKind($kindEnum),
                ),
            ],
        ]);
    }

    /**
     * plan-053 M5 (#1171) TR-32 — an SVG preview of the slip this definition
     * would print.
     *
     * Server-side on purpose. Admin-web previewed templates with its own
     * TypeScript `preview-renderer.ts`, which meant two implementations of the
     * same layout rules and a standing invitation for the preview and the slip
     * to disagree — the shop that suffers that bug is the one that can read
     * neither. This endpoint replaces it; the FE renderer should be deleted
     * once it is wired up.
     *
     * The preview walks the definition with the SAME geometry the ESC/POS
     * renderer uses, so line breaks land where the printer will put them.
     * Engine-owned blocks show the TR-33 sample basket: structure is exact,
     * figures are illustrative. A preview that displayed authoritative-looking
     * money for an order that does not exist would be worse than one that is
     * obviously a sample.
     *
     * Defaults to the DRAFT, because the whole point is seeing what you are
     * about to publish; `?source=published` shows what is live. POSTing a
     * `definition` previews the editor's UNSAVED state — the same read, told
     * what to render instead of looking it up.
     */
    public function preview(Request $request, string $kind): Response
    {
        $this->authorize('viewAny', PrintTemplate::class);
        $brand = $this->brand($request);
        $kindEnum = $this->kind($kind);
        $preview = PreviewRequest::fromRequest($request);

        $definition = $preview->definition;

        if ($definition === null) {
            $row = $preview->useDraft
                ? $this->versions->currentDraft($kindEnum, PrintTemplateScope::Brand, $brand, null)
                : null;
            $row ??= $this->versions->currentPublished($kindEnum, PrintTemplateScope::Brand, $brand, null);

            // Falling back to the system default rather than 404ing matters: a
            // brand that has published nothing yet is the COMMON case on this
            // screen, and it still needs to see what it is starting from (TR-01).
            $definition = $row !== null
                ? (array) $row->definition
                : $this->defaults->forKind($kindEnum);
        }

        return $this->svgResponse($preview->svg($definition, $kindEnum));
    }

    private function svgResponse(string $svg): Response
    {
        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            // A draft changes as it is edited, so a cached preview is a wrong
            // preview.
            'Cache-Control' => 'no-store, private',
            // The document is inert (no script, no external reference) but it
            // is brand-authored text, so it is served with the same defences
            // any user-supplied document gets.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function brand(Request $request): Brand
    {
        $brand = $request->attributes->get('brand');
        if (! $brand instanceof Brand) {
            abort(400, 'Brand context not resolved.');
        }

        return $brand;
    }

    private function kind(string $kind): PrintTemplateKind
    {
        // TR-06 — an unknown kind is a 422, not a 500 or an empty template.
        if (! $this->catalog->hasKind($kind)) {
            abort(response()->json([
                'message' => "Unknown print template kind [{$kind}].",
                'code' => 'PRINT_TEMPLATE_KIND_UNKNOWN',
            ], 422));
        }

        return PrintTemplateKind::from($kind);
    }

    /** @return array<string, mixed> */
    private function version(PrintTemplate $row, bool $withDefinition = true): array
    {
        $payload = [
            'id' => $row->id,
            'kind' => $row->kind->value,
            'scope' => $row->scope->value,
            'version' => $row->version,
            'status' => $row->status->value,
            'effective_from' => $row->effective_from,
            'shop_editable' => $row->shop_editable,
            'notes' => $row->notes,
            'parent_version_id' => $row->parent_version_id,
            'created_by' => $row->relationLoaded('createdBy') ? $row->createdBy?->name : null,
            'published_by' => $row->relationLoaded('publishedBy') ? $row->publishedBy?->name : null,
            'published_at' => $row->published_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            // TR-09 — echo the optimistic-lock token back so the editor can
            // hand it to the next save without inventing its own.
            'lock_token' => TemplateVersionService::lockToken($row),
        ];

        if ($withDefinition) {
            $payload['definition'] = $row->definition;
        }

        return $payload;
    }
}
