"use client"

import * as React from "react"
import { DayPicker, type MonthCaptionProps, useDayPicker } from "react-day-picker"
import { useLocale } from "next-intl"
import { enUS, ja, vi } from "date-fns/locale"
import type { Locale } from "date-fns"
import { cn } from "@/lib/utils"

export type CalendarProps = React.ComponentProps<typeof DayPicker>

/** Map next-intl locale code → date-fns Locale object. Default `enUS`. */
function resolveDateFnsLocale(code: string): Locale {
  switch (code) {
    case "vi": return vi
    case "ja": return ja
    default: return enUS
  }
}

function CustomCaption(props: MonthCaptionProps) {
  const displayMonth = props.calendarMonth.date
  const { goToMonth } = useDayPicker()
  const locale = useLocale()
  const dateFnsLocale = resolveDateFnsLocale(locale)
  // Lấy tên tháng theo locale qua Intl (đỡ phải bundle thêm date-fns format
  // strings) — date-fns locale chỉ dùng để pass xuống DayPicker bên dưới.
  const months = React.useMemo(() => {
    const intlLocale = locale === "ja" ? "ja-JP" : locale === "vi" ? "vi-VN" : "en-US"
    const fmt = new Intl.DateTimeFormat(intlLocale, { month: "long" })
    return Array.from({ length: 12 }, (_, i) => fmt.format(new Date(2024, i, 1)))
  }, [locale])

  const currentYear = new Date().getFullYear()
  const years = Array.from({ length: 20 }, (_, i) => currentYear - 5 + i)

  // Note: dateFnsLocale used by Calendar wrapper via DayPicker locale prop
  void dateFnsLocale

  return (
    <div className="flex items-center justify-center gap-2 mb-3">
      <select
        value={displayMonth.getMonth()}
        onChange={(e) => {
          const newMonth = new Date(displayMonth)
          newMonth.setMonth(parseInt(e.target.value))
          goToMonth(newMonth)
        }}
        className="text-sm font-medium px-3 py-1.5 rounded-md border bg-background cursor-pointer appearance-none pr-7 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-1"
        style={{
          backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E")`,
          backgroundRepeat: 'no-repeat',
          backgroundPosition: 'right 6px center',
          backgroundSize: '14px'
        }}
      >
        {months.map((month, i) => (
          <option key={i} value={i}>{month}</option>
        ))}
      </select>

      <select
        value={displayMonth.getFullYear()}
        onChange={(e) => {
          const newMonth = new Date(displayMonth)
          newMonth.setFullYear(parseInt(e.target.value))
          goToMonth(newMonth)
        }}
        className="text-sm font-medium px-3 py-1.5 rounded-md border bg-background cursor-pointer appearance-none pr-7 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-1"
        style={{
          backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E")`,
          backgroundRepeat: 'no-repeat',
          backgroundPosition: 'right 6px center',
          backgroundSize: '14px'
        }}
      >
        {years.map((year) => (
          <option key={year} value={year}>{year}</option>
        ))}
      </select>
    </div>
  )
}

function Calendar({
  className,
  classNames,
  showOutsideDays = true,
  ...props
}: CalendarProps) {
  const locale = useLocale()
  const dateFnsLocale = resolveDateFnsLocale(locale)
  return (
    <DayPicker
      locale={dateFnsLocale}
      showOutsideDays={showOutsideDays}
      className={cn("p-3", className)}
      classNames={{
        months: "flex flex-col",
        month: "space-y-2",
        nav: "hidden",
        month_caption: "hidden",
        month_grid: "w-full border-collapse mt-2",
        weekdays: "flex",
        weekday: "text-muted-foreground flex-1 sm:w-9 sm:flex-none font-normal text-xs",
        week: "flex w-full mt-1",
        day: "h-9 flex-1 sm:w-9 sm:flex-none text-center text-sm p-0 relative rounded-md hover:bg-accent hover:text-accent-foreground font-normal",
        day_button: "h-9 w-full sm:w-9 p-0 font-normal",
        selected: "bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground",
        today: "bg-accent text-accent-foreground font-semibold",
        outside: "text-muted-foreground opacity-50",
        disabled: "text-muted-foreground opacity-30",
        hidden: "invisible",
        ...classNames,
      }}
      components={{
        MonthCaption: CustomCaption,
      }}
      {...props}
    />
  )
}
Calendar.displayName = "Calendar"

export { Calendar }
