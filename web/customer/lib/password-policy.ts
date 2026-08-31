// #1780 — chính sách mật khẩu của tài khoản khách.
//
// Đây là BẢN SAO CHÍNH XÁC của `App\Rules\StrongCustomerPassword` ở backend.
// Hai bên phải khớp từng điều kiện một: FE nghiêm hơn BE thì khách gõ đủ mà nút
// vẫn xám; BE nghiêm hơn FE thì checklist xanh hết rồi submit ăn 422 — cả hai
// đều đọc ra như "trang đăng ký hỏng", không ai nghĩ tới hai bộ luật lệch nhau.
//
// Sửa ở đây thì phải sửa cả `backend/app/Rules/StrongCustomerPassword.php` và
// 4 dòng thông báo trong `backend/lang/{en,ja,vi}/validation.php`.

export const PASSWORD_MIN_LENGTH = 10;

/** Một điều kiện một dòng, đúng thứ tự hiển thị dưới ô mật khẩu. */
export interface PasswordPolicyState {
	minLength: boolean;
	uppercase: boolean;
	lettersAndNumbers: boolean;
	symbol: boolean;
}

/** Khoá i18n của từng dòng, dùng chung cho checklist và thông báo lỗi. */
export const PASSWORD_RULE_KEYS = [
	"minLength",
	"uppercase",
	"lettersAndNumbers",
	"symbol",
] as const satisfies readonly (keyof PasswordPolicyState)[];

export function checkPasswordPolicy(value: string): PasswordPolicyState {
	return {
		// Đếm CODE POINT chứ không phải `value.length` (đơn vị UTF-16): mật khẩu
		// có emoji đếm bằng `.length` sẽ dài gấp đôi số ký tự thật, tức FE cho
		// qua một mật khẩu mà backend (mb_strlen) từ chối.
		minLength: [...value].length >= PASSWORD_MIN_LENGTH,
		uppercase: /\p{Lu}/u.test(value),
		// "Có chữ và số" là MỘT điều kiện gồm hai vế, đúng như mockup hiển thị.
		lettersAndNumbers: /\p{L}/u.test(value) && /\p{N}/u.test(value),
		// Ký tự đặc biệt = mọi thứ không phải chữ, không phải số. Khoảng trắng
		// tính là đặc biệt: nó hợp lệ trong mật khẩu.
		symbol: /[^\p{L}\p{N}]/u.test(value),
	};
}

export function passwordMeetsPolicy(value: string): boolean {
	return Object.values(checkPasswordPolicy(value)).every(Boolean);
}
