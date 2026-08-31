"use client";

import { useState, useEffect, useMemo } from "react";
import { useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { accountHref } from "@/lib/shop-routes";
import { useTranslations, useLocale } from "next-intl";
import { useAuth } from "@/context/auth-context";
import { apiFetch, ApiError } from "@/lib/api";
import BirthdaySelect from "@/components/birthday-select";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  type BirthdayParts,
  EMPTY_BIRTHDAY,
  parseBirthday,
  resolveBirthday,
  todayIso,
} from "@/lib/birthday";
import { deriveCountry, formatAsYouType, validatePhoneForCountry } from "@/lib/phone";
import { toast } from "sonner";

/** Giá trị enum `CustomerGender` ở backend — đổi ở đây phải đổi cả schema. */
type Gender = "female" | "male";

interface ProfileData {
  id: string;
  name: string;
  email: string;
  phone?: string | null;
  first_name?: string | null;
  last_name?: string | null;
  address?: string | null;
  /** `YYYY-MM-DD` — ngày dân sự, backend cố tình KHÔNG gửi kèm giờ. */
  birthday?: string | null;
  gender?: Gender | null;
}

/**
 * Tên quốc gia đọc được, theo ngôn ngữ đang hiển thị ("Việt Nam" · "日本" ·
 * "United States"). Thông báo lỗi nói tên nước thì rõ hơn nói mã "VN".
 */
function countryLabel(country: string, locale: string): string {
  try {
    return new Intl.DisplayNames([locale], { type: "region" }).of(country) ?? country;
  } catch {
    return country;
  }
}

// ---------------------------------------------------------------------------
// Field — nhãn + ô nhập, dùng chung cho mọi ô của form.
// ---------------------------------------------------------------------------
function Field({
  id,
  label,
  error,
  hint,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id} className="text-sm font-medium text-neutral-700">
        {label}
      </Label>
      {children}
      {error ? (
        <p className="text-xs text-destructive">{error}</p>
      ) : hint ? (
        <p className="text-xs text-neutral-400">{hint}</p>
      ) : null}
    </div>
  );
}

const INPUT_CLASS = "h-11 rounded-lg border-neutral-300 px-3.5 text-sm";

/** Ba cách ngày sinh hỏng, ba câu khác nhau — "không hợp lệ" không nói được phải sửa gì. */
const BIRTHDAY_ERROR_KEY = {
  incomplete: "birthdayIncomplete",
  invalid: "birthdayInvalid",
  future: "birthdayFuture",
} as const;

// ---------------------------------------------------------------------------
// AccountEditView — panel "Thông tin cá nhân" trong vỏ 2 cột (#1483).
//
// Bộ trường theo bản thiết kế: họ tên · SĐT · email · ngày sinh · giới tính.
// `address` KHÔNG còn ở đây (thiết kế tách sang mục "Địa chỉ" riêng) — form
// không gửi field đó nên địa chỉ đã lưu giữ nguyên, không bị xoá.
// ---------------------------------------------------------------------------
export default function AccountEditView() {
  // Cửa hàng đang xem, từ segment `[shop]` của URL (#1505).
  const { shop } = useParams<{ shop?: string }>();
  const { isLoggedIn, isLoading, user, refreshUser } = useAuth();
  const router = useRouter();
  const t = useTranslations("account");
  const tCommon = useTranslations("common");
  const locale = useLocale();

  // Số điện thoại hợp lệ theo NGÔN NGỮ đang xem: vi → VN, en → US, ja → JP.
  // Khác checkout (bám theo nước của chi nhánh) là có chủ ý — đây là hồ sơ của
  // khách, không gắn với một cửa hàng nào, nên ngôn ngữ họ chọn là tín hiệu
  // duy nhất về nước của số điện thoại.
  const phoneCountry = useMemo(() => deriveCountry(locale), [locale]);
  const phoneCountryName = useMemo(
    () => countryLabel(phoneCountry, locale),
    [phoneCountry, locale]
  );

  const [fullName, setFullName] = useState("");
  const [phone, setPhone] = useState("");
  const [birthday, setBirthday] = useState<BirthdayParts>(EMPTY_BIRTHDAY);
  const [gender, setGender] = useState<Gender | null>(null);
  const [fetching, setFetching] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  // Lấy hồ sơ đầy đủ lúc mount — `user` trong context chỉ có id/name/email.
  useEffect(() => {
    if (!user) return;
    apiFetch<{ data: ProfileData }>("/api/v1/customer/auth/user", { silent401: true })
      .then(({ data }) => {
        setFullName(data.first_name || data.name || "");
        setPhone(data.phone || "");
        setBirthday(parseBirthday(data.birthday));
        setGender(data.gender ?? null);
      })
      .catch(() => {
        setFullName(user.name || "");
      })
      .finally(() => setFetching(false));
  }, [user]);

  // Auth guard
  useEffect(() => {
    if (!isLoading && !isLoggedIn) {
      router.replace("/login?redirect=/account/edit");
    }
  }, [isLoading, isLoggedIn, router]);

  if (!isLoading && !isLoggedIn) return null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setErrors({});

    const nextErrors: Record<string, string[]> = {};

    // SĐT là ô không bắt buộc — bỏ trống nghĩa là xoá, không phải nhập sai.
    let phoneToSend: string | null = null;
    if (phone.trim() !== "") {
      const result = validatePhoneForCountry(phone, phoneCountry);
      if (result.valid) {
        // Gửi đi dạng chuẩn của nước đó (`033 690 9454`), không phải chuỗi thô
        // người dùng gõ — cùng một số thì trong DB chỉ có một cách viết.
        phoneToSend = result.formatted;
      } else {
        nextErrors.phone = [
          t(result.errorKey ?? "phoneInvalid", { country: phoneCountryName }),
        ];
      }
    }

    const resolved = resolveBirthday(birthday, todayIso());
    if (resolved.status !== "ok" && resolved.status !== "empty") {
      nextErrors.birthday = [t(BIRTHDAY_ERROR_KEY[resolved.status])];
    }

    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors);
      return;
    }

    setSaving(true);
    try {
      await apiFetch("/api/v1/customer/auth/user", {
        method: "PATCH",
        body: JSON.stringify({
          first_name: fullName,
          phone: phoneToSend,
          birthday: resolved.status === "ok" ? resolved.iso : null,
          gender,
        }),
      });
      // Ô nhập theo đúng chuỗi vừa lưu — tránh cảnh người dùng thấy chuỗi cũ
      // trên màn hình trong khi server đã giữ chuỗi khác.
      setPhone(phoneToSend ?? "");
      await refreshUser();
      toast.success(t("saved"));
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setErrors((err.body.errors as Record<string, string[]>) || {});
      } else {
        toast.error(t("errorOccurred"));
      }
    } finally {
      setSaving(false);
    }
  }

  const busy = isLoading || fetching;

  return (
    <>
      <h2 className="text-xl font-bold text-primary">{t("navProfile")}</h2>

      {busy ? (
        <div className="flex items-center justify-center py-20">
          <span className="size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="mt-6">
          <div className="grid gap-x-6 gap-y-5 md:grid-cols-2">
            <Field id="full_name" label={t("fullName")} error={errors.first_name?.[0]}>
              <Input
                id="full_name"
                value={fullName}
                onChange={(e) => setFullName(e.target.value)}
                maxLength={100}
                className={INPUT_CLASS}
                autoComplete="name"
              />
            </Field>

            <Field
              id="phone"
              label={t("phone")}
              error={errors.phone?.[0]}
              hint={t("phoneHint", { country: phoneCountryName })}
            >
              <Input
                id="phone"
                type="tel"
                inputMode="tel"
                value={phone}
                onChange={(e) => {
                  const next = e.target.value;
                  // Chỉ chèn dấu cách/gạch khi chuỗi DÀI RA. Format lúc người
                  // dùng đang xoá thì dấu vừa bị xoá lập tức mọc lại, và phím
                  // backspace trông như hỏng.
                  setPhone(
                    next.length > phone.length ? formatAsYouType(next, phoneCountry) : next
                  );
                  if (errors.phone?.length) setErrors((prev) => ({ ...prev, phone: [] }));
                }}
                maxLength={20}
                placeholder={t("phonePlaceholder")}
                aria-invalid={Boolean(errors.phone?.[0]) || undefined}
                className={INPUT_CLASS}
                autoComplete="tel"
              />
            </Field>

            {/* Email chỉ đọc: đổi email là luồng cần xác minh lại, không phải
                một ô trong form hồ sơ (API trả 422 nếu form gửi `email`). */}
            <Field id="email" label={t("email")} hint={t("emailReadOnly")}>
              {/* `readOnly` chứ không `disabled`: disabled kéo theo opacity-50
                  làm email đọc như placeholder mờ, mà đây là thông tin khách
                  cần đọc được — chỉ là không sửa tại đây. */}
              <Input
                id="email"
                value={user?.email ?? ""}
                readOnly
                aria-readonly="true"
                className={`${INPUT_CLASS} cursor-not-allowed bg-neutral-100 text-neutral-700`}
              />
            </Field>

            <Field id="birthday_first" label={t("birthday")} error={errors.birthday?.[0]}>
              <BirthdaySelect
                firstId="birthday_first"
                locale={locale}
                value={birthday}
                onChange={(next) => {
                  setBirthday(next);
                  if (errors.birthday?.length) setErrors((prev) => ({ ...prev, birthday: [] }));
                }}
                invalid={Boolean(errors.birthday?.[0])}
                labels={{
                  day: t("birthdayDay"),
                  month: t("birthdayMonth"),
                  year: t("birthdayYear"),
                }}
              />
            </Field>

            <div className="space-y-1.5">
              <Label className="text-sm font-medium text-neutral-700">{t("gender")}</Label>
              <div
                role="radiogroup"
                aria-label={t("gender")}
                className="grid grid-cols-2 gap-3 md:max-w-[calc(50%-0.375rem)]"
              >
                {(["female", "male"] as const).map((value) => {
                  const selected = gender === value;
                  return (
                    <button
                      key={value}
                      type="button"
                      role="radio"
                      aria-checked={selected}
                      // Bấm lại mục đang chọn = bỏ khai báo. `null` khác "nữ",
                      // và không có cách nào khác để quay về "chưa khai".
                      onClick={() => setGender(selected ? null : value)}
                      className={`h-11 rounded-lg border text-sm transition-colors ${
                        selected
                          ? "border-primary bg-primary/10 font-semibold text-primary"
                          : "border-neutral-300 bg-white text-neutral-700 hover:bg-neutral-50"
                      }`}
                    >
                      {t(value === "female" ? "genderFemale" : "genderMale")}
                    </button>
                  );
                })}
              </div>
              {errors.gender && <p className="text-xs text-destructive">{errors.gender[0]}</p>}
            </div>
          </div>

          <div className="mt-8 flex flex-col-reverse gap-3 border-t border-neutral-200 pt-6 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => router.push(accountHref(shop))}
              disabled={saving}
              className="h-11 rounded-lg px-8 text-sm font-medium"
            >
              {tCommon("cancel")}
            </Button>
            <Button type="submit" disabled={saving} className="h-11 rounded-lg px-8 text-sm font-bold">
              {saving && (
                <span className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
              )}
              {saving ? t("saving") : t("saveChanges")}
            </Button>
          </div>
        </form>
      )}
    </>
  );
}
