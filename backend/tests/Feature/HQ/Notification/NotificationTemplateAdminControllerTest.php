<?php

/**
 * HQ Template admin API tests (plan-012 T2.4).
 */

use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'tpl-hq-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'locale' => 'ja',
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/templates";
});

function completeContent(): array
{
    return [
        'ja' => ['title' => 'jaタイトル {{name}}', 'body' => 'jaボディ'],
        'en' => ['title' => 'en title {{name}}', 'body' => 'en body'],
        'vi' => ['title' => 'vi tiêu đề {{name}}', 'body' => 'vi body'],
    ];
}

describe('store', function () {
    it('creates a template when ja/en/vi content is complete', function () {
        $this->actingAs($this->user)->postJson($this->base, [
            'key' => 'custom.hello',
            'content' => completeContent(),
        ])->assertCreated()
            ->assertJsonPath('data.key', 'custom.hello')
            ->assertJsonPath('data.is_system', false);
    });

    it('rejects when any of ja/en/vi is missing', function () {
        $partial = completeContent();
        unset($partial['en']);

        $this->actingAs($this->user)->postJson($this->base, [
            'key' => 'custom.partial',
            'content' => $partial,
        ])->assertStatus(422);
    });
});

describe('is_system protection', function () {
    it('returns 403 on PATCH when is_system=true', function () {
        $tpl = NotificationTemplate::query()->create([
            'key' => 'sys.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => true,
        ]);

        $this->actingAs($this->user)
            ->patchJson("{$this->base}/{$tpl->id}", ['content' => completeContent()])
            ->assertForbidden();
    });

    it('returns 204 on DELETE when is_system=false', function () {
        $tpl = NotificationTemplate::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'key' => 'user.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => false,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$tpl->id}")
            ->assertNoContent();
    });
});

describe('IN_USE guard on delete (TC-NOTIF-TPL05)', function () {
    it('blocks deleting a template referenced by a rule and returns IN_USE', function () {
        $tpl = NotificationTemplate::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'key' => 'used.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => false,
        ]);

        NotificationRule::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => null,
            'action' => ['template_key' => $tpl->key, 'channels' => ['in_app']],
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$tpl->id}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'IN_USE');

        expect(NotificationTemplate::find($tpl->id))->not->toBeNull();
    });

    it('allows deleting a template that no rule references', function () {
        $tpl = NotificationTemplate::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'key' => 'unused.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => false,
        ]);

        NotificationRule::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => null,
            'action' => ['template_key' => 'some.other.key', 'channels' => ['in_app']],
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$tpl->id}")
            ->assertNoContent();

        expect(NotificationTemplate::find($tpl->id))->toBeNull();
    });
});

describe('duplicate (TC-NOTIF-TPL06)', function () {
    it('creates an editable brand-owned copy with a -copy key', function () {
        $tpl = NotificationTemplate::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'key' => 'dup.base',
            'content' => completeContent(),
            'default_channels' => ['in_app', 'email'],
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/{$tpl->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.key', 'dup.base-copy')
            ->assertJsonPath('data.is_system', false);

        $copy = NotificationTemplate::find($response->json('data.id'));
        expect($copy->id)->not->toBe($tpl->id)
            ->and($copy->brand_id)->toBe($this->brand->id)
            ->and($copy->content)->toEqual($tpl->content)
            ->and($copy->default_channels)->toEqual(['in_app', 'email']);
    });

    it('increments the copy suffix when -copy already exists', function () {
        $tpl = NotificationTemplate::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'key' => 'dup.again',
            'content' => completeContent(),
            'is_system' => false,
        ]);

        $this->actingAs($this->user)
            ->postJson("{$this->base}/{$tpl->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.key', 'dup.again-copy');

        $this->actingAs($this->user)
            ->postJson("{$this->base}/{$tpl->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.key', 'dup.again-copy-2');
    });

    it('duplicates a system template into an editable copy', function () {
        $sys = NotificationTemplate::query()->create([
            'key' => 'sys.dup.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/{$sys->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.is_system', false);

        $copy = NotificationTemplate::find($response->json('data.id'));
        expect($copy->brand_id)->toBe($this->brand->id);
    });

    it('returns 404 when duplicating a template from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreign = NotificationTemplate::query()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
            'key' => 'foreign.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => false,
        ]);

        $this->actingAs($this->user)
            ->postJson("{$this->base}/{$foreign->id}/duplicate")
            ->assertNotFound();
    });
});

describe('render preview', function () {
    it('returns ja/en/vi rendered for a saved template', function () {
        $tpl = NotificationTemplate::query()->create([
            'key' => 'preview.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson("{$this->base}/render", [
                'template_id' => $tpl->id,
                'params' => ['name' => 'Alex'],
            ])->assertOk()
            ->assertJsonPath('data.ja.title', 'jaタイトル Alex')
            ->assertJsonPath('data.en.title', 'en title Alex')
            ->assertJsonPath('data.vi.title', 'vi tiêu đề Alex');
    });
});

describe('cross-tenant authz pin (issue #173 part E)', function () {
    it('does not list templates from another organization (excluding is_system)', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);

        // Foreign tenant's private template.
        NotificationTemplate::query()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
            'key' => 'foreign.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => false,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base)->assertOk();
        $keys = collect($response->json('data'))->pluck('key')->all();

        // The foreign template's key must not appear. is_system templates
        // (cross-brand-shared by design) are allowed to appear.
        foreach ($keys as $key) {
            expect(str_starts_with($key, 'foreign.'))->toBeFalse();
        }
    });

    it('returns 404 when updating a template from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreign = NotificationTemplate::query()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
            'key' => 'foreign.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => false,
        ]);

        $this->actingAs($this->user)
            ->patchJson("{$this->base}/{$foreign->id}", ['key' => 'pwned'])
            ->assertNotFound();

        expect($foreign->fresh()->key)->not->toBe('pwned');
    });

    it('returns 404 when deleting a template from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreign = NotificationTemplate::query()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
            'key' => 'foreign.'.Str::random(4),
            'content' => completeContent(),
            'is_system' => false,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$foreign->id}")
            ->assertNotFound();

        expect(NotificationTemplate::find($foreign->id))->not->toBeNull();
    });
});

describe('brand_id mass-assignment guard (issue #171)', function () {
    it('ignores caller-supplied brand_id and pins to the route brand', function () {
        $foreignBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'key' => 'pin.'.Str::random(4),
            'content' => completeContent(),
            'brand_id' => $foreignBrand->id, // attempt to pin elsewhere
        ])->assertCreated();

        $createdId = $response->json('data.id');
        $persisted = NotificationTemplate::find($createdId);

        expect($persisted->brand_id)->toBe($this->brand->id)
            ->and($persisted->brand_id)->not->toBe($foreignBrand->id);
    });
});

describe('e2e — custom template renders in /me/notifications', function () {
    it('admin creates a custom template; dispatch using that key renders new content', function () {
        $customKey = 'custom.'.Str::random(4);

        $this->user->update(['locale' => 'en']);

        $this->actingAs($this->user)->postJson($this->base, [
            'key' => $customKey,
            'content' => completeContent(),
        ])->assertCreated();

        $notification = Notification::query()->create([
            'organization_id' => $this->orgId,
            'type' => $customKey,
            'template_key' => $customKey,
            'params' => ['name' => 'Taro'],
            'priority' => 'normal',
        ]);
        NotificationRecipient::query()->create([
            'notification_id' => $notification->id,
            'recipient_type' => $this->user->getMorphClass(),
            'recipient_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/me/notifications?collapse=false')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'en title Taro');
    });
});
