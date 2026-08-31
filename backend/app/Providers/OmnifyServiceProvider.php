<?php

namespace App\Providers;

use App\Models\Allergen;
use App\Models\AllergenMaterial;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchFloatingSectionOverride;
use App\Models\BranchReview;
use App\Models\BranchScheduleOverride;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\CashDeviceErrorEvent;
use App\Models\CashDeviceInventorySnapshot;
use App\Models\CashDeviceTransaction;
use App\Models\CatalogRevision;
use App\Models\Category;
use App\Models\CategoryProduct;
use App\Models\Coupon;
use App\Models\CouponBranch;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\CustomerPointEntry;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\DevicePaymentOption;
use App\Models\DeviceSigningKey;
use App\Models\DisposalRecord;
use App\Models\ExpiryAlert;
use App\Models\File;
use App\Models\FloatingSection;
use App\Models\FloatingSectionProduct;
use App\Models\FloatingSectionProductSku;
use App\Models\FloatingSectionProductToppingItemOverride;
use App\Models\FloatingSectionSchedule;
use App\Models\GatewayPayout;
use App\Models\GenealogyLink;
use App\Models\IdentityInboxEntry;
use App\Models\InvoiceCounter;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\MaterialSubstitutionRule;
use App\Models\MaterialUnit;
use App\Models\Menu;
use App\Models\MenuAvailabilityEvent;
use App\Models\MenuMenuSection;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuProductToppingItemOverride;
use App\Models\MenuPromotion;
use App\Models\MenuPromotionCategory;
use App\Models\MenuPromotionProduct;
use App\Models\MenuSchedule;
use App\Models\MenuScheduleDate;
use App\Models\MenuSection;
use App\Models\MoneyReconciliationTask;
use App\Models\Notification;
use App\Models\NotificationAudience;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationDelivery;
use App\Models\NotificationDigestPreference;
use App\Models\NotificationEmailSuppression;
use App\Models\NotificationPreference;
use App\Models\NotificationRecipient;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use App\Models\NotificationSchedule;
use App\Models\NotificationTemplate;
use App\Models\OrderCodeCounter;
use App\Models\OrderCondition;
use App\Models\OrderItemTopping;
use App\Models\OrderMoneyOverwrite;
use App\Models\OrderPayment;
use App\Models\OrderPaymentIntent;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentMethod;
use App\Models\PaymentPolicyRevision;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentRefund;
use App\Models\PaymentSettlement;
use App\Models\PeripheralDevice;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\PointReward;
use App\Models\PointRewardBranch;
use App\Models\Post;
use App\Models\PostBranch;
use App\Models\PostCategory;
use App\Models\PostPostTag;
use App\Models\PostTag;
use App\Models\Printer;
use App\Models\PrintImageAsset;
use App\Models\PrintImageRaster;
use App\Models\PrintJob;
use App\Models\PrintJobResolution;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductReview;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ProductType;
use App\Models\Recall;
use App\Models\RecallAffectedOrder;
use App\Models\RecallDrill;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\RoleUserPivot;
use App\Models\SettlementReportBatch;
use App\Models\ShopOrderSetting;
use App\Models\ShopPaymentOption;
use App\Models\StockAlert;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableStatusChange;
use App\Models\TableTemplate;
use App\Models\TaxType;
use App\Models\TaxTypeRate;
use App\Models\Till;
use App\Models\TillCashDenominationCount;
use App\Models\TillCashEvent;
use App\Models\TillSession;
use App\Models\TillSettlementTenderDetail;
use App\Models\TillTenderCategory;
use App\Models\TillTenderType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\User;
use App\Models\VariantUnit;
use App\Models\VoidReason;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Models\WorkstationLogRecord;
use App\Models\WorkstationLogRequest;
use App\Models\Zone;
use App\Models\ZoneTemplate;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Omnify Service Provider
 *
 * DO NOT EDIT - This file is auto-generated by Omnify.
 * Any changes will be overwritten on next generation.
 *
 * - Loads Omnify migrations from database/migrations/omnify
 * - Registers morph map for polymorphic relationships
 *
 * @generated by omnify
 */
class OmnifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load Omnify migrations: the default-connection directory plus every
        // per-connection subdirectory (multi-database setups route each domain's
        // migrations into its own subfolder; those migrations target their
        // connection via Schema::connection(), so a single migrate run suffices).
        $omnifyMigrations = database_path('migrations/omnify');
        $this->loadMigrationsFrom($omnifyMigrations);
        foreach (glob($omnifyMigrations.'/*', GLOB_ONLYDIR) as $connectionDir) {
            $this->loadMigrationsFrom($connectionDir);
        }

        // Merge Omnify aliases without requiring every host-application model
        // (Sanctum tokenables, media, notifications, etc.) to be listed here.
        Relation::morphMap([
            'Allergen' => Allergen::class,
            'AllergenMaterial' => AllergenMaterial::class,
            'AuditLog' => AuditLog::class,
            'Branch' => Branch::class,
            'BranchFloatingSectionOverride' => BranchFloatingSectionOverride::class,
            'BranchReview' => BranchReview::class,
            'BranchScheduleOverride' => BranchScheduleOverride::class,
            'Brand' => Brand::class,
            'BrandOrderPolicy' => BrandOrderPolicy::class,
            'CashDeviceErrorEvent' => CashDeviceErrorEvent::class,
            'CashDeviceInventorySnapshot' => CashDeviceInventorySnapshot::class,
            'CashDeviceTransaction' => CashDeviceTransaction::class,
            'CatalogRevision' => CatalogRevision::class,
            'Category' => Category::class,
            'CategoryProduct' => CategoryProduct::class,
            'Coupon' => Coupon::class,
            'CouponBranch' => CouponBranch::class,
            'CouponRedemption' => CouponRedemption::class,
            'Customer' => Customer::class,
            'CustomerOrder' => CustomerOrder::class,
            'CustomerOrderItem' => CustomerOrderItem::class,
            'CustomerPointEntry' => CustomerPointEntry::class,
            'Denomination' => Denomination::class,
            'Device' => Device::class,
            'DevicePaymentOption' => DevicePaymentOption::class,
            'DeviceSigningKey' => DeviceSigningKey::class,
            'DisposalRecord' => DisposalRecord::class,
            'ExpiryAlert' => ExpiryAlert::class,
            'File' => File::class,
            'FloatingSection' => FloatingSection::class,
            'FloatingSectionProduct' => FloatingSectionProduct::class,
            'FloatingSectionProductSku' => FloatingSectionProductSku::class,
            'FloatingSectionProductToppingItemOverride' => FloatingSectionProductToppingItemOverride::class,
            'FloatingSectionSchedule' => FloatingSectionSchedule::class,
            'GatewayPayout' => GatewayPayout::class,
            'GenealogyLink' => GenealogyLink::class,
            'IdentityInboxEntry' => IdentityInboxEntry::class,
            'InvoiceCounter' => InvoiceCounter::class,
            'Material' => Material::class,
            'MaterialBatch' => MaterialBatch::class,
            'MaterialBatchItem' => MaterialBatchItem::class,
            'MaterialLot' => MaterialLot::class,
            'MaterialLotReservation' => MaterialLotReservation::class,
            'MaterialSubstitutionRule' => MaterialSubstitutionRule::class,
            'MaterialUnit' => MaterialUnit::class,
            'Menu' => Menu::class,
            'MenuAvailabilityEvent' => MenuAvailabilityEvent::class,
            'MenuMenuSection' => MenuMenuSection::class,
            'MenuProduct' => MenuProduct::class,
            'MenuProductSku' => MenuProductSku::class,
            'MenuProductToppingItemOverride' => MenuProductToppingItemOverride::class,
            'MenuPromotion' => MenuPromotion::class,
            'MenuPromotionCategory' => MenuPromotionCategory::class,
            'MenuPromotionProduct' => MenuPromotionProduct::class,
            'MenuSchedule' => MenuSchedule::class,
            'MenuScheduleDate' => MenuScheduleDate::class,
            'MenuSection' => MenuSection::class,
            'MoneyReconciliationTask' => MoneyReconciliationTask::class,
            'Notification' => Notification::class,
            'NotificationAudience' => NotificationAudience::class,
            'NotificationChannelRoute' => NotificationChannelRoute::class,
            'NotificationDelivery' => NotificationDelivery::class,
            'NotificationDigestPreference' => NotificationDigestPreference::class,
            'NotificationEmailSuppression' => NotificationEmailSuppression::class,
            'NotificationPreference' => NotificationPreference::class,
            'NotificationRecipient' => NotificationRecipient::class,
            'NotificationRule' => NotificationRule::class,
            'NotificationRuleFiring' => NotificationRuleFiring::class,
            'NotificationSchedule' => NotificationSchedule::class,
            'NotificationTemplate' => NotificationTemplate::class,
            'OrderCodeCounter' => OrderCodeCounter::class,
            'OrderCondition' => OrderCondition::class,
            'OrderItemTopping' => OrderItemTopping::class,
            'OrderMoneyOverwrite' => OrderMoneyOverwrite::class,
            'OrderPayment' => OrderPayment::class,
            'OrderPaymentIntent' => OrderPaymentIntent::class,
            'Organization' => Organization::class,
            'PaymentAttempt' => PaymentAttempt::class,
            'PaymentGatewayConnection' => PaymentGatewayConnection::class,
            'PaymentGatewayConnectionOption' => PaymentGatewayConnectionOption::class,
            'PaymentGatewayOption' => PaymentGatewayOption::class,
            'PaymentGatewayProvider' => PaymentGatewayProvider::class,
            'PaymentMethod' => PaymentMethod::class,
            'PaymentPolicyRevision' => PaymentPolicyRevision::class,
            'PaymentProviderEvent' => PaymentProviderEvent::class,
            'PaymentRefund' => PaymentRefund::class,
            'PaymentSettlement' => PaymentSettlement::class,
            'PeripheralDevice' => PeripheralDevice::class,
            'Permission' => Permission::class,
            'PersonalAccessToken' => PersonalAccessToken::class,
            'PointReward' => PointReward::class,
            'PointRewardBranch' => PointRewardBranch::class,
            'Post' => Post::class,
            'PostBranch' => PostBranch::class,
            'PostCategory' => PostCategory::class,
            'PostPostTag' => PostPostTag::class,
            'PostTag' => PostTag::class,
            'PrintImageAsset' => PrintImageAsset::class,
            'PrintImageRaster' => PrintImageRaster::class,
            'PrintJob' => PrintJob::class,
            'PrintJobResolution' => PrintJobResolution::class,
            'PrintTemplate' => PrintTemplate::class,
            'Printer' => Printer::class,
            'Product' => Product::class,
            'ProductOption' => ProductOption::class,
            'ProductOptionValue' => ProductOptionValue::class,
            'ProductReview' => ProductReview::class,
            'ProductSku' => ProductSku::class,
            'ProductToppingGroup' => ProductToppingGroup::class,
            'ProductToppingGroupItemOverride' => ProductToppingGroupItemOverride::class,
            'ProductType' => ProductType::class,
            'ProductionOrder' => ProductionOrder::class,
            'ProductionOrderItem' => ProductionOrderItem::class,
            'Recall' => Recall::class,
            'RecallAffectedOrder' => RecallAffectedOrder::class,
            'RecallDrill' => RecallDrill::class,
            'Recipe' => Recipe::class,
            'Role' => Role::class,
            'RolePermission' => RolePermission::class,
            'RoleUserPivot' => RoleUserPivot::class,
            'SettlementReportBatch' => SettlementReportBatch::class,
            'ShopOrderSetting' => ShopOrderSetting::class,
            'ShopPaymentOption' => ShopPaymentOption::class,
            'StockAlert' => StockAlert::class,
            'StockCount' => StockCount::class,
            'StockCountItem' => StockCountItem::class,
            'StockLevel' => StockLevel::class,
            'StockMovement' => StockMovement::class,
            'StockTransaction' => StockTransaction::class,
            'StockTransactionItem' => StockTransactionItem::class,
            'StockTransfer' => StockTransfer::class,
            'StockTransferItem' => StockTransferItem::class,
            'Table' => Table::class,
            'TableSession' => TableSession::class,
            'TableStatusChange' => TableStatusChange::class,
            'TableTemplate' => TableTemplate::class,
            'TaxType' => TaxType::class,
            'TaxTypeRate' => TaxTypeRate::class,
            'Till' => Till::class,
            'TillCashDenominationCount' => TillCashDenominationCount::class,
            'TillCashEvent' => TillCashEvent::class,
            'TillSession' => TillSession::class,
            'TillSettlementTenderDetail' => TillSettlementTenderDetail::class,
            'TillTenderCategory' => TillTenderCategory::class,
            'TillTenderType' => TillTenderType::class,
            'ToppingGroup' => ToppingGroup::class,
            'ToppingGroupItem' => ToppingGroupItem::class,
            'ToppingGroupItemSku' => ToppingGroupItemSku::class,
            'User' => User::class,
            'VariantUnit' => VariantUnit::class,
            'VoidReason' => VoidReason::class,
            'Warehouse' => Warehouse::class,
            'WarehouseMember' => WarehouseMember::class,
            'WorkstationLogRecord' => WorkstationLogRecord::class,
            'WorkstationLogRequest' => WorkstationLogRequest::class,
            'Zone' => Zone::class,
            'ZoneTemplate' => ZoneTemplate::class,
        ]);
    }
}
