<?php

use App\Models\Branch;
use App\Models\Device;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

// Register the /broadcasting/auth endpoint under the SSO guard used by the
// rest of the API so admin-web's Bearer token passes (plan-012 T4.10).
// Without this the route defaults to the `web` session guard and every
// private-channel subscribe returns 403.
Broadcast::routes(['middleware' => ['sso.auth']]);

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// =========================================================================
//  plan-012 T4.10 — private channels for the NotificationReceived event.
// =========================================================================

Broadcast::channel('user.{userId}.notifications', function (User $user, string $userId) {
    return (string) $user->id === (string) $userId;
});

Broadcast::channel('device.{deviceId}.notifications', function ($authenticated, string $deviceId) {
    // Device-token subscribers authenticate via the device pairing middleware
    // and present as the Device model directly (not a User). A signed-in User
    // is always rejected on this channel.
    if (! $authenticated instanceof Device) {
        return false;
    }

    return (string) $authenticated->id === (string) $deviceId;
});

// =========================================================================
//  plan-027 T1.4 — private channel for KDS device kds-events broadcast.
// =========================================================================

Broadcast::channel('branch.{branchId}.kds-events', function ($authenticated, string $branchId) {
    if (! $authenticated instanceof Device) {
        return false;
    }
    if ($authenticated->type !== DeviceTypeEnum::Kds) {
        return false;
    }

    return (string) $authenticated->branch_id === (string) $branchId;
});

// =========================================================================
//  #1175 — workstation sync poke channel. Carries only the contentless
//  `sync.poke` hint (WorkstationSyncPoke) telling the branch's workstation
//  to run its pull tick early. Device-token subscribers authenticate via
//  /api/v1/devices/broadcasting/auth (BroadcastAuthController), which hands
//  the Device model to this callback; only an ACTIVE workstation paired to
//  that exact branch may listen.
// =========================================================================

Broadcast::channel('workstation.sync.{branchId}', function ($authenticated, string $branchId) {
    if (! $authenticated instanceof Device) {
        return false;
    }
    if ($authenticated->type !== DeviceTypeEnum::Workstation) {
        return false;
    }
    if ($authenticated->status !== DeviceStatusEnum::Active) {
        return false;
    }

    return (string) $authenticated->branch_id === (string) $branchId;
});

// =========================================================================
//  POS staff realtime — pos-web cloud-fallback channel for the OrderVoided
//  broadcast. pos-web authenticates as an SSO User (not a Device), so the
//  kds-events callback above always rejects it; this channel mirrors the
//  IAM check in ResolvesShopContext::bindShopContext — the user must hold an
//  org role on the organization that owns the branch.
// =========================================================================

Broadcast::channel('branch.{branchId}.pos-events', function ($authenticated, string $branchId) {
    if (! $authenticated instanceof User) {
        return false;
    }

    $branch = Branch::find($branchId);
    if (! $branch) {
        return false;
    }

    $organization = Organization::where('console_organization_id', $branch->console_organization_id)->first();
    if (! $organization) {
        return false;
    }

    return DB::table('role_user_pivots')
        ->where('user_id', $authenticated->id)
        ->where('organization_id', $organization->id)
        ->exists();
});
