/**
 * TanStack Query hooks for notification templates (plan-012 T2.5).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  templateService,
  type StoreTemplateInput,
  type TemplateContent,
  type UpdateTemplateInput,
} from "@/services/notification-template-service";

export const templateKeys = {
  all: (brandSlug: string) => ["hq", brandSlug, "templates"] as const,
  list: (brandSlug: string) => ["hq", brandSlug, "templates", "list"] as const,
  render: (
    brandSlug: string,
    key: string | null,
    content: TemplateContent | null,
    params: Record<string, unknown>
  ) => ["hq", brandSlug, "templates", "render", key, content, params] as const,
};

export function useTemplates(brandSlug: string) {
  return useQuery({
    queryKey: templateKeys.list(brandSlug),
    queryFn: () => templateService.list(brandSlug),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useCreateTemplate(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: StoreTemplateInput) => templateService.create(brandSlug, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: templateKeys.all(brandSlug) });
    },
  });
}

export function useUpdateTemplate(brandSlug: string, id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: UpdateTemplateInput) => templateService.update(brandSlug, id, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: templateKeys.all(brandSlug) });
    },
  });
}

export function useDeleteTemplate(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => templateService.destroy(brandSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: templateKeys.all(brandSlug) });
    },
  });
}

export function useDuplicateTemplate(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => templateService.duplicate(brandSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: templateKeys.all(brandSlug) });
    },
  });
}

/**
 * Inline render — debounce the caller's content + params before passing
 * in. Disabled while content is null (no rule yet).
 */
export function useTemplateRenderPreview(
  brandSlug: string,
  content: TemplateContent | null,
  params: Record<string, unknown>
) {
  return useQuery({
    queryKey: templateKeys.render(brandSlug, null, content, params),
    queryFn: () => templateService.renderInline(brandSlug, content!, params),
    enabled: !!brandSlug && content !== null,
    staleTime: 2_000,
    retry: false,
  });
}

/**
 * Render a saved template (by id) against sample params — used by the
 * broadcast composer for its step-2 live preview (plan-012 T4.12).
 */
export function useTemplateRenderSavedPreview(
  brandSlug: string,
  templateId: string | null,
  params: Record<string, unknown>
) {
  return useQuery({
    queryKey: ["hq", brandSlug, "templates", "render-saved", templateId, params] as const,
    queryFn: () => templateService.renderSaved(brandSlug, templateId!, params),
    enabled: !!brandSlug && !!templateId,
    staleTime: 2_000,
    retry: false,
  });
}
