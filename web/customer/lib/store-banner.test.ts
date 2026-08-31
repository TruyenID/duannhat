import assert from "node:assert/strict";
import test from "node:test";
import { hasBanner, resolveBannerSet } from "./store-banner.ts";

/**
 * #936 — chuỗi fallback là phần dễ vỡ nhất: backend trả giá trị THÔ (null khi
 * admin chưa upload), client phải tự lấp để mọi viewport đều có ảnh.
 *
 * #1198 — bộ assertion này ĐÃ TỪNG tồn tại ở `components/store-banner.test.ts`
 * và chưa từng chạy: nằm ngoài glob `lib/**` của `npm test`, VÀ viết cho vitest
 * trong khi cả repo chạy `node:test`. Chuyển về đây, đúng runner, để nó thực sự
 * gác.
 */

test("dùng đúng ảnh của từng breakpoint khi đủ 3 ảnh", () => {
  assert.deepEqual(
    resolveBannerSet({
      img_branches: "legacy.jpg",
      banner_desktop: "d.jpg",
      banner_tablet: "t.jpg",
      banner_mobile: "m.jpg",
    }),
    { desktop: "d.jpg", tablet: "t.jpg", mobile: "m.jpg" },
  );
});

test("lấp từ ảnh lớn xuống khi thiếu breakpoint nhỏ", () => {
  assert.deepEqual(
    resolveBannerSet({
      img_branches: null,
      banner_desktop: "d.jpg",
      banner_tablet: null,
      banner_mobile: null,
    }),
    { desktop: "d.jpg", tablet: "d.jpg", mobile: "d.jpg" },
  );
});

test("chỉ có banner mobile thì desktop/tablet vẫn phải có ảnh (rơi về legacy)", () => {
  assert.deepEqual(
    resolveBannerSet({
      img_branches: "legacy.jpg",
      banner_desktop: null,
      banner_tablet: null,
      banner_mobile: "m.jpg",
    }),
    { desktop: "legacy.jpg", tablet: "legacy.jpg", mobile: "m.jpg" },
  );
});

test("shop cũ chưa upload gì vẫn giữ nguyên banner legacy ở cả 3 breakpoint", () => {
  assert.deepEqual(resolveBannerSet({ img_branches: "legacy.jpg" }), {
    desktop: "legacy.jpg",
    tablet: "legacy.jpg",
    mobile: "legacy.jpg",
  });
});

test("không có ảnh nào → null hết (caller bỏ hẳn block banner)", () => {
  assert.deepEqual(resolveBannerSet({}), {
    desktop: null,
    tablet: null,
    mobile: null,
  });
});

test("hasBanner: true khi có bất kỳ ảnh nào, kể cả chỉ mỗi banner desktop", () => {
  assert.equal(hasBanner({ banner_desktop: "d.jpg" }), true);
  assert.equal(hasBanner({ img_branches: "legacy.jpg" }), true);
});

test("hasBanner: false khi rỗng hoàn toàn", () => {
  assert.equal(hasBanner({}), false);
  assert.equal(hasBanner({ img_branches: null, banner_mobile: null }), false);
});
