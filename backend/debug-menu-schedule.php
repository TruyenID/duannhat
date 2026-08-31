#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Menu;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;

echo "\n🔍 Menu Schedule Debug Tool\n";
echo "============================\n\n";

// Get branch slug from argument
$branchSlug = $argv[1] ?? null;
if (! $branchSlug) {
    echo "❌ Usage: php debug-menu-schedule.php <branch-slug>\n";
    echo "Example: php debug-menu-schedule.php hq\n\n";
    exit(1);
}

$branch = Branch::where('slug', $branchSlug)->first();
if (! $branch) {
    echo "❌ Branch not found: {$branchSlug}\n\n";
    exit(1);
}

$timezone = $branch->timezone ?? config('app.timezone', 'UTC');
$now = Carbon::now($timezone);
$currentTime = $now->format('H:i:s');
$dayOfWeek = $now->dayOfWeek; // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
$dayBit = 1 << $dayOfWeek;

$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

echo "📍 Branch: {$branch->name} ({$branch->slug})\n";
echo "⏰ Timezone: {$timezone}\n";
echo "📅 Current Time: {$now->format('Y-m-d H:i:s')} ({$dayNames[$dayOfWeek]})\n";
echo "🔢 Day Bit: {$dayBit} (1 << {$dayOfWeek})\n\n";

echo "─────────────────────────────────────────────\n\n";

// Find all menus for this branch
$menus = Menu::where('branch_id', $branch->id)
    ->with(['schedules' => fn ($q) => $q->orderBy('priority')])
    ->get();

if ($menus->isEmpty()) {
    echo "❌ No menus found for branch {$branch->slug}\n\n";
    exit(0);
}

echo "📋 Found {$menus->count()} menu(s)\n\n";

foreach ($menus as $menu) {
    $isActive = $menu->status === 'Active';
    $statusIcon = $isActive ? '✅' : '❌';

    echo "{$statusIcon} Menu: {$menu->name}\n";
    echo "   ID: {$menu->id}\n";
    echo "   Status: {$menu->status}\n";
    echo "   Priority: {$menu->priority}\n";
    echo '   Cart Timeout: '.($menu->cart_timeout_minutes ?? 'null')." min\n";

    if ($menu->schedules->isEmpty()) {
        echo "   📅 Schedules: None (Always Available)\n";
    } else {
        echo "   📅 Schedules ({$menu->schedules->count()}):\n";

        foreach ($menu->schedules as $idx => $schedule) {
            $scheduleActive = $schedule->is_active;
            $matchesDay = ($schedule->days_of_week & $dayBit) > 0;
            $matchesTime = $schedule->start_time <= $currentTime && $schedule->end_time >= $currentTime;
            $isCurrentlyActive = $scheduleActive && $matchesDay && $matchesTime;

            $icon = $isCurrentlyActive ? '✅' : '⏸️';

            $num = $idx + 1;
            echo "\n      {$icon} Schedule #{$num} (ID: {$schedule->id})\n";
            echo '         is_active: '.($scheduleActive ? 'YES ✅' : 'NO ❌')."\n";
            echo "         priority: {$schedule->priority}\n";
            echo "         start_time: {$schedule->start_time}\n";
            echo "         end_time: {$schedule->end_time}\n";
            echo "         days_of_week: {$schedule->days_of_week} (binary: ".decbin($schedule->days_of_week).")\n";

            // Decode days
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                if (($schedule->days_of_week & (1 << $i)) > 0) {
                    $days[] = $dayNames[$i];
                }
            }
            echo '         → Active on: '.implode(', ', $days)."\n";

            echo "         ✓ Matches today ({$dayNames[$dayOfWeek]})? ".($matchesDay ? 'YES ✅' : 'NO ❌')."\n";
            echo "         ✓ Current time ({$currentTime}) in range? ".($matchesTime ? 'YES ✅' : 'NO ❌')."\n";

            if (! $scheduleActive) {
                echo "         ⚠️  Schedule is DISABLED (is_active = false)\n";
            }
            if (! $matchesDay) {
                echo "         ⚠️  Today is NOT in days_of_week bitmask\n";
            }
            if (! $matchesTime) {
                echo "         ⚠️  Current time is OUTSIDE the time window\n";
            }
        }
    }

    echo "\n";
    echo "   🔍 Would this menu be returned by CustomerMenuService?\n";

    if (! $isActive) {
        echo "      ❌ NO — Menu status is not 'Active'\n";
    } elseif ($menu->schedules->isEmpty()) {
        echo "      ✅ YES — Menu has no schedules (always available)\n";
    } else {
        $hasActiveSchedule = $menu->schedules->first(function ($schedule) use ($dayBit, $currentTime) {
            return $schedule->is_active
                && ($schedule->days_of_week & $dayBit) > 0
                && $schedule->start_time <= $currentTime
                && $schedule->end_time >= $currentTime;
        });

        if ($hasActiveSchedule) {
            echo "      ✅ YES — Has at least 1 active schedule matching current time\n";
        } else {
            echo "      ❌ NO — No active schedule matches current day/time\n";
        }
    }

    echo "\n─────────────────────────────────────────────\n\n";
}

echo "💡 Tip: To test different times, use:\n";
echo "   cd backend && php artisan tinker --execute=\"Carbon\\Carbon::setTestNow('2026-05-14 14:30:00');\"\n\n";
