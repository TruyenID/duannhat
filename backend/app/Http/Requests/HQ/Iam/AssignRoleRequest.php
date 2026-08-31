<?php

declare(strict_types=1);

namespace App\Http\Requests\HQ\Iam;

use App\Models\Branch;
use App\Models\Role;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization done in controller via hasPermission
    }

    public function rules(): array
    {
        return [
            'role_slug' => ['required', 'string', 'exists:roles,slug'],
            'branch_id' => ['nullable', 'string', 'uuid', function (string $attr, mixed $value, Closure $fail) {
                if ($value === null) {
                    return;
                }

                $org = request()->attributes->get('organization');

                // Branch must belong to this organization (via console_organization_id).
                $exists = Branch::where('id', $value)
                    ->where('console_organization_id', $org?->console_organization_id)
                    ->exists();

                if (! $exists) {
                    $fail('The branch does not belong to this organization.');
                }
            }],
        ];
    }

    /**
     * Resolve the role to assign, preferring the caller's own org-scoped copy over the
     * global template of the same slug (plan-fix-issue-847). Once an org forks a system
     * role, newly-assigned members must inherit the org's customized copy — not the
     * pristine template — otherwise two "org-admin"s in one org would diverge.
     */
    public function role(): Role
    {
        $callerConsoleOrgId = $this->attributes->get('organization')?->console_organization_id;

        return Role::where('slug', $this->validated('role_slug'))
            ->where(function ($query) use ($callerConsoleOrgId) {
                $query->where('console_organization_id', $callerConsoleOrgId)
                    ->orWhereNull('console_organization_id');
            })
            ->orderByRaw('console_organization_id IS NULL') // org-scoped row first, template last
            ->firstOrFail();
    }
}
