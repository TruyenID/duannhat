<?php

namespace App\Rules;

use App\Services\Shop\EffectiveOrderPolicyService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * plan-035 — country-aware phone validation for takeaway orders.
 *
 * The branch's country (ISO 3166-1 alpha-2, derived from `branches.locale`
 * by {@see EffectiveOrderPolicyService::deriveCountry()})
 * decides which national numbering plan a customer's phone must satisfy. A
 * Vietnamese branch rejects an 11-digit Japanese mobile; a Japanese branch
 * rejects a 10-digit Vietnamese number, and so on.
 *
 * The plan originally reached for `giggsey/libphonenumber-for-php`, but the
 * checkout only needs to accept/reject the handful of countries this platform
 * operates in. This rule is a self-contained numbering-plan check (no network
 * / no extra Composer dependency): it normalises the raw input to a national
 * significant number (stripping formatting, an international `+CC` / `00CC`
 * prefix, or a national trunk `0`) and matches it against the per-country
 * pattern below. Unknown countries fall back to a permissive E.164 length
 * check so a branch with an exotic locale never hard-rejects a real number.
 *
 * A null / empty value passes — presence is governed separately by the
 * `nullable` rule on the form request.
 */
class PhoneFormatForBranch implements ValidationRule
{
    /**
     * Per-country numbering plan.
     *   cc    — international calling code (digits, no `+`)
     *   trunk — national trunk prefix stripped in the national form ('' = none)
     *   nsn   — regex the national significant number must match
     *
     * @var array<string, array{cc: string, trunk: string, nsn: string}>
     */
    private const SPECS = [
        // VN mobile/landline: 9 significant digits after the trunk 0.
        'VN' => ['cc' => '84', 'trunk' => '0', 'nsn' => '/^[2-9]\d{8}$/'],
        // JP mobile (10) + landline (9) significant digits after trunk 0.
        'JP' => ['cc' => '81', 'trunk' => '0', 'nsn' => '/^[1-9]\d{8,9}$/'],
        // US/CA (NANP): 10 digits, area + exchange lead 2-9. Trunk is '1'.
        'US' => ['cc' => '1', 'trunk' => '', 'nsn' => '/^[2-9]\d{2}[2-9]\d{6}$/'],
        'GB' => ['cc' => '44', 'trunk' => '0', 'nsn' => '/^[1-9]\d{8,9}$/'],
        'KR' => ['cc' => '82', 'trunk' => '0', 'nsn' => '/^[1-9]\d{7,9}$/'],
        'CN' => ['cc' => '86', 'trunk' => '', 'nsn' => '/^[1-9]\d{6,11}$/'],
        'TH' => ['cc' => '66', 'trunk' => '0', 'nsn' => '/^[1-9]\d{7,8}$/'],
        'ID' => ['cc' => '62', 'trunk' => '0', 'nsn' => '/^[1-9]\d{7,11}$/'],
    ];

    /** Localised "invalid phone for country" message, self-contained. */
    private const MESSAGES = [
        'en' => 'The phone number is not valid for :country.',
        'ja' => ':country の有効な電話番号を入力してください。',
        'vi' => 'Số điện thoại không hợp lệ cho :country.',
    ];

    public function __construct(private string $country)
    {
        $this->country = strtoupper($country);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! self::isValidForCountry((string) $value, $this->country)) {
            $fail($this->message());
        }
    }

    /**
     * True when $raw is a plausible phone number for $country's numbering plan.
     */
    public static function isValidForCountry(string $raw, string $country): bool
    {
        $country = strtoupper($country);
        $spec = self::SPECS[$country] ?? null;

        // Normalise: detect an international prefix, then reduce to pure digits.
        $trimmed = trim($raw);
        $isIntl = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D/', '', $trimmed) ?? '';

        if ($digits === '') {
            return false;
        }

        if (str_starts_with($digits, '00')) {
            $isIntl = true;
            $digits = substr($digits, 2);
        }

        // Unknown country → permissive E.164 length check (4–15 digits).
        if ($spec === null) {
            return strlen($digits) >= 4 && strlen($digits) <= 15;
        }

        $cc = $spec['cc'];
        $trunk = $spec['trunk'];

        if ($isIntl) {
            // An international number must belong to THIS country's calling code.
            if ($cc === '' || ! str_starts_with($digits, $cc)) {
                return false;
            }
            $digits = substr($digits, strlen($cc));
            // Some plans keep the trunk 0 even after the calling code — drop it.
            if ($trunk !== '' && str_starts_with($digits, $trunk)) {
                $digits = substr($digits, strlen($trunk));
            }
        } elseif ($trunk !== '' && str_starts_with($digits, $trunk)) {
            // National form with a trunk prefix (e.g. VN/JP leading 0).
            $digits = substr($digits, strlen($trunk));
        } elseif ($trunk === '' && $cc !== '' && str_starts_with($digits, $cc)
            && strlen($digits) > self::maxNsnLength($spec['nsn'])) {
            // NANP national long-distance form: a leading '1' trunk.
            $digits = substr($digits, strlen($cc));
        }

        return preg_match($spec['nsn'], $digits) === 1;
    }

    /**
     * Largest national-significant-number length the pattern accepts — used to
     * decide whether a NANP leading '1' is a trunk digit or part of the number.
     */
    private static function maxNsnLength(string $nsn): int
    {
        // NANP NSN is a fixed 10 digits; keep this simple and specific.
        return 10;
    }

    public function message(): string
    {
        $locale = app()->getLocale();
        $template = self::MESSAGES[$locale] ?? self::MESSAGES['en'];

        return str_replace(':country', $this->country, $template);
    }
}
