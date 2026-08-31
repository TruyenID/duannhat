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
	// "en-US,en;q=0.9,vi;q=0.8" → first tag "en-US"
	first, _, _ := strings.Cut(raw, ",")
	// drop ";q=..." weight
	first, _, _ = strings.Cut(first, ";")
	// drop region subtag ("en-us" → "en")
	first, _, _ = strings.Cut(strings.ToLower(strings.TrimSpace(first)), "-")
	if supportedPrintLocales[first] {
		return first
	}
	return "ja"
}
