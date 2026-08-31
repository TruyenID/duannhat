package handler

import (
	"database/sql"
	"log"
	"math"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// orderMoney là bộ ba số tiền dẫn xuất của một đơn — đọc từ SỔ khi sổ có, rơi
// về ba cột khi chưa.
type orderMoney struct {
	// Tax là float64 theo đúng quy ước của `service.Order.TaxAmount`: thuế mang
	// độ chính xác dưới đơn vị có chủ đích (option-B). Ép về int ở đây là âm
	// thầm bỏ quyết định đó.
	Tax           float64
	Discount      int
	ServiceCharge int

	// FromLedger nói con số đến từ đâu. Không phải để trang trí: nó là thứ
	// duy nhất phân biệt "sổ nói 0" với "sổ không có gì" ở mọi chỗ gọi, và là
	// thứ test khẳng định được.
	FromLedger bool
}

// deriveOrderMoney đọc `order_conditions` của một đơn và cộng thành tax /
// discount / service_charge, rơi về ba cột `orders.*` khi sổ chưa có dòng nào.
//
// # Vì sao hàm này tồn tại (#2075)
//
// Máy trạm ĐÃ ghi sổ (#2032) và ĐÃ phơi `conditions[]` ra LAN, nhưng **không nơi
// nào đọc sổ để tính tiền** — mọi con số vẫn chảy qua ba cột
// `orders.tax_amount` / `discount_amount` / `service_charge`. Nên tới hôm nay sổ
// ở máy trạm là dữ liệu chỉ để hiển thị, chưa phải nguồn.
//
// Đó là điều kiện tiên quyết của #2041 bước 3 (Cloud xoá ba cột). Chuỗi hỏng nếu
// làm ngược thứ tự:
//
//  1. Cloud xoá cột ⇒ payload đồng bộ không còn ba trường đó;
//  2. máy trạm bản cũ đọc payload thiếu trường ⇒ số về 0;
//  3. **0 tiền thuế, im lặng, không lỗi nào** — và ở màn chia bill nó còn tệ
//     hơn: khoảng lệch đúng bằng số thuế bị render thành dòng 端数調整, tức sai
//     lệch được nguỵ trang thành làm tròn.
//
// Nên máy trạm phải biết đọc sổ TRƯỚC khi Cloud ngừng gửi cột.
//
// # Vì sao có fallback, và vì sao fallback KHÔNG phải chỗ ẩn lỗi
//
// Fallback cho phép triển khai lệch pha: một máy ngoài thực địa chạy bản cũ, một
// đơn ghi trước #2032, hoặc một đơn vừa tạo chưa kịp ghi sổ — cả ba đều còn cột.
// Nhưng fallback im lặng chính là chế độ lỗi mà bài này chữa, nên nó KHÔNG im:
// `FromLedger` đi kèm kết quả, và lỗi truy vấn được log thay vì trả 0.
//
// # Dấu hiệu "sổ có tồn tại"
//
// Là **có dòng hay không**, không phải "tổng có khác 0 hay không". Một đơn giảm
// giá phủ hết giỏ có `tax = 0` hợp lệ; đọc tổng-bằng-0 thành "sổ trống" là đúng
// lỗi mà Cloud vừa phải sửa ở #2074.
//
// Chỉ cộng dòng `conditionable_type = 'order'`: các dòng dẫn xuất luôn được
// `writeOrderConditionsTx` ghi ở cấp đơn, còn `refund` là dòng cấp MÓN và mang
// `type` khác — nên không có đường nào cộng trùng.
func (s *Server) deriveOrderMoney(order *service.Order) orderMoney {
	fallback := orderMoney{
		Tax:           order.TaxAmount,
		Discount:      order.DiscountAmount,
		ServiceCharge: order.ServiceCharge,
	}

	rows, err := s.db.Query(`
		SELECT type, SUM(amount)
		FROM order_conditions
		WHERE conditionable_type = 'order'
		  AND conditionable_id = ?
		  AND type IN ('tax', 'discount', 'service_charge')
		GROUP BY type`, order.ID)
	if err != nil {
		// Sổ KHÔNG ĐỌC ĐƯỢC và sổ TRỐNG trông giống hệt nhau ở đầu ra — đúng
		// chế độ lỗi mà `loadOrderConditions` đã phải log lại (#2032).
		log.Printf("[order-money] deriveOrderMoney order=%s: %v", order.ID, err)

		return fallback
	}
	defer rows.Close()

	out := orderMoney{}
	seen := 0

	for rows.Next() {
		var typ string
		var amount float64
		if err := rows.Scan(&typ, &amount); err != nil {
			continue
		}
		seen++

		switch typ {
		case "tax":
			out.Tax = amount
		case "discount":
			// Dòng `discount` mang dấu ÂM trong sổ (khoản trừ), còn cột
			// `orders.discount_amount` là số DƯƠNG. Chỗ gọi trừ đi cột ấy, nên
			// phải trả về cùng quy ước dương — đảo dấu ở đây, một lần.
			out.Discount = roundToInt(math.Abs(amount))
		case "service_charge":
			out.ServiceCharge = roundToInt(amount)
		}
	}
	if err := rows.Err(); err != nil {
		log.Printf("[order-money] deriveOrderMoney order=%s rows: %v", order.ID, err)

		return fallback
	}

	if seen == 0 {
		return fallback
	}

	out.FromLedger = true

	return out
}

// loadOrderDiscountLines đọc CÁC DÒNG `discount` của một đơn từ sổ
// `order_conditions` — mỗi dòng một nhóm mức (#2031), cho khối `discounts` của
// phiếu bill (#2071). Khác `deriveOrderMoney` ở chỗ nó trả TỪNG DÒNG chứ không
// cộng: tầng in phải in đúng phép chia theo mức mà sổ đã ghi, một con số tổng
// không tách lại được.
//
// KHÔNG có fallback về cột `orders.discount_amount`, có chủ đích: cột giữ số
// YÊU CẦU, sổ giữ số ĐÃ ÁP DỤNG (#2031) — sổ trống thì phiếu không in dòng
// giảm giá nào, thay vì in một con số thuộc quy ước khác. Thứ tự dòng cố định
// (rate tăng dần, dòng không-rate cuối) để hai lượt in cùng một đơn ra cùng
// một tờ giấy.
//
// Số tiền làm tròn half-away-from-zero về đơn vị nhỏ nhất, GIỮ DẤU — dòng sổ
// mang giá trị âm và tầng in in nguyên văn.
func (s *Server) loadOrderDiscountLines(orderID string) []service.OrderDiscountLine {
	rows, err := s.db.Query(`
		SELECT rate, amount
		FROM order_conditions
		WHERE conditionable_type = 'order'
		  AND conditionable_id = ?
		  AND type = 'discount'
		ORDER BY (rate IS NULL), rate`, orderID)
	if err != nil {
		// Sổ không đọc được ≠ sổ trống, nhưng ở tầng in cả hai cùng một đầu ra
		// (không in khối) — log là dấu vết duy nhất, như deriveOrderMoney.
		log.Printf("[order-money] loadOrderDiscountLines order=%s: %v", orderID, err)

		return nil
	}
	defer rows.Close()

	var out []service.OrderDiscountLine
	for rows.Next() {
		var rate sql.NullFloat64
		var amount float64
		if err := rows.Scan(&rate, &amount); err != nil {
			continue
		}
		line := service.OrderDiscountLine{Amount: roundToInt(amount)}
		if rate.Valid {
			r := rate.Float64
			line.Rate = &r
		}
		out = append(out, line)
	}
	if err := rows.Err(); err != nil {
		log.Printf("[order-money] loadOrderDiscountLines order=%s rows: %v", orderID, err)

		return nil
	}

	return out
}

// roundToInt — sổ lưu số thực, tiền ở đây là số nguyên đơn vị nhỏ nhất.
// Half-away-from-zero để một khoản trừ không bao giờ co lại vì làm tròn.
func roundToInt(v float64) int {
	if v < 0 {
		return -int(math.Floor(-v + 0.5))
	}

	return int(math.Floor(v + 0.5))
}
