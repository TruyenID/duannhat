package handler

import (
	"database/sql"
	"net/http"
	"strconv"
	"strings"
)

// handleLocalPosTillSessionIndex — danh sách ca của quầy, mới nhất trước (#3062).
//
// # Vì sao endpoint này tồn tại
//
// Phiếu 精算 vốn là **hiệu ứng phụ bắn một lần** của việc chốt ca: pos-web gọi
// in ngay sau khi settle, và nếu lượt đó hỏng thì tờ giấy mất vĩnh viễn — bấm
// chốt lại chỉ nhận 409 `SHIFT_ALREADY_FINALIZED`.
//
// Đo được ở 本郷店 ngày 2026-08-16: máy in hoá đơn offline từ 20:00 JST, ca chốt
// lúc 21:50 — offline gần hai tiếng, và tờ 精算 của ca đó không bao giờ ra.
//
// Khả năng in lại thì máy trạm CÓ SẴN: `handleLANPrintShiftReport` không kiểm
// trạng thái ca, nó in được một ca đã `settled`. Thứ thiếu là **đường đi tới
// nó** — không đầu nào (máy trạm lẫn Cloud) trả về danh sách ca, nên pos-web
// không có gì để hiện ra cho người ta bấm.
//
// # Đọc từ bản LOCAL, không proxy Cloud
//
// Có chủ đích. Ca cần in lại nhất là ca vừa hỏng, và lý do hỏng thường là mạng
// hoặc máy — đúng lúc Cloud có thể không với tới. Một trang lịch sử chỉ chạy
// khi có mạng là trang vắng mặt đúng lúc cần.
//
// # Chỉ ĐỌC
//
// Không đổi gì trong sổ. `settlement_snapshot` là ảnh chụp bất biến (plan-046
// R7) và mọi con số đối soát đọc từ đó; in lại là ra giấy, không phải tính lại.
func (s *Server) handleLocalPosTillSessionIndex(w http.ResponseWriter, r *http.Request) {
	till, err := s.loadTill()
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if till == nil {
		// Chưa ghép quầy thì không có ca nào để kể — danh sách rỗng, không phải
		// lỗi. 404 ở đây sẽ làm màn hình hiện "hỏng" cho một trạng thái bình
		// thường của máy chưa cấu hình.
		writeJSON(w, http.StatusOK, map[string]any{"data": []any{}})
		return
	}
	tillID, _ := till["id"].(string)

	// Trần mặc định 30: danh sách này để TÌM một ca vừa chốt, không phải để
	// duyệt lịch sử kế toán. Kéo cả nghìn hàng vào một tablet quầy là đổi một
	// nhu cầu không có lấy một màn hình chậm.
	limit := 30
	if v := strings.TrimSpace(r.URL.Query().Get("limit")); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 && n <= 200 {
			limit = n
		}
	}

	q := `SELECT id, session_code, status, business_date, default_currency_code,
	             opening_float_amount, counted_cash, cash_variance,
	             opened_at, closed_at, opener_name,
	             chain_id, chain_sequence, settlement_kind
	      FROM till_sessions
	      WHERE till_id = ?`
	args := []any{tillID}

	// Lọc theo ngày nghiệp vụ, KHÔNG theo `opened_at`. Ca đêm mở 23:50 và đóng
	// 02:10 thuộc về ngày hôm trước — đó là điều `business_date` nói, và là
	// điều nhân viên nghĩ khi họ nói "ca hôm qua" (#1091).
	if v := strings.TrimSpace(r.URL.Query().Get("business_date_from")); v != "" {
		q += " AND business_date >= ?"
		args = append(args, v)
	}
	if v := strings.TrimSpace(r.URL.Query().Get("business_date_to")); v != "" {
		q += " AND business_date <= ?"
		args = append(args, v)
	}

	q += " ORDER BY opened_at DESC LIMIT ?"
	args = append(args, limit)

	rows, err := s.db.Query(q, args...)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	out := []map[string]any{}
	for rows.Next() {
		var id, code, status, businessDate, currency, openedAt string
		var openingFloat, countedCash, cashVariance sql.NullFloat64
		var closedAt, openerName, chainID, settlementKind sql.NullString
		var chainSeq sql.NullInt64

		if err := rows.Scan(&id, &code, &status, &businessDate, &currency,
			&openingFloat, &countedCash, &cashVariance,
			&openedAt, &closedAt, &openerName,
			&chainID, &chainSeq, &settlementKind); err != nil {
			writeServerError(w, r, err)
			return
		}

		out = append(out, map[string]any{
			"id":                    id,
			"session_code":          code,
			"status":                status,
			"business_date":         businessDate,
			"default_currency_code": currency,
			"opening_float_amount":  nullableFloatValue(openingFloat),
			"counted_cash":          nullableFloatValue(countedCash),
			"cash_variance":         nullableFloatValue(cashVariance),
			"opened_at":             openedAt,
			"closed_at":             nullableStringValue(closedAt),
			"opener_name":           nullableStringValue(openerName),
			"chain_id":              nullableStringValue(chainID),
			// `chain_sequence` NULL nghĩa là ca không thuộc chuỗi nào — khác hẳn
			// "thuộc chuỗi ở vị trí 0". Trả `nil` để màn hình phân biệt được;
			// ép về 0 là dựng ra một vị trí không tồn tại.
			"chain_sequence": func() any {
				if chainSeq.Valid {
					return chainSeq.Int64
				}

				return nil
			}(),
			"settlement_kind": nullableStringValue(settlementKind),
		})
	}
	if err := rows.Err(); err != nil {
		writeServerError(w, r, err)
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": out})
}
