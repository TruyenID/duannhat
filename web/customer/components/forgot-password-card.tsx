"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ApiError, apiFetch } from "@/lib/api";
import { forgotPasswordState } from "@/lib/password-reset";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

interface ForgotPasswordCardProps {
  /** Địa chỉ khách vừa gõ ở form đăng nhập — điền sẵn để khỏi gõ lại. */
  initialEmail?: string;
  /** Quay lại form đăng nhập. */
  onBack: () => void;
}

/** Cùng phép kiểm dạng địa chỉ với form đăng ký — hai màn không được lệch nhau. */
const EMAIL_SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Màn xin link đặt lại mật khẩu (#1783).
 *
 * Nằm NGAY TRONG trang đăng nhập chứ không phải một route riêng, cùng cách
 * `VerifyEmailCode` chiếm chỗ form: khách bấm "Quên mật khẩu?" là đang đứng
 * giữa một lần đăng nhập hỏng, và đưa họ sang một URL khác làm mất luôn địa chỉ
 * vừa gõ.
 *
 * ## Màn này không được trả lời câu "địa chỉ đó có tài khoản không"
 *
 * Đó là toàn bộ lý do backend trả CÙNG một 200 cho mọi địa chỉ. Nên sau khi
 * gửi, màn hiện đúng MỘT câu có điều kiện — "nếu địa chỉ này có tài khoản..." —
 * và không có nhánh nào khác. Không đọc gì trong body phản hồi
 * (`forgotPasswordState` cố ý bỏ qua nó), không in message của server ra.
 *
 * Trạng thái "đã gửi" dùng màu primary, KHÔNG phải destructive: không có gì
 * hỏng ở đây, kể cả khi địa chỉ không có tài khoản.
 */
export default function ForgotPasswordCard({ initialEmail, onBack }: ForgotPasswordCardProps) {
  const t = useTranslations("forgotPassword");

  const [email, setEmail] = useState(initialEmail ?? "");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [sent, setSent] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (submitting) return;

    const value = email.trim();
    if (value === "") {
      setError(t("emailRequired"));
      return;
    }
    if (!EMAIL_SHAPE.test(value)) {
      setError(t("emailInvalid"));
      return;
    }

    setError("");
    setSubmitting(true);
    try {
      await apiFetch("/api/v1/customer/auth/password/forgot", {
        method: "POST",
        body: JSON.stringify({ email: value }),
      });
      setSent(true);
    } catch (err) {
      // Quy lỗi về dạng thuần rồi mới phân loại — `lib/password-reset.ts` là
      // nơi duy nhất quyết định trạng thái, và nó không đọc chuỗi của server.
      const state = forgotPasswordState(
        err instanceof ApiError
          ? { ok: false, status: err.status, body: err.body }
          : { ok: false },
      );
      if (state === "sent") {
        setSent(true);
      } else {
        setError(t(state));
      }
    } finally {
      setSubmitting(false);
    }
  }

  // Cùng khổ card với màn nhập mã xác thực — khách vừa rời form đăng nhập sang
  // đây, một khung lệch radius/padding đọc ra như hai sản phẩm khác nhau.
  return (
    <div className="w-full max-w-[800px] rounded-2xl border bg-card px-5 py-10 shadow-sm sm:px-10 md:py-16">
      <div className="mx-auto w-full max-w-md">
        {sent ? (
          <>
            <div className="text-center">
              <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">
                {t("sentTitle")}
              </h1>
            </div>

            <div
              role="status"
              className="mt-6 rounded-xl border border-primary/20 bg-primary/8 px-4 py-3 text-sm text-primary"
            >
              {t("sent")}
            </div>

            <p className="mt-4 text-sm text-muted-foreground">{t("sentHint")}</p>

            <Button
              type="button"
              onClick={onBack}
              className="mt-6 h-12 w-full rounded-xl text-base font-bold"
            >
              {t("back")}
            </Button>

            <div className="mt-3 text-center">
              <button
                type="button"
                onClick={() => {
                  // Về lại form với địa chỉ cũ còn nguyên: lý do thường gặp
                  // nhất để quay lại đây là gõ nhầm một ký tự.
                  setSent(false);
                  setError("");
                }}
                className="text-sm font-medium text-primary hover:underline"
              >
                {t("tryAnother")}
              </button>
            </div>
          </>
        ) : (
          <>
            <div className="text-center">
              <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">{t("title")}</h1>
              <p className="mt-2.5 text-sm text-muted-foreground">{t("intro")}</p>
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
                <Label htmlFor="forgot-email" className="text-[15px] font-medium md:text-[15px] md:font-medium">
                  {t("email")}
                </Label>
                <Input
                  id="forgot-email"
                  type="email"
                  placeholder={t("emailPlaceholder")}
                  autoComplete="email"
                  autoFocus
                  value={email}
                  onChange={(e) => {
                    setEmail(e.target.value);
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
              <button
                type="button"
                onClick={onBack}
                className="text-[15px] font-medium text-primary hover:underline"
              >
                {t("back")}
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
