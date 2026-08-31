<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

trait HasOrganizationScope
{
    /**
     * Get the current organization ID from the authenticated user.
     */
    protected function getOrganizationId(): string
    {
        $user = Auth::user();

        if (! $user || ! $user->console_organization_id) {
            throw new \RuntimeException('Organization context is required.');
        }

        return $user->console_organization_id;
    }
}
