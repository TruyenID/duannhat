/**
 * FAQ hooks — #1504. CRUD Câu hỏi thường gặp cho HQ (index / create / update /
 * delete). Nguồn dữ liệu của trang `/account/faq` bên customer-web (#1486).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { faqService, type CreateFaqInput, type UpdateFaqInput } from "@/services/faq-service";
import { useTranslation } from "@/providers/app-provider";
import { faqKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useFaqs(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    // Khoá kèm locale: `question`/`answer` trả về theo ngôn ngữ đang xem, nên
    // đổi ngôn ngữ phải là một lần fetch khác chứ không dùng lại cache cũ.
    queryKey: faqKeys.list(brandSlug, locale),
    queryFn: () => faqService.list(brandSlug),
    enabled: !!brandSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateFaq(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateFaqInput) => faqService.create(brandSlug, data),
    onSuccess: () => {
      toast.success(t("toast.faq.created"));
      qc.invalidateQueries({ queryKey: faqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.faq.create_failed")),
  });
}

export function useUpdateFaq(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateFaqInput }) =>
      faqService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success(t("toast.faq.updated"));
      qc.invalidateQueries({ queryKey: faqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.faq.update_failed")),
  });
}

export function useToggleFaqPublished(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, currentIsPublished }: { id: string; currentIsPublished: boolean }) =>
      faqService.togglePublished(brandSlug, id, currentIsPublished),
    onSuccess: () => qc.invalidateQueries({ queryKey: faqKeys.all(brandSlug) }),
    onError: (e: Error) => toast.error(e.message || t("toast.faq.update_failed")),
  });
}

export function useToggleFaqPinned(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, currentIsPinned }: { id: string; currentIsPinned: boolean }) =>
      faqService.togglePinned(brandSlug, id, currentIsPinned),
    onSuccess: () => qc.invalidateQueries({ queryKey: faqKeys.all(brandSlug) }),
    onError: (e: Error) => toast.error(e.message || t("toast.faq.update_failed")),
  });
}

export function useDeleteFaq(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => faqService.remove(brandSlug, id),
    onSuccess: () => {
      toast.success(t("toast.faq.deleted"));
      qc.invalidateQueries({ queryKey: faqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.faq.delete_failed")),
  });
}
