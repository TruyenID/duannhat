"use client";
import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useCustomer, useUpdateCustomer } from "@/hooks/api/use-customers";
import { useTranslation } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import { customerFullName, type CreateCustomerInput } from "@/services/customer-service";
import { CustomerForm } from "../../components/customer-form";
import { Spinner } from "@godxjp/ui";
export default function EditCustomerPage() {
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const router = useRouter();
  const { t } = useTranslation();
  const { data: customerData, isLoading } = useCustomer(shopSlug, id);
  const update = useUpdateCustomer(shopSlug);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const customer = customerData?.data;
  if (isLoading) {
    return (
      <>
        <PageHeader
          title={t("shop.customers.edit_title")}
          backHref={`/shop/${shopSlug}/customers/${id}`}
        />
        <PageContent>
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
          </div>
        </PageContent>
      </>
    );
  }
  if (!customer) {
    return (
      <>
        <PageHeader
          title={t("shop.customers.edit_title")}
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
  async function handleSubmit(data: CreateCustomerInput) {
    setFieldErrors({});
    try {
      await update.mutateAsync({ id, data });
      router.push(`/shop/${shopSlug}/customers/${id}`);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }
  return (
    <>
      <PageHeader
        title={`Edit — ${customerFullName(customer)}`}
        backHref={`/shop/${shopSlug}/customers/${id}`}
      />
      <PageContent>
        <div className="max-w-xl">
          <CustomerForm
            mode="edit"
            initialValues={customer}
            isPending={update.isPending}
            fieldErrors={fieldErrors}
            onSubmit={handleSubmit}
            onCancel={() => router.push(`/shop/${shopSlug}/customers/${id}`)}
          />
        </div>
      </PageContent>
    </>
  );
}
