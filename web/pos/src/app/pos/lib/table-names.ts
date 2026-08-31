/**
 * Cách viết tên bàn của một đơn — MỘT chỗ, cho mọi màn.
 *
 * ## Vì sao `+` chứ không phải `,`
 *
 * Đơn nhiều bàn là đơn **GỘP BÀN**: một hoá đơn trải trên nhiều bàn đã ghép lại
 * với nhau. Dấu phẩy đọc ra một DANH SÁCH (bốn thứ rời nhau); dấu cộng đọc ra
 * một PHÉP GỘP (bốn bàn thành một đơn). Thu ngân liếc dải tab và cần thấy ngay
 * đây là một đơn, không phải bốn.
 *
 * ## Vì sao SẮP XẾP
 *
 * Hai nguồn cấp tên bàn cho cùng một đơn: quan hệ `order.tables` (khi chi tiết
 * đã vào cache) và feed bàn qua `current_order_id` (khi chưa). Chúng KHÔNG cùng
 * thứ tự — cái đầu theo thứ tự server trả, cái sau theo thứ tự feed. Không sắp
 * thì nhãn **xáo lại ngay trước mắt** khi thu ngân bấm vào tab: "A-3 + A-6 +
 * A-5 + A-4" thành "A-3 + A-4 + A-5 + A-6". Sắp một lần ở đây làm hai nguồn
 * không thể nói khác nhau.
 *
 * `localeCompare` với `numeric` để "A-2" đứng trước "A-10" — so sánh chuỗi trần
 * xếp "A-10" trước "A-2", đúng kiểu sai mà chỉ quán có trên 9 bàn mỗi khu mới
 * gặp.
 */
export const TABLE_NAME_SEPARATOR = " + ";

/** Tên một bàn như thu ngân gọi nó: tên riêng nếu có, không thì mã bàn. */
export function tableDisplayName(
  table: { name?: string | null; code?: string | null } | null | undefined,
): string {
  return (table?.name ?? "").trim() || (table?.code ?? "").trim();
}

/**
 * Gộp tên nhiều bàn thành nhãn của một đơn. Bỏ tên rỗng, sắp ổn định, nối bằng
 * {@link TABLE_NAME_SEPARATOR}. Không có bàn nào ⇒ chuỗi rỗng.
 */
export function joinTableNames(
  tables: readonly ({ name?: string | null; code?: string | null } | null | undefined)[]
    | null
    | undefined,
): string {
  return (tables ?? [])
    .map(tableDisplayName)
    .filter(Boolean)
    .sort((a, b) => a.localeCompare(b, undefined, { numeric: true }))
    .join(TABLE_NAME_SEPARATOR);
}
