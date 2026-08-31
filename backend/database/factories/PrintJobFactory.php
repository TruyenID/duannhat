<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PrintJob;
use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * plan-052 — the default state is the COMMON case: a kitchen ticket that the
 * workstation already printed and journalled UP (transport ws_lan, terminal on
 * arrival, real print time stamped). States below cover the interesting rest.
 *
 * @extends Factory<PrintJob>
 */
class PrintJobFactory extends Factory
{
    protected $model = PrintJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $printedAt = now()->subMinutes(fake()->numberBetween(1, 10));

        return [
            'id' => (string) Str::uuid(),
            'organization_id' => Organization::query()->inRandomOrder()->first()?->id ?? Organization::factory()->create()->id,
            'branch_id' => Branch::query()->inRandomOrder()->first()?->id ?? Branch::factory()->create()->id,
            'printer_id' => null,
            'transport' => PrintTransport::WsLan->value,
            'kind' => PrintJobKind::Kitchen->value,
            'order_id' => null,
            'payment_id' => null,
            'payload' => ['template' => 'kitchen_ticket', 'version' => 1],
            'reprint_no' => 1,
            'requested_by_id' => null,
            'requested_via' => 'workstation',
            'reprint_reason' => null,
            'status' => PrintJobStatus::Printed->value,
            'confidence' => PrintConfidence::SentOnly->value,
            'attempts' => 1,
            'last_error' => null,
            'acked_at' => null,
            'printed_reported_at' => $printedAt,
            'expires_at' => $printedAt->copy()->addMinutes(15),
        ];
    }

    public function kind(PrintJobKind|string $kind): static
    {
        return $this->state(fn (): array => [
            'kind' => $kind instanceof PrintJobKind ? $kind->value : $kind,
        ]);
    }

    public function transport(PrintTransport|string $transport): static
    {
        return $this->state(fn (): array => [
            'transport' => $transport instanceof PrintTransport ? $transport->value : $transport,
        ]);
    }

    public function status(PrintJobStatus|string $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status instanceof PrintJobStatus ? $status->value : $status,
        ]);
    }

    /** A Cloud-owned queue row (cloudprnt) — the only mode Cloud may transition. */
    public function cloudQueued(): static
    {
        return $this->state(fn (): array => [
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'attempts' => 0,
            'printed_reported_at' => null,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['confidence' => PrintConfidence::Confirmed->value]);
    }
}
