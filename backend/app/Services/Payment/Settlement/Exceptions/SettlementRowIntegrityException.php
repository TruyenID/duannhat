<?php

namespace App\Services\Payment\Settlement\Exceptions;

use RuntimeException;

/**
 * Plan-050 S-15 / S-17 — the row the gateway (or a report file) handed us
 * contradicts itself (net ≠ gross − fee − fee_tax, malformed currency).
 * A contradictory number is never stored: importers turn this into a
 * failed/orphan-mismatch outcome instead of persisting the row as-is.
 */
final class SettlementRowIntegrityException extends RuntimeException {}
