"use client";

/**
 * plan-054 — is this branch wired for PayPay dynamic QR?
 *
 * Reads `GET /api/v1/customer/branches/{slug}/payment-context` →
 * `data.paypay_enabled` and answers a single boolean.
 *
 * **This is NOT a visibility flag.** The `qr_pay` radio renders on every
 * checkout surface regardless — it has always meant "staff settle it at the
 * till" and it works. All this hook decides is whether *choosing* it runs the
 * QR flow. An unresolved probe therefore still reads as `false` at the call
 * sites, i.e. "keep exactly today's behaviour" (plan-054 D-B11 / §10.2).
 *
 * What changed: **"unavailable" and "could not determine" are no longer the
 * same answer.** They used to be — a single blip during a wifi→4G handoff
 * resolved `false` and, because the effect only re-ran on `branchSlug`, told the
 * customer "This shop does not accept PayPay QR" for the entire life of the
 * page, on a shop that does. Now only the server can say `false`; a transport
 * failure says "ask again", and the hook does: a bounded automatic ladder, plus
 * a fresh attempt whenever the tab is foregrounded or the browser comes back
 * online.
 *
 * Cached per branch slug for the page lifetime, mirroring `lib/stripe-config`:
 * checkout desktop, checkout mobile and the pay screen all ask for the same
 * slug and must not each burn a request. Only *answers* are cached.
 */

import { useEffect, useRef, useState } from "react";

import { ApiError, apiFetch } from "@/lib/api";
import {
  classifyPayPayAvailabilityAnswer,
  parseCounterPaySettings,
  parsePayPayAvailability,
  paymentContextPath,
  COUNTER_PAY_DEFAULTS,
  type CounterPaySettings,
} from "@/lib/paypay-qr";

/**
 * One probe, two answers (#2806).
 *
 * `paypay_enabled` and the counter-pay switches ride the same
 * `payment-context` response, so they are cached together. Giving the counter
 * switches their own hook would double the requests on every checkout mount for
 * two booleans that arrive in the same body — and worse, the two hooks would
 * retry independently and could disagree about the same branch mid-render.
 *
 * They keep SEPARATE undetermined semantics, which is the whole reason this is
 * one cache of a record rather than one cache of a boolean: PayPay resolves to
 * `null` (never assume-on-error, see the docblock above), while the counter
 * switches fall back to `COUNTER_PAY_DEFAULTS` because for them the assume-on-
 * error direction is the safe one.
 */
interface PaymentContextAnswer {
  /** `null` = undetermined. NOT the same as `false`. */
  paypayEnabled: boolean | null;
  counter: CounterPaySettings;
}

const cache = new Map<string, Promise<PaymentContextAnswer>>();

/** Automatic retries per slug before we wait for a focus/online nudge instead. */
const MAX_AUTO_RETRIES = 2;
/** Ladder for those retries; the last entry repeats. */
const RETRY_DELAYS_MS = [1_500, 4_000];

export function loadPaymentContext(branchSlug: string): Promise<PaymentContextAnswer> {
  const hit = cache.get(branchSlug);
  if (hit) return hit;

  const pending = apiFetch<unknown>(paymentContextPath(branchSlug), { silent401: true })
    .then((raw) => ({
      paypayEnabled: parsePayPayAvailability(raw) as boolean | null,
      counter: parseCounterPaySettings(raw),
    }))
    .catch((error: unknown) => {
      const answer = classifyPayPayAvailabilityAnswer(
        error instanceof ApiError ? error.status : null,
      );
      // A 4xx IS the server answering — keep it cached. Anything else is noise:
      // drop the rejected entry so the next caller actually re-asks.
      if (answer === null) cache.delete(branchSlug);
      // The counter switches take the defaults on every failure, 4xx included:
      // a 404 branch has no settings to honour, and the screens that ask are
      // already handling "no such branch" on their own.
      return { paypayEnabled: answer, counter: COUNTER_PAY_DEFAULTS };
    });

  cache.set(branchSlug, pending);
  return pending;
}

export interface UsePayPayAvailabilityResult {
  /** `true` only when the server said so. Never assume-on-error. */
  paypayEnabled: boolean;
  loading: boolean;
  /**
   * #2806 — the branch's pay-at-counter switches.
   *
   * Readable WHILE `loading`, unlike `paypayEnabled`, and that is on purpose:
   * they carry their defaults from the first render, so a screen gating the
   * counter radio on them never has to blank it out and bring it back. The
   * flicker rule in `lib/counter-pay.ts` exists because the OLD answer was
   * derived from gateway probes; this one is not derived from anything.
   */
  counter: CounterPaySettings;
}

export function usePayPayAvailability(
  branchSlug: string | null | undefined,
): UsePayPayAvailabilityResult {
  // The answer is stamped with the slug it was fetched for, so `loading` and
  // `paypayEnabled` are derived at render rather than written from the effect
  // body. Switching branches therefore reads as "loading" immediately instead
  // of briefly reporting the previous branch's answer.
  //
  // `enabled: null` is the third state this hook grew: "we gave up asking for
  // now". It reads as not-enabled everywhere, but it is NOT a verdict — the
  // focus/online listener below keeps trying, and it is what stops `loading`
  // from hanging forever on a dead network (the pay screen renders a spinner
  // while loading, so an eternal `loading` is its own dead end).
  const [probe, setProbe] = useState<
    { slug: string; enabled: boolean | null; counter: CounterPaySettings } | null
  >(null);
  // Monotonic — bumping it re-runs the probe. Automatic bumps are capped by
  // `autoRetriesRef`; a focus/online nudge resets that cap, so a customer who
  // comes back with signal always gets a fresh attempt.
  const [attempt, setAttempt] = useState(0);
  const autoRetriesRef = useRef(0);

  useEffect(() => {
    autoRetriesRef.current = 0;
  }, [branchSlug]);

  useEffect(() => {
    if (!branchSlug) return;
    let cancelled = false;
    let timer: number | undefined;
    void loadPaymentContext(branchSlug).then(({ paypayEnabled, counter }) => {
      if (cancelled) return;
      if (paypayEnabled === null) {
        // Undetermined. Retry on a short ladder first; only once that is spent
        // do we surface the third state, and even then no `false` is recorded.
        //
        // The counter switches are held back with it rather than published
        // early: this branch is reached only when the whole response failed, so
        // `counter` here is the defaults anyway — publishing it would just
        // re-state what the caller already reads while `probe` is null.
        if (autoRetriesRef.current < MAX_AUTO_RETRIES) {
          const delay =
            RETRY_DELAYS_MS[Math.min(autoRetriesRef.current, RETRY_DELAYS_MS.length - 1)];
          autoRetriesRef.current += 1;
          timer = window.setTimeout(() => setAttempt((n) => n + 1), delay);
          return;
        }
      }
      setProbe({ slug: branchSlug, enabled: paypayEnabled, counter });
    });
    return () => {
      cancelled = true;
      if (timer !== undefined) window.clearTimeout(timer);
    };
  }, [branchSlug, attempt]);

  // Still undetermined when the customer returns to the tab, or when the device
  // reconnects: ask once more. This is the unbounded door — bounded automatic
  // retries cannot cover a tunnel, and the alternative is the permanent false
  // verdict this hook exists to stop giving.
  const answered = Boolean(branchSlug) && probe?.slug === branchSlug;
  const unresolved =
    Boolean(branchSlug) && (!answered || probe?.enabled === null);
  useEffect(() => {
    if (!branchSlug || !unresolved) return;
    const again = () => {
      autoRetriesRef.current = 0;
      setAttempt((n) => n + 1);
    };
    const onVisible = () => {
      if (document.visibilityState === "visible") again();
    };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("online", again);
    return () => {
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("online", again);
    };
  }, [branchSlug, unresolved]);

  return {
    // Only a literal `true` from the server upgrades anything — undetermined
    // and unavailable both read as "keep today's behaviour" (§10.2).
    paypayEnabled: answered && probe?.enabled === true,
    loading: Boolean(branchSlug) && !answered,
    // Defaults until the branch's own answer lands — see the interface.
    counter: answered ? (probe?.counter ?? COUNTER_PAY_DEFAULTS) : COUNTER_PAY_DEFAULTS,
  };
}
