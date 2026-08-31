<?php

namespace App\Http\Requests\Pos;

/**
 * Plan 046 — POST /pos/till/sessions/{session}/handover.
 *
 * A handover settles the current shift exactly like close (same counted-cash
 * form + tender details), so the validation is identical — it extends
 * CloseTillSessionRequest to stay in lock-step. The only behavioural difference
 * (settlement_kind=handover, keep the chain open) lives in the service, not the
 * request shape.
 */
class HandoverTillSessionRequest extends CloseTillSessionRequest {}
