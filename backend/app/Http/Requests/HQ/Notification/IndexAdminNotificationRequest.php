<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // type can come as a scalar or an array — coerced below.
            'type' => ['nullable'],
            'type.*' => ['string', 'max:100'],
            'actor_type' => ['nullable', Rule::in(['user', 'device', 'null', 'User', 'Device'])],
            'actor_id' => ['nullable', 'uuid'],
            'recipient_type' => ['nullable', Rule::in(['user', 'device', 'User', 'Device'])],
            'recipient_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'organization_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // actor_id requires actor_type and vice versa (except actor_type='null')
            if ($this->filled('actor_id') && ! $this->filled('actor_type')) {
                $v->errors()->add('actor_type', 'actor_type is required when actor_id is provided.');
            }
            if ($this->filled('recipient_id') && ! $this->filled('recipient_type')) {
                $v->errors()->add('recipient_type', 'recipient_type is required when recipient_id is provided.');
            }
        });
    }

    /**
     * Coerce `type` scalar into an array so the service receives a consistent
     * shape regardless of `?type=x` vs `?type[]=x&type[]=y`.
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (isset($data['type']) && ! is_array($data['type'])) {
            $data['type'] = [$data['type']];
        }

        // Normalize TitleCase aliases for actor_type/recipient_type.
        foreach (['actor_type', 'recipient_type'] as $field) {
            if (isset($data[$field]) && $data[$field] !== 'null') {
                $data[$field] = ucfirst($data[$field]);
            }
        }

        if ($key !== null) {
            return data_get($data, $key, $default);
        }

        return $data;
    }
}
