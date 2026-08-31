"use client";
import { useState } from "react";
import { useTranslation } from "@/providers/app-provider";
import type { Customer, CreateCustomerInput } from "@/services/customer-service";
import { Button, Card, Input, Label, Textarea, Spinner } from "@godxjp/ui";
export interface CustomerFormProps {
  mode: "create" | "edit";
  initialValues?: Customer | null;
  isPending?: boolean;
  fieldErrors?: Record<string, string[]>;
  onSubmit: (data: CreateCustomerInput) => void;
  onCancel: () => void;
}
export function CustomerForm({
  mode,
  initialValues,
  isPending,
  fieldErrors = {},
  onSubmit,
  onCancel,
}: CustomerFormProps) {
  const { t } = useTranslation();
  const [firstName, setFirstName] = useState(initialValues?.first_name ?? "");
  const [lastName, setLastName] = useState(initialValues?.last_name ?? "");
  const [phone, setPhone] = useState(initialValues?.phone ?? "");
  const [email, setEmail] = useState(initialValues?.email ?? "");
  const [address, setAddress] = useState(initialValues?.address ?? "");
  const [taxCode, setTaxCode] = useState(initialValues?.tax_code ?? "");
  const [note, setNote] = useState(initialValues?.note ?? "");
  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    onSubmit({
      first_name: firstName,
      last_name: lastName || null,
      phone: phone || null,
      email: email || null,
      address: address || null,
      tax_code: taxCode || null,
      note: note || null,
    });
  }
  return (
    <form onSubmit={handleSubmit} data-slot="customer-form">
      <Card className="space-y-4 p-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="cf-first-name">First name *</Label>
            <Input
              id="cf-first-name"
              value={firstName}
              onChange={(e) => setFirstName(e.target.value)}
              required
              maxLength={100}
            />
            {fieldErrors.first_name?.[0] && (
              <p className="text-[11px] text-red-500">{fieldErrors.first_name[0]}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cf-last-name">Last name</Label>
            <Input
              id="cf-last-name"
              value={lastName}
              onChange={(e) => setLastName(e.target.value)}
              maxLength={100}
            />
            {fieldErrors.last_name?.[0] && (
              <p className="text-[11px] text-red-500">{fieldErrors.last_name[0]}</p>
            )}
          </div>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="cf-phone">{t("shop.customers.form.phone_label")}</Label>
            <Input
              id="cf-phone"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              maxLength={20}
            />
            {fieldErrors.phone?.[0] && (
              <p className="text-[11px] text-red-500">{fieldErrors.phone[0]}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cf-email">{t("shop.customers.form.email_label")}</Label>
            <Input
              id="cf-email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
            {fieldErrors.email?.[0] && (
              <p className="text-[11px] text-red-500">{fieldErrors.email[0]}</p>
            )}
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="cf-address">{t("shop.customers.form.address_label")}</Label>
          <Input
            id="cf-address"
            value={address}
            onChange={(e) => setAddress(e.target.value)}
            maxLength={500}
          />
          {fieldErrors.address?.[0] && (
            <p className="text-[11px] text-red-500">{fieldErrors.address[0]}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="cf-tax">{t("shop.customers.form.tax_code_label")}</Label>
          <Input
            id="cf-tax"
            value={taxCode}
            onChange={(e) => setTaxCode(e.target.value)}
            maxLength={50}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="cf-note">{t("shop.customers.form.note_label")}</Label>
          <Textarea
            id="cf-note"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            rows={3}
            maxLength={1000}
            className="field-sizing-fixed"
          />
        </div>
        <div className="flex items-center gap-2 pt-2">
          <Button type="submit" disabled={isPending || !firstName.trim()}>
            {isPending && <Spinner className="mr-2 size-3.5" />}
            {mode === "create" ? t("common.create") : t("common.save")}
          </Button>
          <Button type="button" variant="outline" onClick={onCancel}>
            {t("common.cancel")}
          </Button>
        </div>
      </Card>
    </form>
  );
}
