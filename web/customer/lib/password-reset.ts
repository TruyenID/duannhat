// #1783 — luồng quên / đặt lại mật khẩu, phần KHÔNG có React.
//
// Cả hai endpoint đều CÔNG KHAI, nên tính chất quan trọng nhất của màn hình
// không phải "đặt lại được mật khẩu" mà là **form không được trở thành máy dò
// tài khoản**: một câu trả lời khác nhau cho địa chỉ có và không có tài khoản
// là cách rẻ nhất để liệt kê khách hàng của một quán.
//
// Backend đã giữ tính chất đó (`forgot` luôn 200 với cùng một câu; `reset` gộp
// token sai / hết hạn / đã dùng / địa chỉ không có tài khoản thành MỘT lỗi 422
// trên trường `token`). Nhưng nó chỉ còn đúng tới lúc FE quyết định hiển thị gì:
// in thẳng `body.message` ra màn hình là trao lại cho backend quyền quyết định
// UI nói gì, và một dòng copy đổi ở repo bên kia sẽ lặng lẽ mở lại đường dò.
//
// Nên các hàm dưới đây **không bao giờ trả về chuỗi đến từ server** ở nhánh nào
// liên quan tới sự tồn tại của tài khoản. Chúng trả về một literal, và trang tự
// chọn câu chữ của mình từ catalogue i18n.
//
// Module cố ý thuần tuý (không `next/*`, không import `lib/api`) để
// `node --test` chạy thẳng — `lib/api.ts` kéo theo alias `@/` và `window`.

import { loginHref } from './shop-routes.ts';

/** Kết quả một lượt gọi API, quy về dạng mà module này hiểu. */
export type ApiResult =
	| { ok: true; body?: unknown }
	/** `status` vắng mặt = lỗi mạng / không phải phản hồi HTTP. */
	| { ok: false; status?: number; body?: unknown };

/** Trạng thái màn "xin link đặt lại". */
export type ForgotPasswordState =
	/** Đã gửi — hoặc địa chỉ không có tài khoản. HAI TRƯỜNG HỢP NÀY GIỐNG NHAU. */
	| 'sent'
	/** Địa chỉ sai ĐỊNH DẠNG. Không nói gì về việc có tài khoản hay không. */
	| 'invalidEmail'
	| 'rateLimited'
	| 'error';

/**
 * Trạng thái để hiện sau một lượt xin link.
 *
 * `ok` ⇒ `sent`, KHÔNG đọc gì trong body. Backend hiện trả cùng một câu cho mọi
 * địa chỉ, nhưng kể cả khi nó đổi ý và bắt đầu trả `{ exists: false }` thì màn
 * hình vẫn không phân biệt được — đó là điểm của hàm này.
 *
 * 422 chỉ có thể là lỗi ĐỊNH DẠNG: `CustomerForgotPasswordRequest` cố ý không
 * có rule `exists:customers,email`, và lý do được ghi ngay tại chỗ. Vẫn không
 * lấy chuỗi của server ra dùng — câu chữ do FE sở hữu.
 */
export function forgotPasswordState(result: ApiResult): ForgotPasswordState {
	if (result.ok) return 'sent';
	if (result.status === 429) return 'rateLimited';
	if (result.status === 422) return 'invalidEmail';
	return 'error';
}

/** Vì sao lượt đặt mật khẩu mới thất bại. */
export type ResetPasswordFailure =
	/**
	 * Link không dùng được nữa. Gộp token sai / hết hạn / đã dùng / địa chỉ
	 * không có tài khoản — đúng như backend đã gộp. Với khách thì cả bốn đòi
	 * CÙNG một hành động: xin link mới.
	 */
	| 'linkUnusable'
	/** Mật khẩu mới không đạt chính sách (hoặc hai ô không khớp). */
	| 'weakPassword'
	| 'rateLimited'
	| 'error';

export interface ResetPasswordOutcome {
	failure: ResetPasswordFailure;
	/**
	 * Chỉ có ở `weakPassword`: thông điệp chính sách mật khẩu do backend dịch.
	 *
	 * Đây là nhánh DUY NHẤT được phép mượn chữ của server, vì nó nói về mật khẩu
	 * khách vừa gõ chứ không nói gì về việc địa chỉ kia có tài khoản hay không.
	 */
	passwordMessage?: string;
}

/**
 * Phân loại thất bại của bước đặt mật khẩu mới.
 *
 * **Lỗi `token` thắng lỗi `password`** khi cả hai cùng có: link đã chết thì gõ
 * lại mật khẩu mạnh hơn cũng không cứu được, và để khách sửa mật khẩu rồi ăn
 * tiếp đúng lỗi cũ là một vòng lặp không có lối ra.
 *
 * Lỗi trên `email` cũng quy về `linkUnusable`: địa chỉ nằm trong query của link,
 * khách không gõ nó — sai nghĩa là link hỏng.
 */
export function resetPasswordOutcome(result: ApiResult): ResetPasswordOutcome {
	if (result.ok) return { failure: 'error' };
	if (result.status === 429) return { failure: 'rateLimited' };
	if (result.status !== 422) return { failure: 'error' };

	const errors = readErrors(result.body);
	if (errors.token !== undefined || errors.email !== undefined) {
		return { failure: 'linkUnusable' };
	}
	if (errors.password !== undefined) {
		return { failure: 'weakPassword', passwordMessage: errors.password };
	}
	// 422 không kèm trường nào nhận ra được: coi như link hỏng, vì đó là kết cục
	// duy nhất khách có đường đi tiếp (xin link mới).
	return { failure: 'linkUnusable' };
}

/** Tham số mà link trong thư mang theo. */
export interface ResetLinkParams {
	token: string;
	email: string;
	/** Slug cửa hàng, có thể vắng — khách cũ không gắn chi nhánh nào. */
	shop: string | null;
}

/**
 * Đọc `token` + `email` + `shop` từ query của link trong thư.
 *
 * Trả `null` khi thiếu token hoặc email: link như vậy không thể gửi đi đâu
 * được, nên trang phải hiện thẳng "link không dùng được" thay vì bày ra một
 * form mà mọi lượt bấm đều 422.
 *
 * Nhận một hàm đọc query (`URLSearchParams.get`) chứ không nhận
 * `URLSearchParams`, để `node --test` gọi được mà không cần DOM.
 */
export function parseResetLink(get: (key: string) => string | null): ResetLinkParams | null {
	const token = (get('token') ?? '').trim();
	const email = (get('email') ?? '').trim();
	if (token === '' || email === '') return null;

	const shop = (get('shop') ?? '').trim();
	return { token, email, shop: shop === '' ? null : shop };
}

/**
 * Đích sau khi đặt lại mật khẩu xong: trang đăng nhập CỦA CỬA HÀNG trong link.
 *
 * `?reset=ok` để trang đăng nhập hiện một câu xác nhận — không có nó thì khách
 * bị đá về form đăng nhập trắng trơn và không biết mật khẩu mới đã ăn hay chưa.
 *
 * Không có cửa hàng thì `loginHref` trỏ /select-branch, và cờ bị **bỏ đi**:
 * /select-branch không chuyển tiếp query sang trang đăng nhập, nên đính vào chỉ
 * tạo ra một tham số chết trong URL. Đổi lại khách mất một câu xác nhận ở nhánh
 * hiếm này — chấp nhận được hơn là một cờ trông như đang hoạt động.
 */
export function loginHrefAfterReset(shop: string | null): string {
	return loginHrefWithFlag(shop, 'reset=ok');
}

/**
 * Đích của nút "Xin link mới" trên trang đặt lại: trang đăng nhập với màn xin
 * link MỞ SẴN.
 *
 * Không mở sẵn thì khách vừa đọc "hãy xin link mới" lại rơi vào form đăng nhập
 * và phải tự tìm ra rằng đường đi tiếp nằm sau chữ "Quên mật khẩu?".
 */
export function loginHrefRequestingLink(shop: string | null): string {
	return loginHrefWithFlag(shop, 'forgot=1');
}

function loginHrefWithFlag(shop: string | null, flag: string): string {
	const href = loginHref(shop);
	return href.includes('?') ? href : `${href}?${flag}`;
}

/** `{ errors: { field: ["msg", ...] } }` → `{ field: "msg" }`. Bỏ qua mọi thứ lạ. */
function readErrors(body: unknown): Record<string, string | undefined> {
	if (body === null || typeof body !== 'object') return {};
	const errors = (body as { errors?: unknown }).errors;
	if (errors === null || typeof errors !== 'object') return {};

	const out: Record<string, string | undefined> = {};
	for (const [field, messages] of Object.entries(errors as Record<string, unknown>)) {
		if (Array.isArray(messages) && typeof messages[0] === 'string') {
			out[field] = messages[0];
		} else if (typeof messages === 'string') {
			out[field] = messages;
		}
	}
	return out;
}
