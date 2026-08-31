<?php

namespace App\Services\Customer;

use RuntimeException;

/**
 * The customer cannot pay this order with PayPay right now.
 *
 * Distinct from a gateway or infrastructure failure: the message is safe to show
 * the guest, and the controller turns it into a 422 rather than a 500. Covers a
 * branch without PayPay, an order that is closed or already settled, and the
 * transport switch being off.
 */
final class PayPayUnavailable extends RuntimeException {}
