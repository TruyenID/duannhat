<?php

namespace App\Policies;

use App\Models\PrintJob;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;
use App\Services\Printing\ReprintAdvisor;

/**
 * plan-052 M2 / T2.2 — who may look at the print ledger, and who may close a
 * line in it.
 *
 * | Ability   | org-admin | org-manager | shop-manager | staff / cashier |
 * |-----------|-----------|-------------|--------------|-----------------|
 * | viewAny   | ✅        | ✅          | ✅           | ✅              |
 * | view      | ✅        | ✅          | ✅           | ✅              |
 * | resolve   | ✅        | ✅          | ✅           | ❌ 403          |
 *
 * A cashier SEES everything — they are the one standing at the machine, and
 * hiding the queue from them would make the screen useless exactly where it
 * matters. What they may not do is declare the matter closed: "the receipt was
 * printed by hand" is a statement an auditor may later lean on, so it carries
 * a manager's name (the same #1124 governance the reprint gate uses).
 *
 * Role checks go through `hasRoleInContext(slug, orgId, branchId)`. Never a
 * `level >= N` comparison — that pattern does not exist in this codebase.
 */
class PrintJobPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        // The shop-scoping middleware already proved this user has access to
        // this shop; the controller scopes every query to it.
        return true;
    }

    public function view(User $user, PrintJob $job): bool
    {
        return $this->belongsToUserOrg($user, $job);
    }

    /**
     * T2.2 — manager-only. NOT a reprint button: reprinting is never gated at
     * all (P-10, ruling 2026-07-28). This is the separate act of declaring a
     * stuck job settled, which an auditor may later lean on.
     */
    public function resolve(User $user, PrintJob $job): bool
    {
        return $this->belongsToUserOrg($user, $job) && $this->isManager($user, $job);
    }

    /**
     * Deliberately the SAME three checks {@see ReprintAdvisor} uses to decide
     * how loudly to warn about a money-document reprint. One definition of
     * "manager" across the printing domain — two subtly different ones is how a
     * shop ends up with a person the reprint dialog treats as trusted but who
     * may not admit they printed the slip by hand.
     */
    private function isManager(User $user, PrintJob $job): bool
    {
        $organizationId = (string) $job->organization_id;
        $branchId = (string) $job->branch_id;

        return $user->hasRoleInContext('shop-manager', $organizationId, $branchId)
            || $user->hasRoleInContext('org-manager', $organizationId, $branchId)
            || $user->hasRoleInContext('org-admin', $organizationId);
    }
}
