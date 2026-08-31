"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { useAuth } from "@/context/auth-context";
import { ApiError } from "@/lib/api";
import { maskEmail } from "@/lib/mask-email";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";

interface VerifyEmailCodeProps {
  /** Địa chỉ vừa đăng ký — mã đã được gửi tới đây. */
  email: string;
  /** Tên thương hiệu, chỉ để nhắc khách nhớ ra mình đăng ký ở đâu. */
  brandName?: string;
  /** Gọi khi email đã xác nhận xong (kể cả trường hợp đã xác nhận từ trước). */
  onVerified: () => void;
}

/** Số ô nhập. Phải khớp `regex:/^[0-9]{6}$/` của backend. */
const CODE_LENGTH = 6;

/** Giây phải chờ giữa hai lần bấm gửi lại. Backend chặn ở 3 lần/phút. */
const RESEND_COOLDOWN_SECONDS = 60;

/**
 * Màn nhập mã xác thực 6 chữ số sau khi đăng ký.
 *
 * Thay cho màn "hãy mở link trong thư" (#1680). Lý do đổi nằm ở hành vi thật
 * của khách: họ đăng ký trên điện thoại, bấm link trong Gmail sẽ mở webview
 * riêng của Gmail — khác origin, `localStorage` rỗng — và bỏ lại tab đăng ký ở
 * chỗ cũ. Gõ 6 chữ số thì khách ở nguyên nơi họ đang đứng.
 *
 * Địa chỉ hiện ra ở dạng CHE (`v****h@gmail.com`): màn này bật lên ngay giữa
 * quán, và thứ khách cần biết chỉ là "đúng hộp thư của mình chưa".
 *
 * SÁU Ô RIÊNG chứ không phải một ô 6 ký tự — nhưng chỉ ở phần nhìn: mọi phím
 * lùi/mũi tên/dán đều được xử lý trên MỘT chuỗi `digits`, nên không có chuyện
 * mỗi ô giữ một mẩu trạng thái rồi lệch nhau. Ô nào cũng nhận được cả 6 chữ số
 * dán vào, vì khách dán từ Gmail thì con trỏ đang ở đâu là chuyện may rủi.
 */
export default function VerifyEmailCode({ email, brandName, onVerified }: VerifyEmailCodeProps) {
  const t = useTranslations("verifyEmail");
  const { verifyEmailCode, resendVerification } = useAuth();

  const [digits, setDigits] = useState<string[]>(() => Array(CODE_LENGTH).fill(""));
  const [submitting, setSubmitting] = useState(false);
  const [resending, setResending] = useState(false);
  const [cooldown, setCooldown] = useState(RESEND_COOLDOWN_SECONDS);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const inputs = useRef<Array<HTMLInputElement | null>>([]);
  const code = digits.join("");
  const complete = code.length === CODE_LENGTH;
  const maskedEmail = useMemo(() => maskEmail(email), [email]);

  // Đếm ngược bắt đầu NGAY khi màn hiện ra, không đợi lần bấm gửi lại đầu tiên:
  // mã vừa được gửi lúc đăng ký, nên bấm gửi lại ngay giây đầu chỉ đổi mã khách
  // sắp đọc được — và ăn thẳng vào hạn 3 lần/phút của backend.
  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = setTimeout(() => setCooldown((s) => s - 1), 1000);
    return () => clearTimeout(timer);
  }, [cooldown]);

  useEffect(() => {
    inputs.current[0]?.focus();
  }, []);

  /** Đặt lại nội dung 6 ô và (tuỳ chọn) dời con trỏ. KHÔNG đụng tới error/notice. */
  function putDigits(next: string[], focusIndex?: number) {
    setDigits(next);
    if (focusIndex !== undefined) {
      inputs.current[Math.min(Math.max(focusIndex, 0), CODE_LENGTH - 1)]?.focus();
    }
  }

  /**
   * Khách vừa sửa mã ⇒ lời nhắn cũ hết đúng.
   *
   * Tách khỏi `putDigits` vì có chỗ cần dọn ô mà PHẢI GIỮ lời nhắn: sau một mã
   * sai, ô được xoá sạch để gõ lại nhưng câu "mã không đúng" phải còn trên màn.
   */
  function editDigits(next: string[], focusIndex?: number) {
    setError("");
    setNotice("");
    putDigits(next, focusIndex);
  }

  function handleChange(index: number, raw: string) {
    const typed = raw.replace(/\D/g, "");
    const next = [...digits];

    if (typed === "") {
      // Xoá bằng phím Delete, hoặc gõ đè bằng ký tự không phải số.
      next[index] = "";
      editDigits(next);
      return;
    }

    // Ô đang focus luôn được `select()`, nên gõ đè thường cho `typed` đúng 1 ký
    // tự. Trình duyệt/IME nào ghép "cũ + mới" thì bỏ phần cũ đi. Phần còn lại
    // rải sang các ô kế tiếp — dán vào ô thứ ba thì điền từ ô thứ ba, không
    // nhét cả cụm vào một ô.
    const incoming =
      typed.length > 1 && digits[index] !== "" && typed.startsWith(digits[index])
        ? typed.slice(digits[index].length)
        : typed;

    for (let i = 0; i < incoming.length && index + i < CODE_LENGTH; i++) {
      next[index + i] = incoming[i];
    }

    editDigits(next, index + incoming.length);
  }

  function handleKeyDown(index: number, event: React.KeyboardEvent<HTMLInputElement>) {
    if (event.key === "Backspace") {
      event.preventDefault();
      const next = [...digits];
      // Ô đang trống thì lùi lại và xoá ô trước — hành vi mà mọi màn OTP đều
      // có, và thiếu nó thì khách phải bấm chuột vào từng ô để sửa.
      if (next[index] === "" && index > 0) {
        next[index - 1] = "";
        editDigits(next, index - 1);
        return;
      }
      next[index] = "";
      editDigits(next, index);
      return;
    }

    if (event.key === "ArrowLeft" && index > 0) {
      event.preventDefault();
      inputs.current[index - 1]?.focus();
    }

    if (event.key === "ArrowRight" && index < CODE_LENGTH - 1) {
      event.preventDefault();
      inputs.current[index + 1]?.focus();
    }
  }

  function handlePaste(index: number, event: React.ClipboardEvent<HTMLInputElement>) {
    const pasted = event.clipboardData.getData("text").replace(/\D/g, "");
    if (pasted === "") return;

    event.preventDefault();
    const next = [...digits];
    for (let i = 0; i < pasted.length && index + i < CODE_LENGTH; i++) {
      next[index + i] = pasted[i];
    }
    editDigits(next, index + pasted.length);
  }

  async function submit(value: string) {
    if (value.length !== CODE_LENGTH || submitting) return;

    setSubmitting(true);
    setError("");
    setNotice("");
    try {
      await verifyEmailCode(email, value);
      onVerified();
    } catch (err) {
      handleVerifyError(err);
    } finally {
      setSubmitting(false);
    }
  }

  /**
   * `reason` của backend quyết định lời khuyên, vì hai kết cục đòi hai hành động
   * khác nhau: `invalid` thì gõ lại là xong, còn `expired`/`too_many_attempts`
   * thì mã đã CHẾT — gõ lại bao nhiêu lần cũng vô ích, phải bấm gửi lại. Gộp
   * chúng vào một câu "mã không đúng" là để khách gõ lại cho tới lúc bỏ cuộc.
   */
  function handleVerifyError(err: unknown) {
    if (!(err instanceof ApiError)) {
      setError(t("networkError"));
      return;
    }

    // 429 của route throttle KHÔNG mang `reason`, nên nếu để nó rơi xuống nhánh
    // mặc định thì khách bị báo "mã không đúng" cho một mã có thể hoàn toàn
    // đúng — và sẽ gõ lại, ăn tiếp 429, đến khi bỏ cuộc. Giữ nguyên các chữ số
    // đã gõ: chúng chưa từng được chấm.
    if (err.status === 429) {
      setError(t("rateLimited"));
      return;
    }

    const reason = (err.body as { reason?: string }).reason;
    const dead = reason === "expired" || reason === "too_many_attempts";

    if (dead) {
      // Mã đã chết ⇒ mở khoá nút gửi lại NGAY. Bắt khách ngồi đếm nốt phần đếm
      // ngược của một mã không còn dùng được là một ngõ cụt có đồng hồ.
      setCooldown(0);
    }

    setError(t(reason === "expired" ? "codeExpired" : dead ? "tooManyAttempts" : "codeInvalid"));
    // Dọn ô để gõ lại, nhưng giữ nguyên câu lỗi vừa đặt — `putDigits` không
    // đụng tới nó (khác `editDigits`).
    putDigits(Array(CODE_LENGTH).fill(""), 0);
  }

  async function handleResend() {
    if (cooldown > 0 || resending) return;

    setResending(true);
    setError("");
    setNotice("");
    try {
      await resendVerification(email);
      setCooldown(RESEND_COOLDOWN_SECONDS);
      // Mã cũ vừa bị backend ghi đè — để nguyên các chữ số đã gõ trên màn là
      // mời khách bấm gửi một mã chắc chắn sai.
      putDigits(Array(CODE_LENGTH).fill(""), 0);
      setNotice(t("resent"));
    } catch {
      setError(t("resendFailed"));
    } finally {
      setResending(false);
    }
  }

  return (
    // Cùng khung card với trang đăng nhập/đăng ký — khách vừa rời trang đăng ký
    // sang đây, một khung lệch radius/padding đọc ra như hai sản phẩm khác nhau.
    // 800px là khổ card đo được trong ảnh thiết kế.
    <div className="w-full max-w-[800px] rounded-2xl border bg-card px-5 py-10 shadow-sm sm:px-10 md:py-16">
      <div className="mx-auto w-full max-w-md">
        <div className="text-center">
          <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">{t("title")}</h1>
          <p className="mt-2.5 text-sm text-muted-foreground">
            {t("intro")}{" "}
            <span className="font-semibold break-all text-foreground">{maskedEmail}</span>
          </p>
        </div>

        <form
          className="mt-7"
          onSubmit={(e) => {
            e.preventDefault();
            void submit(code);
          }}
          noValidate
        >
          <div className="flex justify-center gap-2 sm:gap-5">
            {digits.map((digit, index) => (
              <input
                key={index}
                ref={(el) => {
                  inputs.current[index] = el;
                }}
                type="text"
                // Số 0 mờ trong ô trống (theo mockup) — cho biết mỗi ô nhận
                // MỘT chữ số, thay vì để sáu ô trống không nói gì.
                placeholder="0"
                // `inputMode` + `pattern` để bàn phím số bật lên trên điện
                // thoại. KHÔNG dùng `type="number"`: nó kéo theo nút tăng/giảm,
                // cho phép `e`/`+`/`-`, và cuộn chuột đổi giá trị.
                inputMode="numeric"
                pattern="[0-9]*"
                autoComplete={index === 0 ? "one-time-code" : "off"}
                maxLength={CODE_LENGTH}
                aria-label={t("digitLabel", { index: index + 1 })}
                value={digit}
                onChange={(e) => handleChange(index, e.target.value)}
                onKeyDown={(e) => handleKeyDown(index, e)}
                onPaste={(e) => handlePaste(index, e)}
                onFocus={(e) => e.target.select()}
                disabled={submitting}
                className={cn(
                  "size-12 rounded-lg border bg-background text-center text-xl font-semibold tabular-nums",
                  "placeholder:font-normal placeholder:text-muted-foreground/50",
                  "focus-visible:ring-primary/30 focus-visible:border-primary focus-visible:ring-2 focus-visible:outline-none",
                  "disabled:opacity-60 sm:size-14",
                  error && "border-destructive",
                )}
              />
            ))}
          </div>

          <div className="mt-3 text-right">
            <button
              type="button"
              onClick={handleResend}
              disabled={cooldown > 0 || resending}
              className="text-sm font-medium text-primary hover:underline disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:no-underline"
            >
              {resending
                ? t("resending")
                : cooldown > 0
                  ? t("resendIn", { seconds: cooldown })
                  : t("resend")}
            </button>
          </div>

          {error && (
            <p role="alert" className="mt-4 text-sm text-destructive">
              {error}
            </p>
          )}
          {notice && (
            <p role="status" className="mt-4 text-sm text-primary">
              {notice}
            </p>
          )}

          <Button
            type="submit"
            disabled={!complete || submitting}
            className="mt-5 h-12 w-full rounded-xl text-base font-bold"
          >
            {submitting && (
              <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
            )}
            {submitting ? t("verifying") : t("submit")}
          </Button>
        </form>

        <div className="mt-6 rounded-xl bg-muted/60 px-4 py-3.5 text-sm text-muted-foreground">
          <span className="font-semibold text-foreground">{t("noEmailTitle")}</span>{" "}
          {brandName ? t("noEmailHelpBrand", { brand: brandName }) : t("noEmailHelp")}
        </div>
      </div>
    </div>
  );
}
