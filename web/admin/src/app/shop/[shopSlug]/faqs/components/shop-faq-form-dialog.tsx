"use client";

/**
 * ShopFaqFormDialog — tạo/sửa một câu hỏi RIÊNG của chi nhánh (#1673).
 *
 * Giống `FaqFormDialog` của HQ về mọi luật nội dung; khác đúng một chỗ: gọi
 * `useCreateShopFaq` / `useUpdateShopFaq` nên câu hỏi mang `branch_id` của
 * chi nhánh trên URL. Hộp thoại này KHÔNG bao giờ mở cho câu kế thừa từ HQ —
 * trang chặn từ trước, và backend cũng trả 404 nếu có ai lách qua.
 */

import { useState } from "react";

import { Button, Input, Spinner, Switch, Textarea } from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";

import { ApiError } from "@/lib/api";
import { useCreateShopFaq, useUpdateShopFaq } from "@/hooks/api/use-shop-faqs";
import type { CreateFaqInput, ShopFaq, UpdateFaqInput } from "@/services/faq-service";
import { useTranslation } from "@/providers/app-provider";
import { SUPPORTED_LOCALES, emptyLocaleMap } from "@/types/models/payload-helpers";

/** Trần của `post_translations.title` (string 200) — backend trả 422 quá đó. */
const QUESTION_MAX = 200;

export interface ShopFaqFormDialogProps {
  shopSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  faq?: ShopFaq | null;
}

interface FormState {
  question: Record<string, string>;
  answer: Record<string, string>;
  is_published: boolean;
  is_pinned: boolean;
}

function emptyForm(): FormState {
  return {
    question: emptyLocaleMap(),
    answer: emptyLocaleMap(),
    is_published: true,
    is_pinned: false,
  };
}

function hydrateForm(faq: ShopFaq): FormState {
  const question = emptyLocaleMap();
  const answer = emptyLocaleMap();

  for (const locale of SUPPORTED_LOCALES) {
    question[locale] = faq.translations?.[locale]?.question ?? "";
    answer[locale] = faq.translations?.[locale]?.answer ?? "";
  }

  return {
    question,
    answer,
    is_published: faq.is_published,
    is_pinned: faq.is_pinned,
  };
}

export function ShopFaqFormDialog({ shopSlug, open, onOpenChange, faq }: ShopFaqFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!faq;
  const [form, setForm] = useState<FormState>(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  const [dirty, setDirty] = useState(false);

  const createMutation = useCreateShopFaq(shopSlug);
  const updateMutation = useUpdateShopFaq(shopSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  const hydrationKey = open ? `${faq?.id ?? "create"}` : null;
  const [prevHydrationKey, setPrevHydrationKey] = useState<string | null>(null);
  if (hydrationKey !== prevHydrationKey) {
    setPrevHydrationKey(hydrationKey);
    if (hydrationKey !== null) {
      setForm(faq ? hydrateForm(faq) : emptyForm());
      setDirty(false);
    }
    setFieldErrors({});
  }

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setDirty(true);
    setForm((prev) => ({ ...prev, [key]: value }));
    if (fieldErrors[key as string]) {
      setFieldErrors((prev) => {
        const next = { ...prev };
        delete next[key as string];
        return next;
      });
    }
  }

  function requestClose() {
    if (dirty && !isPending) {
      setConfirmExitOpen(true);
    } else {
      onOpenChange(false);
    }
  }

  const hasAnyQuestion = SUPPORTED_LOCALES.some((locale) => form.question[locale]?.trim());

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    if (!hasAnyQuestion) {
      setFieldErrors({ "ja.question": [t("hq.faqs.error.question_required")] });
      return;
    }

    const payload: CreateFaqInput = {
      is_published: form.is_published,
      is_pinned: form.is_pinned,
    };

    for (const locale of SUPPORTED_LOCALES) {
      const question = form.question[locale]?.trim() ?? "";
      const answer = form.answer[locale]?.trim() ?? "";

      if (question === "" && answer === "") continue;

      payload[locale] = {
        ...(question !== "" ? { question } : {}),
        ...(answer !== "" ? { answer } : {}),
      };
    }

    try {
      if (isEdit && faq) {
        await updateMutation.mutateAsync({ id: faq.id, data: payload as UpdateFaqInput });
      } else {
        await createMutation.mutateAsync(payload);
      }
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          if (next) {
            onOpenChange(true);
          } else {
            requestClose();
          }
        }}
      >
        <DialogContent className="flex max-h-[90vh] flex-col gap-0 sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>
              {isEdit ? t("shop.faqs.dialog.edit_title") : t("shop.faqs.dialog.create_title")}
            </DialogTitle>
            <DialogDescription>
              {isEdit ? t("shop.faqs.dialog.edit_desc") : t("shop.faqs.dialog.create_desc")}
            </DialogDescription>
          </DialogHeader>

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm"
          >
            <Field
              label={t("hq.faqs.field.question")}
              required
              error={
                fieldErrors["ja.question"]?.[0] ??
                fieldErrors["en.question"]?.[0] ??
                fieldErrors["vi.question"]?.[0]
              }
            >
              <Input
                translatable
                value={form.question as TranslatableValue}
                onChange={(value) => update("question", value as Record<string, string>)}
                maxLength={QUESTION_MAX}
                placeholder={t("hq.faqs.field.question_placeholder")}
              />
            </Field>

            <Field
              label={t("hq.faqs.field.answer")}
              error={
                fieldErrors["ja.answer"]?.[0] ??
                fieldErrors["en.answer"]?.[0] ??
                fieldErrors["vi.answer"]?.[0]
              }
            >
              <Textarea
                translatable
                value={form.answer as TranslatableValue}
                onChange={(value) => update("answer", value as Record<string, string>)}
                rows={6}
                placeholder={t("hq.faqs.field.answer_placeholder")}
              />
              <p className="text-xs text-muted-foreground">{t("hq.faqs.field.answer_hint")}</p>
            </Field>

            <div className="grid grid-cols-2 gap-3">
              <Field label={t("hq.faqs.field.is_published")}>
                <div className="flex h-8 items-center gap-3">
                  <Switch
                    id="shop-faq-is-published"
                    checked={form.is_published}
                    onCheckedChange={(v) => update("is_published", v === true)}
                  />
                  <label
                    htmlFor="shop-faq-is-published"
                    className="cursor-pointer text-xs text-muted-foreground"
                  >
                    {form.is_published ? t("hq.faqs.status.published") : t("hq.faqs.status.hidden")}
                  </label>
                </div>
              </Field>

              <Field label={t("hq.faqs.field.is_pinned")}>
                <div className="flex h-8 items-center gap-3">
                  <Switch
                    id="shop-faq-is-pinned"
                    checked={form.is_pinned}
                    onCheckedChange={(v) => update("is_pinned", v === true)}
                  />
                  <label
                    htmlFor="shop-faq-is-pinned"
                    className="cursor-pointer text-xs text-muted-foreground"
                  >
                    {t("hq.faqs.field.is_pinned_hint")}
                  </label>
                </div>
              </Field>
            </div>
          </form>

          <DialogFooter className="border-t pt-3">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={requestClose}
              disabled={isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button type="button" size="sm" onClick={handleSubmit} disabled={isPending}>
              {isPending && <Spinner className="mr-1.5 size-3.5" />}
              {isEdit ? t("common.save_changes") : t("common.create")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isPending}>
              {t("hq.products.unsaved.continue_editing")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setConfirmExitOpen(false);
                onOpenChange(false);
              }}
              disabled={isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}

function Field({
  label,
  required,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-xs font-medium text-muted-foreground">
        {label}
        {required && <span className="ml-0.5 text-destructive">*</span>}
      </label>
      {children}
      {error && <span className="text-xs text-destructive">{error}</span>}
    </div>
  );
}
