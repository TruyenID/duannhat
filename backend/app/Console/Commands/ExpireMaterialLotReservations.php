<?php

namespace App\Console\Commands;

use App\Services\Inventory\MaterialLotReservationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('material-lot-reservations:expire')]
#[Description('Expire stale material lot reservations past the 7-day TTL after expected_consume_at.')]
class ExpireMaterialLotReservations extends Command
{
    public function handle(MaterialLotReservationService $service): int
    {
        $expired = $service->expireStale();

        $this->info("Expired {$expired} stale reservation(s).");

        return self::SUCCESS;
    }
}
