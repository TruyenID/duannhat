package handler

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// cashChangerURL resolves the Glory 釣銭機 adapter URL for the current request.
// Primary source: the Cloud peripheral registry (type coin_changer) synced DOWN
// into peripheral_devices — metadata.url wins, else metadata.host[:port]
// (port 80 default, TLS-less like the adapter). Env WS_APP_CASH_CHANGER_URL is a
// dev-only fallback. Returns ("", false) when no machine is configured, which the
// glory client turns into ErrNotConfigured → the LAN endpoints 503.
func (s *Server) cashChangerURL() (string, bool) {
	if m := s.cashChangerMetadata(); m != nil {
		if u := metaFirstString(m, "url"); u != "" {
			return u, true
		}
		if h := metaFirstString(m, "host", "ip", "address"); h != "" {
			port := metaPort(m, "port", 80)
			if port == 80 {
				return "http://" + h, true
			}
			return fmt.Sprintf("http://%s:%d", h, port), true
		}
	}

	// Local-dev fallback.
	if u := os.Getenv("WS_APP_CASH_CHANGER_URL"); u != "" {
		return u, true
	}
	return "", false
}

// cashChangerMetadata reads the registered 釣銭機's metadata blob, or nil when
// no machine is registered / the blob is unusable.
func (s *Server) cashChangerMetadata() map[string]any {
	var meta sql.NullString
	err := s.db.QueryRow(
		`SELECT metadata FROM peripheral_devices
		 WHERE type = 'coin_changer' AND is_active = 1
		 ORDER BY updated_at DESC LIMIT 1`,
	).Scan(&meta)
	if err != nil || !meta.Valid || meta.String == "" {
		return nil
	}
	var m map[string]any
	if json.Unmarshal([]byte(meta.String), &m) != nil {
		return nil
	}
	return m
}

// Deposit-timeout bounds (#2422). The machine's own API caps at 86400s and
// treats 0 as "no timeout" — which we refuse to offer, because on timeout the
// machine KEEPS the cash and "wait forever" turns a stuck transaction into a
// locked machine nobody can clear from the POS.
const (
	cashChangerDepositTimeoutDefault = 300 * time.Second
	cashChangerDepositTimeoutMin     = 30 * time.Second
	cashChangerDepositTimeoutMax     = 86400 * time.Second
)

// cashChangerDepositTimeout resolves how long the 釣銭機 waits for the customer
// to finish inserting cash, from `metadata.deposit_timeout_seconds` on the
// registered machine. Resolved PER TRANSACTION (like the adapter URL), so an
// edit in admin takes effect on the next sale without restarting.
//
// Anything missing, non-numeric or out of bounds reads as the 300s default
// rather than an error: this runs on the sales path, and Cloud already rejects
// bad values at registration (PeripheralDeviceService::metadataRulesFor). A
// workstation that refused to sell because a synced number looked odd would be
// choosing the worse failure.
func (s *Server) cashChangerDepositTimeout() time.Duration {
	m := s.cashChangerMetadata()
	if m == nil {
		return cashChangerDepositTimeoutDefault
	}
	secs := metaPort(m, "deposit_timeout_seconds", 0) // same lenient number/string decode
	if secs <= 0 {
		return cashChangerDepositTimeoutDefault
	}
	d := time.Duration(secs) * time.Second
	if d < cashChangerDepositTimeoutMin || d > cashChangerDepositTimeoutMax {
		return cashChangerDepositTimeoutDefault
	}
	return d
}

// LAN 釣銭機 (Glory) cash-collection endpoints, called by pos-web. A collection
// takes 30–300s so it is asynchronous: start returns a session id, the POS polls
// status, and the cancel button aborts it. See
// docs/guide/cash-changer-glory-adapter.md.
//
//	POST /api/v1/pos/cash-changer/collect       { order_id, amount?, metadata? } → { session_id }
//	GET  /api/v1/pos/cash-changer/collect/{session}     → snapshot
//	POST /api/v1/pos/cash-changer/collect/{session}/cancel

// cashChangerSurface phân biệt hai mount dùng CHUNG một handler.
//
// #2535 B11 — `/api/v1/pos/cash-changer/*` và `/api/v1/kiosk/cash-changer/*` là
// cùng bộ handler, nên mọi rào thêm vào đây áp cho cả hai. Đúng MỘT rào không
// được phép áp chung: ca thu ngân. `local_pos.go` ghi rõ đường payment của kiosk
// *"is intentionally NOT gated"* — máy tự phục vụ phải bán được trong khoảng
// đóng-ca→mở-ca, và những khoản đó thành gap payment đối soát tay ở ca sau.
//
// Phân biệt bằng THAM SỐ chứ không đọc `r.URL.Path`: đường dẫn là chi tiết định
// tuyến, và một lần đổi prefix sẽ âm thầm gỡ rào ca của POS.
type cashChangerSurface int

const (
	cashChangerPOS cashChangerSurface = iota
	cashChangerKiosk
)

type cashChangerItemAllocation struct {
	ItemID string `json:"item_id"`
	Units  int    `json:"units"`
}

// cashChangerSplitMetadata is audit context only: the workstation forwards it
// into the payment but never uses it to decide how much cash to collect.
// Pointers distinguish a required numeric zero (`bill_index`) from omission.
type cashChangerSplitMetadata struct {
	SplitMode       string                       `json:"split_mode"`
	BillIndex       *int                         `json:"bill_index"`
	TotalBills      *int                         `json:"total_bills"`
	Label           *string                      `json:"label,omitempty"`
	ItemAllocations *[]cashChangerItemAllocation `json:"item_allocations,omitempty"`
	Amount          *int                         `json:"amount,omitempty"`
}

// canonicalCashChangerSplitMetadata validates the POS-owned split context and
// serialises only the canonical audit vocabulary. Provenance fields are not in
// this input type; the recorder always owns and overwrites those itself.
func canonicalCashChangerSplitMetadata(raw json.RawMessage, collectAmount *int) (string, error) {
	if len(raw) == 0 {
		if collectAmount != nil {
			return "", errors.New("split amount requires metadata")
		}
		return "", nil
	}
	if collectAmount == nil {
		return "", errors.New("split metadata requires amount")
	}

	var metadata cashChangerSplitMetadata
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&metadata); err != nil {
		return "", fmt.Errorf("invalid split metadata: %w", err)
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return "", errors.New("invalid split metadata: trailing JSON value")
	}
	if metadata.BillIndex == nil || *metadata.BillIndex < 0 {
		return "", errors.New("split metadata bill_index must be zero or greater")
	}
	if metadata.TotalBills == nil || *metadata.TotalBills < 1 {
		return "", errors.New("split metadata total_bills must be positive")
	}

	switch metadata.SplitMode {
	case "even":
		if metadata.Label != nil || metadata.ItemAllocations != nil || metadata.Amount != nil {
			return "", errors.New("even split metadata contains fields for another mode")
		}
	case "by_items":
		if metadata.Label == nil || *metadata.Label == "" {
			return "", errors.New("by_items split metadata label is required")
		}
		if metadata.ItemAllocations == nil || len(*metadata.ItemAllocations) == 0 {
			return "", errors.New("by_items split metadata item_allocations are required")
		}
		if metadata.Amount != nil {
			return "", errors.New("by_items split metadata must not contain amount")
		}
		for _, allocation := range *metadata.ItemAllocations {
			if allocation.ItemID == "" || allocation.Units <= 0 {
				return "", errors.New("by_items split metadata allocations require item_id and positive units")
			}
		}
	case "by_amount":
		if metadata.Label == nil || *metadata.Label == "" {
			return "", errors.New("by_amount split metadata label is required")
		}
		if metadata.Amount == nil || *metadata.Amount != *collectAmount {
			return "", errors.New("by_amount split metadata amount must match collection amount")
		}
		if metadata.ItemAllocations != nil {
			return "", errors.New("by_amount split metadata must not contain item_allocations")
		}
	default:
		return "", errors.New("split metadata split_mode must be even, by_items, or by_amount")
	}

	canonical, err := json.Marshal(metadata)
	if err != nil {
		return "", fmt.Errorf("encode split metadata: %w", err)
	}

	return string(canonical), nil
}

// handleCashChangerCollect starts a cash collection for an order. The amount is
// server-authoritative — never trust a client amount for cash the machine will
// physically count.
func (s *Server) handleCashChangerCollect(surface cashChangerSurface) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		s.cashChangerCollect(w, r, surface)
	}
}

func (s *Server) cashChangerCollect(w http.ResponseWriter, r *http.Request, surface cashChangerSurface) {
	if _, ok := s.cashChangerURL(); !ok {
		writeError(w, http.StatusServiceUnavailable, "no cash changer configured")
		return
	}

	// #2535 B8 — rào ca, parity với `handlePOSPayment`. Thu tiền khi không có ca
	// nào mở sẽ rơi thành gap payment `till_session_id` NULL, phải đối soát tay
	// ở ca sau; đường POS đã chặn từ plan-044, đường 釣銭機 thì chưa bao giờ.
	if surface == cashChangerPOS && !s.hasInProgressShift() {
		writeNoOpenShift(w)
		return
	}

	var body struct {
		OrderID string `json:"order_id"`
		// #2941 — phần của MỘT người khi chia bill. Vắng mặt = đòi toàn bộ
		// phần còn thiếu (hành vi cũ, không đổi).
		//
		// Con trỏ chứ không phải giá trị: `0` do client gửi phải phân biệt
		// được với "không gửi gì". Gộp hai thứ đó thì một lỗi phía POS thành
		// một lượt thu nguyên đơn, im lặng.
		//
		// `*int` vì cả đường tiền của máy trạm là số nguyên (`total int`,
		// `Begin(orderID string, total int)`). Nhận `float64` rồi ép kiểu sẽ
		// cắt cụt âm thầm — `4.5` thành `4`, và không ai thấy.
		Amount *int `json:"amount"`
		// #2942 — ngữ cảnh kiểm toán của đúng hàng chia bill. Máy trạm chỉ
		// validate + chuyển tiếp; số tiền máy thu vẫn là `Amount` đã kẹp ở dưới.
		Metadata json.RawMessage `json:"metadata"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.OrderID == "" {
		writeError(w, http.StatusBadRequest, "order_id is required")
		return
	}

	var total int
	var status string
	if err := s.db.QueryRow(
		`SELECT COALESCE(total_amount, 0), COALESCE(status, '') FROM orders WHERE id = ?`, body.OrderID,
	).Scan(&total, &status); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeServerError(w, r, err)
		return
	}
	if total <= 0 {
		writeError(w, http.StatusUnprocessableEntity, "order total must be positive")
		return
	}

	// #2535 B8 — đơn ở trạng thái tận cùng thì không có gì để thu. Trước đây
	// handler chỉ đọc `total_amount`, nên máy khởi động được trên đơn đã
	// `closed`: `transitionOrderStatus` là một câu UPDATE trần, không máy trạng
	// thái, nên lượt thu lật `closed → paying → closed` và tạo dòng payment thứ
	// hai — để rồi Cloud từ chối 409 và ghi
	// `workstation_payment_stranded_at_the_drawer`. Tiền mặt đã vào máy.
	if status == "closed" || status == "voided" {
		writeError(w, http.StatusConflict, "order is already "+status)
		return
	}

	// #2535 B6 — đòi phần CÒN THIẾU, không phải tổng đơn.
	//
	// Bản cũ luôn truyền `total_amount`. Đơn đã trả một phần (một chân chia
	// bill, đặt cọc, trả một phần rồi ghi nợ) thì máy đòi lại nguyên tổng trong
	// khi màn POS ngay cạnh đang hiện `remaining_amount` đúng — hai con số đá
	// nhau trên cùng một màn hình. Và nếu khách trả theo con số của máy thì
	// khoản dư đó không có đường về: lúc sync UP Cloud **cắt** số tiền xuống
	// `outstanding` và chỉ ghi một dòng log.
	//
	// `sumCapturedPaymentsForOrder` chứ không phải `sumActive`: tiền mặt đã đếm
	// không được trừ đi bởi một khoản `pending` của máy quẹt thẻ đang treo (cùng
	// lý do #555 M13 đã sửa cho recorder).
	captured, err := s.sumCapturedPaymentsForOrder(body.OrderID)
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	remaining := total - captured
	if remaining <= 0 {
		writeError(w, http.StatusUnprocessableEntity, "order is already fully paid")
		return
	}

	// #2941 — chia bill: thu phần của MỘT người.
	//
	// Số tiền đến TỪ CLIENT ở đây, và đó là quyết định có cân nhắc — không
	// phải nới rào cũ.
	//
	// Máy trạm KHÔNG tính được phần chia. `equal-split.ts` của pos-web snap
	// theo minor unit của tiền tệ, loại hàng đã khoá, dồn phần dư vào hàng
	// cuối, và cho staff sửa tay từng hàng rồi chia lại phần còn lại. Ba dữ
	// kiện cuối sống trong state màn POS — máy trạm không biết hàng nào đang
	// khoá hay staff vừa gõ đè số nào. Tự tính ở đây chỉ đúng cho ca "chia đều,
	// không ai sửa tay", tức hỏng đúng lúc staff điều chỉnh.
	//
	// Thứ rào cũ THẬT SỰ bảo vệ là: một số tiền lớn hơn phần còn nợ thì lúc
	// sync UP Cloud CẮT xuống `outstanding` và chỉ ghi một dòng log ⇒ tiền dư
	// mắc kẹt trong ngăn kéo. Phép kẹp dưới đây đóng đúng lỗ đó, sớm hơn Cloud
	// một nhịp.
	//
	// Và `Tendered`/`Change` VẪN do máy đếm — phần "con số không ai kiểm lại
	// được" không hề đi qua client.
	amount := remaining
	if body.Amount != nil {
		amount = *body.Amount
		if amount <= 0 {
			writeError(w, http.StatusUnprocessableEntity, "amount must be positive")
			return
		}
		if amount > remaining {
			writeError(w, http.StatusUnprocessableEntity, "amount exceeds the outstanding balance")
			return
		}
	}

	paymentMetadata, err := canonicalCashChangerSplitMetadata(body.Metadata, body.Amount)
	if err != nil {
		writeError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}

	sessionID, err := s.cashChanger.BeginWithPaymentMetadata(body.OrderID, amount, paymentMetadata)
	if err != nil {
		if errors.Is(err, service.ErrMachineBusy) {
			writeError(w, http.StatusConflict, err.Error())
			return
		}
		writeServerError(w, r, err)
		return
	}

	s.auditLogPOS(r, "cash_changer.collect", "order", body.OrderID, `{"session":"`+sessionID+`"}`)
	writeJSON(w, http.StatusAccepted, map[string]any{
		"data": map[string]any{"session_id": sessionID, "order_id": body.OrderID, "total": amount},
	})
}

// handleCashChangerStatus returns the pollable state of a collection.
func (s *Server) handleCashChangerStatus(w http.ResponseWriter, r *http.Request) {
	// Status is a pure in-memory snapshot; no machine round-trip, so no config
	// gate — an unconfigured workstation simply has no sessions (404 below).
	snap, ok := s.cashChanger.Snapshot(r.PathValue("session"))
	if !ok {
		writeError(w, http.StatusNotFound, "session not found")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": snapshotJSON(snap)})
}

// handleCashChangerCancel asks the machine to return the deposited cash.
func (s *Server) handleCashChangerCancel(w http.ResponseWriter, r *http.Request) {
	session := r.PathValue("session")
	if err := s.cashChanger.CancelSession(r.Context(), session); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.auditLogPOS(r, "cash_changer.cancel", "session", session, "{}")
	writeJSON(w, http.StatusOK, map[string]any{"data": map[string]any{"session_id": session, "canceling": true}})
}

func snapshotJSON(snap service.SessionSnapshot) map[string]any {
	return map[string]any{
		"session_id": snap.SessionID,
		"order_id":   snap.OrderID,
		"running":    snap.Running,
		"status":     string(snap.Status),
		"payment_id": snap.PaymentID,
		"total":      snap.Total,
		"tendered":   snap.Tendered,
		"change":     snap.Change,
		"error":      snap.Error,
	}
}

// cashChangerServerID trả định danh máy 釣銭機 đang đăng ký, dùng cho actor
// audit và `metadata.server_id` của dòng tiền mặt (#2535 B9).
//
// Ưu tiên `metadata.server_id` (chính là `X-Server-Id` của adapter, tức thứ
// khớp được với sổ trong máy) rồi mới tới id hàng `peripheral_devices`. Rỗng là
// câu trả lời hợp lệ — máy trạm chạy bằng env fallback thì không có hàng nào để
// nêu tên, và một chuỗi bịa ra còn tệ hơn ô trống.
// cashChangerDeviceID trả UUID thiết bị trong registry — và CHỈ nó (#2878).
//
// Khác `cashChangerServerID` ngay ở dòng đầu: hàm kia ưu tiên
// `metadata.server_id`/`serial`, tức một chuỗi do người lắp máy đặt. Chuỗi đó
// đúng cho dòng audit tại chỗ nhưng Cloud khoá sổ theo `peripheral_devices.id`,
// nên quán nào có khai serial sẽ đẩy lên một khoá Cloud không tra được.
//
// Rỗng là câu trả lời ĐÚNG khi chưa đăng ký máy (chạy bằng env fallback) —
// đường đẩy tự bỏ qua những hàng như vậy thay vì bịa ra một UUID.
func (s *Server) cashChangerDeviceID() string {
	rows, err := s.db.Query(
		`SELECT id FROM peripheral_devices
		 WHERE type = 'coin_changer' AND is_active = 1
		 ORDER BY updated_at DESC`,
	)
	if err != nil {
		return ""
	}
	defer rows.Close()

	ids := make([]string, 0, 2)
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			continue
		}
		ids = append(ids, id)
	}

	switch len(ids) {
	case 0:
		// Chưa đăng ký máy (chạy bằng env fallback). Rỗng là câu trả lời ĐÚNG.
		return ""
	case 1:
		return ids[0]
	default:
		// #2881 — NHIỀU máy: phép tra này không phân biệt được "máy vừa chạy
		// lượt thu" với "một máy nào đó của quán", nên trả về bất kỳ cái nào là
		// ĐOÁN. Và đoán ở đây gán tiền cho sai máy trong im lặng.
		//
		// Thà không quy máy còn hơn quy nhầm: hàng phiên vẫn ghi (bán hàng
		// không dừng), chỉ là không đẩy lên Cloud được cho tới khi người vận
		// hành gỡ mập mờ. Đó là đánh đổi đúng chiều với một sổ TIỀN.
		if s.alerts != nil {
			s.alerts.Raise(service.KindCashDeviceAmbiguous, "cash_changer",
				"Có nhiều hơn một máy 釣銭機 đang bật — không quy được lượt thu về máy nào",
				map[string]any{"device_ids": ids, "count": len(ids)})
		}

		return ""
	}
}

func (s *Server) cashChangerServerID() string {
	if m := s.cashChangerMetadata(); m != nil {
		if id := metaFirstString(m, "server_id", "serverId", "serial", "serial_no"); id != "" {
			return id
		}
	}

	var id sql.NullString
	_ = s.db.QueryRow(
		`SELECT id FROM peripheral_devices
		 WHERE type = 'coin_changer' AND is_active = 1
		 ORDER BY updated_at DESC LIMIT 1`,
	).Scan(&id)

	return id.String
}
