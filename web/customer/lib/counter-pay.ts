/**
 * "Thanh toán tại quầy" — **quán quyết định, không phải mã nguồn** (#2806).
 *
 * Một chỗ duy nhất trả lời "có được chào trả tại quầy không", để bốn màn
 * (checkout desktop, checkout mobile, dine-in payment-view, /orders/{id}/pay)
 * không trôi khỏi nhau.
 *
 * ## Vì sao câu trả lời chuyển vào cài đặt cửa hàng
 *
 * Luật này đã lật BA lần, và mỗi lần đều phải sửa mã vì câu trả lời được **suy
 * ra** chứ không được **lưu**:
 *
 * 1. Ban đầu counter chào ngang hàng với thẻ và PayPay; ở takeaway còn là mặc
 *    định.
 * 2. **#2545** — chủ dự án chốt khách không được CHỌN trả tại quầy nữa, nên nó
 *    chỉ còn hiện khi chi nhánh không có cổng online nào dùng được: một lối
 *    thoát, không phải một lựa chọn.
 * 3. Chủ dự án chốt lại — counter hiện trên mọi chi nhánh.
 *
 * Lần thứ ba suýt được trả lời bằng `return true` ghim cứng (#2797). Đó vẫn là
 * đúng cái sai của hai lần trước, chỉ đổi chiều: quán không đụng vào được, và
 * lần lật thứ tư lại là một PR nữa.
 *
 * Nay `shop_order_settings.counter_pay_enabled` giữ câu trả lời, mặc định
 * `true`, và tới đây qua `payment-context`.
 *
 * ## Ba luật cũ — hai cái CHẾT theo #2545, một cái còn sống
 *
 * Cả ba đều là hệ quả của việc ĐỌC TRẠNG THÁI CỔNG, nên khi câu trả lời thôi
 * phụ thuộc vào cổng thì chúng hết áp dụng:
 *
 * 1. ~~Đang dò thì không chào counter~~ — cờ này không phải là suy diễn từ
 *    probe, nó mang mặc định ngay từ render đầu, nên không có khoảnh khắc
 *    "chưa biết" để nháy radio lên rồi rút đi.
 * 2. ~~`paypayEnabled === false` gộp hai nghĩa~~ — không còn ai hỏi nó ở đây.
 * 3. **CÒN SỐNG: hàm này KHÔNG biết gì về mức tối thiểu của Stripe.**
 *    `/orders/{id}/pay` có đường lùi riêng (đơn dưới ¥50 thì thẻ bị khoá) rẽ về
 *    counter NGAY CẢ KHI PayPay bật — cố ý, vì tự chọn PayPay sẽ mint một QR
 *    thật cho người chưa hề yêu cầu. Nó nằm ở call-site, có chú thích tại chỗ,
 *    và nay phải OR với cờ quán: quán tắt counter thì đường lùi đó không còn
 *    đích, xem chú thích ở chính call-site ấy.
 *
 * `CounterPayAvailabilityInput` vẫn còn vì `defaultPaymentMethod` bên dưới cần
 * nó — chỗ nào CHÀO counter và chỗ nào CHỌN SẴN counter là hai câu hỏi khác
 * nhau, và câu thứ hai vẫn phải đọc trạng thái cổng.
 */

import type { CounterPaySettings } from "@/lib/paypay-qr";

export type { CounterPaySettings };

export interface CounterPayAvailabilityInput {
  /** Stripe đã trả về `publishable_key` không rỗng chưa. */
  stripeReady: boolean;
  /** `useStripeConfig().loading` — còn đang hỏi `/stripe/config`. */
  stripeLoading: boolean;
  /** `usePayPayAvailability().paypayEnabled` — chỉ `true` khi server nói vậy. */
  paypayEnabled: boolean;
  /** `usePayPayAvailability().loading` — còn đang dò `payment-context`. */
  paypayLoading: boolean;
}

/**
 * Có được chào "thanh toán tại quầy" trên màn này không?
 *
 * Đúng bằng cờ của chi nhánh — không suy diễn thêm gì. Giữ nguyên dạng hàm
 * (thay vì đọc thẳng cờ ở bốn call-site) vì đây là chỗ duy nhất ghi lại luật,
 * và luật này đã lật ba lần.
 */
export function shouldOfferCounterPay(counter: CounterPaySettings): boolean {
  return counter.counterPayEnabled;
}

/**
 * Có hiện mã QR cho kiosk quét trên màn trả tại quầy không?
 *
 * Hai câu hỏi tách rời có chủ đích: quán muốn nhân viên tra đơn bằng mã `#xxxx`
 * đọc lên thì tắt riêng QR, kênh trả tại quầy vẫn còn.
 *
 * **Tắt QR KHÔNG được kéo theo việc gỡ payload đằng sau nó.**
 * `counterPartialAmount` / `counterItems` ở `payment-view.tsx` mã hoá lựa chọn
 * chia bill của khách (số tiền, và số suất từng món mà kiosk chuyển tiếp thành
 * `metadata.item_allocations`); đó là đường DUY NHẤT chở những con số ấy sang
 * kiosk. Gỡ đi là mất tính năng — quán bật lại QR sẽ thấy nó chia sai.
 */
export function shouldShowCounterPayQr(counter: CounterPaySettings): boolean {
  return counter.counterPayEnabled && counter.counterPayShowQr;
}

/** Các `payment_method` nghĩa là "nhân viên thu tại quầy", không phải cổng online. */
const COUNTER_METHODS = new Set(["counter", "call_staff"]);

/**
 * Phương thức được chọn sẵn khi khách CHƯA bấm gì.
 *
 * Phải dẫn xuất, không được ghim cứng. Radio `card` render vô điều kiện còn
 * `counter` thì có điều kiện, nên một mặc định `"card"` cố định sẽ thả khách
 * xuống đúng ô hỏng ở hai loại chi nhánh:
 *
 *   - chỉ có PayPay  → `card` được chọn nhưng `stripeNotConfigured` đỏ ngay
 *     dưới nó, trong khi `qr_pay` dùng được;
 *   - KHÔNG cổng nào → `card` vẫn được chọn, còn `counter` — lựa chọn duy nhất
 *     trả được tiền, và là lý do cả tệp này tồn tại — nằm im.
 *
 * Trong lúc còn dò thì trả `"card"`: đó là radio luôn có mặt, nên nó là chỗ
 * đứng trung tính. Khi probe xong, lựa chọn chỉ dịch đi ở đúng những chi nhánh
 * mà `card` vốn không dùng được — một phép SỬA về ô chạy được, không phải rút
 * mất thứ khách đang nhắm. Khách bấm rồi thì `paymentChoice` không còn `null`
 * và hàm này thôi có tiếng nói (xem call-site).
 */
export function defaultPaymentMethod(input: CounterPayAvailabilityInput): string {
  if (input.stripeLoading || input.paypayLoading) return "card";
  if (input.stripeReady) return "card";
  if (input.paypayEnabled) return "qr_pay";

  return "counter";
}

export function isCounterPayMethod(method: string): boolean {
  return COUNTER_METHODS.has(method);
}

/**
 * Sửa lựa chọn đang giữ trong state khi trạng thái cổng thanh toán đổi.
 *
 * Chỉ can thiệp khi lựa chọn hiện tại đã KHÔNG còn hợp lệ — một `counter` sót
 * lại (từ mặc định cũ, hoặc từ lúc probe chưa xong) phải bị đẩy về `card` ngay
 * khi có cổng online. Ngoài ra không đụng vào: khách đã chọn gì thì giữ nguyên,
 * kể cả khi counter đang được chào.
 */
export function correctPaymentMethod(
  current: string,
  counterOffered: boolean,
): string {
  if (!counterOffered && isCounterPayMethod(current)) return "card";
  return current;
}
