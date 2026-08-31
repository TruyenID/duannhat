<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->call([
                IamSeeder::class,
                PlatformDirectorySeeder::class,
                BetoyaSeeder::class,
                TillTenderCategorySeeder::class,
                TillTenderTypeSeeder::class,
                DenominationSeeder::class,
                PaymentMethodSeeder::class,
                PaymentGatewayCatalogSeeder::class,
                SystemNotificationTemplateSeeder::class,
                SystemNotificationAudienceSeeder::class,
                // #2451 — LUẬT, không chỉ template + audience. Thiếu dòng này thì
                // tầng thông báo lên nửa vời: có mẫu tin, có tập người nhận, mà
                // KHÔNG có luật nào để bắn. Đo trên production 2026-08-11: 29
                // template · 3 audience · **0 rule** — chưa luật hệ thống nào từng
                // chạy ở đó. Phải nằm SAU audience: rule trỏ tới audience bằng
                // `audience_name`, và sau Betoya: rule dựng theo từng brand/org.
                SystemNotificationRuleSeeder::class,
                // #2320 — chốt sau cùng: mọi brand/branch (kể cả cái Platform
                // vừa đồng bộ xuống ở bước 2 mà ảnh chụp Betoya không phủ) đều
                // có đủ loại thuế, Reverb, ProductType combo và
                // shop_order_settings. Thay cho EnsureBrandReverbAppsSeeder.
                BaselineProvisioningSeeder::class,
            ]);

            return;
        }

        // Local mirrors production: the same organization, brand and 17 branches
        // (MockDataSeeder), carrying the same catalog, menus, floor plans and
        // shop settings (BetoyaSeeder → CatalogSnapshotSeeder). Everything after
        // that is demo data production has no equivalent of — cashier shifts,
        // orders, notifications, inventory movement — layered ON TOP of the real
        // catalog rather than inventing a parallel one.
        //
        // The synthetic catalog seeders that used to run here (ProductSeeder,
        // MenuSeeder, BrandComboSeeder, the Sjk*/Hanoi*/GlobalMultiTimezone
        // family) are deliberately gone: their placeholder products and menus
        // would sit in the same shop menus as the real ones with nothing marking
        // them apart.
        $this->call([
            IamSeeder::class,
            MockDataSeeder::class,
            // Betoya catalog snapshot — product types, categories, products,
            // SKUs, toppings, allergens, materials, recipes, menus + sections +
            // schedules, zones, tables, shop order settings and branch media,
            // exported from production by fixtures/_dump_catalog.php. Must run
            // straight after MockDataSeeder: it maps the fixture onto the
            // branches by slug, and every later seeder reads the catalog it
            // installs. Also dựng baseline cho brand + gọi
            // BetoyaCatalogLocalizationSeeder.
            BetoyaSeeder::class,
            // Shop-scoped manager + staff logins for the demo shop (人形町店).
            // Idempotent (firstOrCreate); depends on MockDataSeeder for the
            // branch + IamSeeder for the shop-manager/shop-staff roles. Provides
            // the seeded accounts the admin-web dev SSO bypass signs in as:
            //   shop-manager-sjk@famgia.com / password   (shop-manager)
            //   shop-staff-sjk@famgia.com  / password    (shop-staff)
            ShopManagerUserSeeder::class,
            // Warehouses + devices (TMS / kiosk / workstation / KDS / handy) per
            // branch, plus a sample floor plan for the branches the snapshot has
            // none for. Skips the sample catalog for any brand that already has
            // products, so it never layers placeholders onto the snapshot.
            LocalDevSeeder::class,
            AllergenSeeder::class,
            MaterialSeeder::class,
            // Plan-022 — comprehensive demo dataset for the inventory + production
            // domain. Layers on top of MaterialSeeder for the FIRST active brand:
            // 2 warehouses, base+secondary MaterialUnits, cold-chain CCP, 3
            // produced materials with recipes in every approval status (draft /
            // pending / approved / rejected), inbound lots in every status
            // (active / quarantined / expired / depleted / disposed), one
            // MaterialBatch per workflow state (draft → completed + cancelled),
            // plus manual stock adjustments. Idempotent — detects existing demo
            // warehouse and skips.
            Plan022ComprehensiveDemoSeeder::class,
            // Fills in produced materials + approved recipes for every other
            // active brand that has the canonical raw materials from
            // MaterialSeeder but no recipes yet (Plan022ComprehensiveDemoSeeder
            // covers only the first brand). Idempotent — brands with existing
            // recipes are skipped.
            RecipeSeeder::class,
            // #2320 — baseline mỗi brand/branch: loại thuế 標準/軽減/非課税,
            // Reverb, ProductType combo, shop_order_settings. Cùng provisioner
            // mà production và `provisioning:reconcile` dùng, nên nhánh dev và
            // nhánh production khác nhau ở DỮ LIỆU DEMO chứ không khác nhau ở
            // baseline. CatalogSnapshotSeeder đã gọi sẵn cho Betoya; lượt này
            // quét những brand/branch thêm sau đó.
            BaselineProvisioningSeeder::class,
            // Chỉ còn phần DEMO: nâng service charge lên 5% để màn thanh toán
            // của khách có gì mà hiển thị. Việc TẠO hàng cài đặt đã chuyển sang
            // BranchBaselineProvisioner — hai chỗ cùng tạo một hàng là đúng cái
            // #2320 đang dọn.
            ShopOrderSettingSeeder::class,
            // Plan-030 cashier shift. Categories must seed BEFORE TenderType
            // because the tender-type controller validates `category` against
            // till_tender_categories — system row missing = create fails.
            TillTenderCategorySeeder::class,
            TillTenderTypeSeeder::class,
            // Global denomination baseline (JPY / VND / USD / EUR). Seeded
            // with organization_id NULL so every shop sees them without
            // per-tenant duplication. Without this, a fresh DB has 0
            // denominations and the cashier "Open Shift" dropdown is
            // empty until a manager manually adds rows in Shop Settings.
            DenominationSeeder::class,
            // Phương thức thanh toán (cash / card / transfer / e_wallet /
            // stripe) per organization. Liên kết với TillTenderType qua
            // payment_method_code — phải chạy sau TillTenderTypeSeeder để
            // các code anchor (cash, card) đã được tham chiếu hợp lệ.
            PaymentMethodSeeder::class,
            PaymentGatewayCatalogSeeder::class,
            // POS shift open/close: 1 Till + 1 manager + 4 cashiers cho mỗi
            // chi nhánh bán hàng (trừ bản doanh / bếp trung tâm / nhà máy).
            // Cashier dropdown "Người mở ca" trên màn Open Shift của pos-web
            // sẽ có sẵn người để chọn. Idempotent — re-run không nhân bản
            // user/till.
            PosShiftSeedSeeder::class,
            // Demo customers + orders across every status. Draws its order
            // lines from the snapshot catalog when the brand already has one.
            CustomerOrderSeeder::class,
            DashboardSeeder::class,
            PostSeeder::class,
            // Notification seeders — templates + system audiences. Reverb creds
            // per brand đã do BaselineProvisioningSeeder cấp ở trên, trước mọi
            // demo data dựa vào dispatch.
            SystemNotificationTemplateSeeder::class,
            SystemNotificationAudienceSeeder::class,
            // #2451 — xem chú thích ở nhánh production ngay trên.
            SystemNotificationRuleSeeder::class,
            // Named demo users (Alice/Bình/Chi/Daiki/Emi/Frank) MUST run
            // before NotificationDemoSeeder — the notification seeder looks
            // them up by email to wire actor/recipient relationships.
            NotificationDemoUsersSeeder::class,
            NotificationDemoSeeder::class,
            // Plan-019 — coupons + menu promotions per brand/branch.
            // CouponSeeder runs first so MenuPromotionSeeder can reference
            // the same brands without re-resolving them. Both go through
            // their respective service classes (convention #3).
            CouponSeeder::class,
            MenuPromotionSeeder::class,
            // Recall + Trace demo data. Builds a genealogy chain on the first
            // active brand (supplier lots → produced lot → customer orders),
            // creates Recall rows in every status (draft/active/completed/
            // cancelled), and seeds RecallDrill history. Must run AFTER
            // Plan022ComprehensiveDemoSeeder (needs the demo supplier lots +
            // completed batch's output lot) and CustomerOrderSeeder (needs
            // orders to attach as sales edges). Idempotent — skips when any
            // Recall already exists for the brand.
            RecallTraceDemoSeeder::class,
            // Shapes deterministic MaterialUnit fixtures across all brands
            // for the TC-UNIT-100..111 manual test suite (list, add, edit,
            // delete, base unit, empty state). Must run AFTER MaterialSeeder
            // and Plan022ComprehensiveDemoSeeder so it can overwrite any
            // units those produced. Idempotent.
            MaterialUnitDemoSeeder::class,
            // Plan-036 — Manager Till Tracking demo dataset on the demo shop.
            // Creates 3 tills, 2 open shifts (one stale > 24h), 6 settled today
            // (one out-of-tolerance), 1 closing draft, 1 force-abandoned, 1
            // expired, plus 30 days of historical settled sessions so the
            // dashboard, history filters, detail page, and Z-report PDF all have
            // data out of the box. Must run AFTER MockDataSeeder,
            // DenominationSeeder, TillTenderTypeSeeder, and CustomerOrderSeeder
            // (anchors OrderPayments to a real order). Idempotent — bails if the
            // shop already has > 5 TillSession rows.
            Plan036TillTrackingDemoSeeder::class,
            // POS end-to-end coverage top-up for staging pos-web testing:
            // menu_schedules for menus with none, a pending-pair POS device per
            // selling branch, and a richer customer dataset with VN/JP/GB/US
            // phone variants. Idempotent — safe to chain at end.
            PosE2eSeeder::class,
            // plan-040 UI-demo top-up — 2nd warehouse per single-warehouse shop
            // (transfer testing) + one variant-with-recipe per brand (production
            // calculator). Purely additive + idempotent; safe to chain at end.
            Plan040UiDemoSeeder::class,
            // Production order snapshot (chụp 2026-08-05: 52 đơn · 65 dòng · 5
            // thanh toán) — upsert-only theo id, KHÔNG xoá gì, nên chạy trên DB
            // đang sống cũng an toàn. Phải đứng CUỐI: đơn trỏ tables/users/
            // till_sessions mà các seeder trên vừa dựng, và bàn được nối lại
            // current_order_id sau khi đơn tồn tại. Fixture đã stamp đủ
            // tax_rate (#2188 — không tái sinh dòng NULL).
            // TẠM tắt local (2026-08-10): fixtures/orders/*.json lệch pha với
            // branches.json vừa refresh ở d18319ace — branch_id trong toàn bộ
            // snapshot đơn/ca/bàn không khớp branch nào trên DB local, insert
            // vỡ FK/unique. Cần regen lại fixtures/orders/ từ CÙNG bản dump
            // production với branches.json rồi bật lại dòng dưới.
            // OrderSnapshotSeeder::class,
        ]);
    }
}
