<?php

namespace App\Services\Customer\Enums;

enum CustomerAuthorityOperation: string
{
    case Archive = 'archive';
    case Restore = 'restore';
    case IssueToken = 'issue_token';
    case RevokeToken = 'revoke_token';
    case GlobalProfile = 'global_profile';
    case TenantProfile = 'tenant_profile';
    case VerifyEmail = 'verify_email';
    case LinkScope = 'link_scope';
    case UnlinkScope = 'unlink_scope';
    case Merge = 'merge';
    case ChangeCredentials = 'change_credentials';
}
