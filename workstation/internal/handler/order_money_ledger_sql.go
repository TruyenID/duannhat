package handler

// #2140 — MỘT chỗ duy nhất phát biểu quy ước "sổ `order_conditions` thắng cột".
//
// Có HAI hình dạng đọc tiền từ sổ, và chúng khác nhau thật sự:
//
//   - **per-order** — `deriveOrderMoney` (#2075): một đơn, một query, dùng khi
//     dựng phiếu / chia bill;
//   - **theo TẬP** — file này: `SUM` qua toàn bộ đơn đã thanh toán trong cửa sổ
//     ca, dùng cho 精算 và ảnh chụp đối soát.
//
// Không gộp được thành một hàm: gọi bản per-order trong vòng lặp để thay một câu
// `SUM` là **N+1 query trên đường đóng ca**. Nhưng hai đường PHẢI đồng ý về
// QUY ƯỚC, và quy ước ấy có hai cái bẫy đã trả giá:
//
//  1. **Dấu.** Dòng `discount` trong sổ mang giá trị ÂM; cột `orders.discount_amount`
//     mang giá trị DƯƠNG. Cộng thẳng là ra số âm ở chỗ lẽ ra dương.
//  2. **"Sổ tồn tại" = CÓ DÒNG, không phải "tổng khác 0".** Một đơn hợp lệ có thể
//     có tổng thuế đúng bằng 0 (toàn bộ hàng 非課税). Đọc "tổng = 0" thành "sổ
//     chưa có dữ liệu" rồi rơi về cột chính là lỗi #2074 ở phía Cloud.
//
// Nên quy ước sống ở đây, có tên, thay vì bị chép lại vào từng câu SQL.
//
// Khi PR ws#236 (`deriveOrderMoney`) merge, bước tiếp theo là cho nó dùng chung
// hằng số ở đây thay vì tự khai lại — xem #2123.

// orderLedgerTypes là ba loại dòng sổ mô tả tiền ở MỨC ĐƠN.
//
// `refund` cố ý KHÔNG có mặt: nó là dòng mức ITEM (`conditionable_type =
// 'order_item'`), và tiền hoàn đã nằm trong `orders.total_amount` rồi — cộng
// thêm ở đây là trừ hai lần.
const orderLedgerTypes = `'tax','discount','service_charge'`

// orderLedgerJoinSQL là subquery gom sổ theo từng đơn, để `LEFT JOIN` vào
// `orders o`. Alias cố định `lc`.
//
// `rows_seen` là tiêu chí "sổ tồn tại" — ĐẾM DÒNG, không phải tổng tiền. Xem
// bẫy (2) ở docblock trên.
const orderLedgerJoinSQL = `
	LEFT JOIN (
		SELECT conditionable_id AS order_id,
		       SUM(CASE WHEN type = 'tax'            THEN amount      ELSE 0 END) AS tax,
		       SUM(CASE WHEN type = 'discount'       THEN ABS(amount) ELSE 0 END) AS discount,
		       SUM(CASE WHEN type = 'service_charge' THEN amount      ELSE 0 END) AS service_charge,
		       COUNT(*) AS rows_seen
		FROM order_conditions
		WHERE conditionable_type = 'order'
		  AND type IN (` + orderLedgerTypes + `)
		GROUP BY conditionable_id
	) lc ON lc.order_id = o.id`

// orderLedgerValueSQL trả về biểu thức chọn giữa sổ và cột cho MỘT đơn.
//
// `ledgerCol` là cột trong subquery (`tax` / `discount` / `service_charge`);
// `fallbackCol` là cột trên `orders` dùng khi đơn chưa có dòng sổ nào.
//
// Chọn theo `rows_seen` của CẢ ĐƠN, không theo từng loại: khi plan-045 ghi sổ
// cho một đơn thì nó ghi đủ, nên "có dòng nào" là dấu hiệu đúng cho cả ba con
// số. Đây cũng chính là điều kiện `seen == 0` mà bản per-order dùng — hai đường
// phải cùng một câu trả lời cho cùng một đơn.
func orderLedgerValueSQL(ledgerCol, fallbackCol string) string {
	return `CASE WHEN COALESCE(lc.rows_seen, 0) > 0
	             THEN COALESCE(lc.` + ledgerCol + `, 0)
	             ELSE ` + fallbackCol + ` END`
}
