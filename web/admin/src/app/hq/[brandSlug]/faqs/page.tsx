"use client";

/**
 * HQ FAQ — #1504. Câu hỏi thường gặp hiển thị ở customer-web `/account/faq`
 * (#1486). Trước màn hình này không có đường nào để nhập câu hỏi: không API
 * admin, không seeder, không lệnh artisan — trang khách vĩnh viễn trống.
 *
 * Cố ý KHÔNG phải CRUD bài viết: backend lưu FAQ trong bảng `posts` thuộc
 * chuyên mục `faq`, nhưng ở đây chỉ có câu hỏi, câu trả lời, hiển thị và ghim.
 * Không có đường nào từ trang này chạm tới bài news/promotion.
 *
 * Phạm vi là TỔ CHỨC dù URL có `{brandSlug}` — `posts` không có `brand_id`,
 * nên các brand cùng tổ chức dùng chung một bộ FAQ. Trang nói rõ điều đó thay
 * vì để người dùng tự phát hiện sau khi sửa nhầm.
 */

import { useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { EllipsisVertical, Eye, EyeOff, Pin, PinOff, Pencil, Plus, Trash2 } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { Badge, Button, StatusBadge } from "@godxjp/ui";
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
  useDeleteFaq,
  useFaqs,
  useToggleFaqPinned,
  useToggleFaqPublished,
} from "@/hooks/api/use-faqs";
import type { Faq } from "@/services/faq-service";
import { FaqFormDialog } from "./components/faq-form-dialog";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { SUPPORTED_LOCALES } from "@/types/models/payload-helpers";

export default function FaqsPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<Faq | null>(null);
  const [deleting, setDeleting] = useState<Faq | null>(null);

  const { data: response, isLoading, refetch, isFetching } = useFaqs(brandSlug);
  const items = useMemo<Faq[]>(() => response?.data ?? [], [response]);

  const togglePublished = useToggleFaqPublished(brandSlug);
  const togglePinned = useToggleFaqPinned(brandSlug);
  const deleteMutation = useDeleteFaq(brandSlug);

  const columns: ColumnDef<Faq>[] = useMemo(
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
        size: 320,
        cell: ({ row }) => (
          <div className="flex max-w-72 items-start gap-1.5">
            {row.original.is_pinned && (
              <Pin className="mt-0.5 size-3.5 shrink-0 text-amber-500" aria-hidden="true" />
            )}
            {/* Ba lớp, thiếu lớp nào cũng hỏng theo kiểu khác nhau:
                `min-w-0`          — flex item mặc định `min-width: auto` nên nó từ chối
                                     co xuống dưới bề rộng nội dung, `max-w-72` của cha
                                     thành vô nghĩa;
                `whitespace-normal` — `TableCell` của @godxjp/ui đặt `whitespace-nowrap`,
                                     mà chữ không xuống dòng thì `line-clamp` chỉ cắt cụt
                                     giữa từ, không bao giờ ra dấu `…`;
                `break-words`      — một token dài không dấu cách (URL) vẫn tràn được. */}
            <button
              type="button"
              className="line-clamp-2 min-w-0 text-left font-medium break-words whitespace-normal text-primary hover:underline"
              title={row.original.question ?? undefined}
              onClick={() => setEditing(row.original)}
            >
              {row.original.question}
            </button>
          </div>
        ),
      },
      {
        id: "answer",
        header: t("hq.faqs.col.answer"),
        size: 320,
        cell: ({ row }) => (
          // `max-w-sm` là phần LÀM VIỆC ở đây, không phải `line-clamp-2`:
          // bảng dùng `table-layout: auto`, nên ô không bị chặn bề ngang thì
          // trình duyệt cứ nới cột cho vừa chữ và KHÔNG BAO GIỜ có dòng thứ
          // hai để mà cắt. Một câu trả lời dài từng đẩy bảng rộng 1661px trong
          // khung 1150px, hất cột Thao tác ra ngoài màn hình (#1671).
          //
          // `size: 320` khai ở trên không cứu được: `DataTable` chỉ gắn width
          // lên <TableHead>, mà với table-layout auto thì đó chỉ là GỢI Ý.
          //
          // Con số 384px (`max-w-sm`) chọn theo phép đo, không phải cảm tính:
          // `max-w-md` (448px) vẫn để bảng tràn 50px ở viewport 1440.
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
        size: 130,
        cell: ({ row }) => {
          // Chỉ ra ngôn ngữ nào ĐÃ dịch. Thiếu một thứ tiếng không phải lỗi —
          // customer-web lui về ngôn ngữ khác — nhưng người vận hành cần thấy
          // rõ, vì khách xem tiếng đó sẽ đọc câu hỏi bằng ngôn ngữ khác.
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
        size: 100,
        cell: ({ row }) => (
          <StatusBadge status={row.original.is_published ? "active" : "inactive"} />
        ),
      },
      {
        accessorKey: "updated_at",
        header: t("common.updated"),
        size: 130,
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
                    togglePublished.mutate({
                      id: faq.id,
                      currentIsPublished: faq.is_published,
                    })
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
    [togglePublished, togglePinned, t, locale, timezone]
  );

  return (
    <>
      <PageHeader
        title={t("hq.faqs.title")}
        description={t("hq.faqs.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("common.new")}
        </Button>
      </PageHeader>

      <PageContent>
        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={7} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.faqs.empty")} />
        )}
      </PageContent>

      {/* Tạo mới */}
      <FaqFormDialog
        brandSlug={brandSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
        faq={null}
      />

      {/* Sửa */}
      <FaqFormDialog
        brandSlug={brandSlug}
        open={!!editing}
        onOpenChange={(o) => !o && setEditing(null)}
        faq={editing}
      />

      {/* Xoá — hỏi lại vì khác "ẩn", xoá là biến khỏi cả màn hình này. */}
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
