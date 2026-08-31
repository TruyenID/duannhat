package handler

import "database/sql"

// #2063 — đơn này có ĐANG TREO tiền không, tức có bị cấm in biên lai / hoá đơn
// đỏ không.
//
// # Luật hợp thành, và vì sao nó phải là HOẶC
//
//	treo = Cloud nói treo  HOẶC  có nợ ghi sổ LOCAL mà Cloud CHƯA thấy
//
// Vế đầu là nguồn có thẩm quyền. Máy trạm KHÔNG tự tính được toàn bộ, và đó là
// bẫy số 1 của issue: khoản thu nợ cưỡi trên MỘT ĐƠN KHÁC (#821 A4), chỉ trỏ
// ngược về khoản nợ bằng `metadata.settles_payment_id`. Bản sao local của đơn
// kia chỉ có `cloud_payment_summary` — danh sách nhãn phương thức, không có
// metadata. Nên tự tính thì chỉ BẬT được cờ, không bao giờ TẮT: một đơn khách
// đã trả nợ xong sẽ vĩnh viễn không in được hoá đơn.
//
// Vế hai hẹp ĐÚNG BẰNG cửa sổ tụt hậu: một khoản ghi nợ vừa thu ở quầy, chưa
// kịp sync UP, nên Cloud chưa thể biết. `cloud_id` rỗng là dấu duy nhất phân
// biệt "Cloud chưa thấy" với "Cloud thấy rồi và đã trả lời". Khi payment sync
// xong, `cloud_id` được ghi lại và vế này TỰ TẮT — không cần ai dọn.
//
// # Ba trạng thái của cột, không phải hai
//
// `orders.is_on_hold` NULL nghĩa là Cloud CHƯA nói (đơn chưa qua đường đọc có
// đóng dấu). Đọc NULL thành "không treo" là bẫy số 2 mang xuống tầng này: mọi
// endpoint GHI của Cloud trả order về mà không kèm cờ.
//
// Với một đơn Cloud chưa nói gì, câu trả lời đến hoàn toàn từ vế hai — đúng, vì
// nếu có nợ local chưa sync thì chắc chắn đang treo, còn nếu không có thì máy
// trạm không có căn cứ nào để chặn.
func (s *Server) orderIsOnHold(orderID string) bool {
	if s.db == nil || orderID == "" {
		return false
	}

	var cloudFlag sql.NullInt64
	if err := s.db.QueryRow(
		`SELECT is_on_hold FROM orders WHERE id = ?`, orderID,
	).Scan(&cloudFlag); err != nil {
		// Không đọc được KHÁC đọc ra "không treo". Nhưng ở đây fail-OPEN là
		// đúng chiều: một lỗi DB không được biến thành "cấm in mọi chứng từ
		// tiền của cả quán". Vế hai bên dưới vẫn chạy và vẫn chặn được ca thật
		// sự nguy hiểm (nợ vừa ghi, chưa sync).
		cloudFlag = sql.NullInt64{}
	}

	if cloudFlag.Valid && cloudFlag.Int64 != 0 {
		return true
	}

	// Cloud nói KHÔNG treo (0) thì vế hai vẫn phải chạy: giữa lúc Cloud trả lời
	// và lúc này, thu ngân có thể vừa ghi một khoản nợ mới chưa sync.
	return s.hasUnsyncedLocalDebt(orderID)
}

// Có khoản ghi nợ nào của đơn này còn nằm local mà Cloud chưa thấy không.
//
// `cloud_id IS NULL OR ”` là toàn bộ định nghĩa "Cloud chưa thấy". Dùng chính
// phép nối `payments → payment_methods.type = 'on_account'` mà đường in phiếu
// ghi nợ đang dùng (`lan_print.go`), để hai chỗ không trôi khỏi nhau về việc
// "thế nào là một khoản ghi nợ".
func (s *Server) hasUnsyncedLocalDebt(orderID string) bool {
	var n int
	if err := s.db.QueryRow(
		`SELECT COUNT(1)
		   FROM payments p
		   LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
		  WHERE p.order_id = ?
		    AND COALESCE(pm.type, '') = 'on_account'
		    AND p.status IN ('succeeded', 'confirmed')
		    AND COALESCE(p.cloud_id, '') = ''`,
		orderID,
	).Scan(&n); err != nil {
		return false
	}

	return n > 0
}
