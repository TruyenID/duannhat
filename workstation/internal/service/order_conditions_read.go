package service

import (
	"database/sql"
	"log"
	"math"
)

// #2170 (tầng 2 của #2106) — đường ĐỌC của sổ `order_conditions` cho tầng in.
//
// Người ghi (`writeOrderConditionsTx`, order_conditions.go — cùng package) đã
// ghi mỗi mức thuế MỘT dòng `type='tax'` mang đúng ba trường mà một khối thuế
// trên phiếu cần:
//
//	rate · taxable_base · amount  →  receiptTaxBlock{Rate, Taxable, Tax}
//
// Đọc lại thay vì tính lại là toàn bộ điểm của #2170: dòng sổ là kết quả của
// `priceGroups` với ĐỦ giảm giá + phí dịch vụ (gap #7 gộp thuế phí dịch vụ vào
// nhóm cùng mức), nên nó là con số Cloud in trên phiếu CloudPRNT — còn phép
// tính cũ của `buildReceiptTaxSummary` ép discount/sc về 0 và lệch khỏi sổ
// trên mọi đơn có khuyến mãi.

// OrderTaxLine is one ledger tax row (`order_conditions`, `type='tax'`) as the
// print layer sees it (#2170): the applied per-rate breakdown, verbatim from
// the ledger, rounded to minor units the same way Cloud's renderer does
// (`ReceiptTaxSummary::fromBreakdown` uses PHP `(int) round`, half away from
// zero — `math.Round` is the same rule). The service-charge tax is already
// merged into the group of its rate by the pricing engine (gap #7), so these
// rows cover the order's ENTIRE consideration: Σ(Taxable+Tax) is the gross
// total, post-discount.
type OrderTaxLine struct {
	Rate    float64 `json:"rate"`
	Taxable int     `json:"taxable"`
	Tax     int     `json:"tax"`
}

// OrderTaxLines đọc các dòng `tax` của một đơn từ sổ `order_conditions` —
// nguồn cho khối thuế theo mức trên phiếu (#2170). Trả nil khi sổ trống (đơn
// cũ / đơn offline chưa được định giá): tầng in khi đó RƠI VỀ phép tính hiện
// tại thay vì 500 hay in bảng rỗng — xem `buildReceiptTaxSummary`.
//
// Thứ tự dòng cố định (rate tăng dần) để hai lượt in cùng một đơn ra cùng một
// tờ giấy, khớp `sort` mà nhánh tính cũng làm.
func (e *OrderEngine) OrderTaxLines(orderID string) []OrderTaxLine {
	rows, err := e.db.Query(`
		SELECT rate, taxable_base, amount
		FROM order_conditions
		WHERE conditionable_type = 'order'
		  AND conditionable_id = ?
		  AND type = 'tax'
		ORDER BY rate`, orderID)
	if err != nil {
		// Sổ không đọc được ≠ sổ trống, nhưng ở tầng in cả hai cùng một đầu ra
		// (rơi về phép tính) — log là dấu vết duy nhất, như loadOrderDiscountLines.
		log.Printf("[order-money] OrderTaxLines order=%s: %v", orderID, err)

		return nil
	}
	defer rows.Close()

	var out []OrderTaxLine
	for rows.Next() {
		var rate, taxable sql.NullFloat64
		var amount float64
		if err := rows.Scan(&rate, &taxable, &amount); err != nil {
			continue
		}
		line := OrderTaxLine{Tax: int(math.Round(amount))}
		if rate.Valid {
			line.Rate = rate.Float64
		}
		if taxable.Valid {
			line.Taxable = int(math.Round(taxable.Float64))
		}
		out = append(out, line)
	}
	if err := rows.Err(); err != nil {
		log.Printf("[order-money] OrderTaxLines order=%s rows: %v", orderID, err)

		return nil
	}

	return out
}
