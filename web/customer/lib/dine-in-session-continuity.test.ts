import { test } from "node:test";
import assert from "node:assert/strict";

import { sessionContinuity } from "./dine-in-session-continuity.ts";

test("chưa từng join trên máy này → fresh", () => {
	assert.equal(sessionContinuity(null, "s-1"), "fresh");
	assert.equal(sessionContinuity(undefined, "s-1"), "fresh");
	assert.equal(sessionContinuity("", "s-1"), "fresh");
});

test("cùng một phiên → same", () => {
	assert.equal(sessionContinuity("s-1", "s-1"), "same");
});

/** Bài chính của #2634: nhân viên trả bàn về free ⇒ phiên cũ chết, server cấp phiên mới. */
test("phiên đã bị thay → replaced", () => {
	assert.equal(sessionContinuity("s-old", "s-new"), "replaced");
});

/**
 * Response thiếu `session` KHÔNG được đọc thành "đã bị thay".
 *
 * Đoán ở đây sẽ đá khách ra khỏi màn hình chỉ vì một field vắng mặt — và backend
 * mới là nơi giữ sự thật, lượt gọi món kế tiếp vẫn đi qua nó.
 */
test("server không trả phiên → giữ nguyên luồng, KHÔNG đoán là đã thay", () => {
	assert.equal(sessionContinuity("s-old", null), "same");
	assert.equal(sessionContinuity("s-old", undefined), "same");
	assert.equal(sessionContinuity("s-old", ""), "same");
});

/**
 * Rác localStorage từ lượt khách trước + bàn đã dọn: server cấp phiên mới, nên
 * đây vẫn là `replaced` — và đó ĐÚNG. Màn "phiên đã kết thúc" có nút quét lại,
 * nên khách mới mất ba giây; còn im lặng thì họ mất giỏ hàng.
 *
 * Đây cũng là chỗ phân biệt với cách gate theo `tables.status`, thứ đã gây bug
 * khẩn 2026-06-12 khi đá oan người quét QR vào bàn free.
 */
test("rác localStorage của lượt khách trước cũng là replaced", () => {
	assert.equal(sessionContinuity("s-hôm-qua", "s-hôm-nay"), "replaced");
});
