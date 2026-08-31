<?php

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelContract;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationEmailSuppression;
use App\Models\NotificationRecipient;
use App\Models\User;
use App\Services\Notification\TemplateRenderer;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email channel (plan-012 T3.6). Renders the notification's template
 * for the recipient's locale, then Mail::to($email)->send(...). User
 * recipients only — Device recipients are skipped (no email address).
 */
class EmailChannel implements NotificationChannelContract
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function name(): string
    {
        return 'email';
    }

    public function send(Notification $notification, NotificationRecipient $recipient): DeliveryResult
    {
        $user = $this->resolveUser($recipient);
        if ($user === null) {
            return DeliveryResult::skipped('email channel only supports User recipients');
        }
        if (empty($user->email)) {
            return DeliveryResult::skipped('recipient has no email address');
        }

        // Plan-023 M4 T4.7 — suppression list short-circuit. Skip before
        // we render or touch SMTP so the cheapest path is taken on every
        // attempt against a known-bad address. Ignore rows whose
        // un_suppressed_at is set — those have been admin-cleared.
        $isSuppressed = NotificationEmailSuppression::query()
            ->where('organization_id', $notification->organization_id)
            ->where('email', strtolower((string) $user->email))
            ->whereNull('un_suppressed_at')
            ->exists();
        if ($isSuppressed) {
            return DeliveryResult::skipped('recipient email is on the suppression list');
        }

        $template = $notification->template;
        if ($template === null) {
            return DeliveryResult::skipped('template not found — would render blank email');
        }

        $locale = (string) ($user->locale ?? 'ja');
        $rendered = $this->renderer->render($template, (array) ($notification->params ?? []), $locale);

        try {
            // Pass user id + notification type so the Mailable can emit a
            // signed-URL List-Unsubscribe header (RFC 8058 / issue #173 A).
            Mail::to($user->email)->send(new NotificationMail(
                $rendered['title'],
                $rendered['body'],
                (string) $user->id,
                (string) $notification->type,
            ));
        } catch (Throwable $e) {
            return DeliveryResult::failed($e->getMessage());
        }

        return DeliveryResult::sent();
    }

    private function resolveUser(NotificationRecipient $recipient): ?User
    {
        $recipient->loadMissing('recipient');
        $model = $recipient->recipient;

        return $model instanceof User ? $model : null;
    }
}
