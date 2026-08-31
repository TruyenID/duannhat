// plan-035 — Branch-aware phone validation + format mask.
//
// Wraps `libphonenumber-js` so the checkout form can format-as-you-type
// for the branch's country (e.g. `0336909454` → `033 690 9454` on a VN
// branch) and reject numbers that don't actually exist in that country's
// numbering plan.

import {
	AsYouType,
	getCountryCallingCode,
	parsePhoneNumberFromString,
	type CountryCode,
} from "libphonenumber-js";

export interface PhoneValidationResult {
	valid: boolean;
	formatted: string;
	errorKey?: string;
}

/**
 * #1845 — the countries a customer may pick from on the register form.
 *
 * Deliberately a SHORT list, not every country libphonenumber knows: each
 * entry has to carry a translated name in ja/en/vi and an example number, and
 * an unverified country in the picker is a number shape nobody here checked.
 * Order is the order shown; `DEFAULT_PHONE_COUNTRY` is what the form opens on.
 */
export const SUPPORTED_PHONE_COUNTRIES = ["JP", "VN", "GB"] as const;

export type SupportedPhoneCountry = (typeof SUPPORTED_PHONE_COUNTRIES)[number];

export const DEFAULT_PHONE_COUNTRY: SupportedPhoneCountry = "JP";

/**
 * Placeholder per COUNTRY, not per UI language.
 *
 * The old `phonePlaceholder` message was translated, so a Vietnamese-reading
 * customer at a Japanese branch was shown a Vietnamese number shape while the
 * field only accepted Japanese ones. A digit grouping is not a translation —
 * it belongs to the country dialled, so it lives here.
 */
const PHONE_PLACEHOLDERS: Record<SupportedPhoneCountry, string> = {
	JP: "90 1234 5678",
	VN: "912 345 678",
	GB: "7400 123456",
};

/**
 * Cờ dạng emoji từ mã ISO ("JP" → 🇯🇵) bằng cặp Regional Indicator.
 *
 * Windows không có font cờ sẽ hiện "JP" — vẫn đọc được đúng nghĩa, nên đây là
 * mức xuống cấp chấp nhận được.
 */
export function flagEmoji(country: string): string {
	if (!/^[A-Za-z]{2}$/.test(country)) return "";
	return country
		.toUpperCase()
		.replace(/./g, (char) => String.fromCodePoint(127397 + char.charCodeAt(0)));
}

export function isSupportedPhoneCountry(value: unknown): value is SupportedPhoneCountry {
	return (
		typeof value === "string" &&
		(SUPPORTED_PHONE_COUNTRIES as readonly string[]).includes(value)
	);
}

/** National-format example for the country's own numbering plan. */
export function phonePlaceholderFor(country: CountryCode | string): string {
	return isSupportedPhoneCountry(country) ? PHONE_PLACEHOLDERS[country] : "";
}

/**
 * `+81` for `JP`. Wrapped in try/catch because `getCountryCallingCode` THROWS
 * on an unknown code — a junk value in `branches.locale` must not blank out a
 * whole page.
 */
export function callingCodeFor(country: CountryCode | string): string {
	try {
		return `+${getCountryCallingCode(country as CountryCode)}`;
	} catch {
		return "";
	}
}

/**
 * Derive an ISO-3166-1 alpha-2 country code from a `branches.locale`
 * value (`vi-VN`, `ja-JP`, `en-GB`). Falls back to a small mapping for
 * bare locales (`vi` → `VN`) and `US` for unknown — same logic as the
 * server-side `EffectiveOrderPolicyService::deriveCountry`.
 */
export function deriveCountry(locale: string | null | undefined): CountryCode {
	if (!locale) return "US";

	const parts = locale.split("-");
	if (parts.length >= 2 && parts[1].length === 2) {
		return parts[1].toUpperCase() as CountryCode;
	}

	const fallback: Record<string, CountryCode> = {
		vi: "VN",
		ja: "JP",
		en: "US",
		zh: "CN",
		ko: "KR",
		th: "TH",
		id: "ID",
	};
	return fallback[parts[0].toLowerCase()] ?? "US";
}

/**
 * Validate + canonicalise a phone number for a given branch country.
 * Returns the formatted national form (`033 690 9454` for VN) on
 * success; on failure returns an i18n key the caller can pass through
 * `useTranslations()`.
 */
export function validatePhoneForCountry(
	rawValue: string | null | undefined,
	country: CountryCode | string,
): PhoneValidationResult {
	const trimmed = (rawValue ?? "").trim();
	if (trimmed === "") {
		return { valid: false, formatted: "", errorKey: "phoneRequired" };
	}

	const parsed = parsePhoneNumberFromString(trimmed, country as CountryCode);
	if (!parsed || !parsed.isValid()) {
		return {
			valid: false,
			formatted: trimmed,
			errorKey: "phoneInvalidForCountry",
		};
	}

	if (parsed.country && parsed.country !== country) {
		return {
			valid: false,
			formatted: trimmed,
			errorKey: "phoneWrongCountry",
		};
	}

	return { valid: true, formatted: parsed.formatNational() };
}

/**
 * Format-as-you-type for inline mask. Use on `onChange` to render the
 * partial number with the country's natural grouping while the user is
 * still typing (no validation, no rejection of partials).
 */
export function formatAsYouType(value: string, country: CountryCode | string): string {
	const formatter = new AsYouType(country as CountryCode);
	return formatter.input(value);
}

/**
 * Re-mask an already-typed number after the country picker changes (#1845).
 *
 * `formatAsYouType` alone is not enough here: it appends to whatever grouping
 * is already in the string, so a VN-grouped `033 690 9454` switched to JP keeps
 * the VN spacing and reads as a number the field then rejects. Strip back to
 * bare digits first, then let the new country regroup them.
 */
export function reformatForCountry(value: string, country: CountryCode | string): string {
	const digits = value.replace(/[^\d]/g, "");
	return digits === "" ? "" : formatAsYouType(digits, country);
}

/**
 * E.164 form (`+84336909454`) — what we send to the BE. Falls back to
 * the raw value when libphonenumber can't parse it; the BE rule
 * catches that case again.
 */
export function toE164(value: string, country: CountryCode | string): string {
	const parsed = parsePhoneNumberFromString(value, country as CountryCode);
	return parsed?.number ?? value;
}
