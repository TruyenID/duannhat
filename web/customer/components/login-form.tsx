"use client";

import { Suspense, useMemo, useState } from "react";
import { Link, useRouter } from "@/i18n/routing";
import { useSearchParams } from "next/navigation";
import { useTranslations } from 'next-intl';
import { useAuth } from "@/context/auth-context";
import { useBrand } from "@/context/brand-context";
import { useGlobalLoading } from "@/context/loading-context";
import { ApiError } from "@/lib/api";
import { loginOutcome, looksLikeEmail } from "@/lib/login-identifier";
import { registerHref } from "@/lib/shop-routes";
import Header from "@/components/Header";
import ForgotPasswordCard from "@/components/forgot-password-card";
import VerifyEmailCode from "@/components/verify-email-code";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

interface LoginFormProps {
  /** Branch slug from /login/[shop] route. Used to show branch name in the header. */
  shopSlug?: string;
}

function LoginFormInner({ shopSlug }: LoginFormProps) {
  const { login, resendVerification } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  const { branches, currentBranch } = useBrand();
  const { showLoading } = useGlobalLoading();
  const t = useTranslations('login');

  const fallbackRedirect = shopSlug ? `/takeaway/${shopSlug}` : "/";
  const redirect = searchParams.get("redirect") ?? fallbackRedirect;

  const branch = useMemo(
    () => (shopSlug ? branches.find((b) => b.slug === shopSlug) : undefined),
    [branches, shopSlug],
  );
  // Phụ đề chào theo THƯƠNG HIỆU, không phải tên chi nhánh: khách đăng nhập
  // vào một tài khoản của thương hiệu, chi nhánh chỉ là nơi họ đang mua. Danh
  // sách chi nhánh nạp bất đồng bộ nên tên có thể chưa có ở lần render đầu —
  // lúc đó dùng câu chào không kèm tên thay vì hiện chuỗi rỗng.
  const brandName = branch?.brand?.name || currentBranch.brand.name || "";

  // #1782 — ô đầu nhận EMAIL hoặc SỐ ĐIỆN THOẠI. Tên biến nói đúng điều đó:
  // gọi nó là `email` rồi truyền vào `resendVerification()` là cách một số điện
  // thoại lọt vào chỗ chỉ nhận địa chỉ thư.
  const [identifier, setIdentifier] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  // Mặc định TICK = giữ nguyên hành vi cũ (token ở localStorage). Bỏ tick thì
  // token chỉ sống trong tab hiện tại — xem `lib/auth-token.ts`.
  const [remember, setRemember] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  // #1680 — email đúng mật khẩu đúng nhưng chưa xác nhận. Khác null thì cả
  // trang nhường chỗ cho màn nhập mã: gõ lại mật khẩu bao nhiêu lần cũng không
  // qua, nên để lại form đăng nhập ở đó chỉ mời khách thử tiếp một đường cụt.
  const [unverifiedEmail, setUnverifiedEmail] = useState<string | null>(null);

  // #1783 — màn xin link đặt lại mật khẩu, chiếm chỗ form giống màn nhập mã.
  // Mở sẵn khi URL mang `?forgot=1`: trang đặt lại mật khẩu gửi khách về đây
  // sau một link đã chết, và câu nó vừa đọc là "hãy xin một link mới".
  const [forgotOpen, setForgotOpen] = useState(() => searchParams.get("forgot") === "1");

  // Kết quả xác nhận, do trang đăng ký đính vào URL sau khi nhập mã xong —
  // hoặc do backend đính vào khi khách bấm một LINK cũ còn sót trong hộp thư.
  const verified = searchParams.get("verified");
  // #1783 — vừa đặt lại mật khẩu xong. Không có câu này thì khách bị đá về một
  // form trắng và không biết mật khẩu mới đã ăn hay chưa.
  const passwordReset = searchParams.get("reset") === "ok";

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    setUnverifiedEmail(null);
    if (!identifier || !password) {
      setError(t('fillAllFields'));
      return;
    }
    setLoading(true);
    try {
      await login(identifier, password, remember);
      // Bridge button-spinner → next-page-mount gap with the global overlay
      // (auto-dismisses on pathname change).
      showLoading();
      router.push(redirect);
    } catch (err) {
      const outcome = loginOutcome(
        err instanceof ApiError ? { status: err.status, body: err.body } : {},
        identifier,
      );

      if (outcome.kind === "unverified") {
        // Mật khẩu ĐÚNG, chỉ thiếu bước xác nhận email — nên đưa thẳng khách
        // sang màn nhập mã thay vì để lại một câu lỗi kèm nút "gửi lại". Từ khi
        // thư mang MÃ chứ không mang link, trang đăng nhập không còn chỗ nào gõ
        // mã vào: gửi lại từ đây là gửi vào ngõ cụt.
        //
        // Địa chỉ lấy từ PHẢN HỒI, không phải từ ô nhập: khách đăng nhập bằng
        // số điện thoại (#1782) thì chuỗi họ gõ không gửi thư đi đâu được.
        //
        // Gửi luôn một mã mới: mã phát lúc đăng ký sống 10 phút, mà tới được
        // đây thì gần như chắc chắn nó đã hết hạn (khách bỏ dở rồi quay lại).
        // Lỗi gửi lại không chặn đường — màn nhập mã có nút gửi lại của nó.
        void resendVerification(outcome.email).catch(() => {});
        setUnverifiedEmail(outcome.email);
      } else if (outcome.kind === "fieldMessage") {
        // #1782 — hiện NGUYÊN câu backend nói về chuỗi định danh, hôm nay là
        // "số này gắn với nhiều tài khoản, hãy dùng email". Quy nó về
        // `invalidCredentials` là để nhóm khách đó gõ lại mật khẩu mãi mãi.
        setError(outcome.message);
      } else {
        setError(t('invalidCredentials'));
      }
      setLoading(false);
    }
  }

  /**
   * Xác nhận xong thì ĐĂNG NHẬP LUÔN, không đá về form.
   *
   * Tới được màn nhập mã nghĩa là backend đã trả 403 `email_not_verified` — tức
   * cặp email + mật khẩu vừa gõ đã được chấm là đúng (cổng xác nhận nằm SAU
   * `Hash::check`). Bắt gõ lại đúng thứ vừa gõ đúng là một bước thừa.
   */
  async function handleVerified() {
    setUnverifiedEmail(null);
    setLoading(true);
    try {
      // Đăng nhập lại bằng đúng chuỗi khách vừa gõ (email hay số đều được) —
      // không phải bằng địa chỉ lấy từ phản hồi 403: nếu số đó ứng với nhiều
      // tài khoản thì backend đã chặn từ lượt trước, còn ở đây dùng lại chuỗi
      // gốc giữ cho lượt thứ hai đi đúng con đường mà lượt đầu đã đi.
      await login(identifier, password, remember);
      showLoading();
      router.push(redirect);
    } catch {
      // Hiếm: mật khẩu đổi ở thiết bị khác giữa chừng, hoặc phiên mạng hỏng.
      // Trả về form với câu lỗi thường gặp thay vì treo ở màn nhập mã.
      setError(t('invalidCredentials'));
      setLoading(false);
    }
  }

  return (
    // Wrapper flex column với `min-h-screen` + `bg-muted/30` đảm bảo:
    // 1. Toàn trang chỉ 1 màu nền (Header bg-white + body bg-muted/30 → liền mạch)
    // 2. Form `flex-1` chiếm phần còn lại sau Header → `items-center justify-center`
    //    thực sự center theo cả 2 trục viewport trên desktop.
    <div className="flex min-h-screen flex-col bg-muted/30">
      {/* Header trang login (#1781) — logo + Đăng nhập/Đăng ký + đổi ngôn ngữ
          theo mockup. `hideSwitcher` giữ nguyên để logo là LINK về trang chủ
          chứ không phải nút mở brand switcher; `hideOrderHistory` bỏ cặp nút
          "Chưa thanh toán"/"Lịch sử đơn hàng" mà mockup không có. Không còn
          `hideAuth` (trước đây ẩn cả cụm auth) và không còn nút back. */}
      <Header
        showLogo
        hideSwitcher
        hideOrderCta
        hideOrderHistory
        hideShadow
      />
      <div className="flex flex-1 flex-col items-center justify-center px-4 py-8 md:py-12">
        {unverifiedEmail !== null ? (
          <VerifyEmailCode
            email={unverifiedEmail}
            brandName={brandName || undefined}
            onVerified={handleVerified}
          />
        ) : forgotOpen ? (
          <ForgotPasswordCard
            // Chỉ điền sẵn khi thứ khách vừa gõ THỰC SỰ là địa chỉ email
            // (#1782): màn kia chỉ nhận email, và đổ một số điện thoại vào đó
            // là bày sẵn một ô chắc chắn 422 rồi bắt khách tự xoá.
            initialEmail={looksLikeEmail(identifier) ? identifier.trim() : ""}
            onBack={() => setForgotOpen(false)}
          />
        ) : (
        /* Card ~800px = đúng khổ card trong ảnh thiết kế (đo được 794px); cột
           nhập 448px canh giữa nên hai bên còn ~176px, cân với chiều cao card.
           1140px từng thử qua và đọc ra một dải ngang rỗng hai bên — nó quay
           lại một lần nữa vì một lần trộn nhánh hỏng (d50807e), nên đừng đọc
           con số này như một thứ chưa ai cân nhắc. */
        <div className="w-full max-w-[800px] rounded-2xl border bg-card px-5 py-10 shadow-sm sm:px-10 md:py-16">
          <div className="mx-auto w-full max-w-md">
            <div className="mb-8 text-center">
              <h1 className="text-3xl font-bold tracking-tight md:text-[34px]">{t('title')}</h1>
              <p className="mt-2.5 text-base text-muted-foreground">
                {brandName ? t('welcomeBrand', { brand: brandName }) : t('welcome')}
              </p>
            </div>

            {/* #1783 — vừa đặt lại mật khẩu. Màu primary chứ không phải
                destructive: đây là tin vui, không phải lỗi. */}
            {passwordReset && (
              <div
                role="status"
                className="mb-4 rounded-xl border border-primary/20 bg-primary/8 px-4 py-3 text-sm text-primary"
              >
                {t('passwordResetDone')}
              </div>
            )}

            {/* Kết quả xác nhận email (#1680) — do trang đăng ký đính vào URL
                sau khi nhập mã xong, hoặc do backend đính vào khi khách bấm một
                LINK cũ còn sót trong hộp thư. `ok`/`already` là tin vui, phần
                còn lại phải nói rõ để khách biết làm gì tiếp. */}
            {verified && (
              <div
                className={
                  verified === "ok" || verified === "already"
                    ? "mb-4 rounded-xl border border-primary/20 bg-primary/8 px-4 py-3 text-sm text-primary"
                    : "mb-4 rounded-xl border border-destructive/20 bg-destructive/8 px-4 py-3 text-sm text-destructive"
                }
              >
                {t(`verified.${verified === "ok" ? "ok" : verified === "already" ? "already" : verified === "expired" ? "expired" : "invalid"}`)}
              </div>
            )}

            {/* Form */}
            <form onSubmit={handleSubmit} className="space-y-5">
              {error && (
                <div className="rounded-xl border border-destructive/20 bg-destructive/8 px-4 py-3 text-sm text-destructive">
                  {error}
                </div>
              )}

              <div className="space-y-1.5">
                <Label htmlFor="identifier" className="text-[15px] font-medium md:text-[15px] md:font-medium">
                  {t('identifier')}
                </Label>
                {/* `type="text"`, KHÔNG phải `type="email"` (#1782): trình duyệt
                    tự chặn form khi ô `type="email"` chứa một số điện thoại, và
                    chặn bằng một bong bóng của trình duyệt — không phải câu chữ
                    của mình, không dịch được, và mâu thuẫn thẳng với cái nhãn
                    vừa mời khách gõ số vào. `autoComplete="username"` để trình
                    quản lý mật khẩu điền được cả hai dạng. */}
                <Input
                  id="identifier"
                  type="text"
                  placeholder={t('identifierPlaceholder')}
                  autoComplete="username"
                  value={identifier}
                  onChange={(e) => setIdentifier(e.target.value)}
                  className="h-11 text-base md:text-base"
                  required
                />
              </div>

              <div className="space-y-1.5">
                <div className="flex items-center justify-between gap-2">
                  <Label htmlFor="password" className="text-[15px] font-medium md:text-[15px] md:font-medium">
                    {t('password')}
                  </Label>
                  {/* #1783 — mở màn xin link đặt lại, mang theo địa chỉ đang gõ
                      dở. Không đổi URL: khách đang giữa một lần đăng nhập hỏng,
                      và một trang mới sẽ nuốt mất `redirect` họ đang mang. */}
                  <button
                    type="button"
                    onClick={() => {
                      setError("");
                      setForgotOpen(true);
                    }}
                    className="text-[15px] text-primary hover:underline"
                  >
                    {t('forgotPassword')}
                  </button>
                </div>
                <div className="relative">
                  <Input
                    id="password"
                    type={showPassword ? "text" : "password"}
                    placeholder={t('passwordPlaceholder')}
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="h-11 pr-16 text-base md:text-base"
                    required
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((v) => !v)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-[15px] font-medium text-primary hover:underline"
                  >
                    {showPassword ? t('hidePassword') : t('showPassword')}
                  </button>
                </div>
              </div>

              <div className="flex items-center gap-2 pt-0.5">
                <Checkbox
                  id="remember"
                  className="size-[18px]"
                  checked={remember}
                  onCheckedChange={(checked) => setRemember(checked)}
                />
                <Label htmlFor="remember" className="text-[15px] font-normal md:text-[15px] md:font-normal">
                  {t('rememberMe')}
                </Label>
              </div>

              <Button type="submit" className="h-12 w-full gap-2 text-base font-semibold" disabled={loading}>
                {loading && (
                  <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                )}
                {loading ? t('loggingIn') : t('loginButton')}
              </Button>
            </form>

            <p className="mt-7 text-center text-[15px] text-muted-foreground">
              {t('noAccount')}{" "}
              <Link href={registerHref(shopSlug)} className="font-semibold text-primary hover:underline">
                {t('registerNow')}
              </Link>
            </p>
          </div>
        </div>
        )}
      </div>
    </div>
  );
}

export default function LoginForm(props: LoginFormProps) {
  return (
    <Suspense>
      <LoginFormInner {...props} />
    </Suspense>
  );
}
