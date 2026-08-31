<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class MenuSchedulePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $scheduleId;

    public string $startTime;

    public string $endTime;

    public ?string $masterScheduleId;

    public function __construct(string $scheduleId, public int $daysOfWeek, string $startTime, string $endTime, public int $priority, public ?string $startDate = null, public ?string $endDate = null, public bool $active = true, ?string $masterScheduleId = null)
    {
        if ($daysOfWeek < 1 || $daysOfWeek > 127 || $priority < 0
            || preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $startTime) !== 1
            || preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $endTime) !== 1) {
            throw new InvalidArgumentException('Menu schedule is invalid.');
        }

        $this->scheduleId = MutationCommand::uuid($scheduleId, 'scheduleId');
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->masterScheduleId = MutationCommand::nullableUuid($masterScheduleId, 'masterScheduleId');
        if ($startTime >= $endTime) {
            throw new InvalidArgumentException('Menu schedule time window must end after it starts.');
        }
        if (($startDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1) || ($endDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1)) {
            throw new InvalidArgumentException('Menu schedule dates must use YYYY-MM-DD.');
        }
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            throw new InvalidArgumentException('Menu schedule date window is invalid.');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'days_of_week' => $this->daysOfWeek,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'priority' => $this->priority,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'active' => $this->active,
            'master_schedule_id' => $this->masterScheduleId,
        ];
    }
}
