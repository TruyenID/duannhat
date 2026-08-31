"use client";

import { useMemo } from "react";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge, Button, Input } from "@godxjp/ui";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { DataTable } from "@/components/shared/data-table";
import { Pagination } from "@/components/shared/pagination";
import { formatDateTime } from "@/lib/date";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useTransactions } from "@/hooks/api/use-transactions";
import { useTimezone, useTranslation } from "@/providers/app-provider";
import type { TransactionRow } from "@/services/transaction-service";

/**
 * Tra cứu giao dịch toàn kênh (#2880 · T3 của #2876).
 *
 * ## Vì sao màn này tồn tại khi đã có màn settlement
 *
 * `settings/payments/settlements` trả lời **"cổng đã trả tiền về chưa, phí bao
 * nhiêu"** — quan hệ quán ↔ CỔNG. Màn này trả lời **"giao dịch X là gì"** —
 * quan hệ quán ↔ KHÁCH. Hai sổ khác nhau, nên hai màn khác nhau; gộp lại sẽ
 * làm cả hai khó đọc.
 *
 * ## Đây là nghĩa vụ pháp lý
 *
 * 電子帳簿保存法 検索要件: phải tra được theo **取引年月日 · 取引金額 · 取引先**
 * và KẾT HỢP từ hai trục trở lên. Ba nhóm ô lọc dưới đây map thẳng vào ba trục
 * đó — thứ tự và cách nhóm không phải thẩm mỹ.
 *
 * ## Ô `reference` đứng riêng và đứng đầu
 *
 * Một giao dịch mang tới sáu loại mã tuỳ đường đi; người vận hành cầm đúng một
 * cái, thường là cái nhà cung cấp đưa. Bắt họ chọn "mã này thuộc loại nào"
 * trước khi tra là bắt họ hiểu kiến trúc nội bộ. Một ô, tra hết — kể cả mã máy
 * 釣銭機 ở dạng TRẦN (tiền tố `glory:` là quy ước nội bộ, không in trên phiếu).
 *
 * ## CHỈ ĐỌC
 *
 * Không nút sửa, không nút hoàn tiền, không đổi trạng thái. Sửa tiền đi qua
 * đường đã có, có lý do và có audit — thêm một cửa thứ hai vào tiền là thêm một
 * cửa không ai canh.
 */

const FILTER_DEFAULTS = {
  reference: "",
  date_from: "",
  date_to: "",
  amount_min: "",
  amount_max: "",
  provider: "",
  branch_id: "",
  per_page: "25",
};

export default function TransactionsPage() {
  const params = useParams();
  const brandSlug = String(params?.brandSlug ?? "");
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { filters, setFilter, page, setPage, resetFilters } = useSearchFilters(FILTER_DEFAULTS);

  const query = useMemo(
    () => ({
      page,
      per_page: Number(filters.per_page) || 25,
      reference: filters.reference || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      amount_min: filters.amount_min ? Number(filters.amount_min) : undefined,
      amount_max: filters.amount_max ? Number(filters.amount_max) : undefined,
      provider: filters.provider || undefined,
      branch_id: filters.branch_id || undefined,
    }),
    [page, filters]
  );

  const { data, isLoading, isError, error } = useTransactions(brandSlug, query);

  const columns = useMemo<ColumnDef<TransactionRow>[]>(
    () => [
      {
        accessorKey: "paid_at",
        header: t("hq.transactions.paidAt"),
        cell: ({ row }) =>
          row.original.paid_at ? formatDateTime(row.original.paid_at, locale, timezone) : "—",
      },
      {
        accessorKey: "amount",
        header: t("hq.transactions.amount"),
        cell: ({ row }) => (
          <span className="tabular-nums">{row.original.amount.toLocaleString(locale)}</span>
        ),
      },
      {
        id: "counterparty",
        header: t("hq.transactions.counterparty"),
        // 取引先 của một khoản thu là CỔNG, hoặc chính quán khi thu tiền mặt.
        // Hiện "—" khi không có provider là đúng: tiền mặt không có bên thứ ba.
        cell: ({ row }) => row.original.gateway.provider ?? row.original.tender_key ?? "—",
      },
      {
        accessorKey: "status",
        header: t("common.status"),
        cell: ({ row }) => <Badge variant="outline">{row.original.status ?? "—"}</Badge>,
      },
      {
        id: "reference",
        header: t("hq.transactions.reference"),
        // Hiện mã của CỔNG khi có, vì đó là mã người vận hành đối chiếu với
        // bảng kê. Không có thì rơi về mã nội bộ.
        cell: ({ row }) => (
          <span className="font-mono text-xs">
            {row.original.attempt?.provider_object_id ??
              row.original.reference_no ??
              row.original.payment_code ??
              "—"}
          </span>
        ),
      },
    ],
    [t, locale, timezone]
  );

  return (
    <>
      <PageHeader
        title={t("hq.transactions.title")}
        description={t("hq.transactions.description")}
      />
      <PageContent>
        <div className="space-y-4">
          {/* Ô mã đứng RIÊNG và đứng ĐẦU — xem docblock. */}
          <Input
            value={filters.reference}
            onChange={(e) => setFilter("reference", e.target.value)}
            placeholder={t("hq.transactions.referencePlaceholder")}
          />

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <Input
              type="date"
              value={filters.date_from}
              onChange={(e) => setFilter("date_from", e.target.value)}
            />
            <Input
              type="date"
              value={filters.date_to}
              onChange={(e) => setFilter("date_to", e.target.value)}
            />
            <Input
              type="number"
              inputMode="numeric"
              value={filters.amount_min}
              onChange={(e) => setFilter("amount_min", e.target.value)}
              placeholder={t("hq.transactions.amountMin")}
            />
            <Input
              type="number"
              inputMode="numeric"
              value={filters.amount_max}
              onChange={(e) => setFilter("amount_max", e.target.value)}
              placeholder={t("hq.transactions.amountMax")}
            />
            <Button variant="outline" onClick={resetFilters}>
              {t("common.reset")}
            </Button>
          </div>

          {isError ? (
            // KHÔNG nuốt lỗi thành bảng rỗng: một bảng rỗng đọc thành "không có
            // giao dịch nào", và với dữ liệu tiền đó là câu trả lời SAI nguy
            // hiểm hơn một thông báo lỗi.
            <div className="rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
              {(error as Error)?.message ??
                t("hq.transactions.loadFailed")}
            </div>
          ) : (
            <>
              <DataTable
                columns={columns}
                data={data?.data ?? []}
                emptyMessage={
                  isLoading
                    ? t("common.loading")
                    : t("hq.transactions.empty")
                }
              />
              <Pagination
                meta={
                  data?.meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }
                }
                page={page}
                perPage={Number(filters.per_page) || 25}
                onPageChange={setPage}
                onPerPageChange={(n) => setFilter("per_page", String(n))}
              />
            </>
          )}
        </div>
      </PageContent>
    </>
  );
}
