import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router";

import {
  ackAlert,
  ackAlertKind,
  ApiError,
  listAlerts,
  type AlertAckActor,
  type AlertPushStats,
  type WorkstationAlert,
} from "../lib/api";
import { useTranslation } from "../providers/app-provider";

/**
 * #1806 S1 — bảng cảnh báo của máy trạm.
 *
 * Đây là mảnh khiến cả hạ tầng alert có nghĩa. Trước nó, `AlertStore` đã có
 * bảng, có index gộp, có test — và **0 caller**; 145 chỗ `slog.Warn` kêu vào
 * một file log không ai mở. Một cảnh báo không ai nhìn thấy thì bằng không.
 *
 * ## Ba quyết định hiển thị
 *
 * **Không phân trang, không lọc.** Một máy có hơn vài chục alert mở cùng lúc là
 * bất thường đến mức bản thân nó là tín hiệu; cắt bớt danh sách sẽ giấu đúng
 * cái cần thấy.
 *
 * **`count` và `first_seen` luôn hiện.** Alert được gộp theo `(kind, subject)`,
 * nên một dòng có thể đại diện cho 200 lần xảy ra. Không hiện số lần thì "máy
 * in bếp mất kết nối" trông giống nhau dù nó xảy ra một lần lúc sáng hay đang
 * xảy ra liên tục ngay lúc này.
 *
 * **Nút xác nhận chỉ hiện với alert KHÔNG tự đóng.** Alert tự đóng được (máy in
 * chẳng hạn) sẽ tự biến mất khi in lại thành công — cho bấm tay chỉ tạo ra
 * đường để người ta dọn màn hình mà không sửa gì. Điều kiện đó nay đọc từ cờ
 * `ack_required` do máy trạm gửi kèm, KHÔNG từ danh sách kind gõ cứng ở đây:
 * bản cũ gõ cứng `cash_retained`, nên `cloud_money_overwrite` hiện ra mà không
 * có nút nào và ba dòng ở 本郷店/人形町店 treo từ 2026-08-13 (#2848).
 *
 * ## Hai thứ #2848 thêm vào
 *
 * **Xác nhận cả đợt theo kind.** `POST /api/alerts/ack-kind` có từ #2167, sinh
 * ra cho đúng `cloud_money_overwrite` — một đợt lệch rải ra hàng chục dòng —
 * nhưng chưa app nào gọi. Nút này đóng NHIỀU dòng một lúc nên nó **hai bước**:
 * bấm lần một chỉ mở lời hỏi có ghi rõ số dòng và tên người sẽ vào sổ, bấm lần
 * hai mới đóng. Lời hỏi tự thu lại sau 10 giây để một nút đang "lên đạn" không
 * nằm chờ người đi ngang bấm trúng.
 *
 * **`by` là NGƯỜI, không phải nhãn thiết bị.** Xem `AckActorBar` ngay dưới.
 */
export function AlertPanel() {
  const { t } = useTranslation();
  const [alerts, setAlerts] = useState<WorkstationAlert[]>([]);
  const [push, setPush] = useState<AlertPushStats | null>(null);
  const [actor, setActor] = useState<AlertAckActor>({ shift_in_progress: false });
  const [declaredName, setDeclaredName] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [armedKind, setArmedKind] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const snapshot = await listAlerts();
      setAlerts(snapshot.alerts);
      setPush(snapshot.push);
      setActor(snapshot.ackActor);
      setError(null);
    } catch {
      setError(t("alerts.load_failed"));
    }
  }, [t]);

  useEffect(() => {
    void load();
    // 15s: đủ nhanh để người đứng quầy thấy sự cố, đủ chậm để không thành một
    // nguồn tải mới trên chính máy đang có vấn đề.
    const id = setInterval(() => void load(), 15_000);
    return () => clearInterval(id);
  }, [load]);

  // Nút "cả đợt" đã lên đạn thì tự thu lại — một nút chờ sẵn ở trạng thái
  // "bấm phát nữa là đóng 30 dòng" không được phép nằm đó qua ca.
  useEffect(() => {
    if (armedKind === null) return;
    const id = setTimeout(() => setArmedKind(null), 10_000);
    return () => clearTimeout(id);
  }, [armedKind]);

  // Tên người sẽ vào sổ: ưu tiên tuyệt đối cho ca (máy trạm cũng cưỡng chế điều
  // đó — gõ gì ở đây cũng không ghi đè được người phụ trách ca).
  const actorName = actor.name ?? "";
  const needsDeclaredName = actor.shift_in_progress && actorName === "";
  const canAck =
    actor.shift_in_progress && (actorName !== "" || declaredName.trim().length > 0);
  const ackingAs = actorName !== "" ? actorName : declaredName.trim();

  function ackErrorMessage(err: unknown): string {
    // Rẽ theo MÃ, không theo câu chữ — câu chữ còn phải dịch ba thứ tiếng.
    if (err instanceof ApiError) {
      if (err.data?.code === "NO_OPEN_SHIFT") return t("alerts.ack_no_open_shift");
      if (err.data?.code === "ACTOR_NAME_REQUIRED") return t("alerts.ack_name_required");
    }
    return t("alerts.ack_failed");
  }

  async function handleAck(alert: WorkstationAlert) {
    setBusy(alert.id);
    try {
      await ackAlert(alert.id, declaredName.trim());
      setDeclaredName("");
      await load();
    } catch (err) {
      setError(ackErrorMessage(err));
    } finally {
      setBusy(null);
    }
  }

  async function handleAckKind(kind: string) {
    setBusy(kind);
    try {
      await ackAlertKind(kind, declaredName.trim());
      setArmedKind(null);
      setDeclaredName("");
      await load();
    } catch (err) {
      setError(ackErrorMessage(err));
    } finally {
      setBusy(null);
    }
  }

  // Kind nào đang có NHIỀU HƠN MỘT dòng chờ xác nhận — chỉ những kind đó mới
  // đáng có nút cả đợt. Một dòng thì nút hàng loạt chỉ là nút đơn lẻ với nhiều
  // rủi ro hơn.
  const batchKinds = useMemo(() => {
    const counts = new Map<string, number>();
    for (const a of alerts) {
      if (!a.ack_required) continue;
      counts.set(a.kind, (counts.get(a.kind) ?? 0) + 1);
    }
    return [...counts.entries()].filter(([, n]) => n > 1);
  }, [alerts]);

  // Nhãn của kind: có bản dịch thì dùng, không thì hiện thẳng mã kind. `t()`
  // trả về chính khoá khi thiếu bản dịch, nên so sánh với khoá là cách phát
  // hiện "chưa dịch" — hiện `cloud_money_overwrite` vẫn đọc được, hiện
  // `alerts.kind.cloud_money_overwrite` thì không.
  function kindLabel(kind: string): string {
    const key = `alerts.kind.${kind}`;
    const label = t(key);
    return label === key ? kind : label;
  }

  if (error === null && alerts.length === 0) {
    return null;
  }

  return (
    <section className="rounded-lg border border-border p-4">
      <h2 className="mb-3 text-sm font-semibold">
        {t("alerts.title")} ({alerts.length})
      </h2>

      {error !== null && <p className="mb-2 text-xs text-destructive">{error}</p>}

      {alerts.some((a) => a.ack_required) && (
        <AckActorBar
          actor={actor}
          needsDeclaredName={needsDeclaredName}
          declaredName={declaredName}
          onDeclaredNameChange={setDeclaredName}
        />
      )}

      <ul className="flex flex-col gap-2">
        {alerts.map((a) => (
          <li
            key={a.id}
            className="flex items-start justify-between gap-3 rounded border border-border/60 p-2"
          >
            <div className="min-w-0">
              <p className="truncate text-sm font-medium">{a.title}</p>
              <p className="text-xs text-muted-foreground">
                {a.severity} · {a.subject}
                {/* Số lần xảy ra là thứ phân biệt một sự cố cũ với một sự cố
                    đang diễn ra — xem docblock. */}
                {a.count > 1 && ` · ×${a.count}`}
                {` · ${new Date(a.first_seen_at).toLocaleString()}`}
              </p>
              {a.kind === "stale_build" && (
                <Link
                  to="/settings?tab=update"
                  className="mt-1 inline-block text-xs text-primary underline-offset-2 hover:underline"
                >
                  {t("alerts.stale_build_open_settings")}
                </Link>
              )}
            </div>

            {/* Chỉ alert cần xác nhận mới có nút — cờ do máy trạm gửi, không
                phải danh sách kind chép lại ở đây (xem docblock). */}
            {a.ack_required && (
              <button
                type="button"
                disabled={busy === a.id || !canAck}
                onClick={() => void handleAck(a)}
                className="shrink-0 rounded border border-border px-2 py-1 text-xs disabled:opacity-50"
              >
                {t("alerts.ack")}
              </button>
            )}
          </li>
        ))}
      </ul>

      {batchKinds.length > 0 && (
        <div className="mt-3 flex flex-col gap-2 border-t border-border/60 pt-3">
          {batchKinds.map(([kind, n]) =>
            armedKind === kind ? (
              <div key={kind} className="flex flex-wrap items-center gap-2">
                <span className="text-xs text-destructive">
                  {t("alerts.ack_kind_confirm", {
                    count: n,
                    kind: kindLabel(kind),
                    actor: ackingAs,
                  })}
                </span>
                <button
                  type="button"
                  disabled={busy === kind || !canAck}
                  onClick={() => void handleAckKind(kind)}
                  className="rounded border border-destructive px-2 py-1 text-xs text-destructive disabled:opacity-50"
                >
                  {t("alerts.ack_kind_commit", { count: n })}
                </button>
                <button
                  type="button"
                  onClick={() => setArmedKind(null)}
                  className="rounded border border-border px-2 py-1 text-xs"
                >
                  {t("alerts.ack_kind_cancel")}
                </button>
              </div>
            ) : (
              <button
                key={kind}
                type="button"
                disabled={!canAck}
                onClick={() => setArmedKind(kind)}
                className="self-start rounded border border-border px-2 py-1 text-xs disabled:opacity-50"
              >
                {t("alerts.ack_kind", { count: n, kind: kindLabel(kind) })}
              </button>
            ),
          )}
        </div>
      )}

      {push !== null && <AlertPushLine push={push} />}
    </section>
  );
}

/**
 * #2848 — dòng nói TRƯỚC khi bấm là máy sẽ ghi tên ai vào sổ.
 *
 * Backend bắt buộc `by` với lý do tường minh — *"ack phải biết ai xác nhận"* —
 * vì đóng một alert lệch tiền là khẳng định của một CON NGƯỜI rằng đã đối soát
 * tiền thật. Bề mặt này từng gửi hằng số `"workstation-ui"`, nên yêu cầu được
 * thoả về hình thức và vô hiệu về thực chất: mọi lượt xác nhận, mọi máy, mọi
 * ngày, cùng một "người". Với dấu vết kiểm toán tiền thì ghi một tác nhân giả
 * **tệ hơn không ghi** — không ghi thì biết là chưa biết.
 *
 * Danh tính nay mượn của CA THU NGÂN đang mở: dữ liệu đã có, không thêm thao
 * tác cho nhân viên, và đúng ngữ cảnh (lệch tiền thuộc về ca, cuối ca nó đi
 * vào 過不足). Ba trạng thái:
 *
 * - **không có ca** → chặn hẳn, và nói thẳng phải mở ca trước. Không rơi về
 *   nhãn thiết bị, không hỏi tên: một cái tên gõ ngoài mọi ca chẳng gắn được
 *   vào đâu.
 * - **ca có tên** → hiện tên đó, không cho sửa. Máy trạm cũng cưỡng chế.
 * - **ca khuyết tên** → ô nhập tên. Mở ca KHÔNG bắt buộc chọn người (pos-web
 *   mặc định "__me" và không gửi khoá nào), nên chặn luôn cả nhánh này sẽ làm
 *   nút chết trên đúng cấu hình phổ biến nhất, tức quay về đúng #2848. Tên gõ
 *   tay được đóng dấu `tự khai` trong sổ để phân biệt với tên lấy từ sổ ca.
 */
function AckActorBar({
  actor,
  needsDeclaredName,
  declaredName,
  onDeclaredNameChange,
}: {
  actor: AlertAckActor;
  needsDeclaredName: boolean;
  declaredName: string;
  onDeclaredNameChange: (value: string) => void;
}) {
  const { t } = useTranslation();

  if (!actor.shift_in_progress) {
    return (
      <p className="mb-2 rounded border border-destructive/60 p-2 text-xs text-destructive">
        {t("alerts.ack_no_open_shift")}
      </p>
    );
  }

  if (!needsDeclaredName) {
    return (
      <p className="mb-2 text-xs text-muted-foreground">
        {t("alerts.ack_actor", {
          name: actor.name ?? "",
          session: actor.session_code ?? "",
        })}
      </p>
    );
  }

  return (
    <div className="mb-2 flex flex-col gap-1">
      <label className="text-xs text-muted-foreground" htmlFor="alert-ack-actor">
        {t("alerts.ack_actor_prompt", { session: actor.session_code ?? "" })}
      </label>
      <input
        id="alert-ack-actor"
        type="text"
        value={declaredName}
        maxLength={60}
        onChange={(e) => onDeclaredNameChange(e.target.value)}
        placeholder={t("alerts.ack_actor_placeholder")}
        className="w-64 rounded border border-border px-2 py-1 text-xs"
      />
    </div>
  );
}

/**
 * #2695 — một dòng nói HQ có nhận được những alert này không.
 *
 * Ba trạng thái, không phải hai: chưa từng đẩy · đẩy hỏng · đẩy được. Đường
 * đẩy phía Cloud fail-open theo thiết kế, nên nếu chỉ hiện "có lỗi / không có
 * lỗi" thì một máy CHƯA BAO GIỜ gọi endpoint trông giống hệt một máy vừa gọi
 * xong — đúng trạng thái production đã nằm trong đó suốt nhiều tháng với 0
 * thông báo `workstation.alert`.
 */
function AlertPushLine({ push }: { push: AlertPushStats }) {
  const { t } = useTranslation();

  if (push.ok === 0 && push.failed === 0) {
    return (
      <p className="mt-3 text-xs text-muted-foreground">
        {t("alerts.push_never")}
        {push.last_skip_reason !== undefined && push.last_skip_reason !== ""
          ? ` — ${push.last_skip_reason}`
          : ""}
      </p>
    );
  }

  if (push.failed > 0) {
    return (
      <p className="mt-3 text-xs text-destructive">
        {t("alerts.push_failed", { ok: push.ok, failed: push.failed })}
        {push.last_error !== undefined && push.last_error !== "" ? ` — ${push.last_error}` : ""}
      </p>
    );
  }

  return (
    <p className="mt-3 text-xs text-muted-foreground">
      {t("alerts.push_ok", { ok: push.ok, sent: push.alerts_sent })}
      {push.last_ok_at !== undefined && push.last_ok_at !== ""
        ? ` · ${new Date(push.last_ok_at).toLocaleString()}`
        : ""}
    </p>
  );
}
