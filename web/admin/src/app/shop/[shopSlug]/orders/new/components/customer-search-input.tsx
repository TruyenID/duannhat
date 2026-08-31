"use client";
import { useState } from "react";
import { Search, X } from "lucide-react";
import { useCustomerLookup } from "@/hooks/api/use-customers";
import { customerFullName, type Customer } from "@/services/customer-service";
import { useTranslation } from "@/providers/app-provider";
import { Input, Spinner } from "@godxjp/ui";
export interface CustomerSearchInputProps {
  shopSlug: string;
  onSelect: (customer: Customer) => void;
  selectedCustomer?: Customer | null;
  onClear: () => void;
}
export function CustomerSearchInput({
  shopSlug,
  onSelect,
  selectedCustomer,
  onClear,
}: CustomerSearchInputProps) {
  const { t } = useTranslation();
  const [phone, setPhone] = useState("");
  const { data, isLoading } = useCustomerLookup(shopSlug, phone);
  const results = data?.data ?? [];
  const showDropdown = phone.length >= 8 && results.length > 0;
  if (selectedCustomer) {
    return (
      <div
        data-slot="customer-search-input"
        className="flex items-center gap-2 rounded border px-3 py-1.5 text-sm"
      >
        <span className="font-medium">{customerFullName(selectedCustomer)}</span>
        {selectedCustomer.phone && (
          <span className="text-muted-foreground">{selectedCustomer.phone}</span>
        )}
        <button
          type="button"
          aria-label={t("shop.orders.new.clear_customer_aria")}
          onClick={() => {
            onClear();
            setPhone("");
          }}
          className="ml-auto text-xs text-muted-foreground hover:text-foreground"
        >
          <X className="h-3.5 w-3.5" aria-hidden />
        </button>
      </div>
    );
  }
  return (
    <div data-slot="customer-search-input" className="relative">
      <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
      <Input
        placeholder={t("shop.orders.new.customer_phone_placeholder")}
        value={phone}
        onChange={(e) => setPhone(e.target.value)}
        className="h-8 pl-8 text-sm"
      />
      {isLoading && phone.length >= 8 && (
        <div className="absolute top-1/2 right-2.5 -translate-y-1/2">
          <Spinner className="size-3.5" />
        </div>
      )}
      {showDropdown && (
        <div className="absolute z-50 mt-1 w-full rounded border bg-popover shadow-md">
          {results.map((c) => (
            <button
              key={c.id}
              type="button"
              className="flex w-full items-center justify-between px-3 py-2 text-sm hover:bg-muted"
              onClick={() => {
                onSelect(c);
                setPhone("");
              }}
            >
              <span className="font-medium">{customerFullName(c)}</span>
              <span className="text-muted-foreground">{c.phone}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
