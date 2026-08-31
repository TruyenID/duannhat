// src/lib/payment-metadata.ts
import type { ItemAllocation, PaymentPayload } from '../types/kiosk';

type SplitMetadata = NonNullable<PaymentPayload['metadata']>;

interface SplitMetadataArgs {
  splitMode?: string;
  splitBillIndex?: string;
  splitTotalBills?: string;
  splitLabel?: string;
  totalAmount?: string;
  /** by_items only — the items + units this sub-check covers. */
  itemAllocations?: ItemAllocation[];
}

/**
 * Build the `metadata` blob a payment screen sends for split flows.
 *
 * Returns `undefined` for `full` / `custom` (no metadata) so callers can
 * `...(metadata && { metadata })`.
 *
 * #2860 — không còn ánh xạ tên nào ở đây: kiosk và backend dùng CHUNG một từ
 * vựng (`even`/`by_items`/`by_amount`). Hàm này chỉ còn quyết định HÌNH DẠNG
 * của blob, không còn dịch tên.
 *
 * `by_amount` trả `undefined` có chủ ý: kiosk chưa có màn khai số tiền từng
 * người, nên không có gì để ghi vào metadata. Đó là thiếu MÀN HÌNH, không
 * phải thiếu từ vựng.
 */
export function buildSplitMetadata(
  args: SplitMetadataArgs,
): SplitMetadata | undefined {
  const {
    splitMode,
    splitBillIndex,
    splitTotalBills,
    splitLabel,
    totalAmount,
    itemAllocations,
  } = args;

  if (splitMode === 'even') {
    return {
      split_mode: 'even',
      bill_index: Number(splitBillIndex ?? 0),
      total_bills: Number(splitTotalBills ?? 1),
      label: splitLabel,
      expected_total_amount: Number(totalAmount ?? 0),
    };
  }

  if (splitMode === 'by_items') {
    return {
      split_mode: 'by_items',
      bill_index: Number(splitBillIndex ?? 0),
      label: splitLabel,
      expected_total_amount: Number(totalAmount ?? 0),
      item_allocations: (itemAllocations ?? [])
        .filter((a) => a.units > 0)
        .map((a) => ({ item_id: a.item_id, units: a.units })),
    };
  }

  return undefined;
}

/** Split modes that record the payment locally + loop, vs. terminal `/success`. */
export function isMultiSlipSplit(splitMode?: string): boolean {
  return (
    splitMode === 'even' ||
    splitMode === 'by_amount' ||
    splitMode === 'by_items'
  );
}

/** Destination success screen for a recorded split payment. */
export function splitSuccessRoute(splitMode?: string): string {
  if (splitMode === 'even') return '/split/success';
  if (splitMode === 'by_items') return '/split/items-success';
  return '/custom/success';
}
