<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\ReprintAdvisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /api/v1/pos/print-jobs/reprint-advice — plan-052 §4 / P-10 (#1166).
 *
 * Formerly `…/reprint-authorization`, and formerly able to answer 422. It no
 * longer can. The owner ruling of 2026-07-28 is that **the system never blocks
 * a print command**: a cashier at the counter with a jammed printer and a
 * waiting customer must always be able to reprint. The old route is kept as an
 * alias so no pos-web build breaks, but both paths now answer 200 with the
 * same advisory body.
 *
 * What the POS does with it: show the dialog when `requires_reason_prompt` is
 * true, list `warnings`, and keep the "In luôn" button ENABLED no matter what
 * the operator types. Dismissing the dialog is a supported outcome; the ledger
 * then carries `reprint_reason: null` + `warned_without_reason: true` and the
 * M2 reconcile report surfaces it for a manager.
 *
 * The only 4xx left are REAL errors: an unknown document kind (422 from
 * validation), or a `payment_id` / `print_job_id` that does not exist in this
 * shop (404). Those are not policy — they mean the client is asking about a
 * document this shop does not have, and answering "sure, go ahead" to that
 * would be a lie.
 *
 * Why a pre-flight call at all, now that it cannot refuse: for `ws_lan` — the
 * only transport in M1 — the workstation performs the print, offline-first, and
 * Cloud must never sit between a shop and its printer (RISKS PR2). The client
 * asks while it is online so the operator sees the warning and the reason gets
 * typed; offline it simply prints and the journal row arrives later carrying
 * whatever was typed locally (or the missing-reason flag).
 */
class PrintReprintAdviceController extends Controller
{
    public function __construct(private readonly ReprintAdvisor $advisor) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_column(PrintJobKind::cases(), 'value'))],
            'reprint_no' => ['required', 'integer', 'min:1'],
            'reprint_reason' => ['nullable', 'string', 'max:255'],
            'actor_user_id' => ['nullable', 'string', 'max:36'],
            // Optional anchors. When present they must resolve INSIDE this shop.
            'payment_id' => ['nullable', 'string', 'max:36'],
            'print_job_id' => ['nullable', 'string', 'max:36'],
        ]);

        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $this->assertBelongsToShop($shop, $validated['payment_id'] ?? null, $validated['print_job_id'] ?? null);

        $organizationId = (string) (Organization::query()
            ->where('console_organization_id', $shop->console_organization_id)
            ->value('id') ?? '');

        // The signed-in user wins; `actor_user_id` covers the device-token
        // path, where pos-web authenticates as the terminal and names the
        // cashier explicitly (same shape as the #1124 checkout gate).
        $actor = $request->user() instanceof User
            ? $request->user()
            : ($validated['actor_user_id'] ?? null ? User::query()->find($validated['actor_user_id']) : null);

        $advice = $this->advisor->advise(
            PrintJobKind::from($validated['kind']),
            (int) $validated['reprint_no'],
            $actor,
            $validated['reprint_reason'] ?? null,
            $organizationId,
            (string) $shop->id,
        );

        return response()->json(['data' => $advice->toArray()]);
    }

    /**
     * A document the shop does not have is a real error, and the ONLY thing
     * still able to produce a 4xx here. Both lookups are scoped to the branch,
     * so a job or payment from another shop answers 404 rather than leaking its
     * existence.
     */
    private function assertBelongsToShop(Branch $shop, ?string $paymentId, ?string $printJobId): void
    {
        if ($printJobId !== null && $printJobId !== '') {
            $exists = PrintJob::query()
                ->where('id', $printJobId)
                ->where('branch_id', $shop->id)
                ->exists();

            if (! $exists) {
                throw new NotFoundHttpException('print job not found in this shop');
            }
        }

        if ($paymentId !== null && $paymentId !== '') {
            $exists = OrderPayment::query()
                ->where('id', $paymentId)
                ->where('branch_id', $shop->id)
                ->exists();

            if (! $exists) {
                throw new NotFoundHttpException('payment not found in this shop');
            }
        }
    }
}
