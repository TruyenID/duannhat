import { describe, it, beforeEach, after } from "node:test";
import assert from "node:assert/strict";

import {
  AUTH_TOKEN_STORAGE_KEY,
  clearAuthToken,
  readAuthToken,
  writeAuthToken,
} from "./auth-token.ts";

/** Storage tối giản — đủ 3 method mà module dùng. */
function fakeStorage(): Storage {
  const map = new Map<string, string>();
  return {
    get length() {
      return map.size;
    },
    clear: () => map.clear(),
    getItem: (k: string) => map.get(k) ?? null,
    key: (i: number) => [...map.keys()][i] ?? null,
    removeItem: (k: string) => void map.delete(k),
    setItem: (k: string, v: string) => void map.set(k, v),
  } as Storage;
}

const originalWindow = (globalThis as { window?: unknown }).window;
let local: Storage;
let session: Storage;

beforeEach(() => {
  local = fakeStorage();
  session = fakeStorage();
  (globalThis as { window?: unknown }).window = {
    localStorage: local,
    sessionStorage: session,
  };
});

after(() => {
  (globalThis as { window?: unknown }).window = originalWindow;
});

describe("writeAuthToken", () => {
  it("ghi nhớ đăng nhập → localStorage", () => {
    writeAuthToken("tok-1", true);
    assert.equal(local.getItem(AUTH_TOKEN_STORAGE_KEY), "tok-1");
    assert.equal(session.getItem(AUTH_TOKEN_STORAGE_KEY), null);
  });

  it("không ghi nhớ → sessionStorage, đóng tab là mất", () => {
    writeAuthToken("tok-1", false);
    assert.equal(session.getItem(AUTH_TOKEN_STORAGE_KEY), "tok-1");
    assert.equal(local.getItem(AUTH_TOKEN_STORAGE_KEY), null);
  });

  // Cái bug mà hàm này tồn tại để chặn: token cũ nằm lại ở localStorage thì
  // phiên vẫn sống sau khi đóng trình duyệt, dù khách vừa bỏ tick.
  it("bỏ tick sau một lần có tick thì KHÔNG để token cũ lại localStorage", () => {
    writeAuthToken("tok-cu", true);
    writeAuthToken("tok-moi", false);
    assert.equal(local.getItem(AUTH_TOKEN_STORAGE_KEY), null);
    assert.equal(session.getItem(AUTH_TOKEN_STORAGE_KEY), "tok-moi");
    assert.equal(readAuthToken(), "tok-moi");
  });

  it("tick lại sau một lần bỏ tick thì không để token cũ lại sessionStorage", () => {
    writeAuthToken("tok-cu", false);
    writeAuthToken("tok-moi", true);
    assert.equal(session.getItem(AUTH_TOKEN_STORAGE_KEY), null);
    assert.equal(local.getItem(AUTH_TOKEN_STORAGE_KEY), "tok-moi");
  });
});

describe("readAuthToken", () => {
  it("đọc được token cất ở một trong hai chỗ", () => {
    assert.equal(readAuthToken(), null);
    session.setItem(AUTH_TOKEN_STORAGE_KEY, "chi-trong-tab");
    assert.equal(readAuthToken(), "chi-trong-tab");
    local.setItem(AUTH_TOKEN_STORAGE_KEY, "ghi-nho");
    assert.equal(readAuthToken(), "ghi-nho");
  });

  it("SSR (không có window) → null, không ném lỗi", () => {
    (globalThis as { window?: unknown }).window = undefined;
    assert.equal(readAuthToken(), null);
  });
});

/**
 * Tiêu chí nghiệm thu của #1781 nói bằng HÀNH VI, không bằng chỗ cất: "bỏ tick
 * → đóng/mở lại tab thì phải đăng nhập lại; tick → vẫn giữ phiên". Các test
 * phía trên khẳng định token nằm ở kho nào, nhưng "nằm ở sessionStorage" chỉ
 * thành "mất khi đóng tab" nhờ một tính chất của TRÌNH DUYỆT mà không test nào
 * ở trên chạm tới — và `readAuthToken` thì đọc CẢ HAI kho, nên đó đúng là chỗ
 * một lần đọc sai thứ tự có thể làm phiên sống dậy.
 *
 * Đóng/mở tab được mô phỏng đúng như trình duyệt làm: `sessionStorage` mới tinh,
 * `localStorage` giữ nguyên.
 */
function reopenTab(): void {
  session = fakeStorage();
  (globalThis as { window?: unknown }).window = {
    localStorage: local,
    sessionStorage: session,
  };
}

describe("ghi nhớ đăng nhập", () => {
  it("BỎ tick → đóng/mở lại tab là mất phiên", () => {
    writeAuthToken("tok", false);
    assert.equal(readAuthToken(), "tok");

    reopenTab();

    assert.equal(readAuthToken(), null);
  });

  it("TICK (mặc định) → đóng/mở lại tab vẫn còn phiên", () => {
    writeAuthToken("tok", true);

    reopenTab();

    assert.equal(readAuthToken(), "tok");
  });

  it("bỏ tick sau một lần có tick → cũng mất, dù localStorage sống qua tab", () => {
    writeAuthToken("tok-cu", true);
    writeAuthToken("tok-moi", false);

    reopenTab();

    assert.equal(readAuthToken(), null);
  });
});

describe("clearAuthToken", () => {
  it("dọn cả hai chỗ", () => {
    local.setItem(AUTH_TOKEN_STORAGE_KEY, "a");
    session.setItem(AUTH_TOKEN_STORAGE_KEY, "b");
    clearAuthToken();
    assert.equal(readAuthToken(), null);
  });
});
