/**
 * #3116 / #3118 — phép chiếu giữa DANH SÁCH RADIO người dùng thấy và MÁY
 * TRẠNG THÁI HAI TẦNG màn dine-in giữ.
 *
 * Vì sao tách khỏi `payment-view.tsx`: nó là luật, không phải cách vẽ. Nằm
 * trong một component 2600 dòng thì không có cách nào ghim, và test runner của
 * customer-web (`node --test 'lib/**' 'messages/**'`) không dựng DOM nên cũng
 * không với tới được. Tách ra đây là đường DUY NHẤT để những luật dưới đây có
 * rào — trước #3118 chúng hoàn toàn không có.
 *
 * Luật nền, đừng làm phẳng:
 *
 *   `method` (KÊNH: trực tuyến / tại quầy) × `onlineGateway` (CỔNG: paypay /
 *   stripe) giữ SỰ THẬT. Danh sách radio ba dòng chỉ là hình chiếu của cặp đó.
 *
 * #1303 tách hai tầng ra vì `method === "online"` từng mang hai nghĩa cùng lúc
 * ("trả online" và "trả bằng thẻ"). Một danh sách phẳng KHÔNG làm hai nghĩa ấy
 * gộp lại được — nó chỉ ghi cả hai tầng trong một cú bấm. Ai định gộp state cho
 * khớp UI thì đọc lại #1303 trước.
 */

/** Ba dòng người dùng thấy. */
export type PaymentOption = "card" | "qr_pay" | "counter";

/** Tầng 1 — kênh. */
export type PaymentChannel = "online" | "counter";

/** Tầng 2 — cổng, chỉ có nghĩa khi kênh là `online`. */
export type OnlineGateway = "paypay" | "stripe";

/** Cặp state thật mà một dòng radio ghi xuống. */
export type PaymentState = {
  method: PaymentChannel;
  /** `null` = không đụng tới tầng cổng (chọn "tại quầy" không nói gì về cổng). */
  gateway: OnlineGateway | null;
};

/**
 * Chiều XUÔI: cặp state → dòng đang sáng.
 *
 * `counter` thắng trước, vì khi kênh là "tại quầy" thì cổng đang giữ giá trị cũ
 * và giá trị đó KHÔNG có nghĩa gì cả. Đọc cổng trước sẽ làm dòng PayPay sáng
 * lên trong lúc khách đã chọn trả tại quầy.
 */
export function paymentOptionFrom(
  method: PaymentChannel,
  activeGateway: OnlineGateway,
): PaymentOption {
  if (method === "counter") return "counter";
  return activeGateway === "paypay" ? "qr_pay" : "card";
}

/**
 * Chiều NGƯỢC: dòng được bấm → cặp state phải ghi.
 *
 * "counter" trả `gateway: null` chứ không trả một cổng mặc định: ghi đè cổng ở
 * đây sẽ âm thầm đổi lựa chọn cổng của khách khi họ chỉ ghé qua "tại quầy" rồi
 * quay lại.
 */
export function paymentStateFor(option: PaymentOption): PaymentState {
  if (option === "counter") return { method: "counter", gateway: null };
  return { method: "online", gateway: option === "qr_pay" ? "paypay" : "stripe" };
}

/**
 * Đã mint mã PayPay thì KHOÁ lựa chọn.
 *
 * Mã đã sống ở phía PayPay. Rời khỏi nó im lặng là để lại một mã quét được mà
 * trên màn không còn gì theo dõi — khách quét, tiền đi, và màn hình đang nói về
 * một phương thức khác. Đường ra là bấm huỷ tường minh.
 */
export function isPaymentChoiceLocked(paypayMint: unknown): boolean {
  return paypayMint !== null && paypayMint !== undefined;
}

/**
 * Bấm vào dòng này có ăn không.
 *
 * Đang khoá thì chỉ dòng ĐANG chọn còn bấm được (bấm lại chính nó là no-op vô
 * hại, và chặn nó sẽ làm ô trông như hỏng).
 */
export function canSelectPaymentOption(
  next: PaymentOption,
  current: PaymentOption,
  locked: boolean,
): boolean {
  return !locked || next === current;
}

/**
 * Dòng PayPay có được vẽ không.
 *
 * Điều kiện ĐỘC LẬP với kênh đang chọn — khác hẳn hàng tab cũ (`showTabs` tắt
 * khi khách đang ở "tại quầy"). Một danh sách mà các dòng biến mất khi ta di
 * chuyển giữa chúng thì không phải danh sách.
 *
 * Mã đã mint giữ dòng sống kể cả khi năng lực chi nhánh sau đó nói không — cùng
 * lý do `showQrPanel` xếp trên `paypayEnabled`: mã đã phát ra ngoài rồi thì
 * không được phép biến mất khỏi màn.
 */
export function payPayRowShown(paypayQrEnabled: boolean, paypayMint: unknown): boolean {
  return paypayQrEnabled || isPaymentChoiceLocked(paypayMint);
}

/**
 * #3118 — id của phần tử mang NHÃN của dòng, để `aria-labelledby` trỏ vào.
 *
 * Bắt buộc, không phải trang trí: `RadioGroupItem` của Base UI render ra
 * `<span role="radio">` (xem `node_modules/@base-ui/react/radio/root/RadioRoot.js`).
 * `<span>` KHÔNG phải phần tử labelable, nên bọc `<label>` quanh nó vừa không
 * chuyển tiếp cú bấm, vừa không cấp tên cho nó. Không có `aria-labelledby` thì
 * trình đọc màn hình đọc ra ba radio VÔ DANH — trên đúng màn chọn cách trả
 * tiền. WCAG 2.1 SC 4.1.2 (Name, Role, Value).
 */
export function paymentOptionLabelId(option: PaymentOption): string {
  return `payment-option-label-${option}`;
}
