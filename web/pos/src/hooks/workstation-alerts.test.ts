import { beforeEach, describe, expect, it } from "vitest";

import { workstationAlerts, type WorkstationAlert } from "./workstation-alerts";

const alert = (over: Partial<WorkstationAlert> = {}): WorkstationAlert => ({
  kind: "no_printer",
  subject: "receipt_printer",
  severity: "critical",
  title: "Chưa cấu hình máy in",
  ...over,
});

beforeEach(() => workstationAlerts.clear());

describe("#1806 S2 — trạng thái alert máy trạm ở pos-web", () => {
  it("snapshot THAY toàn bộ, không gộp", () => {
    // Đây là bất biến quan trọng nhất. Một sự cố đã hết trong lúc socket chết
    // sẽ KHÔNG có `alert.resolved` nào tới — gộp thì banner đỏ ở lại vĩnh viễn
    // cho một vấn đề đã được sửa, và người dùng học được rằng nó không đáng tin.
    workstationAlerts.raise(alert({ subject: "kitchen_printer" }));
    workstationAlerts.raise(alert({ subject: "receipt_printer" }));

    let seen: WorkstationAlert[] = [];
    workstationAlerts.subscribe((a) => (seen = a));

    workstationAlerts.replace([alert({ subject: "receipt_printer" })]);

    expect(seen).toHaveLength(1);
    expect(seen[0].subject).toBe("receipt_printer");
  });

  it("gộp theo (kind, subject) — cùng khoá với phía Go", () => {
    let seen: WorkstationAlert[] = [];
    workstationAlerts.subscribe((a) => (seen = a));

    workstationAlerts.raise(alert({ count: 1 }));
    workstationAlerts.raise(alert({ count: 9 }));

    // Cùng (kind, subject) ⇒ MỘT dòng, và là bản mới nhất. Khoá lệch với phía
    // Go sẽ làm màn hình đếm khác panel máy trạm cho cùng một sự cố.
    expect(seen).toHaveLength(1);
    expect(seen[0].count).toBe(9);

    workstationAlerts.raise(alert({ subject: "kitchen_printer" }));
    expect(seen).toHaveLength(2);
  });

  it("resolve gỡ đúng một dòng", () => {
    let seen: WorkstationAlert[] = [];
    workstationAlerts.subscribe((a) => (seen = a));

    workstationAlerts.raise(alert({ subject: "a" }));
    workstationAlerts.raise(alert({ subject: "b" }));
    workstationAlerts.resolve("no_printer", "a");

    expect(seen.map((x) => x.subject)).toEqual(["b"]);
  });

  it("mất kết nối thì XOÁ HẾT — không biết gì khác không có sự cố", () => {
    let seen: WorkstationAlert[] = [];
    workstationAlerts.subscribe((a) => (seen = a));

    workstationAlerts.raise(alert());
    workstationAlerts.clear();

    expect(seen).toHaveLength(0);
  });

  it("subscribe nhận ngay trạng thái hiện tại", () => {
    workstationAlerts.raise(alert());

    let seen: WorkstationAlert[] | null = null;
    workstationAlerts.subscribe((a) => (seen = a));

    expect(seen).toHaveLength(1);
  });
});
