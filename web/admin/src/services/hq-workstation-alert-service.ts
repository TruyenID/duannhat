/**
 * #1806 S3 (phần còn lại) — cảnh báo máy trạm, gom theo quán cho HQ.
 *
 * ## KHÔNG có endpoint riêng, và đó là điểm của epic
 *
 * Ruling #1806 S3 đã bác bảng `workstation_alerts` ở Cloud: alert đi vào **nền
 * tảng thông báo** với `type: 'workstation.alert'`. Dựng một service gọi một
 * endpoint mới ở đây sẽ tái lập đúng thứ ruling vừa bác — **bề mặt cảnh báo thứ
 * hai** — chỉ là ở tầng frontend. Nên file này KHÔNG có `apiFetch` của riêng nó
 * cho việc đọc: nó gọi lại `notificationAdminService.list()` với bộ lọc
 * `type=workstation.alert`, và toàn bộ giá trị nó thêm vào là **gom + trình
 * bày**.
 *
 * ## Gom ở client, vì khoá gộp của Cloud không phải khoá HQ cần nhìn
 *
 * Cloud khử trùng theo `(branch, kind, subject, ngày nghiệp vụ)`. Tốt cho hộp
 * thư — một sự cố kéo dài một tuần kêu 7 lần chứ không phải mỗi tick một lần.
 * Nhưng HQ nhìn NHIỀU quán, nên grain họ cần là **quán → sự cố**, và một sự cố
 * kéo dài phải là MỘT dòng có ghi "7 ngày", không phải 7 dòng giống nhau.
 *
 * Vì vậy hai tầng gom, cùng khoá `(kind, subject)` mà phía Go dùng — lệch khoá
 * thì màn HQ đếm khác panel máy trạm cho cùng một sự cố:
 *
 * 1. `params` của từng thông báo → `WorkstationAlertRow` (một ngày nghiệp vụ)
 * 2. gom theo `(kind, subject)` trong một quán → `WorkstationAlertIncident`
 *
 * ## `count` và `first_seen_at` là ảnh chụp của máy trạm, không phải của Cloud
 *
 * Cloud không giữ lịch sử; nó nhận ảnh chụp lúc đẩy (ruling S3). Nên khi gộp
 * nhiều ngày lại: `count` lấy **lớn nhất** chứ không cộng — cộng hai ảnh chụp
 * của cùng một bộ đếm đang chạy là đếm trùng. `firstSeenAt` lấy **sớm nhất**,
 * đúng nghĩa "lần đầu".
 */

import type { PaginatedResponse } from "@/lib/api";
import {
  notificationAdminService,
  type AdminNotificationListRow,
} from "@/services/notification-service";

/** Giá trị `type` mà `AlertController` phát ra. Một nguồn chân lý cho chuỗi này. */
export const WORKSTATION_ALERT_TYPE = "workstation.alert";

export type AlertSeverity = "critical" | "warning" | "info";

/** Nặng trước. Dùng cho cả sắp xếp dòng lẫn sắp xếp quán. */
export const SEVERITY_RANK: Record<AlertSeverity, number> = {
  critical: 0,
  warning: 1,
  info: 2,
};

const SEVERITIES: readonly AlertSeverity[] = ["critical", "warning", "info"];

/** Một thông báo `workstation.alert` đã bóc `params`, tức MỘT ngày nghiệp vụ. */
export interface WorkstationAlertRow {
  /** id thông báo — link sang màn audit sẵn có. */
  id: string;
  branchId: string;
  branchName: string;
  kind: string;
  subject: string;
  severity: AlertSeverity;
  title: string;
  count: number;
  firstSeenAt: string | null;
  /** `created_at` của thông báo: lúc Cloud nhận, không phải lúc sự cố xảy ra. */
  reportedAt: string | null;
}

/** Một sự cố `(kind, subject)` của một quán, gộp qua các ngày trong cửa sổ. */
export interface WorkstationAlertIncident {
  key: string;
  kind: string;
  subject: string;
  severity: AlertSeverity;
  title: string;
  /** Lớn nhất trong các ảnh chụp — xem docblock đầu file về việc KHÔNG cộng. */
  count: number;
  firstSeenAt: string | null;
  lastReportedAt: string | null;
  /** Số ngày nghiệp vụ sự cố này còn mở. 3 = đã ba ngày không ai sửa. */
  days: number;
  /** Thông báo mới nhất của sự cố — đích của link "xem chi tiết". */
  latestNotificationId: string;
}

export interface BranchAlertGroup {
  branchId: string;
  branchName: string;
  incidents: WorkstationAlertIncident[];
  severityCounts: Record<AlertSeverity, number>;
  worstSeverity: AlertSeverity;
  lastReportedAt: string | null;
}

// =========================================================================
//  Bóc params — `params` là JSON tự do, nên mọi trường đều phải coi là unknown
// =========================================================================

function asString(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

function asSeverity(value: unknown): AlertSeverity {
  // Mức lạ rơi về `info` chứ không bị bỏ dòng: một cảnh báo hiển thị sai mức
  // vẫn nói được "quán này có chuyện"; một cảnh báo bị nuốt thì không.
  return value === "critical" || value === "warning" || value === "info" ? value : "info";
}

function asCount(value: unknown): number {
  const n = typeof value === "number" ? value : Number(value);
  return Number.isFinite(n) && n >= 1 ? Math.trunc(n) : 1;
}

/**
 * Một dòng audit → một `WorkstationAlertRow`, hoặc `null` nếu không phải alert.
 *
 * Quy về `subject.id` (id chi nhánh) làm khoá quán, không phải `branch_name`:
 * tên quán đổi được và trùng được, còn id thì không. `branch_name` chỉ dùng để
 * HIỂN THỊ.
 */
export function parseWorkstationAlert(row: AdminNotificationListRow): WorkstationAlertRow | null {
  if (row.type !== WORKSTATION_ALERT_TYPE) return null;

  const params = row.params ?? {};
  const branchName =
    asString(params.branch_name) ?? row.subject?.display_name ?? row.subject?.id ?? "—";
  const branchId = row.subject?.id ?? branchName;
  const kind = asString(params.kind) ?? "unknown";

  return {
    id: row.id,
    branchId,
    branchName,
    kind,
    subject: asString(params.subject) ?? "",
    severity: asSeverity(params.severity),
    title: asString(params.title) ?? kind,
    count: asCount(params.count),
    firstSeenAt: asString(params.first_seen_at),
    reportedAt: row.created_at,
  };
}

function earliest(a: string | null, b: string | null): string | null {
  if (!a) return b;
  if (!b) return a;
  return a < b ? a : b;
}

function latest(a: string | null, b: string | null): string | null {
  if (!a) return b;
  if (!b) return a;
  return a > b ? a : b;
}

/**
 * Gom phẳng → theo quán. Hàm THUẦN, không React, không mạng: đây là chỗ duy
 * nhất có logic đáng sai, nên nó được test riêng.
 *
 * Sắp xếp: quán nặng nhất trước, hoà thì quán vừa kêu gần đây nhất trước. HQ
 * mở màn này để biết đi cứu quán nào TRƯỚC, nên thứ tự chính là câu trả lời;
 * xếp theo tên là bắt họ tự dò.
 */
export function groupWorkstationAlerts(rows: WorkstationAlertRow[]): BranchAlertGroup[] {
  const branches = new Map<string, BranchAlertGroup>();
  const incidents = new Map<string, Map<string, WorkstationAlertIncident>>();

  for (const row of rows) {
    let group = branches.get(row.branchId);
    if (!group) {
      group = {
        branchId: row.branchId,
        branchName: row.branchName,
        incidents: [],
        severityCounts: { critical: 0, warning: 0, info: 0 },
        worstSeverity: "info",
        lastReportedAt: null,
      };
      branches.set(row.branchId, group);
      incidents.set(row.branchId, new Map());
    }

    const perBranch = incidents.get(row.branchId)!;
    // Cùng khoá `(kind, subject)` mà alert_kinds.go dùng — xem docblock đầu file.
    // `JSON.stringify` chứ không nối chuỗi bằng một ký tự phân cách: `kind` và
    // `subject` là chuỗi tự do do phía Go gửi lên, nên MỌI ký tự phân cách đều
    // có thể nằm trong chính giá trị và làm hai sự cố khác nhau gộp làm một.
    const key = JSON.stringify([row.kind, row.subject]);
    const existing = perBranch.get(key);

    if (!existing) {
      perBranch.set(key, {
        key,
        kind: row.kind,
        subject: row.subject,
        severity: row.severity,
        title: row.title,
        count: row.count,
        firstSeenAt: row.firstSeenAt,
        lastReportedAt: row.reportedAt,
        days: 1,
        latestNotificationId: row.id,
      });
    } else {
      existing.count = Math.max(existing.count, row.count);
      existing.firstSeenAt = earliest(existing.firstSeenAt, row.firstSeenAt);
      existing.days += 1;
      // Mức và tiêu đề lấy theo bản ghi MỚI NHẤT: một sự cố có thể leo thang
      // (warning hôm qua, critical hôm nay) và HQ cần thấy mức đang có.
      const next = latest(existing.lastReportedAt, row.reportedAt);
      if (next !== existing.lastReportedAt) {
        existing.severity = row.severity;
        existing.title = row.title;
        existing.latestNotificationId = row.id;
        existing.lastReportedAt = next;
      }
    }

    group.lastReportedAt = latest(group.lastReportedAt, row.reportedAt);
  }

  for (const group of branches.values()) {
    group.incidents = [...incidents.get(group.branchId)!.values()].sort(
      (a, b) =>
        SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity] ||
        b.count - a.count ||
        a.kind.localeCompare(b.kind)
    );

    for (const incident of group.incidents) {
      group.severityCounts[incident.severity] += 1;
    }

    group.worstSeverity =
      SEVERITIES.find((severity) => group.severityCounts[severity] > 0) ?? "info";
  }

  return [...branches.values()].sort(
    (a, b) =>
      SEVERITY_RANK[a.worstSeverity] - SEVERITY_RANK[b.worstSeverity] ||
      (b.lastReportedAt ?? "").localeCompare(a.lastReportedAt ?? "") ||
      a.branchName.localeCompare(b.branchName)
  );
}

// =========================================================================
//  Đọc
// =========================================================================

/** Trần số trang kéo về. 5 × 100 = 500 dòng. */
export const MAX_PAGES = 5;
const PER_PAGE = 100;

export interface WorkstationAlertQuery {
  /** Số ngày lùi lại từ bây giờ. */
  windowDays: number;
  /** `undefined` = mọi mức. */
  severity?: AlertSeverity;
}

export interface WorkstationAlertFeed {
  groups: BranchAlertGroup[];
  /** Số thông báo đã kéo về (trước khi lọc mức ở client). */
  loaded: number;
  /** Tổng ở server trong cửa sổ — so với `loaded` để biết có bị cắt không. */
  total: number;
  /**
   * `true` khi server còn dòng mà ta không kéo nữa. Phải HIỆN RA: một màn
   * cảnh báo cắt bớt trong im lặng có thể giấu hẳn một quán đang cháy, và
   * người đọc không có cách nào biết.
   */
  truncated: boolean;
}

/**
 * Kéo alert trong cửa sổ thời gian rồi gom theo quán.
 *
 * Lọc `critical` được đẩy XUỐNG SERVER qua `priority=urgent` (AlertController
 * ánh xạ critical → urgent) để giảm nguy cơ bị cắt trang, nhưng vẫn lọc lại ở
 * client: `warning` và `info` cùng ánh xạ về `high`, nên priority KHÔNG tách
 * được hai mức đó và không thể là bộ lọc duy nhất.
 */
export async function fetchWorkstationAlerts(
  brandSlug: string,
  query: WorkstationAlertQuery
): Promise<WorkstationAlertFeed> {
  const from = new Date(Date.now() - query.windowDays * 86_400_000).toISOString();

  const rows: WorkstationAlertRow[] = [];
  let loaded = 0;
  let total = 0;
  let page = 1;
  let lastPage = 1;

  do {
    const res: PaginatedResponse<AdminNotificationListRow> = await notificationAdminService.list(
      brandSlug,
      {
        type: [WORKSTATION_ALERT_TYPE],
        from,
        per_page: PER_PAGE,
        page,
        ...(query.severity === "critical" ? { priority: "urgent" as const } : {}),
      }
    );

    for (const row of res.data) {
      const parsed = parseWorkstationAlert(row);
      if (parsed && (!query.severity || parsed.severity === query.severity)) {
        rows.push(parsed);
      }
    }

    loaded += res.data.length;
    total = res.meta?.total ?? loaded;
    lastPage = res.meta?.last_page ?? 1;
    page += 1;
  } while (page <= lastPage && page <= MAX_PAGES);

  return {
    groups: groupWorkstationAlerts(rows),
    loaded,
    total,
    truncated: loaded < total,
  };
}
