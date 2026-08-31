<?php

/**
 * PaymentAttempt Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentAttempt\Models\PaymentAttemptBaseModel;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PaymentAttempt — add project-specific model logic here.
 */
class PaymentAttempt extends PaymentAttemptBaseModel
{
    use HasFactory;

    /**
     * Additional fillable attributes not in the Omnify base model.
     *
     * `estimated_fee_minor` (plan-050 L1, #1155) is a DASHBOARD-ONLY
     * gateway fee estimate — never authoritative; booked fees live in
     * payment_settlements.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->fillable = array_merge($this->fillable, [
            'estimated_fee_minor',
        ]);
    }

    /**
     * Additional casts not in the Omnify base model.
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'estimated_fee_minor' => 'integer',
        ]);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentAttemptFactory
    {
        return PaymentAttemptFactory::new();
    }

    //
}
