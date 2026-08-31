package handler

import (
	"net/http"
	"strings"
)

// supportedPrintLocales are the languages the thermal-print templates can
// render. Anything outside this set falls back to Japanese — the store's
// native/accounting locale — so a slip is never left with empty labels.
var supportedPrintLocales = map[string]bool{"ja": true, "en": true, "vi": true}

// localeFromRequest resolves the print locale for a LAN request from the
// Accept-Language header that pos-web already sends on every print call
// (workstation-print-service.ts sets it from the cashier's app_locale).
//
// It is deliberately permissive: it takes the FIRST language tag, strips any
// quality weight (";q=0.9") and region subtag ("en-US" → "en"), lowercases it
// and whitelists {ja,en,vi}. Unknown/empty → "ja". A dedicated helper (not an
// inline read) keeps this the single source of truth so kitchen/receipt
// templates can adopt the same locale plumbing later.
func localeFromRequest(r *http.Request) string {
	return normalizePrintLocale(r.Header.Get("Accept-Language"))
}

// normalizePrintLocale is the pure, testable core of localeFromRequest.
func normalizePrintLocale(raw string) string {
	if loc := parseAcceptLanguage(raw); loc != "" {
		return loc
	}
	return "ja"
}

// dataLocaleFromRequest resolves the display locale for a JSON read, and
// returns "" when the caller did not state one.
//
// It is deliberately NOT localeFromRequest. That helper defaults to "ja"
// because a thermal slip must never print with empty labels and the shop's
// accounting locale is the safest paper — a defensible choice for paper, and
// the wrong one for an API response. Reusing it here meant any client that
// failed to send Accept-Language silently got the whole tender vocabulary in
// Japanese, which is indistinguishable on screen from "the language switch is
// broken" — and is exactly how it was reported.
//
// Empty means "not stated", and every caller pairs it with localizedNameExpr,
// which then serves the untranslated base column: the value Cloud resolved
// with its own configured default. A client that forgets the header degrades
// to the previous behaviour instead of flipping a whole shop floor into a
// language nobody picked.
func dataLocaleFromRequest(r *http.Request) string {
	return parseAcceptLanguage(r.Header.Get("Accept-Language"))
}

// parseAcceptLanguage returns the first supported language in an
// Accept-Language header, or "" when none is. Permissive about shape: it takes
// the FIRST tag, strips any quality weight (";q=0.9") and region subtag
// ("en-US" → "en"), and lowercases it — so both the bare "vi" pos-web sends
// and a browser's "vi-VN,vi;q=0.9,en;q=0.8" resolve to "vi".
func parseAcceptLanguage(raw string) string {
	// "en-US,en;q=0.9,vi;q=0.8" → first tag "en-US"
	first, _, _ := strings.Cut(raw, ",")
	// drop ";q=..." weight
	first, _, _ = strings.Cut(first, ";")
	// drop region subtag ("en-us" → "en")
	first, _, _ = strings.Cut(strings.ToLower(strings.TrimSpace(first)), "-")
	if supportedPrintLocales[first] {
		return first
	}
	return ""
}
