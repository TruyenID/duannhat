<?php

namespace App\Services\Notification;

use App\Contracts\NotificationChannelContract;
use App\Omnify\Enums\NotificationChannelEnum;
use App\Services\Notification\Channels\EmailChannel;
use App\Services\Notification\Channels\InAppChannel;
use App\Services\Notification\Channels\PushChannel;
use App\Services\Notification\Channels\RealtimeChannel;
use RuntimeException;

/**
 * Maps `NotificationChannelEnum` cases → concrete channel impls
 * (plan-012 T3.6). `NotificationChannelJob` resolves the impl from this
 * registry before dispatching `send()`.
 *
 * M3 registers `in_app`, `email`, `push`. M4 T4.9 registers `realtime`
 * via `AppServiceProvider::boot` (keeps the Reverb dependency optional
 * for M3).
 */
class NotificationChannelResolver
{
    /**
     * @var array<string, NotificationChannelContract>
     */
    private array $channels = [];

    public function __construct(
        InAppChannel $inApp,
        EmailChannel $email,
        PushChannel $push,
        RealtimeChannel $realtime,
    ) {
        $this->register($inApp);
        $this->register($email);
        $this->register($push);
        $this->register($realtime);
    }

    public function register(NotificationChannelContract $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function for(NotificationChannelEnum $channel): NotificationChannelContract
    {
        $name = $channel->value;
        if (! isset($this->channels[$name])) {
            throw new RuntimeException("No NotificationChannelContract registered for `{$name}`.");
        }

        return $this->channels[$name];
    }

    /**
     * @return array<string, NotificationChannelContract>
     */
    public function all(): array
    {
        return $this->channels;
    }
}
