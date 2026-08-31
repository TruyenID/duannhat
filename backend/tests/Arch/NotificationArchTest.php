<?php

/**
 * Notification Arch Tests (plan-008 T9.5)
 *
 * Enforces structural invariants of the notification platform:
 *   1. Every Notification controller method calls `authorize()` before
 *      touching the service layer — row-level access gates must not be
 *      skipped silently.
 *   2. Every class registered in `Relation::morphMap()` / `enforceMorphMap()`
 *      exists and extends `Illuminate\Database\Eloquent\Model` — catches
 *      morph map drift after model renames or deletions.
 */

use App\Exceptions\NotificationException;
use App\Jobs\DispatchScheduledNotificationJob;
use App\Jobs\ScheduledNotificationHealthCheckJob;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\User;
use App\Omnify\Enums\NotificationChannelEnum;
use App\Services\Notification\Audience;
use App\Services\Notification\AudienceResolverService;
use App\Services\Notification\NotificationChannelResolver;
use App\Services\Notification\NotificationService;
use Database\Seeders\SystemNotificationTemplateSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

test('every class in the morph map exists and extends Model', function () {
    // Triggers OmnifyServiceProvider::boot → enforceMorphMap registration.
    (new User)->getMorphClass();

    $map = Relation::morphMap();

    expect($map)->not->toBeEmpty();

    $offenders = [];
    foreach ($map as $alias => $className) {
        if (! class_exists($className)) {
            $offenders[] = "{$alias} => {$className} (class does not exist)";

            continue;
        }
        if (! is_subclass_of($className, Model::class)) {
            $offenders[] = "{$alias} => {$className} (not an Eloquent Model subclass)";
        }
    }

    expect($offenders)->toBeEmpty(
        'Morph map drift detected:'.PHP_EOL.implode(PHP_EOL, $offenders),
    );
});

test('Notification + NotificationRecipient are registered in the morph map', function () {
    (new User)->getMorphClass();

    $map = Relation::morphMap();
    $classes = array_values($map);

    expect($classes)->toContain(Notification::class)
        ->and($classes)->toContain(NotificationRecipient::class)
        ->and($classes)->toContain(User::class);
});

test('every public Notification controller method calls authorize()', function () {
    $files = [
        app_path('Http/Controllers/Api/V1/Me/NotificationController.php'),
        app_path('Http/Controllers/Api/V1/HQ/NotificationAdminController.php'),
    ];

    $offenders = [];

    foreach ($files as $file) {
        $src = file_get_contents($file);

        // Match public functions that are HTTP action methods (not __construct
        // and not private helpers). Skip the `resolveRecipientRow` private
        // helper used by single-row mutations.
        preg_match_all(
            '/public function ([a-zA-Z0-9_]+)\s*\([^)]*\)[^{]*\{([^}]*(?:\{[^}]*\}[^}]*)*)\}/s',
            $src,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as [$full, $method, $body]) {
            if ($method === '__construct') {
                continue;
            }

            // Skip the single-row mutation methods that delegate the auth
            // check to resolveRecipientRow() → forRecipient()->firstOrFail()
            // (404 existence-hide pattern instead of 403).
            if (in_array($method, ['seen', 'read', 'dismissed'], true)) {
                if (! str_contains($body, 'resolveRecipientRow')) {
                    $offenders[] = basename($file).'::'.$method;
                }

                continue;
            }

            if (! str_contains($body, 'authorize(')) {
                $offenders[] = basename($file).'::'.$method;
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'Notification controller methods missing authorize():'.PHP_EOL
        .implode(PHP_EOL, $offenders),
    );
});

test('NotificationService rejects non-Notifiable recipients', function () {
    expect(fn () => app(NotificationService::class)->dispatch([
        'type' => 'test',
        'recipients' => [new stdClass],
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]))->toThrow(NotificationException::class);
});

test('every Phase A type in NotificationService::DEFAULT_PRIORITIES has a seeded system template (plan-012 T2.6)', function () {
    $reflection = new ReflectionClass(NotificationService::class);
    $defaults = $reflection->getConstant('DEFAULT_PRIORITIES') ?? [];
    $types = array_keys($defaults);

    $seederReflection = new ReflectionClass(SystemNotificationTemplateSeeder::class);
    $templatesMethod = $seederReflection->getMethod('templates');
    $templatesMethod->setAccessible(true);
    $seededKeys = array_column($templatesMethod->invoke(null), 'key');

    // #3188 — TRƯỚC ĐÂY đây là danh sách CHO PHÉP: chỉ năm type được kiểm, mọi
    // type khác `continue` qua. Nên ba type thêm sau (#2696/#2697) rơi qua trong
    // im lặng, và hai trong số đó không có template nào.
    //
    // Cái giá đo được: `payment.paypay_qr_unbookable` đã gửi THẬT bốn lần trên
    // production (13/08 → 17/08) với `snapshot=KHÔNG` — bốn thông báo không mang
    // nội dung, vì `snapshotTemplateContent()` là no-op khi thiếu template. Đối
    // chứng cùng lúc: `till.unresolved_orders` có template ⇒ `snapshot=CÓ`.
    //
    // Nay đảo chiều: kiểm MỌI type, và thứ đứng ngoài phải KHAI ra kèm lý do.
    // Danh sách cho phép im lặng khi có type mới; danh sách loại trừ thì không.
    $deliberatelyUnseeded = [
        'order.paid' => 'khai trong DEFAULT_PRIORITIES nhưng chưa có emitter nào phát — không có gì để render',
        'system.critical' => 'kênh thoát hiểm cho sự cố hạ tầng; nội dung do nơi phát tự đặt, không đi qua template',
    ];

    $missing = [];
    foreach ($types as $type) {
        if (array_key_exists($type, $deliberatelyUnseeded)) {
            continue;
        }
        if (! in_array($type, $seededKeys, true)) {
            $missing[] = $type;
        }
    }

    expect($missing)->toBeEmpty(
        'Type trong DEFAULT_PRIORITIES mà KHÔNG có template được seed: '.implode(', ', $missing)
        .'. Thông báo vẫn ra đời nhưng không có nội dung nào được chốt lại — thêm template vào '
        .'SystemNotificationTemplateSeeder, hoặc khai vào $deliberatelyUnseeded kèm lý do đo được.',
    );

    // Ratchet: một miễn trừ chỉ được sống chừng nào type nó miễn trừ CÒN tồn
    // tại. Không có vế này, danh sách chỉ có thể dài ra — và một entry hết ứng
    // là giấy phép cấp sẵn cho đúng cái tên ấy nếu ai đó dùng lại nó sau này.
    $stale = array_diff(array_keys($deliberatelyUnseeded), $types);
    expect($stale)->toBeEmpty(
        'Loại trừ trỏ vào type KHÔNG còn trong DEFAULT_PRIORITIES: '.implode(', ', $stale)
        .'. Gỡ entry đi.',
    );

    // Mẫu số bằng không có ba nguồn, và một trong số đó là "không hàng nào
    // thuộc diện được hỏi". Nếu hằng số đổi tên hoặc reflection hỏng, bài này
    // phải ĐỎ chứ không được im rồi báo sạch.
    expect(count($types))->toBeGreaterThanOrEqual(
        5,
        'DEFAULT_PRIORITIES chỉ còn '.count($types).' type — hằng số đổi tên hay reflection hỏng? '
        .'Sửa bài test, đừng xoá nó.',
    );
});

test('every NotificationChannelContract impl has a matching enum case and is registered (plan-012 T3.6)', function () {
    $resolver = app(NotificationChannelResolver::class);
    $registered = array_keys($resolver->all());
    $enumValues = array_map(fn ($case) => $case->value, NotificationChannelEnum::cases());

    // Every registered impl must correspond to an enum case.
    foreach ($registered as $name) {
        expect($enumValues)->toContain($name);
    }

    $required = ['in_app', 'realtime', 'email', 'push'];
    foreach ($required as $name) {
        expect($registered)->toContain($name);
    }
});

test('DispatchScheduledNotificationJob implements ShouldQueue and ScheduledNotificationHealthCheckJob is registered in routes/console.php (plan-012 T4.14)', function () {
    expect((new ReflectionClass(DispatchScheduledNotificationJob::class))
        ->implementsInterface(ShouldQueue::class))->toBeTrue();
    expect((new ReflectionClass(ScheduledNotificationHealthCheckJob::class))
        ->implementsInterface(ShouldQueue::class))->toBeTrue();

    $console = file_get_contents(base_path('routes/console.php'));
    expect($console)->toContain('ScheduledNotificationHealthCheckJob');
    expect($console)->toContain('->hourly()');
});

test('Brand model casts reverb_app_secret as encrypted (plan-012 T4.2)', function () {
    $refl = new ReflectionMethod(Brand::class, 'casts');
    $refl->setAccessible(true);
    $casts = $refl->invoke(new Brand);
    expect($casts)->toHaveKey('reverb_app_secret');
    expect($casts['reverb_app_secret'])->toBe('encrypted');
});

test('NotificationChannelEnum has exactly 4 cases; sms deliberately absent (plan-012 T3.1)', function () {
    $values = array_map(fn ($case) => $case->value, NotificationChannelEnum::cases());
    expect($values)->toEqualCanonicalizing(['in_app', 'realtime', 'email', 'push']);
    expect($values)->not->toContain('sms');
});

test('every Audience static factory maps to a registered AudienceResolver (plan-012 T1.3)', function () {
    $resolverTypes = array_keys(app(AudienceResolverService::class)->resolvers());

    $drift = [];
    foreach (Audience::FACTORY_TO_RESOLVER_TYPE as $factory => $expectedType) {
        if (! in_array($expectedType, $resolverTypes, true)) {
            $drift[] = "Audience::{$factory}() → type `{$expectedType}` — not registered in AudienceResolverService::resolvers";
        }
    }

    expect($drift)->toBeEmpty(
        'Factory/resolver drift — every Audience helper must map to a registered resolver:'.PHP_EOL
        .implode(PHP_EOL, $drift),
    );
});
