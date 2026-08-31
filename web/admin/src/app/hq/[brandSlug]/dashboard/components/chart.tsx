import * as React from "react";
import * as RechartsPrimitive from "recharts";

import { cn } from "@/lib/utils";

// Format: { THEME_NAME: CSS_SELECTOR }
const THEMES = { light: "", dark: ".dark" } as const;

/**
 * Configuration object for chart data series. Each key maps to a data series
 * and defines its label, optional icon, and color (either a single color or
 * per-theme colors).
 *
 * @example
 * ```tsx
 * const config: ChartConfig = {
 *   revenue: { label: "Revenue", color: "var(--color-blue-500)" },
 *   expenses: { label: "Expenses", theme: { light: "#ef4444", dark: "#f87171" } },
 * };
 * ```
 */
export type ChartConfig = {
  [k in string]: {
    /** Display label for this data series. */
    label?: React.ReactNode;
    /** Optional icon component displayed in the legend. */
    icon?: React.ComponentType;
  } & (
    | { /** Single color used across all themes. */ color?: string; theme?: never }
    | {
        color?: never;
        /** Per-theme color mapping (light/dark). */ theme: Record<keyof typeof THEMES, string>;
      }
  );
};

type ChartContextProps = {
  config: ChartConfig;
};

const ChartContext = React.createContext<ChartContextProps | null>(null);

/**
 * Hook to access the chart configuration from the nearest ChartContainer.
 * Must be used within a {@link ChartContainer}.
 *
 * @throws If used outside of a ChartContainer.
 */
function useChart() {
  const context = React.useContext(ChartContext);

  if (!context) {
    throw new Error("useChart must be used within a <ChartContainer />");
  }

  return context;
}

/**
 * Wrapper that provides chart configuration context, injects theme-aware CSS
 * custom properties for data series colors, and renders a Recharts
 * `ResponsiveContainer`.
 *
 * @param config - Chart configuration mapping data keys to labels, icons, and colors.
 *
 * @example
 * ```tsx
 * <ChartContainer config={{ revenue: { label: "Revenue", color: "#3b82f6" } }}>
 *   <BarChart data={data}>
 *     <Bar dataKey="revenue" fill="var(--color-revenue)" />
 *   </BarChart>
 * </ChartContainer>
 * ```
 */
function ChartContainer({
  id,
  className,
  children,
  config,
  style,
  ...props
}: React.ComponentProps<"div"> & {
  /** Chart configuration mapping data keys to labels, icons, and colors. */
  config: ChartConfig;
  /** Recharts chart element (e.g. BarChart, LineChart). */
  children: React.ComponentProps<typeof RechartsPrimitive.ResponsiveContainer>["children"];
}) {
  const uniqueId = React.useId();
  const chartId = `chart-${id || uniqueId.replace(/:/g, "")}`;
  const chartContextValue = React.useMemo(() => ({ config }), [config]);

  // Build CSS custom properties for all color entries so they are available
  // as var(--color-<key>) inside the chart, without injecting a <style> tag.
  const colorVars = React.useMemo(() => {
    const vars: Record<string, string> = {};
    for (const [key, itemConfig] of Object.entries(config)) {
      // Prefer the light-theme color when a theme map is provided
      const color =
        itemConfig.theme?.["light" as keyof typeof itemConfig.theme] ?? itemConfig.color;
      if (color) {
        vars[`--color-${key}`] = color;
      }
    }
    return vars;
  }, [config]);

  return (
    <ChartContext.Provider value={chartContextValue}>
      <div
        data-slot="chart"
        data-chart={chartId}
        className={cn(
          "aspect-video justify-center text-xs [&_.recharts-cartesian-axis-tick_text]:fill-muted-foreground [&_.recharts-cartesian-grid_line[stroke='#ccc']]:stroke-border/50 [&_.recharts-curve.recharts-tooltip-cursor]:stroke-border [&_.recharts-dot[stroke='#fff']]:stroke-transparent [&_.recharts-layer]:outline-hidden [&_.recharts-polar-grid_[stroke='#ccc']]:stroke-border [&_.recharts-radial-bar-background-sector]:fill-muted [&_.recharts-rectangle.recharts-tooltip-cursor]:fill-muted [&_.recharts-reference-line_[stroke='#ccc']]:stroke-border [&_.recharts-sector]:outline-hidden [&_.recharts-sector[stroke='#fff']]:stroke-transparent [&_.recharts-surface]:outline-hidden",
          className
        )}
        style={{ ...colorVars, ...style } as React.CSSProperties}
        {...props}
      >
        {/*
          Recharts' ResponsiveContainer starts its internal size state at
          {-1, -1} and logs "width(-1) height(-1)" on the very first render,
          before its own ResizeObserver has measured the parent. Passing a
          positive `initialDimension` bypasses that check — the observer
          still corrects to real parent dimensions on the next tick, so
          nothing changes visually. See godx-jp/godx-tempo-ui#1.
        */}
        <RechartsPrimitive.ResponsiveContainer initialDimension={{ width: 1, height: 1 }}>
          {children}
        </RechartsPrimitive.ResponsiveContainer>
      </div>
    </ChartContext.Provider>
  );
}

/**
 * @deprecated CSS custom properties are now injected via inline `style` on the
 * ChartContainer element. This component is kept as a no-op so existing import
 * sites don't break.
 */
const ChartStyle = (_props: { id: string; config: ChartConfig }) => null;

/** Re-export of Recharts Tooltip for use with ChartTooltipContent. */
const ChartTooltip = RechartsPrimitive.Tooltip;

/**
 * Styled tooltip content for use inside `<ChartTooltip content={<ChartTooltipContent />} />`.
 * Renders data series with color indicators, labels from ChartConfig, and formatted values.
 *
 * @param indicator - Shape of the color indicator: `"dot"`, `"line"`, or `"dashed"`.
 * @param hideLabel - Whether to hide the tooltip header label.
 * @param hideIndicator - Whether to hide the color indicator.
 * @param nameKey - Data key to resolve series name from the payload.
 * @param labelKey - Data key to resolve the tooltip header label from config.
 */
function ChartTooltipContent({
  active,
  payload,
  className,
  indicator = "dot",
  hideLabel = false,
  hideIndicator = false,
  label,
  labelFormatter,
  labelClassName,
  formatter,
  color,
  nameKey,
  labelKey,
}: React.ComponentProps<"div"> & {
  active?: boolean;
  payload?: Array<{
    name?: string;
    value?: string | number;
    dataKey?: string;
    color?: string;
    fill?: string;
    payload?: Record<string, unknown>;
    [key: string]: unknown;
  }>;
  label?: string;
  labelClassName?: string;
  labelFormatter?: (label: unknown, payload: Array<Record<string, unknown>>) => React.ReactNode;
  formatter?: (
    value: unknown,
    name: string,
    item: Record<string, unknown>,
    index: number,
    payload: unknown
  ) => React.ReactNode;
  color?: string;
  /** Whether to hide the tooltip header label. */
  hideLabel?: boolean;
  /** Whether to hide the color indicator next to each series. */
  hideIndicator?: boolean;
  /** Shape of the color indicator. Defaults to `"dot"`. */
  indicator?: "line" | "dot" | "dashed";
  /** Data key used to resolve the series name from payload. */
  nameKey?: string;
  /** Data key used to resolve the header label from config. */
  labelKey?: string;
}) {
  const { config } = useChart();

  const tooltipLabel = React.useMemo(() => {
    if (hideLabel || !payload?.length) {
      return null;
    }

    const [item] = payload;
    const key = `${labelKey || item?.dataKey || item?.name || "value"}`;
    const itemConfig = getPayloadConfigFromPayload(config, item, key);
    const value =
      !labelKey && typeof label === "string"
        ? config[label as keyof typeof config]?.label || label
        : itemConfig?.label;

    if (labelFormatter) {
      return (
        <div className={cn("font-medium", labelClassName)}>{labelFormatter(value, payload)}</div>
      );
    }

    if (!value) {
      return null;
    }

    return <div className={cn("font-medium", labelClassName)}>{value}</div>;
  }, [label, labelFormatter, payload, hideLabel, labelClassName, config, labelKey]);

  if (!active || !payload?.length) {
    return null;
  }

  const nestLabel = payload.length === 1 && indicator !== "dot";

  return (
    <div
      className={cn(
        "grid min-w-[8rem] items-start gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl",
        className
      )}
    >
      {!nestLabel ? tooltipLabel : null}
      <div className="grid gap-1.5">
        {payload.map((item, index) => {
          const key = `${nameKey || item.name || item.dataKey || "value"}`;
          const itemConfig = getPayloadConfigFromPayload(config, item, key);
          const indicatorColor = color || (item.payload?.fill as string) || item.color;

          return (
            <div
              key={item.dataKey}
              className={cn(
                "flex w-full flex-wrap items-stretch gap-2 [&>svg]:h-2.5 [&>svg]:w-2.5 [&>svg]:text-muted-foreground",
                indicator === "dot" && "items-center"
              )}
            >
              {formatter && item?.value !== undefined && item.name ? (
                formatter(item.value, item.name, item, index, item.payload)
              ) : (
                <>
                  {itemConfig?.icon ? (
                    <itemConfig.icon />
                  ) : (
                    !hideIndicator && (
                      <div
                        className={cn(
                          "shrink-0 rounded-[2px] border-(--color-border) bg-(--color-bg)",
                          {
                            "h-2.5 w-2.5": indicator === "dot",
                            "w-1": indicator === "line",
                            "w-0 border-[1.5px] border-dashed bg-transparent":
                              indicator === "dashed",
                            "my-0.5": nestLabel && indicator === "dashed",
                          }
                        )}
                        style={
                          {
                            "--color-bg": indicatorColor,
                            "--color-border": indicatorColor,
                          } as React.CSSProperties
                        }
                      />
                    )
                  )}
                  <div
                    className={cn(
                      "flex flex-1 justify-between leading-none",
                      nestLabel ? "items-end" : "items-center"
                    )}
                  >
                    <div className="grid gap-1.5">
                      {nestLabel ? tooltipLabel : null}
                      <span className="text-muted-foreground">
                        {itemConfig?.label || item.name}
                      </span>
                    </div>
                    {item.value && (
                      <span className="font-mono font-medium text-foreground tabular-nums">
                        {item.value.toLocaleString()}
                      </span>
                    )}
                  </div>
                </>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

/** Re-export of Recharts Legend for use with ChartLegendContent. */
const ChartLegend = RechartsPrimitive.Legend;

/**
 * Styled legend content for use inside `<ChartLegend content={<ChartLegendContent />} />`.
 * Renders a horizontal list of series labels with color indicators or custom icons from ChartConfig.
 *
 * @param hideIcon - Whether to hide the color dot / custom icon.
 * @param nameKey - Data key to resolve the series name from payload.
 */
function ChartLegendContent({
  className,
  hideIcon = false,
  payload,
  verticalAlign = "bottom",
  nameKey,
}: React.ComponentProps<"div"> & {
  payload?: Array<{
    value?: string;
    dataKey?: string;
    color?: string;
    [key: string]: unknown;
  }>;
  verticalAlign?: "top" | "bottom" | "middle";
  /** Whether to hide the color indicator or custom icon. */
  hideIcon?: boolean;
  /** Data key used to resolve the series name from payload. */
  nameKey?: string;
}) {
  const { config } = useChart();

  if (!payload?.length) {
    return null;
  }

  return (
    <div
      className={cn(
        "flex items-center justify-center gap-4",
        verticalAlign === "top" ? "pb-3" : "pt-3",
        className
      )}
    >
      {payload.map((item) => {
        const key = `${nameKey || item.dataKey || "value"}`;
        const itemConfig = getPayloadConfigFromPayload(config, item, key);

        return (
          <div
            key={item.value}
            className={cn(
              "flex items-center gap-1.5 [&>svg]:h-3 [&>svg]:w-3 [&>svg]:text-muted-foreground"
            )}
          >
            {itemConfig?.icon && !hideIcon ? (
              <itemConfig.icon />
            ) : (
              <div
                className="h-2 w-2 shrink-0 rounded-[2px]"
                style={{
                  backgroundColor: item.color,
                }}
              />
            )}
            {itemConfig?.label}
          </div>
        );
      })}
    </div>
  );
}

// Helper to extract item config from a payload.
function getPayloadConfigFromPayload(config: ChartConfig, payload: unknown, key: string) {
  if (typeof payload !== "object" || payload === null) {
    return undefined;
  }

  const payloadPayload =
    "payload" in payload && typeof payload.payload === "object" && payload.payload !== null
      ? payload.payload
      : undefined;

  let configLabelKey: string = key;

  if (key in payload && typeof payload[key as keyof typeof payload] === "string") {
    configLabelKey = payload[key as keyof typeof payload] as string;
  } else if (
    payloadPayload &&
    key in payloadPayload &&
    typeof payloadPayload[key as keyof typeof payloadPayload] === "string"
  ) {
    configLabelKey = payloadPayload[key as keyof typeof payloadPayload] as string;
  }

  return configLabelKey in config ? config[configLabelKey] : config[key as keyof typeof config];
}

export {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  ChartLegend,
  ChartLegendContent,
  ChartStyle,
};
