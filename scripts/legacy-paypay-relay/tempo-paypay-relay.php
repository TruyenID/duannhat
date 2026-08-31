<?php
/**
 * Plugin Name: Tempo PayPay webhook relay
 * Description: Replays each genuine PayPay OPA notification to TempoFast. Read-only tee — never touches the WooCommerce order flow.
 * Version: 1.0.0
 *
 * WHY THIS EXISTS (#2445)
 *
 * PayPay configures webhooks per MERCHANT, and merchant 653886312490745856 is
 * shared by this WooCommerce site and TempoFast. One merchant means one Live
 * webhook slot, so pointing it at TempoFast would silently stop confirming
 * orders here. This tee keeps the slot where it is and hands TempoFast a copy.
 *
 * THE TWO RULES THIS FILE LIVES BY
 *
 *  1. It must never change what WooCommerce does. Every failure — DNS, TLS,
 *     timeout, a 500 from TempoFast — is swallowed. The paying customer's order
 *     status is decided by paypay4wc alone, exactly as before this file existed.
 *  2. It must send the ORIGINAL bytes. TempoFast verifies the payload as PayPay
 *     sent it; re-encoding the JSON would change the body and prove nothing.
 *
 * WHEN PAYPAY POINTS LIVE AT TEMPOFAST DIRECTLY
 *
 * Nothing here needs changing and nothing breaks. TempoFast dedupes on
 * (connection_id, environment, provider_event_id), so the same notification
 * arriving from PayPay and from this relay books once. Delete the file when the
 * legacy shop is retired, not before.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * THE ORIGIN, NOT `tempo.godx.jp`. Do not "fix" this to the public domain.
 *
 * `tempo.godx.jp` is fronted by CloudFront, and TempoFast verifies a PayPay OPA
 * webhook by its SOURCE IP. Measured on 2026-08-11, three requests through the
 * public domain arrived as 18.177.200.66, 18.178.121.91 and 43.206.19.6 — a
 * different CloudFront edge each time, never the caller. The same request sent
 * to `tempo-prod.godx.jp` arrived as the caller's real address.
 *
 * So an IP allowlist can never match through the CDN: not this relay's address,
 * and not PayPay's own. That is why every delivery was rejected before this
 * file existed, and why moving the URL back would silently break it again while
 * still returning HTTP 200 here.
 */
const TEMPO_PAYPAY_RELAY_URL = 'https://tempo-prod.godx.jp/api/v1/webhooks/payment/paypay';

/**
 * Priority 1: run BEFORE paypay4wc's own handler (default 10).
 *
 * Ordering is deliberate. `wp_remote_post` is non-blocking so it costs the
 * request almost nothing, and going first means a fatal introduced later in the
 * plugin's handler cannot silently stop the relay — the copy is already away.
 */
add_action('woocommerce_api_paypay', 'tempo_paypay_relay', 1);

function tempo_paypay_relay(): void
{
    try {
        $body = file_get_contents('php://input');

        // Not our traffic: only replay what looks like a PayPay OPA
        // notification for the merchant TempoFast shares with this site.
        if (! is_string($body) || $body === '') {
            return;
        }

        $payload = json_decode($body, true);
        if (! is_array($payload) || ! isset($payload['notification_type'])) {
            return;
        }

        wp_remote_post(TEMPO_PAYPAY_RELAY_URL, [
            // Fire-and-forget. The WooCommerce order must not wait on a foreign
            // host, and PayPay must not see our latency: they retry on timeout,
            // and a retry storm here would be self-inflicted.
            'blocking' => false,
            'timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json',
                // Marks the hop for whoever reads TempoFast's logs. TempoFast
                // authenticates on the source IP of this host, not on this
                // header — a header anyone can set must never be a credential.
                'X-Tempo-Relay' => 'menu.betoya.jp/wc-api/paypay',
            ],
            'body' => $body,   // ORIGINAL bytes, not a re-encode.
        ]);
    } catch (\Throwable $e) {
        // Deliberately silent. This is a tee; a broken tee is not a broken
        // payment. Errors here would otherwise surface as a failed PayPay
        // callback and leave a paid order stuck in the legacy shop.
        return;
    }
}
