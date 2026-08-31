<?php

namespace App\Contracts;

/**
 * Marker interface for models that can be recipients of a Notification.
 *
 * `NotificationService::dispatch` validates every resolved recipient with
 * `instanceof \App\Contracts\Notifiable` before writing recipient rows. This
 * catches misuse at the service layer (e.g. passing an Allergen as a
 * recipient) independently of the morph map's `enforceMorphMap` check.
 *
 * Implemented by `User` and `Device`. Both also use the
 * `App\Models\Concerns\ReceivesNotifications` trait which provides the
 * `notificationInbox()` + `unreadNotifications()` morphMany relations.
 *
 * NOTE: deliberately NOT an alias of Laravel's built-in
 * `Illuminate\Notifications\Notifiable` trait. This contract is about
 * recipient eligibility for the project's `NotificationService`, not about
 * Laravel's own `Notification` dispatcher.
 */
interface Notifiable
{
    // Marker interface — no methods required.
}
