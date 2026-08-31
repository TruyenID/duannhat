<?php

/**
 * plan-012 T2.3 — GET /me/notifications returns title + body rendered
 * server-side per the caller's locale + "Decision 15 — no snapshot"
 * behavior (admin PATCH flows through immediately).
 */

use App\Models\Notification;
use App\Models\NotificationRecipient;
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
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'locale' => 'ja',
    ]);

    $this->template = NotificationTemplate::query()->create([
        'key' => 'recipe.approved',
        'content' => [
            'ja' => ['title' => 'レシピ承認：{{recipe_name}}', 'body' => '{{approver}}が承認'],
            'en' => ['title' => 'Recipe approved: {{recipe_name}}', 'body' => 'by {{approver}}'],
            'vi' => ['title' => 'Công thức đã duyệt: {{recipe_name}}', 'body' => 'bởi {{approver}}'],
        ],
        'is_system' => true,
    ]);

    $this->notification = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => ['recipe_name' => 'カレー', 'approver' => '田中'],
        'priority' => 'normal',
    ]);
    NotificationRecipient::query()->create([
        'notification_id' => $this->notification->id,
        'recipient_type' => $this->user->getMorphClass(),
        'recipient_id' => $this->user->id,
    ]);
});

it('returns title+body rendered for a ja-locale user', function () {
    $this->user->update(['locale' => 'ja']);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/notifications?collapse=false')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'レシピ承認：カレー')
        ->assertJsonPath('data.0.body', '田中が承認');
});

it('returns title+body rendered for an en-locale user', function () {
    $this->user->update(['locale' => 'en']);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/notifications?collapse=false')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Recipe approved: カレー')
        ->assertJsonPath('data.0.body', 'by 田中');
});

it('returns title+body rendered for a vi-locale user', function () {
    $this->user->update(['locale' => 'vi']);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/notifications?collapse=false')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Công thức đã duyệt: カレー')
        ->assertJsonPath('data.0.body', 'bởi 田中');
});

it('reflects an admin PATCH immediately (Decision 15 — no snapshot)', function () {
    $this->user->update(['locale' => 'ja']);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/notifications?collapse=false')
        ->assertJsonPath('data.0.title', 'レシピ承認：カレー');

    // Admin renames the ja title.
    $this->template->update([
        'content' => array_merge((array) $this->template->content, [
            'ja' => ['title' => '【更新済】{{recipe_name}}', 'body' => 'ボディ'],
        ]),
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/notifications?collapse=false')
        ->assertJsonPath('data.0.title', '【更新済】カレー');
});

it('falls back to template_key literal when the template row is missing', function () {
    $this->template->delete();

    $this->actingAs($this->user)
        ->getJson('/api/v1/me/notifications?collapse=false')
        ->assertJsonPath('data.0.title', 'recipe.approved')
        ->assertJsonPath('data.0.body', '');
});
