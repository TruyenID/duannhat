<?php

namespace App\Services\Order;

use App\Models\CustomerOrder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/**
 * plan-035 — generate a PNG QR code that the kitchen / cashier scans to
 * look up a takeaway order at pickup. The PNG bytes are embedded inline
 * as base64 into the OrderPlacedMail HTML so the mail recipient never has
 * to fetch an attachment.
 *
 * Payload is intentionally simple — `{order_id, code, branch_slug}` JSON
 * — and stays small so the resulting QR is robust to low-light camera
 * scans on a phone screen. Plan-036 (POS scanner) will add HMAC signing
 * before any production rollout.
 */
class OrderQrService
{
    /** Generate the PNG bytes for the given order. */
    public function generatePng(CustomerOrder $order, int $size = 240): string
    {
        $payload = json_encode([
            'v' => 1,
            'order_id' => $order->id,
            'code' => $order->order_code,
            'branch' => $order->branch?->slug,
            'type' => 'takeaway',
        ], JSON_UNESCAPED_SLASHES);

        $renderer = new GDLibRenderer($size);
        $writer = new Writer($renderer);

        return $writer->writeString($payload);
    }

    /** Convenience wrapper returning a `data:image/png;base64,...` URI. */
    public function generateDataUri(CustomerOrder $order, int $size = 240): string
    {
        return 'data:image/png;base64,'.base64_encode($this->generatePng($order, $size));
    }
}
