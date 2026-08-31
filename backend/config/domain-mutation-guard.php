<?php

declare(strict_types=1);

return [
    'current_gate' => 4,
    'allowlist' => base_path('architecture/domain-mutation-writers.php'),

    /*
     * #1195 — tables allowed to sit outside every aggregate even though a
     * foreign key ties them to one.
     *
     * The guard used to be silent by default: a table nobody remembered to
     * list was exempt forever, and CI stayed green. That failed twice for real
     * (#1194: the shop topping-override table and a translations table). The
     * architecture test now asserts the opposite invariant — a table with an FK
     * into a declared aggregate must itself belong to SOME aggregate — and this
     * list is the only way out. Every entry states WHY, and a stale entry (the
     * table no longer exists, or has since been declared) fails the test too,
     * so the list cannot rot into a second blind spot.
     *
     * "Own lifecycle" is the bar: the rows outlive, and are created
     * independently of, the aggregate they point at. Pointing at a product SKU
     * does not make a stock movement part of the product.
     */
    'fk_reachability_exempt' => [
        // ── Inventory: its own aggregate-in-waiting. Every one of these
        // references product_skus to say WHAT is on a shelf, but stock exists
        // per warehouse with its own lifecycle, and a product edit must never
        // move stock.
        // `materials.output_sku_id` only became visible to this test once the
        // deferred circular FK was actually emitted again (omnify-go#135) — it
        // had been silently dropped from the schema.
        'materials' => 'Inventory: output_sku_id points at the SKU a material YIELDS; a material is a purchasing/recipe entity whose lifecycle is warehouse operations, not a catalog edit.',
        'stock_levels' => 'Inventory: per-warehouse balance of a SKU; created and destroyed by stock movements, not by catalog edits.',
        'stock_movements' => 'Inventory: the movement ledger itself — append-only, lifecycle owned by warehouse operations.',
        'stock_alerts' => 'Inventory: reorder thresholds per warehouse × SKU.',
        'stock_count_items' => 'Inventory: lines of a physical stock count.',
        'stock_transaction_items' => 'Inventory: lines of a receipt/issue transaction.',
        'stock_transfer_items' => 'Inventory: lines of an inter-warehouse transfer.',

        // ── Floor: zones, tables and their QR tokens. A table points at the
        // order currently occupying it; seating is not order state.
        'tables' => 'Floor: current_order_id names the order occupying the table right now; the table itself is created by floor layout (zones, QR tokens) and outlives every order on it.',

        // ── Production (plan-022): a production order consumes materials and
        // yields SKUs; the SKU reference is an output pointer, not membership.
        'production_orders' => 'Production: a batch run that YIELDS a SKU; its lifecycle is the kitchen schedule.',
        'production_order_items' => 'Production: lines of a production run.',
        'material_batch_items' => 'Production: material lot consumption of a batch recipe.',

        // ── Customer-generated content: written by end customers through their
        // own moderation flow; a review must survive the order being archived.
        'branch_reviews' => 'UGC: customer review of a branch, moderated on its own flow.',
        'product_reviews' => 'UGC: customer review of a product bought on an order.',

        // ── Loyalty (#1441): điểm và coupon cá nhân KHÔNG thuộc aggregate của
        // khách hay của đơn — chúng chỉ trỏ tới.
        'coupons' => 'Loyalty: customer_id chỉ tên CHỦ SỞ HỮU của một coupon cá nhân (mint khi đổi điểm), không phải quan hệ thành phần — coupon sống độc lập, hết hạn theo lịch riêng, và phải tồn tại được sau khi hồ sơ khách bị lưu trữ.',
        'customer_point_entries' => 'Loyalty: sổ cái điểm append-only. customer_order_id là XUẤT XỨ của bút toán ("điểm này tích từ đơn nào"), không phải một dòng của đơn: ghi điểm KHÔNG được đi qua guard đột biến đơn hàng, vì nó không thay đổi gì trên đơn — và bút toán phải sống tiếp sau khi đơn đóng sổ.',
    ],

    'aggregates' => [
        'product' => [
            'models' => ['Category', 'CategoryProduct', 'CategoryTranslation', 'Product', 'ProductOption', 'ProductOptionTranslation', 'ProductOptionValue', 'ProductOptionValueTranslation', 'ProductSku', 'ProductSkuTranslation', 'ProductToppingGroup', 'ProductToppingGroupItemOverride', 'ProductTranslation', 'ProductType', 'ProductTypeTranslation', 'ToppingGroup', 'ToppingGroupItem', 'ToppingGroupItemSku', 'ToppingGroupTranslation', 'VariantUnit'],
            'tables' => ['categories', 'product_category', 'category_translations', 'products', 'product_translations', 'product_options', 'product_option_translations', 'product_option_values', 'product_option_value_translations', 'product_skus', 'product_sku_translations', 'product_topping_groups', 'product_topping_group_item_overrides', 'product_types', 'product_type_translations', 'topping_groups', 'topping_group_translations', 'topping_group_items', 'topping_group_item_skus', 'variant_units'],
            'boundaries' => [
                'app/Console/Maintenance/TaxExemptBrandPersistence.php',
                'app/Services/Product/Internal/EloquentProductPersistence.php',
                // #962 (ca78a941e) — cổng CustomerEngagement gọi sang để cộng
                // dồn bộ đếm đánh giá trên `products`. Nó nằm TRONG module
                // Product và ghi bảng của chính module, nên là writer hợp lệ;
                // nó chỉ chưa được khai, và `architecture:domain-writers` đã đỏ
                // trên `dev` kể từ lúc lớp cổng đó ra đời.
                'app/Services/Product/Internal/EloquentReviewedSkuDirectory.php',
                // #2346 — đường ghi duy nhất cho việc đóng dấu loại thuế mặc
                // định lên product CHƯA GẮN GÌ. Baseline provisioning (module
                // khác) gọi qua cổng `ProductTaxStamp` thay vì `DB::table`.
                'app/Services/Product/Internal/EloquentProductTaxStamp.php',
                'app/Services/Product/ProductOptionService.php',
                'app/Services/Product/ProductOptionValueService.php',
                'app/Services/Product/ProductSkuService.php',
                'app/Services/Product/CategoryService.php',
                'app/Services/Product/ProductTypeService.php',
                'app/Services/Product/RecipeService.php',
                'app/Services/Topping/ProductToppingGroupService.php',
                // #1185/#1194 — referential cleanup ONLY (see the `menu` twin
                // below). A topping item SOFT-deletes, so the ON DELETE CASCADE
                // its override tables already declare never fires: the rows must
                // be purged in PHP or they outlive the item, can never apply,
                // and break the Shop→Menu save path. Writes no business field.
                //
                // ToppingGroupItemService is deliberately NOT listed. Its one
                // direct write (the reorder branch) now goes through
                // ProductToppingGroupService::updateGroupItem like the add and
                // remove branches beside it, so it needs no standing permission.
                'app/Models/ToppingGroupItem.php',
                // #889/#2506 — bảy lệnh `woo:*` từng ghi thẳng aggregate này
                // ĐÃ XOÁ cùng issue của chúng. Đừng dựng lại: một lệnh nhập
                // liệu ghi thẳng model là ngoại lệ có hạn dùng, không phải
                // khuôn mẫu.
            ],
        ],
        'menu' => [
            'models' => ['BranchFloatingSectionOverride', 'BranchScheduleOverride', 'FloatingSection', 'FloatingSectionProduct', 'FloatingSectionProductSku', 'FloatingSectionProductToppingItemOverride', 'FloatingSectionSchedule', 'FloatingSectionTranslation', 'Menu', 'MenuAvailabilityEvent', 'MenuItem', 'MenuMenuSection', 'MenuProduct', 'MenuProductSku', 'MenuProductToppingItemOverride', 'MenuPromotion', 'MenuPromotionCategory', 'MenuPromotionProduct', 'MenuPromotionTranslation', 'MenuSchedule', 'MenuScheduleDate', 'MenuSection', 'MenuTranslation', 'MenuSectionTranslation'],
            'tables' => ['branch_floating_section_overrides', 'branch_schedule_overrides', 'floating_sections', 'floating_section_products', 'floating_section_product_skus', 'floating_section_product_topping_item_overrides', 'floating_section_schedules', 'floating_section_translations', 'menus', 'menu_availability_events', 'menu_items', 'menu_menu_sections', 'menu_products', 'menu_product_skus', 'menu_product_topping_item_overrides', 'menu_promotions', 'menu_promotion_category', 'menu_promotion_product', 'menu_promotion_translations', 'menu_schedules', 'menu_schedule_dates', 'menu_sections', 'menu_translations', 'menu_section_translations'],
            'boundaries' => [
                'app/Console/Maintenance/TaxExemptBrandPersistence.php',
                'app/Services/Product/MenuScheduleService.php',
                'app/Services/Product/MenuSectionService.php',
                'app/Services/Product/MenuService.php',
                // #1661 — chỗ ghi DUY NHẤT của pivot `menu_menu_sections`.
                // `MenuService`/`MenuSectionService` vẫn ở đây vì chúng ghi các
                // bảng khác của cùng aggregate; ba lời gọi pivot của chúng đã
                // dời sang writer này để bản catalog được đánh dấu ở một chỗ
                // (bảng khoá kép ⇒ không observer nào bắt được nó).
                'app/Services/Catalog/MenuSectionPivotWriter.php',
                'app/Services/Product/FloatingSectionService.php',
                'app/Services/Product/FloatingSectionScheduleService.php',
                'app/Services/Product/BranchMenuScheduleService.php',
                'app/Services/Promotion/MenuPromotionService.php',
                'app/Services/Topping/ShopMenuToppingOverrideService.php',
                // #1185/#1194 — floating-section twin of the line above: owns
                // the shop-level topping overrides of a floating-section
                // (khung giờ ưu đãi) product. Invisible to the guard until its
                // table was registered above.
                'app/Services/Topping/ShopFloatingSectionToppingOverrideService.php',
                // #1185/#1194 — referential cleanup ONLY: purges the menu-side
                // and floating-section-side override rows of a topping item
                // that has just been (soft-)deleted. See the `product` twin
                // above for why the FK cascade cannot do this.
                'app/Models/ToppingGroupItem.php',
                // #889/#2506 — bốn lệnh `woo:*` từng ghi thẳng aggregate này
                // ĐÃ XOÁ cùng issue của chúng. Xem ghi chú song sinh ở
                // aggregate `product` bên trên.
            ],
        ],
        'customer' => [
            'models' => ['Customer'],
            'tables' => ['customers'],
            'boundaries' => ['app/Services/Customer/CustomerService.php'],
        ],
        // #3199 (ADR 0002) — sổ NHẬN của luồng danh tính từ Platform.
        //
        // Khai chủ thật chứ không xin miễn trừ: bảng này là thứ quyết định một
        // event có được áp hay không (unique `event_id` chống trùng, `sequence`
        // chống ghi đè ngược thời gian), nên "ai được ghi vào nó" là câu hỏi có
        // hậu quả. Đúng MỘT writer — consumer — và biên giới đó là thứ khiến
        // hai bất biến trên không thể bị đi vòng.
        //
        // Mirror (`organizations`/`brands`/`branches`) KHÔNG nằm ở đây: chúng đã
        // do đường đăng nhập SSO ghi từ trước và có nhiều writer hợp lệ; gộp
        // chúng vào đây sẽ là một tuyên bố sai về quyền sở hữu.
        'identity_feed' => [
            'models' => ['IdentityInboxEntry'],
            'tables' => ['identity_inbox'],
            'boundaries' => ['app/Services/Platform/IdentityEventConsumer.php'],
        ],
        // #2878 (T1 của #2876) — sổ lượt thu tiền ở máy 釣銭機.
        //
        // Khai chủ THẬT chứ không xin `fk_reachability_exempt`, dù bảng có FK
        // sang `customer_orders` + `order_payments` và lý lẽ "vòng đời riêng"
        // dùng được: hàng vẫn ra đời khi lượt thu HỎNG, tức không có đơn và
        // không có dòng tiền nào. Nhưng miễn trừ chỉ làm rào im, còn khai chủ
        // làm người ghi NHÌN THẤY ĐƯỢC — mà đây là bảng nói về tiền mặt, nên
        // "ai được ghi vào nó" đúng là câu cần trả lời.
        //
        // Một biên giới duy nhất, và đó là chủ ý: đường ghi hợp lệ DUY NHẤT là
        // sync-UP từ máy trạm. Không màn hình nào, không lệnh nào, không seeder
        // nào được tạo một lượt thu tiền — một hàng ở đây khẳng định rằng một
        // cái máy vật lý đã làm một việc, và chỉ cái máy đó mới nói được điều
        // ấy. Thêm biên giới thứ hai vào đây là mở đường cho một khẳng định
        // không có máy nào đứng sau.
        'cash_device' => [
            'models' => ['CashDeviceTransaction', 'CashDeviceInventorySnapshot', 'CashDeviceErrorEvent'],
            'tables' => ['cash_device_transactions', 'cash_device_inventory_snapshots', 'cash_device_error_events'],
            'boundaries' => [
                'app/Services/Till/CashDeviceTransactionIntake.php',
                // #2879/#2882 — cùng aggregate, cùng luật: đường ghi hợp lệ DUY
                // NHẤT là sync-UP từ máy trạm. `CashDrawerReconciliationService`
                // KHÔNG có mặt ở đây và đó là điểm chính của nó — nó chỉ ĐỌC,
                // và một service đọc nằm trong danh sách biên giới ghi sẽ mời
                // người sau thêm một lệnh sửa vào đó.
                'app/Services/Till/CashDeviceObservationIntake.php',
            ],
        ],
        // #1684 thêm bảng này mà không khai chủ, nên `OwnershipLedgersAgreeTest`
        // L2 đỏ trên `dev` (108 > ngân sách 107, và ngân sách CHỈ ĐƯỢC GIẢM).
        //
        // Khai được là nhờ #1666: trước đó lệnh ghi nằm ngay trong
        // `ShopFaqController::visibility`, tức người ghi là một controller và
        // biên giới sẽ là tầng HTTP. Sau khi tách, `PostFaqService` là người ghi
        // DUY NHẤT của bảng (đã đo: `updateOrCreate` ở đúng một chỗ).
        //
        // Chỉ `post_branches`, KHÔNG kèm `posts`: `posts` còn phục vụ cả bài
        // news/promotion với nhiều người ghi khác, nên khai nó là một quyết định
        // riêng chứ không phải hệ quả của việc thêm một công tắc hiển thị.
        'post_visibility' => [
            'models' => ['PostBranch'],
            'tables' => ['post_branches'],
            'boundaries' => ['app/Services/Post/PostFaqService.php'],
        ],
        // #1195 — a redemption row is what enforces a coupon's usage caps, so
        // forging one is money off a bill. The table belonged to no aggregate
        // and its writer was invisible to the gate.
        //
        // `coupons` itself is NOT here yet: it is still served by the Omnify
        // generated CRUD service, and declaring the table would register that
        // generated service (and its controller) as a domain boundary — a
        // decision about omnify-generated writers in general, not about
        // coupons. Tracked in #1195.
        'promotion' => [
            'models' => ['CouponRedemption'],
            'tables' => ['coupon_redemptions'],
            'boundaries' => [
                'app/Services/Promotion/CouponService.php',
                // #1550 — lượt gộp khách chuyển chủ `coupon_redemptions` + `coupons`; ghi từ
                // trong Promotion để CustomerEngagement không tự cầm bảng này.
                'app/Services/Promotion/Internal/EloquentCustomerCouponReassignment.php',
                // #1581 — nửa GẮN/GỠ coupon lên đơn tách khỏi `CouponService`
                // sang Ordering. Nó vẫn là writer hợp pháp của `coupon_redemptions`
                // (dòng redemption sinh ra đúng lúc áp coupon lên một đơn), nên
                // biên giới đi theo mã chứ không ở lại theo đường dẫn cũ.
                'app/Services/Order/Coupon/OrderCouponService.php',
                // #962 — cùng chuyện đó lần thứ hai: chính những câu ghi ấy lại
                // rời `OrderCouponService` xuống adapter khi Ordering thôi cầm
                // `Coupon`/`CouponRedemption` (Pricing). Người ghi không đổi,
                // chỉ đổi chỗ đứng — nên biên giới phải theo, y như lần #1581.
                'app/Services/Promotion/EloquentOrderCouponLedger.php',
            ],
        ],
        'order' => [
            'models' => ['CustomerOrder', 'CustomerOrderItem', 'OrderCodeCounter', 'OrderCondition', 'OrderItemTopping'],
            'tables' => ['customer_orders', 'customer_order_items', 'order_code_counters', 'order_conditions', 'order_item_toppings'],
            'boundaries' => [
                'app/Console/Maintenance/ScheduledPickupTimeRepairPersistence.php',
                'app/Services/Order/Internal/EloquentOrderPersistence.php',
                // #1550 — như trên, cho `customer_orders`.
                'app/Services/Order/Internal/EloquentCustomerOrderReassignment.php',
                'app/Services/Order/Internal/Concerns/WritesCustomerOrders.php',
            ],
        ],
        // #2885 — bằng chứng lệch tiền giữa máy trạm và Cloud.
        //
        // Aggregate RIÊNG, không nhập vào `order`, và đó là quyết định: biên
        // giới của guard cấp theo AGGREGATE chứ không theo bảng, nên khai
        // `order_money_overwrites` dưới `order` sẽ đồng thời trao cho người ghi
        // bằng chứng quyền ghi cả `customer_orders`. Nới mặt tiền đột biến TIỀN
        // để tiện khai một bảng audit là đánh đổi sai chiều.
        //
        // Bảng cũng KHÔNG có FK nào vào aggregate khác (`order_id`/`device_id`
        // là Uuid trần, cùng khuôn `audit_logs`), nên nó không rơi vào
        // `fk_reachability_exempt` bên trên — nó có chủ thật, không phải miễn trừ.
        //
        // Người ghi là `OrderMoneyEvidenceRecorder`, KHÔNG phải controller —
        // xem #1666/`post_visibility` bên trên: để lệnh ghi trong controller
        // thì biên giới của một bảng tiền sẽ là tầng HTTP.
        'order_money_evidence' => [
            'models' => ['OrderMoneyOverwrite'],
            'tables' => ['order_money_overwrites'],
            'boundaries' => ['app/Services/Order/Internal/OrderMoneyEvidenceRecorder.php'],
        ],
        // #2901 — log máy trạm kéo về Cloud theo yêu cầu, và bản thân các yêu
        // cầu đó.
        //
        // Aggregate RIÊNG chứ không nhập vào `device`: biên giới của guard cấp
        // theo AGGREGATE chứ không theo bảng, nên khai hai bảng này chung với
        // thiết bị sẽ đồng thời trao cho người ghi log quyền ghi `devices` —
        // tức quyền ghép cặp/thu hồi thiết bị. Đánh đổi sai chiều, cùng lý lẽ
        // với `order_money_evidence` ngay trên.
        //
        // Hai bảng đi CHUNG một aggregate vì bất biến nằm GIỮA chúng: mỗi lô
        // bản ghi phải cộng dồn `received_count`/`rejected_count` và đóng
        // `status` của đúng yêu cầu nó trả lời, trong cùng một lượt. Tách đôi
        // thì lệnh ghi cuối cùng vẫn phải chạm cả hai, chỉ là không cổng nào
        // biết.
        //
        // Bảng KHÔNG có FK nào vào aggregate khác (`device_id`/`branch_id`/
        // `request_id` là Uuid trần, cùng khuôn `audit_logs`), nên chúng không
        // rơi vào `fk_reachability_exempt` bên trên — chúng có chủ thật, không
        // phải miễn trừ.
        //
        // Người ghi là `WorkstationLogArchive`, KHÔNG phải controller hay
        // command: để lệnh ghi rải trong cả hai thì biên giới của một bảng chở
        // PII sẽ là tầng HTTP CỘNG tầng CLI, tức không còn là biên giới (#1666).
        'workstation_log' => [
            'models' => ['WorkstationLogRecord', 'WorkstationLogRequest'],
            'tables' => ['workstation_log_records', 'workstation_log_requests'],
            'boundaries' => ['app/Services/Device/Internal/WorkstationLogArchive.php'],
        ],
        'payment' => [
            'models' => ['DevicePaymentOption', 'OrderPayment', 'OrderPaymentIntent', 'PaymentAttempt', 'PaymentGatewayConnection', 'PaymentGatewayConnectionOption', 'PaymentGatewayOption', 'PaymentGatewayOptionTranslation', 'PaymentGatewayProvider', 'PaymentGatewayProviderTranslation', 'PaymentMethod', 'PaymentMethodTranslation', 'PaymentPolicyRevision', 'PaymentGatewaySecretVersion', 'PaymentProviderEvent', 'PaymentRefund', 'PaymentSettlement', 'GatewayPayout', 'ShopPaymentOption'],
            // #1637 — order_payment_intents joins THIS aggregate, not `order`,
            // even though its FK points at customer_orders. The row says which
            // gateway object is currently standing in for the order's money;
            // that is the payment side's consistency to keep, and moving it out
            // of the order row is the entire point of #1611.
            // #2609 — `legacy_field_alias_hits` is the alias-reliance counter for
            // the payment POLICY identity fields, so it belongs to this
            // aggregate: its schema is `schemas/Backend/Payment/FieldAliasHit.yaml`
            // and `app/Http/Support/LegacyPaymentPolicyFieldAliases` is the only
            // thing in the tree that writes it (fail-open, via `FieldAliasHit`).
            // Declared as a TABLE only, like `payment_gateway_secret_audits` and
            // `settlement_report_batches` beside it — the model stays out of the
            // list because it is not in `config/modules.php`, and naming it here
            // would make `payment` straddle a module (L1 of OwnershipLedgersAgree).
            'tables' => ['device_payment_options', 'legacy_field_alias_hits', 'order_payments', 'order_payment_intents', 'payment_attempts', 'payment_gateway_connections', 'payment_gateway_connection_options', 'payment_gateway_options', 'payment_gateway_option_translations', 'payment_gateway_providers', 'payment_gateway_provider_translations', 'payment_methods', 'payment_method_translations', 'payment_policy_revisions', 'payment_gateway_secret_audits', 'payment_gateway_secret_versions', 'payment_provider_events', 'payment_refunds', 'payment_settlements', 'gateway_payouts', 'settlement_report_batches', 'shop_payment_options'],
            'boundaries' => [
                'app/Omnify/Modules/PaymentMethod/Services/PaymentMethodServiceBase.php',
                'app/Services/Omnify/PaymentMethodService.php',
                // #1611 (7a-2b) — the RUNTIME writer the #1637 comment above was
                // waiting for. Payments now stamps its own pointer table instead
                // of reading a gateway-named column off Ordering's row; the old
                // column is still dual-written until the contract step.
                'app/Services/Payment/Internal/OrderPaymentIntentPointer.php',
                'app/Services/Payment/Orchestration/Internal/EloquentOrderPaymentLedgerWriter.php',
                'app/Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php',
                'app/Services/Payment/Orchestration/Internal/PaymentGatewayOrchestrationBootstrap.php',
                // plan-054 — same role as the Stripe provisioner beside it:
                // ensures the org-scoped canonical PaymentMethod row exists at
                // runtime, because db:seed never runs on staging or production.
                'app/Services/Payment/Orchestration/Internal/PayPayCanonicalPaymentMethodProvisioner.php',
                'app/Services/Payment/Orchestration/Internal/PayPayCustomerWebBootstrap.php',
                // #2454 — scheduler bookkeeping for `payments:sweep-paypay-qr`:
                // stamps `payment_attempts.last_swept_at` so the backoff ladder
                // knows when each code was last asked about. It writes no money
                // and reaches no verdict; `state` is still the command's.
                'app/Services/Payment/Gateway/PayPay/PayPayQrSweepSchedule.php',
                'app/Services/Payment/Orchestration/Internal/StripeCanonicalPaymentMethodProvisioner.php',
                'app/Services/Payment/Policy/Persistence/EloquentPaymentPolicyRevisionPersistence.php',
                'app/Services/Payment/Policy/Admin/PaymentPolicyEvaluationService.php',
                'app/Services/Payment/Configuration/Internal/EloquentPaymentGatewayConfigurationPersistence.php',
                'app/Services/Payment/ProviderEvent/LegacyGlobalStripeConnection.php',
                'app/Services/Payment/ProviderEvent/ProviderEventInboxService.php',
                'app/Services/Payment/ProviderEvent/ProviderEventIntakeService.php',
                // #1195 — plan-050 settlement/payout layer. These write the
                // rows that say what the gateway ACTUALLY paid out; all three
                // tables were unreachable by the guard until the FK check
                // surfaced them.
                'app/Services/Payment/Settlement/SettlementRowAssembler.php',
                'app/Services/Payment/Settlement/SettlementReconciliationService.php',
                'app/Services/Payment/Settlement/Stripe/StripeSettlementRecorder.php',
                // #2864 — đánh dấu hàng settlement là tiền của merchant KHÁC
                // trên tài khoản Stripe dùng chung. Ghi ĐÚNG một cột `status`
                // trên `payment_settlements`, cùng lối `update()` mà
                // SettlementReconciliationService dùng khi re-match orphan.
                'app/Services/Payment/Settlement/ForeignSettlementMarker.php',
                // #2893 — chuyển quy thuộc 968 bản ghi tiền từ hàng connection
                // tổng hợp sang hàng THẬT, đóng dấu định danh PSP thật lên hàng
                // thật, rồi đánh `is_active=false` cho hàng tổng hợp. Ghi vào
                // `payment_settlements` · `payment_provider_events` ·
                // `gateway_payouts` · `payment_gateway_connections` — cả bốn đều
                // trong aggregate này. KHÔNG ghi `order_payments`.
                'app/Services/Payment/Settlement/SettlementAttributionMigrator.php',
                // #3084 — đóng dấu "quyền sở hữu chưa phân giải" lên connection
                // đang mang UUID bịa. Ghi đúng hai cột org-unit trên
                // `payment_gateway_connections`; không chạm tiền, không chạm
                // quy thuộc cổng. Chỉ chạy khi có người gõ lệnh.
                'app/Services/Payment/Settlement/UnresolvedOwnershipBackfill.php',
            ],
        ],
    ],
];
