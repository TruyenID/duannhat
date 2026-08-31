import ResetPasswordForm from "@/components/reset-password-form";

/**
 * `/{locale}/reset-password?token=…&email=…&locale=…&shop=…` (#1783).
 *
 * Đích của link trong thư đặt lại mật khẩu. KHÔNG nằm dưới `/login/[shop]` dù
 * cửa hàng cũng đi kèm: `shop` là tuỳ chọn (khách cũ không gắn chi nhánh nào)
 * và middleware #1505 sẽ đá mọi URL thiếu segment cửa hàng về /select-branch —
 * tức làm chết đúng những link mà backend cố ý gửi không kèm shop.
 */
export default function ResetPasswordPage() {
  return <ResetPasswordForm />;
}
