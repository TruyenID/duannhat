"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { Download, ExternalLink, Search } from "lucide-react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { ApiError } from "@/lib/api";
import { useTraceCustomerOrder, useTraceLot } from "@/hooks/api/use-trace";
import { useHqMaterialLots } from "@/hooks/api/use-material-lots";
import { useDebounce } from "@/hooks/use-debounce";
import { useTranslation } from "@/providers/app-provider";
import {
  isCycle,
  isLeafNode,
  isLotNode,
  isTruncated,
  type TraceLotNode,
  type TraceNode,
} from "@/services/trace-service";

import { flattenTrace, TraceExportDialog, type TraceRow } from "./components/trace-export-dialog";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Input,
  Spinner,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";

// Walk the trace tree for any depth-truncation marker so the page can offer a
// "Load deeper levels" action when the tree was cut at max_depth (TC-TRACE-105).
function lotTreeTruncated(lot: TraceLotNode): boolean {
  const walk = (nodes: TraceNode[]): boolean =>
    nodes.some((n) => isTruncated(n) || (isLotNode(n) && (walk(n.parents) || walk(n.children))));
  return walk(lot.parents) || walk(lot.children);
}

// Error state for a failed trace query (e.g. API 500) — message + retry, so a
// failed lookup no longer renders a blank screen (TC-TRACE-109). A 429 gets a
// dedicated "slow down" message: the trace endpoints are rate-limited, so the
// generic "failed to load" copy would be misleading and tempt the user into a
// retry storm that keeps the lockout alive.
function TraceError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const { t } = useTranslation();
  const rateLimited = error instanceof ApiError && error.status === 429;
  return (
    <Card>
      <CardContent className="flex flex-col items-center gap-3 py-8 text-sm text-muted-foreground">
        <p>{rateLimited ? t("trace.rate_limited") : t("common.error_loading")}</p>
        <Button variant="outline" size="sm" onClick={onRetry}>
          {t("common.retry")}
        </Button>
      </CardContent>
    </Card>
  );
}

export default function HqTracePage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string }>();

  const [selectedLot, setSelectedLot] = useState("");
  const [lotSearch, setLotSearch] = useState("");
  // Combobox open state — dropdown shows on focus (default list, no search
  // required) and stays open while typing, so the user can either browse or
  // search. Closes on blur (delayed so a click on a row still registers).
  const [lotPickerOpen, setLotPickerOpen] = useState(false);
  const [orderInput, setOrderInput] = useState("");
  const [submittedOrder, setSubmittedOrder] = useState("");
  // Trace depth — default 10 (TC-TRACE-105; was 6). "Load deeper levels" bumps
  // it when the tree is truncated; reset to 10 on each new lot/order.
  const [maxDepth, setMaxDepth] = useState(10);
  // Export preview dialog (TC-TRACE-110) — null when closed; holds the flattened
  // affected entities + filename when open.
  const [exportData, setExportData] = useState<{
    subject: string;
    filename: string;
    rows: TraceRow[];
  } | null>(null);

  // Server-side lot search (debounced 300ms) — the backend `search` filter
  // matches lot_code / supplier code / material sku+name, so lots beyond the
  // first page are findable (the old client-side Combobox over 100 preloaded
  // lots couldn't find them). TC-TRACE-101.
  const debouncedLotSearch = useDebounce(lotSearch, 300);
  const { data: lotList, isLoading: lotsLoading } = useHqMaterialLots(params.brandSlug, {
    search: debouncedLotSearch.trim() || undefined,
    per_page: 20,
  });
  const lots = lotList?.data ?? [];

  const lotTrace = useTraceLot(params.brandSlug, selectedLot || undefined, { maxDepth });
  const orderTrace = useTraceCustomerOrder(params.brandSlug, submittedOrder || undefined, {
    maxDepth,
  });

  return (
    <>
      <PageHeader title={t("trace.page_title")} description={t("trace.page_subtitle")} />
      <PageContent>
        <Tabs defaultValue="by-lot">
          <TabsList>
            <TabsTrigger value="by-lot">{t("trace.tab_by_lot")}</TabsTrigger>
            <TabsTrigger value="by-order">{t("trace.tab_by_order")}</TabsTrigger>
          </TabsList>

          <TabsContent value="by-lot" className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle>{t("trace.lot_picker_title")}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="relative">
                  <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    className="pl-8"
                    placeholder={t("trace.lot_picker_placeholder")}
                    value={lotSearch}
                    onChange={(e) => setLotSearch(e.target.value)}
                    onFocus={() => setLotPickerOpen(true)}
                    // Delay close so a click on a result row still fires before
                    // the dropdown unmounts.
                    onBlur={() => setTimeout(() => setLotPickerOpen(false), 150)}
                  />
                  {lotPickerOpen ? (
                    <div className="absolute z-10 mt-1 w-full rounded-md border bg-popover shadow-md">
                      {lotsLoading ? (
                        <Spinner className="mx-auto my-3" />
                      ) : lots.length === 0 ? (
                        <p className="py-3 text-center text-sm text-muted-foreground">
                          {debouncedLotSearch.trim()
                            ? t("trace.lot_no_results")
                            : t("trace.lot_picker_empty")}
                        </p>
                      ) : (
                        <div className="max-h-60 divide-y overflow-y-auto">
                          {lots.map((l) => (
                            <button
                              key={l.id}
                              type="button"
                              // onMouseDown (not onClick) so selection fires
                              // before the input's onBlur closes the dropdown.
                              onMouseDown={(e) => {
                                e.preventDefault();
                                setSelectedLot(l.id);
                                setLotSearch(l.lot_code);
                                setMaxDepth(10);
                                setLotPickerOpen(false);
                              }}
                              className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-accent ${
                                selectedLot === l.id ? "bg-accent" : ""
                              }`}
                            >
                              <span className="font-medium">{l.lot_code}</span>
                              <span className="text-xs text-muted-foreground">
                                {l.material?.sku ?? l.material_id}
                              </span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  ) : null}
                </div>
              </CardContent>
            </Card>

            {lotTrace.isLoading ? (
              <Spinner className="mx-auto" />
            ) : lotTrace.isError ? (
              <TraceError error={lotTrace.error} onRetry={() => lotTrace.refetch()} />
            ) : lotTrace.data?.data ? (
              <>
                <TraceLotResult
                  node={lotTrace.data.data}
                  onExport={() =>
                    setExportData({
                      subject: lotTrace.data!.data.lot_code,
                      filename: `trace-lot-${lotTrace.data!.data.lot_code}.csv`,
                      rows: flattenTrace([lotTrace.data!.data]),
                    })
                  }
                />
                {lotTreeTruncated(lotTrace.data.data) ? (
                  <div className="flex justify-center">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setMaxDepth((d) => d + 10)}
                      disabled={lotTrace.isFetching}
                    >
                      {lotTrace.isFetching ? <Spinner className="mr-2 size-4" /> : null}
                      {t("trace.load_more")}
                    </Button>
                  </div>
                ) : null}
              </>
            ) : null}
          </TabsContent>

          <TabsContent value="by-order" className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle>{t("trace.order_picker_title")}</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="flex gap-2">
                  <Input
                    placeholder={t("trace.order_picker_placeholder")}
                    value={orderInput}
                    onChange={(e) => setOrderInput(e.target.value)}
                  />
                  <Button
                    onClick={() => {
                      setSubmittedOrder(orderInput.trim());
                      setMaxDepth(10);
                    }}
                  >
                    <Search className="mr-2 size-4" />
                    {t("trace.lookup_button")}
                  </Button>
                </div>
              </CardContent>
            </Card>

            {orderTrace.isLoading ? (
              <Spinner className="mx-auto" />
            ) : orderTrace.isError ? (
              <TraceError error={orderTrace.error} onRetry={() => orderTrace.refetch()} />
            ) : orderTrace.data?.data ? (
              <>
                <TraceOrderResult
                  data={orderTrace.data.data}
                  onExport={() =>
                    setExportData({
                      subject: orderTrace.data!.data.customer_order_id,
                      filename: `trace-order-${orderTrace.data!.data.customer_order_id}.csv`,
                      rows: flattenTrace(orderTrace.data!.data.direct_lots),
                    })
                  }
                />
                {orderTrace.data.data.truncated ? (
                  <div className="flex justify-center">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setMaxDepth((d) => d + 10)}
                      disabled={orderTrace.isFetching}
                    >
                      {orderTrace.isFetching ? <Spinner className="mr-2 size-4" /> : null}
                      {t("trace.load_more")}
                    </Button>
                  </div>
                ) : null}
              </>
            ) : null}
          </TabsContent>
        </Tabs>
      </PageContent>

      <TraceExportDialog
        open={exportData !== null}
        onOpenChange={(open) => {
          if (!open) setExportData(null);
        }}
        subject={exportData?.subject ?? ""}
        filename={exportData?.filename ?? ""}
        rows={exportData?.rows ?? []}
      />
    </>
  );
}

// =========================================================================
//  Tree rendering
// =========================================================================

function TraceLotResult({ node, onExport }: { node: TraceLotNode; onExport: () => void }) {
  const { t } = useTranslation();
  const { brandSlug } = useParams<{ brandSlug: string }>();
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
        <CardTitle>
          <Link
            href={`/hq/${brandSlug}/material-lots/${node.lot_id}`}
            className="inline-flex items-center gap-1.5 text-primary hover:underline"
          >
            {node.lot_code}
            <ExternalLink className="size-4" />
          </Link>
        </CardTitle>
        <Button variant="outline" size="sm" onClick={onExport}>
          <Download className="mr-2 size-4" />
          {t("trace.export_csv")}
        </Button>
      </CardHeader>
      <CardContent className="space-y-4">
        <Section title={t("trace.section_parents")} nodes={node.parents} side="parents" />
        <Section title={t("trace.section_children")} nodes={node.children} side="children" />
      </CardContent>
    </Card>
  );
}

function TraceOrderResult({
  data,
  onExport,
}: {
  data: { customer_order_id: string; direct_lots: TraceLotNode[]; truncated: boolean };
  onExport: () => void;
}) {
  const { t } = useTranslation();
  const { brandSlug } = useParams<{ brandSlug: string }>();
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
        <CardTitle className="flex items-center gap-2">
          <span>
            {t("trace.order_result_title")} ({data.direct_lots.length}
            {data.truncated ? "+" : ""})
          </span>
          <Link
            href={`/hq/${brandSlug}/orders/${data.customer_order_id}`}
            aria-label={t("trace.open_detail")}
            title={t("trace.open_detail")}
            className="inline-flex items-center text-primary hover:underline"
          >
            <ExternalLink className="size-4" />
          </Link>
        </CardTitle>
        {data.direct_lots.length > 0 ? (
          <Button variant="outline" size="sm" onClick={onExport}>
            <Download className="mr-2 size-4" />
            {t("trace.export_csv")}
          </Button>
        ) : null}
      </CardHeader>
      <CardContent>
        {data.direct_lots.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("trace.no_edges")}</p>
        ) : (
          <Section title={t("trace.section_source_lots")} nodes={data.direct_lots} side="parents" />
        )}
      </CardContent>
    </Card>
  );
}

function Section({
  title,
  nodes,
  side,
}: {
  title: string;
  nodes: TraceNode[];
  side: "parents" | "children";
}) {
  const { t } = useTranslation();
  if (nodes.length === 0) {
    return (
      <div>
        <h4 className="mb-2 text-sm font-medium">{title}</h4>
        <p className="text-sm text-muted-foreground">{t("trace.empty")}</p>
      </div>
    );
  }
  return (
    <div>
      <h4 className="mb-2 text-sm font-medium">{title}</h4>
      <Accordion type="multiple" className="w-full">
        {nodes.map((n, idx) => (
          <NodeRow key={`${side}-${idx}`} node={n} side={side} />
        ))}
      </Accordion>
    </div>
  );
}

function NodeRow({ node, side }: { node: TraceNode; side: "parents" | "children" }) {
  const { t } = useTranslation();
  const { brandSlug } = useParams<{ brandSlug: string }>();

  if (isCycle(node)) {
    return (
      <div className="px-4 py-2 text-sm text-amber-700">
        ↻ {t("trace.cycle_detected")}: {node.lot_id}
      </div>
    );
  }

  if (isTruncated(node)) {
    return <div className="px-4 py-2 text-sm text-muted-foreground">⋯ {t("trace.truncated")}</div>;
  }

  if (isLeafNode(node)) {
    // A customer-order leaf links to the order detail; disposal / manual
    // adjustments have no detail page, so they render as plain text.
    const reference = node.customer_order_id ?? node.edge.source_event_id ?? "—";
    return (
      <div className="flex items-center justify-between gap-2 px-4 py-2 text-sm">
        <Badge variant="outline">{node.leaf_type}</Badge>
        {node.leaf_type === "customer_order" && node.customer_order_id ? (
          <Link
            href={`/hq/${brandSlug}/orders/${node.customer_order_id}`}
            className="inline-flex items-center gap-1 font-mono text-xs text-primary hover:underline"
          >
            {node.customer_order_id}
            <ExternalLink className="size-3" />
          </Link>
        ) : (
          <span className="font-mono text-xs">{reference}</span>
        )}
        <span className="tabular-nums">
          {node.edge.qty_consumed} {node.edge.unit ?? ""}
        </span>
      </div>
    );
  }

  if (isLotNode(node)) {
    const childList = side === "parents" ? node.parents : node.children;
    return (
      <AccordionItem value={node.lot_id}>
        {/* Grid so the trigger fills the row while the detail link sits beside
            it as a sibling — avoids an <a> nested inside the trigger <button>. */}
        <div className="grid grid-cols-[1fr_auto] items-center gap-1 pr-2">
          <AccordionTrigger>
            <div className="flex w-full items-center justify-between pr-2 text-left">
              <div>
                <div className="font-medium">{node.lot_code}</div>
                <div className="text-xs text-muted-foreground">
                  {node.material.sku ?? node.material.id} · {node.warehouse.name}
                </div>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant="outline">{node.source}</Badge>
                <Badge>{node.status}</Badge>
                {node.edge ? (
                  <span className="text-xs tabular-nums">
                    {node.edge.qty_consumed} {node.edge.unit ?? ""}
                  </span>
                ) : null}
              </div>
            </div>
          </AccordionTrigger>
          <Link
            href={`/hq/${brandSlug}/material-lots/${node.lot_id}`}
            aria-label={t("trace.open_detail")}
            title={t("trace.open_detail")}
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <ExternalLink className="size-4" />
          </Link>
        </div>
        <AccordionContent className="space-y-1 pl-4">
          {childList.length === 0 ? (
            <p className="text-xs text-muted-foreground">{t("trace.empty")}</p>
          ) : (
            <Accordion type="multiple" className="w-full">
              {childList.map((c, i) => (
                <NodeRow key={`${node.lot_id}-${i}`} node={c} side={side} />
              ))}
            </Accordion>
          )}
        </AccordionContent>
      </AccordionItem>
    );
  }

  return null;
}
