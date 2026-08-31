<?php

namespace App\Contracts;

use App\Omnify\Enums\ApprovalStatusEnum;

/**
 * Models that participate in the generic approval workflow implemented by
 * `HasApprovalWorkflow`.
 *
 * Each model maps its own status column (which may be a superset enum like
 * `ProductStatusEnum` with lifecycle extras) to the normalized 4-state
 * `ApprovalStatusEnum` through these accessors. The trait works purely
 * against the normalized enum — each model is responsible for the mapping.
 */
interface Approvable
{
    /**
     * Read the current approval state, normalized to ApprovalStatusEnum.
     *
     * Models whose status column is exactly ApprovalStatusEnum (e.g. Recipe)
     * return the column value directly. Models with a superset enum
     * (e.g. Product with active/inactive lifecycle extras) MUST project
     * their superset onto the 4 approval states.
     */
    public function getApprovalStatus(): ApprovalStatusEnum;

    /**
     * Write a normalized approval state back to the model's own column.
     * Does NOT save — caller is responsible for persistence.
     */
    public function setApprovalStatus(ApprovalStatusEnum $status): void;
}
