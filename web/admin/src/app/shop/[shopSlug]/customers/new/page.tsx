"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useCreateCustomer } from "@/hooks/api/use-customers";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import type { CreateCustomerInput } from "@/services/customer-service";
import { CustomerForm } from "../components/customer-form";

export default function NewCustomerPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const router = useRouter();
  const { t } = useTranslation();
  const create = useCreateCustomer(shopSlug);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  async function handleSubmit(data: CreateCustomerInput) {
    setFieldErrors({});
    try {
      const result = await create.mutateAsync(data);
      router.push(`/shop/${shopSlug}/customers/${result.data.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  return (
    <>
      <PageHeader title={t("shop.customers.new_title")} backHref={`/shop/${shopSlug}/customers`} />
      <PageContent>
        <div className="max-w-xl">
          <CustomerForm
            mode="create"
            isPending={create.isPending}
            fieldErrors={fieldErrors}
            onSubmit={handleSubmit}
            onCancel={() => router.push(`/shop/${shopSlug}/customers`)}
          />
        </div>
      </PageContent>
    </>
  );
}
