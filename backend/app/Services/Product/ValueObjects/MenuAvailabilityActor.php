<?php

namespace App\Services\Product\ValueObjects;

use App\Omnify\Enums\MenuAvailabilitySourceEnum;

/**
 * plan-056 — WHO turned a dish on or off, and through which door.
 *
 * A value object rather than three loose arguments because the three travel
 * together through five call sites and one of them is easy to get wrong: the
 * WORKSTATION door carries a device token, so `$request->user()` there is null
 * and the acting cashier's identity arrives in the request BODY instead. A
 * `?string $userId` parameter sitting next to `?string $name` invites passing
 * the body value straight through; `fromWorkstation()` makes the vetting step
 * (does this user belong to the branch's org?) a decision the caller has to
 * make out loud.
 *
 * `name` is a SNAPSHOT, copied onto `menu_products.disabled_by_name` and
 * `menu_availability_events.actor_name`. It is deliberately not a join: staff
 * leave, and a report that says "tắt bởi (đã xoá)" answers nobody's question.
 */
final readonly class MenuAvailabilityActor
{
    private function __construct(
        public MenuAvailabilitySourceEnum $source,
        public ?string $userId,
        public ?string $name,
    ) {}

    /** Cashier on pos-web talking straight to Cloud (cloud mode / no workstation). */
    public static function fromPos(?string $userId, ?string $name): self
    {
        return new self(MenuAvailabilitySourceEnum::Pos, $userId, self::trimName($name));
    }

    /** Manager on admin-web, through the existing shop toggle endpoints. */
    public static function fromAdmin(?string $userId, ?string $name): self
    {
        return new self(MenuAvailabilitySourceEnum::Admin, $userId, self::trimName($name));
    }

    /**
     * A workstation replaying a LAN write with its device token.
     *
     * `$userId` MUST already have been vetted against the branch's organization
     * by the caller — the device token proves the terminal, never the person at
     * it. Pass null when vetting fails; the event still records `actor_name` so
     * the shop can read who the terminal said it was, without Cloud asserting
     * an identity it could not verify.
     */
    public static function fromWorkstation(?string $vettedUserId, ?string $name): self
    {
        return new self(MenuAvailabilitySourceEnum::Workstation, $vettedUserId, self::trimName($name));
    }

    /** Seeders, console commands, tests — nobody to name. */
    public static function system(): self
    {
        return new self(MenuAvailabilitySourceEnum::Admin, null, null);
    }

    /**
     * Column width is 100 (see MenuProduct.yaml). Truncating here rather than
     * validating means a long display name never turns a 422 into "the dish
     * would not turn off" — the name is metadata, the toggle is the point.
     */
    private static function trimName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 100);
    }
}
