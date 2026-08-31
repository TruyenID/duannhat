/**
 * #1806 S3 — gom cảnh báo máy trạm theo quán.
 *
 * Ghim ba thứ **sai một cách im lặng**: cả ba vẫn cho ra một màn hình trông
 * bình thường, và cả ba đều làm người trực HQ đi cứu nhầm quán.
 *
 * 1. **Cộng `count` qua nhiều ngày.** Cloud nhận ẢNH CHỤP của một bộ đếm đang
 *    chạy (ruling S3: không có lịch sử ở Cloud), nên hai ảnh chụp của cùng một
 *    sự cố là 200 rồi 340, không phải 200 + 340 = 540. Cộng vào thì con số
 *    phình theo số ngày và một sự cố cũ luôn trông nặng hơn sự cố mới.
 *
 * 2. **Khoá gộp lệch khỏi `(kind, subject)`.** Đó là khoá phía Go
 *    (`alert_kinds.go`) và phía pos-web/KDS. Lệch khoá thì màn HQ đếm khác
 *    panel máy trạm cho **cùng một sự cố**, và không ai biết bên nào đúng.
 *
 * 3. **Xếp theo tên quán thay vì theo mức.** Màn này tồn tại để trả lời "cứu
 *    quán nào trước". Xếp theo tên là bắt người đọc tự dò — và họ sẽ dò từ trên
 *    xuống, tức là bắt đầu từ quán có tên vần A.
 *
 * @vitest-environment node
 */

import { describe, expect, it } from "vitest";

import {
  groupWorkstationAlerts,
  parseWorkstationAlert,
  WORKSTATION_ALERT_TYPE,
  type WorkstationAlertRow,
} from "@/services/hq-workstation-alert-service";
import type { AdminNotificationListRow } from "@/services/notification-service";

function notification(
  overrides: Partial<AdminNotificationListRow> & { params?: Record<string, unknown> } = {}
): AdminNotificationListRow {
  return {
    id: "n1",
    type: WORKSTATION_ALERT_TYPE,
    template_key: "workstation.alert",
    params: {},
    priority: "urgent",
    actor: null,
    subject: { type: "Branch", id: "b1", display_name: "Shibuya" },
    organization: null,
    aggregation_key: null,
    created_at: "2026-08-05T09:00:00+00:00",
    recipients_summary: { total: 1, seen: 0, read: 0, dismissed: 0 },
    ...overrides,
  } as AdminNotificationListRow;
}

function row(overrides: Partial<WorkstationAlertRow> = {}): WorkstationAlertRow {
  return {
    id: "n1",
    branchId: "b1",
    branchName: "Shibuya",
    kind: "no_printer",
    subject: "kitchen-1",
    severity: "critical",
    title: "Kitchen printer offline",
    count: 12,
    firstSeenAt: "2026-08-05T08:00:00+00:00",
    reportedAt: "2026-08-05T09:00:00+00:00",
    ...overrides,
  };
}

describe("parseWorkstationAlert", () => {
  it("bóc params thành dòng có đủ tên quán, count và first_seen", () => {
    const parsed = parseWorkstationAlert(
      notification({
        params: {
          branch_name: "Shibuya",
          kind: "no_printer",
          subject: "kitchen-1",
          severity: "critical",
          title: "Kitchen printer offline",
          count: 212,
          first_seen_at: "2026-08-05T08:00:00+00:00",
        },
      })
    );

    // Ba trường bắt buộc của ruling — thiếu bất kỳ cái nào thì dòng cảnh báo
    // không hành động được.
    expect(parsed?.branchName).toBe("Shibuya");
    expect(parsed?.count).toBe(212);
    expect(parsed?.firstSeenAt).toBe("2026-08-05T08:00:00+00:00");
  });

  it("bỏ qua thông báo không phải workstation.alert", () => {
    expect(parseWorkstationAlert(notification({ type: "order.created" }))).toBeNull();
  });

  it("mức lạ rơi về info thay vì bị nuốt mất dòng", () => {
    const parsed = parseWorkstationAlert(notification({ params: { severity: "meltdown" } }));
    expect(parsed).not.toBeNull();
    expect(parsed?.severity).toBe("info");
  });

  it("gom theo id chi nhánh, không theo tên — tên đổi được và trùng được", () => {
    const a = parseWorkstationAlert(
      notification({ subject: { type: "Branch", id: "b1", display_name: "X" } })
    );
    const b = parseWorkstationAlert(
      notification({
        id: "n2",
        subject: { type: "Branch", id: "b2", display_name: "X" },
        params: { branch_name: "X" },
      })
    );
    expect(a?.branchId).not.toBe(b?.branchId);
  });
});

describe("groupWorkstationAlerts", () => {
  it("count LẤY LỚN NHẤT qua các ngày, không cộng dồn", () => {
    const groups = groupWorkstationAlerts([
      row({ id: "n1", count: 200, reportedAt: "2026-08-04T09:00:00+00:00" }),
      row({ id: "n2", count: 340, reportedAt: "2026-08-05T09:00:00+00:00" }),
    ]);

    expect(groups).toHaveLength(1);
    expect(groups[0].incidents).toHaveLength(1);
    expect(groups[0].incidents[0].count).toBe(340);
    // Không phải 540 — xem docblock đầu file.
    expect(groups[0].incidents[0].count).not.toBe(540);
    expect(groups[0].incidents[0].days).toBe(2);
  });

  it("first_seen lấy SỚM NHẤT, nếu không thì 'lần đầu' nói dối", () => {
    const groups = groupWorkstationAlerts([
      row({ id: "n2", firstSeenAt: "2026-08-05T08:00:00+00:00" }),
      row({ id: "n1", firstSeenAt: "2026-08-03T22:15:00+00:00" }),
    ]);
    expect(groups[0].incidents[0].firstSeenAt).toBe("2026-08-03T22:15:00+00:00");
  });

  it("gộp theo (kind, subject) — hai máy in khác nhau là HAI sự cố", () => {
    const groups = groupWorkstationAlerts([
      row({ id: "n1", subject: "kitchen-1" }),
      row({ id: "n2", subject: "kitchen-2" }),
      row({ id: "n3", kind: "cash_retained", subject: "kitchen-1", severity: "warning" }),
    ]);

    expect(groups[0].incidents).toHaveLength(3);
    expect(groups[0].severityCounts).toEqual({ critical: 2, warning: 1, info: 0 });
  });

  it("mức leo thang lấy theo bản ghi MỚI NHẤT, không phải bản ghi đầu", () => {
    const groups = groupWorkstationAlerts([
      row({ id: "old", severity: "warning", reportedAt: "2026-08-04T09:00:00+00:00" }),
      row({ id: "new", severity: "critical", reportedAt: "2026-08-05T09:00:00+00:00" }),
    ]);
    expect(groups[0].incidents[0].severity).toBe("critical");
    expect(groups[0].incidents[0].latestNotificationId).toBe("new");
  });

  it("quán nặng nhất đứng trước, KHÔNG xếp theo tên", () => {
    const groups = groupWorkstationAlerts([
      row({ id: "n1", branchId: "b-a", branchName: "Akasaka", severity: "info" }),
      row({ id: "n2", branchId: "b-z", branchName: "Zushi", severity: "critical" }),
    ]);

    expect(groups.map((g) => g.branchName)).toEqual(["Zushi", "Akasaka"]);
    expect(groups[0].worstSeverity).toBe("critical");
  });

  it("trong một quán, dòng nặng nhất lên trên, hoà thì nhiều lần hơn lên trên", () => {
    const groups = groupWorkstationAlerts([
      row({ id: "n1", kind: "a_info", subject: "s1", severity: "info", count: 900 }),
      row({ id: "n2", kind: "b_crit", subject: "s2", severity: "critical", count: 2 }),
      row({ id: "n3", kind: "c_crit", subject: "s3", severity: "critical", count: 50 }),
    ]);

    expect(groups[0].incidents.map((i) => i.kind)).toEqual(["c_crit", "b_crit", "a_info"]);
  });

  it("không có alert nào thì không có nhóm nào", () => {
    expect(groupWorkstationAlerts([])).toEqual([]);
  });
});
