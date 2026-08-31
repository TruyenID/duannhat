<?php

namespace App\Services\Order\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class OrderContactPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public ?string $name;

    public ?string $phone;

    public ?string $email;

    public function __construct(?string $name, ?string $phone, ?string $email)
    {
        $this->name = $name === null ? null : MutationCommand::safeToken($name, 'name', 255);
        $this->phone = $phone === null ? null : MutationCommand::safeToken($phone, 'phone', 50);
        $this->email = $email === null ? null : mb_strtolower(trim($email));
        if ($this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email must be valid.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'phone' => $this->phone, 'email' => $this->email];
    }
}
