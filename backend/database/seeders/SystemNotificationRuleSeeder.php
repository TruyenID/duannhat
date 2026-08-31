<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\NotificationRule;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds system notification rules.
 *
 * M7 T7.11 — 4 Phase A shadow rules per brand (inactive, shadow-rollout).
 * M8 T8.6–T8.12 — global system rules for product/menu/stock/device/coupon/brand,
 *   seeded once per organization (not per brand). Active by default since they
 *   are the canonical emitter path for M8 domains.
 *
 * Idempotency:
 *   Brand-scoped (M7): `firstOrCreate(['brand_id' => $brand->id, 'name' => $name])`.
 *   Global (M8): `firstOrCreate(['organization_id' => $orgId, 'brand_id' => null, 'name' => $name])`.
 *
 * The seeder does NOT touch() existing models — seeding never causes Eloquent
 * events to fire on historical rows (protects against the "rule storm" risk).
 */
class SystemNotificationRuleSeeder extends Seeder
{
    /**
     * M7 Phase A shadow rules (per brand). Kept in CONST for arch test compatibility.
     *
     * @var array<int, array<string, mixed>>
     */
    private const M7_RULES = [
        [
            'name' => 'Shadow: stock alert (low/out)',
            'description' => 'Mirrors StockAlertNotificationObserver. Fires on every new StockAlert; routes to warehouse managers.',
            'trigger_event' => 'model.created',
            'trigger_model_type' => 'StockAlert',
            'conditions' => [
                'combinator' => 'and',
                'children' => [],
            ],
            'action_template_key' => 'stock.alert.low',
            'audience_name' => 'All warehouse managers',
            'priority' => 'high',
            'is_active' => false,
        ],
        [
            'name' => 'Shadow: customer order status changed',
            'description' => 'Mirrors CustomerOrderNotificationObserver. Fires when CustomerOrder.status changes; routes to shop managers of the branch.',
            'trigger_event' => 'model.updated',
            'trigger_model_type' => 'CustomerOrder',
            'conditions' => [
                'field' => 'status',
                'op' => 'changed',
            ],
            'action_template_key' => 'order.status_changed',
            'audience_name' => 'All shop managers',
            'priority' => 'normal',
            'is_active' => false,
        ],
        [
            'name' => 'Shadow: recipe approved',
            'description' => 'Mirrors RecipeService::approve. Fires when Recipe.approval_status flips to approved; routes to recipe submitter (modelled as brand admins in the audience set).',
            'trigger_event' => 'model.updated',
            'trigger_model_type' => 'Recipe',
            'conditions' => [
                'field' => 'approval_status',
                'op' => 'changed_to',
                'value' => 'approved',
            ],
            'action_template_key' => 'recipe.approved',
            'audience_name' => 'All brand admins',
            'priority' => 'normal',
            'is_active' => false,
        ],
        [
            'name' => 'Shadow: recipe rejected',
            'description' => 'Mirrors RecipeService::reject. Fires when Recipe.approval_status flips to rejected; routes to recipe submitter (modelled as brand admins).',
            'trigger_event' => 'model.updated',
            'trigger_model_type' => 'Recipe',
            'conditions' => [
                'field' => 'approval_status',
                'op' => 'changed_to',
                'value' => 'rejected',
            ],
            'action_template_key' => 'recipe.rejected',
            'audience_name' => 'All brand admins',
            'priority' => 'normal',
            'is_active' => false,
        ],
    ];

    /**
     * Plan-023 M8 global system rules — seeded once per organization.
     * Exposed as public static so arch tests can assert coverage.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function systemRules(): array
    {
        return [
            // T8.6 — Product approval lifecycle
            [
                'name' => 'System: product submitted for approval',
                'description' => 'Fires when a product status changes to pending, notifying brand admins to review.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Product',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'pending'],
                'action' => [
                    'template_key' => 'product.submitted_for_approval',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id'],
                    'param_template' => ['$product_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: product approved',
                'description' => 'Fires when a product is approved, notifying the submitter.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Product',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'approved'],
                'action' => [
                    'template_key' => 'product.approved',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
                    'param_template' => ['$product_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: product rejected',
                'description' => 'Fires when a product is rejected, notifying the submitter via high-priority email.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Product',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'rejected'],
                'action' => [
                    'template_key' => 'product.rejected',
                    'priority' => 'high',
                    'channels' => ['in_app', 'realtime', 'email'],
                    'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
                    'param_template' => ['$product_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
            // T8.7 — Menu approval lifecycle
            [
                'name' => 'System: menu submitted for approval',
                'description' => 'Fires when a menu status changes to Pending, notifying brand admins.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Menu',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'Pending'],
                'action' => [
                    'template_key' => 'menu.submitted_for_approval',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id'],
                    'param_template' => ['$menu_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: menu approved',
                'description' => 'Fires when a menu is approved.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Menu',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'Approved'],
                'action' => [
                    'template_key' => 'menu.approved',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
                    'param_template' => ['$menu_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: menu rejected',
                'description' => 'Fires when a menu is rejected, notifying the submitter via email.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Menu',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'Rejected'],
                'action' => [
                    'template_key' => 'menu.rejected',
                    'priority' => 'high',
                    'channels' => ['in_app', 'realtime', 'email'],
                    'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
                    'param_template' => ['$menu_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
            // T8.8 — StockTransaction lifecycle
            // NOTE: StockTransaction.status (pending/approved/completed/cancelled) uses
            // the StockTransactionStatusEnum. "pending" maps to submitted status.
            // Field `warehouse_id` is used for branch scoping; `created_by_id` for submitter.
            [
                'name' => 'System: stock transaction submitted',
                'description' => 'Fires when a stock transaction is set to pending (submitted), notifying warehouse managers.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'StockTransaction',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'pending'],
                'action' => [
                    'template_key' => 'stock_transaction.submitted',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
                    'param_template' => ['$transaction_code' => '$model.transaction_code'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: stock transaction approved',
                'description' => 'Fires when a stock transaction is approved, notifying the requester.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'StockTransaction',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'approved'],
                'action' => [
                    'template_key' => 'stock_transaction.approved',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
                    'param_template' => ['$transaction_code' => '$model.transaction_code'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: stock transaction rejected',
                'description' => 'Fires when a stock transaction is cancelled/rejected, notifying the requester via high priority.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'StockTransaction',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'cancelled'],
                'action' => [
                    'template_key' => 'stock_transaction.rejected',
                    'priority' => 'high',
                    'channels' => ['in_app', 'realtime', 'email'],
                    'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
                    'param_template' => ['$transaction_code' => '$model.transaction_code'],
                ],
                'is_active' => true,
            ],
            // T8.9 — StockTransfer lifecycle
            // NOTE: StockTransfer uses destination_warehouse_id and source_warehouse_id.
            // No shop_id / branch_id column exists on StockTransfer, so the branch-scoped
            // audience resolver reads the warehouse's branch via the relation path
            // `{destination,source}Warehouse.branch_id` (data_get traverses the relation).
            // Feeding the raw warehouse_id would query role_user_pivots.branch_id against a
            // warehouse UUID and always resolve to zero recipients.
            [
                'name' => 'System: stock transfer in transit',
                'description' => 'Fires when a stock transfer moves to in_transit, alerting the destination warehouse.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'StockTransfer',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'in_transit'],
                'action' => [
                    'template_key' => 'stock_transfer.in_transit',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'warehouse_manager', 'scope' => 'branch', 'branch_field' => 'destinationWarehouse.branch_id'],
                    'param_template' => ['$transfer_code' => '$model.transfer_code'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: stock transfer received',
                'description' => 'Fires when a stock transfer is completed/received, notifying the source warehouse.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'StockTransfer',
                'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => 'completed'],
                'action' => [
                    'template_key' => 'stock_transfer.received',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'warehouse_manager', 'scope' => 'branch', 'branch_field' => 'sourceWarehouse.branch_id'],
                    'param_template' => ['$transfer_code' => '$model.transfer_code'],
                ],
                'is_active' => true,
            ],
            // T8.10 — Device pairing + offline
            // NOTE: Device uses branch_id (not shop_id). device.offline uses custom event.
            [
                'name' => 'System: device paired',
                'description' => 'Fires when a device status changes to active (paired), notifying org admins.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Device',
                'conditions' => [
                    'combinator' => 'and',
                    'children' => [
                        ['field' => 'status', 'op' => 'changed_to', 'value' => 'active'],
                        ['field' => 'paired_at', 'op' => 'changed'],
                    ],
                ],
                'action' => [
                    'template_key' => 'device.paired',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'shop-manager', 'scope' => 'branch', 'branch_field' => 'branch_id'],
                    'param_template' => ['$device_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: device unpaired',
                'description' => 'Fires when a device reverts to pending_activation (unpaired), high priority email.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Device',
                'conditions' => [
                    'combinator' => 'and',
                    'children' => [
                        ['field' => 'status', 'op' => 'changed_to', 'value' => 'pending_activation'],
                        ['field' => 'paired_at', 'op' => 'changed'],
                    ],
                ],
                'action' => [
                    'template_key' => 'device.unpaired',
                    'priority' => 'high',
                    'channels' => ['in_app', 'realtime', 'email'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'shop-manager', 'scope' => 'branch', 'branch_field' => 'branch_id'],
                    'param_template' => ['$device_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: device offline',
                'description' => 'Fires when DeviceOfflineDetectionJob detects a stale device heartbeat.',
                'trigger_event' => 'custom.device.offline.detected',
                'trigger_model_type' => 'Device',
                'conditions' => ['combinator' => 'and', 'children' => []],
                'action' => [
                    'template_key' => 'device.offline',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'realtime'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'shop-manager', 'scope' => 'branch', 'branch_field' => 'branch_id'],
                    'param_template' => ['$minutes_offline' => '$context.minutes_offline', '$device_name' => '$model.name'],
                ],
                'is_active' => true,
                'cooldown_minutes' => 60,
            ],
            // T8.11 — Coupon redemption + expiry
            [
                'name' => 'System: coupon redeemed',
                'description' => 'Fires when a CouponRedemption is created.',
                'trigger_event' => 'model.created',
                'trigger_model_type' => 'CouponRedemption',
                'conditions' => ['combinator' => 'and', 'children' => []],
                'action' => [
                    'template_key' => 'coupon.redeemed',
                    'priority' => 'low',
                    'channels' => ['in_app'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'coupon.brand_id'],
                    'param_template' => [],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: coupon expiring soon',
                'description' => 'Fires when CouponExpirationScannerJob detects a coupon expiring within 72h.',
                'trigger_event' => 'custom.coupon.expiring',
                'trigger_model_type' => 'Coupon',
                'conditions' => ['combinator' => 'and', 'children' => []],
                'action' => [
                    'template_key' => 'coupon.expiring_soon',
                    'priority' => 'normal',
                    'channels' => ['in_app', 'email'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id'],
                    'param_template' => ['$hours_remaining' => '$context.hours_remaining'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'System: coupon expired',
                'description' => 'Fires when CouponExpirationScannerJob detects a coupon that has expired.',
                'trigger_event' => 'custom.coupon.expired',
                'trigger_model_type' => 'Coupon',
                'conditions' => ['combinator' => 'and', 'children' => []],
                'action' => [
                    'template_key' => 'coupon.expired',
                    'priority' => 'low',
                    'channels' => ['in_app'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id'],
                    'param_template' => [],
                ],
                'is_active' => true,
            ],
            // T8.12 — Brand status changed
            // NOTE: brands table uses is_active (boolean), not a status enum.
            // Audience: organization owners (org-scoped role).
            [
                'name' => 'System: brand status changed',
                'description' => 'Fires when a brand is activated or deactivated (is_active changed), notifying organization owners.',
                'trigger_event' => 'model.updated',
                'trigger_model_type' => 'Brand',
                'conditions' => ['field' => 'is_active', 'op' => 'changed'],
                'action' => [
                    'template_key' => 'brand.status_changed',
                    'priority' => 'high',
                    'channels' => ['in_app', 'realtime', 'email'],
                    'audience_rule' => ['type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'organization', 'org_field' => 'organization_id'],
                    'param_template' => ['$brand_name' => '$model.name'],
                ],
                'is_active' => true,
            ],
        ];
    }

    public function run(): void
    {
        // M7: seed per-brand shadow rules
        Brand::query()->cursor()->each(function (Brand $brand) {
            $organizationId = Organization::query()
                ->where('console_organization_id', $brand->console_organization_id)
                ->value('id');

            if ($organizationId === null) {
                return;
            }

            foreach (self::M7_RULES as $template) {
                NotificationRule::query()->firstOrCreate(
                    ['brand_id' => $brand->id, 'name' => $template['name']],
                    [
                        'id' => (string) Str::uuid(),
                        'organization_id' => $organizationId,
                        'branch_id' => null,
                        'description' => $template['description'],
                        'trigger_event' => $template['trigger_event'],
                        'trigger_model_type' => $template['trigger_model_type'],
                        'conditions' => $template['conditions'],
                        'action' => [
                            'template_key' => $template['action_template_key'],
                            'priority' => $template['priority'],
                            'channels' => ['in_app'],
                            'audience_name' => $template['audience_name'],
                            'param_template' => [],
                        ],
                        'cooldown_minutes' => 0,
                        'is_active' => $template['is_active'],
                        'fire_count' => 0,
                    ]
                );
            }
        });

        // M8: seed global system rules once per organization
        Organization::query()->cursor()->each(function (Organization $org) {
            foreach (static::systemRules() as $rule) {
                NotificationRule::query()->firstOrCreate(
                    [
                        'organization_id' => $org->id,
                        'brand_id' => null,
                        'name' => $rule['name'],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'branch_id' => null,
                        'description' => $rule['description'],
                        'trigger_event' => $rule['trigger_event'],
                        'trigger_model_type' => $rule['trigger_model_type'],
                        'conditions' => $rule['conditions'],
                        'action' => $rule['action'],
                        'cooldown_minutes' => $rule['cooldown_minutes'] ?? 0,
                        'is_active' => $rule['is_active'],
                        'fire_count' => 0,
                    ]
                );
            }
        });
    }
}
