"use client"

import * as React from "react"
import { useTranslations } from "next-intl"
import { Calendar as CalendarIcon, ChevronUp, ChevronDown } from "lucide-react"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Calendar } from "@/components/ui/calendar"
import { instantFromWallClock, wallClockAt, type WallClock } from "@/lib/branch-clock"
import {
  Dialog,
  DialogContent,
  DialogTrigger,
} from "@/components/ui/dialog"

interface DateTimePickerProps {
  value?: Date
  onChange?: (date: Date | undefined) => void
  minDate?: Date
  className?: string
  placeholder?: string
  /**
   * godx-tempo#1767 — múi giờ mà mọi con số trong picker này được HIỂU theo.
   *
   * Bỏ trống = đồng hồ máy khách, đúng hành vi cũ. Truyền vào múi giờ chi
   * nhánh thì nhãn nút, bánh xe giờ/phút và lịch đều nói theo đồng hồ tại
   * quán — cùng đồng hồ với giờ mở/đóng cửa in ngay cạnh đó.
   *
   * `value` và `onChange` vẫn là INSTANT tuyệt đối ở cả hai chế độ. Picker chỉ
   * đổi cách đọc/ghi, không đổi thứ gửi lên backend.
   */
  timeZone?: string | null
}

const pad2 = (n: number) => String(n).padStart(2, "0")

/**
 * Nhãn trên nút: giữ nguyên khuôn "dd/MM/yyyy, HH:mm" mà `date-fns` vẫn in ra,
 * chỉ đổi chỗ lấy số — từ mặt đồng hồ truyền vào thay vì từ đồng hồ máy. Dựng
 * tay chứ không qua `Intl` để thứ tự ngày/tháng không đổi theo locale, vì
 * `dateTimePlaceholder` ở cả ba ngôn ngữ đều đang là "dd/mm/yyyy, --:--".
 */
function formatWallClock(wall: WallClock): string {
  return `${pad2(wall.day)}/${pad2(wall.month)}/${wall.year}, ${pad2(wall.hour)}:${pad2(wall.minute)}`
}

export function DateTimePicker({
  value,
  onChange,
  minDate,
  className,
  placeholder,
  timeZone = null,
}: DateTimePickerProps) {
  const t = useTranslations("dateTimePicker")
  const placeholderText = placeholder ?? t("placeholder")
  const [open, setOpen] = React.useState(false)
  // Ngày đang chọn giữ dưới dạng NGÀY TRẦN (chỉ Y/M/D) của mặt đồng hồ đích:
  // `Calendar` vốn chỉ làm việc với ngày tháng không múi giờ, nên ép nó hiểu
  // instant sẽ lệch đúng một ngày với khách ở múi giờ khác. Giờ/phút nằm riêng
  // ở `hours`/`minutes`; ba mảnh này chỉ được ghép lại thành instant ở
  // `handleConfirm`.
  const initialWall = value ? wallClockAt(value, timeZone) : null
  const [selectedDate, setSelectedDate] = React.useState<Date | undefined>(
    initialWall ? new Date(initialWall.year, initialWall.month - 1, initialWall.day) : undefined,
  )
  // `??` chứ không `||`: khuôn cũ `value?.getHours() || 10` nuốt mất giờ 0, nên
  // một mốc đã chọn lúc 00:20 mở lại ra 10:20. Chỉ đúng khi KHÔNG có mốc nào
  // mới rơi về 10 giờ.
  const [hours, setHours] = React.useState(initialWall?.hour ?? 10)
  const [minutes, setMinutes] = React.useState(initialWall?.minute ?? 0)
  const [touchStartY, setTouchStartY] = React.useState<number | null>(null)
  const [touchType, setTouchType] = React.useState<'hours' | 'minutes' | null>(null)
  const [scrollOffset, setScrollOffset] = React.useState(0)
  const [isAnimating, setIsAnimating] = React.useState(false)
  const [isDragging, setIsDragging] = React.useState(false)
  const [isEditingHours, setIsEditingHours] = React.useState(false)
  const [isEditingMinutes, setIsEditingMinutes] = React.useState(false)
  const [isMobile, setIsMobile] = React.useState(false)

  const ITEM_HEIGHT = 40 // Height of each item in the wheel

  React.useEffect(() => {
    // Detect if mobile — runs only on mount; cannot be derived during render
    // because window APIs are SSR-unsafe.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setIsMobile('ontouchstart' in window || navigator.maxTouchPoints > 0)
  }, [])

  // Picking a date/time only updates the WORKING state now — it no longer
  // commits (onChange) or closes the popover. The user picks the date, then the
  // hour + minute, and nothing is finalized until they tap "Chọn"
  // (handleConfirm). Previously handleDateSelect closed the popover on the very
  // first date tap, so the customer never got to choose the time.
  const handleDateSelect = (date: Date | undefined) => {
    if (date) {
      // Chỉ giữ Y/M/D. Trước đây chỗ này `date.setHours(...)` — vừa sửa thẳng
      // vào object mà `Calendar` đưa sang, vừa nhét giờ/phút vào một ngày mà
      // ngay dưới đây đã có state riêng để giữ.
      setSelectedDate(new Date(date.getFullYear(), date.getMonth(), date.getDate()))
    }
  }

  const handleHoursChange = (newHours: number) => {
    setHours(newHours)
  }

  const handleMinutesChange = (newMinutes: number) => {
    setMinutes(newMinutes)
  }

  const handleClear = () => {
    setSelectedDate(undefined)
    onChange?.(undefined)
    setOpen(false)
  }

  /** Commit whatever the user picked in the calendar + time pickers.
   * Falls back to today if no date selected yet. plan-037: floor the
   * result to `minDate` so the time-wheel UI (which doesn't know about
   * the minimum) can't smuggle in an earlier slot.
   *
   * #1767 — ba mảnh (ngày trần + giờ + phút) được đọc như giờ treo tường tại
   * `timeZone` rồi mới quy về instant. So sánh với `minDate` vẫn là so INSTANT
   * với INSTANT: sàn đó là "bây giờ + các cửa sổ chờ", một mốc tuyệt đối,
   * không phải một con số trên mặt đồng hồ nào. */
  const handleConfirm = () => {
    // Chưa chạm vào lịch thì mặc định là HÔM NAY — hôm nay của quán, không phải
    // của máy khách. Hai cái đó có thể là hai ngày khác nhau, và lấy nhầm thì
    // đúng nửa đêm là lệch nguyên một ngày.
    const day: WallClock = selectedDate
      ? {
          year: selectedDate.getFullYear(),
          month: selectedDate.getMonth() + 1,
          day: selectedDate.getDate(),
          hour: hours,
          minute: minutes,
        }
      : { ...wallClockAt(new Date(), timeZone), hour: hours, minute: minutes };

    const picked = instantFromWallClock(day, timeZone);

    if (minDate && picked < minDate) {
      // Bump up to the earliest allowed slot rather than rejecting the
      // tap — keeps the UX flowing while still enforcing the floor.
      const flooredWall = wallClockAt(minDate, timeZone);
      setSelectedDate(new Date(flooredWall.year, flooredWall.month - 1, flooredWall.day));
      setHours(flooredWall.hour);
      setMinutes(flooredWall.minute);
      onChange?.(new Date(minDate));
    } else {
      onChange?.(picked);
    }
    setOpen(false);
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger
        type="button"
        className={cn(
          "inline-flex items-center justify-start w-full h-10 px-3 rounded-md border border-input bg-background text-sm font-normal hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
          !value && "text-muted-foreground",
          className
        )}
        style={{
          fontSize: '16px',
          paddingLeft: '12px',
          paddingRight: '12px',
        }}
      >
        <CalendarIcon className="mr-2 h-4 w-4" />
        {value ? formatWallClock(wallClockAt(value, timeZone)) : <span>{placeholderText}</span>}
      </DialogTrigger>
      <DialogContent className="w-[90vw] sm:w-auto p-0 sm:max-w-3xl max-w-sm overflow-hidden" showCloseButton={false} style={{ borderRadius: '12px' }}>
        {/* Mobile: Calendar trên, Time picker dưới (vertical stack) */}
        {/* Desktop: Calendar trái, Time picker phải (horizontal flex) */}
        <div className="flex flex-col sm:flex-row sm:items-start bg-gray-50 rounded-xl" style={{ borderRadius: '12px' }}>
          {/* Calendar */}
          <div className="sm:border-r flex-shrink-0 w-full sm:w-auto">
            <Calendar
              mode="single"
              selected={selectedDate}
              onSelect={handleDateSelect}
              disabled={(date: Date) => {
                // Compare DATE only (year/month/day) so today stays
                // enabled even when `minDate` has a future hour-of-day
                // (e.g. now+1h floor). The time-picker handles
                // within-the-day filtering separately.
                //
                // #1767 — ngày sàn phải đọc trên mặt đồng hồ QUÁN. Với khách
                // lệch múi giờ, `minDate` có thể vẫn là hôm nay ở máy nhưng đã
                // sang ngày mai ở quán (hoặc ngược lại), và so nhầm thì lịch
                // khoá mất đúng cái ngày khách được phép chọn.
                if (!minDate) return false;
                const dateMidnight = new Date(date.getFullYear(), date.getMonth(), date.getDate());
                const minWall = wallClockAt(minDate, timeZone);
                const minMidnight = new Date(minWall.year, minWall.month - 1, minWall.day);
                return dateMidnight < minMidnight;
              }}
              autoFocus
              className="w-full"
            />
          </div>

          {/* Time Picker */}
          <div className="border-t sm:border-t-0 pt-6 space-y-4 pb-4 sm:w-72 flex-shrink-0 flex flex-col sm:items-center">
            <p className="text-base font-semibold text-gray-900 px-4 sm:px-0">{t("chooseTime")}</p>
            <div className="relative flex gap-4 items-center justify-center px-4 sm:px-0" style={{ height: '140px' }}>
              {/* Shared background - 1 nền chung cho cả giờ và phút, full width */}
              <div className="absolute inset-x-0 top-[50px] h-14 bg-gray-100 pointer-events-none z-0"></div>

              {/* Hours Wheel */}
              <div className="flex flex-col items-center relative z-10" style={{ height: '140px', width: '100px' }}>

                <div
                  className="relative w-full h-full overflow-hidden"
                  style={{
                    cursor: !isMobile && !isEditingHours && !isDragging ? 'grab' : !isMobile && isDragging ? 'grabbing' : 'default'
                  }}
                  onWheel={(e) => {
                    if (isEditingHours) return
                    e.preventDefault()
                    if (e.deltaY < 0) {
                      handleHoursChange(hours === 23 ? 0 : hours + 1)
                    } else {
                      handleHoursChange(hours === 0 ? 23 : hours - 1)
                    }
                  }}
                  onMouseDown={(e) => {
                    if (isEditingHours || isMobile) return
                    // Chỉ drag khi click vào vùng không phải input
                    if ((e.target as HTMLElement).tagName === 'INPUT') return
                    e.preventDefault()
                    setTouchStartY(e.clientY)
                    setTouchType('hours')
                    setScrollOffset(0)
                    setIsAnimating(false)
                    setIsDragging(true)
                  }}
                  onMouseMove={(e) => {
                    if (!isDragging || touchStartY === null || touchType !== 'hours') return
                    e.preventDefault()
                    const currentY = e.clientY
                    const deltaY = currentY - touchStartY
                    setScrollOffset(deltaY)

                    if (Math.abs(deltaY) >= ITEM_HEIGHT) {
                      if (deltaY > 0) {
                        handleHoursChange(hours === 0 ? 23 : hours - 1)
                      } else {
                        handleHoursChange(hours === 23 ? 0 : hours + 1)
                      }
                      setTouchStartY(currentY)
                      setScrollOffset(0)
                    }
                  }}
                  onMouseUp={() => {
                    if (!isDragging) return
                    setIsAnimating(true)
                    setTouchStartY(null)
                    setTouchType(null)
                    setScrollOffset(0)
                    setIsDragging(false)
                    setTimeout(() => setIsAnimating(false), 150)
                  }}
                  onMouseLeave={() => {
                    if (isDragging) {
                      setIsAnimating(true)
                      setTouchStartY(null)
                      setTouchType(null)
                      setScrollOffset(0)
                      setIsDragging(false)
                      setTimeout(() => setIsAnimating(false), 150)
                    }
                  }}
                  onTouchStart={(e) => {
                    e.preventDefault()
                    setTouchStartY(e.touches[0].clientY)
                    setTouchType('hours')
                    setScrollOffset(0)
                    setIsAnimating(false)
                  }}
                  onTouchMove={(e) => {
                    if (touchStartY === null || touchType !== 'hours') return
                    e.preventDefault()
                    const currentY = e.touches[0].clientY
                    const deltaY = currentY - touchStartY
                    setScrollOffset(deltaY)

                    if (Math.abs(deltaY) >= ITEM_HEIGHT) {
                      if (deltaY > 0) {
                        handleHoursChange(hours === 0 ? 23 : hours - 1)
                      } else {
                        handleHoursChange(hours === 23 ? 0 : hours + 1)
                      }
                      setTouchStartY(currentY)
                      setScrollOffset(0)
                    }
                  }}
                  onTouchEnd={() => {
                    setIsAnimating(true)
                    setTouchStartY(null)
                    setTouchType(null)
                    setScrollOffset(0)
                    setTimeout(() => setIsAnimating(false), 150)
                  }}
                >
                  <div
                    className="relative w-full h-full"
                    style={{
                      transform: touchType === 'hours' ? `translateY(${scrollOffset}px)` : 'translateY(0)',
                      transition: isAnimating && touchType !== 'hours' ? 'transform 150ms cubic-bezier(0.25, 0.46, 0.45, 0.94)' : 'none',
                    }}
                  >
                    {/* Show 3 numbers: -1, current, +1 */}
                    {[-1, 0, 1].map((offset) => {
                      const value = (hours + offset + 24) % 24
                      const position = 50 + offset * 48
                      const isCurrent = offset === 0

                      // Desktop: Current number is editable input
                      if (isCurrent && !isMobile) {
                        return (
                          <div
                            key={offset}
                            className="absolute inset-x-0 flex items-center justify-center"
                            style={{
                              top: `${position}px`,
                              height: '48px',
                            }}
                          >
                            <input
                              type="text"
                              inputMode="numeric"
                              value={String(hours).padStart(2, '0')}
                              onChange={(e) => {
                                const val = e.target.value.replace(/\D/g, '')
                                if (val === '') {
                                  handleHoursChange(0)
                                  return
                                }
                                const num = parseInt(val, 10)
                                if (!isNaN(num)) {
                                  if (num > 23) {
                                    handleHoursChange(23)
                                  } else {
                                    handleHoursChange(num)
                                  }
                                }
                              }}
                              onFocus={(e) => {
                                setIsEditingHours(true)
                                e.target.select()
                              }}
                              onBlur={(e) => {
                                setIsEditingHours(false)
                                // Auto format khi blur
                                const val = parseInt(e.target.value, 10)
                                if (isNaN(val) || val < 0) {
                                  handleHoursChange(0)
                                } else if (val > 23) {
                                  handleHoursChange(23)
                                }
                              }}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                  e.currentTarget.blur()
                                } else if (e.key === 'ArrowUp') {
                                  e.preventDefault()
                                  handleHoursChange(hours === 23 ? 0 : hours + 1)
                                } else if (e.key === 'ArrowDown') {
                                  e.preventDefault()
                                  handleHoursChange(hours === 0 ? 23 : hours - 1)
                                }
                              }}
                              className="w-12 h-10 text-center bg-transparent border-none outline-none text-gray-900 cursor-text"
                              style={{
                                fontSize: '20px',
                                fontWeight: '500',
                              }}
                            />
                          </div>
                        )
                      }

                      return (
                        <div
                          key={offset}
                          className="absolute inset-x-0 flex items-center justify-center"
                          style={{
                            top: `${position}px`,
                            height: '48px',
                            fontSize: isCurrent ? '20px' : '16px',
                            fontWeight: isCurrent ? '500' : '400',
                            color: isCurrent ? '#111827' : '#9ca3af',
                            transition: 'all 100ms ease-out',
                          }}
                        >
                          {String(value).padStart(2, '0')}
                        </div>
                      )
                    })}
                  </div>
                </div>
              </div>

              {/* Minutes Wheel */}
              <div className="flex flex-col items-center relative z-10" style={{ height: '140px', width: '100px' }}>

                <div
                  className="relative w-full h-full overflow-hidden"
                  style={{
                    cursor: !isMobile && !isEditingMinutes && !isDragging ? 'grab' : !isMobile && isDragging ? 'grabbing' : 'default'
                  }}
                  onWheel={(e) => {
                    if (isEditingMinutes) return
                    e.preventDefault()
                    if (e.deltaY < 0) {
                      handleMinutesChange(minutes === 59 ? 0 : minutes + 1)
                    } else {
                      handleMinutesChange(minutes === 0 ? 59 : minutes - 1)
                    }
                  }}
                  onMouseDown={(e) => {
                    if (isEditingMinutes || isMobile) return
                    // Chỉ drag khi click vào vùng không phải input
                    if ((e.target as HTMLElement).tagName === 'INPUT') return
                    e.preventDefault()
                    setTouchStartY(e.clientY)
                    setTouchType('minutes')
                    setScrollOffset(0)
                    setIsAnimating(false)
                    setIsDragging(true)
                  }}
                  onMouseMove={(e) => {
                    if (!isDragging || touchStartY === null || touchType !== 'minutes') return
                    e.preventDefault()
                    const currentY = e.clientY
                    const deltaY = currentY - touchStartY
                    setScrollOffset(deltaY)

                    if (Math.abs(deltaY) >= ITEM_HEIGHT) {
                      if (deltaY > 0) {
                        handleMinutesChange(minutes === 0 ? 59 : minutes - 1)
                      } else {
                        handleMinutesChange(minutes === 59 ? 0 : minutes + 1)
                      }
                      setTouchStartY(currentY)
                      setScrollOffset(0)
                    }
                  }}
                  onMouseUp={() => {
                    if (!isDragging) return
                    setIsAnimating(true)
                    setTouchStartY(null)
                    setTouchType(null)
                    setScrollOffset(0)
                    setIsDragging(false)
                    setTimeout(() => setIsAnimating(false), 150)
                  }}
                  onMouseLeave={() => {
                    if (isDragging) {
                      setIsAnimating(true)
                      setTouchStartY(null)
                      setTouchType(null)
                      setScrollOffset(0)
                      setIsDragging(false)
                      setTimeout(() => setIsAnimating(false), 150)
                    }
                  }}
                  onTouchStart={(e) => {
                    e.preventDefault()
                    setTouchStartY(e.touches[0].clientY)
                    setTouchType('minutes')
                    setScrollOffset(0)
                    setIsAnimating(false)
                  }}
                  onTouchMove={(e) => {
                    if (touchStartY === null || touchType !== 'minutes') return
                    e.preventDefault()
                    const currentY = e.touches[0].clientY
                    const deltaY = currentY - touchStartY
                    setScrollOffset(deltaY)

                    if (Math.abs(deltaY) >= ITEM_HEIGHT) {
                      if (deltaY > 0) {
                        handleMinutesChange(minutes === 0 ? 59 : minutes - 1)
                      } else {
                        handleMinutesChange(minutes === 59 ? 0 : minutes + 1)
                      }
                      setTouchStartY(currentY)
                      setScrollOffset(0)
                    }
                  }}
                  onTouchEnd={() => {
                    setIsAnimating(true)
                    setTouchStartY(null)
                    setTouchType(null)
                    setScrollOffset(0)
                    setTimeout(() => setIsAnimating(false), 150)
                  }}
                >
                  <div
                    className="relative w-full h-full"
                    style={{
                      transform: touchType === 'minutes' ? `translateY(${scrollOffset}px)` : 'translateY(0)',
                      transition: isAnimating && touchType !== 'minutes' ? 'transform 150ms cubic-bezier(0.25, 0.46, 0.45, 0.94)' : 'none',
                    }}
                  >
                    {/* Show 3 numbers: -1, current, +1 */}
                    {[-1, 0, 1].map((offset) => {
                      const value = (minutes + offset + 60) % 60
                      const position = 50 + offset * 48
                      const isCurrent = offset === 0

                      // Desktop: Current number is editable input
                      if (isCurrent && !isMobile) {
                        return (
                          <div
                            key={offset}
                            className="absolute inset-x-0 flex items-center justify-center"
                            style={{
                              top: `${position}px`,
                              height: '48px',
                            }}
                          >
                            <input
                              type="text"
                              inputMode="numeric"
                              value={String(minutes).padStart(2, '0')}
                              onChange={(e) => {
                                const val = e.target.value.replace(/\D/g, '')
                                if (val === '') {
                                  handleMinutesChange(0)
                                  return
                                }
                                const num = parseInt(val, 10)
                                if (!isNaN(num)) {
                                  if (num > 59) {
                                    handleMinutesChange(59)
                                  } else {
                                    handleMinutesChange(num)
                                  }
                                }
                              }}
                              onFocus={(e) => {
                                setIsEditingMinutes(true)
                                e.target.select()
                              }}
                              onBlur={(e) => {
                                setIsEditingMinutes(false)
                                // Auto format khi blur
                                const val = parseInt(e.target.value, 10)
                                if (isNaN(val) || val < 0) {
                                  handleMinutesChange(0)
                                } else if (val > 59) {
                                  handleMinutesChange(59)
                                }
                              }}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                  e.currentTarget.blur()
                                } else if (e.key === 'ArrowUp') {
                                  e.preventDefault()
                                  handleMinutesChange(minutes === 59 ? 0 : minutes + 1)
                                } else if (e.key === 'ArrowDown') {
                                  e.preventDefault()
                                  handleMinutesChange(minutes === 0 ? 59 : minutes - 1)
                                }
                              }}
                              className="w-12 h-10 text-center bg-transparent border-none outline-none text-gray-900 cursor-text"
                              style={{
                                fontSize: '20px',
                                fontWeight: '500',
                              }}
                            />
                          </div>
                        )
                      }

                      return (
                        <div
                          key={offset}
                          className="absolute inset-x-0 flex items-center justify-center"
                          style={{
                            top: `${position}px`,
                            height: '48px',
                            fontSize: isCurrent ? '20px' : '16px',
                            fontWeight: isCurrent ? '500' : '400',
                            color: isCurrent ? '#111827' : '#9ca3af',
                            transition: 'all 100ms ease-out',
                          }}
                        >
                          {String(value).padStart(2, '0')}
                        </div>
                      )
                    })}
                  </div>
                </div>
              </div>
            </div>

            {/* Actions */}
            <div className="flex justify-end sm:justify-center gap-2 px-4 sm:px-0 pt-4 border-t border-border/40">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={handleClear}
                className="text-sm h-9"
              >
                {t("clear")}
              </Button>
              <Button
                type="button"
                variant="default"
                size="sm"
                onClick={handleConfirm}
                className="text-white text-sm h-9"
                style={{ backgroundColor: '#2563eb' }}
              >
                {t("confirm")}
              </Button>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
