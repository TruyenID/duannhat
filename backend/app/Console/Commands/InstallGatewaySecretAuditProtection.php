<?php

namespace App\Console\Commands;

use App\Services\Payment\Secret\GatewaySecretAuditProtection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:install-gateway-secret-audit-protection')]
#[Description('Install fail-closed UPDATE/DELETE protection for the payment gateway secret audit table')]
final class InstallGatewaySecretAuditProtection extends Command
{
    public function __construct(private readonly GatewaySecretAuditProtection $protection)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->protection->install();
        $this->components->info('Payment gateway secret audit protection is installed.');

        return self::SUCCESS;
    }
}
