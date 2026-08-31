<?php

namespace App\Http\Requests\HQ\Notification;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the admin broadcast composer payload (plan-012 T4.11).
 *
 * Body shape:
 *   {
 *     audience_id?: uuid,
 *     audience_inline?: { version, combinator, rules[], exclude? },
 *     template_id?: uuid,
 *     template_inline?: { key, content: { ja, en, vi } },
 *     params?: object,
 *     channels: ["in_app" | "realtime" | "email" | "push"],
 *     priority: "low" | "normal" | "high" | "urgent",
 *     scheduled_for?: iso8601 datetime,
 *   }
 */
class BroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audience_id' => ['nullable', 'uuid'],
            'audience_inline' => ['nullable', 'array'],
            'audience_inline.rules' => ['nullable', 'array'],
            'template_id' => ['nullable', 'uuid'],
            'template_inline' => ['nullable', 'array'],
            'template_inline.key' => ['nullable', 'string'],
            'template_inline.content' => ['nullable', 'array'],
            'params' => ['nullable', 'array'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', 'in:in_app,realtime,email,push'],
            'priority' => ['required', 'string', 'in:low,normal,high,urgent'],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $hasAudienceId = $this->filled('audience_id');
            $hasAudienceInline = $this->has('audience_inline') && ! empty($this->input('audience_inline'));
            if ($hasAudienceId === $hasAudienceInline) {
                $v->errors()->add('audience', 'Provide exactly one of audience_id or audience_inline.');
            }

            $hasTemplateId = $this->filled('template_id');
            $hasTemplateInline = $this->has('template_inline') && ! empty($this->input('template_inline'));
            if ($hasTemplateId === $hasTemplateInline) {
                $v->errors()->add('template', 'Provide exactly one of template_id or template_inline.');
            }

            if ($hasTemplateInline) {
                $content = (array) $this->input('template_inline.content', []);
                foreach (['ja', 'en', 'vi'] as $locale) {
                    if (empty($content[$locale]['title']) || empty($content[$locale]['body'])) {
                        $v->errors()->add("template_inline.content.$locale", "Missing title or body for $locale.");
                    }
                }
            }
        });
    }
}
