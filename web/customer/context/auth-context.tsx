"use client";

import React, { createContext, useContext, useState, useEffect, useCallback } from "react";
import { apiFetch } from "@/lib/api";
import { clearAuthToken, readAuthToken, writeAuthToken } from "@/lib/auth-token";
import { FEATURES } from "@/lib/feature-flags";
import { loginPayload } from "@/lib/login-identifier";
import { loadGuestOrders, clearGuestOrders } from "@/lib/guest-orders";

const DEVICE_NAME = "customer-web";

interface User {
  id: string;
  name: string;
  email: string;
  email_verified?: boolean;
}

/** Kết quả đăng ký (#1680) — chưa có phiên, chỉ có địa chỉ đang chờ xác nhận. */
export interface RegisterResult {
  email: string;
}

/**
 * Dữ liệu đăng ký (#1780).
 *
 * Là OBJECT chứ không phải 6 tham số vị trí như trước: form đăng ký giờ có 9
 * trường, và `register(a, b, c, d, e, f, g, h, i)` là chỗ hoán đổi nhầm hai
 * chuỗi mà TypeScript không bắt được (`firstName`/`lastName` cùng kiểu, và cả
 * hai đều "hợp lệ" với backend — khách chỉ thấy tên mình bị đảo).
 */
export interface RegisterInput {
  firstName: string;
  lastName: string;
  email: string;
  /** Dạng E.164 (`+84912345678`) — form ghép mã vùng của chi nhánh vào. */
  phone: string;
  password: string;
  passwordConfirmation: string;
  /** `YYYY-MM-DD`, hoặc rỗng nếu khách không khai. */
  birthday: string;
  /** `male` | `female` | `other`, hoặc rỗng nếu khách không khai. */
  gender: string;
  /** Khách có tham gia chương trình thành viên hay không. */
  loyaltyOptedIn: boolean;
  /** Slug cửa hàng, lấy từ segment `[shop]` của URL đăng ký. */
  branchSlug: string;
}

interface AuthContextValue {
  isLoggedIn: boolean;
  user: User | null;
  token: string | null;
  isLoading: boolean;
  /**
   * `identifier` (#1782) — EMAIL **hoặc** SỐ ĐIỆN THOẠI. Backend tự phân biệt
   * và từ chối khi một số ứng với nhiều tài khoản.
   *
   * `remember` (#1781) — mặc định `true` (hành vi cũ: phiên sống qua lần đóng
   * trình duyệt). `false` khi khách bỏ tick "Ghi nhớ đăng nhập": token chỉ nằm
   * trong `sessionStorage`, đóng tab là phải đăng nhập lại.
   */
  login: (identifier: string, password: string, remember?: boolean) => Promise<void>;
  register: (input: RegisterInput) => Promise<RegisterResult>;
  resendVerification: (email: string) => Promise<void>;
  verifyEmailCode: (email: string, code: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

interface AuthResponse {
  data: { user: User; token: string };
}

interface RegisterResponse {
  data: { email: string; verification_required: boolean };
}

interface UserResponse {
  data: User;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(null);
  // sessionChecked: false khi đang verify token khôi phục; true khi đã biết
  // session hợp lệ hay không. Derive isLoading từ đây thay vì setLoading thủ công.
  const [sessionChecked, setSessionChecked] = useState(false);

  // Restore session from localStorage on mount.
  // FEATURES.auth off → app chạy guest-only: bỏ qua hoàn toàn việc verify
  // token (không gọi /auth/user) và coi session đã check xong ngay lập tức,
  // để `isLoading` không treo các consumer chờ auth (vd cart-context).
  useEffect(() => {
    if (!FEATURES.auth) {
      Promise.resolve().then(() => setSessionChecked(true));
      return;
    }
    const stored = readAuthToken();
    if (!stored) {
      // Defer tới microtask để không setState đồng bộ trong effect body.
      Promise.resolve().then(() => setSessionChecked(true));
      return;
    }
    const ac = new AbortController();
    apiFetch<UserResponse>("/api/v1/customer/auth/user", {
      silent401: true,
      signal: ac.signal,
    })
      .then(({ data }) => {
        if (ac.signal.aborted) return;
        setUser(data);
        setToken(stored);
      })
      .catch(() => {
        if (ac.signal.aborted) return;
        clearAuthToken();
      })
      .finally(() => {
        if (!ac.signal.aborted) setSessionChecked(true);
      });
    return () => ac.abort();
  }, []);

  const isLoading = !sessionChecked;

  function persist(u: User, t: string, remember: boolean) {
    writeAuthToken(t, remember);
    setUser(u);
    setToken(t);
  }

  // Guard chung cho 3 mutation auth. Khi FEATURES.auth off các trang gọi
  // chúng (/login, /register, /account) đã bị middleware chặn — throw ở đây
  // chỉ là backstop để không có đường nào lén tạo session.
  function assertAuthEnabled(): void {
    if (!FEATURES.auth) throw new Error("Auth is disabled (FEATURES.auth === false)");
  }

  async function login(identifier: string, password: string, remember = true): Promise<void> {
    assertAuthEnabled();
    // #1782 — gửi `identifier` (email HOẶC số điện thoại). Trường `email` cũ
    // vẫn được backend chấp nhận, nhưng nhét một số điện thoại vào một trường
    // tên là `email` là để lại một lời nói dối trong mọi log đọc sau này.
    const { data } = await apiFetch<AuthResponse>("/api/v1/customer/auth/login", {
      method: "POST",
      body: JSON.stringify(loginPayload(identifier, password, DEVICE_NAME)),
    });
    // `remember` KHÔNG đi lên backend: token Sanctum không có TTL và endpoint
    // login không nhận cờ nào như vậy. Nó chỉ chọn chỗ cất token phía trình
    // duyệt — xem `lib/auth-token.ts` (#1781).
    persist(data.user, data.token, remember);
  }

  /**
   * `branchSlug` là bắt buộc (#1505) — backend từ chối đăng ký không kèm cửa
   * hàng, vì đó chính là thứ quyết định `customers.branch_id`. Slug đến từ
   * segment `[shop]` của URL đăng ký, không phải từ chi nhánh đang chọn trong
   * localStorage: một tab khác đổi chi nhánh là giá trị kia đã khác.
   *
   * KHÔNG tạo phiên (#1680). Backend trả 202 và không phát token: tài khoản
   * chỉ dùng được sau khi khách bấm link trong thư. Trả lại địa chỉ email để
   * trang đăng ký hiện màn "hãy kiểm tra hộp thư" — và để nút gửi lại không
   * phải bắt khách gõ lại địa chỉ vừa nhập.
   */
  async function register(input: RegisterInput): Promise<RegisterResult> {
    assertAuthEnabled();
    const { data } = await apiFetch<RegisterResponse>("/api/v1/customer/auth/register", {
      method: "POST",
      body: JSON.stringify({
        first_name: input.firstName,
        last_name: input.lastName || undefined,
        email: input.email,
        phone: input.phone,
        password: input.password,
        password_confirmation: input.passwordConfirmation,
        // Ô trống gửi `""` sẽ làm rule `date`/`enum` của backend trượt; gửi
        // `undefined` (biến mất khỏi JSON) mới là "không khai". Backend cũng
        // normalise `""` → null, nhưng dựa vào đó là để hai lớp cùng giữ một
        // luật mà chỉ một lớp nói ra.
        birthday: input.birthday || undefined,
        gender: input.gender || undefined,
        loyalty_opted_in: input.loyaltyOptedIn,
        device_name: DEVICE_NAME,
        branch_slug: input.branchSlug,
      }),
    });
    return { email: data.email };
  }

  /**
   * Gửi lại thư xác nhận (#1680). Không cần đăng nhập — đúng những người cần
   * nó là những người chưa đăng nhập được.
   *
   * Backend luôn trả cùng một câu dù địa chỉ có tồn tại hay không, nên phía
   * này cũng không được suy ra gì từ kết quả: hiện "đã gửi" là hết.
   */
  async function resendVerification(email: string): Promise<void> {
    assertAuthEnabled();
    await apiFetch("/api/v1/customer/auth/email/resend", {
      method: "POST",
      body: JSON.stringify({ email }),
    });
  }

  /**
   * Xác nhận email bằng mã 6 chữ số gõ tay.
   *
   * Không phát token: cổng vẫn là `login()`, đúng như #1680 đặt ra. Xác nhận
   * xong thì khách đăng nhập bằng chính mật khẩu vừa đặt — nên hàm này không
   * trả về gì, và trang gọi nó chỉ cần biết "xong rồi" (resolve) hay "chưa"
   * (throw `ApiError` mang `reason`).
   *
   * Mã đúng nhưng email đã xác nhận từ trước cũng RESOLVE, không throw: với
   * khách thì kết cục giống hệt nhau — email của họ đã dùng được.
   */
  async function verifyEmailCode(email: string, code: string): Promise<void> {
    assertAuthEnabled();
    await apiFetch("/api/v1/customer/auth/email/verify-code", {
      method: "POST",
      body: JSON.stringify({ email, code }),
    });
  }

  const refreshUser = useCallback(async (): Promise<void> => {
    try {
      const { data } = await apiFetch<UserResponse>("/api/v1/customer/auth/user", { silent401: true });
      setUser(data);
    } catch {
      // silent — user state unchanged
    }
  }, []);

  const logout = useCallback(async (): Promise<void> => {
    if (token) {
      await apiFetch("/api/v1/customer/auth/logout", { method: "POST", silent401: true }).catch(() => {});
    }
    clearAuthToken();
    setUser(null);
    setToken(null);
  }, [token]);

  // Post-login claim: gắn các order guest đã đặt (lưu ở localStorage qua
  // `saveGuestOrder`) vào account vừa login. BE endpoint MUST verify
  // ownership trước khi claim (vd: yêu cầu match phone hoặc created_at
  // trong vòng N giờ) — FE chỉ gửi danh sách id.
  //
  // Vòng đời:
  //   - Bắn khi `user` chuyển từ null → có giá trị (login / register /
  //     hydrate refresh).
  //   - Nếu BE chưa implement endpoint (404 / 5xx) → giữ guest orders ở
  //     localStorage, user vẫn xem được qua /orders cho tới khi expire.
  //   - Nếu thành công → clear localStorage để không hiển thị trùng.
  useEffect(() => {
    if (!user) return;
    const guestOrders = loadGuestOrders();
    if (guestOrders.length === 0) return;

    apiFetch("/api/v1/customer/me/orders/claim", {
      method: "POST",
      body: JSON.stringify({ order_ids: guestOrders.map((o) => o.id) }),
      silent401: true,
    })
      .then(() => {
        clearGuestOrders();
      })
      .catch(() => {
        // BE chưa implement / lỗi mạng → fail silently. Guest orders vẫn
        // còn ở localStorage, user xem qua /orders như cũ.
      });
  }, [user]);

  return (
    <AuthContext.Provider value={{ isLoggedIn: !!user, user, token, isLoading, login, register, resendVerification, verifyEmailCode, logout, refreshUser }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside AuthProvider");
  return ctx;
}
