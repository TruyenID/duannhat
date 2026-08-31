<?php

namespace App\Policies;

use App\Models\TableTemplate;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class TableTemplatePolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TableTemplate $tableTemplate): bool
    {
        return $this->belongsToUserOrg($user, $tableTemplate);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TableTemplate $tableTemplate): bool
    {
        return $this->belongsToUserOrg($user, $tableTemplate);
    }

    public function delete(User $user, TableTemplate $tableTemplate): bool
    {
        return $this->belongsToUserOrg($user, $tableTemplate);
    }

    public function restore(User $user, TableTemplate $tableTemplate): bool
    {
        return $this->belongsToUserOrg($user, $tableTemplate);
    }

    public function toggleActive(User $user, TableTemplate $tableTemplate): bool
    {
        return $this->belongsToUserOrg($user, $tableTemplate);
    }
}
