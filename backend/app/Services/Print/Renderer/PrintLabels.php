<?php

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1876) — catalog nhãn i18n cho phiếu in.
 *
 * Bản port của `printLabelsFor()` bên Go (`print_kitchen_bill_i18n.go`). Mọi
 * emitter lấy nhãn từ ĐÂY, không lấy từ block definition — nên một dấu cách
 * thừa ở `notePrefix` làm lệch mọi dòng ghi chú của mọi kind, và lệch theo kiểu
 * chỉ lộ ra dưới dạng "hash khác".
 *
 * File này được SINH từ fixture chung
 * `workstation/internal/service/testdata/print_labels_golden.json`
 * (Go sinh — PHP đọc, cùng khuôn T5.2a đã dùng cho primitives). Chép tay 108
 * chuỗi là cách chắc chắn để hai bên trôi khỏi nhau.
 *
 * `PrintLabelsParityTest` đọc lại chính fixture đó và so từng trường, nên một
 * lần sửa tay ở đây mà không sinh lại bên Go sẽ đỏ.
 *
 * `titleRedInvoice` (#2062): "PHIEU THANH TOAN" ở vi, "PAYMENT RECEIPT" ở en,
 * 「領収書」 ở ja.
 *
 * #1445 giữ "HOA DON DO" ở MỌI locale với lập luận: tên một biểu mẫu pháp định
 * là một phần của biểu mẫu, không được dịch. Lập luận đó ĐÚNG — và chính vì nó
 * đúng mà tên cũ phải đi. Sau #1779 tờ này không còn là hoá đơn GTGT (không số,
 * không mẫu số/ký hiệu, không ký số, không mã CQT), nên mang tên một chứng từ
 * luật định là một tuyên bố SAI chứ không phải một lựa chọn từ ngữ. `ja` đã
 * trung thực từ #1890 (領収書 = biên lai); vi/en nay theo cùng tinh thần.
 *
 * vi/en giữ ASCII và đó KHÔNG phải sở thích: nửa fleet dùng máy in không có ROM
 * kanji, in ra sẽ là một hàng ô vuông.
 *
 * Chuỗi này SONG SINH với `config/print_templates.php` (`red_invoice.title`):
 * formatter cũ đọc ô này, template đọc file kia, và cổng TR-40 so hai đường đó
 * byte-for-byte — sửa một bên là đỏ ngay.
 *
 * `reprintMark` là chữ trên dấu bản sao (`<mark> #N`, in từ bản thứ 2). Cùng lý
 * do locale: "BAN IN" ở vi/en, 「再印刷」 ở ja.
 */
final class PrintLabels
{
    private function __construct(
        public readonly string $change,
        public readonly string $customerLabel,
        public readonly string $dineIn,
        /** #2071 — đầu dòng giảm giá của khối `discounts` ("Giam gia (10%)  -¥91"). */
        public readonly string $discount,
        public readonly string $guest,
        public readonly string $item,
        public readonly string $kitchenTotal,
        public readonly string $notePrefix,
        public readonly string $orderMethod,
        public readonly string $orderNo,
        public readonly string $orderTotal,
        public readonly string $paidAmount,
        public readonly string $paymentMethod,
        /**
         * Ô trạng thái thanh toán trên hai tờ đi CÙNG ĐỒ ĂN (phiếu bếp + phiếu
         * hall), chỉ cho đơn mang về — bàn tại chỗ trả sau khi ăn nên phiếu của
         * nó không nói gì về tiền.
         *
         * Hai giá trị, không phải ba: đơn mang về không chia bill và không trả
         * một phần, nên "trả một phần" là trạng thái luồng này không tới được.
         * Thiếu bất kỳ đồng nào ⇒ CHƯA TRẢ — hướng khiến nhân viên đi HỎI, thay
         * vì đưa đồ ăn đi.
         *
         * Cố ý NGẮN, và đó là ràng buộc layout chứ không phải sở thích chữ
         * nghĩa: khối meta phiếu bếp phóng to giá trị ×2 rồi hạ CẢ HÀNG về ×1
         * khi một cột hết vừa. Một chữ "DA THANH TOAN" 13 cột ở đây sẽ hạ cấp
         * hàng đó trên mọi phiếu mang về — kéo theo cả 伝票番号, chính là trường
         * mà khối ấy sinh ra để đọc được từ xa.
         */
        public readonly string $paymentState,
        public readonly string $paymentPaid,
        public readonly string $paymentUnpaid,
        public readonly string $phone,
        public readonly string $pickupTime,
        public readonly string $price,
        public readonly string $remaining,
        public readonly string $serviceCharge,
        public readonly string $splitModeByAmount,
        public readonly string $splitModeByItems,
        public readonly string $reprintMark,
        public readonly string $splitModeEqual,
        public readonly string $splitPeople,
        public readonly string $splitShare,
        public readonly string $splitTitle,
        public readonly string $spot,
        public readonly string $subtotal,
        public readonly string $table,
        public readonly string $takeaway,
        public readonly string $tax,
        public readonly string $tendered,
        public readonly string $ticketSeq,
        public readonly string $titleKitchen,
        public readonly string $titleNewItems,
        public readonly string $titlePaid,
        public readonly string $titleRedInvoice,
        public readonly string $titleRemaining,
        public readonly string $titleTableBill,
        /** #2809 — Go thêm nhãn này ở b085325a6; golden là nguồn, PHP theo sau. */
        public readonly string $toppingWaived,
        public readonly string $total,
    ) {}

    public static function forLocale(?string $locale): self
    {
        return match (strtolower(trim((string) $locale))) {
            'en' => self::en(),
            'vi' => self::vi(),
            // Mọi locale lạ rơi về ja, khớp `default:` của Go. Fallback này được
            // ghim riêng ở cả hai repo vì một locale không nhận ra mà ra chuỗi
            // rỗng sẽ in thành dòng cụt trên phiếu thật.
            default => self::ja(),
        };
    }

    /** @return array<string, string> khoá theo TÊN TRƯỜNG Go, để so trực tiếp với fixture */
    public function toGoFieldMap(): array
    {
        return [
            'Change' => $this->change,
            'CustomerLabel' => $this->customerLabel,
            'DineIn' => $this->dineIn,
            'Discount' => $this->discount,
            'Guest' => $this->guest,
            'Item' => $this->item,
            'KitchenTotal' => $this->kitchenTotal,
            'NotePrefix' => $this->notePrefix,
            'OrderMethod' => $this->orderMethod,
            'OrderNo' => $this->orderNo,
            'OrderTotal' => $this->orderTotal,
            'PaidAmount' => $this->paidAmount,
            'PaymentMethod' => $this->paymentMethod,
            'PaymentPaid' => $this->paymentPaid,
            'PaymentState' => $this->paymentState,
            'PaymentUnpaid' => $this->paymentUnpaid,
            'Phone' => $this->phone,
            'PickupTime' => $this->pickupTime,
            'Price' => $this->price,
            'Remaining' => $this->remaining,
            'ReprintMark' => $this->reprintMark,
            'ServiceCharge' => $this->serviceCharge,
            'SplitModeByAmount' => $this->splitModeByAmount,
            'SplitModeByItems' => $this->splitModeByItems,
            // #2860 — placeholder `SplitModeEqual` GIỮ NGUYÊN TÊN có chủ ý, dù
            // từ vựng chia bill đã đổi `equal` → `even`.
            //
            // Đây là một NAMESPACE KHÁC: tên placeholder trong mẫu in, thứ quán
            // gõ vào template và thứ `print_labels_golden.json` ghim chung với
            // workstation. Đổi nó là làm câm một placeholder trong mọi mẫu đã
            // lưu — mà mẫu in là thứ quán sửa được, nên "đã lưu" ở đây nghĩa là
            // cả những mẫu không ai trong repo này nhìn thấy.
            //
            // Đổi giá trị trên wire không bắt buộc phải đổi tên biến trong mẫu,
            // và gộp hai việc lại chỉ để tên trông đều nhau là đánh đổi sai.
            'SplitModeEqual' => $this->splitModeEqual,
            'SplitPeople' => $this->splitPeople,
            'SplitShare' => $this->splitShare,
            'SplitTitle' => $this->splitTitle,
            'Spot' => $this->spot,
            'Subtotal' => $this->subtotal,
            'Table' => $this->table,
            'Takeaway' => $this->takeaway,
            'Tax' => $this->tax,
            'Tendered' => $this->tendered,
            'TicketSeq' => $this->ticketSeq,
            'TitleKitchen' => $this->titleKitchen,
            'TitleNewItems' => $this->titleNewItems,
            'TitlePaid' => $this->titlePaid,
            'TitleRedInvoice' => $this->titleRedInvoice,
            'TitleRemaining' => $this->titleRemaining,
            'TitleTableBill' => $this->titleTableBill,
            'ToppingWaived' => $this->toppingWaived,
            'Total' => $this->total,
        ];
    }

    private static function ja(): self
    {
        return new self(
            change: 'お釣り',
            customerLabel: 'お客様',
            dineIn: '店内',
            discount: '割引',
            guest: 'お客',
            item: '商品',
            kitchenTotal: '合計',
            notePrefix: '備考: ',
            orderMethod: '提供',
            orderNo: '伝票番号',
            orderTotal: '注文合計',
            paidAmount: '支払済',
            paymentMethod: '支払方法',
            paymentState: '支払',
            paymentPaid: '済み',
            paymentUnpaid: '未払',
            phone: '電話',
            pickupTime: '受取時間',
            price: '合計',
            remaining: '会計残高',
            serviceCharge: 'サービス料',
            splitModeByAmount: '金額割',
            splitModeByItems: '品目割',
            reprintMark: '再印刷',
            splitModeEqual: '均等割',
            splitPeople: '名',
            splitShare: '取り分',
            splitTitle: '分割会計',
            spot: '予約',
            subtotal: '小計',
            table: 'テーブル',
            takeaway: '持ち帰り',
            tax: '税金',
            tendered: 'お預かり',
            ticketSeq: '番号',
            titleKitchen: '厨房伝票',
            titleNewItems: '追加商品',
            titlePaid: '支払済',
            titleRedInvoice: '領収書',
            titleRemaining: '残額',
            titleTableBill: 'テーブル伝票',
            toppingWaived: 'トッピング無料分',
            total: '合計 (税込)',
        );
    }

    private static function en(): self
    {
        return new self(
            change: 'Change',
            customerLabel: 'Customer',
            dineIn: 'Dine-in',
            discount: 'Discount',
            guest: 'Guest',
            item: 'Item',
            kitchenTotal: 'Total',
            notePrefix: 'Note: ',
            orderMethod: 'Type',
            orderNo: 'Bill No.',
            orderTotal: 'Order total',
            paidAmount: 'Paid',
            paymentMethod: 'Method',
            paymentState: 'Payment',
            paymentPaid: 'PAID',
            paymentUnpaid: 'UNPAID',
            phone: 'Phone',
            pickupTime: 'Pickup',
            price: 'Total',
            remaining: 'Balance due',
            serviceCharge: 'Service',
            splitModeByAmount: 'Split by amount',
            splitModeByItems: 'Split by items',
            reprintMark: 'BAN IN',
            splitModeEqual: 'Split evenly',
            splitPeople: 'people',
            splitShare: 'Share',
            splitTitle: 'SPLIT BILL',
            spot: 'Spot',
            subtotal: 'Subtotal',
            table: 'Table',
            takeaway: 'Takeaway',
            tax: 'Tax',
            tendered: 'Tendered',
            ticketSeq: 'No.',
            titleKitchen: 'KITCHEN',
            titleNewItems: 'NEW ITEMS',
            titlePaid: 'PAID',
            titleRedInvoice: 'PAYMENT RECEIPT',
            titleRemaining: 'REMAINING',
            titleTableBill: 'TABLE BILL',
            toppingWaived: 'Free toppings',
            total: 'Total (incl. tax)',
        );
    }

    private static function vi(): self
    {
        return new self(
            change: 'Tien thoi lai',
            customerLabel: 'Khach hang',
            dineIn: 'Tai ban',
            discount: 'Giam gia',
            guest: 'Khach',
            item: 'San pham',
            kitchenTotal: 'Tong cong',
            notePrefix: 'Ghi chu: ',
            orderMethod: 'Cach dat',
            orderNo: 'So phieu',
            orderTotal: 'Tong don',
            paidAmount: 'Da thanh toan',
            paymentMethod: 'Phuong thuc',
            paymentState: 'Thanh toan',
            paymentPaid: 'DA TRA',
            paymentUnpaid: 'CHUA TRA',
            phone: 'SDT',
            pickupTime: 'Gio lay',
            price: 'Tong',
            remaining: 'Con lai',
            serviceCharge: 'Phi phuc vu',
            splitModeByAmount: 'Chia theo so tien',
            splitModeByItems: 'Chia theo mon',
            reprintMark: 'BAN IN',
            splitModeEqual: 'Chia deu',
            splitPeople: 'nguoi',
            splitShare: 'Phan chia',
            splitTitle: 'HOA DON CHIA',
            spot: 'Dat cho',
            subtotal: 'Tam tinh',
            table: 'Ban',
            takeaway: 'Mang ve',
            tax: 'Thue',
            tendered: 'Tien khach dua',
            ticketSeq: 'STT',
            titleKitchen: 'PHIEU BEP',
            titleNewItems: 'MON VUA THEM',
            titlePaid: 'DA THANH TOAN',
            titleRedInvoice: 'PHIEU THANH TOAN',
            titleRemaining: 'PHAN CON LAI',
            titleTableBill: 'HOA DON BAN',
            toppingWaived: 'Topping mien phi',
            total: 'Tong (da VAT)',
        );
    }

    /**
     * Nhãn kiểu chia bill — đối ứng của `(printLabels).splitModeText`.
     *
     * `default` trả "chia đều" chứ không ném: `splitModeKind()` chỉ sinh ra bốn
     * giá trị, và một giá trị lạ tới đây nghĩa là dữ liệu hỏng — lúc đó in
     * "chia đều" vẫn ra được tờ giấy, còn ném thì khách đứng chờ ở quầy.
     */
    public function splitModeText(string $kind): string
    {
        return match ($kind) {
            'by_items' => $this->splitModeByItems,
            'by_amount' => $this->splitModeByAmount,
            // `even` + nhánh cuối: nhãn "chia đều". Nhánh cuối GIỮ có chủ ý —
            // xem docblock: thà in nhãn hơi rộng còn hơn để khách đứng chờ.
            default => $this->splitModeEqual,
        };
    }
}
