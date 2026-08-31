"use client";

/**
 * Shop FAQ — #1673. Câu hỏi thường gặp RIÊNG của chi nhánh, cộng công tắc kế
 * thừa bộ câu hỏi của HQ (#1504).
 *
 * Bảng cố ý hiển thị CẢ câu kế thừa, ở dạng chỉ đọc và có nhãn rõ ràng. Một
 * màn hình chỉ liệt kê câu riêng sẽ trống trơn ở hầu hết chi nhánh, trong khi
 * trang FAQ của khách có hai chục câu — người quản chi nhánh cần thấy đúng thứ
 * khách đang đọc, không phải một nửa sự thật.
 *
 * Câu của HQ chỉ sửa được ở HQ. Backend trả 404 cho mọi thao tác ghi lên
 * chúng từ đây, nên nút hành động của dòng kế thừa được ẩn hẳn chứ không phải
 * bấm vào rồi mới báo lỗi.
 */

import { useMemo, useState } from "react";
import { useParams } from "next/navigation";
import {
  Building2,
  EllipsisVertical,
  Eye,
  EyeOff,
  Pencil,
  Pin,
  PinOff,
  Plus,
  RefreshCw,
  Trash2,
} from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { Badge, Button, StatusBadge, Switch } from "@godxjp/ui";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import {
  useDeleteShopFaq,
  useSetShopFaqInheritHq,
  useSetShopFaqVisibility,
  useShopFaqs,
  useToggleShopFaqPinned,
  useToggleShopFaqPublished,
} from "@/hooks/api/use-shop-faqs";
import type { ShopFaq } from "@/services/faq-service";
import { ShopFaqFormDialog } from "./components/shop-faq-form-dialog";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { SUPPORTED_LOCALES } from "@/types/models/payload-helpers";

export default function ShopFaqsPage() {
  const params = useParams<{ shopSlug: string }>();
  const shopSlug = params.shopSlug;
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<ShopFaq | null>(null);
  const [deleting, setDeleting] = useState<ShopFaq | null>(null);

  const { data: response, isLoading, refetch, isFetching } = useShopFaqs(shopSlug);
  const items = useMemo<ShopFaq[]>(() => response?.data ?? [], [response]);
  const inheritHq = response?.inherit_hq ?? true;
  const ownCount = items.filter((faq) => !faq.is_inherited).length;
  const inheritedCount = items.length - ownCount;

  const togglePublished = useToggleShopFaqPublished(shopSlug);
  const togglePinned = useToggleShopFaqPinned(shopSlug);
  const deleteMutation = useDeleteShopFaq(shopSlug);
  const setInherit = useSetShopFaqInheritHq(shopSlug);
  const setVisibility = useSetShopFaqVisibility(shopSlug);

  const columns: ColumnDef<ShopFaq>[] = useMemo(
    () => [
      {
        id: "stt",
        header: t("hq.products.col.stt"),
        size: 50,
        cell: ({ row }) => <span className="text-xs text-muted-foreground">{row.index + 1}</span>,
      },
      {
        id: "question",
        header: t("hq.faqs.col.question"),
        size: 300,
        cell: ({ row }) => (
          <div className="flex max-w-72 items-start gap-1.5">
            {row.original.is_pinned && (
              <Pin className="mt-0.5 size-3.5 shrink-0 text-amber-500" aria-hidden="true" />
            )}
            {row.original.is_inherited ? (
              // Không phải nút: câu kế thừa không mở được hộp thoại sửa.
              <span className="line-clamp-2 min-w-0 font-medium break-words whitespace-normal text-neutral-700">
                {row.original.question}
              </span>
            ) : (
              <button
                type="button"
                className="line-clamp-2 min-w-0 text-left font-medium break-words whitespace-normal text-primary hover:underline"
                title={row.original.question ?? undefined}
                onClick={() => setEditing(row.original)}
              >
                {row.original.question}
              </button>
            )}
          </div>
        ),
      },
      {
        id: "source",
        header: t("shop.faqs.col.source"),
        size: 120,
        cell: ({ row }) =>
          row.original.is_inherited ? (
            <Badge
              variant="outline"
              className="h-5 gap-1 border-transparent bg-sky-50 px-1.5 text-[11px] font-medium text-sky-700"
            >
              <Building2 className="size-3" />
              {t("shop.faqs.source.hq")}
            </Badge>
          ) : (
            <Badge
              variant="outline"
              className="h-5 border-transparent bg-emerald-50 px-1.5 text-[11px] font-medium text-emerald-700"
            >
              {t("shop.faqs.source.own")}
            </Badge>
          ),
      },
      {
        id: "answer",
        header: t("hq.faqs.col.answer"),
        size: 300,
        cell: ({ row }) => (
          // Chặn bề ngang + ghi đè `whitespace-nowrap` của TableCell — nếu
          // không, một câu trả lời dài đẩy cột Thao tác khỏi màn hình (#1671).
          <span
            className="line-clamp-2 max-w-sm text-xs break-words whitespace-normal text-muted-foreground"
            title={row.original.answer ?? undefined}
          >
            {row.original.answer || "—"}
          </span>
        ),
      },
      {
        id: "languages",
        header: t("hq.faqs.col.languages"),
        size: 120,
        cell: ({ row }) => {
          const filled = SUPPORTED_LOCALES.filter((l) =>
            row.original.translations?.[l]?.question?.trim()
          );

          return (
            <div className="flex flex-wrap gap-1">
              {SUPPORTED_LOCALES.map((l) => (
                <Badge
                  key={l}
                  variant="outline"
                  className={`h-5 px-1.5 text-[11px] font-medium uppercase ${
                    filled.includes(l)
                      ? "border-transparent bg-emerald-50 text-emerald-700"
                      : "border-dashed text-muted-foreground"
                  }`}
                >
                  {l}
                </Badge>
              ))}
            </div>
          );
        },
      },
      {
        id: "status",
        header: t("common.status"),
        size: 140,
        cell: ({ row }) => {
          const faq = row.original;

          // Câu riêng của chi nhánh: trạng thái là `is_published` của chính nó,
          // đổi qua menu Thao tác như cũ.
          if (!faq.is_inherited) {
            return <StatusBadge status={faq.is_published ? "active" : "inactive"} />;
          }

          // #1684 — câu đi mượn: công tắc riêng của chi nhánh.
          //
          // BR-FB03 — HQ ẩn thì thắng tuyệt đối. Khoá công tắc lại thay vì để
          // nó bấm được: một công tắc bật lên mà khách vẫn không thấy gì là
          // cách nhanh nhất để người dùng mất niềm tin vào cả màn hình.
          const lockedByHq = !faq.is_published;

          return (
            <div className="flex items-center gap-2">
              <Switch
                checked={faq.is_visible && !lockedByHq}
                disabled={lockedByHq || setVisibility.isPending}
                onCheckedChange={(v) => setVisibility.mutate({ id: faq.id, isVisible: v === true })}
                aria-label={t("shop.faqs.visibility.aria")}
              />
              <span className="text-xs text-muted-foreground">
                {lockedByHq
                  ? t("shop.faqs.visibility.locked_by_hq")
                  : faq.is_visible
                    ? t("shop.faqs.visibility.on")
                    : t("shop.faqs.visibility.off")}
              </span>
            </div>
          );
        },
      },
      {
        accessorKey: "updated_at",
        header: t("common.updated"),
        size: 120,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {formatDate(row.original.updated_at, locale, timezone)}
          </span>
        ),
      },
      {
        id: "actions",
        size: 50,
        header: t("common.action"),
        cell: ({ row }) => {
          const faq = row.original;

          // Câu kế thừa không có hành động nào ở đây — ẩn hẳn menu thay vì để
          // người dùng bấm rồi nhận 404 từ backend.
          if (faq.is_inherited) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }

          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => setEditing(faq)}>
                  <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() =>
                    togglePublished.mutate({ id: faq.id, currentIsPublished: faq.is_published })
                  }
                >
                  {faq.is_published ? (
                    <EyeOff className="mr-2 size-3.5" />
                  ) : (
                    <Eye className="mr-2 size-3.5" />
                  )}
                  {faq.is_published ? t("hq.faqs.action.hide") : t("hq.faqs.action.publish")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() =>
                    togglePinned.mutate({ id: faq.id, currentIsPinned: faq.is_pinned })
                  }
                >
                  {faq.is_pinned ? (
                    <PinOff className="mr-2 size-3.5" />
                  ) : (
                    <Pin className="mr-2 size-3.5" />
                  )}
                  {faq.is_pinned ? t("hq.faqs.action.unpin") : t("hq.faqs.action.pin")}
                </DropdownMenuItem>
                <DropdownMenuItem variant="destructive" onClick={() => setDeleting(faq)}>
                  <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          );
        },
      },
    ],
    [togglePublished, togglePinned, setVisibility, t, locale, timezone]
  );

  return (
    <>
      <PageHeader
        title={t("shop.faqs.title")}
        description={t("shop.faqs.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        {/* #1684 — nạp lại danh sách câu hỏi của HQ.
            CỐ Ý KHÔNG chép gì về chi nhánh: câu của HQ luôn đọc thẳng từ nguồn,
            nên nút này chỉ làm mới. Nếu nó chép, HQ sửa một câu là 17 bản chép
            nằm lại ở lời cũ — đúng thứ mô hình này tránh. */}
        <Button
          size="sm"
          variant="outline"
          className="h-7 gap-1 text-xs"
          disabled={isFetching}
          onClick={() => refetch()}
        >
          <RefreshCw className={`size-3.5 ${isFetching ? "animate-spin" : ""}`} />
          {t("shop.faqs.sync")}
        </Button>
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("common.new")}
        </Button>
        <HelpPanel
          title={t("shop.faqs.title")}
          subtitle={t("help.panel.shop_faqs.subtitle")}
          purpose={t("help.panel.shop_faqs.purpose")}
          usage={[
            t("help.panel.shop_faqs.usage.1"),
            t("help.panel.shop_faqs.usage.2"),
            t("help.panel.shop_faqs.usage.3"),
            t("help.panel.shop_faqs.usage.4"),
          ]}
          checks={[
            t("help.panel.shop_faqs.checks.1"),
            t("help.panel.shop_faqs.checks.2"),
            t("help.panel.shop_faqs.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_faqs.glossary.inherited.term"),
              description: t("help.panel.shop_faqs.glossary.inherited.desc"),
            },
            {
              term: t("help.panel.shop_faqs.glossary.pin.term"),
              description: t("help.panel.shop_faqs.glossary.pin.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        {/* Công tắc kế thừa. Đặt trên bảng vì nó quyết định bảng có những gì —
            tắt đi là các dòng "Từ HQ" biến mất khỏi cả đây lẫn trang khách. */}
        <div className="mb-4 flex flex-col gap-2 rounded-lg border bg-muted/30 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-start gap-3">
            <Switch
              id="faq-inherit-hq"
              checked={inheritHq}
              disabled={setInherit.isPending || isLoading}
              onCheckedChange={(v) => setInherit.mutate(v === true)}
            />
            <div>
              <label htmlFor="faq-inherit-hq" className="cursor-pointer text-sm font-medium">
                {t("shop.faqs.inherit.label")}
              </label>
              <p className="text-xs text-muted-foreground">
                {inheritHq ? t("shop.faqs.inherit.on_desc") : t("shop.faqs.inherit.off_desc")}
              </p>
            </div>
          </div>
          <p className="text-xs whitespace-nowrap text-muted-foreground">
            {t("shop.faqs.counts", { own: String(ownCount), inherited: String(inheritedCount) })}
          </p>
        </div>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={8} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("shop.faqs.empty")} />
        )}
      </PageContent>

      <ShopFaqFormDialog
        shopSlug={shopSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
        faq={null}
      />

      <ShopFaqFormDialog
        shopSlug={shopSlug}
        open={!!editing}
        onOpenChange={(o) => !o && setEditing(null)}
        faq={editing}
      />

      <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.faqs.delete.title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("hq.faqs.delete.desc")}
              {deleting?.question ? ` — “${deleting.question}”` : ""}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteMutation.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              disabled={deleteMutation.isPending}
              onClick={() => {
                if (!deleting) return;
                deleteMutation.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
              }}
            >
              {t("common.delete")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
