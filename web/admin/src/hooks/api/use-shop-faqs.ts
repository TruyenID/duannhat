/**
 * Shop FAQ hooks — #1673. Câu hỏi RIÊNG của một chi nhánh, cộng công tắc kế
 * thừa bộ câu hỏi của HQ.
 *
 * Mọi mutation đều invalidate cùng một key, kể cả công tắc kế thừa: bật/tắt
 * nó thay đổi CẢ danh sách (câu của HQ xuất hiện hoặc biến mất), không chỉ một
 * cái boolean.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { shopFaqService, type CreateFaqInput, type UpdateFaqInput } from "@/services/faq-service";
import { useTranslation } from "@/providers/app-provider";
import { shopFaqKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useShopFaqs(shopSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: shopFaqKeys.list(shopSlug, locale),
    queryFn: () => shopFaqService.list(shopSlug),
    enabled: !!shopSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateShopFaq(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateFaqInput) => shopFaqService.create(shopSlug, data),
    onSuccess: () => {
      toast.success(t("toast.faq.created"));
      qc.invalidateQueries({ queryKey: shopFaqKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.faq.create_failed")),
  });
}

export function useUpdateShopFaq(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateFaqInput }) =>
      shopFaqService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success(t("toast.faq.updated"));
      qc.invalidateQueries({ queryKey: shopFaqKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.faq.update_failed")),
  });
}

export function useToggleShopFaqPublished(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, currentIsPublished }: { id: string; currentIsPublished: boolean }) =>
      shopFaqService.togglePublished(shopSlug, id, currentIsPublished),
    onSuccess: () => qc.invalidateQueries({ queryKey: shopFaqKeys.all(shopSlug) }),
    onError: (e: Error) => toast.error(e.message || t("toast.faq.update_failed")),
  });
}

export function useToggleShopFaqPinned(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, currentIsPinned }: { id: string; currentIsPinned: boolean }) =>
      shopFaqService.togglePinned(shopSlug, id, currentIsPinned),
    onSuccess: () => qc.invalidateQueries({ queryKey: shopFaqKeys.all(shopSlug) }),
    onError: (e: Error) => toast.error(e.message || t("toast.faq.update_failed")),
  });
}

export function useDeleteShopFaq(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => shopFaqService.remove(shopSlug, id),
    onSuccess: () => {
      toast.success(t("toast.faq.deleted"));
      qc.invalidateQueries({ queryKey: shopFaqKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.faq.delete_failed")),
  });
}

/**
 * #1684 — ẩn/hiện một câu kế thừa từ HQ cho riêng chi nhánh này.
 *
 * Không toast khi thành công: người dùng bấm công tắc rồi thấy nó đổi ngay, và
 * một toast cho mỗi lần bấm sẽ chồng đống khi họ tắt liền năm câu.
 */
export function useSetShopFaqVisibility(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, isVisible }: { id: string; isVisible: boolean }) =>
      shopFaqService.setVisibility(shopSlug, id, isVisible),
    onSuccess: () => qc.invalidateQueries({ queryKey: shopFaqKeys.all(shopSlug) }),
    onError: (e: Error) => toast.error(e.message || t("toast.faq.update_failed")),
  });
}

export function useSetShopFaqInheritHq(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (inherit: boolean) => shopFaqService.setInheritHq(shopSlug, inherit),
    onSuccess: (_data, inherit) => {
      toast.success(inherit ? t("toast.faq.inherit_on") : t("toast.faq.inherit_off"));
      qc.invalidateQueries({ queryKey: shopFaqKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.faq.update_failed")),
  });
}
