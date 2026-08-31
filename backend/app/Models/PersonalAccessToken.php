<?php

/**
 * PersonalAccessToken Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PersonalAccessToken\Models\PersonalAccessTokenBaseModel;
use Database\Factories\PersonalAccessTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PersonalAccessToken — add project-specific model logic here.
 */
class PersonalAccessToken extends PersonalAccessTokenBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PersonalAccessTokenFactory
    {
        return PersonalAccessTokenFactory::new();
    }

    //
}
