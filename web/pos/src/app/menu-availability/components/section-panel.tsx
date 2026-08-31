/**
 * plan-056 — one section, its dishes, and the two bulk buttons.
 *
 * The bulk OFF button always routes through the reason dialog and always shows
 * how many dishes it will touch. Turning a whole course off mid-service is the
 * single most damaging mis-tap available on this screen, and the count is what
 * makes a mis-tap obvious BEFORE the paper moves rather than after.
 */

import { FolderOpen, Power, PowerOff } from "lucide-react";
import { Badge, Button, Spinner } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type {
  AvailabilityProduct,
  AvailabilitySku,
  AvailabilityToppingItem,
} from "@/services/menu-availability-service";
import { AvailabilityProductRow } from "./product-row";
import type { OptionValueGroup } from "./option-value-switches";

export interface AvailabilitySectionGroup {
  id: string;
  name: string;
  products: AvailabilityProduct[];
}

export interface AvailabilitySectionPanelProps {
  section: AvailabilitySectionGroup;
  /** True while THIS section's bulk write is in flight. */
  isBulkPending: boolean;
  pendingProductId: string | null;
  pendingSkuId: string | null;
  pendingToppingId: string | null;
  pendingOptionValueId: string | null;
  onBulk: (section: AvailabilitySectionGroup, next: boolean) => void;
  onToggleProduct: (product: AvailabilityProduct, next: boolean) => void;
  onToggleSku: (
    product: AvailabilityProduct,
    sku: AvailabilitySku,
    next: boolean,
  ) => void;
  onToggleTopping: (
    product: AvailabilityProduct,
    item: AvailabilityToppingItem,
    next: boolean,
  ) => void;
  onToggleOptionValue: (product: AvailabilityProduct, group: OptionValueGroup, next: boolean) => void;
}

export function AvailabilitySectionPanel({
  section,
  isBulkPending,
  pendingProductId,
  pendingSkuId,
  pendingToppingId,
  pendingOptionValueId,
  onBulk,
  onToggleProduct,
  onToggleSku,
  onToggleTopping,
  onToggleOptionValue,
}: AvailabilitySectionPanelProps) {
  const { t } = useTranslation();

  const total = section.products.length;
  const activeCount = section.products.filter((p) => p.is_active).length;
  const allOn = total > 0 && activeCount === total;
  const allOff = activeCount === 0;

  return (
    <section className="mb-5" data-slot="availability-section-panel">
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <FolderOpen className="size-4 text-muted-foreground" />
        <h2 className="text-sm font-semibold">{section.name}</h2>
        <Badge variant="outline" className="h-5 text-[10px]">
          {t("menu_availability.section_count", { active: activeCount, total })}
        </Badge>

        <div className="ml-auto flex items-center gap-1">
          {isBulkPending && <Spinner className="size-4" />}
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 gap-1.5"
            // `allOn` disables it because there is nothing to do, not because
            // it is forbidden — pressing a button that changes nothing and
            // reports "0 dishes" teaches staff the number is meaningless.
            disabled={isBulkPending || total === 0 || allOn}
            onClick={() => onBulk(section, true)}
            data-testid={`availability-bulk-on-${section.id}`}
          >
            <Power className="size-3.5" />
            {t("menu_availability.bulk_on")}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 gap-1.5 text-muted-foreground"
            disabled={isBulkPending || total === 0 || allOff}
            onClick={() => onBulk(section, false)}
            data-testid={`availability-bulk-off-${section.id}`}
          >
            <PowerOff className="size-3.5" />
            {t("menu_availability.bulk_off")}
          </Button>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        {section.products.map((product) => (
          <AvailabilityProductRow
            key={product.id}
            product={product}
            isPending={pendingProductId === product.id}
            pendingSkuId={pendingSkuId}
            pendingToppingId={pendingToppingId}
            pendingOptionValueId={pendingOptionValueId}
            onToggleProduct={onToggleProduct}
            onToggleSku={onToggleSku}
            onToggleTopping={onToggleTopping}
            onToggleOptionValue={onToggleOptionValue}
          />
        ))}
        {total === 0 && (
          <p className="rounded-lg border border-dashed py-6 text-center text-xs text-muted-foreground">
            {t("menu_availability.section_empty")}
          </p>
        )}
      </div>
    </section>
  );
}
