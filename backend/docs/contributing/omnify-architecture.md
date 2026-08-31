---
title: Omnify Architecture (auto-generated)
generated_at: 2026-07-30
generated_by: omnify
schema_count: 144
group_count: 11
---

# Omnify Architecture

> AUTO-GENERATED — do not edit. Regenerate via `omnify generate`.

This project follows the Omnify v5.8.x canonical Laravel layout. The
`app/Omnify/` zone is owned by the codegen; `app/Models/`, `app/Http/`,
`app/Services/`, `app/Policies/` are project-owned and extend the
auto-gen bases.

## Layer map

| Layer | Auto-gen base | User-editable (Laravel-canonical) |
|-------|---------------|-----------------------------------|
| Model | `/Users/satoshi01/Herd/dxs-product/backend/app/Models/Base/<Group>/<Name>.php` | `/Users/satoshi01/Herd/dxs-product/backend/app/Models/<Name>.php` (FLAT) |
| Translation | `/Users/satoshi01/Herd/dxs-product/backend/app/Models/Base/Translation/<Name>Translation.php` | `/Users/satoshi01/Herd/dxs-product/backend/app/Models/Translation/<Name>Translation.php` |
| Pivot | `/Users/satoshi01/Herd/dxs-product/backend/app/Models/Base/Pivot/<Name>.php` | `/Users/satoshi01/Herd/dxs-product/backend/app/Models/Pivot/<Name>.php` |
| Enum | `/Users/satoshi01/Herd/dxs-product/backend/app/Omnify/Enums/<Name>Enum.php` | _(no user-editable)_ |
| Request | `/Users/satoshi01/Herd/dxs-product/backend/app/Http/Requests/OmnifyBase/<Group>/<Name>StoreRequest.php` | `/Users/satoshi01/Herd/dxs-product/backend/app/Http/Requests/<Name>StoreRequest.php` (FLAT) |
| Resource | `/Users/satoshi01/Herd/dxs-product/backend/app/Http/Resources/OmnifyBase/<Group>/<Name>Resource.php` | `/Users/satoshi01/Herd/dxs-product/backend/app/Http/Resources/<Name>Resource.php` (FLAT) |
| Service | `/Users/satoshi01/Herd/dxs-product/backend/app/Services/Omnify/OmnifyBase/<Group>/<Name>Service.php` | `/Users/satoshi01/Herd/dxs-product/backend/app/Services/Omnify/<Name>Service.php` (FLAT) |
| Policy | `/Users/satoshi01/Herd/dxs-product/backend/app/Policies/Omnify/Base/<Group>/<Name>Policy.php` | `/Users/satoshi01/Herd/dxs-product/backend/app/Policies/Omnify/<Name>Policy.php` (FLAT) |

## Schema → Base FQN reference

Every schema in the project, organized by YAML group folder. The
FQN columns are the **base** classes — extend these from your
user-editable wrappers.

### Device (7 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| Device | `App\Models\Base\DeviceBaseModel` | `App\Services\Omnify\OmnifyBase\DeviceServiceBase` | `App\Http\Resources\OmnifyBase\DeviceResourceBase` | `App\Http\Requests\OmnifyBase\DeviceStoreRequestBase` | `App\Policies\Omnify\Base\DevicePolicyBase` |
| DeviceSigningKey | `App\Models\Base\DeviceSigningKeyBaseModel` | `App\Services\Omnify\OmnifyBase\DeviceSigningKeyServiceBase` | `App\Http\Resources\OmnifyBase\DeviceSigningKeyResourceBase` | `App\Http\Requests\OmnifyBase\DeviceSigningKeyStoreRequestBase` | `App\Policies\Omnify\Base\DeviceSigningKeyPolicyBase` |
| PeripheralDevice | `App\Models\Base\PeripheralDeviceBaseModel` | `App\Services\Omnify\OmnifyBase\PeripheralDeviceServiceBase` | `App\Http\Resources\OmnifyBase\PeripheralDeviceResourceBase` | `App\Http\Requests\OmnifyBase\PeripheralDeviceStoreRequestBase` | `App\Policies\Omnify\Base\PeripheralDevicePolicyBase` |
| PrintJob | `App\Models\Base\PrintJobBaseModel` | `App\Services\Omnify\OmnifyBase\PrintJobServiceBase` | `App\Http\Resources\OmnifyBase\PrintJobResourceBase` | `App\Http\Requests\OmnifyBase\PrintJobStoreRequestBase` | `App\Policies\Omnify\Base\PrintJobPolicyBase` |
| PrintJobResolution | `App\Models\Base\PrintJobResolutionBaseModel` | `App\Services\Omnify\OmnifyBase\PrintJobResolutionServiceBase` | `App\Http\Resources\OmnifyBase\PrintJobResolutionResourceBase` | `App\Http\Requests\OmnifyBase\PrintJobResolutionStoreRequestBase` | `App\Policies\Omnify\Base\PrintJobResolutionPolicyBase` |
| PrintTemplate | `App\Models\Base\PrintTemplateBaseModel` | `App\Services\Omnify\OmnifyBase\PrintTemplateServiceBase` | `App\Http\Resources\OmnifyBase\PrintTemplateResourceBase` | `App\Http\Requests\OmnifyBase\PrintTemplateStoreRequestBase` | `App\Policies\Omnify\Base\PrintTemplatePolicyBase` |
| Printer | `App\Models\Base\PrinterBaseModel` | `App\Services\Omnify\OmnifyBase\PrinterServiceBase` | `App\Http\Resources\OmnifyBase\PrinterResourceBase` | `App\Http\Requests\OmnifyBase\PrinterStoreRequestBase` | `App\Policies\Omnify\Base\PrinterPolicyBase` |

### Inventory (25 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| DisposalRecord | `App\Models\Base\DisposalRecordBaseModel` | `App\Services\Omnify\OmnifyBase\DisposalRecordServiceBase` | `App\Http\Resources\OmnifyBase\DisposalRecordResourceBase` | `App\Http\Requests\OmnifyBase\DisposalRecordStoreRequestBase` | `App\Policies\Omnify\Base\DisposalRecordPolicyBase` |
| ExpiryAlert | `App\Models\Base\ExpiryAlertBaseModel` | `App\Services\Omnify\OmnifyBase\ExpiryAlertServiceBase` | `App\Http\Resources\OmnifyBase\ExpiryAlertResourceBase` | `App\Http\Requests\OmnifyBase\ExpiryAlertStoreRequestBase` | `App\Policies\Omnify\Base\ExpiryAlertPolicyBase` |
| GenealogyLink | `App\Models\Base\GenealogyLinkBaseModel` | `App\Services\Omnify\OmnifyBase\GenealogyLinkServiceBase` | `App\Http\Resources\OmnifyBase\GenealogyLinkResourceBase` | `App\Http\Requests\OmnifyBase\GenealogyLinkStoreRequestBase` | `App\Policies\Omnify\Base\GenealogyLinkPolicyBase` |
| MaterialBatch | `App\Models\Base\MaterialBatchBaseModel` | `App\Services\Omnify\OmnifyBase\MaterialBatchServiceBase` | `App\Http\Resources\OmnifyBase\MaterialBatchResourceBase` | `App\Http\Requests\OmnifyBase\MaterialBatchStoreRequestBase` | `App\Policies\Omnify\Base\MaterialBatchPolicyBase` |
| MaterialBatchItem | `App\Models\Base\MaterialBatchItemBaseModel` | `App\Services\Omnify\OmnifyBase\MaterialBatchItemServiceBase` | `App\Http\Resources\OmnifyBase\MaterialBatchItemResourceBase` | `App\Http\Requests\OmnifyBase\MaterialBatchItemStoreRequestBase` | `App\Policies\Omnify\Base\MaterialBatchItemPolicyBase` |
| MaterialLot | `App\Models\Base\MaterialLotBaseModel` | `App\Services\Omnify\OmnifyBase\MaterialLotServiceBase` | `App\Http\Resources\OmnifyBase\MaterialLotResourceBase` | `App\Http\Requests\OmnifyBase\MaterialLotStoreRequestBase` | `App\Policies\Omnify\Base\MaterialLotPolicyBase` |
| MaterialLotReservation | `App\Models\Base\MaterialLotReservationBaseModel` | `App\Services\Omnify\OmnifyBase\MaterialLotReservationServiceBase` | `App\Http\Resources\OmnifyBase\MaterialLotReservationResourceBase` | `App\Http\Requests\OmnifyBase\MaterialLotReservationStoreRequestBase` | `App\Policies\Omnify\Base\MaterialLotReservationPolicyBase` |
| MaterialSubstitutionRule | `App\Models\Base\MaterialSubstitutionRuleBaseModel` | `App\Services\Omnify\OmnifyBase\MaterialSubstitutionRuleServiceBase` | `App\Http\Resources\OmnifyBase\MaterialSubstitutionRuleResourceBase` | `App\Http\Requests\OmnifyBase\MaterialSubstitutionRuleStoreRequestBase` | `App\Policies\Omnify\Base\MaterialSubstitutionRulePolicyBase` |
| MaterialUnit | `App\Models\Base\MaterialUnitBaseModel` | `App\Services\Omnify\OmnifyBase\MaterialUnitServiceBase` | `App\Http\Resources\OmnifyBase\MaterialUnitResourceBase` | `App\Http\Requests\OmnifyBase\MaterialUnitStoreRequestBase` | `App\Policies\Omnify\Base\MaterialUnitPolicyBase` |
| ProductionOrder | `App\Models\Base\ProductionOrderBaseModel` | `App\Services\Omnify\OmnifyBase\ProductionOrderServiceBase` | `App\Http\Resources\OmnifyBase\ProductionOrderResourceBase` | `App\Http\Requests\OmnifyBase\ProductionOrderStoreRequestBase` | `App\Policies\Omnify\Base\ProductionOrderPolicyBase` |
| ProductionOrderItem | `App\Models\Base\ProductionOrderItemBaseModel` | `App\Services\Omnify\OmnifyBase\ProductionOrderItemServiceBase` | `App\Http\Resources\OmnifyBase\ProductionOrderItemResourceBase` | `App\Http\Requests\OmnifyBase\ProductionOrderItemStoreRequestBase` | `App\Policies\Omnify\Base\ProductionOrderItemPolicyBase` |
| Recall | `App\Models\Base\RecallBaseModel` | `App\Services\Omnify\OmnifyBase\RecallServiceBase` | `App\Http\Resources\OmnifyBase\RecallResourceBase` | `App\Http\Requests\OmnifyBase\RecallStoreRequestBase` | `App\Policies\Omnify\Base\RecallPolicyBase` |
| RecallAffectedOrder | `App\Models\Base\RecallAffectedOrderBaseModel` | `App\Services\Omnify\OmnifyBase\RecallAffectedOrderServiceBase` | `App\Http\Resources\OmnifyBase\RecallAffectedOrderResourceBase` | `App\Http\Requests\OmnifyBase\RecallAffectedOrderStoreRequestBase` | `App\Policies\Omnify\Base\RecallAffectedOrderPolicyBase` |
| RecallDrill | `App\Models\Base\RecallDrillBaseModel` | `App\Services\Omnify\OmnifyBase\RecallDrillServiceBase` | `App\Http\Resources\OmnifyBase\RecallDrillResourceBase` | `App\Http\Requests\OmnifyBase\RecallDrillStoreRequestBase` | `App\Policies\Omnify\Base\RecallDrillPolicyBase` |
| StockAlert | `App\Models\Base\StockAlertBaseModel` | `App\Services\Omnify\OmnifyBase\StockAlertServiceBase` | `App\Http\Resources\OmnifyBase\StockAlertResourceBase` | `App\Http\Requests\OmnifyBase\StockAlertStoreRequestBase` | `App\Policies\Omnify\Base\StockAlertPolicyBase` |
| StockCount | `App\Models\Base\StockCountBaseModel` | `App\Services\Omnify\OmnifyBase\StockCountServiceBase` | `App\Http\Resources\OmnifyBase\StockCountResourceBase` | `App\Http\Requests\OmnifyBase\StockCountStoreRequestBase` | `App\Policies\Omnify\Base\StockCountPolicyBase` |
| StockCountItem | `App\Models\Base\StockCountItemBaseModel` | `App\Services\Omnify\OmnifyBase\StockCountItemServiceBase` | `App\Http\Resources\OmnifyBase\StockCountItemResourceBase` | `App\Http\Requests\OmnifyBase\StockCountItemStoreRequestBase` | `App\Policies\Omnify\Base\StockCountItemPolicyBase` |
| StockLevel | `App\Models\Base\StockLevelBaseModel` | `App\Services\Omnify\OmnifyBase\StockLevelServiceBase` | `App\Http\Resources\OmnifyBase\StockLevelResourceBase` | `App\Http\Requests\OmnifyBase\StockLevelStoreRequestBase` | `App\Policies\Omnify\Base\StockLevelPolicyBase` |
| StockMovement | `App\Models\Base\StockMovementBaseModel` | `App\Services\Omnify\OmnifyBase\StockMovementServiceBase` | `App\Http\Resources\OmnifyBase\StockMovementResourceBase` | `App\Http\Requests\OmnifyBase\StockMovementStoreRequestBase` | `App\Policies\Omnify\Base\StockMovementPolicyBase` |
| StockTransaction | `App\Models\Base\StockTransactionBaseModel` | `App\Services\Omnify\OmnifyBase\StockTransactionServiceBase` | `App\Http\Resources\OmnifyBase\StockTransactionResourceBase` | `App\Http\Requests\OmnifyBase\StockTransactionStoreRequestBase` | `App\Policies\Omnify\Base\StockTransactionPolicyBase` |
| StockTransactionItem | `App\Models\Base\StockTransactionItemBaseModel` | `App\Services\Omnify\OmnifyBase\StockTransactionItemServiceBase` | `App\Http\Resources\OmnifyBase\StockTransactionItemResourceBase` | `App\Http\Requests\OmnifyBase\StockTransactionItemStoreRequestBase` | `App\Policies\Omnify\Base\StockTransactionItemPolicyBase` |
| StockTransfer | `App\Models\Base\StockTransferBaseModel` | `App\Services\Omnify\OmnifyBase\StockTransferServiceBase` | `App\Http\Resources\OmnifyBase\StockTransferResourceBase` | `App\Http\Requests\OmnifyBase\StockTransferStoreRequestBase` | `App\Policies\Omnify\Base\StockTransferPolicyBase` |
| StockTransferItem | `App\Models\Base\StockTransferItemBaseModel` | `App\Services\Omnify\OmnifyBase\StockTransferItemServiceBase` | `App\Http\Resources\OmnifyBase\StockTransferItemResourceBase` | `App\Http\Requests\OmnifyBase\StockTransferItemStoreRequestBase` | `App\Policies\Omnify\Base\StockTransferItemPolicyBase` |
| Warehouse | `App\Models\Base\WarehouseBaseModel` | `App\Services\Omnify\OmnifyBase\WarehouseServiceBase` | `App\Http\Resources\OmnifyBase\WarehouseResourceBase` | `App\Http\Requests\OmnifyBase\WarehouseStoreRequestBase` | `App\Policies\Omnify\Base\WarehousePolicyBase` |
| WarehouseMember | `App\Models\Base\WarehouseMemberBaseModel` | `App\Services\Omnify\OmnifyBase\WarehouseMemberServiceBase` | `App\Http\Resources\OmnifyBase\WarehouseMemberResourceBase` | `App\Http\Requests\OmnifyBase\WarehouseMemberStoreRequestBase` | `App\Policies\Omnify\Base\WarehouseMemberPolicyBase` |

### Notification (12 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| Notification | `App\Models\Base\NotificationBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationServiceBase` | `App\Http\Resources\OmnifyBase\NotificationResourceBase` | `App\Http\Requests\OmnifyBase\NotificationStoreRequestBase` | `App\Policies\Omnify\Base\NotificationPolicyBase` |
| NotificationAudience | `App\Models\Base\NotificationAudienceBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationAudienceServiceBase` | `App\Http\Resources\OmnifyBase\NotificationAudienceResourceBase` | `App\Http\Requests\OmnifyBase\NotificationAudienceStoreRequestBase` | `App\Policies\Omnify\Base\NotificationAudiencePolicyBase` |
| NotificationChannelRoute | `App\Models\Base\NotificationChannelRouteBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationChannelRouteServiceBase` | `App\Http\Resources\OmnifyBase\NotificationChannelRouteResourceBase` | `App\Http\Requests\OmnifyBase\NotificationChannelRouteStoreRequestBase` | `App\Policies\Omnify\Base\NotificationChannelRoutePolicyBase` |
| NotificationDelivery | `App\Models\Base\NotificationDeliveryBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationDeliveryServiceBase` | `App\Http\Resources\OmnifyBase\NotificationDeliveryResourceBase` | `App\Http\Requests\OmnifyBase\NotificationDeliveryStoreRequestBase` | `App\Policies\Omnify\Base\NotificationDeliveryPolicyBase` |
| NotificationDigestPreference | `App\Models\Base\NotificationDigestPreferenceBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationDigestPreferenceServiceBase` | `App\Http\Resources\OmnifyBase\NotificationDigestPreferenceResourceBase` | `App\Http\Requests\OmnifyBase\NotificationDigestPreferenceStoreRequestBase` | `App\Policies\Omnify\Base\NotificationDigestPreferencePolicyBase` |
| NotificationEmailSuppression | `App\Models\Base\NotificationEmailSuppressionBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationEmailSuppressionServiceBase` | `App\Http\Resources\OmnifyBase\NotificationEmailSuppressionResourceBase` | `App\Http\Requests\OmnifyBase\NotificationEmailSuppressionStoreRequestBase` | `App\Policies\Omnify\Base\NotificationEmailSuppressionPolicyBase` |
| NotificationPreference | `App\Models\Base\NotificationPreferenceBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationPreferenceServiceBase` | `App\Http\Resources\OmnifyBase\NotificationPreferenceResourceBase` | `App\Http\Requests\OmnifyBase\NotificationPreferenceStoreRequestBase` | `App\Policies\Omnify\Base\NotificationPreferencePolicyBase` |
| NotificationRecipient | `App\Models\Base\NotificationRecipientBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationRecipientServiceBase` | `App\Http\Resources\OmnifyBase\NotificationRecipientResourceBase` | `App\Http\Requests\OmnifyBase\NotificationRecipientStoreRequestBase` | `App\Policies\Omnify\Base\NotificationRecipientPolicyBase` |
| NotificationRule | `App\Models\Base\NotificationRuleBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationRuleServiceBase` | `App\Http\Resources\OmnifyBase\NotificationRuleResourceBase` | `App\Http\Requests\OmnifyBase\NotificationRuleStoreRequestBase` | `App\Policies\Omnify\Base\NotificationRulePolicyBase` |
| NotificationRuleFiring | `App\Models\Base\NotificationRuleFiringBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationRuleFiringServiceBase` | `App\Http\Resources\OmnifyBase\NotificationRuleFiringResourceBase` | `App\Http\Requests\OmnifyBase\NotificationRuleFiringStoreRequestBase` | `App\Policies\Omnify\Base\NotificationRuleFiringPolicyBase` |
| NotificationSchedule | `App\Models\Base\NotificationScheduleBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationScheduleServiceBase` | `App\Http\Resources\OmnifyBase\NotificationScheduleResourceBase` | `App\Http\Requests\OmnifyBase\NotificationScheduleStoreRequestBase` | `App\Policies\Omnify\Base\NotificationSchedulePolicyBase` |
| NotificationTemplate | `App\Models\Base\NotificationTemplateBaseModel` | `App\Services\Omnify\OmnifyBase\NotificationTemplateServiceBase` | `App\Http\Resources\OmnifyBase\NotificationTemplateResourceBase` | `App\Http\Requests\OmnifyBase\NotificationTemplateStoreRequestBase` | `App\Policies\Omnify\Base\NotificationTemplatePolicyBase` |

### Payment (13 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| DevicePaymentOption | `App\Models\Base\DevicePaymentOptionBaseModel` | `App\Services\Omnify\OmnifyBase\DevicePaymentOptionServiceBase` | `App\Http\Resources\OmnifyBase\DevicePaymentOptionResourceBase` | `App\Http\Requests\OmnifyBase\DevicePaymentOptionStoreRequestBase` | `App\Policies\Omnify\Base\DevicePaymentOptionPolicyBase` |
| GatewayPayout | `App\Models\Base\GatewayPayoutBaseModel` | `App\Services\Omnify\OmnifyBase\GatewayPayoutServiceBase` | `App\Http\Resources\OmnifyBase\GatewayPayoutResourceBase` | `App\Http\Requests\OmnifyBase\GatewayPayoutStoreRequestBase` | `App\Policies\Omnify\Base\GatewayPayoutPolicyBase` |
| PaymentAttempt | `App\Models\Base\PaymentAttemptBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentAttemptServiceBase` | `App\Http\Resources\OmnifyBase\PaymentAttemptResourceBase` | `App\Http\Requests\OmnifyBase\PaymentAttemptStoreRequestBase` | `App\Policies\Omnify\Base\PaymentAttemptPolicyBase` |
| PaymentGatewayConnection | `App\Models\Base\PaymentGatewayConnectionBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentGatewayConnectionServiceBase` | `App\Http\Resources\OmnifyBase\PaymentGatewayConnectionResourceBase` | `App\Http\Requests\OmnifyBase\PaymentGatewayConnectionStoreRequestBase` | `App\Policies\Omnify\Base\PaymentGatewayConnectionPolicyBase` |
| PaymentGatewayConnectionOption | `App\Models\Base\PaymentGatewayConnectionOptionBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentGatewayConnectionOptionServiceBase` | `App\Http\Resources\OmnifyBase\PaymentGatewayConnectionOptionResourceBase` | `App\Http\Requests\OmnifyBase\PaymentGatewayConnectionOptionStoreRequestBase` | `App\Policies\Omnify\Base\PaymentGatewayConnectionOptionPolicyBase` |
| PaymentGatewayOption | `App\Models\Base\PaymentGatewayOptionBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentGatewayOptionServiceBase` | `App\Http\Resources\OmnifyBase\PaymentGatewayOptionResourceBase` | `App\Http\Requests\OmnifyBase\PaymentGatewayOptionStoreRequestBase` | `App\Policies\Omnify\Base\PaymentGatewayOptionPolicyBase` |
| PaymentGatewayProvider | `App\Models\Base\PaymentGatewayProviderBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentGatewayProviderServiceBase` | `App\Http\Resources\OmnifyBase\PaymentGatewayProviderResourceBase` | `App\Http\Requests\OmnifyBase\PaymentGatewayProviderStoreRequestBase` | `App\Policies\Omnify\Base\PaymentGatewayProviderPolicyBase` |
| PaymentPolicyRevision | `App\Models\Base\PaymentPolicyRevisionBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentPolicyRevisionServiceBase` | `App\Http\Resources\OmnifyBase\PaymentPolicyRevisionResourceBase` | `App\Http\Requests\OmnifyBase\PaymentPolicyRevisionStoreRequestBase` | `App\Policies\Omnify\Base\PaymentPolicyRevisionPolicyBase` |
| PaymentProviderEvent | `App\Models\Base\PaymentProviderEventBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentProviderEventServiceBase` | `App\Http\Resources\OmnifyBase\PaymentProviderEventResourceBase` | `App\Http\Requests\OmnifyBase\PaymentProviderEventStoreRequestBase` | `App\Policies\Omnify\Base\PaymentProviderEventPolicyBase` |
| PaymentRefund | `App\Models\Base\PaymentRefundBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentRefundServiceBase` | `App\Http\Resources\OmnifyBase\PaymentRefundResourceBase` | `App\Http\Requests\OmnifyBase\PaymentRefundStoreRequestBase` | `App\Policies\Omnify\Base\PaymentRefundPolicyBase` |
| PaymentSettlement | `App\Models\Base\PaymentSettlementBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentSettlementServiceBase` | `App\Http\Resources\OmnifyBase\PaymentSettlementResourceBase` | `App\Http\Requests\OmnifyBase\PaymentSettlementStoreRequestBase` | `App\Policies\Omnify\Base\PaymentSettlementPolicyBase` |
| SettlementReportBatch | `App\Models\Base\SettlementReportBatchBaseModel` | `App\Services\Omnify\OmnifyBase\SettlementReportBatchServiceBase` | `App\Http\Resources\OmnifyBase\SettlementReportBatchResourceBase` | `App\Http\Requests\OmnifyBase\SettlementReportBatchStoreRequestBase` | `App\Policies\Omnify\Base\SettlementReportBatchPolicyBase` |
| ShopPaymentOption | `App\Models\Base\ShopPaymentOptionBaseModel` | `App\Services\Omnify\OmnifyBase\ShopPaymentOptionServiceBase` | `App\Http\Resources\OmnifyBase\ShopPaymentOptionResourceBase` | `App\Http\Requests\OmnifyBase\ShopPaymentOptionStoreRequestBase` | `App\Policies\Omnify\Base\ShopPaymentOptionPolicyBase` |

### Post (4 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| Post | `App\Models\Base\PostBaseModel` | `App\Services\Omnify\OmnifyBase\PostServiceBase` | `App\Http\Resources\OmnifyBase\PostResourceBase` | `App\Http\Requests\OmnifyBase\PostStoreRequestBase` | `App\Policies\Omnify\Base\PostPolicyBase` |
| PostCategory | `App\Models\Base\PostCategoryBaseModel` | `App\Services\Omnify\OmnifyBase\PostCategoryServiceBase` | `App\Http\Resources\OmnifyBase\PostCategoryResourceBase` | `App\Http\Requests\OmnifyBase\PostCategoryStoreRequestBase` | `App\Policies\Omnify\Base\PostCategoryPolicyBase` |
| PostPostTag | `App\Models\Base\PostPostTagBaseModel` | `App\Services\Omnify\OmnifyBase\PostPostTagServiceBase` | `App\Http\Resources\OmnifyBase\PostPostTagResourceBase` | `App\Http\Requests\OmnifyBase\PostPostTagStoreRequestBase` | `App\Policies\Omnify\Base\PostPostTagPolicyBase` |
| PostTag | `App\Models\Base\PostTagBaseModel` | `App\Services\Omnify\OmnifyBase\PostTagServiceBase` | `App\Http\Resources\OmnifyBase\PostTagResourceBase` | `App\Http\Requests\OmnifyBase\PostTagStoreRequestBase` | `App\Policies\Omnify\Base\PostTagPolicyBase` |

### Product (45 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| Allergen | `App\Models\Base\AllergenBaseModel` | `App\Services\Omnify\OmnifyBase\AllergenServiceBase` | `App\Http\Resources\OmnifyBase\AllergenResourceBase` | `App\Http\Requests\OmnifyBase\AllergenStoreRequestBase` | `App\Policies\Omnify\Base\AllergenPolicyBase` |
| AllergenMaterial | `App\Models\Base\AllergenMaterialBaseModel` | `App\Services\Omnify\OmnifyBase\AllergenMaterialServiceBase` | `App\Http\Resources\OmnifyBase\AllergenMaterialResourceBase` | `App\Http\Requests\OmnifyBase\AllergenMaterialStoreRequestBase` | `App\Policies\Omnify\Base\AllergenMaterialPolicyBase` |
| BranchFloatingSectionOverride | `App\Models\Base\BranchFloatingSectionOverrideBaseModel` | `App\Services\Omnify\OmnifyBase\BranchFloatingSectionOverrideServiceBase` | `App\Http\Resources\OmnifyBase\BranchFloatingSectionOverrideResourceBase` | `App\Http\Requests\OmnifyBase\BranchFloatingSectionOverrideStoreRequestBase` | `App\Policies\Omnify\Base\BranchFloatingSectionOverridePolicyBase` |
| BranchReview | `App\Models\Base\BranchReviewBaseModel` | `App\Services\Omnify\OmnifyBase\BranchReviewServiceBase` | `App\Http\Resources\OmnifyBase\BranchReviewResourceBase` | `App\Http\Requests\OmnifyBase\BranchReviewStoreRequestBase` | `App\Policies\Omnify\Base\BranchReviewPolicyBase` |
| BranchScheduleOverride | `App\Models\Base\BranchScheduleOverrideBaseModel` | `App\Services\Omnify\OmnifyBase\BranchScheduleOverrideServiceBase` | `App\Http\Resources\OmnifyBase\BranchScheduleOverrideResourceBase` | `App\Http\Requests\OmnifyBase\BranchScheduleOverrideStoreRequestBase` | `App\Policies\Omnify\Base\BranchScheduleOverridePolicyBase` |
| CatalogRevision | `App\Models\Base\CatalogRevisionBaseModel` | `App\Services\Omnify\OmnifyBase\CatalogRevisionServiceBase` | `App\Http\Resources\OmnifyBase\CatalogRevisionResourceBase` | `App\Http\Requests\OmnifyBase\CatalogRevisionStoreRequestBase` | `App\Policies\Omnify\Base\CatalogRevisionPolicyBase` |
| Category | `App\Models\Base\CategoryBaseModel` | `App\Services\Omnify\OmnifyBase\CategoryServiceBase` | `App\Http\Resources\OmnifyBase\CategoryResourceBase` | `App\Http\Requests\OmnifyBase\CategoryStoreRequestBase` | `App\Policies\Omnify\Base\CategoryPolicyBase` |
| CategoryProduct | `App\Models\Base\CategoryProductBaseModel` | `App\Services\Omnify\OmnifyBase\CategoryProductServiceBase` | `App\Http\Resources\OmnifyBase\CategoryProductResourceBase` | `App\Http\Requests\OmnifyBase\CategoryProductStoreRequestBase` | `App\Policies\Omnify\Base\CategoryProductPolicyBase` |
| Customer | `App\Models\Base\CustomerBaseModel` | `App\Services\Omnify\OmnifyBase\CustomerServiceBase` | `App\Http\Resources\OmnifyBase\CustomerResourceBase` | `App\Http\Requests\OmnifyBase\CustomerStoreRequestBase` | `App\Policies\Omnify\Base\CustomerPolicyBase` |
| CustomerOrder | `App\Models\Base\CustomerOrderBaseModel` | `App\Services\Omnify\OmnifyBase\CustomerOrderServiceBase` | `App\Http\Resources\OmnifyBase\CustomerOrderResourceBase` | `App\Http\Requests\OmnifyBase\CustomerOrderStoreRequestBase` | `App\Policies\Omnify\Base\CustomerOrderPolicyBase` |
| CustomerOrderItem | `App\Models\Base\CustomerOrderItemBaseModel` | `App\Services\Omnify\OmnifyBase\CustomerOrderItemServiceBase` | `App\Http\Resources\OmnifyBase\CustomerOrderItemResourceBase` | `App\Http\Requests\OmnifyBase\CustomerOrderItemStoreRequestBase` | `App\Policies\Omnify\Base\CustomerOrderItemPolicyBase` |
| FloatingSection | `App\Models\Base\FloatingSectionBaseModel` | `App\Services\Omnify\OmnifyBase\FloatingSectionServiceBase` | `App\Http\Resources\OmnifyBase\FloatingSectionResourceBase` | `App\Http\Requests\OmnifyBase\FloatingSectionStoreRequestBase` | `App\Policies\Omnify\Base\FloatingSectionPolicyBase` |
| FloatingSectionProduct | `App\Models\Base\FloatingSectionProductBaseModel` | `App\Services\Omnify\OmnifyBase\FloatingSectionProductServiceBase` | `App\Http\Resources\OmnifyBase\FloatingSectionProductResourceBase` | `App\Http\Requests\OmnifyBase\FloatingSectionProductStoreRequestBase` | `App\Policies\Omnify\Base\FloatingSectionProductPolicyBase` |
| FloatingSectionProductSku | `App\Models\Base\FloatingSectionProductSkuBaseModel` | `App\Services\Omnify\OmnifyBase\FloatingSectionProductSkuServiceBase` | `App\Http\Resources\OmnifyBase\FloatingSectionProductSkuResourceBase` | `App\Http\Requests\OmnifyBase\FloatingSectionProductSkuStoreRequestBase` | `App\Policies\Omnify\Base\FloatingSectionProductSkuPolicyBase` |
| FloatingSectionProductToppingItemOverride | `App\Models\Base\FloatingSectionProductToppingItemOverrideBaseModel` | `App\Services\Omnify\OmnifyBase\FloatingSectionProductToppingItemOverrideServiceBase` | `App\Http\Resources\OmnifyBase\FloatingSectionProductToppingItemOverrideResourceBase` | `App\Http\Requests\OmnifyBase\FloatingSectionProductToppingItemOverrideStoreRequestBase` | `App\Policies\Omnify\Base\FloatingSectionProductToppingItemOverridePolicyBase` |
| FloatingSectionSchedule | `App\Models\Base\FloatingSectionScheduleBaseModel` | `App\Services\Omnify\OmnifyBase\FloatingSectionScheduleServiceBase` | `App\Http\Resources\OmnifyBase\FloatingSectionScheduleResourceBase` | `App\Http\Requests\OmnifyBase\FloatingSectionScheduleStoreRequestBase` | `App\Policies\Omnify\Base\FloatingSectionSchedulePolicyBase` |
| InvoiceCounter | `App\Models\Base\InvoiceCounterBaseModel` | `App\Services\Omnify\OmnifyBase\InvoiceCounterServiceBase` | `App\Http\Resources\OmnifyBase\InvoiceCounterResourceBase` | `App\Http\Requests\OmnifyBase\InvoiceCounterStoreRequestBase` | `App\Policies\Omnify\Base\InvoiceCounterPolicyBase` |
| Material | `App\Models\Base\MaterialBaseModel` | `App\Services\Omnify\OmnifyBase\MaterialServiceBase` | `App\Http\Resources\OmnifyBase\MaterialResourceBase` | `App\Http\Requests\OmnifyBase\MaterialStoreRequestBase` | `App\Policies\Omnify\Base\MaterialPolicyBase` |
| Menu | `App\Models\Base\MenuBaseModel` | `App\Services\Omnify\OmnifyBase\MenuServiceBase` | `App\Http\Resources\OmnifyBase\MenuResourceBase` | `App\Http\Requests\OmnifyBase\MenuStoreRequestBase` | `App\Policies\Omnify\Base\MenuPolicyBase` |
| MenuMenuSection | `App\Models\Base\MenuMenuSectionBaseModel` | `App\Services\Omnify\OmnifyBase\MenuMenuSectionServiceBase` | `App\Http\Resources\OmnifyBase\MenuMenuSectionResourceBase` | `App\Http\Requests\OmnifyBase\MenuMenuSectionStoreRequestBase` | `App\Policies\Omnify\Base\MenuMenuSectionPolicyBase` |
| MenuProduct | `App\Models\Base\MenuProductBaseModel` | `App\Services\Omnify\OmnifyBase\MenuProductServiceBase` | `App\Http\Resources\OmnifyBase\MenuProductResourceBase` | `App\Http\Requests\OmnifyBase\MenuProductStoreRequestBase` | `App\Policies\Omnify\Base\MenuProductPolicyBase` |
| MenuProductSku | `App\Models\Base\MenuProductSkuBaseModel` | `App\Services\Omnify\OmnifyBase\MenuProductSkuServiceBase` | `App\Http\Resources\OmnifyBase\MenuProductSkuResourceBase` | `App\Http\Requests\OmnifyBase\MenuProductSkuStoreRequestBase` | `App\Policies\Omnify\Base\MenuProductSkuPolicyBase` |
| MenuProductToppingItemOverride | `App\Models\Base\MenuProductToppingItemOverrideBaseModel` | `App\Services\Omnify\OmnifyBase\MenuProductToppingItemOverrideServiceBase` | `App\Http\Resources\OmnifyBase\MenuProductToppingItemOverrideResourceBase` | `App\Http\Requests\OmnifyBase\MenuProductToppingItemOverrideStoreRequestBase` | `App\Policies\Omnify\Base\MenuProductToppingItemOverridePolicyBase` |
| MenuSchedule | `App\Models\Base\MenuScheduleBaseModel` | `App\Services\Omnify\OmnifyBase\MenuScheduleServiceBase` | `App\Http\Resources\OmnifyBase\MenuScheduleResourceBase` | `App\Http\Requests\OmnifyBase\MenuScheduleStoreRequestBase` | `App\Policies\Omnify\Base\MenuSchedulePolicyBase` |
| MenuSection | `App\Models\Base\MenuSectionBaseModel` | `App\Services\Omnify\OmnifyBase\MenuSectionServiceBase` | `App\Http\Resources\OmnifyBase\MenuSectionResourceBase` | `App\Http\Requests\OmnifyBase\MenuSectionStoreRequestBase` | `App\Policies\Omnify\Base\MenuSectionPolicyBase` |
| OrderCodeCounter | `App\Models\Base\OrderCodeCounterBaseModel` | `App\Services\Omnify\OmnifyBase\OrderCodeCounterServiceBase` | `App\Http\Resources\OmnifyBase\OrderCodeCounterResourceBase` | `App\Http\Requests\OmnifyBase\OrderCodeCounterStoreRequestBase` | `App\Policies\Omnify\Base\OrderCodeCounterPolicyBase` |
| OrderCondition | `App\Models\Base\OrderConditionBaseModel` | `App\Services\Omnify\OmnifyBase\OrderConditionServiceBase` | `App\Http\Resources\OmnifyBase\OrderConditionResourceBase` | `App\Http\Requests\OmnifyBase\OrderConditionStoreRequestBase` | `App\Policies\Omnify\Base\OrderConditionPolicyBase` |
| OrderItemTopping | `App\Models\Base\OrderItemToppingBaseModel` | `App\Services\Omnify\OmnifyBase\OrderItemToppingServiceBase` | `App\Http\Resources\OmnifyBase\OrderItemToppingResourceBase` | `App\Http\Requests\OmnifyBase\OrderItemToppingStoreRequestBase` | `App\Policies\Omnify\Base\OrderItemToppingPolicyBase` |
| OrderPayment | `App\Models\Base\OrderPaymentBaseModel` | `App\Services\Omnify\OmnifyBase\OrderPaymentServiceBase` | `App\Http\Resources\OmnifyBase\OrderPaymentResourceBase` | `App\Http\Requests\OmnifyBase\OrderPaymentStoreRequestBase` | `App\Policies\Omnify\Base\OrderPaymentPolicyBase` |
| PaymentMethod | `App\Models\Base\PaymentMethodBaseModel` | `App\Services\Omnify\OmnifyBase\PaymentMethodServiceBase` | `App\Http\Resources\OmnifyBase\PaymentMethodResourceBase` | `App\Http\Requests\OmnifyBase\PaymentMethodStoreRequestBase` | `App\Policies\Omnify\Base\PaymentMethodPolicyBase` |
| Product | `App\Models\Base\ProductBaseModel` | `App\Services\Omnify\OmnifyBase\ProductServiceBase` | `App\Http\Resources\OmnifyBase\ProductResourceBase` | `App\Http\Requests\OmnifyBase\ProductStoreRequestBase` | `App\Policies\Omnify\Base\ProductPolicyBase` |
| ProductOption | `App\Models\Base\ProductOptionBaseModel` | `App\Services\Omnify\OmnifyBase\ProductOptionServiceBase` | `App\Http\Resources\OmnifyBase\ProductOptionResourceBase` | `App\Http\Requests\OmnifyBase\ProductOptionStoreRequestBase` | `App\Policies\Omnify\Base\ProductOptionPolicyBase` |
| ProductOptionValue | `App\Models\Base\ProductOptionValueBaseModel` | `App\Services\Omnify\OmnifyBase\ProductOptionValueServiceBase` | `App\Http\Resources\OmnifyBase\ProductOptionValueResourceBase` | `App\Http\Requests\OmnifyBase\ProductOptionValueStoreRequestBase` | `App\Policies\Omnify\Base\ProductOptionValuePolicyBase` |
| ProductReview | `App\Models\Base\ProductReviewBaseModel` | `App\Services\Omnify\OmnifyBase\ProductReviewServiceBase` | `App\Http\Resources\OmnifyBase\ProductReviewResourceBase` | `App\Http\Requests\OmnifyBase\ProductReviewStoreRequestBase` | `App\Policies\Omnify\Base\ProductReviewPolicyBase` |
| ProductSku | `App\Models\Base\ProductSkuBaseModel` | `App\Services\Omnify\OmnifyBase\ProductSkuServiceBase` | `App\Http\Resources\OmnifyBase\ProductSkuResourceBase` | `App\Http\Requests\OmnifyBase\ProductSkuStoreRequestBase` | `App\Policies\Omnify\Base\ProductSkuPolicyBase` |
| ProductToppingGroup | `App\Models\Base\ProductToppingGroupBaseModel` | `App\Services\Omnify\OmnifyBase\ProductToppingGroupServiceBase` | `App\Http\Resources\OmnifyBase\ProductToppingGroupResourceBase` | `App\Http\Requests\OmnifyBase\ProductToppingGroupStoreRequestBase` | `App\Policies\Omnify\Base\ProductToppingGroupPolicyBase` |
| ProductToppingGroupItemOverride | `App\Models\Base\ProductToppingGroupItemOverrideBaseModel` | `App\Services\Omnify\OmnifyBase\ProductToppingGroupItemOverrideServiceBase` | `App\Http\Resources\OmnifyBase\ProductToppingGroupItemOverrideResourceBase` | `App\Http\Requests\OmnifyBase\ProductToppingGroupItemOverrideStoreRequestBase` | `App\Policies\Omnify\Base\ProductToppingGroupItemOverridePolicyBase` |
| ProductType | `App\Models\Base\ProductTypeBaseModel` | `App\Services\Omnify\OmnifyBase\ProductTypeServiceBase` | `App\Http\Resources\OmnifyBase\ProductTypeResourceBase` | `App\Http\Requests\OmnifyBase\ProductTypeStoreRequestBase` | `App\Policies\Omnify\Base\ProductTypePolicyBase` |
| Recipe | `App\Models\Base\RecipeBaseModel` | `App\Services\Omnify\OmnifyBase\RecipeServiceBase` | `App\Http\Resources\OmnifyBase\RecipeResourceBase` | `App\Http\Requests\OmnifyBase\RecipeStoreRequestBase` | `App\Policies\Omnify\Base\RecipePolicyBase` |
| ToppingGroup | `App\Models\Base\ToppingGroupBaseModel` | `App\Services\Omnify\OmnifyBase\ToppingGroupServiceBase` | `App\Http\Resources\OmnifyBase\ToppingGroupResourceBase` | `App\Http\Requests\OmnifyBase\ToppingGroupStoreRequestBase` | `App\Policies\Omnify\Base\ToppingGroupPolicyBase` |
| ToppingGroupItem | `App\Models\Base\ToppingGroupItemBaseModel` | `App\Services\Omnify\OmnifyBase\ToppingGroupItemServiceBase` | `App\Http\Resources\OmnifyBase\ToppingGroupItemResourceBase` | `App\Http\Requests\OmnifyBase\ToppingGroupItemStoreRequestBase` | `App\Policies\Omnify\Base\ToppingGroupItemPolicyBase` |
| ToppingGroupItemSku | `App\Models\Base\ToppingGroupItemSkuBaseModel` | `App\Services\Omnify\OmnifyBase\ToppingGroupItemSkuServiceBase` | `App\Http\Resources\OmnifyBase\ToppingGroupItemSkuResourceBase` | `App\Http\Requests\OmnifyBase\ToppingGroupItemSkuStoreRequestBase` | `App\Policies\Omnify\Base\ToppingGroupItemSkuPolicyBase` |
| VariantUnit | `App\Models\Base\VariantUnitBaseModel` | `App\Services\Omnify\OmnifyBase\VariantUnitServiceBase` | `App\Http\Resources\OmnifyBase\VariantUnitResourceBase` | `App\Http\Requests\OmnifyBase\VariantUnitStoreRequestBase` | `App\Policies\Omnify\Base\VariantUnitPolicyBase` |

### Promotion (6 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| Coupon | `App\Models\Base\CouponBaseModel` | `App\Services\Omnify\OmnifyBase\CouponServiceBase` | `App\Http\Resources\OmnifyBase\CouponResourceBase` | `App\Http\Requests\OmnifyBase\CouponStoreRequestBase` | `App\Policies\Omnify\Base\CouponPolicyBase` |
| CouponBranch | `App\Models\Base\CouponBranchBaseModel` | `App\Services\Omnify\OmnifyBase\CouponBranchServiceBase` | `App\Http\Resources\OmnifyBase\CouponBranchResourceBase` | `App\Http\Requests\OmnifyBase\CouponBranchStoreRequestBase` | `App\Policies\Omnify\Base\CouponBranchPolicyBase` |
| CouponRedemption | `App\Models\Base\CouponRedemptionBaseModel` | `App\Services\Omnify\OmnifyBase\CouponRedemptionServiceBase` | `App\Http\Resources\OmnifyBase\CouponRedemptionResourceBase` | `App\Http\Requests\OmnifyBase\CouponRedemptionStoreRequestBase` | `App\Policies\Omnify\Base\CouponRedemptionPolicyBase` |
| MenuPromotion | `App\Models\Base\MenuPromotionBaseModel` | `App\Services\Omnify\OmnifyBase\MenuPromotionServiceBase` | `App\Http\Resources\OmnifyBase\MenuPromotionResourceBase` | `App\Http\Requests\OmnifyBase\MenuPromotionStoreRequestBase` | `App\Policies\Omnify\Base\MenuPromotionPolicyBase` |
| MenuPromotionCategory | `App\Models\Base\MenuPromotionCategoryBaseModel` | `App\Services\Omnify\OmnifyBase\MenuPromotionCategoryServiceBase` | `App\Http\Resources\OmnifyBase\MenuPromotionCategoryResourceBase` | `App\Http\Requests\OmnifyBase\MenuPromotionCategoryStoreRequestBase` | `App\Policies\Omnify\Base\MenuPromotionCategoryPolicyBase` |
| MenuPromotionProduct | `App\Models\Base\MenuPromotionProductBaseModel` | `App\Services\Omnify\OmnifyBase\MenuPromotionProductServiceBase` | `App\Http\Resources\OmnifyBase\MenuPromotionProductResourceBase` | `App\Http\Requests\OmnifyBase\MenuPromotionProductStoreRequestBase` | `App\Policies\Omnify\Base\MenuPromotionProductPolicyBase` |

### Shop (14 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| BrandOrderPolicy | `App\Models\Base\BrandOrderPolicyBaseModel` | `App\Services\Omnify\OmnifyBase\BrandOrderPolicyServiceBase` | `App\Http\Resources\OmnifyBase\BrandOrderPolicyResourceBase` | `App\Http\Requests\OmnifyBase\BrandOrderPolicyStoreRequestBase` | `App\Policies\Omnify\Base\BrandOrderPolicyPolicyBase` |
| CustomerInvoice | `App\Models\Base\CustomerInvoiceBaseModel` | `App\Services\Omnify\OmnifyBase\CustomerInvoiceServiceBase` | `App\Http\Resources\OmnifyBase\CustomerInvoiceResourceBase` | `App\Http\Requests\OmnifyBase\CustomerInvoiceStoreRequestBase` | `App\Policies\Omnify\Base\CustomerInvoicePolicyBase` |
| CustomerReturnInvoice | `App\Models\Base\CustomerReturnInvoiceBaseModel` | `App\Services\Omnify\OmnifyBase\CustomerReturnInvoiceServiceBase` | `App\Http\Resources\OmnifyBase\CustomerReturnInvoiceResourceBase` | `App\Http\Requests\OmnifyBase\CustomerReturnInvoiceStoreRequestBase` | `App\Policies\Omnify\Base\CustomerReturnInvoicePolicyBase` |
| ShopOrderSetting | `App\Models\Base\ShopOrderSettingBaseModel` | `App\Services\Omnify\OmnifyBase\ShopOrderSettingServiceBase` | `App\Http\Resources\OmnifyBase\ShopOrderSettingResourceBase` | `App\Http\Requests\OmnifyBase\ShopOrderSettingStoreRequestBase` | `App\Policies\Omnify\Base\ShopOrderSettingPolicyBase` |
| Table | `App\Models\Base\TableBaseModel` | `App\Services\Omnify\OmnifyBase\TableServiceBase` | `App\Http\Resources\OmnifyBase\TableResourceBase` | `App\Http\Requests\OmnifyBase\TableStoreRequestBase` | `App\Policies\Omnify\Base\TablePolicyBase` |
| TableSession | `App\Models\Base\TableSessionBaseModel` | `App\Services\Omnify\OmnifyBase\TableSessionServiceBase` | `App\Http\Resources\OmnifyBase\TableSessionResourceBase` | `App\Http\Requests\OmnifyBase\TableSessionStoreRequestBase` | `App\Policies\Omnify\Base\TableSessionPolicyBase` |
| TableStatusChange | `App\Models\Base\TableStatusChangeBaseModel` | `App\Services\Omnify\OmnifyBase\TableStatusChangeServiceBase` | `App\Http\Resources\OmnifyBase\TableStatusChangeResourceBase` | `App\Http\Requests\OmnifyBase\TableStatusChangeStoreRequestBase` | `App\Policies\Omnify\Base\TableStatusChangePolicyBase` |
| TableTemplate | `App\Models\Base\TableTemplateBaseModel` | `App\Services\Omnify\OmnifyBase\TableTemplateServiceBase` | `App\Http\Resources\OmnifyBase\TableTemplateResourceBase` | `App\Http\Requests\OmnifyBase\TableTemplateStoreRequestBase` | `App\Policies\Omnify\Base\TableTemplatePolicyBase` |
| TaxType | `App\Models\Base\TaxTypeBaseModel` | `App\Services\Omnify\OmnifyBase\TaxTypeServiceBase` | `App\Http\Resources\OmnifyBase\TaxTypeResourceBase` | `App\Http\Requests\OmnifyBase\TaxTypeStoreRequestBase` | `App\Policies\Omnify\Base\TaxTypePolicyBase` |
| VnEinvoiceSetting | `App\Models\Base\VnEinvoiceSettingBaseModel` | `App\Services\Omnify\OmnifyBase\VnEinvoiceSettingServiceBase` | `App\Http\Resources\OmnifyBase\VnEinvoiceSettingResourceBase` | `App\Http\Requests\OmnifyBase\VnEinvoiceSettingStoreRequestBase` | `App\Policies\Omnify\Base\VnEinvoiceSettingPolicyBase` |
| VnEinvoiceTransmission | `App\Models\Base\VnEinvoiceTransmissionBaseModel` | `App\Services\Omnify\OmnifyBase\VnEinvoiceTransmissionServiceBase` | `App\Http\Resources\OmnifyBase\VnEinvoiceTransmissionResourceBase` | `App\Http\Requests\OmnifyBase\VnEinvoiceTransmissionStoreRequestBase` | `App\Policies\Omnify\Base\VnEinvoiceTransmissionPolicyBase` |
| VoidReason | `App\Models\Base\VoidReasonBaseModel` | `App\Services\Omnify\OmnifyBase\VoidReasonServiceBase` | `App\Http\Resources\OmnifyBase\VoidReasonResourceBase` | `App\Http\Requests\OmnifyBase\VoidReasonStoreRequestBase` | `App\Policies\Omnify\Base\VoidReasonPolicyBase` |
| Zone | `App\Models\Base\ZoneBaseModel` | `App\Services\Omnify\OmnifyBase\ZoneServiceBase` | `App\Http\Resources\OmnifyBase\ZoneResourceBase` | `App\Http\Requests\OmnifyBase\ZoneStoreRequestBase` | `App\Policies\Omnify\Base\ZonePolicyBase` |
| ZoneTemplate | `App\Models\Base\ZoneTemplateBaseModel` | `App\Services\Omnify\OmnifyBase\ZoneTemplateServiceBase` | `App\Http\Resources\OmnifyBase\ZoneTemplateResourceBase` | `App\Http\Requests\OmnifyBase\ZoneTemplateStoreRequestBase` | `App\Policies\Omnify\Base\ZoneTemplatePolicyBase` |

### Sso (9 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| Branch | `App\Models\Base\BranchBaseModel` | `App\Services\Omnify\OmnifyBase\BranchServiceBase` | `App\Http\Resources\OmnifyBase\BranchResourceBase` | `App\Http\Requests\OmnifyBase\BranchStoreRequestBase` | `App\Policies\Omnify\Base\BranchPolicyBase` |
| Brand | `App\Models\Base\BrandBaseModel` | `App\Services\Omnify\OmnifyBase\BrandServiceBase` | `App\Http\Resources\OmnifyBase\BrandResourceBase` | `App\Http\Requests\OmnifyBase\BrandStoreRequestBase` | `App\Policies\Omnify\Base\BrandPolicyBase` |
| Organization | `App\Models\Base\OrganizationBaseModel` | `App\Services\Omnify\OmnifyBase\OrganizationServiceBase` | `App\Http\Resources\OmnifyBase\OrganizationResourceBase` | `App\Http\Requests\OmnifyBase\OrganizationStoreRequestBase` | `App\Policies\Omnify\Base\OrganizationPolicyBase` |
| Permission | `App\Models\Base\PermissionBaseModel` | `App\Services\Omnify\OmnifyBase\PermissionServiceBase` | `App\Http\Resources\OmnifyBase\PermissionResourceBase` | `App\Http\Requests\OmnifyBase\PermissionStoreRequestBase` | `App\Policies\Omnify\Base\PermissionPolicyBase` |
| PersonalAccessToken | `App\Models\Base\PersonalAccessTokenBaseModel` | `App\Services\Omnify\OmnifyBase\PersonalAccessTokenServiceBase` | `App\Http\Resources\OmnifyBase\PersonalAccessTokenResourceBase` | `App\Http\Requests\OmnifyBase\PersonalAccessTokenStoreRequestBase` | `App\Policies\Omnify\Base\PersonalAccessTokenPolicyBase` |
| Role | `App\Models\Base\RoleBaseModel` | `App\Services\Omnify\OmnifyBase\RoleServiceBase` | `App\Http\Resources\OmnifyBase\RoleResourceBase` | `App\Http\Requests\OmnifyBase\RoleStoreRequestBase` | `App\Policies\Omnify\Base\RolePolicyBase` |
| RolePermission | `App\Models\Base\RolePermissionBaseModel` | `App\Services\Omnify\OmnifyBase\RolePermissionServiceBase` | `App\Http\Resources\OmnifyBase\RolePermissionResourceBase` | `App\Http\Requests\OmnifyBase\RolePermissionStoreRequestBase` | `App\Policies\Omnify\Base\RolePermissionPolicyBase` |
| RoleUserPivot | `App\Models\Base\RoleUserPivotBaseModel` | `App\Services\Omnify\OmnifyBase\RoleUserPivotServiceBase` | `App\Http\Resources\OmnifyBase\RoleUserPivotResourceBase` | `App\Http\Requests\OmnifyBase\RoleUserPivotStoreRequestBase` | `App\Policies\Omnify\Base\RoleUserPivotPolicyBase` |
| User | `App\Models\Base\UserBaseModel` | `App\Services\Omnify\OmnifyBase\UserServiceBase` | `App\Http\Resources\OmnifyBase\UserResourceBase` | `App\Http\Requests\OmnifyBase\UserStoreRequestBase` | `App\Policies\Omnify\Base\UserPolicyBase` |

### System (1 schema)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| AuditLog | `App\Models\Base\AuditLogBaseModel` | `App\Services\Omnify\OmnifyBase\AuditLogServiceBase` | `App\Http\Resources\OmnifyBase\AuditLogResourceBase` | `App\Http\Requests\OmnifyBase\AuditLogStoreRequestBase` | `App\Policies\Omnify\Base\AuditLogPolicyBase` |

### Till (8 schemas)

| Schema | Model base | Service base | Resource base | Request base | Policy base |
|---|---|---|---|---|---|
| Denomination | `App\Models\Base\DenominationBaseModel` | `App\Services\Omnify\OmnifyBase\DenominationServiceBase` | `App\Http\Resources\OmnifyBase\DenominationResourceBase` | `App\Http\Requests\OmnifyBase\DenominationStoreRequestBase` | `App\Policies\Omnify\Base\DenominationPolicyBase` |
| Till | `App\Models\Base\TillBaseModel` | `App\Services\Omnify\OmnifyBase\TillServiceBase` | `App\Http\Resources\OmnifyBase\TillResourceBase` | `App\Http\Requests\OmnifyBase\TillStoreRequestBase` | `App\Policies\Omnify\Base\TillPolicyBase` |
| TillCashDenominationCount | `App\Models\Base\TillCashDenominationCountBaseModel` | `App\Services\Omnify\OmnifyBase\TillCashDenominationCountServiceBase` | `App\Http\Resources\OmnifyBase\TillCashDenominationCountResourceBase` | `App\Http\Requests\OmnifyBase\TillCashDenominationCountStoreRequestBase` | `App\Policies\Omnify\Base\TillCashDenominationCountPolicyBase` |
| TillCashEvent | `App\Models\Base\TillCashEventBaseModel` | `App\Services\Omnify\OmnifyBase\TillCashEventServiceBase` | `App\Http\Resources\OmnifyBase\TillCashEventResourceBase` | `App\Http\Requests\OmnifyBase\TillCashEventStoreRequestBase` | `App\Policies\Omnify\Base\TillCashEventPolicyBase` |
| TillSession | `App\Models\Base\TillSessionBaseModel` | `App\Services\Omnify\OmnifyBase\TillSessionServiceBase` | `App\Http\Resources\OmnifyBase\TillSessionResourceBase` | `App\Http\Requests\OmnifyBase\TillSessionStoreRequestBase` | `App\Policies\Omnify\Base\TillSessionPolicyBase` |
| TillSettlementTenderDetail | `App\Models\Base\TillSettlementTenderDetailBaseModel` | `App\Services\Omnify\OmnifyBase\TillSettlementTenderDetailServiceBase` | `App\Http\Resources\OmnifyBase\TillSettlementTenderDetailResourceBase` | `App\Http\Requests\OmnifyBase\TillSettlementTenderDetailStoreRequestBase` | `App\Policies\Omnify\Base\TillSettlementTenderDetailPolicyBase` |
| TillTenderCategory | `App\Models\Base\TillTenderCategoryBaseModel` | `App\Services\Omnify\OmnifyBase\TillTenderCategoryServiceBase` | `App\Http\Resources\OmnifyBase\TillTenderCategoryResourceBase` | `App\Http\Requests\OmnifyBase\TillTenderCategoryStoreRequestBase` | `App\Policies\Omnify\Base\TillTenderCategoryPolicyBase` |
| TillTenderType | `App\Models\Base\TillTenderTypeBaseModel` | `App\Services\Omnify\OmnifyBase\TillTenderTypeServiceBase` | `App\Http\Resources\OmnifyBase\TillTenderTypeResourceBase` | `App\Http\Requests\OmnifyBase\TillTenderTypeStoreRequestBase` | `App\Policies\Omnify\Base\TillTenderTypePolicyBase` |

## Hard rules

1. **Never edit anything under the `Omnify/` auto-gen zone.** Files
   here carry a `DO NOT EDIT` header and are overwritten on every
   `omnify generate`.
2. **Schema-derived classes MUST extend the omnify base.** Do not
   extend `Eloquent\Model`, `JsonResource`, `FormRequest`, etc.
   directly when a base exists for that schema.
3. **User-editable lives at canonical Laravel paths** — flat at the
   top level (`app/Models/<Name>.php`, NOT `app/Models/<Group>/<Name>.php`).
4. **Custom (non-schema) classes go in subfolders**, not at the FLAT
   top (`app/Services/Auth/PasswordResetService.php`, etc.).
5. **Translation / Pivot / Enum always grouped** — even when other
   layers are flat, these three keep their dedicated subfolder.
6. **`config/translatable.php` must set `translation_model_namespace`**
   to `App\Models\Translation` so Astrotomic auto-resolves.

## Anti-patterns

- Editing files under the `Omnify/` auto-gen zone
- Re-declaring `$table` / `$fillable` / `$casts` in user-editable
  models (duplicates schema, drifts on next regeneration)
- Importing the auto-gen base namespace directly from controllers /
  services when a user-editable wrapper exists
- Adding manual `Gate::policy()` registrations for schema-derived
  models (Laravel auto-discovery handles them)
- Writing the same schema definition twice (once in YAML, once
  hand-written)
- Putting custom (non-schema) resources at the FLAT top-level path

## Regenerate

```bash
omnify generate
```

Last updated: 2026-07-30 by omnify.
