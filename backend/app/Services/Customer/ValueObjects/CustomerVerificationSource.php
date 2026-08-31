<?php

namespace App\Services\Customer\ValueObjects;

enum CustomerVerificationSource: string
{
    case SignedEmailLink = 'signed_email_link';
    case TrustedAdmin = 'trusted_admin';
    case VerifiedImport = 'verified_import';
}
