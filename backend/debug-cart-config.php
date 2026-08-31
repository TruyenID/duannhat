<?php

use App\Models\Branch;
use App\Models\Menu;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$branchSlug = $argv[1] ?? 'hn';

$branch = Branch::where('slug', $branchSlug)
    ->where('is_active', true)
    ->first();

if (! $branch) {
    echo "❌ Branch '{$branchSlug}' not found or inactive\n";
    exit(1);
}

echo "🔍 Cart Config Debug\n";
echo "====================\n\n";
echo "📍 Branch: {$branch->name} ({$branch->slug})\n";
echo "⏰ Timezone: {$branch->timezone}\n\n";

$menu = Menu::where('branch_id', $branch->id)
    ->where('status', 'Active')
    ->with('schedules')
    ->first();

if (! $menu) {
    echo "❌ No active menu for this branch\n";
    echo "\n✓ cart-config response: effective_timeout_minutes=null, current_menu_end_time=null\n";
    exit(0);
}

echo "📋 Menu: {$menu->name}\n";
echo '   Cart Timeout: '.($menu->cart_timeout_minutes ?? 'null')." min\n\n";

$now = Carbon\Carbon::now($branch->timezone ?? config('app.timezone'));
echo "⏰ Now: {$now->toDateTimeString()} ({$now->englishDayOfWeek})\n";
echo '🔢 Day Bit: '.(1 << $now->dayOfWeek)." (1 << {$now->dayOfWeek})\n\n";

$todaySchedules = $menu->schedules()
    ->where('is_active', true)
    ->whereRaw('(days_of_week & ?) > 0', [1 << $now->dayOfWeek])
    ->get();

echo "📅 Schedules matching today ({$todaySchedules->count()}):\n\n";

$currentEndTime = null;
foreach ($todaySchedules as $schedule) {
    $start = Carbon\Carbon::parse($schedule->start_time, $branch->timezone ?? config('app.timezone'));
    $end = Carbon\Carbon::parse($schedule->end_time, $branch->timezone ?? config('app.timezone'));

    $start->setDateFrom($now);
    $end->setDateFrom($now);

    if ($end->lessThan($start)) {
        $end->addDay();
    }

    $inRange = $now->between($start, $end);
    $icon = $inRange ? '✅' : '⏸️ ';

    echo "   {$icon} Schedule ID: {$schedule->id}\n";
    echo "      start_time: {$schedule->start_time}\n";
    echo "      end_time: {$schedule->end_time}\n";
    echo "      days_of_week: {$schedule->days_of_week} (binary: ".decbin($schedule->days_of_week).")\n";
    echo "      Start parsed: {$start->toDateTimeString()}\n";
    echo "      End parsed: {$end->toDateTimeString()}\n";
    echo '      In range: '.($inRange ? 'YES ✅' : 'NO ❌')."\n\n";

    if ($inRange) {
        $currentEndTime = $end->format('H:i:s');
        break;
    }
}

echo "─────────────────────────────────────────\n\n";

if ($currentEndTime) {
    echo "✅ Active schedule found → current_menu_end_time = {$currentEndTime}\n";
} else {
    echo "❌ No active schedule → current_menu_end_time = null\n";
    echo "   (Menu có thể có schedules nhưng KHÔNG khớp với giờ hiện tại)\n";
}

$effectiveTimeout = $menu->cart_timeout_minutes
    ?? $branch->cart_timeout_minutes
    ?? $branch->brand?->cart_timeout_minutes
    ?? config('cart.default_timeout_minutes');

echo "✅ effective_timeout_minutes = {$effectiveTimeout}\n\n";

echo "💡 cart-config API response:\n";
echo json_encode([
    'data' => [
        'effective_timeout_minutes' => $effectiveTimeout,
        'current_menu_end_time' => $currentEndTime,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
