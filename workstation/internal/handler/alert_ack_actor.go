package handler

import (
	"database/sql"
	"net/http"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// panelAlert là một dòng alert kèm thứ panel cần để quyết định có vẽ nút ack.
//
// `ack_required` = kind KHÔNG tự đóng được (`AutoResolvable: false`). Trước đó
// panel tự gõ cứng `kind === "cash_retained"`, nên `cloud_money_overwrite` —
// cũng không tự đóng được — hiện ra mà **không có nút nào**, và đó là nửa còn
// lại của #2848: ba dòng treo từ 2026-08-13 không có đường đóng nào ở UI cả.
// Danh sách kind sống ở `alert_kinds.go`; một bản sao ở frontend chỉ chờ lệch.
type panelAlert struct {
	service.Alert
	AckRequired bool `json:"ack_required"`
}

func decorateAlertsForPanel(alerts []service.Alert) []panelAlert {
	rows := make([]panelAlert, 0, len(alerts))
	for _, a := range alerts {
		_, _, autoResolvable, ok := service.AlertPolicyFor(a.Kind)
		rows = append(rows, panelAlert{Alert: a, AckRequired: ok && !autoResolvable})
	}
	return rows
}

// ─── AI LÀ NGƯỜI XÁC NHẬN MỘT ALERT TIỀN (#2848) ────────────────────────────
//
// `POST /api/alerts/{id}/ack` và `/api/alerts/ack-kind` đòi `by` với một lý do
// tường minh — *"ack phải biết ai xác nhận"* — vì đóng một alert lệch tiền là
// khẳng định của một CON NGƯỜI rằng đã đối soát tiền thật.
//
// Bề mặt duy nhất gọi nó lại gửi hằng số `"workstation-ui"`. Yêu cầu được thoả
// về HÌNH THỨC và vô hiệu về THỰC CHẤT: mọi lượt xác nhận lệch tiền, ở mọi máy,
// mọi ngày, mang cùng một "người". Với một dấu vết kiểm toán tiền thì **ghi một
// tác nhân giả tệ hơn không ghi** — không ghi thì biết là chưa biết, còn ghi
// "workstation-ui" thì đọc lên như đã truy được trách nhiệm.
//
// Nên `by` KHÔNG còn do client tự khai. Máy trạm tự phân giải nó từ **ca thu
// ngân đang mở**: dữ liệu đã có (`till_sessions`), không thêm thao tác cho nhân
// viên, và đúng ngữ cảnh — lệch tiền thuộc về ca, và cuối ca nó đi vào 過不足.
//
// ## Ba trạng thái, không phải hai
//
// Cột `opened_by_id` / `opener_name` đều NULLABLE và cả hai đường mở ca đều để
// chúng rỗng được: pos-web mặc định chọn "__me" và **không gửi khoá nào**
// (open-page.tsx), còn handler local ghi thẳng `nullIfEmpty(...)` chứ không suy
// ra người từ token thiết bị. Sau khi Cloud pull xuống thì tên mới thường có.
// Vậy nên "có ca mở" KHÔNG kéo theo "biết ai" — ba trạng thái phải phân biệt:
//
//   - không có ca nào đang mở  → CHẶN ack (không có ngữ cảnh nào để gắn)
//   - ca mở + có tên           → ghi tên đó, client không ghi đè được
//   - ca mở + khuyết tên       → đòi người bấm TỰ KHAI tên, và đóng dấu `tự khai`
//
// Vế thứ ba là nhượng bộ có tính toán, không phải cửa sau: chặn luôn cả nó sẽ
// làm nút ack chết trên đúng cấu hình phổ biến nhất (ca mở offline, mặc định
// không chọn người) — một nút không bao giờ bấm được thì alert lệch tiền treo
// vĩnh viễn, tức quay lại đúng #2848. Máy trạm ghi rõ nguồn của cái tên để
// người đọc sổ sau này phân biệt được "lấy từ sổ ca" với "người gõ vào".

// ackActor là danh tính máy trạm được phép ghi vào một lượt ack.
type ackActor struct {
	// ShiftInProgress: có ca `open`/`closing` trên till của máy này không.
	ShiftInProgress bool `json:"shift_in_progress"`
	// SessionCode của ca đó (rỗng khi không có ca).
	SessionCode string `json:"session_code,omitempty"`
	// Name là người phụ trách ca, rỗng khi ca không ghi ai.
	Name string `json:"name,omitempty"`
}

// ackActor phân giải người phụ trách CA ĐANG MỞ trên till của máy này.
//
// Cách lấy tên trùng đúng với hai phiếu ca (`lan_shift_open_report.go`,
// `lan_shift_report.go`): `opener_name` tự khai lúc mở ca nếu có, không thì tra
// `opened_by_id` qua bản sao `staff`. Một máy trạm phục vụ MỘT chi nhánh và tối
// đa MỘT ca mở, nên không cần lọc theo till.
func (s *Server) ackActor() ackActor {
	if s == nil || s.db == nil {
		return ackActor{}
	}

	var code string
	var name sql.NullString
	err := s.db.QueryRow(`
		SELECT ts.session_code,
		       COALESCE(NULLIF(ts.opener_name, ''), NULLIF(st.full_name, ''))
		FROM till_sessions ts
		LEFT JOIN staff st ON st.id = ts.opened_by_id
		WHERE ts.status IN ('open', 'closing')
		ORDER BY ts.opened_at DESC
		LIMIT 1`).Scan(&code, &name)
	if err != nil {
		// sql.ErrNoRows = không có ca. Lỗi khác cũng phải fail-closed: đoán ra
		// một cái tên khi không đọc được sổ ca chính là thứ mục này cấm.
		return ackActor{}
	}

	return ackActor{
		ShiftInProgress: true,
		SessionCode:     code,
		Name:            strings.TrimSpace(name.String),
	}
}

// maxDeclaredNameRunes chặn một ô nhập tự do biến thành ô ghi chú. Tên người,
// không phải lời giải trình — lời giải trình không có chỗ trong cột này và
// đọc lên sẽ che mất chính cái tên.
const maxDeclaredNameRunes = 60

// ackRefusal là lý do KHÔNG ai được ghi vào cột `resolved_by`.
type ackRefusal struct {
	Status  int
	Code    string
	Message string
}

// resolveAckBy dựng chuỗi ghi vào `alerts.resolved_by`, hoặc trả về lý do từ
// chối. `declared` là tên người bấm tự gõ — chỉ được dùng khi ca đang mở nhưng
// không ghi ai, và KHÔNG bao giờ ghi đè tên đã có trong sổ ca.
func (s *Server) resolveAckBy(declared string) (string, *ackRefusal) {
	actor := s.ackActor()

	if !actor.ShiftInProgress {
		return "", &ackRefusal{
			Status: http.StatusConflict,
			Code:   "NO_OPEN_SHIFT",
			// 409 chứ không 400: yêu cầu không sai hình dạng, MÁY đang ở sai
			// trạng thái. Người bấm sửa được bằng cách mở ca.
			Message: "không có ca thu ngân đang mở — mở ca trước khi xác nhận lệch tiền",
		}
	}

	if actor.Name != "" {
		// Client gửi gì cũng bỏ qua: sổ ca đã biết ai, và cho màn hình ghi đè
		// nó là dựng lại đúng cái lỗ vừa bịt.
		return actor.Name + " · ca " + actor.SessionCode, nil
	}

	name := strings.TrimSpace(declared)
	if name == "" {
		return "", &ackRefusal{
			Status:  http.StatusBadRequest,
			Code:    "ACTOR_NAME_REQUIRED",
			Message: "ca đang mở không ghi người phụ trách — nhập tên người xác nhận",
		}
	}
	if len([]rune(name)) > maxDeclaredNameRunes {
		name = string([]rune(name)[:maxDeclaredNameRunes])
	}

	// `tự khai` là phần đắt nhất của chuỗi này: nó nói cho người đọc sổ sau
	// này biết cái tên KHÔNG đến từ sổ ca mà từ bàn phím ngay lúc bấm.
	return name + " · ca " + actor.SessionCode + " · tự khai", nil
}

// writeAckRefusal trả lời một từ chối dưới dạng JSON có `code` — client rẽ
// nhánh theo mã, không theo câu chữ (câu chữ còn phải dịch ba thứ tiếng).
func writeAckRefusal(w http.ResponseWriter, ref *ackRefusal) {
	writeJSON(w, ref.Status, map[string]any{
		"code":    ref.Code,
		"message": ref.Message,
	})
}
