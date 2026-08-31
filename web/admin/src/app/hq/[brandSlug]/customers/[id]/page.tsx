"use client";
import { notFound, useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { CustomerOrderHistory } from "@/components/shared/customer-order-history";
import { CustomerPointsCard } from "@/components/shared/customer-points-card";
import { useHqCustomer } from "@/hooks/api/use-customers";
import { customerFullName } from "@/services/customer-service";
import { Card, Spinner } from "@godxjp/ui";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
export default function HqCustomerDetailPage() {
  const { brandSlug, id } = useParams<{ brandSlug: string; id: string }>();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { data, isLoading, error } = useHqCustomer(brandSlug, id);
  const customer = data?.data;
  // 404 / 403 → Next.js not-found page (id doesn't exist or no access). Runs
  // before the loading guard so a missing id resolves to a proper 404 instead
  // of an inline "not found" line (TC-CUST-DET2).
  if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }
  if (isLoading) {
    return (
      <>
        <PageHeader
          title={t("hq.customers.detail.title_fallback")}
          backHref={`/hq/${brandSlug}/customers`}
        />
        <PageContent>
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
          </div>
        </PageContent>
      </>
    );
  }
  if (error || !customer) {
    return (
      <>
        <PageHeader
          title={t("hq.customers.detail.title_fallback")}
          backHref={`/hq/${brandSlug}/customers`}
        />
        <PageContent>
          <div className="py-12 text-center text-sm text-muted-foreground">
            {t("hq.customers.detail.not_found")}
          </div>
        </PageContent>
      </>
    );
  }
  return (
    <>
      <PageHeader title={customerFullName(customer)} backHref={`/hq/${brandSlug}/customers`} />
      <PageContent>
        <div className="max-w-2xl space-y-4">
          <Card className="p-4">
            <h2 className="mb-3 text-sm font-semibold">{t("hq.customers.detail.info")}</h2>
            <dl className="grid grid-cols-[120px_1fr] gap-x-4 gap-y-2 text-sm">
              <dt className="text-muted-foreground">First name</dt>
              <dd>{customer.first_name}</dd>
              <dt className="text-muted-foreground">Last name</dt>
              <dd>{customer.last_name ?? "—"}</dd>
              <dt className="text-muted-foreground">Phone</dt>
              <dd>{customer.phone ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("common.email")}</dt>
              <dd>{customer.email ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("hq.customers.detail.address")}</dt>
              <dd>{customer.address ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("hq.customers.detail.tax_code")}</dt>
              <dd>{customer.tax_code ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("hq.customers.detail.note")}</dt>
              <dd>{customer.note ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("common.created")}</dt>
              <dd>{formatDateTime(customer.created_at, locale, timezone)}</dd>
            </dl>
          </Card>
          <CustomerOrderHistory
            orders={customer.orders ?? []}
            basePath={`/hq/${brandSlug}/orders`}
            emptyMessage={t("hq.customers.detail.no_orders_any_branch")}
            showBranch
          />
          <CustomerPointsCard
            scope={{ kind: "hq", brandSlug }}
            customerId={id}
            ordersBasePath={`/hq/${brandSlug}/orders`}
          />
        </div>
      </PageContent>
    </>
  );
}
