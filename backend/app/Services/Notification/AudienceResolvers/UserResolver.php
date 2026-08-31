<?php

namespace App\Services\Notification\AudienceResolvers;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves `{type: 'user', user_ids: [...]}` sub-rules to User models.
 * Trace: `user:direct`.
 */
class UserResolver implements AudienceResolver
{
    public function type(): string
    {
        return 'user';
    }

    public function resolve(array $rule, Brand $brand): Collection
    {
        $ids = array_values(array_unique((array) ($rule['user_ids'] ?? [])));

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (User $user): array => [
                'notifiable' => $user,
                'key' => $user->getMorphClass().':'.$user->getKey(),
                'trace' => 'user:direct',
            ])
            ->values();
    }
}
