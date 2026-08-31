<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->belongsToUserOrg($user, $branch);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Branch $branch): bool
    {
        $result = $this->belongsToUserOrg($user, $branch);

        \Log::info('BranchPolicy::update', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_console_org_id' => $user->console_organization_id,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'branch_console_org_id' => $branch->console_organization_id,
            'result' => $result,
        ]);

        return $result;
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->belongsToUserOrg($user, $branch);
    }

    /**
     * Branch uses console_organization_id (not organization_id),
     * so we compare directly instead of using ResolvesOrganization trait.
     */
    private function belongsToUserOrg(User $user, Branch $branch): bool
    {
        return $user->console_organization_id
            && $user->console_organization_id === $branch->console_organization_id;
    }
}
