<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationCommand;
use App\Services\Menu\Enums\MenuServiceType;

final readonly class MenuDefinitionPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $name;

    public ?string $description;

    /** @var list<MenuSchedulePayload> */
    public array $schedules;

    public ?string $masterMenuId;

    /** @var list<LocalizedText> */
    public array $translations;

    /** @param list<MenuSchedulePayload> $schedules */
    public function __construct(string $name, ?string $description, public MenuLayoutPayload $layout, array $schedules = [], public bool $master = false, public ?string $validFrom = null, public ?string $validTo = null, public int $priority = 0, public ?int $cartTimeoutMinutes = null, public ?MenuServiceType $serviceType = null, ?string $masterMenuId = null, public ?int $transitionGraceMinutes = null, array $translations = [])
    {
        $this->name = MutationCommand::safeToken($name, 'name', 255);
        $this->description = $description === null ? null : MutationCommand::safeToken($description, 'description', 4000);

        foreach ($schedules as $schedule) {
            if (! $schedule instanceof MenuSchedulePayload) {
                throw new \InvalidArgumentException('schedules must contain MenuSchedulePayload values.');
            }
        }

        $this->schedules = MutationCommand::canonicalSet($schedules, static fn (MenuSchedulePayload $schedule): string => $schedule->scheduleId, 'schedules');
        if ($priority < 0 || ($cartTimeoutMinutes !== null && ($cartTimeoutMinutes < 1 || $cartTimeoutMinutes > 1440)) || ($transitionGraceMinutes !== null && ($transitionGraceMinutes < 0 || $transitionGraceMinutes > 120))) {
            throw new \InvalidArgumentException('Menu priority, cart timeout, or sync grace is invalid.');
        }
        $this->masterMenuId = MutationCommand::nullableUuid($masterMenuId, 'masterMenuId');
        if ($master && $this->masterMenuId !== null) {
            throw new \InvalidArgumentException('A master menu cannot itself reference a master menu.');
        }
        if ($validFrom !== null && $validTo !== null && $validFrom > $validTo) {
            throw new \InvalidArgumentException('Menu validity window is invalid.');
        }
        if (($validFrom !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) !== 1) || ($validTo !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $validTo) !== 1)) {
            throw new \InvalidArgumentException('Menu validity dates must use YYYY-MM-DD.');
        }
        foreach ($translations as $translation) {
            if (! $translation instanceof LocalizedText) {
                throw new \InvalidArgumentException('translations must contain LocalizedText values.');
            }
        }
        $this->translations = MutationCommand::canonicalSet($translations, static fn (LocalizedText $translation): string => $translation->locale->value, 'translations');
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'layout' => $this->layout,
            'schedules' => $this->schedules,
            'master' => $this->master,
            'valid_from' => $this->validFrom,
            'valid_to' => $this->validTo,
            'priority' => $this->priority,
            'cart_timeout_minutes' => $this->cartTimeoutMinutes,
            'service_type' => $this->serviceType?->value,
            'master_menu_id' => $this->masterMenuId,
            'menu_transition_grace_minutes' => $this->transitionGraceMinutes,
            'translations' => $this->translations,
        ];
    }
}
