<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk upsert of `notification_channel_routes` — plan-012 T3.9.
 *
 * Body shape: `{ routes: [{type, channels[], priority_overrides?}] }`
 * Each row is keyed by `(organization_id, type)`; existing rows are updated
 * in place, missing rows are inserted. Routes NOT listed in the payload are
 * left alone (no destructive sync).
 */
class UpsertChannelRoutesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validChannels = 'in:in_app,realtime,email,push';
        $validPriorities = 'in:low,normal,high,urgent';

        return [
            'routes' => ['required', 'array', 'min:1'],
            'routes.*.type' => ['required', 'string', 'max:100'],
            'routes.*.channels' => ['required', 'array', 'min:1'],
            'routes.*.channels.*' => ['string', $validChannels],
            'routes.*.priority_overrides' => ['nullable', 'array'],
            'routes.*.priority_overrides.*' => ['array'],
            'routes.*.priority_overrides.*.*' => ['string', $validChannels],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $routes = (array) $this->input('routes', []);
            foreach ($routes as $i => $route) {
                $overrides = $route['priority_overrides'] ?? null;
                if (! is_array($overrides)) {
                    continue;
                }
                foreach ($overrides as $priority => $channels) {
                    if (! in_array((string) $priority, ['low', 'normal', 'high', 'urgent'], true)) {
                        $v->errors()->add("routes.$i.priority_overrides.$priority", 'Invalid priority key.');
                    }
                }
            }
        });
    }
}
