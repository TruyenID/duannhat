import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { readdirSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import {
  LOCALE_COOKIE,
  localeCookieHeader,
  localeSwitchTarget,
  readLocaleCookie,
} from "./locale-switch-target.ts";

describe("localeSwitchTarget — #1773", () => {
  it("giữ nguyên đường dẫn khi không có query", () => {
    assert.equal(localeSwitchTarget("/takeaway/ningyocho", "", ""), "/takeaway/ningyocho");
    assert.equal(localeSwitchTarget("/takeaway/ningyocho"), "/takeaway/ningyocho");
  });

  it("giữ cờ đã-thanh-toán ở màn báo thành công", () => {
    // Đây là ca đắt nhất: mất `stripe_return=1` thì khách vừa trả tiền xong bị
    // báo là "đang chờ thanh toán" và mất mã đơn cần mang ra quầy.
    const search = "?id=019f&code=ORD-2026-3203&type=takeaway&shop=ningyocho&stripe_return=1";

    assert.equal(
      localeSwitchTarget("/order-success", search, ""),
      `/order-success${search}`,
    );
  });

  it("giữ tab đang xem ở lịch sử đơn", () => {
    // Link "Chưa thanh toán" trên header trỏ thẳng vào URL này.
    assert.equal(localeSwitchTarget("/orders", "?tab=pending"), "/orders?tab=pending");
  });

  it("giữ cờ một-lần repick của màn checkout", () => {
    assert.equal(localeSwitchTarget("/checkout", "?repick=pickup"), "/checkout?repick=pickup");
  });

  it("chấp nhận search dù có hay không dấu ?", () => {
    assert.equal(localeSwitchTarget("/orders", "tab=pending"), "/orders?tab=pending");
    assert.equal(localeSwitchTarget("/orders", "?tab=pending"), "/orders?tab=pending");
  });

  it("không mọc dấu ? hay # thừa khi phần đó rỗng", () => {
    // `window.location.search` là "" khi không có tham số, nhưng một caller
    // truyền "?" trơ cũng không được làm URL dài thêm sau mỗi lần đổi ngôn ngữ.
    assert.equal(localeSwitchTarget("/orders", "?"), "/orders");
    assert.equal(localeSwitchTarget("/orders", "", "#"), "/orders");
    assert.equal(localeSwitchTarget("/orders", null, undefined), "/orders");
  });

  it("giữ cả hash", () => {
    assert.equal(
      localeSwitchTarget("/takeaway/ningyocho", "?q=nem", "#cat-pho"),
      "/takeaway/ningyocho?q=nem#cat-pho",
    );
    assert.equal(localeSwitchTarget("/takeaway/ningyocho", "", "cat-pho"), "/takeaway/ningyocho#cat-pho");
  });

  it("không nuốt giá trị có ký tự đã encode", () => {
    const search = "?note=Kh%C3%B4ng%20h%C3%A0nh&tab=pending";

    assert.equal(localeSwitchTarget("/orders", search), `/orders${search}`);
  });
});

describe("cookie ngôn ngữ — #1777", () => {
  it("ghi và đọc lại cùng một giá trị", () => {
    // Bên ghi (switcher) và bên đọc (LocaleGuard) phải khớp nhau. Lệch nhau
    // không ra "quên nhớ ngôn ngữ" — nó ra VÒNG LẶP: guard thấy cookie khác
    // locale trên URL và replace ngược lại, cắt query thêm một lần nữa.
    const header = localeCookieHeader("ja");

    assert.match(header, new RegExp(`^${LOCALE_COOKIE}=ja;`));
    assert.equal(readLocaleCookie(header.split(";")[0]), "ja");
  });

  it("đọc được khi cookie nằm giữa các cookie khác", () => {
    assert.equal(readLocaleCookie(`sid=abc; ${LOCALE_COOKIE}=vi; theme=dark`), "vi");
  });

  it("trả null khi chưa có cookie", () => {
    assert.equal(readLocaleCookie(""), null);
    assert.equal(readLocaleCookie(null), null);
    assert.equal(readLocaleCookie("sid=abc; theme=dark"), null);
    assert.equal(readLocaleCookie(`${LOCALE_COOKIE}=`), null);
  });

  it("cookie sống đủ lâu và giới hạn trong site", () => {
    const header = localeCookieHeader("en");

    assert.match(header, /path=\//);
    assert.match(header, /max-age=31536000/);
    assert.match(header, /SameSite=Lax/);
  });
});

/**
 * #1777 — bản vá của #1773 chỉ đi vào MỘT trong ba chỗ đổi locale, và không có
 * gì báo cho ai biết. Hai chỗ còn lại (`Header.tsx` → MobileNavMenu,
 * `locale-guard.tsx`) vẫn truyền `usePathname()` trần vào `router.replace`, nên
 * trên điện thoại — nơi hamburger là cách đổi ngôn ngữ DUY NHẤT ở
 * `/order-success` — lỗi còn nguyên và desktop lại không tái hiện được.
 *
 * Nên test này không kiểm một hàm, nó kiểm rằng KHÔNG CÒN bản sao thứ tư.
 */
const SOURCE_ROOT = fileURLToPath(new URL("..", import.meta.url));
const SCANNED_DIRS = ["app", "components", "context", "hooks", "lib"];
const SOURCE_EXT = /\.tsx?$/;

function sourceFiles(): string[] {
  const files: string[] = [];

  for (const dir of SCANNED_DIRS) {
    for (const entry of readdirSync(`${SOURCE_ROOT}${dir}`, { recursive: true })) {
      const rel = `${dir}/${String(entry)}`;
      if (!SOURCE_EXT.test(rel)) continue;
      if (rel.endsWith(".test.ts") || rel.endsWith(".test.tsx")) continue;
      files.push(rel);
    }
  }

  return files;
}

describe("mọi chỗ đổi locale phải đi qua useLocaleSwitch — #1777", () => {
  it("không còn ai truyền pathname trần vào router.replace kèm locale", () => {
    // Đây chính là chữ ký của lỗi: `usePathname()` của next-intl KHÔNG kèm
    // query string, nên gọi kiểu này là vứt sạch tham số của trang đang đứng.
    const offender = /\.(replace|push)\(\s*pathname\s*,\s*\{\s*locale/;
    const offenders = sourceFiles().filter((file) =>
      offender.test(readFileSync(`${SOURCE_ROOT}${file}`, "utf8")),
    );

    assert.deepEqual(
      offenders,
      [],
      `phải dùng useLocaleSwitch (giữ query + ghi cookie) thay vì tự điều hướng: ${offenders.join(", ")}`,
    );
  });

  it("chỉ MỘT nơi biết tên cookie ngôn ngữ", () => {
    const writers = sourceFiles().filter((file) =>
      readFileSync(`${SOURCE_ROOT}${file}`, "utf8").includes("NEXT_LOCALE"),
    );

    assert.deepEqual(writers, ["lib/locale-switch-target.ts"]);
  });

  it("quét thật sự có đọc file (rào rỗng thì test này vô nghĩa)", () => {
    const files = sourceFiles();

    assert.ok(files.length > 50, `chỉ quét được ${files.length} file`);
    assert.ok(files.includes("components/Header.tsx"));
    assert.ok(files.includes("components/locale-guard.tsx"));
    assert.ok(files.includes("components/language-switcher.tsx"));
    assert.ok(files.includes("hooks/use-locale-switch.ts"));
  });
});
