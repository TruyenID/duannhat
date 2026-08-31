# Plan 054 — DESIGN (review round 1)

> Round 0 bị viết dựa trên một codebase chưa đọc hết. Bản này sửa theo 4 vòng
> review. Chỗ nào round 0 sai đều ghi rõ để người thực thi không lặp lại.

## 0. Nguyên tắc rút ra từ review

1. **Nâng cấp hành vi, không đổi hiển thị.** Nút PayPay **đang tồn tại và dùng
   được** trên 3 surface. Làm nó biến mất khi chưa cấu hình = regression.
2. **Không phát minh cái đã có.** Màn QR, thư viện QR, realtime, throttle theo
   order id — có sẵn hết.
3. **Guard tiền nằm ở caller, không ở hàm ghi sổ.** Sao chép nhầm tầng = mất
   sạch guard.
4. **Thất bại phải kêu.** Mọi nhánh hỏng của plan này đều im lặng theo mặc định.

## 1. Capability `paypay.web_payment.qr.v1`

Thêm vào [PaymentGatewayCatalogSeeder](../../backend/database/seeders/PaymentGatewayCatalogSeeder.php)
cạnh `seedPayPayOption()`:

```php
'integration_product' => 'opa_web_payment',
'api_version'         => '2.0',
'rail'                => 'wallet',
'method_type'         => 'paypay',
'brands'              => ['paypay'],
'channels'            => ['customer_web'],
'device_classes'      => ['browser'],
'currencies'          => [['code' => 'JPY', 'minor_unit' => 0]],
'workflows'           => ['sale'],
'operations'          => ['create', 'retrieve_payment', 'webhook_verification'],
'limits' => ['partial_capture' => false, 'partial_refund' => false,
             'multiple_refunds' => false, 'min_amount' => 1, 'max_amount' => 10_000_000],
'recovery' => ['retrieve_payment' => true, 'retrieve_refund' => false,
               'webhook_verification' => true, 'strategy' => 'daily_reconciliation'],
'merchant_identity_requirements' => ['assume_merchant'],
'sort_order' => 21,
```

**Đổi so với round 0:** bỏ `refund`/`retrieve_refund` khỏi `operations` và đặt
`multiple_refunds => false`. Round 0 khai có refund trong khi **không có đường
refund nào chạy được** (R4/R5b). Catalog phải nói thật.

`REQUIRED_JSON_DEFAULTS` bắt buộc truyền vào `firstOrCreate()` — 6 cột json NOT
NULL không default ([docblock:26-47](../../backend/database/seeders/PaymentGatewayCatalogSeeder.php#L26-L47)).

**⚠️ Capability set của adapter cũng phải thêm.**
[`PayPayLifecycleMapper::capabilitySet():52`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayLifecycleMapper.php#L52)
hardcode `'paypay.preauth.wallet.v1'` và adapter chỉ trả **một** set. Trong khi
`syncConnectionOptions` — **writer duy nhất** của `payment_gateway_connection_options` —
khớp theo `payment_gateway_options.code === $capabilities->id`
([:489-494](../../backend/app/Services/Payment/Configuration/Internal/EloquentPaymentGatewayConfigurationPersistence.php#L489-L494)).
→ **`/validate` sẽ KHÔNG BAO GIỜ sinh row cho capability QR.** Không thêm set
thứ hai thì row entitlement mãi mãi là hàng viết tay.

## 2. `PayPayQrCodeClient`

`app/Services/Payment/Gateway/PayPay/PayPayQrCodeClient.php` — bắt buộc nằm trong
`Gateway/PayPay/` (SDK hiện chỉ ở 4 file `app/` + 1 file test).

```php
public function create(GatewayConnectionData $c, string $mpid, int $amount,
                       string $currency, string $description,
                       ?string $redirectUrl, string $correlationId): array;
public function retrieve(GatewayConnectionData $c, string $mpid, string $cid): array;
public function delete(GatewayConnectionData $c, string $codeId, string $cid): void;
```

Dùng lại `PayPaySdkClientFactory` + `PayPaySdkCallGuard` + `PayPayCredentialsResolver`.

**Bắt buộc kiểm `resultInfo.code !== 'SUCCESS'` rồi ném.**
[`mapPaymentResponse:96-99`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayLifecycleMapper.php#L96-L99)
**không hề đọc `resultInfo`** → 200-kèm-lỗi biến thành `rawStatus = 'UNKNOWN'`
âm thầm. Không lặp lại lỗi đó ở lớp QR.

`setOrderDescription()` cắt ≤ 255; không đưa free text của khách vào.

**`redirectUrl` cần origin công khai** — pilot local phải có tunnel (M7).

## 3. Retrieve: hai endpoint khác nhau — phải chọn cơ chế

`Code::getPaymentDetails` → `/v2/codes/payments/{mpid}`.
`Payment::getPaymentDetails` → `endpointByPaymentType()` chỉ đặc biệt hoá case
`'pending'`; mọi giá trị khác rơi `default` → `/v2/payments/{mpid}`. **Không có
tham số nào lách được.**

Adapter đang dùng nhánh sai ([:332](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L332)),
và `RetrievePaymentCommand` **không mang** option id/capability/channel — nên
mitigation "adapter rẽ nhánh theo capability" của round 0 **không viết được**.

Ba lựa chọn, **phải chốt một** (T2.6):

| | Cách | Đánh đổi |
|---|---|---|
| A | Prefix quy ước trên mpid (`tempoqr-…`) để adapter dispatch | Rẻ nhất, không đụng command dùng chung; nhưng là magic string |
| B | Thêm field optional vào `RetrievePaymentCommand`, đổ từ `$attempt->connectionOption->option->code` | Sạch nhất; **đụng command Stripe cũng dùng** |
| C | Gateway class riêng dưới registry key khác | Cách ly tốt nhất; trùng lặp nhiều |

**Kết luận round 2: làm B **cộng** hằng số prefix — B một mình là thiếu.**

- **C chết về mặt cấu trúc**, không chỉ trùng lặp: `PaymentGatewayRegistry::forProvider`
  key **chỉ** theo `PaymentGatewayProviderCodeEnum`, và recovery suy nó từ
  `$connection->provider->code`. Registry key riêng đòi một case enum provider mới
  → phải là giá trị `provider` của connection row → **tách rời khỏi catalog,
  policy và credentials của PayPay**.
- **B chạy được**: `connection_option_id` là **NOT NULL + FK**, quan hệ
  `connectionOption->option` có sẵn, không có `preventLazyLoading` → chuỗi
  `$attempt->connectionOption->option->code` resolve được (nhớ eager-load,
  `ReconcilePaymentAttempts:40` hiện chưa). 15 chỗ dựng `RetrievePaymentCommand`
  đều positional đúng 3 tham số → thêm tham số thứ 4 optional **không phá cái nào**.
- **Nhưng B chỉ với tới 1 trong 3 chỗ.** Hai chỗ nữa nhận mpid trần và vẫn đi
  `/v2/payments/{mpid}`: callback replay idempotency
  ([:83-88](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L83))
  và `resolvePayPayPaymentId` ([:379-393](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L379),
  dùng bởi `cancel()` và refund). Pilot chưa gọi tới, nhưng để lại **hai quả mìn**
  cho người nối cancel/refund sau, cùng kiểu dead-letter.

→ Thêm `PayPayQrCodeClient::MPID_PREFIX = 'tempoqr-'` làm **một hằng số duy nhất**,
dùng cả lúc mint lẫn làm **fallback dispatch** ở hai chỗ kia khi không có option
code. ~20 dòng, đóng cả ba. Phản biện "magic string sẽ mục" không áp dụng: đây là
quy ước trên một id **do chính ta mint**, cùng một symbol, có test phủ — khác hẳn
quy ước trên id do bên thứ ba mint.

## 4. `payment-context` — subset của shape có sẵn, KHÔNG phát minh

Round 0 định trả `{provider, option_code, connection_id}`. **Sai hai lần:**

- Endpoint **public, không auth, không throttle** ([customer.php:69](../../backend/routes/api/customer.php#L69)),
  docblock cấm rõ: *"Ids only, never provider/connection detail"*
  ([:203](../../backend/app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php#L203)).
- `option_code` **không tồn tại ở đâu trong repo**; 4 client khác đã dùng chung
  shape của [`EffectivePaymentOptionsPresenter:53-84`](../../backend/app/Services/Payment/Policy/Admin/EffectivePaymentOptionsPresenter.php#L53-L84)
  với khoá `id`, không phải `option_id`.

```jsonc
{
  "data": {
    "policy_revision": 12,
    "gateway_option_id": "…",                  // GIỮ — Stripe cũ đọc
    "async_payment_methods_enabled": false,
    "options": [                                // MỚI — subset, whitelist
      { "id": "…", "method_type": "card",   "rail": "card"   },
      { "id": "…", "method_type": "paypay", "rail": "wallet" }
    ]
  }
}
```

Chỉ `id` + `method_type` + `rail`. **Không** `provider`, **không** `connection_id`,
**không** `trace`. Connection resolve server-side từ order lúc tạo QR.

`rail` giữ lại vì kiosk đã dùng nó để chọn icon/nhãn — customer-web sẽ cần y hệt.

**Không có nhãn từ API:** `display_name` chỉ là `methodType` thô, chưa dịch
([:267](../../backend/app/Services/Payment/Policy/Admin/PaymentPolicyEvaluationService.php#L267)).
Nhãn "PayPay" hardcode phía client, giống kiosk và pos-web. → T1.2 seed tên
ja/en/vi là **vô ích**, bỏ.

**Phân biệt QR với preauth:** không làm được qua `provider`/`rail`/`method_type`
(cả hai option trùng cả ba). → phải thêm `code` vào presenter **dùng chung** (mọi
channel cùng được), rồi lọc server-side. Không lọc theo provider — sẽ chọn nhầm
preauth, mà preauth thì ném cho khách guest.

Gộp một lần đánh giá policy, đừng gọi `effectiveOptions()` hai lần (R22).

## 5. `PayPayPaymentService` — thứ tự đã sửa

```php
public function createOrRetrieveQrCode(CustomerOrder $order): array;
public function syncStatus(CustomerOrder $order): array;
```

### `createOrRetrieveQrCode` — thứ tự MỚI

Round 0 tạo QR **trước** attempt → attempt ném là QR mồ côi, khách trả tiền vào
hư không (R7).

1. **Fail closed nếu transport flag tắt.** `PAYMENT_ORCHESTRATOR_TRANSPORT_CUSTOMER_WEB=false`
   → `prepare*` trả `null` **im lặng** ([:192-194](../../backend/app/Services/Payment/Orchestration/OrderPaymentOrchestrationCompat.php#L192-L194)).
   Stripe chịu được (ghi sổ theo intent id); PayPay **không** (webhook khớp theo
   attempt). → từ chối tạo QR, 503.
2. **Khẳng định `branch.currency === 'JPY'`**, độc lập policy. Policy hardcode
   `'JPY'` trong request ([:380](../../backend/app/Services/Payment/Policy/Admin/PaymentPolicyEvaluationService.php#L380))
   nên chi nhánh VND vẫn được báo là có PayPay (R10).
3. Chặn `Closed`/`Voided` (422), chặn `total <= 0` (422) — theo khuôn
   [createFullPaymentIntent:141,145](../../backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php#L141).
4. Resolve option **QR** qua resolver. Không có → 422 `PAYPAY_NOT_AVAILABLE`.
5. **Khoá đơn** `lockForUpdate()`. Trong lock:
   - `amount = total_amount − paid_amount` (**không** phải `total_amount` trần —
     split bill đã tồn tại, xem [CustomerOrderController:210-218](../../backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php#L210-L218)).
   - Có attempt chưa terminal? Số tiền **chưa đổi** → trả QR cũ. Số tiền **đã đổi**
     (coupon, thêm món) → `deleteQRCode` cái cũ, mint mpid mới.
6. **RESERVE ATTEMPT TRƯỚC.** Attempt sinh `merchantPaymentId`:
   `'tempoqr-' . $attemptId` — **theo attempt, không theo order** (R6). Sửa
   `channel` về `CustomerWeb` sau khi reserve (skeleton hardcode `Pos` ở `:492`).
7. `PayPayQrCodeClient::create(...)`.
8. Lỗi ở (7) → **`deleteQRCode` trước (best-effort), rồi mới huỷ attempt.**

**Round 2 xác nhận mọi mảnh ghép đã có sẵn, không cần thêm port operation:**

- `prepareAttemptSkeleton` **không set `provider_object_id`** — NULL lúc reserve,
  và MySQL cho phép NULL trùng vô hạn trên unique → **hai reservation đồng thời
  không đụng nhau**. Đây chính là cách Stripe đang chạy.
- `attachCustomerWebPrepareReference` ([:603-614](../../backend/app/Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php#L603))
  set `provider_object_id` **và** lật `channel` → `CustomerWeb` — đúng hai việc
  bước 6 cần. Provider-neutral trong code dù docblock nói giọng Stripe.
- **Huỷ attempt = `finalize` với evidence `Canceled`**, không cần method mới.
  `GatewayPaymentResult` cho phép `payment: null` với `Canceled`. Khuôn có sẵn:
  `finalizeLegacyFail`.
- `finalize` đòi `expectedVersion` — với attempt vừa reserve thì là **1**.

**Hai điều kiện tiên quyết round 1 nói ngầm, phải nói rõ:**
- `PreparePaymentCommand:59-61` **ném nếu `actorId === null`**. Khách guest không
  có → cần một **system-actor UUID** riêng cho PayPay, kiểu `STRIPE_SYSTEM_ACTOR_ID`.
- `verifyPreparation` **đòi tender** có `PaymentMethod->type` map đúng `TenderKind`.
  `type = 'qr'` → `TenderKind::Qr` ✔ khớp §6, nhưng **phải truyền tường minh**.

### ⚠️ Lỗ MỚI do chính bước 8 mở ra

Bước 8 biến "QR mồ côi **không** attempt" thành "QR mồ côi có attempt **terminal**".
Nếu QR *đã* được tạo nhưng response HTTP mất (timeout, reset) và ta huỷ attempt,
khách trả QR đó → webhook rơi vào attempt `Canceled` → `isTerminalAttemptState` →
**`paypay_ignored_terminal`** → event đánh dấu **succeeded** → **tiền mất im lặng**.

→ Vì vậy cảnh báo phải phủ **cả hai** outcome, không chỉ `paypay_no_matching_attempt`.
Và `deleteQRCode` phải chạy **trước** khi huỷ, không phải sau.

**Kèm:** `finalizeAttempt:217` sẽ **NULL hoá mpid** khi huỷ (`$evidence->payment?->value`
không có fallback). Đây mới là thứ **kích hoạt** bug đó — không phải webhook như
round 1 đoán. Hoặc truyền `new ProviderObjectReference($mpid)` trong evidence huỷ,
hoặc vá `?? $attempt->provider_object_id`.
9. Trả `{qr_url, deeplink, merchant_payment_id, amount, expires_at, expires_in_seconds}`.

**`expires_in_seconds` bắt buộc** — countdown phải neo vào server. Repo đã học
bài này ở plan-031 (`seconds_until_due`, "skew-immune"). Khách lệch giờ máy sẽ
thấy QR "hết hạn 9 tiếng trước".

### `syncStatus`

`retrieve` từ PayPay là **nguồn sự thật duy nhất**; không tin state local.
`COMPLETED` → §6. Amount lấy **từ response PayPay**, không từ `order.total_amount`.

## 6. Ghi sổ — mô hình theo `markOrderPaidFromIntent`, KHÔNG phải `recordStripeWebhookPayment`

Đây là lỗi nặng nhất của round 0. `recordStripeWebhookPayment` là SELECT-rồi-INSERT
trần, nhận đơn **đã bị khoá sẵn**. Guard thật nằm ở
[`StripePaymentService::markOrderPaidFromIntent:892-1018`](../../backend/app/Services/Customer/StripePaymentService.php#L892-L1018).

`OrderPaymentService::recordPayPayPayment()` phải có **đủ 5 lớp**:

```
DB::transaction:
  1. CustomerOrder::lockForUpdate()
  2. idempotency probe DƯỚI lock
     - reference_no = $mpid  VÀ
     - idempotency_key = $mpid   ← để dùng unique (customer_order_id, idempotency_key) CÓ SẴN
  3. currency khớp? không → từ chối + đánh dấu hoàn tiền
  4. overpay? tính lại tổng sổ dưới lock; vượt → từ chối + đánh dấu hoàn tiền
  5. ledgerWriter->createRow(... channel = CustomerWeb ...)
sau commit:
  6. hoàn tự động khoản mắc kẹt (ca 3 và 4)
  7. syncLedgerCacheAndSettleIfPaid($order, referenceNo: $mpid)
```

**Vì sao phải stamp `idempotency_key`:** `order_payments` **không có unique nào**
trên `reference_no` (varchar(100), không index). Unique có sẵn là
`(customer_order_id, idempotency_key)`, mà `recordStripeWebhookPayment` **không
set** `idempotency_key` → thành `(order_id, NULL)`, MySQL cho NULL trùng vô hạn.
Stamp mpid vào đó là cách duy nhất có backstop cấp DB.

⚠️ `EloquentOrderPaymentLedgerWriter::createWithUniqueCode:102-116` bắt
`UniqueConstraintViolationException` rồi **retry 5 lần với payment_code mới** —
không phân biệt "trùng reference" với "đụng code". Phải dạy nó phân biệt.

**Ca overpay là ca webhook** — không 409 được cho ai vì không có ai đang chờ.
Bắt buộc **hoàn tự động**, giống Stripe `:1012-1018`.

### ⚠️ Round 1 tự mâu thuẫn — giải như sau

§6 bước 6 đòi **hoàn tự động** khoản mắc kẹt; §8/D5 đòi **409 mọi refund** chạm
row PayPay. Hai điều đó triệt tiêu nhau.

Giải: **hai đường khác nhau, không phải một.**
`StripePaymentService::refundStrandedCharge:1341-1374` **không ghi ledger row nào**
— nó là lệnh gọi gateway thuần + log, có kill switch riêng. Còn D5 chặn
`OrderPaymentService::refund` (đường **staff bấm nút**, có ghi sổ, có 赤伝).

→ Giữ nguyên **409 cho refund do staff khởi tạo**, và thêm **đường hoàn khoản
mắc kẹt riêng** gọi thẳng `PayPayPaymentGateway::refund` với kill switch của nó.
Nếu không muốn làm đường thứ hai ở pilot thì phải **hạ exit criteria #4** xuống
"từ chối + cảnh báo + hoàn tay trên portal" — nhưng phải chọn, không được để
nguyên hai câu chọi nhau.

### Nguồn currency phải là attempt, không phải branch

Guard lệch currency **snapshot từ `payment_attempts.currency`**
([:493](../../backend/app/Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php#L493)),
**không** từ `branch.currency` — admin đổi currency chi nhánh được (plan-031), và
Stripe cố ý đọc snapshot bất biến trong metadata chứ không đọc lại branch.

### `createWithUniqueCode` biến backstop DB thành dead-letter

`EloquentOrderPaymentLedgerWriter:104-116` bắt `UniqueConstraintViolationException`
**không phân biệt**, retry 5 lần rồi ném `RuntimeException`. Trong đường webhook,
cái đó chui qua `ProcessPaymentProviderEventJob` → 5 lần → `markDeadLetter`.

→ Đúng cái race mà unique sinh ra để bắt, plan lại nhận về **event dead-letter**
thay vì "đã ghi rồi". Bắt buộc phân biệt index nào bị vi phạm (hoặc probe lại
`findByOrderAndIdempotencyKey` khi catch) và trả tín hiệu "trùng" mà caller coi
là **thành công**.

`payment_method_id`: **dùng lại row `e_wallet` đã seed** (code `e_wallet`, type
`qr`), **không** tạo code `paypay` mới. `TillSessionService:2393-2397` map nhóm
tender theo **code**; `paypay` rơi vào nhóm nào cũng không → expected theo nhóm
không cân được (R17).

## 7. Webhook — **PHẢI VIẾT MỚI** (round 0 nói ngược)

Round 0: *"Không phải viết mới."* **Sai, và là câu nguy hiểm nhất trong plan.**

`applyPayPayNotification` → `recoverAttempt` → `finalizeAttempt`
([:201-250](../../backend/app/Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php#L201-L250))
chỉ update `payment_attempts`. **Không ghi `order_payments`.** Stripe sống được
nhờ **route webhook thứ hai** ([customer.php:146](../../backend/routes/api/customer.php#L146))
ghi sổ riêng. PayPay chỉ có inbox.

→ Phải nối `recordPayPayPayment()` vào recovery. **Chỉ có ĐÚNG MỘT chỗ hợp lệ**
— round 2 đã loại hai chỗ còn lại:

| Chỗ | Vì sao KHÔNG |
|---|---|
| `ProviderEventApplicator::applyPayPayNotification` | Chỉ nhận lại `bool`; `recoverAttempt` **vứt `$evidence`**. Số tiền PayPay thật không lấy lại được từ attempt (`finalizeAttempt` không ghi `amount_minor` từ evidence, mapper không nhét amount vào summary) → buộc phải dùng `$attempt->amount_minor`, tức **số ta xin**, không phải số PayPay lấy. Trái thẳng DESIGN §5. |
| `EloquentPaymentPersistence::finalizeAttempt` | **Deadlock ABBA**: nó mở transaction riêng và giữ `payment_attempts … lockForUpdate` suốt thân hàm. Thêm `CustomerOrder::lockForUpdate()` vào đó = webhook đi **attempt→order**, còn poll đi **order→attempt**. Cộng thêm blast radius **toàn phần** với Stripe/POS/kiosk — mọi caller đều đã ghi ledger row trước đó, sẽ ghi đúp. |

**✅ Chỗ đúng: `ProviderRetrievalRecoveryService::recoverAttempt`, ngay SAU khi
`$this->payments->finalize(...)` trả về ([:69](../../backend/app/Services/Payment/ProviderEvent/ProviderRetrievalRecoveryService.php#L69)).**

Ở đó có đủ: `$attempt` (→ order id) **và** `$evidence->processedMoney` (số tiền
PayPay xác nhận, mapper đổ vào từ `data.amount.amount`). Lock attempt **đã commit
xong**, nên lấy lock order tiếp theo giữ đúng thứ tự **order→attempt** mà
codebase đang theo ([StripePaymentService:892-893](../../backend/app/Services/Customer/StripePaymentService.php#L892) →
`:1003`). Không có chu trình.

Ràng buộc kèm theo:
- Gate `provider === Paypay` **và** `$evidence->state === Succeeded`.
- `recordPayPayPayment()` mở **transaction riêng của nó** — tuyệt đối không gọi
  khi đang giữ lock attempt.
- Blast radius với Stripe thu về **một** scheduled command
  (`payments:reconcile-attempts --execute`), thay vì mọi đường finalize.
- Applicator **không** nằm trong transaction nào (`apply`, `applyInboxEvent`,
  `ProviderEventProcessor::process`, `ProcessPaymentProviderEventJob::handle` đều
  không mở) — chỉ các method persistence tự mở.

**Cảnh báo phải rộng hơn round 1 nói:** cả `paypay_no_matching_attempt` (`:309`)
**và `paypay_ignored_terminal`** (`:308`) đều phải kêu với mpid `tempoqr-`. Xem §5.

**Sửa kèm:** `finalizeAttempt:217` ghi `provider_object_id => $evidence->payment?->value`
thiếu `?? $attempt->provider_object_id` (`markAttemptForReconciliation:264` thì
có) → evidence null hoá cột, **phá khoá khớp cho mọi webhook sau**.

**Cảnh báo:** `paypay_no_matching_attempt` với mpid hình dạng `tempoqr-` phải
**kêu**, không được trả outcome hiền lành rồi đánh dấu event `succeeded`.

## 8. Refund — chặn cứng (D5)

`isStripePayment()` đòi `reference_no` bắt đầu `pi_` **và** code `stripe`
([:1237-1248](../../backend/app/Services/Customer/OrderPaymentService.php#L1237-L1248)).
Row PayPay rơi xuống nhánh **ledger-only**: sổ báo đã hoàn, tiền vẫn ở PayPay,
kèm 適格返還請求書 (`:1121`). Kill switch + hạn mức refund cũng nằm trên nhánh chết.

→ **409 cho mọi refund chạm row có `payment_methods.code = 'e_wallet'` + attempt
provider `paypay`**, kèm thông báo chỉ staff sang PayPay merchant portal.

## 9. Endpoint

```php
Route::post('orders/{id}/paypay-qr',        …'createPayPayQrCode');
Route::get ('orders/{id}/paypay-qr/status', …'payPayQrStatus');
```

Throttle **theo order id, không theo IP** — dùng lại khuôn limiter
`customer-order-read` ([AppServiceProvider:492-495](../../backend/app/Providers/AppServiceProvider.php#L492-L495),
120/min, có comment giải thích: mọi điện thoại trong quán chung một IP NAT).

## 10. customer-web — nâng cấp hành vi, KHÔNG đổi hiển thị

### 10.1 Round 0 chỉ sai chỗ

DESIGN round 0 trỏ `checkout-page.tsx#L1314-L1330` là "cái radio hardcode". Đó là
block **dine-in pay-before**, không phải takeaway — và block đó gần như **không
tới được** trong app đang chạy (dine-in thật đi qua `/dine-in/[shop]/table/[qrToken]`,
còn `booking-page` thì sau cờ `FEATURES.booking = false`).

Sáu surface chọn phương thức thanh toán:

| # | Surface | Vị trí | Giá trị | Phạm vi plan |
|---|---|---|---|---|
| 1 | Checkout desktop, takeaway | `checkout-page.tsx:1353-1436` | card/qr_pay/counter | ✅ trong |
| 2 | Checkout desktop, dine-in pay-before | `checkout-page.tsx:1270-1332` | card/qr_pay | ✅ trong |
| 3 | Checkout desktop, dine-in pay-after | `checkout-page.tsx:1250-1267` | counter/call_staff | ❌ ngoài |
| 4 | Checkout mobile | `checkout-page-mobile.tsx:867-940` | card/qr_pay/counter | ✅ trong |
| 5 | Dine-in bill | `dine-in/.../payment-view.tsx:1724-1745` | online/counter | ❌ ngoài — **đây là câu trả lời cho Q1** |
| 6 | Trả đơn đã có | `orders/[id]/pay/page.tsx:290-322` | online/counter | ✅ trong (màn QR) |

Surface 3 có `call_staff` — **không phải gateway option**; một `options.map()` ngây
thơ sẽ xoá mất nó.

### 10.2 Không làm nút PayPay biến mất

T6.2 round 0 ("không effective → không hiện") sẽ khiến nút PayPay **biến mất ở
mọi chi nhánh** — vì theo chính audit của plan, hiện **0 chi nhánh** có cấu hình.
Ship cái đó nghĩa là ship một regression, mà round 0 xếp R7 là **THẤP**.

→ **Giữ `qr_pay` render vô điều kiện** (nghĩa thủ công như hôm nay). Effective
option chỉ **nâng cấp** hành vi sang luồng API. Chi nhánh có cấu hình → màn QR;
chưa có → y như hôm nay. Diff ~40 dòng thay vì viết lại state machine checkout.

`payment` không phải tuỳ chọn hiển thị — nó là **bộ chọn luồng**: `counter` +
takeaway thì **không tạo order backend nào**, chỉ ghi draft localStorage rồi sang
`/order-confirm/{draftId}` (`checkout-page.tsx:507-599`). Không thể data-driven
thuần.

Giá trị radio giữ nguyên `qr_pay` — BE enum chưa có `paypay`, đổi là 422.

### 10.3 Màn QR: dùng `/orders/[id]/pay`, không tạo route mới

Route [`app/[locale]/orders/[id]/pay/page.tsx`](../../customer-web/app/[locale]/orders/[id]/pay/page.tsx)
đã làm sẵn ~90%:

| Cần | Đã có |
|---|---|
| Chống reload | order id nằm trên URL, refetch khi mount (`:102`) |
| Chặn khách khác | `loadGuestOrders()` + kiểm id (`:94-99`) |
| Chặn đơn đã trả | `PAYABLE_STATUSES` + `!is_fully_paid` (`:112-116`) — chính là R2 |
| Vẽ QR | `QRCodeSVG` (`:400`) |
| Realtime | `useOrderPaidRealtime` (`:43`) |
| Vào lại sau khi đóng tab | `/orders` → `/orders/{id}` → "Pay now" |

→ Thêm giá trị thứ ba vào bộ chọn `method` sẵn có (`:290-322`), và checkout điều
hướng `qr_pay` sang `/orders/{id}/pay?method=paypay` sau khi tạo đơn.

**Không đặt QR ở `/order-confirm/{id}`** — route đó đọc draft localStorage, mà
draft bị xoá ngay khi order được tạo (`:67-74, 136`).

Nếu Q1 mở sang dine-in: nới cổng `order_type === "takeaway"` (`:116`) là một
dòng — rẻ hơn nhiều so với sửa `payment-view.tsx`.

### 10.4 Realtime trước, poll sau

``use-order-paid-realtime.ts``
đã subscribe kênh public `customer.order.{orderId}`, nghe `.order.paid` và
`.payment.recorded`. Docblock của nó mô tả **đúng** kịch bản này. Backend đã
broadcast cả hai event, và vì §6 gọi `syncLedgerCacheAndSettleIfPaid` nên **cả
hai bắn cho PayPay mà không cần thêm gì**.

→ Realtime là chính; poll chậm 10–15s làm dự phòng; poll ngay khi
`visibilitychange`. Round 0 định poll 2s — bỏ. **R4 hạ xuống THẤP.**

Deploy cần `NEXT_PUBLIC_REVERB_HOST` đúng (bẫy #386 đã ghi trong `.env.development`).

### 10.5 Thư viện QR: không cần thêm gì

`qrcode.react@^4.2.0` đã là dependency, đã dùng ở 4 chỗ. Dùng
`<QRCodeSVG value={qr_url} size={220} fgColor="#000000" bgColor="#ffffff" />`.

customer-web **không** dùng `@godxjp/ui` — nó có shadcn primitives riêng trong
`components/ui/`. Dựng màn từ đó.

### 10.6 i18n

Đã có, dùng lại: `checkout.payByPayPay`, `checkout.paymentFailed`,
`checkout.processing`, `orderSuccess.titlePaid`, `guestOrders.payMethod*`.

Thêm vào cả 3 file `messages/{ja,en,vi}.json`:
- `guestOrders.payMethodPayPay`
- `checkout.paypayUnavailable`
- namespace mới **`paypay`**: `title`, `scanHint`, `amountLabel`, `orderCodeLabel`,
  `openAppCta`, `expiresIn` (`{time}`), `expired`, `retryCta`, `waitingForPayment`,
  `checking`, `createFailed`, `notAvailable`, `alreadyPaid`, `cancelled`,
  `keepOpenHint`, `backToOrder`.

### 10.7 Nợ sẵn có cần dọn cùng

`PAYABLE_STATUSES` lệch nhau giữa `orders/[id]/page.tsx:294` và
`orders/[id]/pay/page.tsx:112` dù comment ghi "keep them in sync".
