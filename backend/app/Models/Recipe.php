<?php

/**
 * Recipe Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Concerns\HasApprovalWorkflow;
use App\Contracts\Approvable;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Modules\Recipe\Models\RecipeBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Recipe — add project-specific model logic here.
 */
class Recipe extends RecipeBaseModel implements Approvable
{
    use AuditsActivity;
    use HasApprovalWorkflow;
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RecipeFactory
    {
        return RecipeFactory::new();
    }

    /**
     * Recipe's `approval_status` column stores ApprovalStatusEnum directly,
     * so the contract methods are pass-through. Casts on the base model
     * deliver the enum instance; fall back to ::from() for raw strings
     * (e.g. when hydrated from a fresh insert before refresh()).
     */
    public function getApprovalStatus(): ApprovalStatusEnum
    {
        return $this->approval_status instanceof ApprovalStatusEnum
            ? $this->approval_status
            : ApprovalStatusEnum::from($this->approval_status);
    }

    public function setApprovalStatus(ApprovalStatusEnum $status): void
    {
        $this->approval_status = $status->value;
    }
}
