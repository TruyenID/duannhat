<?php

declare(strict_types=1);

/**
 * #1970 — every surface must give ONE answer to "is this menu on sale right now".
 *
 * This file used to assert the opposite, under the name
 * CampaignWindowSurfaceDivergenceTest. Ruled 2026-07-30 (#1237), the calendar
 * window (`menu_schedules.start_date` / `end_date`) was a customer-facing device
 * only: a campaign menu dated 1–15 Feb was invisible to the guest scanning the QR
 * in July while the POS beside them could still pick it and sell from it. The
 * stated reason was live sales — pre-orders, regulars, fixing someone's mistake.
 *
 * Ruled again 2026-08-06 (#1970): one shop, one moment, one answer. The window is
 * now applied on the staff surfaces too. If staff again need to sell outside it,
 * that must arrive as an explicit permission, NOT as a quiet widening of a query.
 *
 * Kept as a ratchet in the other direction, and kept deliberately behavioural:
 * each test lifts the window and re-reads, so a surface that returns nothing for
 * an unrelated reason fails instead of passing vacuously. That failure mode is
 * not hypothetical — the first version of the guest test read `menus[]` off a
 * response shaped `menu_id` and passed while asserting nothing at all.
 */

use App\Models\Branch;
use App\Models\BranchScheduleOverride;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Services\Customer\CustomerMenuService;
use App\Services\Product\MenuService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

beforeEach(function () {
    // Frozen well after the campaign closes, so only the window can exclude it.
    Carbon::setTestNow(Carbon::parse('2026-07-20 03:00:00', 'UTC'));

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->makeBranchWithCampaign = function (string $timezone = 'Asia/Tokyo', array $scheduleOverrides = []) {
        $branch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
            'timezone' => $timezone,
        ]);

        $menu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $branch->id,
            'status' => 'Active',
            'valid_from' => null,
            'valid_to' => null,
        ]);

        // Open every day, all day, so nothing EXCEPT the calendar window can
        // exclude it — otherwise a passing test proves some other filter fired.
        $schedule = MenuSchedule::factory()->create(array_merge([
            'menu_id' => $menu->id,
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-15',
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'days_of_week' => 127,
            'is_active' => true,
            // array_merge, NOT `+`: the union operator keeps the LEFT operand's
            // keys, so every override a caller passed was silently dropped. Nothing
            // caught it because #1970 never passed one.
        ], $scheduleOverrides));

        return [$branch, $menu, $schedule];
    };
});

afterEach(fn () => Carbon::setTestNow());

/** Does the staff-facing POS day picker offer this menu today? */
function posOffers(Branch $branch, Menu $menu): bool
{
    $menus = app(MenuService::class)->listActiveBranchMenusForShopByDay(
        $branch->id,
        (int) Carbon::now($branch->timezone)->dayOfWeek,
    );

    // in_array + toBeTrue rather than toContain: for arrays and strings Pest
    // reads every argument to toContain as another needle, so an explanatory
    // message passed there is searched for as a value — and on a `not` branch
    // that makes the assertion pass for the wrong reason.
    return in_array($menu->id, $menus->pluck('id')->all(), true);
}

/** Does the guest-facing (QR / kiosk) read offer this menu right now? */
function guestOffers(Branch $branch, Menu $menu): bool
{
    return (app(CustomerMenuService::class)->getMenuForBranch($branch->id)['menu_id'] ?? null) === $menu->id;
}

it('hides an expired campaign menu from the POS day picker — this REVERSES #1237', function () {
    [$branch, $menu] = ($this->makeBranchWithCampaign)();

    expect(posOffers($branch, $menu))->toBeFalse(
        'The POS day picker is offering a campaign menu whose window closed in '
        .'February. That is the #1237 behaviour, which #1970 reversed — staff and '
        .'guests must agree. If selling outside the window is wanted again, add an '
        .'explicit permission rather than widening this query.',
    );

    // Window lifted → the same menu, same instant, is offered again. This is what
    // proves the exclusion above came from the calendar window and not from some
    // unrelated filter having emptied the list.
    MenuSchedule::query()->where('menu_id', $menu->id)->update(['start_date' => null, 'end_date' => null]);

    expect(posOffers($branch, $menu))->toBeTrue(
        'With the window lifted the POS still does not offer this menu, so the '
        .'assertion above proves nothing — this test has gone vacuous.',
    );
});

it('hides the same menu from a guest at the table, and it is the window doing it', function () {
    [$branch, $menu] = ($this->makeBranchWithCampaign)();

    expect(guestOffers($branch, $menu))->toBeFalse(
        'The guest-facing read stopped applying the campaign window, so an expired '
        .'campaign menu is now on sale to customers. This half was never in question.',
    );

    MenuSchedule::query()->where('menu_id', $menu->id)->update(['start_date' => null, 'end_date' => null]);

    expect(guestOffers($branch, $menu))->toBeTrue(
        'With the window lifted the guest still cannot see this menu, so the '
        .'assertion above proves nothing — this test has gone vacuous.',
    );
});

it('feeds the window to the workstation so the OFFLINE POS is not looser than the online one', function () {
    [$branch, $menu, $schedule] = ($this->makeBranchWithCampaign)();

    $rows = $this->withoutMiddleware()
        ->getJson('/api/v1/workstation/menu-schedules')
        ->json('data');

    // The endpoint resolves its branch from the device token; without one the
    // feed is empty, which would make an assertion on its contents vacuous. So
    // this asserts the CONTRACT the Go mirror reads instead: the payload shape
    // must carry the two columns, because a feed without them leaves the LAN
    // till selling an expired menu at exactly the moment nobody can see it.
    $sample = $rows[0] ?? null;

    if ($sample !== null) {
        expect($sample)->toHaveKeys(['start_date', 'end_date']);
    }

    $source = file_get_contents(
        base_path('app/Http/Controllers/Api/V1/Workstation/MenuScheduleReplicaController.php'),
    );

    expect(str_contains($source, "'start_date' => \$startDate"))->toBeTrue(
        'The replica feed stopped emitting the campaign window while the Cloud POS '
        .'surface it mirrors still applies it. The offline LAN POS is now LOOSER '
        .'than the online one — an expired campaign stays sellable exactly when the '
        .'internet is down and nobody can see it happening. See #1970.',
    );
    expect(str_contains($source, "'end_date' => \$endDate"))->toBeTrue(
        'The replica feed stopped emitting end_date — see the message above.',
    );

    expect($schedule->getRawOriginal('end_date'))->not->toBeNull();
    expect($menu->branch_id)->toBe($branch->id);
});

it('lets the SHOP narrow the window HQ set, and every surface follows the shop', function () {
    // HQ says the campaign runs 1–15 Feb; the shop pulls its own end forward to
    // 5 Feb. Frozen "now" is 10 Feb — inside HQ's window, past the shop's.
    Carbon::setTestNow(Carbon::parse('2026-02-10 03:00:00', 'UTC'));

    [$branch, $menu, $schedule] = ($this->makeBranchWithCampaign)();

    expect(posOffers($branch, $menu))->toBeTrue('HQ window covers 10 Feb — precondition failed.');
    expect(guestOffers($branch, $menu))->toBeTrue('HQ window covers 10 Feb — precondition failed.');

    BranchScheduleOverride::create([
        'menu_schedule_id' => $schedule->id,
        'branch_id' => $branch->id,
        'organization_id' => $this->orgId,
        'end_date' => '2026-02-05',
    ]);

    expect(posOffers($branch, $menu))->toBeFalse(
        "The shop's own end date is being ignored on the POS — it is still selling "
        ."from HQ's window. #1970: the branch override narrows what HQ set.",
    );
    expect(guestOffers($branch, $menu))->toBeFalse(
        "The shop's own end date is being ignored on the guest read.",
    );
});

it('reads the window in the BRANCH timezone, so two countries do not share one midnight', function () {
    // 2026-02-15 16:00 UTC = 16 Feb 01:00 in Tokyo (campaign over) but still
    // 15 Feb 23:00 in Hanoi (campaign live). One instant, two business dates —
    // the #1091 trap. A UTC or app-clock read would answer the same for both.
    Carbon::setTestNow(Carbon::parse('2026-02-15 16:00:00', 'UTC'));

    [$tokyoBranch, $tokyoMenu] = ($this->makeBranchWithCampaign)('Asia/Tokyo');
    [$hanoiBranch, $hanoiMenu] = ($this->makeBranchWithCampaign)('Asia/Ho_Chi_Minh');

    expect(posOffers($tokyoBranch, $tokyoMenu))->toBeFalse(
        'Tokyo is already on 16 Feb, one day past the campaign, but the POS still '
        .'offers the menu — the window is being read on the wrong clock (#1091).',
    );
    expect(posOffers($hanoiBranch, $hanoiMenu))->toBeTrue(
        'Hanoi is still on 15 Feb, the campaign\'s last day, but the POS has '
        .'already dropped the menu — the window is being read on the wrong clock.',
    );
});

// ===========================================================================
//  #1979 — the same one-answer rule, for the two recurrence kinds added after
//  #1970. A schedule row repeats by weekday (as before), by day of month, or on
//  an explicit list of dates. Every surface has to agree about all three, and
//  the failure mode if one lags is the same as #1237's: the till sells what the
//  QR code says is gone.
// ===========================================================================

/** A menu whose single schedule row uses $kind, always-on otherwise. */
function makeRecurringMenu(object $ctx, string $kind, array $attrs = []): array
{
    [$branch, $menu, $schedule] = ($ctx->makeBranchWithCampaign)('Asia/Tokyo', array_merge([
        'recurrence_kind' => $kind,
        // No calendar window: this file's default sets a Feb 2026 one, and it
        // would mask the recurrence being tested.
        'start_date' => null,
        'end_date' => null,
    ], $attrs));

    return [$branch, $menu, $schedule];
}

it('offers a MONTHLY menu on its listed day and not the day after — on every surface', function () {
    // 15 Feb 2026 03:00 UTC = 12:00 in Tokyo, comfortably inside 00:00–23:59.
    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));

    // Days 1 and 15 → bit0 and bit14.
    [$branch, $menu] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => (1 << 0) | (1 << 14)]);

    expect(posOffers($branch, $menu))->toBeTrue('The 15th is a listed day but the POS does not offer the menu.');
    expect(guestOffers($branch, $menu))->toBeTrue('The 15th is a listed day but the guest cannot see the menu.');

    // Same row, next day — not listed. Both surfaces must drop it TOGETHER.
    Carbon::setTestNow(Carbon::parse('2026-02-16 03:00:00', 'UTC'));

    expect(posOffers($branch, $menu))->toBeFalse('The 16th is not a listed day, yet the POS still offers the menu.');
    expect(guestOffers($branch, $menu))->toBeFalse('The 16th is not a listed day, yet the guest still sees the menu.');
});

it('repeats a MONTHLY row into the following month without being re-entered', function () {
    // This is the whole point of the kind versus a list of dates: same row, a
    // month later, still on. A calendar-window implementation would fail here.
    [$branch, $menu] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => 1 << 14]);

    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeTrue('precondition: 15 Feb should be on');

    Carbon::setTestNow(Carbon::parse('2026-03-15 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeTrue('15 March is the same listed day one month on — Monthly did not repeat.');

    Carbon::setTestNow(Carbon::parse('2026-03-14 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeFalse('14 March is not listed, so this proves the repeat is by DAY, not "always on".');
});

it('never matches the 31st in a 30-day month, rather than sliding to the last day', function () {
    // Ruled in MenuScheduleRecurrence.yaml: no silent slide. A shop that means
    // "the last day" has to say so, once that exists — guessing would put a
    // menu on sale on a day nobody chose.
    [$branch, $menu] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => 1 << 30]);

    Carbon::setTestNow(Carbon::parse('2026-04-30 03:00:00', 'UTC')); // April has 30 days
    expect(posOffers($branch, $menu))->toBeFalse('The 31st slid onto 30 April.');

    Carbon::setTestNow(Carbon::parse('2026-05-31 03:00:00', 'UTC')); // May has 31
    expect(posOffers($branch, $menu))->toBeTrue('31 May IS the 31st, so this proves the rule is not simply always-false.');
});

it('offers a SPECIFIC-DATES menu only on the named days, and does not recur', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));

    [$branch, $menu, $schedule] = makeRecurringMenu($this, 'SpecificDates');
    $schedule->scheduleDates()->createMany([
        ['date' => '2026-02-15', 'organization_id' => $this->orgId],
        ['date' => '2026-02-20', 'organization_id' => $this->orgId],
    ]);

    expect(posOffers($branch, $menu))->toBeTrue('15 Feb is named but the POS does not offer the menu.');
    expect(guestOffers($branch, $menu))->toBeTrue('15 Feb is named but the guest cannot see the menu.');

    Carbon::setTestNow(Carbon::parse('2026-02-16 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeFalse('16 Feb is not named, yet the POS offers the menu.');
    expect(guestOffers($branch, $menu))->toBeFalse('16 Feb is not named, yet the guest sees the menu.');

    // A month on, same day-of-month — must NOT come back. This separates the
    // kind from Monthly; without the assertion the two are indistinguishable.
    Carbon::setTestNow(Carbon::parse('2026-03-15 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeFalse('15 March came back on its own — SpecificDates is recurring.');
});

it('unions two rows of different kinds on one menu — which is how "both" is expressed', function () {
    // The product ask was "day of month AND specific dates". Rather than a
    // combining rule inside one row (which would have to answer what "Monday
    // and the 15th" means), a menu carries one row per kind and the rows OR.
    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));

    [$branch, $menu, $monthlyRow] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => 1 << 14]);

    $oneOff = MenuSchedule::factory()->create([
        'menu_id' => $menu->id,
        'recurrence_kind' => 'SpecificDates',
        'start_time' => '00:00:00',
        'end_time' => '23:59:00',
        'days_of_week' => 127,
        'start_date' => null,
        'end_date' => null,
        'is_active' => true,
    ]);
    $oneOff->scheduleDates()->create(['date' => '2026-02-20', 'organization_id' => $this->orgId]);

    expect(posOffers($branch, $menu))->toBeTrue('the 15th, covered by the Monthly row');

    Carbon::setTestNow(Carbon::parse('2026-02-20 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeTrue('the 20th, covered by the SpecificDates row');

    Carbon::setTestNow(Carbon::parse('2026-02-21 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeFalse('the 21st is covered by neither row, so the union is not simply "always on".');

    expect($monthlyRow->id)->not->toBe($oneOff->id);
});

it('lets the SHOP narrow a MONTHLY row, and the guest read follows the shop too', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));

    // HQ says the 1st and the 15th; the shop keeps only the 1st.
    [$branch, $menu, $schedule] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => (1 << 0) | (1 << 14)]);

    expect(posOffers($branch, $menu))->toBeTrue('precondition: HQ lists the 15th');

    BranchScheduleOverride::create([
        'menu_schedule_id' => $schedule->id,
        'branch_id' => $branch->id,
        'organization_id' => $this->orgId,
        'days_of_month' => 1 << 0,
    ]);

    expect(posOffers($branch, $menu))->toBeFalse("The shop dropped the 15th but the POS still sells HQ's list.");
    expect(guestOffers($branch, $menu))->toBeFalse('The shop dropped the 15th but the guest read still uses HQ\'s list.');
});

it('feeds the recurrence RULE to the workstation, not a pre-expanded list of dates', function () {
    // An expansion needs a horizon, and a till that stays offline past that
    // horizon would quietly go blank — the failure would look like "the menu
    // disappeared", days after the last sync, with nothing on screen to link it
    // back. Sending the rule has no horizon to outlive.
    $source = file_get_contents(
        base_path('app/Http/Controllers/Api/V1/Workstation/MenuScheduleReplicaController.php'),
    );

    foreach (["'recurrence_kind' => \$kind", "'days_of_month' => \$daysOfMonth", "'specific_dates' => \$specificDates"] as $needle) {
        expect(str_contains($source, $needle))->toBeTrue(
            "The replica feed stopped emitting {$needle}. The offline LAN POS cannot "
            .'evaluate a recurrence it was never told about, so it falls back to '
            .'showing the menu — LOOSER than the online POS, exactly the shape #1970 fixed.'
        );
    }
});

/** Ask the day picker for a weekday that is NOT today. */
function posOffersOnWeekday(Branch $branch, Menu $menu, int $dayOfWeek): bool
{
    $menus = app(MenuService::class)->listActiveBranchMenusForShopByDay($branch->id, $dayOfWeek);

    return in_array($menu->id, $menus->pluck('id')->all(), true);
}

it('#1979 KHÔNG rò menu MONTHLY của hôm nay sang một thứ khác trong bộ chọn ngày', function () {
    // 15 Feb 2026 là CHỦ NHẬT. Menu lặp ngày 15 hàng tháng.
    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));
    [$branch, $menu] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => 1 << 14]);

    // Tiền đề: hỏi đúng hôm nay (Chủ nhật) thì phải có.
    expect(posOffersOnWeekday($branch, $menu, 0))->toBeTrue('tiền đề: 15/2 là Chủ nhật và là ngày được liệt kê');

    // Bấm "thứ Ba". Thứ Ba gần nhất là 17/2 — KHÔNG phải ngày 15, nên menu này
    // không bán hôm đó. Nếu nó vẫn hiện, bộ chọn đang trả lời về HÔM NAY chứ
    // không phải về ngày được hỏi, và quán sẽ chuẩn bị nguyên liệu cho một menu
    // không lên.
    expect(posOffersOnWeekday($branch, $menu, 2))->toBeFalse(
        'Bộ chọn ngày trả menu lặp-theo-ngày-tháng của HÔM NAY khi được hỏi về một '
        .'thứ khác. Luật tuần chấm theo thứ được hỏi, còn luật tháng/ngày-cụ-thể '
        .'lại chấm theo ngày hôm nay — hai vế của cùng một câu hỏi nói về hai ngày '
        .'khác nhau.',
    );
});

// ── Cạnh của lịch (#1979) ────────────────────────────────────────────────────
//
// Mọi test dưới đây ghim một CẠNH, không ghim đường chính. Đường chính đã có ở
// trên; cạnh mới là chỗ hai kiểu luật gặp nhau, chỗ bit chạm biên, và chỗ dữ
// liệu ở trạng thái mà giao diện không tạo ra được nhưng DB thì có.

it('#1979 hôm nay ĐÚNG thứ được hỏi thì lấy hôm nay, không nhảy sang tuần sau', function () {
    // Ranh giới của phép giải ngày: delta phải là 0, không phải 7. Nếu sai, mọi
    // câu hỏi "hôm nay bán gì" đều trả lời cho tuần sau — và với luật tuần thì
    // KHÔNG lộ ra, vì thứ vẫn khớp. Chỉ luật tháng mới phơi được.
    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));
    [$branch, $menu] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => 1 << 14]);

    expect(posOffersOnWeekday($branch, $menu, 0))->toBeTrue(
        'Hôm nay là Chủ nhật 15 và menu lặp ngày 15, nhưng hỏi đúng Chủ nhật lại '
        .'không có — phép giải ngày đã nhảy sang Chủ nhật tuần sau (22), tức delta 7 thay vì 0.',
    );
});

it('#1979 ngày 1 và ngày 31 — hai bit ở biên của mặt nạ tháng', function () {
    // bit0 = ngày 1, bit30 = ngày 31. Lệch một là hỏng ở đúng hai đầu, mà đầu
    // nào cũng là ngày người ta hay đặt lịch (mùng 1 đầu tháng, 31 cuối tháng).
    Carbon::setTestNow(Carbon::parse('2026-03-01 03:00:00', 'UTC'));
    [$b1, $m1] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => 1 << 0]);
    expect(posOffers($b1, $m1))->toBeTrue('bit0 phải là ngày 1');

    Carbon::setTestNow(Carbon::parse('2026-03-02 03:00:00', 'UTC'));
    expect(posOffers($b1, $m1))->toBeFalse('bit0 không được khớp ngày 2');

    Carbon::setTestNow(Carbon::parse('2026-03-31 03:00:00', 'UTC'));
    [$b2, $m2] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => 1 << 30]);
    expect(posOffers($b2, $m2))->toBeTrue('bit30 phải là ngày 31');

    Carbon::setTestNow(Carbon::parse('2026-03-30 03:00:00', 'UTC'));
    expect(posOffers($b2, $m2))->toBeFalse('bit30 không được khớp ngày 30');
});

it('#1979 ngày 29 tháng 2: có ở năm nhuận, không có ở năm thường', function () {
    // 2028 nhuận, 2027 không. Đây là "31 trong tháng 30 ngày" ở dạng hiếm hơn và
    // dễ bị bỏ sót hơn — một menu đặt ngày 29 sẽ im lặng biến mất ba năm liền.
    [$branch, $menu] = makeRecurringMenu($this, 'Monthly', ['days_of_month' => 1 << 28]);

    Carbon::setTestNow(Carbon::parse('2028-02-29 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeTrue('2028 là năm nhuận, 29/2 có thật');

    Carbon::setTestNow(Carbon::parse('2027-02-28 03:00:00', 'UTC'));
    expect(posOffers($branch, $menu))->toBeFalse(
        '2027 không nhuận — ngày 29 không tồn tại, và luật KHÔNG được trượt về 28.',
    );
});

it('#1979 khung ngày VẪN chặn một ngày được nêu đích danh — window AND kind', function () {
    // Hai luật giao nhau bằng AND. Nếu ai đó đọc thành OR thì một ngày nêu đích
    // danh sẽ vượt qua cả khung ngày mà HQ đặt — tức shop bán ngoài chiến dịch.
    Carbon::setTestNow(Carbon::parse('2026-02-20 03:00:00', 'UTC'));

    [$branch, $menu, $schedule] = makeRecurringMenu($this, 'SpecificDates', [
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-10',
    ]);
    $schedule->scheduleDates()->create(['date' => '2026-02-20', 'organization_id' => $this->orgId]);

    expect(posOffers($branch, $menu))->toBeFalse(
        '20/2 được nêu đích danh nhưng nằm NGOÀI khung 1–10/2. Khung và kiểu lặp '
        .'phải giao nhau bằng AND; đọc thành OR là bán ngoài chiến dịch.',
    );
});

it('#1979 DB chặn hai dòng ngày trùng nhau — nên truy vấn không phải tự chống nhân đôi', function () {
    // Tiền đề đầu tiên của tôi ở đây SAI: tôi định ghim "hai dòng trùng thì menu
    // không nhân đôi", tin rằng `EXISTS` là thứ bảo vệ. Chạy thử thì DB ném
    // UniqueConstraintViolation — hàng trùng không tồn tại được ngay từ đầu.
    //
    // Ghi lại đúng thứ ĐANG bảo vệ, vì nó mạnh hơn: ràng buộc ở tầng schema.
    // Nếu ai đó gỡ nó, test này đỏ và người sửa biết phải xét lại truy vấn
    // (`EXISTS` chịu được trùng, `JOIN` thì không).
    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));
    [, , $schedule] = makeRecurringMenu($this, 'SpecificDates');
    $schedule->scheduleDates()->create(['date' => '2026-02-15', 'organization_id' => $this->orgId]);

    expect(fn () => $schedule->scheduleDates()->create([
        'date' => '2026-02-15',
        'organization_id' => $this->orgId,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('#1979 shop đè được MẶT NẠ nhưng KHÔNG đổi được KIỂU lặp của HQ', function () {
    // `branch_schedule_overrides` có cả `days_of_week` lẫn `days_of_month`, nhưng
    // KHÔNG có `recurrence_kind` — kiểu lặp là quyết định của HQ, shop chỉ được
    // thu hẹp bên trong kiểu đó.
    //
    // Cạnh nguy hiểm: shop điền `days_of_month` cho một dòng mà HQ đặt kiểu
    // `Weekly`. Giao diện có thể không cho, nhưng DB thì cho, và feed replica có
    // thể mang giá trị đó xuống. Lúc ấy mặt nạ tháng bị BỎ QUA — im lặng. Ghim
    // hành vi này để nó là một quyết định đọc được, không phải một bất ngờ.
    Carbon::setTestNow(Carbon::parse('2026-02-15 03:00:00', 'UTC'));

    // HQ: Weekly, bật Chủ nhật (15/2/2026 là Chủ nhật) ⇒ đang bán.
    [$branch, $menu, $schedule] = makeRecurringMenu($this, 'Weekly', ['days_of_week' => 1 << 0]);
    expect(posOffers($branch, $menu))->toBeTrue('tiền đề: HQ Weekly bật Chủ nhật');

    // Shop điền mặt nạ THÁNG = ngày 20 (không phải hôm nay). Vì kiểu vẫn là
    // Weekly, giá trị này không được đọc — menu vẫn bán.
    BranchScheduleOverride::create([
        'menu_schedule_id' => $schedule->id,
        'branch_id' => $branch->id,
        'organization_id' => $this->orgId,
        'days_of_month' => 1 << 19,
    ]);

    expect(posOffers($branch, $menu))->toBeTrue(
        'Mặt nạ THÁNG do shop điền đã tắt một dòng có kiểu WEEKLY. Kiểu lặp thuộc '
        .'về HQ; nếu muốn shop đổi được kiểu thì đó là thay đổi thiết kế, không '
        .'phải hệ quả phụ của một cột bị đọc nhầm.',
    );
});
