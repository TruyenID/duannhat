import { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle, Input, Separator } from "@godxjp/ui";
import { getDailyReport, getPopularItems } from "../lib/api";
import type { DailyReport, PopularItem } from "../lib/api";
import { useTranslation } from "../providers/app-provider";
import { PageHeader } from "../components/layout/page-header";
import { PageContent } from "../components/layout/page-content";

export function Reports() {
  const { t } = useTranslation();
  const [date, setDate] = useState(new Date().toISOString().split("T")[0]);
  const [report, setReport] = useState<DailyReport | null>(null);
  const [popular, setPopular] = useState<PopularItem[]>([]);

  useEffect(() => { loadReport(); }, [date]);

  async function loadReport() {
    try {
      const r = await getDailyReport(date);
      if (r) setReport(r);
      const p = await getPopularItems(date, 10);
      if (p) setPopular(p);
    } catch {}
  }

  const formatPrice = (amount: number) => new Intl.NumberFormat("ja-JP").format(amount);

  return (
    <>
      <PageHeader title={t("nav.reports")}>
        <Input
          type="date"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          className="w-auto"
        />
      </PageHeader>
      <PageContent>
        <div className="grid grid-cols-4 gap-4 mb-6">
          <Card>
            <CardContent className="pt-4">
              <div className="text-xs text-muted-foreground mb-1">Total Orders</div>
              <div className="text-xl font-bold">{report?.total_orders ?? 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4">
              <div className="text-xs text-muted-foreground mb-1">Paid Orders</div>
              <div className="text-xl font-bold text-success">{report?.paid_orders ?? 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4">
              <div className="text-xs text-muted-foreground mb-1">Revenue</div>
              <div className="text-xl font-bold text-warning">{formatPrice(report?.total_revenue ?? 0)}</div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4">
              <div className="text-xs text-muted-foreground mb-1">Avg Order Value</div>
              <div className="text-xl font-bold">{formatPrice(report?.avg_order_value ?? 0)}</div>
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Popular Items</CardTitle>
          </CardHeader>
          <CardContent>
            {popular.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t("common.no_data")}</p>
            ) : (
              <div>
                <div className="grid grid-cols-4 gap-2 text-xs font-medium text-muted-foreground pb-2">
                  <span>Item</span>
                  <span>Category</span>
                  <span className="text-right">Qty</span>
                  <span className="text-right">Revenue</span>
                </div>
                <Separator className="mb-2" />
                {popular.map((item, i) => (
                  <div key={i} className="grid grid-cols-4 gap-2 py-1.5 text-sm">
                    <span className="font-medium">{item.name}</span>
                    <span className="text-xs capitalize text-muted-foreground">{item.category}</span>
                    <span className="text-right">{item.quantity}</span>
                    <span className="text-right font-medium">{formatPrice(item.revenue)}</span>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </PageContent>
    </>
  );
}
