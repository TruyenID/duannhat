<?php

namespace App\Services\Notification;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Renders `NotificationTemplate` rows into `{title, body}` pairs at
 * response time (plan-012 T2.2).
 *
 * `NotificationResource` invokes `render($template, $params, $userLocale)`
 * so `/me/notifications` returns strings already substituted for the
 * authenticated user's locale. Composer live-preview uses `renderAll`
 * to get all three locales in one call.
 *
 * Contract (enforced here, documented in
 * `schemas/Backend/Notification/NotificationTemplate.yaml`):
 *
 *   - `content` is `{<locale>: {title, body}}` with at least `ja`
 *     present (seeder-guaranteed for is_system rows).
 *   - Tokens `{{param_key}}` inside title/body are substituted from
 *     `$params`. Missing key → empty string + `Log::warning`.
 *   - Unknown locale falls back $locale → `ja` → `en`. If even `en` is
 *     absent we return empty strings (still no throw — diagnostic, not
 *     a 500).
 */
class TemplateRenderer
{
    public const FALLBACK_LOCALES = ['ja', 'en'];

    /**
     * Plan-023 M6 T6.3 — resolve a template_key against the
     * shop → brand → organization (system) → platform chain. Returns
     * the first matching row OR null.
     *
     * Lookup precedence:
     *   1. shop-scoped row    (branch_id = $shop?->id)
     *   2. brand-scoped row   (brand_id = $brand?->id AND branch_id IS NULL)
     *   3. org / platform row (branch_id IS NULL AND brand_id IS NULL)
     *
     * `is_system` is NOT a filter — system rows are seeded with
     * `brand_id=null`, `branch_id=null`, so they fall through to the
     * third bucket. Brand or shop rows override system text by
     * existence alone.
     *
     * Per-request in-memory cache via Container::scoped() avoids the
     * 3-query waterfall on bulk inbox fetches; the cache key is
     * (template_key, brand_id, shop_id) so different recipients in
     * the same response can hit the cache for free.
     */
    public static function resolveForKey(string $key, ?Brand $brand = null, ?Branch $shop = null): ?NotificationTemplate
    {
        $cacheKey = sprintf(
            'tpl-resolve:%s:%s:%s',
            $key,
            $brand?->id ?? '-',
            $shop?->id ?? '-',
        );

        return app()->scoped($cacheKey, function () use ($key, $brand, $shop) {
            // 1. shop override
            if ($shop !== null) {
                $row = NotificationTemplate::query()
                    ->where('key', $key)
                    ->where('branch_id', $shop->id)
                    ->first();
                if ($row !== null) {
                    return $row;
                }
            }

            // 2. brand-level
            if ($brand !== null) {
                $row = NotificationTemplate::query()
                    ->where('key', $key)
                    ->where('brand_id', $brand->id)
                    ->whereNull('branch_id')
                    ->first();
                if ($row !== null) {
                    return $row;
                }
            }

            // 3. org / platform (system rows live here with brand_id=null)
            return NotificationTemplate::query()
                ->where('key', $key)
                ->whereNull('brand_id')
                ->whereNull('branch_id')
                ->first();
        });
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{title: string, body: string}
     */
    public function render(NotificationTemplate $template, array $params, string $locale): array
    {
        $content = $this->resolveLocale($template, $locale);

        $title = (string) ($content['title'] ?? '');
        $body = (string) ($content['body'] ?? '');

        return [
            'title' => $this->substitute($title, $params, $template->key),
            'body' => $this->substitute($body, $params, $template->key),
        ];
    }

    /**
     * All locales — used by the composer live-preview endpoint.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, array{title: string, body: string}>
     */
    public function renderAll(NotificationTemplate $template, array $params): array
    {
        $content = (array) $template->content;
        $out = [];

        foreach (array_keys($content) as $locale) {
            $out[(string) $locale] = $this->render($template, $params, (string) $locale);
        }

        return $out;
    }

    /**
     * @return array{title?: string, body?: string}
     */
    private function resolveLocale(NotificationTemplate $template, string $locale): array
    {
        $content = (array) $template->content;

        if (isset($content[$locale]) && is_array($content[$locale])) {
            return $content[$locale];
        }

        foreach (self::FALLBACK_LOCALES as $fallback) {
            if ($fallback !== $locale && isset($content[$fallback]) && is_array($content[$fallback])) {
                return $content[$fallback];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function substitute(string $template, array $params, string $templateKey): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $match) use ($params, $templateKey): string {
                $key = $match[1];
                if (! array_key_exists($key, $params)) {
                    Log::warning('notification.template.missing_param', [
                        'template_key' => $templateKey,
                        'param' => $key,
                    ]);

                    return '';
                }

                return (string) $params[$key];
            },
            $template,
        ) ?? $template;
    }
}
