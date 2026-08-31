<?php

namespace App\Http\Requests\HQ;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $consoleOrgId = $this->user()->console_organization_id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('branches', 'slug')
                    ->where('console_organization_id', $consoleOrgId),
            ],
            'timezone' => ['nullable', 'string', 'max:50', 'timezone:all'],
            'currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', 'string', Rule::in(['ja', 'en', 'vi'])],

            // Shop detail fields
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            // Logo + banner: stored URL strings (produced by the shop image
            // upload endpoint or seeded external CDN URLs). `img_branches` is
            // the banner column. Customer-web renders both directly.
            'logo' => ['nullable', 'string', 'max:2048'],
            'img_branches' => ['nullable', 'string', 'max:2048'],
            // #936 — per-breakpoint banners. All optional; customer-web falls
            // back down the chain (mobile → tablet → desktop → img_branches).
            'banner_desktop' => ['nullable', 'string', 'max:2048'],
            'banner_tablet' => ['nullable', 'string', 'max:2048'],
            'banner_mobile' => ['nullable', 'string', 'max:2048'],
            'seat_capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'business_hours' => ['nullable', 'string', 'max:100'],
            'weekly_hours' => ['nullable', 'array'],
            'weekly_hours.*' => ['array'],
            'weekly_hours.*.open' => ['sometimes', 'nullable', 'string', 'date_format:H:i'],
            'weekly_hours.*.close' => ['sometimes', 'nullable', 'string', 'date_format:H:i'],
            'weekly_hours.*.closed' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'timezone' => config('app.default_branch_timezone', 'Asia/Tokyo'),
            'currency' => 'JPY',
            'locale' => 'ja',
        ]);
    }
}
