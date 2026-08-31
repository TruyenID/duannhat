import { describe, it, test } from "node:test";
import assert from "node:assert/strict";

import {
	forgotPasswordState,
	loginHrefAfterReset,
	loginHrefRequestingLink,
	parseResetLink,
	resetPasswordOutcome,
	type ApiResult,
} from "./password-reset.ts";

// ---------------------------------------------------------------------------
// #1783 — thứ đáng kiểm nhất ở đây KHÔNG phải "form gửi được request".
//
// Mà là: **màn hình không phân biệt được địa chỉ có tài khoản với địa chỉ
// không có**. Một form quên-mật-khẩu công khai trả lời khác nhau cho hai
// trường hợp đó là cách rẻ nhất để liệt kê khách hàng của một quán — và nó bị
// phá bởi đúng loại thay đổi trông vô hại nhất: "hiện luôn message của server
// cho khách biết chuyện gì xảy ra".
//
// Backend đã cố ý trả cùng một 200 cho cả hai. Các test dưới đây canh nửa còn
// lại: kể cả khi backend đổi ý và bắt đầu nói ra, FE vẫn không hiện.
// ---------------------------------------------------------------------------

/**
 * Những body mà một backend BẤT CẨN có thể trả về. Không cái nào được làm đổi
 * thứ khách nhìn thấy.
 */
const LEAKY_SUCCESS_BODIES: unknown[] = [
	{ message: "If that address has an account, a reset link has been sent." },
	{ message: "No account found for that address." },
	{ exists: false },
	{ exists: true, customer_id: "cus_123" },
	{ sent: false, reason: "unknown_email" },
	null,
	undefined,
	"",
];

describe("xin link đặt lại — không phân biệt được tài khoản có hay không", () => {
	it("mọi phản hồi thành công đều ra ĐÚNG MỘT trạng thái", () => {
		const states = new Set(
			LEAKY_SUCCESS_BODIES.map((body) => forgotPasswordState({ ok: true, body })),
		);

		assert.deepEqual([...states], ["sent"]);
	});

	it("không mang chuỗi nào của server ra ngoài", () => {
		// Kiểu trả về đã là union literal, nhưng đó là bảo đảm lúc biên dịch:
		// một `return body.message as ForgotPasswordState` vẫn qua được tsc.
		const allowed = new Set(["sent", "invalidEmail", "rateLimited", "error"]);

		const results: ApiResult[] = [
			...LEAKY_SUCCESS_BODIES.map((body) => ({ ok: true, body }) as ApiResult),
			{ ok: false, status: 422, body: { errors: { email: ["This email is not registered."] } } },
			{ ok: false, status: 404, body: { message: "Customer not found" } },
			{ ok: false, status: 429, body: {} },
			{ ok: false, body: null },
		];

		for (const result of results) {
			assert.ok(
				allowed.has(forgotPasswordState(result)),
				`trạng thái lạ cho ${JSON.stringify(result)}`,
			);
		}
	});
});

test("429 nói riêng — nếu gộp vào 'error' thì khách thử lại ngay và ăn tiếp 429", () => {
	assert.equal(forgotPasswordState({ ok: false, status: 429, body: {} }), "rateLimited");
});

test("422 là lỗi ĐỊNH DẠNG địa chỉ, không phải lỗi 'không có tài khoản'", () => {
	// Backend cố ý không có rule `exists`, nên 422 chỉ tới từ `email` sai dạng.
	// Câu chữ do FE sở hữu — test này chỉ chốt nhánh, `messages/` chốt lời.
	assert.equal(forgotPasswordState({ ok: false, status: 422, body: {} }), "invalidEmail");
});

test("lỗi mạng (không có status) không được đọc ra như 'đã gửi'", () => {
	// Nhánh dễ sai nhất theo hướng NGƯỢC LẠI: gộp mọi thứ vào "sent" cho an
	// toàn thì khách chờ một lá thư không bao giờ tới.
	assert.equal(forgotPasswordState({ ok: false, body: null }), "error");
});

// ---------------------------------------------------------------------------

describe("đặt mật khẩu mới", () => {
	it("token sai, token hết hạn, token đã dùng và địa chỉ không có tài khoản là MỘT kết cục", () => {
		// Backend gộp cả bốn thành 422 trên trường `token` với cùng một message.
		// Nếu FE tách chúng ra bằng cách đọc message thì công gộp đổ sông biển.
		const bodies = [
			{ errors: { token: ["This password reset token is invalid."] } },
			{ errors: { token: ["This password reset token has expired."] } },
			{ errors: { token: ["Token already used."] } },
			{ errors: { token: ["We can't find a user with that email address."] } },
		];

		const outcomes = new Set(
			bodies.map((body) => resetPasswordOutcome({ ok: false, status: 422, body }).failure),
		);

		assert.deepEqual([...outcomes], ["linkUnusable"]);
	});

	it("không mang message của token ra màn hình", () => {
		const outcome = resetPasswordOutcome({
			ok: false,
			status: 422,
			body: { errors: { token: ["No customer registered with vananh@gmail.com"] } },
		});

		assert.equal(outcome.passwordMessage, undefined);
	});

	it("lỗi token THẮNG lỗi mật khẩu khi cả hai cùng có", () => {
		// Link đã chết thì gõ mật khẩu mạnh hơn cũng vô ích. Ưu tiên ngược lại
		// đẩy khách vào vòng lặp: sửa mật khẩu → 422 y hệt → sửa tiếp.
		const outcome = resetPasswordOutcome({
			ok: false,
			status: 422,
			body: {
				errors: {
					token: ["invalid"],
					password: ["Mật khẩu phải có ít nhất 10 ký tự."],
				},
			},
		});

		assert.equal(outcome.failure, "linkUnusable");
	});

	it("lỗi mật khẩu thì ĐƯỢC mượn chữ của backend — nó nói về mật khẩu, không nói về tài khoản", () => {
		const outcome = resetPasswordOutcome({
			ok: false,
			status: 422,
			body: { errors: { password: ["Mật khẩu phải có ít nhất một chữ hoa."] } },
		});

		assert.deepEqual(outcome, {
			failure: "weakPassword",
			passwordMessage: "Mật khẩu phải có ít nhất một chữ hoa.",
		});
	});

	it("lỗi trên `email` cũng là link hỏng — khách không gõ địa chỉ đó, link mang nó", () => {
		const outcome = resetPasswordOutcome({
			ok: false,
			status: 422,
			body: { errors: { email: ["The email field must be a valid email address."] } },
		});

		assert.equal(outcome.failure, "linkUnusable");
	});

	it("422 không nhận ra trường nào vẫn phải cho khách một đường đi tiếp", () => {
		assert.equal(resetPasswordOutcome({ ok: false, status: 422, body: {} }).failure, "linkUnusable");
	});

	it("429 và lỗi mạng không bị đọc nhầm thành link hỏng", () => {
		// Đọc nhầm ở đây tốn của khách một token còn sống: họ đi xin link mới
		// trong khi link đang cầm vẫn dùng được.
		assert.equal(resetPasswordOutcome({ ok: false, status: 429, body: {} }).failure, "rateLimited");
		assert.equal(resetPasswordOutcome({ ok: false, status: 500, body: {} }).failure, "error");
		assert.equal(resetPasswordOutcome({ ok: false, body: null }).failure, "error");
	});
});

// ---------------------------------------------------------------------------

describe("đọc link trong thư", () => {
	function query(params: Record<string, string>) {
		return (key: string) => params[key] ?? null;
	}

	it("lấy đủ token, email và cửa hàng", () => {
		assert.deepEqual(
			parseResetLink(
				query({ token: "abc123", email: "van@example.com", locale: "vi", shop: "ginza" }),
			),
			{ token: "abc123", email: "van@example.com", shop: "ginza" },
		);
	});

	it("thiếu `shop` vẫn hợp lệ — khách cũ không gắn chi nhánh nào", () => {
		assert.deepEqual(parseResetLink(query({ token: "abc123", email: "van@example.com" })), {
			token: "abc123",
			email: "van@example.com",
			shop: null,
		});
	});

	it("thiếu token hoặc email ⇒ null, để trang khỏi bày ra một form chắc chắn 422", () => {
		assert.equal(parseResetLink(query({ email: "van@example.com" })), null);
		assert.equal(parseResetLink(query({ token: "abc123" })), null);
		assert.equal(parseResetLink(query({})), null);
	});

	it("chuỗi rỗng hoặc chỉ khoảng trắng tính là thiếu", () => {
		assert.equal(parseResetLink(query({ token: "   ", email: "van@example.com" })), null);
		assert.equal(parseResetLink(query({ token: "abc", email: "" })), null);
	});
});

describe("đích sau khi đặt lại xong", () => {
	it("về trang đăng nhập ĐÚNG CỬA HÀNG, mang cờ xác nhận", () => {
		// Mất slug ở đây là đá khách ra /select-branch giữa lúc họ đang trong một
		// luồng đã biết rõ mình thuộc cửa hàng nào.
		assert.equal(loginHrefAfterReset("ginza"), "/login/ginza?reset=ok");
	});

	it("không có cửa hàng thì bỏ cờ, không đính vào một URL không chuyển tiếp nó", () => {
		assert.equal(loginHrefAfterReset(null), "/select-branch?next=login");
	});

	it("nút 'xin link mới' mở sẵn màn xin link, không thả khách vào form đăng nhập", () => {
		assert.equal(loginHrefRequestingLink("ginza"), "/login/ginza?forgot=1");
		assert.equal(loginHrefRequestingLink(null), "/select-branch?next=login");
	});
});
