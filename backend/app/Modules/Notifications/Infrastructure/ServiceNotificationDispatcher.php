<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Infrastructure;

use App\Models\Brand;
use App\Models\NotificationRule;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Services\Notification\Audience;
use App\Services\Notification\NotificationService;

/**
 * Nối cổng công bố vào `NotificationService` đang có (#1568).
 *
 * Đây CHỈ là lớp dịch: dựng `Audience` từ khoá scalar, gói mảng `dispatch()`,
 * trả về id. Không có luật nghiệp vụ nào ở đây — nếu có thì nó nằm sai chỗ, vì
 * `NotificationService::dispatch` vẫn là writer duy nhất.
 *
 * `Audience` được dựng **ở đây**, bên trong module, nên nó không còn phải là
 * khái niệm mà module khác biết tới.
 */
final readonly class ServiceNotificationDispatcher implements NotificationDispatcher
{
    public function __construct(private NotificationService $service) {}

    public function toRole(
        NotificationRequest $request,
        string|array $role,
        string $scopeKey,
        string $scopeId,
        Brand $brand,
    ): string {
        // Một vai hay nhiều vai đều đi CHUNG một đường: `byRolesInScope()` với
        // một phần tử dựng ra đúng cái rule mà `byRole()->scopedToKey()` dựng.
        // Giữ hai nhánh chỉ để tiết kiệm một vòng lặp là cách tạo ra hai hình
        // dạng rule khác nhau cho cùng một ý.
        return $this->send($request, [
            'recipients' => Audience::byRolesInScope((array) $role, $scopeKey, $scopeId),
            'brand' => $brand,
        ]);
    }

    public function toRecipients(NotificationRequest $request, iterable $recipients): string
    {
        return $this->send($request, ['recipients' => $recipients]);
    }

    public function coversEmitter(string $modelAlias, string $triggerEvent, string $organizationId): bool
    {
        // Vẫn là lớp dịch: luật "thế nào là che phủ" ở lại
        // `NotificationRule::hasActiveCoverage()`, chỗ nó vốn sống.
        return NotificationRule::hasActiveCoverage($modelAlias, $triggerEvent, $organizationId);
    }

    /** @param array<string, mixed> $extra */
    private function send(NotificationRequest $request, array $extra): string
    {
        $input = [
            // `template_key` mặc định bằng `type` — bảy caller đo được ở #1568
            // đều thế, nên module tự suy ra thay vì bắt truyền hai lần cùng một
            // giá trị. Caller nào cần một bản copy khác cho CÙNG sự kiện thì
            // khai `templateKey` (#2754); `type` giữ nguyên để mọi bộ lọc theo
            // type vẫn thấy nó.
            'type' => $request->type,
            'template_key' => $request->resolvedTemplateKey(),
            'params' => $request->params,
            'actor' => $request->actor,
            'subject' => $request->subject,
            'organization_id' => $request->organizationId,
        ] + $extra;

        if ($request->idempotencyKey !== null) {
            $input['idempotency_key'] = $request->idempotencyKey;
        }
        if ($request->priority !== null) {
            $input['priority'] = $request->priority;
        }
        if ($request->aggregationKey !== null) {
            $input['aggregation_key'] = $request->aggregationKey;
        }

        return (string) $this->service->dispatch($input)->id;
    }
}
