"use client"

import { useEffect, useState } from "react"
import { useTranslations, useLocale } from 'next-intl'
import { Clock } from "lucide-react"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { Label } from "@/components/ui/label"
import { DateTimePicker } from "@/components/ui/date-time-picker"
import { closingTimeLabel, isOpenAt } from "@/lib/opening-hours"
import { branchClockDiffersFromDevice } from "@/lib/branch-clock"
import { cn } from "@/lib/utils"
import type { WeeklyHoursMap } from "@/data/brands"
import { formatGuestTime } from "@/lib/date-format"

export interface PickupTimeData {
  pickup_type: "immediate" | "scheduled"
  scheduled_pickup_time: string | null
}

interface PickupTimeSelectorProps {
  value: PickupTimeData
  onChange: (data: PickupTimeData) => void
  estimatedMinutes?: number
  /** plan-037 — minutes the customer has to confirm the order before
   * it auto-voids. Bake into the earliest schedulable pickup slot so the
   * scheduled time is never sooner than the order itself can commit. */
  confirmationTimeoutMinutes?: number
  /** plan-031 — minutes the customer has to pay (counter-pay
   * takeaway) before the order is auto-cancelled. Only part of the floor
   * when the shop actually waits for payment before cooking — see
   * `prepBeforePayment`. */
  paymentTimeoutMinutes?: number
  /** #1160 — the shop's `prep_before_payment` policy, whose name reads
   * backwards: TRUE = "phải thanh toán trước khi chuẩn bị món" (the kitchen
   * waits for money, so the payment window is on the critical path), FALSE =
   * "chuẩn bị món ngay" (cooking starts immediately, so it is not).
   * Unconditionally adding it — the old behaviour — pushed the earliest slot
   * 15 minutes out at every shop that cooks first. */
  prepBeforePayment?: boolean
  /** #1160 — the shop's published opening hours + the zone they are written
   * in. A slot outside them is refused here so the customer isn't told only
   * after submitting (the server checks again; this is the courtesy copy). */
  weeklyHours?: WeeklyHoursMap | null
  branchTimeZone?: string | null
  className?: string
}

export function PickupTimeSelector({
  value,
  onChange,
  estimatedMinutes = 15,
  confirmationTimeoutMinutes = 3,
  paymentTimeoutMinutes = 15,
  prepBeforePayment = true,
  weeklyHours = null,
  branchTimeZone = null,
  className,
}: PickupTimeSelectorProps) {
  const t = useTranslations('pickup')
  const locale = useLocale()
  const [localValue, setLocalValue] = useState(value)
  // `now` được snapshot ngoài render để pass purity rule. Refresh mỗi phút để
  // ETA + min schedule luôn theo thời gian thực mà không gọi Date.now() trong
  // body component.
  const [now, setNow] = useState<number | null>(null)
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setNow(Date.now())
    const id = window.setInterval(() => setNow(Date.now()), 60_000)
    return () => window.clearInterval(id)
  }, [])

  const handleTypeChange = (pickup_type: "immediate" | "scheduled") => {
    const newValue: PickupTimeData = {
      pickup_type,
      scheduled_pickup_time: pickup_type === "scheduled" ? localValue.scheduled_pickup_time : null,
    }
    setLocalValue(newValue)
    onChange(newValue)
  }

  const handleScheduledTimeChange = (date: Date | undefined) => {
    // Send a timezone-UNAMBIGUOUS instant (UTC ISO) — the backend is the source
    // of truth for timezone. A naive-local string ("YYYY-MM-DDTHH:mm") let the
    // BE interpret the time in the app timezone, so a client in a timezone
    // behind the app (e.g. VN UTC+7 vs app JST UTC+9) had its pickup time land
    // in the past and get rejected by `after:now`. `toISOString()` encodes the
    // exact instant the customer picked; the BE validates/stores/returns it
    // against its own now, and it round-trips correctly for any client tz.
    const scheduled_pickup_time = date ? date.toISOString() : null
    const newValue: PickupTimeData = {
      ...localValue,
      scheduled_pickup_time,
    }
    setLocalValue(newValue)
    onChange(newValue)
  }

  const nowMs = now ?? 0
  const estimatedReadyTime = new Date(nowMs + estimatedMinutes * 60 * 1000)
  // Locale-aware since #1261. Đọc trên đồng hồ CHI NHÁNH từ godx-tempo#1767.
  //
  // Comment cũ ở đây nói ngược lại — cố ý dùng giờ máy để khỏi đá nhau với ô
  // chọn giờ ngay bên dưới. Lý do đó đúng vào lúc viết, nhưng nó bỏ sót thành
  // phần thứ ba trên cùng màn: giờ mở/đóng cửa và câu chặn vốn LUÔN đọc theo
  // đồng hồ quán (#1091, #1160). Nên "cả khối dùng giờ máy" không bao giờ đạt
  // được; khách lệch múi giờ đọc ra "chọn 21:30, quán mở tới 22:00, sao bị
  // chặn?". Giờ cả ba thành phần cùng nói theo một đồng hồ: đồng hồ của quán.
  const timeString = now === null ? "" : formatGuestTime(estimatedReadyTime, locale, branchTimeZone)

  // plan-037 — minimum schedulable slot = now + confirmation window +
  // payment window + prep minutes. Ensures the picker can never choose
  // an earlier pickup than the order's worst-case state-machine path.
  //
  // The slot is picked at checkout time but the order commits LATER (the
  // counter-pay confirm step can sit for a few minutes). A floor computed here
  // is already stale by commit time, so picking the earliest slot then gets
  // rejected as "a minute too soon" → forced re-pick. This buffer adds slack
  // so a small delay stays above the floor. TODO(temp): flat 5' for now — tie
  // to the actual confirm/commit window later.
  const SUBMIT_BUFFER_MINUTES = 5
  // #1160 — the payment window only belongs in the floor when the kitchen
  // waits for payment. A shop that cooks immediately ("Chuẩn bị món ngay,
  // không chờ thanh toán") has no such wait, and charging it 15 minutes of
  // dead time pushed the earliest pickup out for no reason.
  const paymentWindowMinutes = prepBeforePayment ? paymentTimeoutMinutes : 0
  const minOffsetMinutes =
    confirmationTimeoutMinutes + paymentWindowMinutes + estimatedMinutes + SUBMIT_BUFFER_MINUTES
  // Sàn là một INSTANT ("bây giờ + các cửa sổ chờ"), không phải một con số trên
  // mặt đồng hồ nào — nên nó đi thẳng sang picker.
  //
  // #1767 — trước đây chỗ này gói `minDate` thành chuỗi naive-local rồi bên
  // dưới `new Date(...)` mở ra lại, làm mốc lệch đúng bằng offset của máy
  // khách. Comment cũ giải thích khuôn đó theo `<input type="datetime-local">`,
  // mà component này đã không còn render input native ấy từ lâu — nó dựng
  // dialog `DateTimePicker` riêng. Chuỗi trung gian bỏ luôn.
  //
  // `undefined` khi chưa có `now`: sàn tính từ mốc 0 sẽ là một ngày năm 1970,
  // tức không còn là sàn nữa. Khuôn cũ vô tình cũng ra "không có sàn" (chuỗi
  // rỗng → Invalid Date → mọi phép so đều false), chỉ là bằng đường vòng.
  const minDate = now === null ? undefined : new Date(nowMs + minOffsetMinutes * 60 * 1000)

  // #1160 — a scheduled slot must land inside the shop's opening hours, read
  // on the SHOP's clock. The server refuses it anyway (422
  // PICKUP_OUTSIDE_OPENING_HOURS); telling the customer here means they fix it
  // before losing a filled-in form. Judged only once a time is actually
  // chosen — an empty picker is "incomplete", not "closed".
  const scheduledDate = localValue.pickup_type === "scheduled" && localValue.scheduled_pickup_time
    ? new Date(localValue.scheduled_pickup_time)
    : null
  const isOutsideOpeningHours = scheduledDate !== null
    && !Number.isNaN(scheduledDate.getTime())
    && !isOpenAt(weeklyHours, scheduledDate, branchTimeZone)
  const closingTime = scheduledDate ? closingTimeLabel(weeklyHours, scheduledDate, branchTimeZone) : null

  // #1767 — cả khối này giờ nói theo đồng hồ quán. Với khách cùng múi giờ (tức
  // gần như toàn bộ khách nội địa) thì không có gì thay đổi và cũng không cần
  // giải thích gì. Chỉ khi hai đồng hồ THẬT SỰ lệch mới nói ra, kèm luôn giờ
  // hiện tại tại quán để khách tự quy đổi được thay vì phải đoán.
  const showsShopClock = now !== null && branchClockDiffersFromDevice(new Date(nowMs), branchTimeZone)
  const shopClockNow = showsShopClock ? formatGuestTime(new Date(nowMs), locale, branchTimeZone) : ""

  return (
    <div className={cn("space-y-3", className)}>
      {/* Header */}
      <div className="flex items-center gap-2">
        <Clock className="h-5 w-5 text-neutral-600" />
        <h3 className="text-base md:text-[20px] font-semibold text-neutral-900">{t('title')}</h3>
      </div>

      {/* #1767 — nói rõ mọi con số dưới đây thuộc đồng hồ nào, và chỉ nói khi
          đồng hồ quán lệch đồng hồ máy. */}
      {showsShopClock && (
        <p className="text-xs leading-relaxed text-neutral-500">
          {t('shopClockNotice', { time: shopClockNow })}
        </p>
      )}

      {/* Radio Group */}
      <RadioGroup
        value={localValue.pickup_type}
        onValueChange={(v) => handleTypeChange(v as "immediate" | "scheduled")}
      >
        {/* Immediate Option */}
        <div
          className={cn(
            "flex items-start gap-2.5 rounded-lg border p-3 transition-all cursor-pointer",
            localValue.pickup_type === "immediate"
              ? "border-primary bg-primary/5"
              : "border-neutral-200 hover:border-neutral-300"
          )}
          onClick={() => handleTypeChange("immediate")}
        >
          <RadioGroupItem value="immediate" id="pickup-immediate" className="mt-0.5" />
          <div className="flex-1 space-y-0.5">
            <Label htmlFor="pickup-immediate" className="cursor-pointer text-sm font-medium text-neutral-900">
              {t('immediate')}
            </Label>
            <p className="text-xs text-neutral-500 leading-relaxed">
              {t('estimatedReady', { time: timeString, minutes: estimatedMinutes })}
            </p>
          </div>
        </div>

        {/* Scheduled Option */}
        <div
          className={cn(
            "rounded-lg border p-3 transition-all",
            localValue.pickup_type === "scheduled"
              ? "border-primary bg-primary/5"
              : "border-neutral-200 hover:border-neutral-300"
          )}
        >
          <div className="flex items-start gap-2.5">
            <RadioGroupItem
              value="scheduled"
              id="pickup-scheduled"
              className="mt-0.5"
              onClick={() => handleTypeChange("scheduled")}
            />
            <div className="flex-1 space-y-2.5 min-w-0">
              <Label
                htmlFor="pickup-scheduled"
                className="cursor-pointer text-sm font-medium text-neutral-900"
                onClick={() => handleTypeChange("scheduled")}
              >
                {t('scheduled')}
              </Label>
              {localValue.pickup_type === "scheduled" && (
                <>
                  <DateTimePicker
                    value={localValue.scheduled_pickup_time ? new Date(localValue.scheduled_pickup_time) : undefined}
                    onChange={handleScheduledTimeChange}
                    minDate={minDate}
                    timeZone={branchTimeZone}
                    placeholder={t('dateTimePlaceholder')}
                  />
                  {isOutsideOpeningHours && (
                    <p role="alert" className="text-xs font-medium text-red-600 leading-relaxed">
                      {closingTime
                        ? t('outsideOpeningHoursWithClosing', { time: closingTime })
                        : t('outsideOpeningHours')}
                    </p>
                  )}
                </>
              )}
            </div>
          </div>
        </div>
      </RadioGroup>
    </div>
  )
}
