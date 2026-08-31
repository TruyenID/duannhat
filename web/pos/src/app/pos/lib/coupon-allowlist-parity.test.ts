import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { couponMayBeChanged } from "./coupon";
import type { CustomerOrder } from "../types";

/**
 * Allowlist mã giảm giá tồn tại BA bản, ba ngôn ngữ, và phải khớp nhau.
 *
 *   pos-web      `COUPON_MUTABLE_STATUSES`        (TypeScript, file này canh)
 *   Cloud        `OrderCouponService::assertOrderModifiable`   (PHP)
 *   Workstation  `OrderCouponMutable`             (Go)
 *
 * `coupon.ts` tự khai nó là "bản sao có chủ đích" của hai bản kia và cảnh báo
 * đúng nguy cơ: *"Nới thêm một trạng thái ở đây mà backend không nới lại chính
 * là cái bẫy đó."* Nhưng lời cảnh báo là CHỮ — không gì ngăn nó thành sai.
 *
 * ## Vì sao lệch một trạng thái lại tệ theo cả hai chiều
 *
 * **Nới thừa ở pos-web** → ô nhập mã hiện ra ở trạng thái backend từ chối. Thu
 * ngân gõ mã, bấm, ăn 422 giữa lúc khách đứng chờ. Giao diện mời người ta làm
 * một việc chắc chắn thất bại.
 *
 * **Thiếu ở pos-web** → ô nhập mã biến mất ở trạng thái vốn hợp lệ. Khách đưa
 * mã, thu ngân không có chỗ nhập, và `checkout` là cửa MỘT CHIỀU (không có
 * route đưa đơn về `open`) — nên đường thoát duy nhất là huỷ cả đơn.
 *
 * Cả hai đều hỏng ở quầy, trước mặt khách, và không lỗi nào trong số đó làm đỏ
 * một bài test nào khác.
 *
 * ## Vì sao đọc mã nguồn thay vì chép danh sách vào đây
 *
 * Chép danh sách sang bài test chỉ tạo bản sao THỨ TƯ — nó sẽ trôi cùng nhịp
 * với bản nó lẽ ra phải canh. Đọc thẳng ba tệp là cách duy nhất khiến bài này
 * đỏ khi một bên đổi mà hai bên kia không.
 */

const repoRoot = resolve(process.cwd(), "../..");
const read = (p: string) => readFileSync(resolve(repoRoot, p), "utf8");

/** Các hằng `CustomerOrderStatusEnum::Xxx->value` trong thân `assertOrderModifiable`. */
function cloudAllowlist(): string[] {
  const src = read("backend/app/Services/Order/Coupon/OrderCouponService.php");
  const body = src.split("function assertOrderModifiable")[1] ?? "";
  const allowed = body.split("$allowed = [")[1]?.split("];")[0] ?? "";

  return [...allowed.matchAll(/CustomerOrderStatusEnum::(\w+)->value/g)]
    .map((m) => m[1].replace(/([a-z])([A-Z])/g, "$1_$2").toLowerCase())
    .sort();
}

/** Các nhánh `StatusXxx` trong `case` của `OrderCouponMutable`. */
function workstationAllowlist(): string[] {
  const src = read("workstation/internal/service/order_mutation_gate.go");
  const body = src.split("func OrderCouponMutable")[1] ?? "";
  const branch = body.split("case ")[1]?.split(":")[0] ?? "";

  return [...branch.matchAll(/Status(\w+)/g)]
    .map((m) => m[1].replace(/([a-z])([A-Z])/g, "$1_$2").toLowerCase())
    .sort();
}

/** Tập của pos-web, đo qua HÀNH VI chứ không qua việc đọc lại hằng số. */
function posWebAllowlist(candidates: string[]): string[] {
  return candidates
    .filter((s) =>
      couponMayBeChanged({ id: "o1", status: s } as unknown as CustomerOrder),
    )
    .sort();
}

describe("allowlist mã giảm giá — ba bản, ba ngôn ngữ, một sự thật", () => {
  it("đọc được cả hai bản gốc (nếu không, bài dưới xanh vì lý do sai)", () => {
    // Không có bài này thì một lần đổi tên hàm hay bố cục sẽ khiến các regex
    // trên trả mảng rỗng, và `[] === []` làm mọi bài dưới xanh rực trong khi
    // chúng không còn kiểm gì cả — kiểu hỏng tệ nhất của test đọc mã nguồn.
    expect(cloudAllowlist().length).toBeGreaterThan(0);
    expect(workstationAllowlist().length).toBeGreaterThan(0);
  });

  it("Cloud và workstation khớp nhau", () => {
    expect(workstationAllowlist()).toEqual(cloudAllowlist());
  });

  it("pos-web cho phép ĐÚNG những trạng thái Cloud cho phép — không thừa, không thiếu", () => {
    const cloud = cloudAllowlist();
    // Ứng viên phải gồm cả trạng thái NGOÀI allowlist, nếu không phép so chỉ
    // chứng minh "không thiếu" mà bỏ qua "không thừa".
    const candidates = [
      ...cloud,
      "paying",
      "paid",
      "completed",
      "cancelled",
      "voided",
      "refunded",
    ];

    expect(posWebAllowlist(candidates)).toEqual(cloud);
  });

  it("`paying` vắng mặt ở CẢ BA — đơn đã nhận tiền thì không đổi tổng nữa", () => {
    // Ghim riêng vì đây là trạng thái có hậu quả TIỀN BẠC, không chỉ giao diện:
    // đổi tổng sau khi đã thu một phần làm sai lệch khoản đã nhận.
    expect(cloudAllowlist()).not.toContain("paying");
    expect(workstationAllowlist()).not.toContain("paying");
    expect(
      couponMayBeChanged({ id: "o1", status: "paying" } as unknown as CustomerOrder),
    ).toBe(false);
  });

  it("`checkout` CÓ mặt ở cả ba — nếu không, luồng một chạm thành cửa cụt", () => {
    // Luồng một chạm của PR #2471 đẩy đơn sang `checkout` rồi mới mở màn thu
    // tiền, nên ô nhập mã chỉ còn với tới được ở trạng thái này. Bỏ `checkout`
    // khỏi bất kỳ bản nào trong ba bản là khoá luôn đường áp mã sau khi chốt.
    expect(cloudAllowlist()).toContain("checkout");
    expect(workstationAllowlist()).toContain("checkout");
    expect(
      couponMayBeChanged({ id: "o1", status: "checkout" } as unknown as CustomerOrder),
    ).toBe(true);
  });
});
