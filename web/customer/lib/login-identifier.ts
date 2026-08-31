// #1781 / #1782 — ô đầu của form đăng nhập nhận EMAIL **hoặc** SỐ ĐIỆN THOẠI.
//
// Backend (#1782) nhận trường `identifier` và tự đoán email hay số; trường
// `email` cũ vẫn còn cho client chưa cập nhật. Phần khó không nằm ở việc đổi
// tên trường mà ở việc PHÂN LOẠI THẤT BẠI: từ khi ô này nhận cả hai thứ, một
// lượt đăng nhập hỏng có ba kết cục khác nhau đòi ba hành động khác nhau của
// khách, và gộp chúng thành một câu "sai mật khẩu" là đẩy một nhóm khách vào
// ngõ cụt vĩnh viễn (xem `loginOutcome`).
//
// Module cố ý THUẦN (không `next/*`, không `lib/api`) để `node --test` chạy
// thẳng — cùng lý do với `lib/password-reset.ts`.

/** Kết quả một lượt gọi API, quy về dạng mà module này hiểu. */
export type ApiResult =
	/** `status` vắng mặt = lỗi mạng / không phải phản hồi HTTP. */
	{ status?: number; body?: unknown };

/**
 * Cùng phép kiểm dạng địa chỉ với `forgot-password-card.tsx` và form đăng ký —
 * ba màn không được lệch nhau.
 *
 * Đây KHÔNG phải để chặn khách gõ: backend mới là nơi quyết định một chuỗi là
 * email hay số điện thoại. Nó chỉ để trả lời một câu hỏi của riêng phía FE:
 * "thứ khách vừa gõ có dùng làm email được không" — dùng khi cần điền sẵn ô
 * email ở màn quên mật khẩu, và khi cần đoán địa chỉ cho màn nhập mã.
 */
const EMAIL_SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function looksLikeEmail(value: string): boolean {
	return EMAIL_SHAPE.test(value.trim());
}

/** Vì sao lượt đăng nhập thất bại, và khách phải làm gì tiếp. */
export type LoginOutcome =
	/**
	 * Mật khẩu ĐÚNG, chỉ thiếu bước xác nhận email (#1680). `email` là địa chỉ
	 * THẬT của tài khoản do backend trả về — không phải chuỗi khách vừa gõ, vì
	 * đăng nhập bằng số điện thoại thì chuỗi đó không gửi thư đi đâu được.
	 */
	| { kind: 'unverified'; email: string }
	/**
	 * Backend từ chối chính CHUỖI ĐỊNH DANH, và câu nó trả về là thứ duy nhất
	 * nói cho khách biết phải làm gì — hiện nay là ca "số này gắn với nhiều tài
	 * khoản, hãy dùng email" (#1782 chốt (b): không bao giờ xác thực một định
	 * danh mơ hồ). Nuốt câu này đi thì nhóm khách đó chỉ thấy "sai mật khẩu" và
	 * không đời nào đoán ra lý do.
	 */
	| { kind: 'fieldMessage'; message: string }
	/** Sai định danh hoặc sai mật khẩu — gộp làm một, cố ý. */
	| { kind: 'invalid' };

/**
 * Phân loại thất bại của một lượt đăng nhập.
 *
 * ## Vì sao chỉ trường `identifier` được mượn chữ của server
 *
 * Backend đặt `auth.failed` lên trường **`email`** và `auth.phone_ambiguous`
 * lên trường **`identifier`** — hai chỗ khác nhau, không phải ngẫu nhiên. Nhờ
 * vậy "in ra thông điệp của trường `identifier`" đúng bằng "in ra những lỗi
 * KHÔNG nói gì về việc tài khoản có tồn tại hay không": số trùng, chuỗi quá
 * dài, chuỗi rỗng. Còn nhánh nói về sự tồn tại của tài khoản thì nằm ở `email`
 * và bị quy hết về `invalid` với câu chữ của riêng FE.
 *
 * Đây là ràng buộc CHÉO REPO: nếu backend chuyển `auth.failed` sang trường
 * `identifier` thì hàm này sẽ bắt đầu in "email hoặc mật khẩu không đúng" theo
 * chữ của server — vẫn không rò rỉ, nhưng câu chữ đổi chủ. `login-identifier.test.ts`
 * ghim hành vi này.
 *
 * @param typed Chuỗi khách vừa gõ — chỉ dùng làm phương án dự phòng khi phản
 *   hồi 403 không mang `email` (backend cũ hơn #1680).
 */
export function loginOutcome(result: ApiResult, typed: string): LoginOutcome {
	const body = asObject(result.body);

	if (result.status === 403 && body.code === 'email_not_verified') {
		const email =
			typeof body.email === 'string' && body.email !== ''
				? body.email
				: looksLikeEmail(typed)
					? typed.trim()
					: '';
		// Không có địa chỉ nào để gửi mã tới thì màn nhập mã không chạy được —
		// nhánh này chỉ tới được với một backend cũ hơn #1680 (403 không kèm
		// `email`) CỘNG với việc khách đăng nhập bằng số điện thoại, tức không
		// tới được. Trả `invalid` là câu sai, nhưng là cái sai duy nhất không
		// dẫn khách vào một màn hình không bấm được gì.
		if (email !== '') return { kind: 'unverified', email };
		return { kind: 'invalid' };
	}

	if (result.status === 422) {
		const message = readFirstError(body, 'identifier');
		if (message !== undefined) return { kind: 'fieldMessage', message };
	}

	return { kind: 'invalid' };
}

/**
 * Trường gửi lên endpoint đăng nhập.
 *
 * Gửi `identifier`, KHÔNG gửi `email`. Cả hai cùng lúc cũng hợp lệ với backend
 * (`identifier` được ưu tiên) nhưng gửi kèm một `email` chứa số điện thoại là
 * để lại một trường nói dối trong log và trong mọi lần đọc payload sau này.
 */
export function loginPayload(
	identifier: string,
	password: string,
	deviceName: string,
): { identifier: string; password: string; device_name: string } {
	return { identifier: identifier.trim(), password, device_name: deviceName };
}

function asObject(body: unknown): Record<string, unknown> {
	if (body === null || typeof body !== 'object') return {};
	return body as Record<string, unknown>;
}

/** `{ errors: { field: ["msg", ...] } }` → `"msg"`. Bỏ qua mọi thứ lạ. */
function readFirstError(body: Record<string, unknown>, field: string): string | undefined {
	const errors = body.errors;
	if (errors === null || typeof errors !== 'object') return undefined;

	const messages = (errors as Record<string, unknown>)[field];
	if (Array.isArray(messages) && typeof messages[0] === 'string' && messages[0] !== '') {
		return messages[0];
	}
	if (typeof messages === 'string' && messages !== '') return messages;
	return undefined;
}
