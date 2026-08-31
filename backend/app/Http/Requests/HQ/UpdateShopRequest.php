<?php

namespace App\Http\Requests\HQ;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shopParam = $this->route('shop');
        $shopId = $shopParam instanceof Branch ? $shopParam->id : $shopParam;
        $consoleOrgId = $this->user()->console_organization_id;

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('branches', 'slug')
                    ->where('console_organization_id', $consoleOrgId)
                    ->ignore($shopId),
            ],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:50', 'timezone:all'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['ja', 'en', 'vi'])],

            // Shop detail fields
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            // Logo + banner: stored URL strings (produced by the shop image
            // upload endpoint or seeded external CDN URLs). `img_branches` is
            // the banner column. Customer-web renders both directly.
            'logo' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'img_branches' => ['sometimes', 'nullable', 'string', 'max:2048'],
            // #936 — per-breakpoint banners. All optional; customer-web falls
            // back down the chain (mobile → tablet → desktop → img_branches).
            'banner_desktop' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'banner_tablet' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'banner_mobile' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'seat_capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'business_hours' => ['sometimes', 'nullable', 'string', 'max:100'],
            'weekly_hours' => ['sometimes', 'nullable', 'array'],
            'weekly_hours.*' => ['array'],
            'weekly_hours.*.open' => ['sometimes', 'nullable', 'string', 'date_format:H:i'],
            'weekly_hours.*.close' => ['sometimes', 'nullable', 'string', 'date_format:H:i'],
            'weekly_hours.*.closed' => ['sometimes', 'boolean'],
        ];
    }
}
