"use client"

import { useMemo, useSyncExternalStore } from "react"
import { useTranslations } from "next-intl"

import { useBrand } from "@/context/brand-context"
import { useCart } from "@/context/cart-context"
import type { Branch } from "@/data/brands"
import {
  hasPublishedHours,
  isOpenAt,
  nextOpening,
  type DayKey,
  type NextOpening,
} from "@/lib/opening-hours"

/**
 * #1167 — "is this shop open RIGHT NOW?", re-evaluated as the clock moves.
 *
 * The verdict rides `branches.weekly_hours` read on the BRANCH's clock (#1091),
 * never the customer's: a Hanoi customer looking at a Tokyo shop must see
 * Tokyo's closing time. `business_hours` — the free-text "Giờ hoạt động" field —
 * is display copy ONLY and deliberately has no say here. It used to
 * short-circuit the whole check to "open", which meant filling that box in
 * silently disabled every hours check the app had.
 *
 * Fail-open when the branch publishes no usable schedule: most shops never
 * filled `weekly_hours` in, and refusing to serve them is the worse bug.
 */
export interface BranchOpenState {
  /**
   * False only when the shop publishes hours AND now is outside them. The
   * server render and the client's first paint can't know the real time, so
   * they answer `true` — a shop is never blocked by a clock we haven't read.
   */
  isOpen: boolean
  /** When it opens next — for "opens again at 06:00". Null while open. */
  nextOpening: NextOpening | null
}

/** How often the verdict is re-read. A tab left open across closing time must
 *  flip on its own; twice a minute is plenty for an hours boundary. */
const TICK_MS = 30_000

/**
 * The wall clock as an external store — one shared ticker for every subscriber
 * rather than a timer per component, and no setState-in-an-effect.
 *
 * `0` means "not read yet": the server, and the client's very first render,
 * both see it, so hydration matches. React re-reads the snapshot right after
 * subscribing, which is where the real time lands.
 */
let clockMs = 0
let clockTimer: ReturnType<typeof setInterval> | null = null
const clockListeners = new Set<() => void>()

function subscribeToClock(onStoreChange: () => void): () => void {
  clockListeners.add(onStoreChange)

  if (clockTimer === null) {
    clockMs = Date.now()
    clockTimer = setInterval(() => {
      clockMs = Date.now()
      for (const listener of clockListeners) listener()
    }, TICK_MS)
  }

  return () => {
    clockListeners.delete(onStoreChange)
    if (clockListeners.size === 0 && clockTimer !== null) {
      clearInterval(clockTimer)
      clockTimer = null
    }
  }
}

const getClockSnapshot = () => clockMs
const getServerClockSnapshot = () => 0

export function useBranchOpenState(branch: Branch | null | undefined): BranchOpenState {
  const nowMs = useSyncExternalStore(subscribeToClock, getClockSnapshot, getServerClockSnapshot)

  const weeklyHours = branch?.weekly_hours
  const timeZone = branch?.timezone

  return useMemo(() => {
    // Clock unread (SSR / first paint) or nothing published — render as open.
    if (nowMs === 0 || !hasPublishedHours(weeklyHours)) {
      return { isOpen: true, nextOpening: null }
    }

    const now = new Date(nowMs)

    if (isOpenAt(weeklyHours, now, timeZone)) {
      return { isOpen: true, nextOpening: null }
    }

    return { isOpen: false, nextOpening: nextOpening(weeklyHours, now, timeZone) }
  }, [weeklyHours, timeZone, nowMs])
}

const DAY_LABEL_KEY: Record<DayKey, string> = {
  mon: "dayMon",
  tue: "dayTue",
  wed: "dayWed",
  thu: "dayThu",
  fri: "dayFri",
  sat: "daySat",
  sun: "daySun",
}

/**
 * "hôm nay 18:00" / "ngày mai 06:00" / "Thứ 2 09:00" — the tail of every
 * "closed, opens again …" string. Null when there is nothing to promise.
 */
export function useNextOpeningLabel(next: NextOpening | null): string | null {
  const t = useTranslations("shop")

  if (!next) return null
  if (next.dayOffset === 0) return t("reopenToday", { time: next.time })
  if (next.dayOffset === 1) return t("reopenTomorrow", { time: next.time })

  return t("reopenOnDay", { day: t(DAY_LABEL_KEY[next.day]), time: next.time })
}

/**
 * Just the day part — "Hôm nay" / "Ngày mai" / "Thứ 4" — for laying the
 * reopening out as a labelled row (day above, time below) instead of a
 * sentence.
 */
export function useNextOpeningDayLabel(next: NextOpening | null): string | null {
  const t = useTranslations("shop")

  if (!next) return null
  if (next.dayOffset === 0) return t("dayToday")
  if (next.dayOffset === 1) return t("dayTomorrow")

  return t(DAY_LABEL_KEY[next.day])
}

/**
 * The same verdict, resolved for the branch the customer is currently browsing.
 */
export function useCurrentBranchOpenState(): BranchOpenState {
  const { currentBranch } = useBrand()
  return useBranchOpenState(currentBranch)
}

/**
 * Should ordering be blocked right now?
 *
 * Take-away only. Dine-in is deliberately never blocked: the customer is
 * sitting in the restaurant with a QR code in front of them, and a party still
 * at the table after closing must be able to order — last orders are the
 * staff's call, not the schedule's. Backend matches this exactly
 * (CustomerTakeawayOrderService gates take-away; the table endpoint doesn't).
 */
export function useOrderingBlocked(): BranchOpenState & { blocked: boolean } {
  const state = useCurrentBranchOpenState()
  const { orderType } = useCart()

  return { ...state, blocked: orderType === "takeaway" && !state.isOpen }
}
