"use client";
import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { Pencil, Trash2 } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useCustomer, useDeleteCustomer } from "@/hooks/api/use-customers";
import { useOrders } from "@/hooks/api/use-orders";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { useShopCurrency } from "@/providers/shop-currency-provider";
import { formatCurrency } from "@/lib/currency";
import { formatDate, formatDateTime } from "@/lib/date";
import { customerFullName } from "@/services/customer-service";
import { Button, Card, Spinner } from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { CustomerPointsCard } from "@/components/shared/customer-points-card";
export default function CustomerDetailPage() {
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const router = useRouter();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const shopCurrency = useShopCurrency();
  const { data: customerData, isLoading, error } = useCustomer(shopSlug, id);
  const deleteMutation = useDeleteCustomer(shopSlug);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const { data: ordersData, isLoading: ordersLoading } = useOrders(shopSlug, {
    customer_id: id,
    per_page: 10,
  });
  const customer = customerData?.data;
  const orders = ordersData?.data ?? [];
  if (isLoading) {
    return (
      <>
        <PageHeader
          title={t("shop.customers.detail_title")}
          backHref={`/shop/${shopSlug}/customers`}
        />
        <PageContent>
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
            <span className="ml-2 text-sm text-muted-foreground">{t("common.loading")}</span>
          </div>
        </PageContent>
      </>
    );
  }
  if (error || !customer) {
    return (
      <>
        <PageHeader
          title={t("shop.customers.detail_title")}
          backHref={`/shop/${shopSlug}/customers`}
        />
        <PageContent>
          <div className="py-12 text-center text-sm text-muted-foreground">
            {t("shop.customers.not_found")}
          </div>
        </PageContent>
      </>
    );
  }
  return (
    <>
      <PageHeader title={customerFullName(customer)} backHref={`/shop/${shopSlug}/customers`}>
        <Button variant="outline" size="sm" asChild>
          <Link href={`/shop/${shopSlug}/customers/${id}/edit`}>
            <Pencil className="mr-1.5 size-3.5" />
            {t("common.edit")}
          </Link>
        </Button>
        <Button variant="outline" size="sm" onClick={() => setConfirmDelete(true)}>
          <Trash2 className="mr-1.5 size-3.5" />
          {t("common.delete")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="max-w-2xl space-y-4">
          <Card className="p-4">
            <h2 className="mb-3 text-sm font-semibold">{t("shop.customers.info")}</h2>
            <dl className="grid grid-cols-[120px_1fr] gap-x-4 gap-y-2 text-sm">
              <dt className="text-muted-foreground">Phone</dt>
              <dd>{customer.phone ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("common.email")}</dt>
              <dd>{customer.email ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("shop.customers.field.address")}</dt>
              <dd>{customer.address ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("shop.customers.field.tax_code")}</dt>
              <dd>{customer.tax_code ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("shop.customers.field.note")}</dt>
              <dd>{customer.note ?? "—"}</dd>
              <dt className="text-muted-foreground">{t("common.created")}</dt>
              <dd>{formatDateTime(customer.created_at, locale, timezone)}</dd>
            </dl>
          </Card>
          <Card className="p-4">
            <h2 className="mb-3 text-sm font-semibold">{t("shop.customers.order_history")}</h2>
            {ordersLoading && (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner className="size-3.5" /> {t("common.loading")}
              </div>
            )}
            {!ordersLoading && orders.length === 0 && (
              <p className="text-sm text-muted-foreground">
                {t("shop.customers.no_orders_branch")}
              </p>
            )}
            {!ordersLoading && orders.length > 0 && (
              <div className="space-y-1">
                {orders.map((o) => (
                  <Link
                    key={o.id}
                    href={`/shop/${shopSlug}/orders/${o.id}`}
                    className="flex items-center justify-between rounded px-2 py-1.5 text-sm hover:bg-muted"
                  >
                    <span className="font-mono text-xs">{o.order_code}</span>
                    <span className="text-xs text-muted-foreground capitalize">
                      {t(`shop.orders.status.${o.status}`)}
                    </span>
                    <span className="tabular-nums">
                      {/* Shop-scoped money: the shop's currency, not the
                          reader's language (#1260). */}
                      {formatCurrency(o.total_amount, locale, shopCurrency ?? undefined)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {formatDate(o.created_at, locale, timezone)}
                    </span>
                  </Link>
                ))}
              </div>
            )}
          </Card>

          <CustomerPointsCard
            scope={{ kind: "shop", shopSlug }}
            customerId={id}
            ordersBasePath={`/shop/${shopSlug}/orders`}
          />
        </div>
      </PageContent>

      <DeleteConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        description={t("shop.customers.delete_this_confirm")}
        onConfirm={() => {
          setConfirmDelete(false);
          deleteMutation.mutate(id, {
            onSuccess: () => router.push(`/shop/${shopSlug}/customers`),
          });
        }}
        isPending={deleteMutation.isPending}
      />
    </>
  );
}
