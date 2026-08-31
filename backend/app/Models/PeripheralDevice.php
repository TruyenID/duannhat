<?php

/**
 * Peripheral Device model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use Database\Factories\PeripheralDeviceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PeripheralDevice extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'peripheral_devices';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PeripheralDeviceFactory
    {
        return PeripheralDeviceFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'type',
        'is_active',
        'metadata',
        'secret',
        'registered_by_device_id',
        'organization_id',
        'branch_id',
    ];

    /**
     * The attributes hidden in JSON serialization.
     */
    protected $hidden = [
        'secret',
    ];

    /**
     * Type casting for persistence.
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
