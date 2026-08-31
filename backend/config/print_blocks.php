<?php

/**
 * plan-053 (#1171) — the print BLOCK CATALOG (DESIGN.md §1/§3, TASKS T1.1).
 *
 * This file is the allow-list every published template definition is checked
 * against. Nothing outside it can reach a printer: an unknown block id, an
 * unknown `source`, or an edit to a locked block is a 422 at publish time —
 * never a surprise at print time (TR-14: validation happens ONCE, on publish).
 *
 * ── Mutability (DESIGN §1) ────────────────────────────────────────────────
 *   locked      the renderer builds the content from the money/tax engine.
 *               A definition may only POSITION the block; it is always on and
 *               its content/props are untouchable (except `editable_props`).
 *   toggleable  same as locked, but `enabled` may be flipped — subject to
 *               `require_enabled_when`, the legal condition under which the
 *               block must stay on (TR-17).
 *   free        the brand authors the content (i18n text, image, QR target).
 *
 * ── Why locked exists ─────────────────────────────────────────────────────
 * Principle #1 of the plan: a template PRESENTS, it never COMPUTES. Money and
 * tax come from the engine (`PerRateTaxBuckets`, #1154 allocations). If a
 * brand could reorder or reword 税額 / 合計 / 登録番号 / 「再発行」, the slip and
 * the books would drift and the slip would stop being an 適格請求書.
 *
 * ── #1152 ruling on 登録番号 ───────────────────────────────────────────────
 * `registration_number` is TOGGLEABLE, not locked: 免税事業者 without a number
 * is legal and must NOT be nagged. But once the brand/branch HAS a number,
 * printing it is mandatory — `require_enabled_when: seller_has_registration_number`
 * turns the toggle into a hard requirement exactly then (TR-17).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Definition envelope
    |--------------------------------------------------------------------------
    |
    | `schema` is checked verbatim on publish — bumping it is a breaking
    | change that needs a migration of stored definitions, not a silent edit.
    |
    | `receiptline_dialect` used to sit here as `ofsc-1.0` and was REMOVED
    | (#2061). Nothing ever read it: no validator checked it, the Go parser
    | ignored it, and dropping it changed no byte on paper. plan-053 intended
    | the text layout to be "based on OFSC ReceiptLine" (DESIGN.md §envelope)
    | and that part never landed — so the key advertised a standard this IR
    | does not implement. The definition envelope is a house format; say so by
    | not naming someone else's.
    |
    */
    'schema' => 'tempo.print.v1',

    'paper' => [
        // Characters per line. 58mm = 32 cols, 80mm = 48 cols — the same two
        // widths the workstation's PrintJobConfig.PhysicalWidth uses.
        'columns_58mm' => 32,
        'columns_80mm' => 48,
    ],

    /*
    |--------------------------------------------------------------------------
    | Block types
    |--------------------------------------------------------------------------
    */
    'block_types' => ['text', 'image', 'params', 'line_items', 'qr', 'locked'],

    /*
    |--------------------------------------------------------------------------
    | `source` allow-list (TR-21)
    |--------------------------------------------------------------------------
    |
    | A `source` names data the renderer already holds. It is NEVER a URL: an
    | arbitrary URL in a definition would make every workstation in the fleet
    | fetch an attacker-chosen address (SSRF) and pipe the bytes at a printer.
    | Publish rejects anything not listed here.
    |
    | ── Thêm một `source` mới (#1957 mảnh D) ─────────────────────────────────
    |
    | Ba bước, và bước 2 là bước bị quên:
    |
    |  1. Thêm ĐỊNH DANH vào danh sách này. Chỉ snake_case — không URL, không
    |     đường dẫn, không dấu chấm. `SourceAllowListShapeTest` cưỡng chế điều
    |     này; trước #1957 mảnh D nó chỉ là một quy ước trong đầu người sửa file.
    |
    |  2. Nếu nó dùng cho khối `image`, thêm vào CẢ `image.sources` bên dưới.
    |     Chỉ thêm ở một chỗ thì nó qua được `PrintImageStore` rồi bị
    |     `TemplateValidator` từ chối lúc publish — hỏng ở xa chỗ gây ra.
    |
    |  3. Cho renderer biết phân giải nó. Một định danh không ai vẽ đi qua toàn
    |     bộ hệ thống mà không chỗ nào kêu — đó chính là #1949, và người phát
    |     hiện là chủ quán cầm tờ giấy thiếu một khối.
    |
    | Quảng cáo và coupon sẽ vào đây theo đúng ba bước đó khi có nhu cầu thật.
    | Chúng CHƯA được thêm sẵn: một định danh không ai dùng vẫn là một ô trống
    | trong giao diện tải ảnh, và một lời mời bật thứ không tồn tại.
    |
    */
    'sources' => [
        'brand_logo',
        'branch_logo',
        'order_url',
        'invoice_url',
        'payment_url',
        'order_code',
    ],

    /*
    |--------------------------------------------------------------------------
    | `params` field allow-list
    |--------------------------------------------------------------------------
    |
    | Fields a `params` block may print. Same rationale as `sources`: a
    | template may choose WHICH known field to show, never invent a binding.
    |
    */
    'param_fields' => [
        'store_organization', 'store_name', 'store_sub_name', 'store_address', 'store_phone',
        'order_no', 'order_type', 'table', 'guest', 'staff_name', 'cover_count',
        // `ticket_seq` — bộ đếm phiếu bếp trong ngày. Vào đây khi phiếu bếp và
        // phiếu hall về chung một template: khối `order_meta` của bếp khai nó,
        // và một field không có tên ở đây thì publish từ chối cả định nghĩa.
        'ticket_seq',
        'customer_name', 'customer_phone', 'pickup_time',
        // #1181: the VAT invoice header prints the BUYER's tax code + address —
        // FormatVatInvoice has always emitted them, the catalog just never
        // named them, so a brand editing the block could not put them back.
        'customer_tax_code', 'customer_address',
        'cashier_name', 'business_date', 'opened_at', 'closed_at',
        // #1181: the shift slips identify the terminal (`device_name`) —
        // `till_name` was an alias of it and was REMOVED by #2188 (legacy
        // alias ban); definitions must say `device_name`.
        'device_name',
        'shift_sequence', 'chain_sequence',
    ],

    /*
    |--------------------------------------------------------------------------
    | Image constraints (TR-22)
    |--------------------------------------------------------------------------
    |
    | `max_width_dots` above the paper's printable width is CLAMPED, not
    | rejected — a brand uploading a 4000px logo gets a logo that fits, not a
    | failed publish. A malformed/unknown source is still a hard reject.
    |
    */
    'image' => [
        'printable_dots_58mm' => 384,
        'printable_dots_80mm' => 576,
        'formats' => ['png', 'jpeg', 'gif', 'bmp'],

        /*
         * Tập con của `sources` ở trên mà một khối `image` được dùng, và là thứ
         * `PrintImageStore` chấp nhận lưu ảnh vào (#1957 mảnh B).
         *
         * Cần một danh sách RIÊNG chứ không dùng lại `sources`: danh sách chung
         * còn có `order_url` / `order_code`, vốn dành cho khối QR và khối params.
         * Đem chúng vào đường ảnh sẽ cho phép lưu một bitmap dưới định danh
         * `order_url` — một hàng hợp lệ với DB, vô nghĩa với mọi thứ đọc nó.
         *
         * Mở rộng sau này (quảng cáo, coupon) thêm định danh vào ĐÂY **và** vào
         * `sources`. `PrintImageSourcesAreSubsetTest` giữ hai danh sách không
         * trôi khỏi nhau.
         */
        'sources' => [
            'brand_logo',
            'branch_logo',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The blocks themselves
    |--------------------------------------------------------------------------
    |
    | `editable_props` lists the props a definition may set. For `locked` /
    | `toggleable` blocks the list is deliberately tiny (position is implied
    | by array order; `enabled` only when toggleable).
    |
    */
    'blocks' => [

        // ── header ────────────────────────────────────────────────────────
        'logo' => [
            'type' => 'image',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'source', 'align', 'max_width_dots'],
        ],
        'store_info' => [
            'type' => 'params',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'align', 'fields'],
        ],
        'title' => [
            'type' => 'text',
            'mutability' => 'free',
            // `i18n_narrow` is the <42-column variant. FormatVatInvoice already
            // shortens its title on 58mm paper ("HOA DON GTGT"), so the
            // definition needs somewhere to say that (#1181 gap 5).
            'editable_props' => ['enabled', 'align', 'i18n', 'i18n_narrow', 'fallback', 'bold'],
        ],
        'header_text' => [
            'type' => 'text',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'align', 'i18n', 'fallback', 'bold'],
        ],
        'issued_at' => [
            'type' => 'locked',
            'mutability' => 'locked',
            'editable_props' => [],
        ],
        'split_banner' => [
            'type' => 'locked',
            'mutability' => 'locked',
            'editable_props' => [],
        ],
        'order_meta' => [
            'type' => 'params',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'fields'],
        ],
        'customer_header' => [
            'type' => 'params',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'fields'],
        ],
        'order_note' => [
            'type' => 'params',
            'mutability' => 'free',
            'editable_props' => ['enabled'],
        ],
        'column_header' => [
            'type' => 'text',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'i18n', 'fallback'],
        ],

        // ── body ──────────────────────────────────────────────────────────
        'items' => [
            'type' => 'line_items',
            'mutability' => 'locked',
            // Column CHOICE is presentation; column VALUES are engine data.
            // #3082 — `size` mở cho brand chỉnh. Không mở thì quán không tự
            // hạ/nâng được cỡ chữ dòng món sau khi nhìn phiếu thật, và mọi lần
            // chỉnh đều phải qua một lần deploy.
            'editable_props' => ['columns', 'size'],
            'prop_enums' => [
                // Chỉ hai giá trị. `tall` = ×2 CAO, ×1 rộng — xem chú thích ở
                // `kind_overrides.kitchen.items`. Không khai double-width ở đây:
                // enum là chỗ giá trị trở thành hợp lệ, và một giá trị hợp lệ mà
                // renderer chưa tính đúng số cột là một phiếu vỡ bố cục.
                'size' => ['normal', 'tall'],
                'columns' => ['name', 'qty', 'unit_price', 'amount', 'tax_mark'],
            ],
        ],
        'batch_total' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'tax_legend' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],

        // ── money (engine-owned, never authored) ──────────────────────────
        'subtotal' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'discounts' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'service_charge' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'tax_breakdown' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'grand_total' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'payments' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'change_due' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'remaining' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],

        // ── compliance markers ────────────────────────────────────────────
        'registration_number' => [
            'type' => 'locked',
            'mutability' => 'toggleable',
            'editable_props' => ['enabled'],
            // #1152: legal only while the seller has NO number.
            'require_enabled_when' => 'seller_has_registration_number',
        ],
        'invoice_number' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'reprint_marker' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'red_invoice_marker' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'void_marker' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],

        // ── shift / chain reports ─────────────────────────────────────────
        'shift_meta' => [
            'type' => 'params',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'fields'],
        ],
        'float_count' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'denomination_table' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'tender_summary' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'variance' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'chain_summary' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],

        /*
         * #1181 gap 6 — the seven 精算 sections that had NO block id.
         *
         * FormatShiftReport / FormatChainReport have always printed nine
         * sections; the catalog named only two of them (`tax_breakdown`,
         * `service_charge`, both shared with the bill family), so the other
         * seven were unaddressable: a brand could neither reorder nor hide
         * them, and a definition that mentioned one was a 422.
         *
         * TOGGLEABLE rather than locked: a settlement report is an INTERNAL
         * operations document, not an 適格請求書, so a brand that does not run
         * paid-in/paid-out may legitimately drop that section. The CONTENT
         * still comes entirely from the engine (`ShiftReportInfo`) — only the
         * on/off switch and the position are the brand's.
         */
        'sales_summary' => ['type' => 'locked', 'mutability' => 'toggleable', 'editable_props' => ['enabled']],
        'non_cash_change' => ['type' => 'locked', 'mutability' => 'toggleable', 'editable_props' => ['enabled']],
        'discount_summary' => ['type' => 'locked', 'mutability' => 'toggleable', 'editable_props' => ['enabled']],
        'acct_correction' => ['type' => 'locked', 'mutability' => 'toggleable', 'editable_props' => ['enabled']],
        'check_count' => ['type' => 'locked', 'mutability' => 'toggleable', 'editable_props' => ['enabled']],
        'cash_movement' => ['type' => 'locked', 'mutability' => 'toggleable', 'editable_props' => ['enabled']],
        'void_summary' => ['type' => 'locked', 'mutability' => 'toggleable', 'editable_props' => ['enabled']],
        'shift_signature' => [
            'type' => 'text',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'align', 'i18n', 'fallback'],
        ],

        // ── debt / table ──────────────────────────────────────────────────
        'debt_summary' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],
        'debt_signature' => [
            'type' => 'text',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'align', 'i18n', 'fallback'],
        ],
        'paid_summary' => ['type' => 'locked', 'mutability' => 'locked', 'editable_props' => []],

        // ── footer ────────────────────────────────────────────────────────
        'qr_block' => [
            'type' => 'qr',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'source', 'align'],
        ],
        'footer_text' => [
            'type' => 'text',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'align', 'i18n', 'fallback', 'bold'],
        ],
        'greeting' => [
            'type' => 'text',
            'mutability' => 'free',
            'editable_props' => ['enabled', 'align', 'i18n', 'fallback', 'bold'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-kind composition
    |--------------------------------------------------------------------------
    |
    | `blocks`   the ORDERED list of blocks this kind is composed of. It is
    |            simultaneously the kind's allow-list (a block outside it is a
    |            422) and the source of the system default's block order.
    | `required` blocks that must be PRESENT in every published definition.
    |            Locked blocks are additionally always enabled; a toggleable
    |            required block (登録番号) may be off unless
    |            `require_enabled_when` says otherwise.
    |
    | ── #1181: this list IS the slip, so it is transcribed from Go ──────────
    |
    | Every order below matches `workstation/internal/service/
    | print_templates_default.json` block-for-block, and that file is in turn
    | pinned byte-for-byte against the hard-coded `Format*` formatters by the
    | TR-40 migration gate (117 combinations). Before #1181 all thirteen kinds
    | disagreed with it — most visibly the money footer, where the catalog put
    | the 内税 breakdown ABOVE the total while every filed receipt prints it
    | BELOW (issue #1042).
    |
    | That mattered because a brand's editor starts from THIS default: publish
    | it as-is and the shop's slip changes the same day, which is precisely
    | what TR-40 promises can never happen. Go is the source of truth here, not
    | because it is nicer, but because its output is the paper in the till.
    |
    | `required` is likewise pruned to blocks the kind actually has: demanding
    | `reprint_marker` on a kitchen ticket that never printed one made the
    | honest definition unpublishable.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Nợ: block có trong kind nhưng KHÔNG renderer nào vẽ (#1949)
    |--------------------------------------------------------------------------
    |
    | Catalog và registry là hai danh sách, và không cổng nào trước #1949 so
    | chúng — `PrintContractParityTest` so registry PHP ↔ Go (hai bên khớp nhau),
    | `print_cloud_parity_test` so HASH (hai bên cùng bỏ qua block lạ). Nên một
    | block quảng cáo trong catalog mà không ai vẽ đi lọt cả hệ thống, và tờ giấy
    | ra thiếu một khối mà không chỗ nào kêu.
    |
    | Danh sách này là HIỆN TRẠNG đo được, không phải danh sách mong muốn. Nó có
    | hai người đọc:
    |
    |   - `TemplateValidator::checkRenderable` BỎ QUA chúng, để một định nghĩa
    |     trung thực vẫn publish được (lỗ #4 của #1181: block bắt buộc mà không
    |     ai in làm định nghĩa đúng trở thành không publish nổi). Nhưng nó CHẶN
    |     mọi block MỚI rơi vào cùng tình trạng.
    |   - `CatalogRenderableRatchetTest` đếm, và chỉ cho danh sách CO LẠI.
    |
    | Viết emitter cho một block ⇒ bỏ nó khỏi đây. Ratchet sẽ đỏ nếu quên.
    |
    */

    'renderable_debt' => [
        'chain_report' => [],
        'debt_slip' => [],
        'delta_qr' => [],
        'qualified_simplified_invoice' => [],
        // #2071 trả nợ `discounts`: emitter per-rate từ sổ `order_conditions`
        // sống ở BillKindPlans::emitDiscounts / emitBillDiscounts (Go).
        'receipt' => ['invoice_number'],
        'red_invoice' => ['red_invoice_marker', 'invoice_number'],
        'remaining' => [],
        'runner' => [],
        'shift_open' => [],
        'shift_report' => [],
        'table_paid' => [],
        'vat_invoice' => [],
        'void_notice' => [],
    ],

    'kinds' => [

        'receipt' => [
            // FormatPaidTicket. Money footer order is #1042: subtotal →
            // discounts → service charge → TOTAL → per-rate 内税 → legend →
            // 登録番号. The tax split reads as an informational breakdown UNDER
            // the total because it is 内税 — already inside it.
            'blocks' => [
                'logo', 'store_info', 'title', 'issued_at', 'split_banner',
                'order_meta', 'customer_header', 'order_note', 'header_text',
                'column_header', 'items',
                'subtotal', 'discounts', 'service_charge', 'grand_total',
                'tax_breakdown', 'tax_legend', 'registration_number',
                'payments', 'change_due', 'remaining',
                'invoice_number', 'reprint_marker',
                'qr_block', 'footer_text', 'greeting',
            ],
            'required' => ['items', 'tax_breakdown', 'grand_total', 'registration_number', 'reprint_marker'],
        ],

        'kitchen' => [
            // Bộ block của `runner`, TRỪ `qr_block` — phiếu bếp và phiếu hall
            // dùng chung một template và khác nhau đúng ở QR.
            //
            // `subtotal` · `service_charge` có mặt nhưng KHÔNG in ra trên phiếu
            // bếp: dữ liệu của nó mang cờ delta (một lượt bắn món, không phải cả
            // đơn) và emitter tự tắt các hàng mô tả TOÀN đơn. Khai chúng ở đây là
            // để bộ block khớp Go/PHP; cái quyết định in hay không là dữ liệu.
            'blocks' => [
                // Không có `logo`: phiếu bếp không rời khỏi bếp, nên mực và giấy
                // dành cho nó là mực và giấy tiêu không ai đọc.
                'store_info', 'title', 'issued_at', 'order_meta',
                'customer_header', 'order_note', 'column_header', 'items',
                'subtotal', 'service_charge', 'grand_total',
                'tax_breakdown', 'tax_legend', 'registration_number',
                'remaining', 'footer_text',
            ],
            'required' => ['items', 'grand_total'],
        ],

        'runner' => [
            'blocks' => [
                'logo', 'store_info', 'title', 'issued_at', 'order_meta',
                'customer_header', 'order_note', 'column_header', 'items',
                'subtotal', 'service_charge', 'grand_total',
                'tax_breakdown', 'tax_legend', 'registration_number',
                'remaining', 'reprint_marker', 'qr_block', 'footer_text',
            ],
            'required' => ['items', 'grand_total', 'reprint_marker'],
        ],

        'delta_qr' => [
            'blocks' => [
                'logo', 'store_info', 'title', 'issued_at', 'order_meta',
                'customer_header', 'order_note', 'column_header', 'items',
                'grand_total', 'tax_breakdown', 'tax_legend',
                'registration_number', 'reprint_marker', 'qr_block',
                'footer_text',
            ],
            'required' => ['items', 'qr_block', 'reprint_marker'],
        ],

        'remaining' => [
            'blocks' => [
                'logo', 'store_info', 'title', 'issued_at', 'order_meta',
                'customer_header', 'order_note', 'column_header', 'items',
                'subtotal', 'service_charge', 'grand_total',
                'tax_breakdown', 'tax_legend', 'registration_number',
                'payments', 'change_due', 'remaining',
                'reprint_marker', 'qr_block', 'footer_text',
            ],
            'required' => ['items', 'grand_total', 'remaining', 'reprint_marker'],
        ],

        'red_invoice' => [
            'blocks' => [
                'logo', 'store_info', 'title', 'red_invoice_marker',
                'issued_at', 'split_banner', 'order_meta', 'customer_header',
                'order_note', 'column_header', 'items',
                'subtotal', 'service_charge', 'grand_total',
                'tax_breakdown', 'tax_legend', 'registration_number',
                'payments', 'change_due', 'remaining',
                'invoice_number', 'reprint_marker',
                'qr_block', 'footer_text',
            ],
            'required' => [
                'red_invoice_marker', 'items', 'tax_breakdown', 'grand_total',
                'invoice_number', 'registration_number', 'reprint_marker',
            ],
        ],

        'vat_invoice' => [
            // FormatVatInvoice. The reprint marker prints near the TOP, under
            // the store sub-name — not at the end like the bill family
            // (#1181 gap 4). The invoice carries no tax legend and no
            // discount row.
            'blocks' => [
                'logo', 'store_info', 'title', 'reprint_marker',
                'invoice_number', 'customer_header',
                'column_header', 'items',
                'subtotal', 'tax_breakdown', 'grand_total',
                'registration_number', 'payments', 'footer_text',
            ],
            'required' => [
                'items', 'tax_breakdown', 'grand_total', 'invoice_number',
                'registration_number', 'reprint_marker',
            ],
        ],

        /*
         * #1492 — 適格簡易請求書 (Nhật). CÙNG bộ block với `vat_invoice`, và đó là
         * kết luận đo được chứ không phải chép cho nhanh.
         *
         * Khác biệt Nhật–Việt nằm BÊN TRONG từng block, do nhánh `ja` của các
         * hàm `emit*` dựng (`print_renderer_docs.go` dùng chung helper với
         * `formatVatInvoiceJA`): 領収書 ở đầu, 【お客様】/【明細】, dòng 発行 +
         * 登録番号 ở chân. Danh sách block thì trùng.
         *
         * Nên KHÔNG bịa một chuỗi block mới: cổng TR-40 đòi mẫu hệ thống in
         * giống hệt formatter Go mà nó thay thế, TRƯỚC khi ai được sửa một ký tự.
         */
        'qualified_simplified_invoice' => [
            'blocks' => [
                'logo', 'store_info', 'title', 'reprint_marker',
                'invoice_number', 'customer_header',
                'column_header', 'items',
                'subtotal', 'tax_breakdown', 'grand_total',
                'registration_number', 'payments', 'footer_text',
            ],
            /*
             * `registration_number` là BẮT BUỘC, và ở đây nó nặng hơn ở
             * `vat_invoice`: theo インボイス制度, 登録番号 của người bán là thứ làm
             * cho tờ giấy này LÀ một 適格簡易請求書. Thiếu nó thì đây chỉ là một
             * 領収書 thường và người mua không khấu trừ được thuế đầu vào.
             */
            'required' => [
                'items', 'tax_breakdown', 'grand_total', 'invoice_number',
                'registration_number', 'reprint_marker',
            ],
        ],

        'void_notice' => [
            // FormatVoidNotice states WHAT was cancelled, not what was sold —
            // it prints no item table and no money footer.
            'blocks' => [
                'logo', 'void_marker', 'invoice_number',
                'issued_at', 'order_meta', 'footer_text',
            ],
            'required' => ['void_marker', 'invoice_number'],
        ],

        'debt_slip' => [
            // FormatDebtSlip — reprint marker near the TOP (gap 4), no tax
            // breakdown (a PHIEU GHI NO records a debt, not a taxable supply).
            'blocks' => [
                'logo', 'store_info', 'title', 'reprint_marker', 'issued_at',
                'order_meta', 'customer_header', 'column_header', 'items',
                'grand_total', 'payments', 'debt_summary', 'debt_signature',
                'footer_text',
            ],
            'required' => ['debt_summary', 'grand_total', 'reprint_marker'],
        ],

        'shift_open' => [
            // FormatShiftOpenReport: denominations are counted BEFORE the
            // float they add up to, and the cashier's opening note closes the
            // slip (#1181 gap 7).
            'blocks' => [
                'logo', 'store_info', 'title', 'shift_meta',
                'denomination_table', 'float_count', 'order_note',
                'shift_signature', 'footer_text',
            ],
            'required' => ['shift_meta', 'float_count'],
        ],

        'shift_report' => [
            // FormatShiftReport — the nine 精算 sections in printing order
            // (#1181 gap 6). Seven of these ids did not exist before.
            'blocks' => [
                'logo', 'store_info', 'title', 'shift_meta',
                'chain_summary', 'sales_summary', 'tax_breakdown',
                'tender_summary', 'non_cash_change', 'discount_summary',
                'service_charge', 'acct_correction', 'check_count',
                'cash_movement', 'void_summary', 'variance',
                'denomination_table', 'shift_signature', 'footer_text',
            ],
            'required' => ['shift_meta', 'tender_summary', 'variance'],
        ],

        'chain_report' => [
            // FormatChainReport prints the same nine sections as the shift
            // report, above the per-shift chain summary.
            'blocks' => [
                'logo', 'store_info', 'title', 'shift_meta',
                'chain_summary', 'sales_summary', 'tax_breakdown',
                'tender_summary', 'non_cash_change', 'discount_summary',
                'service_charge', 'acct_correction', 'check_count',
                'cash_movement', 'void_summary', 'variance',
                'denomination_table', 'shift_signature', 'footer_text',
            ],
            'required' => ['chain_summary', 'tender_summary'],
        ],

        'table_paid' => [
            // FormatTablePaid is a floor notice: table, order, paid-at. No
            // money footer, no reprint marker.
            'blocks' => [
                'logo', 'store_info', 'issued_at', 'order_meta',
                'paid_summary', 'footer_text',
            ],
            'required' => ['paid_summary'],
        ],
    ],
];
