"use client";

import { Skeleton } from "@godxjp/ui";
import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import type { EmailHealthTimeseriesBucket } from "@/services/notification-email-suppression-service";

export interface DeliverabilityChartProps {
  data: EmailHealthTimeseriesBucket[];
  isLoading: boolean;
}

export function DeliverabilityChart({ data, isLoading }: DeliverabilityChartProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  if (isLoading) {
    return <Skeleton className="h-[280px] w-full" />;
  }

  // Recharts XAxis renders ticks straight from `date` strings (YYYY-MM-DD).
  // We format with the user's locale at render time via `tickFormatter`.
  const formatTick = (raw: string) => {
    // Short month/day only — chart axis has limited width.
    try {
      return new Date(raw).toLocaleDateString(locale, {
        month: "short",
        day: "numeric",
        timeZone: timezone,
      });
    } catch {
      return raw;
    }
  };

  return (
    <div data-slot="deliverability-chart" className="h-[280px] w-full">
      <ResponsiveContainer width="100%" height="100%" initialDimension={{ width: 1, height: 1 }}>
        <ComposedChart data={data} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
          <CartesianGrid vertical={false} strokeDasharray="3 3" stroke="oklch(90% 0.005 60)" />
          <XAxis
            dataKey="date"
            tickFormatter={formatTick}
            tick={{ fontSize: 11 }}
            tickLine={false}
            axisLine={false}
            interval="preserveStartEnd"
            minTickGap={24}
          />
          <YAxis
            allowDecimals={false}
            tick={{ fontSize: 11 }}
            tickLine={false}
            axisLine={false}
            width={32}
          />
          <Tooltip
            labelFormatter={(raw) =>
              typeof raw === "string" ? formatDate(raw, locale, timezone) : String(raw)
            }
            contentStyle={{
              fontSize: "12px",
              borderRadius: "8px",
              border: "1px solid oklch(90% 0.005 60)",
            }}
          />
          <Legend
            wrapperStyle={{ fontSize: "12px", paddingTop: "8px" }}
            iconType="circle"
            iconSize={8}
          />
          {/*
            Sent / delivered stack at the bottom as bars (volume metric),
            bounced / spam ride on top as lines so spikes are obvious even
            when their counts are tiny relative to sent.
          */}
          <Bar
            dataKey="sent"
            name={t("notifications.email_health.metrics.sent")}
            fill="oklch(70% 0.04 240)"
            radius={[2, 2, 0, 0]}
            barSize={12}
          />
          <Bar
            dataKey="delivered"
            name={t("notifications.email_health.metrics.delivered")}
            fill="oklch(72% 0.13 155)"
            radius={[2, 2, 0, 0]}
            barSize={12}
          />
          <Line
            type="monotone"
            dataKey="bounced"
            name={t("notifications.email_health.metrics.bounced")}
            stroke="oklch(68% 0.16 60)"
            strokeWidth={2}
            dot={{ r: 3 }}
            activeDot={{ r: 5 }}
          />
          <Line
            type="monotone"
            dataKey="spam"
            name={t("notifications.email_health.metrics.spam")}
            stroke="oklch(60% 0.20 25)"
            strokeWidth={2}
            dot={{ r: 3 }}
            activeDot={{ r: 5 }}
          />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}
