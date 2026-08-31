<?php

/**
 * plan-053 (#1171) — LAYER 0: the system default definitions (TASKS T1.4).
 *
 * These live in CODE, not in the database, on purpose: a workstation that has
 * never been online — or whose cache was wiped — must still print (TR-05).
 * Only a definition shipped with the software can promise that; a seeded row
 * cannot reach a machine that has never talked to Cloud.
 *
 * The block ORDER of each kind comes from `config/print_blocks.php`
 * (`kinds.<kind>.blocks`) so the catalog stays the single source of truth.
 * This file only supplies the per-block DEFAULT PROPS: what is on out of the
 * box and what the authored text says.
 *
 * The titles/labels mirror `workstation/internal/service/print_*_i18n.go`
 * because TR-40 makes today's Go formatter the migration baseline — a system
 * default that says something different would change every shop's slip on the
 * day the registry ships, which is exactly what plan-053 promises not to do.
 *
 * Consumed by App\Services\Print\SystemTemplateDefaults.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Locales carried inside a definition
    |--------------------------------------------------------------------------
    |
    | i18n lives IN the definition (DESIGN §3), not in the app's locale files:
    | HQ writes its own thank-you note and must not need a code deploy for it.
    | A `text` block must cover all three or declare `fallback: true` (TR-19).
    |
    */
    'locales' => ['ja', 'en', 'vi'],

    /*
    |--------------------------------------------------------------------------
    | Fallback chain when a locale is missing (TR-19)
    |--------------------------------------------------------------------------
    |
    | Device locale → ja → en. The renderer walks it and warns ONCE per
    | (template, locale) — the `warnedBrands` pattern from TaxResolver.
    |
    */
    'locale_fallback' => ['ja', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Default props applied to a block in EVERY kind
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Prop cấp MẪU theo từng kind (#3082)
    |--------------------------------------------------------------------------
    |
    | `kind_overrides` bên dưới tra theo BLOCK ID. Chỗ này dành cho prop nói về
    | tờ giấy chứ không nói về một khối — hiện chỉ có `top_feed`.
    |
    */
    'kind_defaults' => [
        // #3082 — bếp KẸP phiếu vào thanh kẹp, và thanh kẹp CHE MẤT dòng đầu.
        // Ba dòng trắng (~1 cm trên giấy 80mm) để phần bị che là giấy trắng chứ
        // không phải chữ. Chủ dự án chốt 2026-08-17.
        //
        // Chỉ `kitchen`. Mọi kind khác không khai ⇒ `top_feed` vắng mặt ⇒ byte
        // không đổi một chút nào, golden của chúng giữ nguyên.
        'kitchen' => ['top_feed' => 3],
    ],

    'block_defaults' => [
        'logo' => ['type' => 'image', 'enabled' => false, 'source' => 'brand_logo', 'align' => 'center'],
        // #2000 bước 6 — header đầy đủ theo quy ước hoá đơn Nhật. Chủ dự án chốt
        // 2026-08-07: bật cả bốn dòng danh tính.
        //
        // Thứ tự Ở ĐÂY chính là bố cục trên giấy. `store_name` là MỐC: field khai
        // trước nó in phía trên dòng "chi nhánh + tiêu đề", field khai sau in phía
        // dưới. Không có ô cấu hình riêng cho việc đó — thứ tự khai vốn đã nói lên
        // bố cục, và một ô thứ hai sẽ là nguồn sự thật thứ hai cho cùng câu hỏi.
        //
        //     株式会社ファムジア          <- store_organization (法人名)
        //     VIET ORIGIN                 <- store_sub_name (thương hiệu)
        //     渋谷店            領収書     <- store_name + tiêu đề
        //     〒150-0042 東京都渋谷区...   <- store_address
        //     TEL: 03-1234-5678           <- store_phone
        //
        // 法人名 đứng đầu là bắt buộc về nghiệp vụ, không phải thẩm mỹ: 登録番号
        // T+13 (#1152) thuộc về PHÁP NHÂN, nên tên in cạnh con số đó phải là tên
        // pháp nhân. Trước bước này hoá đơn in tên chi nhánh cạnh số của pháp
        // nhân — lệch chủ thể.
        //
        // Dòng nào KHÔNG CÓ dữ liệu thì không in (emitter bỏ qua giá trị rỗng),
        // nên một quán chưa nhập địa chỉ vẫn ra tờ giấy như cũ.
        'store_info' => ['type' => 'params', 'enabled' => true, 'align' => 'left', 'fields' => ['store_organization', 'store_sub_name', 'store_name', 'store_address', 'store_phone']],
        'title' => ['type' => 'text', 'enabled' => true, 'align' => 'right', 'bold' => true],
        'header_text' => ['type' => 'text', 'enabled' => false, 'align' => 'center', 'fallback' => true, 'i18n' => []],
        'issued_at' => ['type' => 'locked'],
        'split_banner' => ['type' => 'locked'],
        'order_meta' => ['type' => 'params', 'enabled' => true, 'fields' => ['order_no', 'table']],
        'customer_header' => ['type' => 'params', 'enabled' => true, 'fields' => ['customer_name', 'customer_phone', 'pickup_time']],
        'order_note' => ['type' => 'params', 'enabled' => true],
        // #1181 gap 3: the EN header the slip actually prints is "Price"
        // (`printLabels.Price`), not "Amount". The catalog's "Amount" would
        // have silently reworded every English receipt on the brand's first
        // publish. The strings are stored PRE-SPACED because that is how a
        // human types a two-column header; the renderer re-justifies them to
        // the real paper width (`columnHeaderText`).
        'column_header' => ['type' => 'text', 'enabled' => true, 'i18n' => [
            'ja' => '商品                          合計',
            'en' => 'Item                        Total',
            'vi' => 'San pham                      Tong',
        ]],
        'items' => ['type' => 'line_items', 'columns' => ['name', 'qty', 'amount']],
        'batch_total' => ['type' => 'locked'],
        'tax_legend' => ['type' => 'locked'],
        'subtotal' => ['type' => 'locked'],
        'discounts' => ['type' => 'locked'],
        'service_charge' => ['type' => 'locked'],
        'tax_breakdown' => ['type' => 'locked'],
        'grand_total' => ['type' => 'locked'],
        'payments' => ['type' => 'locked'],
        'change_due' => ['type' => 'locked'],
        'remaining' => ['type' => 'locked'],
        // #1152: ON by default. A seller with no number simply prints nothing
        // (the renderer skips an empty value) — no warning, that is legal.
        'registration_number' => ['type' => 'locked', 'enabled' => true],
        'invoice_number' => ['type' => 'locked'],
        'reprint_marker' => ['type' => 'locked'],
        'red_invoice_marker' => ['type' => 'locked'],
        'void_marker' => ['type' => 'locked'],
        'shift_meta' => ['type' => 'params', 'enabled' => true, 'fields' => ['device_name', 'cashier_name', 'business_date', 'opened_at', 'closed_at']],
        'float_count' => ['type' => 'locked'],
        'denomination_table' => ['type' => 'locked'],
        'tender_summary' => ['type' => 'locked'],
        'variance' => ['type' => 'locked'],
        'chain_summary' => ['type' => 'locked'],
        // #1181 gap 6 — the seven 精算 sections that previously had no id.
        // On by default: they are what FormatShiftReport prints today.
        'sales_summary' => ['type' => 'locked', 'enabled' => true],
        'non_cash_change' => ['type' => 'locked', 'enabled' => true],
        'discount_summary' => ['type' => 'locked', 'enabled' => true],
        'acct_correction' => ['type' => 'locked', 'enabled' => true],
        'check_count' => ['type' => 'locked', 'enabled' => true],
        'cash_movement' => ['type' => 'locked', 'enabled' => true],
        'void_summary' => ['type' => 'locked', 'enabled' => true],
        'shift_signature' => ['type' => 'text', 'enabled' => false, 'align' => 'left', 'fallback' => true, 'i18n' => []],
        'debt_summary' => ['type' => 'locked'],
        // #1181: the printed PHIEU GHI NO carries NO pre-printed signature
        // line — FormatDebtSlip ends at the debt summary. The block stays in
        // the definition (so a brand can add one) but authors nothing by
        // default. `fallback: true` is what makes an intentionally-empty
        // enabled text block publishable under TR-19; it changes no bytes.
        'debt_signature' => ['type' => 'text', 'enabled' => true, 'align' => 'left', 'fallback' => true, 'i18n' => []],
        'paid_summary' => ['type' => 'locked'],
        'qr_block' => ['type' => 'qr', 'enabled' => false, 'source' => 'order_url', 'align' => 'center'],
        'footer_text' => ['type' => 'text', 'enabled' => false, 'align' => 'center', 'fallback' => true, 'i18n' => []],
        'greeting' => ['type' => 'text', 'enabled' => false, 'align' => 'center', 'fallback' => true, 'i18n' => []],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-kind overrides (titles + the toggles that differ per document)
    |--------------------------------------------------------------------------
    */
    'kind_overrides' => [

        'receipt' => [
            // TẮT tiêu đề (chủ dự án 2026-08-17) — mọi phiếu, mọi trạng thái, cả đơn
            // tại chỗ lẫn mang đi. `i18n` GIỮ LẠI: nó là chữ brand bật lại được từ
            // màn hình mẫu in, và xoá đi thì bật lại ra một dòng trống.
            'title' => ['enabled' => false, 'i18n' => ['ja' => '支払済', 'en' => 'PAID', 'vi' => 'DA THANH TOAN']],
        ],

        'kitchen' => [
            // Phiếu bếp và phiếu hall dùng CHUNG một template; khác nhau đúng ở
            // QR — hall bật, bếp tắt (khối `qr_block` không khai ở đây, và
            // `has('qr_block')` sai nghĩa là không in).
            // TẮT tiêu đề — xem `receipt` ở trên.
            'title' => ['enabled' => false, 'i18n' => ['ja' => '厨房伝票', 'en' => 'KITCHEN', 'vi' => 'PHIEU BEP']],
            // Hai hàng cuối chỉ bếp mới khai. Template dùng chung không còn bảng
            // 4 cột để chở 提供 / 番号, nên chúng thành HÀNG như mọi thứ khác chứ
            // không bị bỏ: số thứ tự phiếu là cách bếp xâu chuỗi phiếu với món
            // ra khỏi bếp, mất nó không phải chuyện thẩm mỹ.
            'order_meta' => ['fields' => ['order_no', 'table', 'order_type', 'ticket_seq']],

            // KHÔNG khai `items.size` ở đây. #3082 từng cho phiếu bếp `tall`
            // (×2 cao); chủ dự án chốt 2026-08-17 rằng dòng món phiếu bếp phải
            // BẰNG phiếu hall, nên override đó đã gỡ — cùng lúc với nhánh
            // `opts.kind == "kitchen"` bên Go. Gỡ một phía thôi là hai đường in
            // lệch nhau ngay dòng món đầu tiên, và cổng byte-parity mới nói ra.

            // #2928 — MST (`登録番号`, T+13) TẮT trên phiếu bếp.
            //
            // Ruling chủ dự án 2026-08-16: "mã số thuế chỉ hiển thị trên hoá
            // đơn mà thôi". Khớp luật: đây là thông tin của HOÁ ĐƠN THUẾ
            // (#1152); phiếu bếp không phải chứng từ và không rời khỏi bếp —
            // cùng lý do khối `logo` không được khai ở đây.
            //
            // TẮT chứ không GỠ khỏi `config/print_blocks.php`: gỡ thì brand mất
            // khả năng bật lại, mà đó là lựa chọn của họ (#2777).
            'registration_number' => ['enabled' => false],
        ],

        'runner' => [
            // TẮT tiêu đề — xem `receipt` ở trên.
            'title' => ['enabled' => false, 'i18n' => ['ja' => 'テーブル伝票', 'en' => 'TABLE BILL', 'vi' => 'HOA DON BAN']],
            // FormatRunnerTicket prints the order QR (showQR: true).
            'qr_block' => ['enabled' => true, 'source' => 'order_url'],
        ],

        'delta_qr' => [
            // TẮT tiêu đề — xem `receipt` ở trên. Kind này từng là kind DUY NHẤT
            // có tiêu đề phụ thuộc dữ liệu (持ち帰り cho đơn mang đi); không in tiêu
            // đề nữa thì nhánh đó cũng hết việc, và nó đã gỡ khỏi `billTitle`.
            'title' => ['enabled' => false, 'i18n' => ['ja' => '追加商品', 'en' => 'NEW ITEMS', 'vi' => 'MON VUA THEM']],
            'qr_block' => ['enabled' => true, 'source' => 'order_url'],
        ],

        'remaining' => [
            'title' => ['i18n' => ['ja' => '残額', 'en' => 'REMAINING', 'vi' => 'PHAN CON LAI']],
            // FormatRemainingTicket prints the order QR so the customer can
            // settle the rest from their phone.
            'qr_block' => ['enabled' => true, 'source' => 'order_url'],
        ],

        'vat_invoice' => [
            /*
             * #1493/#1494 — nhánh `ja` ĐÃ TRẢ VỀ TIẾNG VIỆT, và khối cảnh báo cũ
             * ở đây đã gỡ vì điều kiện của nó hết hiệu lực.
             *
             * Cảnh báo cũ nói: đừng "sửa" `ja` thành tiếng Việt, vì nó không phải
             * bản dịch mà là 適格簡易請求書 — một chứng từ Nhật khác hẳn — và
             * workstation chưa biết quốc gia của shop nên trục locale là đường
             * DUY NHẤT để quán Nhật lấy được chứng từ của mình. Cắt lúc đó = quán
             * Nhật mất chứng từ luật định. Đúng, và đã đúng suốt từ #1445.
             *
             * Ba việc gỡ điều kiện ấy: #1490 đưa `operating_country` xuống thiết
             * bị, #1492 dựng kind `qualified_simplified_invoice`, #1493 đổi trục
             * rẽ trong Go từ locale sang quốc gia. Quán Nhật nay nhận chứng từ
             * Nhật qua kind của chính nó, nên để tiếng Nhật ở đây chỉ tạo ra một
             * tờ LAI: layout Việt, tiêu đề Nhật — đúng thứ cảnh báo cũ sợ, chỉ là
             * theo chiều ngược lại.
             *
             * Vẫn phải khớp bản sao ở workstation
             * (`workstation/internal/service/print_templates_default.json`)
             * và fixture parity Go — lệch là lần Publish đầu của một brand đẩy
             * ngược tiêu đề sai xuống thiết bị, và cổng byte-parity TR-40 đỏ.
             */
            'title' => [
                'i18n' => [
                    'ja' => 'HOA DON GIA TRI GIA TANG',
                    'en' => 'HOA DON GIA TRI GIA TANG',
                    'vi' => 'HOA DON GIA TRI GIA TANG',
                ],
                'i18n_narrow' => [
                    'ja' => 'HOA DON GTGT',
                    'en' => 'HOA DON GTGT',
                    'vi' => 'HOA DON GTGT',
                ],
            ],
            // The invoice identifies the BUYER for tax purposes: name, tax
            // code, billing address (FormatVatInvoice's customer block).
            'customer_header' => ['fields' => ['customer_name', 'customer_tax_code', 'customer_address']],
            // The invoice's column header is drawn by the locked item table
            // itself (four columns incl. unit price), so the authored block
            // contributes nothing. `fallback: true` keeps it publishable.
            'column_header' => ['fallback' => true, 'i18n' => []],
            'items' => ['columns' => ['name', 'qty', 'unit_price', 'amount']],
            'footer_text' => ['enabled' => true],
        ],

        'red_invoice' => [
            // #2062 — TỜ GIẤY THÔI TỰ NHẬN LÀ HOÁ ĐƠN.
            //
            // Đến #1890 tên ở vi/en là 'HOA DON DO', và lập luận giữ nó (#1445)
            // là: tên một biểu mẫu pháp định là một phần của biểu mẫu, không
            // được dịch. Lập luận ấy đúng — và chính nó là vấn đề. Sau #1779
            // tờ này KHÔNG còn là hoá đơn GTGT: không số, không mẫu số/ký hiệu,
            // không ký số, không mã CQT, không truyền CQT. Mang tên một chứng từ
            // luật định mà không phải chứng từ đó là một tuyên bố SAI, không
            // phải một lựa chọn từ ngữ.
            //
            // `ja` đã trung thực từ #1890: 領収書 = BIÊN LAI, không phải hoá đơn.
            // vi/en nay đi theo đúng tinh thần đó.
            //
            // KHÔNG dùng lại chuỗi của kind `receipt` ('DA THANH TOAN' / 'PAID'):
            // hai kind in ra hai tờ giấy khác nhau (`red_invoice` luôn có dòng
            // tên khách), nên trùng tiêu đề là làm chúng không phân biệt được
            // trên giấy.
            //
            // vi/en giữ ASCII KHÔNG phải vì thẩm mỹ — nửa fleet dùng máy in
            // không có ROM kanji và sẽ in ra một hàng ô vuông.
            //
            // Chuỗi này SONG SINH với `PrintLabels::titleRedInvoice` (đường
            // formatter cũ) — đổi một bên thì cổng TR-40 đỏ ngay.
            //
            // Đổi ở đây phải chạy `php artisan print-templates:export-defaults`,
            // nếu không `CatalogParityTest` đỏ (fixture xuất ra bị cũ).
            'title' => ['i18n' => ['ja' => '領収書', 'en' => 'PAYMENT RECEIPT', 'vi' => 'PHIEU THANH TOAN']],
            'customer_header' => ['fields' => ['customer_name']],
        ],

        /*
         * #1492 — 適格簡易請求書, chứng từ luật định của quán NHẬT.
         *
         * Tên KHÔNG dịch (luật #1445): "quốc gia nào ngôn ngữ đó" được thoả bằng
         * việc chọn đúng chứng từ, không bằng việc dịch tên một chứng từ nước
         * khác. Nên cả ba locale đều mang tên tiếng Nhật — một quán Nhật in bản
         * `en` vẫn phải ra 適格簡易請求書, vì đó là tên pháp lý của tờ giấy.
         *
         * Nội dung khớp `formatVatInvoiceJA` đang chạy trên máy trạm — cổng
         * TR-40 đòi mẫu hệ thống in GIỐNG HỆT formatter nó thay thế trước khi ai
         * sửa một ký tự.
         *
         * ⚠ MÂU THUẪN CÓ SẴN, cố ý giữ nguyên ở đây: `vatJAFooter` in
         * 「社内参照用（控え）」 và 「※適格請求書等の代替ではありません」 — tức tờ
         * giấy tự phủ nhận là chứng từ đủ điều kiện, ngay dưới cái tiêu đề nói nó
         * là. Nhiều khả năng là di sản từ trước #1152 (khi chưa có 登録番号 thì
         * nó THẬT SỰ chưa đủ điều kiện). Sửa nó là đổi giấy tờ pháp lý, nên nó
         * KHÔNG thuộc tầng này — theo dõi riêng.
         */
        'qualified_simplified_invoice' => [
            'title' => [
                'i18n' => [
                    'ja' => '適格簡易請求書',
                    'en' => '適格簡易請求書',
                    'vi' => '適格簡易請求書',
                ],
                'i18n_narrow' => [
                    'ja' => '簡易請求書',
                    'en' => '簡易請求書',
                    'vi' => '簡易請求書',
                ],
            ],
            /*
             * BA trường, y hệt `vat_invoice` — và đây là chỗ tôi đã sửa SAI một
             * lần rồi đo lại.
             *
             * Lý lẽ "bản 簡易 không cần 宛名 nên chỉ khai `customer_name`" nghe
             * đúng luật, nhưng khai như vậy làm Cloud NÓI MỘT ĐẰNG còn giấy in
             * một nẻo: `emitVatParties` thoát sớm khi locale là `ja` và gọi
             * `vatJAParties`, hàm đọc thẳng `info` và **bỏ qua `b.Fields`**. Tức
             * ba trường vẫn in ra, bất kể khai gì.
             *
             * Luật TR-40 nói mẫu mặc định phải mô tả tờ giấy shop ĐANG in, nên
             * mẫu phải khai ba. Cắt bớt trường là đổi LAYOUT — việc của tầng sau
             * và của chủ sản phẩm, và phải sửa cả emitter thì mới có hiệu lực.
             */
            'customer_header' => ['fields' => ['customer_name', 'customer_tax_code', 'customer_address']],
            'column_header' => ['fallback' => true, 'i18n' => []],
            'items' => ['columns' => ['name', 'qty', 'unit_price', 'amount']],
            'footer_text' => ['enabled' => true],
        ],

        'void_notice' => [
            // FormatVoidNotice opens with the 取消 marker, not a store header:
            // the reader needs to know WHAT was cancelled, not where.
            'store_info' => ['enabled' => false, 'fields' => ['store_name']],
            'title' => ['enabled' => false, 'bold' => false, 'i18n' => ['ja' => '取消通知', 'en' => 'VOID NOTICE', 'vi' => 'THONG BAO HUY']],
            'order_meta' => ['fields' => []],
            'footer_text' => ['enabled' => true],
        ],

        'debt_slip' => [
            // #1181: like the VAT invoice, the debt slip's heading is the
            // Vietnamese form name in every locale.
            'title' => ['i18n' => ['ja' => 'PHIEU GHI NO', 'en' => 'PHIEU GHI NO', 'vi' => 'PHIEU GHI NO']],
            'customer_header' => ['fields' => ['customer_name', 'customer_phone']],
            'column_header' => ['i18n' => [
                'ja' => 'San pham                Thanh tien',
                'en' => 'San pham                Thanh tien',
                'vi' => 'San pham                Thanh tien',
            ]],
        ],

        'shift_open' => [
            'store_info' => ['align' => 'center', 'fields' => ['store_name']],
            'title' => ['align' => 'center', 'i18n' => ['ja' => '開始', 'en' => 'SHIFT OPEN', 'vi' => 'MO CA']],
            // #1181 gap 7: the opening slip names the TERMINAL that was
            // opened, not the till row it belongs to.
            'shift_meta' => ['fields' => ['device_name', 'cashier_name', 'opened_at']],
        ],

        'shift_report' => [
            'store_info' => ['align' => 'center', 'fields' => ['store_name']],
            'title' => ['align' => 'center', 'i18n' => ['ja' => '精算', 'en' => 'SETTLEMENT', 'vi' => 'KET CA']],
            'shift_meta' => ['fields' => ['device_name', 'business_date', 'opened_at', 'closed_at']],
        ],

        'chain_report' => [
            'store_info' => ['align' => 'center', 'fields' => ['store_name']],
            'title' => ['align' => 'center', 'i18n' => ['ja' => '精算（チェーン）', 'en' => 'CHAIN SETTLEMENT', 'vi' => 'KET CA CUOI (CHUOI)']],
            'shift_meta' => ['fields' => ['device_name', 'business_date', 'chain_sequence']],
        ],

        'table_paid' => [
            'store_info' => ['fields' => ['store_name']],
            'title' => ['align' => 'center', 'i18n' => ['ja' => '会計済', 'en' => 'PAID', 'vi' => 'DA THANH TOAN']],
            'order_meta' => ['fields' => ['order_no']],
        ],
    ],
];
