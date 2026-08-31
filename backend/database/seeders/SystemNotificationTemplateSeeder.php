<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the 5 Phase A notification templates into `notification_templates`
 * so `NotificationResource` can render title/body server-side (plan-012
 * T2.6). Replaces the previous "render on the client from i18n bundle"
 * path that shipped with plan-008.
 *
 * Copy was originally intended to be lifted from
 * `admin-web/src/i18n/{ja,en,vi}.json` `notifications.type.*`, but plan-008
 * shipped without those keys — the FE bell just rendered `template_key`
 * literals. This seeder introduces baseline copy; admins can override
 * per-brand via `POST /hq/{brand}/notifications/templates`.
 *
 * `params_schema.required` matches the keys the emitters actually pass
 * (`StockAlertNotificationObserver`, `CustomerOrderNotificationObserver`,
 * `RecipeService`) — verified by the arch test `every Phase A type has a
 * system template with a compatible params_schema`.
 *
 * Idempotent via `firstOrCreate` on `key`.
 */
class SystemNotificationTemplateSeeder extends Seeder
{
    /**
     * @return array<int, array{key: string, content: array, params_schema: array, default_channels: array}>
     */
    public static function templates(): array
    {
        return [
            [
                'key' => 'stock.alert.low',
                'content' => [
                    'ja' => [
                        'title' => '在庫不足：{{item_name}}',
                        'body' => '{{warehouse_name}} の在庫が {{current_quantity}} に減少（最低 {{min_stock}}）',
                    ],
                    'en' => [
                        'title' => 'Low stock: {{item_name}}',
                        'body' => '{{warehouse_name}} is at {{current_quantity}} (minimum {{min_stock}})',
                    ],
                    'vi' => [
                        'title' => 'Tồn kho thấp: {{item_name}}',
                        'body' => '{{warehouse_name}} còn {{current_quantity}} (tối thiểu {{min_stock}})',
                    ],
                ],
                'params_schema' => [
                    'required' => ['warehouse_name', 'item_name', 'current_quantity', 'min_stock'],
                    'optional' => [],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                'key' => 'stock.alert.out',
                'content' => [
                    'ja' => [
                        'title' => '在庫切れ：{{item_name}}',
                        'body' => '{{warehouse_name}} で {{item_name}} が在庫切れになりました',
                    ],
                    'en' => [
                        'title' => 'Out of stock: {{item_name}}',
                        'body' => '{{warehouse_name}} is out of {{item_name}}',
                    ],
                    'vi' => [
                        'title' => 'Hết hàng: {{item_name}}',
                        'body' => '{{warehouse_name}} đã hết {{item_name}}',
                    ],
                ],
                'params_schema' => [
                    'required' => ['warehouse_name', 'item_name'],
                    'optional' => ['current_quantity', 'min_stock'],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                'key' => 'recipe.approved',
                'content' => [
                    'ja' => [
                        'title' => 'レシピ承認：{{recipe_name}}',
                        'body' => '{{approver}} がレシピを承認しました',
                    ],
                    'en' => [
                        'title' => 'Recipe approved: {{recipe_name}}',
                        'body' => '{{approver}} approved the recipe',
                    ],
                    'vi' => [
                        'title' => 'Công thức đã duyệt: {{recipe_name}}',
                        'body' => '{{approver}} đã duyệt công thức',
                    ],
                ],
                'params_schema' => [
                    'required' => ['recipe_name', 'approver'],
                    'optional' => [],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                'key' => 'recipe.rejected',
                'content' => [
                    'ja' => [
                        'title' => 'レシピ差し戻し：{{recipe_name}}',
                        'body' => '{{reviewer}} により差し戻されました：{{reason}}',
                    ],
                    'en' => [
                        'title' => 'Recipe rejected: {{recipe_name}}',
                        'body' => 'Rejected by {{reviewer}}: {{reason}}',
                    ],
                    'vi' => [
                        'title' => 'Công thức bị từ chối: {{recipe_name}}',
                        'body' => '{{reviewer}} từ chối: {{reason}}',
                    ],
                ],
                'params_schema' => [
                    'required' => ['recipe_name', 'reviewer', 'reason'],
                    'optional' => [],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                'key' => 'material_lot.recall_affected',
                'content' => [
                    'ja' => [
                        'title' => 'リコール通知：ロット {{lot_code}}',
                        'body' => '{{material_name}} のロット {{lot_code}} がリコール対象です。理由：{{reason}}。影響注文 {{affected_orders_count}} 件',
                    ],
                    'en' => [
                        'title' => 'Recall alert: lot {{lot_code}}',
                        'body' => 'Lot {{lot_code}} of {{material_name}} is under recall. Reason: {{reason}}. {{affected_orders_count}} orders affected',
                    ],
                    'vi' => [
                        'title' => 'Thu hồi: lô {{lot_code}}',
                        'body' => 'Lô {{lot_code}} của {{material_name}} bị thu hồi. Lý do: {{reason}}. {{affected_orders_count}} đơn bị ảnh hưởng',
                    ],
                ],
                'params_schema' => [
                    'required' => ['lot_code', 'material_name', 'reason', 'affected_orders_count'],
                    'optional' => ['recall_code', 'initiated_by'],
                ],
                'default_channels' => ['in_app', 'email'],
            ],
            [
                'key' => 'material_lot.expiring',
                'content' => [
                    'ja' => [
                        'title' => '賞味期限間近：{{lot_code}}（残り {{days_until_expiry}} 日）',
                        'body' => '{{warehouse_name}} の {{material_name}}（ロット {{lot_code}}）は {{expiry_date}} に期限切れになります',
                    ],
                    'en' => [
                        'title' => 'Expiring soon: {{lot_code}} ({{days_until_expiry}} days left)',
                        'body' => '{{material_name}} lot {{lot_code}} at {{warehouse_name}} expires on {{expiry_date}}',
                    ],
                    'vi' => [
                        'title' => 'Sắp hết hạn: {{lot_code}} (còn {{days_until_expiry}} ngày)',
                        'body' => '{{material_name}} lô {{lot_code}} tại {{warehouse_name}} hết hạn vào {{expiry_date}}',
                    ],
                ],
                'params_schema' => [
                    'required' => ['lot_code', 'material_name', 'warehouse_name', 'expiry_date', 'days_until_expiry'],
                    'optional' => ['threshold_days'],
                ],
                'default_channels' => ['in_app'],
            ],
            // T8.6 — Product approval lifecycle
            [
                'key' => 'product.submitted_for_approval',
                'content' => [
                    'ja' => ['title' => '商品承認申請：{{product_name}}', 'body' => '{{product_name}} が承認待ちになりました。レビューをお願いします。'],
                    'en' => ['title' => 'Product pending approval: {{product_name}}', 'body' => '{{product_name}} has been submitted for approval.'],
                    'vi' => ['title' => 'Sản phẩm chờ duyệt: {{product_name}}', 'body' => '{{product_name}} đã được gửi để phê duyệt.'],
                ],
                'params_schema' => ['required' => ['product_name'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            [
                'key' => 'product.approved',
                'content' => [
                    'ja' => ['title' => '商品承認完了：{{product_name}}', 'body' => '{{product_name}} が承認されました。'],
                    'en' => ['title' => 'Product approved: {{product_name}}', 'body' => '{{product_name}} has been approved.'],
                    'vi' => ['title' => 'Sản phẩm đã được duyệt: {{product_name}}', 'body' => '{{product_name}} đã được phê duyệt.'],
                ],
                'params_schema' => ['required' => ['product_name'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            [
                'key' => 'product.rejected',
                'content' => [
                    'ja' => ['title' => '商品却下：{{product_name}}', 'body' => '{{product_name}} が却下されました。理由を確認してください。'],
                    'en' => ['title' => 'Product rejected: {{product_name}}', 'body' => '{{product_name}} has been rejected. Please check the reason.'],
                    'vi' => ['title' => 'Sản phẩm bị từ chối: {{product_name}}', 'body' => '{{product_name}} đã bị từ chối. Vui lòng kiểm tra lý do.'],
                ],
                'params_schema' => ['required' => ['product_name'], 'optional' => ['reason']],
                'default_channels' => ['in_app', 'realtime', 'email'],
            ],
            // T8.7 — Menu approval lifecycle
            [
                'key' => 'menu.submitted_for_approval',
                'content' => [
                    'ja' => ['title' => 'メニュー承認申請：{{menu_name}}', 'body' => '{{menu_name}} が承認待ちになりました。レビューをお願いします。'],
                    'en' => ['title' => 'Menu pending approval: {{menu_name}}', 'body' => '{{menu_name}} has been submitted for approval.'],
                    'vi' => ['title' => 'Menu chờ duyệt: {{menu_name}}', 'body' => '{{menu_name}} đã được gửi để phê duyệt.'],
                ],
                'params_schema' => ['required' => ['menu_name'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            [
                'key' => 'menu.approved',
                'content' => [
                    'ja' => ['title' => 'メニュー承認完了：{{menu_name}}', 'body' => '{{menu_name}} が承認されました。'],
                    'en' => ['title' => 'Menu approved: {{menu_name}}', 'body' => '{{menu_name}} has been approved.'],
                    'vi' => ['title' => 'Menu đã được duyệt: {{menu_name}}', 'body' => '{{menu_name}} đã được phê duyệt.'],
                ],
                'params_schema' => ['required' => ['menu_name'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            [
                'key' => 'menu.rejected',
                'content' => [
                    'ja' => ['title' => 'メニュー却下：{{menu_name}}', 'body' => '{{menu_name}} が却下されました。理由を確認してください。'],
                    'en' => ['title' => 'Menu rejected: {{menu_name}}', 'body' => '{{menu_name}} has been rejected.'],
                    'vi' => ['title' => 'Menu bị từ chối: {{menu_name}}', 'body' => '{{menu_name}} đã bị từ chối.'],
                ],
                'params_schema' => ['required' => ['menu_name'], 'optional' => ['reason']],
                'default_channels' => ['in_app', 'realtime', 'email'],
            ],
            // T8.8 — StockTransaction lifecycle
            [
                'key' => 'stock_transaction.submitted',
                'content' => [
                    'ja' => ['title' => '在庫取引申請：{{transaction_code}}', 'body' => '在庫取引 {{transaction_code}} が申請されました。'],
                    'en' => ['title' => 'Stock transaction submitted: {{transaction_code}}', 'body' => 'Stock transaction {{transaction_code}} has been submitted for review.'],
                    'vi' => ['title' => 'Giao dịch kho đã gửi: {{transaction_code}}', 'body' => 'Giao dịch {{transaction_code}} đã được gửi để duyệt.'],
                ],
                'params_schema' => ['required' => ['transaction_code'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            [
                'key' => 'stock_transaction.approved',
                'content' => [
                    'ja' => ['title' => '在庫取引承認：{{transaction_code}}', 'body' => '在庫取引 {{transaction_code}} が承認されました。'],
                    'en' => ['title' => 'Stock transaction approved: {{transaction_code}}', 'body' => 'Stock transaction {{transaction_code}} has been approved.'],
                    'vi' => ['title' => 'Giao dịch kho đã duyệt: {{transaction_code}}', 'body' => 'Giao dịch {{transaction_code}} đã được phê duyệt.'],
                ],
                'params_schema' => ['required' => ['transaction_code'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            [
                'key' => 'stock_transaction.rejected',
                'content' => [
                    'ja' => ['title' => '在庫取引却下：{{transaction_code}}', 'body' => '在庫取引 {{transaction_code}} が却下されました。'],
                    'en' => ['title' => 'Stock transaction rejected: {{transaction_code}}', 'body' => 'Stock transaction {{transaction_code}} has been rejected.'],
                    'vi' => ['title' => 'Giao dịch kho bị từ chối: {{transaction_code}}', 'body' => 'Giao dịch {{transaction_code}} đã bị từ chối.'],
                ],
                'params_schema' => ['required' => ['transaction_code'], 'optional' => ['reason']],
                'default_channels' => ['in_app', 'realtime', 'email'],
            ],
            // T8.9 — StockTransfer lifecycle
            [
                'key' => 'stock_transfer.in_transit',
                'content' => [
                    'ja' => ['title' => '在庫移動中：{{transfer_code}}', 'body' => '在庫移動 {{transfer_code}} が発送されました。受け入れ準備をしてください。'],
                    'en' => ['title' => 'Stock transfer in transit: {{transfer_code}}', 'body' => 'Transfer {{transfer_code}} is on the way. Prepare for receipt.'],
                    'vi' => ['title' => 'Chuyển kho đang vận chuyển: {{transfer_code}}', 'body' => 'Chuyển kho {{transfer_code}} đang trên đường. Chuẩn bị tiếp nhận.'],
                ],
                'params_schema' => ['required' => ['transfer_code'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            [
                'key' => 'stock_transfer.received',
                'content' => [
                    'ja' => ['title' => '在庫移動完了：{{transfer_code}}', 'body' => '在庫移動 {{transfer_code}} が受け入れられました。'],
                    'en' => ['title' => 'Stock transfer received: {{transfer_code}}', 'body' => 'Transfer {{transfer_code}} has been received.'],
                    'vi' => ['title' => 'Chuyển kho đã nhận: {{transfer_code}}', 'body' => 'Chuyển kho {{transfer_code}} đã được tiếp nhận.'],
                ],
                'params_schema' => ['required' => ['transfer_code'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            // T8.10 — Device pairing + offline
            [
                'key' => 'device.paired',
                'content' => [
                    'ja' => ['title' => 'デバイスペアリング完了：{{device_name}}', 'body' => '{{device_name}} がペアリングされアクティブになりました。'],
                    'en' => ['title' => 'Device paired: {{device_name}}', 'body' => '{{device_name}} has been paired and is now active.'],
                    'vi' => ['title' => 'Thiết bị đã ghép nối: {{device_name}}', 'body' => '{{device_name}} đã được ghép nối và hoạt động.'],
                ],
                'params_schema' => ['required' => ['device_name'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            [
                'key' => 'device.unpaired',
                'content' => [
                    'ja' => ['title' => 'デバイスペアリング解除：{{device_name}}', 'body' => '{{device_name}} のペアリングが解除されました。確認してください。'],
                    'en' => ['title' => 'Device unpaired: {{device_name}}', 'body' => '{{device_name}} has been unpaired. Please investigate.'],
                    'vi' => ['title' => 'Thiết bị đã hủy ghép nối: {{device_name}}', 'body' => '{{device_name}} đã bị hủy ghép nối. Vui lòng kiểm tra.'],
                ],
                'params_schema' => ['required' => ['device_name'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime', 'email'],
            ],
            [
                'key' => 'device.offline',
                'content' => [
                    'ja' => ['title' => 'デバイスオフライン：{{device_name}}', 'body' => '{{device_name}} が {{minutes_offline}} 分間オフラインです。確認してください。'],
                    'en' => ['title' => 'Device offline: {{device_name}}', 'body' => '{{device_name}} has been offline for {{minutes_offline}} minutes.'],
                    'vi' => ['title' => 'Thiết bị offline: {{device_name}}', 'body' => '{{device_name}} đã offline {{minutes_offline}} phút.'],
                ],
                'params_schema' => ['required' => ['device_name', 'minutes_offline'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime'],
            ],
            // T8.11 — Coupon redemption + expiry
            [
                'key' => 'coupon.redeemed',
                'content' => [
                    'ja' => ['title' => 'クーポン使用', 'body' => 'クーポンが使用されました。'],
                    'en' => ['title' => 'Coupon redeemed', 'body' => 'A coupon has been redeemed.'],
                    'vi' => ['title' => 'Phiếu giảm giá đã được dùng', 'body' => 'Một phiếu giảm giá đã được sử dụng.'],
                ],
                'params_schema' => ['required' => [], 'optional' => ['coupon_code', 'customer_name']],
                'default_channels' => ['in_app'],
            ],
            [
                'key' => 'coupon.expiring_soon',
                'content' => [
                    'ja' => ['title' => 'クーポン有効期限間近（残り {{hours_remaining}} 時間）', 'body' => 'クーポンの有効期限が {{hours_remaining}} 時間後に切れます。'],
                    'en' => ['title' => 'Coupon expiring soon ({{hours_remaining}}h left)', 'body' => 'A coupon will expire in {{hours_remaining}} hours.'],
                    'vi' => ['title' => 'Phiếu giảm giá sắp hết hạn (còn {{hours_remaining}} giờ)', 'body' => 'Phiếu giảm giá sẽ hết hạn sau {{hours_remaining}} giờ.'],
                ],
                'params_schema' => ['required' => ['hours_remaining'], 'optional' => ['coupon_code']],
                'default_channels' => ['in_app', 'email'],
            ],
            [
                'key' => 'coupon.expired',
                'content' => [
                    'ja' => ['title' => 'クーポン有効期限切れ', 'body' => 'クーポンの有効期限が切れました。'],
                    'en' => ['title' => 'Coupon expired', 'body' => 'A coupon has expired.'],
                    'vi' => ['title' => 'Phiếu giảm giá đã hết hạn', 'body' => 'Một phiếu giảm giá đã hết hạn.'],
                ],
                'params_schema' => ['required' => [], 'optional' => ['coupon_code']],
                'default_channels' => ['in_app'],
            ],
            // T8.12 — Brand status changed
            [
                'key' => 'brand.status_changed',
                'content' => [
                    'ja' => ['title' => 'ブランドステータス変更：{{brand_name}}', 'body' => '{{brand_name}} のアクティブ状態が変更されました。'],
                    'en' => ['title' => 'Brand status changed: {{brand_name}}', 'body' => 'The active status of {{brand_name}} has changed.'],
                    'vi' => ['title' => 'Trạng thái thương hiệu thay đổi: {{brand_name}}', 'body' => 'Trạng thái hoạt động của {{brand_name}} đã thay đổi.'],
                ],
                'params_schema' => ['required' => ['brand_name'], 'optional' => []],
                'default_channels' => ['in_app', 'realtime', 'email'],
            ],
            [
                'key' => 'order.status_changed',
                'content' => [
                    'ja' => [
                        'title' => 'オーダー {{order_code}} ステータス変更',
                        'body' => '{{shop_name}} のオーダーが {{new_status}} に変更されました',
                    ],
                    'en' => [
                        'title' => 'Order {{order_code}} status changed',
                        'body' => '{{shop_name}} order is now {{new_status}}',
                    ],
                    'vi' => [
                        'title' => 'Đơn {{order_code}} đổi trạng thái',
                        'body' => 'Đơn ở {{shop_name}} đã chuyển sang {{new_status}}',
                    ],
                ],
                'params_schema' => [
                    'required' => ['order_code', 'new_status', 'shop_name'],
                    'optional' => [],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                // #1123 (D2) — a card dispute is money leaving the merchant
                // balance unilaterally; the shop manager must hear about it.
                // Production seeding rides the companion data migration
                // (deploy runs migrate only, never db:seed).
                'key' => 'payment.disputed',
                'content' => [
                    'ja' => [
                        'title' => 'チャージバック：{{order_code}}（{{amount}}）',
                        'body' => '注文 {{order_code}} の決済に対して異議申し立てが発生しました（{{phase}}）。Stripeダッシュボードで対応してください。',
                    ],
                    'en' => [
                        'title' => 'Chargeback: {{order_code}} ({{amount}})',
                        'body' => 'A dispute was raised against the payment for order {{order_code}} ({{phase}}). Respond in the Stripe dashboard.',
                    ],
                    'vi' => [
                        'title' => 'Chargeback: {{order_code}} ({{amount}})',
                        'body' => 'Khoản thanh toán của đơn {{order_code}} bị khiếu nại ({{phase}}). Xử lý trong Stripe dashboard.',
                    ],
                ],
                'params_schema' => [
                    'required' => ['order_code', 'amount', 'phase'],
                    'optional' => ['dispute_status'],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                // plan-052 T2.3 (#1166) — a printer that stopped speaking.
                // Cloud never PROBES a shop printer (P-38); it notices that the
                // machine stopped being mentioned. `detection` says which kind
                // of silence it was, so nobody debugs the wrong layer.
                'key' => 'print.printer_silent',
                'content' => [
                    'ja' => [
                        'title' => 'プリンター無応答：{{printer_name}}（{{shop_name}}）',
                        'body' => '{{printer_name}} が {{minutes}} 分間応答していません（検知: {{detection}}）。電源とネットワークをご確認ください。',
                    ],
                    'en' => [
                        'title' => 'Printer silent: {{printer_name}} ({{shop_name}})',
                        'body' => '{{printer_name}} has been silent for {{minutes}} minutes (detected by {{detection}}). Check its power and network.',
                    ],
                    'vi' => [
                        'title' => 'Máy in im lặng: {{printer_name}} ({{shop_name}})',
                        'body' => '{{printer_name}} đã im lặng {{minutes}} phút (phát hiện qua {{detection}}). Kiểm tra nguồn điện và mạng.',
                    ],
                ],
                'params_schema' => [
                    'required' => ['printer_name', 'minutes'],
                    'optional' => ['shop_name', 'detection'],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                // plan-052 T2.3 — the needs_attention backlog. `needs_attention`
                // is the ACK-lost state: sent, never confirmed (P-03). One is
                // normal; a pile means something is wrong with a machine.
                'key' => 'print.jobs_need_attention',
                'content' => [
                    'ja' => [
                        'title' => '印刷ジョブ要確認：{{count}} 件（{{shop_name}}）',
                        'body' => '確認待ちの印刷ジョブが {{count}} 件（しきい値 {{threshold}}）あります。印刷ジョブ画面で対応してください。',
                    ],
                    'en' => [
                        'title' => 'Print jobs need attention: {{count}} ({{shop_name}})',
                        'body' => '{{count}} print jobs are waiting for a decision (threshold {{threshold}}). Review them on the Print jobs screen.',
                    ],
                    'vi' => [
                        'title' => 'Lệnh in cần xử lý: {{count}} ({{shop_name}})',
                        'body' => 'Có {{count}} lệnh in đang chờ quyết định (ngưỡng {{threshold}}). Xem trang Lệnh in để xử lý.',
                    ],
                ],
                'params_schema' => [
                    'required' => ['count'],
                    'optional' => ['shop_name', 'threshold'],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                // plan-052 T2.3 — a MONEY document that did not come out.
                // Fires from the first occurrence: there is no acceptable
                // number of receipts a shop silently fails to print (PR1).
                // Deliberately does NOT offer to reprint — that decision runs
                // through the reprint gate so it earns 「Bản in #N」 (P-10).
                'key' => 'print.money_document_failed',
                'content' => [
                    'ja' => [
                        'title' => '証憑の印刷失敗：{{count}} 件（{{shop_name}}）',
                        'body' => 'レシート／赤伝／請求書の印刷が {{count}} 件失敗しています。自動再印刷は行いません（二重発行防止）。担当者の確認が必要です。',
                    ],
                    'en' => [
                        'title' => 'Money document failed to print: {{count}} ({{shop_name}})',
                        'body' => '{{count}} receipt / red-invoice / debt-slip jobs did not print. They are never reprinted automatically (no second original) — a person must decide.',
                    ],
                    'vi' => [
                        'title' => 'Chứng từ tiền in lỗi: {{count}} ({{shop_name}})',
                        'body' => '{{count}} lệnh in hoá đơn / đỏ / phiếu ghi nợ chưa ra giấy. Hệ thống KHÔNG tự in lại (tránh hai bản gốc) — cần người quyết định.',
                    ],
                ],
                'params_schema' => [
                    'required' => ['count'],
                    'optional' => ['shop_name'],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                // #2696 — đơn còn treo tiền lúc mở ca kế. Fail-open: hỏng
                // template không được chặn lượt mở ca; chuông thiếu copy vẫn
                // tốt hơn im lặng.
                'key' => 'till.unresolved_orders',
                'content' => [
                    'ja' => [
                        'title' => '未精算の伝票：{{order_count}} 件（{{shop_name}}）',
                        'body' => '前シフト精算後も {{order_count}} 件が会計中／チェックアウトのままです（未収 {{outstanding_amount}} {{currency_code}}）。{{order_codes}}',
                    ],
                    'en' => [
                        'title' => 'Unresolved bills: {{order_count}} ({{shop_name}})',
                        'body' => '{{order_count}} order(s) are still paying/checkout after the previous shift closed (outstanding {{outstanding_amount}} {{currency_code}}). {{order_codes}}',
                    ],
                    'vi' => [
                        'title' => 'Đơn còn treo tiền: {{order_count}} ({{shop_name}})',
                        'body' => '{{order_count}} đơn vẫn paying/checkout sau lần đóng ca trước (còn thiếu {{outstanding_amount}} {{currency_code}}). {{order_codes}}',
                    ],
                ],
                'params_schema' => [
                    'required' => ['shop_name', 'order_count', 'outstanding_amount', 'currency_code'],
                    'optional' => ['order_codes', 'outstanding_order_count', 'pending_close_count'],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                // #2737 (nửa còn lại của #2721) — ca đơn kẹt `paying`/`checkout`
                // NHƯNG đã thu đủ tiền. Copy của `till.unresolved_orders` nói
                // "未収 {{outstanding_amount}} {{currency_code}}", nên ca này in ra
                // 「未収 0 JPY」: vô nghĩa, và đọc như một lỗ tiền không có thật.
                //
                // `TemplateRenderer` không có cú pháp điều kiện — một key = MỘT
                // đoạn copy — nên bản riêng buộc phải là một KEY riêng. Không có
                // placeholder tiền ở đây là chủ ý: outstanding = 0 theo định
                // nghĩa của ca này, và một con số 0 hiện trên chuông là thứ duy
                // nhất người nhận sẽ nhớ.
                //
                // `needs_close_only` KHÔNG có mặt trong params_schema: đo trên
                // `TillSessionService` (PR #2735) thì nó là cờ của TỪNG DÒNG đơn
                // trong `$preview['orders']`, không phải param gửi lên template.
                // Params thật mà emitter phát cho ca này là `pending_close_count`
                // (+ `outstanding_order_count` = 0). Khai một param không ai gửi
                // thì renderer nội suy ra chuỗi RỖNG và chỉ log warning — hỏng
                // im lặng, đúng thứ khai schema sinh ra để chặn.
                'key' => 'till.unresolved_orders.pending_close',
                'content' => [
                    'ja' => [
                        'title' => '締めるだけの伝票：{{pending_close_count}} 件（{{shop_name}}）',
                        'body' => '会計は済んでいます — 不足金はありません。前シフト精算後も {{pending_close_count}} 件が会計中／チェックアウトのまま残っているので、伝票を締めてください。{{order_codes}}',
                    ],
                    'en' => [
                        'title' => 'Bills to close: {{pending_close_count}} ({{shop_name}})',
                        'body' => 'Payment is complete — nothing is short. {{pending_close_count}} order(s) are still sitting in paying/checkout after the previous shift closed; they only need closing. {{order_codes}}',
                    ],
                    'vi' => [
                        'title' => 'Đơn chỉ cần đóng: {{pending_close_count}} ({{shop_name}})',
                        'body' => 'Đã thu đủ tiền — không thiếu đồng nào. {{pending_close_count}} đơn vẫn ở trạng thái paying/checkout sau lần đóng ca trước; chỉ cần đóng đơn. {{order_codes}}',
                    ],
                ],
                'params_schema' => [
                    'required' => ['shop_name', 'pending_close_count'],
                    'optional' => ['order_codes', 'order_count', 'outstanding_order_count'],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                // #3188 — type này khai trong `DEFAULT_PRIORITIES` từ #2697 mà
                // KHÔNG có template. `snapshotTemplateContent()` là no-op khi
                // thiếu template, nên thông báo vẫn ra đời, chỉ là không có nội
                // dung nào được chốt lại. Rào lẽ ra bắt được, nhưng nó dùng danh
                // sách CHO PHÉP nên type mới rơi qua trong im lặng.
                //
                // Params lấy từ chính emitter (`StockDriftAlertService`), không
                // phải đoán: `order_code` · `shop_name` · `stage` · `error` ·
                // `order_id` · `order_item_id`.
                //
                // Copy cố ý nêu `stage`: trừ kho hỏng ở lúc THÊM món khác hẳn
                // lúc SỬA món, và người đi tìm hàng lệch cần biết ngay chỗ nào.
                'key' => 'inventory.stock_drift',
                'content' => [
                    'ja' => [
                        'title' => '在庫ズレの疑い：{{order_code}}（{{shop_name}}）',
                        'body' => '在庫の引き落としに失敗しましたが、注文の変更はそのまま確定しています（段階：{{stage}}）。実在庫と帳簿がズレている可能性があります。詳細：{{error}}',
                    ],
                    'en' => [
                        'title' => 'Possible stock drift: {{order_code}} ({{shop_name}})',
                        'body' => 'Stock deduction failed but the order change was kept (stage: {{stage}}). Counted stock and the ledger may now disagree. Detail: {{error}}',
                    ],
                    'vi' => [
                        'title' => 'Nghi lệch kho: {{order_code}} ({{shop_name}})',
                        'body' => 'Trừ kho thất bại nhưng thay đổi đơn vẫn được giữ (giai đoạn: {{stage}}). Kho đếm tay và sổ có thể đang lệch nhau. Chi tiết: {{error}}',
                    ],
                ],
                'params_schema' => [
                    'required' => ['order_code', 'shop_name', 'stage'],
                    'optional' => ['error', 'order_id', 'order_item_id'],
                ],
                'default_channels' => ['in_app'],
            ],
            [
                // #3188 — đã gửi THẬT 4 lần trên production (13/08 → 17/08) mà
                // không có template, nên cả bốn thông báo đó không mang nội dung
                // nào. Đây là chuông tiền: PayPay trả về một sự kiện mà Cloud
                // không quy được về giao dịch nào.
                //
                // Params từ `ProviderEventApplicator`: `merchant_payment_id` ·
                // `outcome` · `event_type` · `provider_event_id` ·
                // `connection_id`.
                //
                // `merchant_payment_id` để ở `required` vì đó là thứ DUY NHẤT
                // tra ngược được sang PayPay; một chuông báo "có sự kiện lạ" mà
                // không kèm mã thì người nhận không làm được gì.
                'key' => 'payment.paypay_qr_unbookable',
                'content' => [
                    'ja' => [
                        'title' => 'PayPay 未計上イベント：{{merchant_payment_id}}',
                        'body' => 'PayPay から受信したイベントを取引に紐付けられませんでした（結果：{{outcome}}／種別：{{event_type}}）。入金の消込は手作業での確認が必要です。',
                    ],
                    'en' => [
                        'title' => 'PayPay event not booked: {{merchant_payment_id}}',
                        'body' => 'A PayPay event could not be attributed to any transaction (outcome: {{outcome}}, event: {{event_type}}). Settlement for it needs a manual check.',
                    ],
                    'vi' => [
                        'title' => 'Sự kiện PayPay không ghi sổ được: {{merchant_payment_id}}',
                        'body' => 'Một sự kiện PayPay không quy được về giao dịch nào (kết quả: {{outcome}}, loại: {{event_type}}). Phần đối soát khoản này phải kiểm tay.',
                    ],
                ],
                'params_schema' => [
                    'required' => ['merchant_payment_id', 'outcome'],
                    'optional' => ['event_type', 'provider_event_id', 'connection_id'],
                ],
                'default_channels' => ['in_app'],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::templates() as $tpl) {
            NotificationTemplate::query()->firstOrCreate(
                ['key' => $tpl['key']],
                [
                    'content' => $tpl['content'],
                    'params_schema' => $tpl['params_schema'],
                    'default_channels' => $tpl['default_channels'],
                    'is_system' => true,
                ],
            );
        }
    }
}
