<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Plan-038 T11.2 — atomically allocates the next invoice_no for a branch
 * within the current calendar month. Format:
 *
 *   {prefix}-{YYYYMM}-{NNNNN}
 *
 * Prefix resolution:
 *   1. branches.invoice_prefix (operator-overridable; nullable column).
 *   2. Fallback: UPPER(LEFT(branch.name, 3)) stripped to A-Z.
 *
 * Allocation runs inside a SELECT … FOR UPDATE transaction against
 * invoice_counters so 50 concurrent issue requests on the same branch
 * produce 50 distinct numbers (asserted by the Pest concurrency test).
 *
 * Org-wide collision check (plan-038 Q11): when two branches in the
 * SAME organization share a resolved prefix, the second branch's prefix
 * is auto-prepended with the org slug ("{slug}-{prefix}") to avoid
 * duplicates that would confuse downstream finance reporting. Cross-org
 * collisions are NOT checked — different organizations are different
 * legal entities and may legitimately share prefixes.
 */
class InvoiceNumberAllocator
{
    public function allocate(Branch $branch, ?\DateTimeInterface $when = null): string
    {
        return $this->allocateFormatted($branch, '%s-%s-%05d', $when);
    }

    /**
     * #1123 — number for a 適格返還請求書 (return invoice / 赤伝). Shares the
     * SAME per-branch counter sequence as invoices (continuous numbering, so
     * uniqueness needs no second counter and invoice_counters' char(6) key is
     * untouched); the `R` segment marks the document type on the printed slip.
     */
    public function allocateReturn(Branch $branch, ?\DateTimeInterface $when = null): string
    {
        return $this->allocateFormatted($branch, '%s-%s-R%05d', $when);
    }

    private function allocateFormatted(Branch $branch, string $format, ?\DateTimeInterface $when = null): string
    {
        $when ??= now();
        $yearMonth = $when->format('Ym');
        $prefix = $this->resolvePrefix($branch);

        // Atomic counter bump. Portable UPSERT via DB::table()->upsert()
        // which Laravel translates per-driver (MySQL ON DUPLICATE KEY,
        // SQLite ON CONFLICT DO UPDATE).
        $seq = DB::transaction(function () use ($branch, $yearMonth): int {
            $existing = DB::table('invoice_counters')
                ->where('branch_id', $branch->id)
                ->where('year_month', $yearMonth)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $next = (int) $existing->last_seq + 1;
                DB::table('invoice_counters')
                    ->where('branch_id', $branch->id)
                    ->where('year_month', $yearMonth)
                    ->update(['last_seq' => $next, 'updated_at' => now()]);

                return $next;
            }

            DB::table('invoice_counters')->insert([
                'branch_id' => $branch->id,
                'year_month' => $yearMonth,
                'last_seq' => 1,
                'updated_at' => now(),
            ]);

            return 1;
        });

        return sprintf($format, $prefix, $yearMonth, $seq);
    }

    /**
     * Resolve the branch's invoice prefix, claiming and PINNING one on first use.
     *
     * #821 A8 — the previous guard was symmetric and therefore useless. Branch
     * "Shibuya" saw "Shinjuku" as a conflict and fell back to "{org}-SHI";
     * "Shinjuku" saw "Shibuya" and fell back to the SAME "{org}-SHI" — same org
     * ⇒ same slug ⇒ same raw ⇒ byte-identical prefix. Neither branch was "first",
     * nothing broke the tie. invoice_counters is keyed (branch_id, year_month) so
     * both started at 1, and uq_branch_invoice_no is per-branch so the database
     * waved both rows through: two red invoices, same number, same legal entity —
     * on the DEFAULT configuration, no misconfiguration required.
     *
     * It also recomputed the prefix on every allocate() and never stored it, so
     * opening a sibling branch mid-month silently rewrote an in-flight sequence
     * (SHI-202607-00042 → A1B2C3-SHI-202607-00043 on the same branch), and an
     * operator's hand-configured prefix got mangled by a mere name collision.
     *
     * Now: a prefix, once set — by the operator or by the first allocation — is
     * pinned and never recomputed. A branch without one claims the first free
     * candidate among its org's siblings (SHI, SHI2, SHI3…) and persists it.
     */
    private function resolvePrefix(Branch $branch): string
    {
        $pinned = trim((string) ($branch->invoice_prefix ?? ''));
        if ($pinned !== '') {
            return $pinned;
        }

        $base = strtoupper(preg_replace('/[^A-Z]/i', '', substr($branch->name ?? 'INV', 0, 3))) ?: 'INV';

        // Lock every branch row in the org for the duration of the claim so two
        // branches issuing their first invoice at the same instant cannot both
        // observe the same prefix as free and both take it.
        return DB::transaction(function () use ($branch, $base): string {
            $taken = Branch::query()
                ->where('console_organization_id', $branch->console_organization_id)
                ->lockForUpdate()
                ->get(['id', 'invoice_prefix'])
                ->reject(fn ($b) => $b->id === $branch->id)
                ->map(fn ($b) => strtoupper(trim((string) ($b->invoice_prefix ?? ''))))
                ->filter()
                ->all();

            $prefix = $base;
            $n = 2;
            while (in_array($prefix, $taken, true)) {
                $prefix = $base.$n;
                $n++;
            }

            // Pin it. Every later allocate() short-circuits on the branch above,
            // so this branch's prefix can never drift again.
            $branch->forceFill(['invoice_prefix' => $prefix])->save();

            return $prefix;
        });
    }
}
