"use client";

import { useMemo, useState } from "react";
import { Link, useRouter } from "@/i18n/routing";
import { useTranslations } from "next-intl";
import { useAuth } from "@/context/auth-context";
import { useBrand } from "@/context/brand-context";
import { ApiError } from "@/lib/api";
import { loginHref } from "@/lib/shop-routes";
import {
  callingCodeFor,
  DEFAULT_PHONE_COUNTRY,
  flagEmoji,
  formatAsYouType,
  isSupportedPhoneCountry,
  phonePlaceholderFor,
  reformatForCountry,
  SUPPORTED_PHONE_COUNTRIES,
  toE164,
  validatePhoneForCountry,
  type SupportedPhoneCountry,
} from "@/lib/phone";
import { PASSWORD_MIN_LENGTH, passwordMeetsPolicy } from "@/lib/password-policy";
import { cn } from "@/lib/utils";
import Header from "@/components/Header";
import PasswordChecklist from "@/components/password-checklist";
import VerifyEmailCode from "@/components/verify-email-code";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { ChevronDown, Eye, EyeOff } from "lucide-react";

interface RegisterFormProps {
  /** Slug cửa hàng lấy từ route /register/[shop]. */
  shopSlug: string;
}

/** Ba giá trị của `CustomerGenderEnum` phía backend — không thêm bớt ở đây. */
const GENDERS = ["male", "female", "other"] as const;

/**
 * Form đăng ký, luôn gắn với một cửa hàng (#1505).
 *
 * `shopSlug` KHÔNG phải thứ trang trí: nó đi thẳng vào request đăng ký để
 * backend đóng dấu `branch_id`/`brand_id`/`organization_id` lên khách mới.
 * Trước đây khách tự đăng ký không thuộc cửa hàng nào cả.
 *
 * #1780 dựng lại theo mockup 2 cột: thêm SĐT (bắt buộc), ngày sinh + giới tính
 * (tuỳ chọn), lựa chọn tham gia chương trình thành viên, và ô đồng ý điều
 * khoản. Mọi trường mới đều có chỗ nhận thật ở backend — không ô nào là trang
 * trí.
 */
export default function RegisterForm({ shopSlug }: RegisterFormProps) {
  const { register } = useAuth();
  const { branches } = useBrand();
  const router = useRouter();
  const t = useTranslations("register");

  const branch = useMemo(
    () => branches.find((b) => b.slug === shopSlug),
    [branches, shopSlug],
  );
  // Dòng điều khoản nói tên THƯƠNG HIỆU, không phải tên chi nhánh: pháp nhân
  // đứng sau điều khoản là thương hiệu, và tên chi nhánh (vd "PHO EXPRESS
  // イオンモール津田沼店") dài tới mức nuốt cả câu.
  const displayName = branch?.brand?.name || branch?.name || "Tempo";

  // #1845 — quốc gia của SĐT do KHÁCH chọn (Nhật / Việt Nam / Anh), mặc định
  // Nhật. Trước đây nó bị khoá theo `effective_order_policy.phone_country` của
  // chi nhánh (plan-035): khách Việt/Anh đang ở Nhật, hay du khách giữ số nước
  // ngoài, không có đường nào đăng ký được vì `validatePhoneForCountry` từ chối
  // số của họ. Trang thanh toán và trang sửa hồ sơ vẫn giữ cơ chế cũ — đổi cả
  // bốn chỗ là một quyết định khác.
  const [phoneCountry, setPhoneCountry] = useState<SupportedPhoneCountry>(DEFAULT_PHONE_COUNTRY);

  const [firstName, setFirstName] = useState("");
  const [lastName, setLastName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [birthday, setBirthday] = useState("");
  const [gender, setGender] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loyaltyOptedIn, setLoyaltyOptedIn] = useState(true);
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  // #1680 — địa chỉ đang chờ xác nhận. Khác null nghĩa là đăng ký đã xong và
  // phần còn lại nằm ở hộp thư của khách; form nhường chỗ cho màn hướng dẫn.
  const [pendingEmail, setPendingEmail] = useState<string | null>(null);

  function clearFieldError(field: string) {
    setFieldErrors((prev) => (prev[field] ? { ...prev, [field]: "" } : prev));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    setFieldErrors({});

    const newFieldErrors: Record<string, string> = {};
    if (!firstName.trim()) {
      newFieldErrors.first_name = t("firstNameRequired");
    } else if (firstName.length > 100) {
      newFieldErrors.first_name = t("firstNameTooLong");
    }
    if (lastName && lastName.length > 100) {
      newFieldErrors.last_name = t("lastNameTooLong");
    }
    if (!email.trim()) {
      newFieldErrors.email = t("emailRequired");
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      newFieldErrors.email = t("emailInvalid");
    }
    // SĐT bắt buộc kể từ #1780, và phải hợp lệ theo kế hoạch đánh số của nước
    // ĐANG CHỌN — không chỉ "có nhập gì đó".
    const phoneResult = validatePhoneForCountry(phone, phoneCountry);
    if (!phoneResult.valid) {
      // Nội suy TÊN nước đã dịch, không phải mã ISO: "Số điện thoại không hợp
      // lệ cho khu vực JP" là câu nói với lập trình viên, không phải với khách.
      newFieldErrors.phone = t(phoneResult.errorKey ?? "phoneInvalid", {
        country: t(`phoneCountryOption.${phoneCountry}`),
      });
    }
    if (!password) {
      newFieldErrors.password = t("passwordRequired");
    } else if (!passwordMeetsPolicy(password)) {
      // Checklist ngay dưới ô đã chỉ ra ĐIỀU KIỆN NÀO còn thiếu, nên dòng lỗi
      // này chỉ cần nói "chưa đạt" thay vì liệt kê lại bốn điều kiện.
      newFieldErrors.password = t("passwordWeak");
    }
    if (!confirmPassword) {
      newFieldErrors.password_confirmation = t("confirmPasswordRequired");
    } else if (password !== confirmPassword) {
      newFieldErrors.password_confirmation = t("passwordMismatch");
    }

    if (Object.keys(newFieldErrors).length > 0) {
      setFieldErrors(newFieldErrors);
      // Không set error banner — lỗi đã hiện dưới từng field
      return;
    }

    setLoading(true);
    try {
      const { email: pending } = await register({
        firstName,
        lastName,
        email,
        // Gửi E.164 (`+84912345678`) chứ không phải chuỗi khách gõ: cùng một số
        // nhập thành `0912...` hay `912...` phải ra cùng một giá trị trong DB,
        // nếu không thì tra khách theo SĐT ở POS sẽ trượt.
        phone: toE164(phone, phoneCountry),
        password,
        passwordConfirmation: confirmPassword,
        birthday,
        gender,
        loyaltyOptedIn,
        branchSlug: shopSlug,
      });
      // KHÔNG đẩy vào /takeaway nữa (#1680): đăng ký không còn tạo phiên, nên
      // đá thẳng vào trang mua hàng là đưa khách tới một chỗ họ chưa vào được.
      setPendingEmail(pending);
    } catch (err) {
      handleRegisterError(err);
    } finally {
      setLoading(false);
    }
  }

  function handleRegisterError(err: unknown): void {
    if (!(err instanceof ApiError)) {
      setError(t("networkError"));
      return;
    }

    const body = err.body as { message?: string; errors?: Record<string, string[]> };
    const backendErrors = body.errors || {};
    const newFieldErrors: Record<string, string> = {};

    // Map backend field errors to UI fields
    Object.entries(backendErrors).forEach(([field, messages]) => {
      const msg = messages[0];
      if (field === "email" && msg.toLowerCase().includes("taken")) {
        newFieldErrors.email = t("emailTaken");
      } else {
        newFieldErrors[field] = msg;
      }
    });

    // `branch_slug` không có ô nhập nào để gắn lỗi — cửa hàng đến từ URL. Lỗi
    // của nó nghĩa là URL trỏ vào cửa hàng không nhận khách (đã gỡ, đã tắt),
    // nên phải hiện thành banner chứ không rơi vào khoảng không.
    const hasShopError = Boolean(newFieldErrors.branch_slug);
    if (hasShopError) {
      setError(t("shopUnavailable"));
      delete newFieldErrors.branch_slug;
    }

    if (Object.keys(newFieldErrors).length > 0) {
      // Có lỗi field cụ thể → chỉ hiển thị dưới input, KHÔNG hiển thị banner
      setFieldErrors(newFieldErrors);
    } else if (!hasShopError) {
      // Lỗi chung không liên quan field (vd server 500, db timeout)
      setError(body.message || t("registerFailed"));
    }
  }

  const inputClass = (field: string) =>
    cn(
      "h-11 rounded-xl text-base md:text-base",
      fieldErrors[field] && "border-destructive focus-visible:ring-destructive",
    );

  return (
    <div className="flex min-h-screen flex-col bg-muted/30">
      {/* Cùng một header với trang đăng nhập (#1781): logo + Đăng nhập/Đăng ký
          + đổi ngôn ngữ. `hideSwitcher` để logo là LINK về trang chủ chứ không
          phải nút mở brand switcher — mockup không có chevron cạnh logo. */}
      <Header showLogo hideSwitcher hideOrderCta hideOrderHistory hideShadow />

      <div className="flex flex-1 flex-col items-center justify-center px-4 py-8 md:py-12">
        {pendingEmail !== null ? (
          <VerifyEmailCode
            email={pendingEmail}
            brandName={displayName}
            // Xác nhận xong KHÔNG tự đăng nhập (#1680 giữ cổng ở `login`), nên
            // đá sang trang đăng nhập của đúng cửa hàng này với `?verified=ok`
            // — banner ở đó là chỗ đã có sẵn để nói "xong rồi, mời đăng nhập".
            onVerified={() => router.replace(`${loginHref(shopSlug)}?verified=ok`)}
          />
        ) : (
          // Khung card giống hệt trang đăng nhập — hai trang là anh em, khách đi
          // qua lại giữa chúng và một khung lệch radius/padding đọc ra như hai
          // sản phẩm khác nhau. Khác một chỗ: đăng nhập bó nội dung về
          // `max-w-sm`, còn ở đây lưới 2 cột dùng hết bề ngang.
          <div className="w-full max-w-3xl rounded-2xl border bg-card px-5 py-10 shadow-sm sm:px-10 md:py-16">
            <div className="mb-8 text-center">
              <h1 className="text-3xl font-bold tracking-tight md:text-[34px]">{t("createAccount")}</h1>
              <p className="mt-2.5 text-base text-muted-foreground">{t("subtitle")}</p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-5" noValidate>
              {error && (
                <div className="rounded-xl border border-destructive/20 bg-destructive/8 px-4 py-3 text-sm text-destructive">
                  {error}
                </div>
              )}

              <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                {/* Họ và tên đệm */}
                <div className="space-y-1.5">
                  <Label htmlFor="lastName" className="text-[15px] font-semibold">
                    {t("lastName")} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="lastName"
                    type="text"
                    placeholder={t("lastNamePlaceholder")}
                    autoComplete="family-name"
                    value={lastName}
                    onChange={(e) => {
                      setLastName(e.target.value);
                      clearFieldError("last_name");
                    }}
                    className={inputClass("last_name")}
                  />
                  {fieldErrors.last_name && (
                    <p className="text-xs text-destructive">{fieldErrors.last_name}</p>
                  )}
                </div>

                {/* Tên */}
                <div className="space-y-1.5">
                  <Label htmlFor="firstName" className="text-[15px] font-semibold">
                    {t("firstName")} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="firstName"
                    type="text"
                    placeholder={t("firstNamePlaceholder")}
                    autoComplete="given-name"
                    value={firstName}
                    onChange={(e) => {
                      setFirstName(e.target.value);
                      clearFieldError("first_name");
                    }}
                    className={inputClass("first_name")}
                  />
                  {fieldErrors.first_name && (
                    <p className="text-xs text-destructive">{fieldErrors.first_name}</p>
                  )}
                </div>

                {/* Email */}
                <div className="space-y-1.5">
                  <Label htmlFor="email" className="text-[15px] font-semibold">
                    {t("email")} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="email"
                    type="email"
                    placeholder={t("emailPlaceholder")}
                    autoComplete="email"
                    value={email}
                    onChange={(e) => {
                      setEmail(e.target.value);
                      clearFieldError("email");
                    }}
                    className={inputClass("email")}
                  />
                  {fieldErrors.email && (
                    <p className="text-xs text-destructive">{fieldErrors.email}</p>
                  )}
                </div>

                {/* Số điện thoại — bộ chọn mã vùng dính liền ô nhập (#1845) */}
                <div className="space-y-1.5">
                  <Label htmlFor="phone" className="text-[15px] font-semibold">
                    {t("phone")} <span className="text-destructive">*</span>
                  </Label>
                  <div className="flex gap-2">
                    {/* `<select>` NGUYÊN BẢN, không phải popup tự dựng: đây là ô
                        ba lựa chọn trên một form khách gõ trên điện thoại, và
                        bánh xe chọn của hệ điều hành vừa quen tay vừa không có
                        gì để hỏng khi bàn phím ảo đẩy layout. */}
                    <div className="relative shrink-0">
                      <select
                        id="phoneCountry"
                        aria-label={t("phoneCountryLabel")}
                        value={phoneCountry}
                        onChange={(e) => {
                          const next = e.target.value;
                          if (!isSupportedPhoneCountry(next)) return;
                          setPhoneCountry(next);
                          // Đổi nước thì gõ lại mặt nạ cho số ĐANG có: giữ
                          // nguyên nhóm chữ số của nước cũ là hiện một số mà ô
                          // này vừa mới thôi chấp nhận.
                          setPhone((prev) => reformatForCountry(prev, next));
                          clearFieldError("phone");
                        }}
                        className="h-11 appearance-none rounded-xl border border-input bg-muted/50 py-0 pr-8 pl-3 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                      >
                        {SUPPORTED_PHONE_COUNTRIES.map((country) => (
                          <option key={country} value={country}>
                            {`${flagEmoji(country)} ${callingCodeFor(country)} ${t(`phoneCountryOption.${country}`)}`}
                          </option>
                        ))}
                      </select>
                      <ChevronDown
                        aria-hidden
                        className="pointer-events-none absolute top-1/2 right-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                      />
                    </div>
                    <Input
                      id="phone"
                      type="tel"
                      inputMode="tel"
                      // Gợi ý đi theo NƯỚC đang chọn, không theo ngôn ngữ giao
                      // diện — khách xem tiếng Việt ở chi nhánh Nhật từng thấy
                      // gợi ý số Việt Nam trong ô chỉ nhận số Nhật.
                      placeholder={phonePlaceholderFor(phoneCountry)}
                      autoComplete="tel"
                      value={phone}
                      onChange={(e) => {
                        setPhone(formatAsYouType(e.target.value, phoneCountry));
                        clearFieldError("phone");
                      }}
                      className={cn(inputClass("phone"), "min-w-0 flex-1")}
                    />
                  </div>
                  {fieldErrors.phone && (
                    <p className="text-xs text-destructive">{fieldErrors.phone}</p>
                  )}
                </div>

                {/* Ngày sinh — tuỳ chọn */}
                <div className="space-y-1.5">
                  <Label htmlFor="birthday" className="text-[15px] font-semibold">
                    {t("birthday")}{" "}
                    <span className="font-normal text-muted-foreground">{t("optional")}</span>
                  </Label>
                  <Input
                    id="birthday"
                    type="date"
                    autoComplete="bday"
                    // Không cho chọn ngày tương lai — backend từ chối bằng
                    // `before_or_equal:today`, và một 422 cho ô mà lịch vừa cho
                    // bấm là lỗi của giao diện, không phải của khách.
                    max={new Date().toISOString().slice(0, 10)}
                    value={birthday}
                    onChange={(e) => {
                      setBirthday(e.target.value);
                      clearFieldError("birthday");
                    }}
                    className={inputClass("birthday")}
                  />
                  {fieldErrors.birthday && (
                    <p className="text-xs text-destructive">{fieldErrors.birthday}</p>
                  )}
                </div>

                {/* Giới tính — tuỳ chọn */}
                <div className="space-y-1.5">
                  <span className="block text-[15px] font-semibold">
                    {t("gender")}{" "}
                    <span className="font-normal text-muted-foreground">{t("optional")}</span>
                  </span>
                  <RadioGroup
                    value={gender}
                    onValueChange={(value) => {
                      setGender(typeof value === "string" ? value : "");
                      clearFieldError("gender");
                    }}
                    aria-label={t("gender")}
                    className="flex h-11 items-center gap-5"
                  >
                    {GENDERS.map((value) => (
                      <label
                        key={value}
                        className="flex cursor-pointer items-center gap-2 text-sm"
                      >
                        <RadioGroupItem value={value} />
                        {t(`genderOption.${value}`)}
                      </label>
                    ))}
                  </RadioGroup>
                  {fieldErrors.gender && (
                    <p className="text-xs text-destructive">{fieldErrors.gender}</p>
                  )}
                </div>

                {/* Mật khẩu */}
                <div className="space-y-1.5">
                  <Label htmlFor="password" className="text-[15px] font-semibold">
                    {t("password")} <span className="text-destructive">*</span>
                  </Label>
                  <div className="relative">
                    <Input
                      id="password"
                      type={showPassword ? "text" : "password"}
                      placeholder={t("passwordPlaceholder", { min: PASSWORD_MIN_LENGTH })}
                      autoComplete="new-password"
                      value={password}
                      onChange={(e) => {
                        setPassword(e.target.value);
                        clearFieldError("password");
                      }}
                      className={cn(inputClass("password"), "pr-10")}
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((v) => !v)}
                      className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                      aria-label={showPassword ? t("hidePassword") : t("showPassword")}
                    >
                      {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                    </button>
                  </div>
                  {fieldErrors.password && (
                    <p className="text-xs text-destructive">{fieldErrors.password}</p>
                  )}
                </div>

                {/* Xác nhận mật khẩu */}
                <div className="space-y-1.5">
                  <Label htmlFor="confirmPassword" className="text-[15px] font-semibold">
                    {t("confirmPassword")} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="confirmPassword"
                    type={showPassword ? "text" : "password"}
                    placeholder={t("confirmPasswordPlaceholder")}
                    autoComplete="new-password"
                    value={confirmPassword}
                    onChange={(e) => {
                      setConfirmPassword(e.target.value);
                      clearFieldError("password_confirmation");
                    }}
                    className={inputClass("password_confirmation")}
                  />
                  {fieldErrors.password_confirmation && (
                    <p className="text-xs text-destructive">{fieldErrors.password_confirmation}</p>
                  )}
                </div>
              </div>

              {/* Checklist trải hết bề ngang, ngay dưới cặp ô mật khẩu. */}
              <PasswordChecklist value={password} />

              {/* Chương trình thành viên (#1780) — lựa chọn này ghi thật vào
                  `customers.loyalty_opted_in` và quyết định có tích điểm hay
                  không, chứ không phải một câu quảng cáo. */}
              <fieldset className="rounded-2xl bg-muted/50 p-4 sm:p-5">
                <legend className="sr-only">{t("membership.question")}</legend>
                <p className="text-[15px] font-semibold">{t("membership.question")}</p>
                <p className="mt-1 text-xs text-muted-foreground">{t("membership.description")}</p>
                <RadioGroup
                  value={loyaltyOptedIn ? "yes" : "no"}
                  onValueChange={(value) => setLoyaltyOptedIn(value === "yes")}
                  aria-label={t("membership.question")}
                  className="mt-4 grid gap-3 sm:grid-cols-2"
                >
                  {(["yes", "no"] as const).map((choice) => (
                    <label
                      key={choice}
                      className={cn(
                        "flex cursor-pointer items-start gap-3 rounded-xl border bg-background p-3.5 transition-colors",
                        (choice === "yes") === loyaltyOptedIn
                          ? "border-primary ring-1 ring-primary/30"
                          : "hover:border-muted-foreground/30",
                      )}
                    >
                      <RadioGroupItem value={choice} className="mt-0.5" />
                      <span className="space-y-0.5">
                        <span className="block text-[15px] font-semibold">
                          {t(`membership.${choice}`)}
                        </span>
                        <span className="block text-xs text-muted-foreground">
                          {t(`membership.${choice}Hint`)}
                        </span>
                      </span>
                    </label>
                  ))}
                </RadioGroup>
              </fieldset>

              {/* Đồng ý điều khoản. Hai link còn trỏ `#` — cùng trạng thái với
                  `components/Footer.tsx`, đổi cả hai chỗ khi có trang thật. */}
              <label className="flex cursor-pointer items-start gap-3 text-sm">
                <Checkbox
                  checked={acceptedTerms}
                  onCheckedChange={(checked) => setAcceptedTerms(checked === true)}
                  className="mt-0.5"
                />
                <span className="text-muted-foreground">
                  {t.rich("terms", {
                    brand: displayName,
                    termsLink: (chunks) => (
                      <Link href="#" className="text-primary hover:underline">
                        {chunks}
                      </Link>
                    ),
                    privacyLink: (chunks) => (
                      <Link href="#" className="text-primary hover:underline">
                        {chunks}
                      </Link>
                    ),
                  })}
                </span>
              </label>

              {/* `flex justify-center` chứ KHÔNG phải `mx-auto` trên nút:
                  `Button` là `inline-flex`, mà `margin: auto` không căn giữa
                  hộp inline-level — nút hẹp lại đúng như mong đợi rồi nằm dính
                  mép trái, trông như quên căn chứ không như hỏng. */}
              <div className="flex justify-center pt-1">
                <Button
                  type="submit"
                  // Chưa tick điều khoản thì KHÔNG cho gửi — nút xám là lời từ
                  // chối đọc được ngay, khác với việc gửi lên rồi trả về lỗi.
                  disabled={loading || !acceptedTerms}
                  className="h-12 w-full rounded-xl text-base font-bold sm:w-3/5"
                >
                  {loading && (
                    <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                  )}
                  {loading ? t("registering") : t("registerButton")}
                </Button>
              </div>
            </form>

            <p className="mt-6 text-center text-sm text-muted-foreground">
              {t("haveAccount")}{" "}
              <Link href={loginHref(shopSlug)} className="font-semibold text-primary hover:underline">
                {t("loginLink")}
              </Link>
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
