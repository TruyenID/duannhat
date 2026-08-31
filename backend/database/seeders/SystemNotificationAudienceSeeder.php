<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Seeds baseline `is_system=true` notification audiences per brand
 * (plan-012 T1.6). These rows are the ones Phase A emitters migrate to
 * when `NOTIFICATION_USE_AUDIENCE=true` in T1.7.
 *
 * Idempotent: `updateOrCreate` on `(brand_id, name)` so running the
 * seeder twice doesn't duplicate rows, and re-running after editing the
 * SYSTEM_AUDIENCES rule shape propagates the change to existing rows.
 * Brands created after this runs get covered by the per-brand observer
 * in M4 T4.3, or by re-running this seeder.
 */
class SystemNotificationAudienceSeeder extends Seeder
{
    /**
     * Baseline audience templates applied per brand. Order here is the
     * order they appear in the admin list.
     *
     * @var array<int, array{name: string, description: string, role: string}>
     */
    private const SYSTEM_AUDIENCES = [
        [
            'name' => 'All warehouse managers',
            'description' => 'Every user with warehouse_manager role across the brand.',
            'role' => 'warehouse_manager',
        ],
        [
            'name' => 'All shop managers',
            'description' => 'Every user with shop-manager role across the brand.',
            'role' => 'shop-manager',
        ],
        [
            'name' => 'All brand admins',
            'description' => 'Every user with org-admin role in this brand.',
            'role' => 'org-admin',
        ],
    ];

    public function run(): void
    {
        Brand::query()->cursor()->each(function (Brand $brand) {
            $organizationId = Organization::query()
                ->where('console_organization_id', $brand->console_organization_id)
                ->value('id');

            if ($organizationId === null) {
                return;
            }

            foreach (self::SYSTEM_AUDIENCES as $template) {
                NotificationAudience::query()->updateOrCreate(
                    ['brand_id' => $brand->id, 'name' => $template['name']],
                    [
                        'organization_id' => $organizationId,
                        'description' => $template['description'],
                        'rule' => [
                            'version' => 1,
                            'combinator' => 'or',
                            'rules' => [[
                                'type' => 'role',
                                'role' => $template['role'],
                                'scope' => ['brand_id' => $brand->id],
                            ]],
                        ],
                        'is_system' => true,
                    ]
                );
            }
        });
    }
}
