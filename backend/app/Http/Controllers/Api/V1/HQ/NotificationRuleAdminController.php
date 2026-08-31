<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Jobs\Notification\EvaluateRuleJob;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use App\Models\Organization;
use App\Services\Notification\RuleDsl\RuleDslValidator;
use App\Services\Notification\RuleEvaluatorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * `/api/v1/hq/{brandSlug}/notifications/rules*` — admin CRUD for
 * NotificationRule rows (plan-023 M7 T7.5) + dry-run endpoint
 * (T7.5 DESIGN §M7 — admin previews last 7 days of model matches).
 *
 * Authorization: reuse `manageAudiences` ability (extends viewAdmin).
 * A finer-grained `manageRules` permission defers to the role-matrix
 * follow-up tracked in plan-012 README.md.
 *
 * Cross-tenant guards:
 *   - brand_id pinned from URL (never accepted from body)
 *   - shop-scoped rows (branch_id IS NOT NULL) NOT visible here —
 *     they belong to the shop admin surface
 *   - System-seeded rows (created via SystemNotificationRuleSeeder)
 *     are NOT specially protected at PATCH/DELETE; admins can
 *     adjust them freely. Re-seeding restores defaults via
 *     firstOrCreate on (brand_id, name).
 */
class NotificationRuleAdminController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly RuleEvaluatorService $evaluator) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/rules',
        summary: 'List rules scoped to this brand (excludes shop-scoped rows)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'trigger_event', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $query = NotificationRule::query()
            ->where('brand_id', $brand->id)
            ->whereNull('branch_id')          // HQ scope only — shop rows live on the shop surface
            ->orderByDesc('updated_at');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($triggerEvent = $request->query('trigger_event')) {
            $query->where('trigger_event', $triggerEvent);
        }

        $paginator = $query->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/rules',
        summary: 'Create a brand-scoped rule (ships is_active=false)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $data = $this->validateRulePayload($request, isUpdate: false);

        $orgIds = $this->brandOrgIds($brand);

        $row = NotificationRule::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgIds[0] ?? null,
            'brand_id' => $brand->id,
            'branch_id' => null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'trigger_event' => $data['trigger_event'],
            'trigger_model_type' => $data['trigger_model_type'] ?? null,
            'conditions' => $data['conditions'],
            'action' => $data['action'],
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 0,
            'is_active' => false,                                  // always inactive on create
            'created_by_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => $row], 201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/rules/{id}',
        summary: 'Fetch a single rule with recent firings preview',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Rule + last 10 firings')]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $rule = $this->findInBrand($id, $brand);

        $firings = NotificationRuleFiring::query()
            ->where('rule_id', $rule->id)
            ->orderByDesc('fired_at')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => array_merge($rule->toArray(), [
                'recent_firings' => $firings,
            ]),
        ]);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/notifications/rules/{id}',
        summary: 'Update a rule (also toggles is_active)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Updated')]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $rule = $this->findInBrand($id, $brand);
        $data = $this->validateRulePayload($request, isUpdate: true);

        $rule->fill($data)->save();

        return response()->json(['data' => $rule->fresh()]);
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/notifications/rules/{id}',
        summary: 'Delete a rule (firings cascade)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Deleted')]
    )]
    public function destroy(Request $request, string $id): Response
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $rule = $this->findInBrand($id, $brand);
        $rule->delete();

        return response()->noContent();
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/notifications/rules/{id}/firings',
        summary: 'List firings for a rule (paginated)',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Paginated firings')]
    )]
    public function firings(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $rule = $this->findInBrand($id, $brand);

        $query = NotificationRuleFiring::query()
            ->where('rule_id', $rule->id)
            ->orderByDesc('fired_at');

        if ($outcome = $request->query('outcome')) {
            $query->where('outcome', $outcome);
        }

        $paginator = $query->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/rules/{id}/firings/{firing}/retry',
        summary: 'Re-fire a failed firing; re-runs the rule against its model and records a new firing',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'New firing row produced by the retry'),
            new OA\Response(response: 404, description: 'Rule or firing not found'),
            new OA\Response(response: 422, description: 'Firing is not a failed firing, rule inactive, or model gone'),
        ]
    )]
    public function retryFiring(Request $request, string $id, string $firing): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $rule = $this->findInBrand($id, $brand);

        $firingRow = NotificationRuleFiring::query()
            ->where('rule_id', $rule->id)
            ->findOrFail($firing);

        if ($firingRow->outcome !== 'error') {
            return response()->json([
                'message' => 'Only failed firings can be retried.',
                'error' => 'not_retryable',
            ], 422);
        }

        if (empty($firingRow->model_type) || empty($firingRow->model_id)) {
            return response()->json([
                'message' => 'This firing has no associated model to retry.',
                'error' => 'model_missing',
            ], 422);
        }

        if (! $rule->is_active) {
            return response()->json([
                'message' => 'Cannot retry: the rule is inactive. Activate it first.',
                'error' => 'rule_inactive',
            ], 422);
        }

        // Re-run the full evaluation pipeline synchronously so the response
        // reflects the retry's outcome. EvaluateRuleJob writes a fresh firing
        // row (matched / skipped_* / shadow / error) — the single source of
        // truth for "evaluate this rule against this model".
        $previousLatestId = NotificationRuleFiring::query()
            ->where('rule_id', $rule->id)
            ->orderByDesc('fired_at')
            ->value('id');

        EvaluateRuleJob::dispatchSync(
            ruleId: $rule->id,
            modelClass: (string) $firingRow->model_type,
            modelId: (string) $firingRow->model_id,
        );

        $latest = NotificationRuleFiring::query()
            ->where('rule_id', $rule->id)
            ->orderByDesc('fired_at')
            ->first();

        if ($latest === null || $latest->id === $previousLatestId) {
            // No new row — the model row was deleted between firing and retry.
            return response()->json([
                'message' => 'Retry produced no new firing (the model may have been deleted).',
                'error' => 'retry_noop',
            ], 422);
        }

        return response()->json(['data' => $latest]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/notifications/rules/{id}/dry-run',
        summary: 'Replay the rule against recent model rows; return matches without dispatching',
        tags: ['HQ - Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Sample of matched rows + evaluation_trace'),
            new OA\Response(response: 422, description: 'Rule trigger_model_type missing or unmapped'),
        ]
    )]
    public function dryRun(Request $request, string $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $this->authorize('manageAudiences', [Notification::class, $brand]);

        $rule = $this->findInBrand($id, $brand);
        $alias = (string) ($rule->trigger_model_type ?? '');
        $class = Relation::getMorphedModel($alias);

        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return response()->json(['message' => 'Rule trigger_model_type is unmapped', 'error' => 'model_unmapped'], 422);
        }

        $since = Carbon::now()->subDays((int) $request->query('since_days', 7));

        $samples = $class::query()
            ->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $matched = [];
        $considered = 0;
        foreach ($samples as $sample) {
            $considered++;
            $result = $this->evaluator->evaluate($rule, $sample, []);
            if ($result->matched) {
                $matched[] = [
                    'id' => $sample->getKey(),
                    'updated_at' => optional($sample->updated_at)->toIso8601String(),
                    'trace' => $result->trace,
                ];
            }
            if (count($matched) >= 5) {
                break;
            }
        }

        return response()->json([
            'data' => [
                'considered' => $considered,
                'matched_count' => count($matched),
                'sample' => $matched,
                'window_since' => $since->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRulePayload(Request $request, bool $isUpdate): array
    {
        $rules = [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'trigger_event' => [$isUpdate ? 'sometimes' : 'required', 'string', 'regex:/^(model\.(created|updated|deleted)|custom\.[a-z0-9._-]+)$/'],
            'trigger_model_type' => ['nullable', 'string', 'max:100'],
            'conditions' => [$isUpdate ? 'sometimes' : 'required', 'array'],
            'action' => [$isUpdate ? 'sometimes' : 'required', 'array'],
            'action.template_key' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:100'],
            'action.channels' => [$isUpdate ? 'sometimes' : 'required', 'array'],
            'action.priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'cooldown_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];

        $data = Validator::make($request->all(), $rules)->validate();

        if (isset($data['conditions'])) {
            $errors = RuleDslValidator::validate($data['conditions']);
            if ($errors !== []) {
                abort(response()->json([
                    'message' => 'Invalid rule conditions DSL.',
                    'errors' => $errors,
                ], 422));
            }
        }

        return $data;
    }

    private function findInBrand(string $id, Brand $brand): NotificationRule
    {
        return NotificationRule::query()
            ->where('brand_id', $brand->id)
            ->whereNull('branch_id')
            ->findOrFail($id);
    }

    /**
     * @return array<int, string>
     */
    private function brandOrgIds(Brand $brand): array
    {
        return Organization::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->pluck('id')
            ->all();
    }
}
