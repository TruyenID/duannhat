<?php

/**
 * DeviceSigningKey Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\DeviceSigningKey\Models\DeviceSigningKeyBaseModel;
use Database\Factories\DeviceSigningKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * DeviceSigningKey — add project-specific model logic here.
 */
class DeviceSigningKey extends DeviceSigningKeyBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DeviceSigningKeyFactory
    {
        return DeviceSigningKeyFactory::new();
    }

    //
}
