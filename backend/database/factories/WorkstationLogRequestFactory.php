<?php

namespace Database\Factories;

use App\Models\WorkstationLogRequest;
use App\Omnify\Enums\WorkstationLogRequestStatusEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * WorkstationLogRequest Factory
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * @extends Factory<WorkstationLogRequest>
 */
class WorkstationLogRequestFactory extends Factory
{
    protected $model = WorkstationLogRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    /**
     * Trạng thái mặc định là một yêu cầu **đang treo, chưa hết hạn** — đúng ca
     * mà mọi đường code quan tâm.
     *
     * Bản generator phát `status` NGẪU NHIÊN trong ba giá trị và `expires_at`
     * ngẫu nhiên quanh hiện tại. Trên một bảng có máy trạng thái, đó là một
     * factory tự sinh ra ba kịch bản khác nhau ở ba lượt chạy — loại flake chỉ
     * lộ ra sau khi CI đỏ vài lần rồi lại xanh. Ca `fulfilled`/`expired` khai
     * bằng state tường minh bên dưới.
     */
    public function definition(): array
    {
        return [
            'device_id' => (string) Str::uuid(),
            'branch_id' => (string) Str::uuid(),
            'organization_id' => (string) Str::uuid(),
            'requested_by_user_id' => (string) Str::uuid(),
            'window_from' => CarbonImmutable::now('UTC')->subHours(6),
            'window_to' => CarbonImmutable::now('UTC'),
            'max_records' => 2000,
            'status' => WorkstationLogRequestStatusEnum::Pending->value,
            'expires_at' => CarbonImmutable::now('UTC')->addDay(),
            'fulfilled_at' => null,
            'received_count' => 0,
            'rejected_count' => 0,
        ];
    }

    /** Đã trả lời xong. `fulfilled_at` bắt buộc có — đó là thứ phân biệt nó với `expired`. */
    public function fulfilled(int $received = 0): self
    {
        return $this->state(fn (): array => [
            'status' => WorkstationLogRequestStatusEnum::Fulfilled->value,
            'fulfilled_at' => CarbonImmutable::now('UTC'),
            'received_count' => $received,
        ]);
    }

    /** Hết hạn mà không ai trả lời: máy tắt / mất mạng / bản cũ chưa biết endpoint. */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => WorkstationLogRequestStatusEnum::Expired->value,
            'expires_at' => CarbonImmutable::now('UTC')->subHour(),
        ]);
    }

    /** Còn `pending` nhưng đồng hồ đã vượt hạn — khoảng giữa hai lượt quét. */
    public function stale(): self
    {
        return $this->state(fn (): array => [
            'status' => WorkstationLogRequestStatusEnum::Pending->value,
            'expires_at' => CarbonImmutable::now('UTC')->subHour(),
        ]);
    }
}
