<?php

/**
 * PaymentPolicyRevision Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentPolicyRevision\Models\PaymentPolicyRevisionBaseModel;
use Database\Factories\PaymentPolicyRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use LogicException;

/**
 * PaymentPolicyRevision — add project-specific model logic here.
 */
class PaymentPolicyRevision extends PaymentPolicyRevisionBaseModel
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Published payment policy revisions are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Published payment policy revisions are append-only.');
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentPolicyRevisionFactory
    {
        return PaymentPolicyRevisionFactory::new();
    }

    //
}
