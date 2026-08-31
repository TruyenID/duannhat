"use client";

import { Suspense, useMemo, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { Link, useRouter } from "@/i18n/routing";
import { useGlobalLoading } from "@/context/loading-context";
import { ApiError, apiFetch } from "@/lib/api";
import { maskEmail } from "@/lib/mask-email";
import { PASSWORD_MIN_LENGTH, passwordMeetsPolicy } from "@/lib/password-policy";
import {
  loginHrefAfterReset,
  loginHrefRequestingLink,
  parseResetLink,
  resetPasswordOutcome,
} from "@/lib/password-reset";
import { loginHref } from "@/lib/shop-routes";
import Header from "@/components/Header";
import PasswordChecklist from "@/components/password-checklist";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/**
 * Trang đặt mật khẩu mới bằng link trong thư (#1783).
 *
 * `token` + `email` (+ `shop` nếu có) nằm trong query của link do backend dựng
 * — xem `App\Notifications\Customer\ResetCustomerPassword`. Không có chúng thì
 * trang KHÔNG bày form ra: mọi lượt bấm sẽ ăn 422, và bắt khách gõ xong hai ô
 * mật khẩu rồi mới nói "link hỏng" là lãng phí đúng chỗ họ đang bực nhất.
 *
 * ## Chính sách mật khẩu ở đây theo REGISTRATION, không theo endpoint
 *
 * `CustomerResetPasswordRequest` dùng `Password::defaults()` (tối thiểu 8 ký
 * tự), trong khi đăng ký và đổi mật khẩu dùng `StrongCustomerPassword` (10 ký
 * tự + hoa + chữ-và-số + ký tự đặc biệt). Tức endpoint đặt lại đang NHẬN mật
 * khẩu yếu hơn chính sách của sản phẩm — một tài khoản có thể hạ cấp mật khẩu
 * bằng đúng luồng sinh ra để cứu nó.
 *
 * Màn này cố ý theo luật CHẶT hơn (cùng checklist với trang đăng ký). Thường
 * thì FE chặt hơn BE là lỗi — `lib/password-policy.ts` ghi rõ vì sao — nhưng
 * cái lệch ở đây nằm ở phía BE: một mật khẩu FE từ chối cũng là mật khẩu mà
 * trang đăng ký từ chối, nên khách không gặp luật nào mới. Khoảng lệch đã báo
 * lại ở #1783 để sửa bên backend.
 */
function ResetPasswordFormInner() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const { showLoading } = useGlobalLoading();
  const t = useTranslations("resetPassword");

  const link = useMemo(
    () => parseResetLink((key) => searchParams.get(key)),
    [searchParams],
  );

  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  // Link đã chết ⇒ form biến mất hẳn. Để lại hai ô mật khẩu bên dưới một câu
  // "link không dùng được nữa" chỉ mời khách thử lại một đường cụt.
  const [linkDead, setLinkDead] = useState(false);

  const shop = link?.shop ?? null;
  const maskedEmail = useMemo(() => (link ? maskEmail(link.email) : ""), [link]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!link || submitting) return;

    if (!passwordMeetsPolicy(password)) {
      setError(t("passwordWeak"));
      return;
    }
    if (password !== confirmPassword) {
      setError(t("passwordMismatch"));
      return;
    }

    setError("");
    setSubmitting(true);
    try {
      await apiFetch("/api/v1/customer/auth/password/reset", {
        method: "POST",
        body: JSON.stringify({
          token: link.token,
          email: link.email,
          password,
          password_confirmation: confirmPassword,
        }),
      });
      // Backend đã xoá sạch phiên cũ và đóng dấu email đã xác nhận, nên khách
      // đăng nhập được ngay bằng mật khẩu vừa đặt.
      showLoading();
      router.replace(loginHrefAfterReset(shop));
    } catch (err) {
      const outcome = resetPasswordOutcome(
        err instanceof ApiError
          ? { ok: false, status: err.status, body: err.body }
          : { ok: false },
      );

      if (outcome.failure === "linkUnusable") {
        setLinkDead(true);
      } else if (outcome.failure === "weakPassword") {
        // Nhánh DUY NHẤT được mượn chữ của backend: nó nói về mật khẩu vừa gõ,
        // không nói gì về việc địa chỉ kia có tài khoản hay không.
        setError(outcome.passwordMessage ?? t("passwordWeak"));
      } else {
        setError(t(outcome.failure));
      }
      setSubmitting(false);
    }
  }

  const unusable = link === null || linkDead;

  return (
    <div className="flex min-h-screen flex-col bg-muted/30">
      <Header showLogo hideSwitcher hideOrderCta hideOrderHistory hideShadow />
      <div className="flex flex-1 flex-col items-center justify-center px-4 py-8 md:py-12">
        <div className="w-full max-w-[800px] rounded-2xl border bg-card px-5 py-10 shadow-sm sm:px-10 md:py-16">
          <div className="mx-auto w-full max-w-md">
            {unusable ? (
              <>
                <div className="text-center">
                  <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">
                    {t("invalidLinkTitle")}
                  </h1>
                </div>

                <p
                  role="alert"
                  className="mt-6 rounded-xl border border-destructive/20 bg-destructive/8 px-4 py-3 text-sm text-destructive"
                >
                  {t("linkUnusable")}
                </p>

                <Button
                  type="button"
                  onClick={() => {
                    showLoading();
                    router.replace(loginHrefRequestingLink(shop));
                  }}
                  className="mt-6 h-12 w-full rounded-xl text-base font-bold"
                >
                  {t("requestNew")}
                </Button>
              </>
            ) : (
              <>
                <div className="text-center">
                  <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">{t("title")}</h1>
                  <p className="mt-2.5 text-sm text-muted-foreground">
                    {t("forAccount", { email: maskedEmail })}
                  </p>
                </div>

                <form onSubmit={handleSubmit} className="mt-7 space-y-5" noValidate>
                  {error && (
                    <p
                      role="alert"
                      className="rounded-xl border border-destructive/20 bg-destructive/8 px-4 py-3 text-sm text-destructive"
                    >
                      {error}
                    </p>
                  )}

                  <div className="space-y-1.5">
                    <Label
                      htmlFor="reset-password"
                      className="text-[15px] font-medium md:text-[15px] md:font-medium"
                    >
                      {t("newPassword")}
                    </Label>
                    <div className="relative">
                      <Input
                        id="reset-password"
                        type={showPassword ? "text" : "password"}
                        placeholder={t("passwordPlaceholder", { min: PASSWORD_MIN_LENGTH })}
                        autoComplete="new-password"
                        autoFocus
                        value={password}
                        onChange={(e) => {
                          setPassword(e.target.value);
                          setError("");
                        }}
                        className="h-11 pr-16 text-base md:text-base"
                      />
                      <button
                        type="button"
                        onClick={() => setShowPassword((v) => !v)}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-[15px] font-medium text-primary hover:underline"
                      >
                        {showPassword ? t("hidePassword") : t("showPassword")}
                      </button>
                    </div>
                    <PasswordChecklist value={password} />
                  </div>

                  <div className="space-y-1.5">
                    <Label
                      htmlFor="reset-password-confirm"
                      className="text-[15px] font-medium md:text-[15px] md:font-medium"
                    >
                      {t("confirmPassword")}
                    </Label>
                    <Input
                      id="reset-password-confirm"
                      type={showPassword ? "text" : "password"}
                      placeholder={t("confirmPlaceholder")}
                      autoComplete="new-password"
                      value={confirmPassword}
                      onChange={(e) => {
                        setConfirmPassword(e.target.value);
                        setError("");
                      }}
                      className="h-11 text-base md:text-base"
                    />
                  </div>

                  <Button
                    type="submit"
                    disabled={submitting}
                    className="h-12 w-full gap-2 rounded-xl text-base font-bold"
                  >
                    {submitting && (
                      <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    )}
                    {submitting ? t("submitting") : t("submit")}
                  </Button>
                </form>

                <div className="mt-6 text-center">
                  <Link
                    href={loginHref(shop)}
                    className="text-[15px] font-medium text-primary hover:underline"
                  >
                    {t("backToLogin")}
                  </Link>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

export default function ResetPasswordForm() {
  return (
    <Suspense>
      <ResetPasswordFormInner />
    </Suspense>
  );
}
