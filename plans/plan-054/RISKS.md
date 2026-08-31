# Plan 054 — RISKS (review round 1)

Round 0 có 8 rủi ro. Review tìm thêm 7 cái **nghiêm trọng hơn** và chứng minh 3
mitigation của round 0 là vô dụng. Danh sách dưới đã sắp lại theo mức thật.

---

## R1 — ~~Đường webhook không ghi sổ~~ · **ĐÃ ĐÓNG bởi `recordPayPayPayment()`**

Đã có đường ghi sổ cho webhook: `applyPayPayNotification` giờ đi qua funnel
`recordPayPayPayment()` (`backend/app/Services/Payment/Orchestration/…`), ghi
`order_payments` và đóng đơn. Ghim bằng `Plan054PayPayWebhookLedgerTest` — 10
test, trong đó *"books the money on the ledger and settles the order from the
webhook path"* là đúng cái mà R1 nói là không tồn tại, và *"keeps the merchant
payment id when finalizing on evidence that carries no reference"* đóng nốt
"bug tiềm ẩn cùng chỗ" (`provider_object_id` bị null hoá) mô tả bên dưới.

Phép đo (2026-08-06): `php -d memory_limit=-1 vendor/bin/pest --compact
tests/Feature/Payment/Plan054PayPayWebhookLedgerTest.php
tests/Feature/Payment/Plan054LedgerWriterUniqueViolationTest.php` → **13 passed
(33 assertions)**.

Giữ nguyên mô tả gốc bên dưới: nó là lý do R1 từng là BLOCKER, và là bản đồ của
đường ghi sổ mà ai sửa vùng này cũng phải hiểu.

<details><summary>Mô tả cũ (round 1) — vì sao từng là BLOCKER</summary>

`applyPayPayNotification` → `recoverAttempt` → `finalizeAttempt`
([EloquentPaymentPersistence.php:201-250](../../backend/app/Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php#L201-L250))
chỉ update `payment_attempts`. **Không có dòng `order_payments` nào được ghi.**

Stripe sống sót vì có **entry point thứ hai**: route webhook riêng
([customer.php:146](../../backend/routes/api/customer.php#L146) →
[StripePaymentService.php:990](../../backend/app/Services/Customer/StripePaymentService.php#L990)
→ `recordStripeWebhookPayment`). PayPay chỉ có inbox.

Hậu quả: khách trả tiền → attempt `succeeded` → `payment_provider_events.outcome
= orchestrator_paypay_attempt_recovered` → **đơn vẫn pending, sổ trống**. Exit
criteria #2 xanh trong khi #1 đỏ.

**Sửa:** viết đường ghi sổ cho webhook. Đây là sửa vào code **dùng chung với
Stripe** → blast radius thật, không phải "dùng lại inbox".

**Bug tiềm ẩn cùng chỗ:** `finalizeAttempt:217` ghi
`'provider_object_id' => $evidence->payment?->value` **không có** `?? $attempt->provider_object_id`,
trong khi `markAttemptForReconciliation:264` thì có. Evidence trả reference null
→ null hoá cột → **phá khoá khớp `(connection_id, provider_object_id)` cho mọi
webhook về sau**.

</details>

## R2 — Ghi sổ thiếu toàn bộ guard tiền · **BLOCKER**

Round 0 bảo sao chép `recordStripeWebhookPayment():1305`. Hàm đó nhận đơn **đã
bị khoá sẵn** và chỉ SELECT-rồi-INSERT. Guard thật ở
[`StripePaymentService::markOrderPaidFromIntent`](../../backend/app/Services/Customer/StripePaymentService.php#L892-L1018):

| Guard | Vị trí |
|---|---|
| `DB::transaction` + `CustomerOrder::lockForUpdate()` | `:892-893` |
| Idempotency probe **dưới lock** | `:912-919` |
| Lệch currency → từ chối + đánh dấu refund | `:929-949` |
| Overpay: tính lại tổng sổ dưới lock, từ chối nếu vượt | `:958-988` |
| Auto-refund khoản mắc kẹt sau commit | `:1012-1018` |

**Kịch bản:** đơn ¥3.000, khách mint QR, rồi trả cash ở quầy → đơn đóng. QR còn
sống 5 phút, khách quét. Theo draft round 0: không thấy `reference_no` → INSERT
¥3.000 → `paid_amount = 6000` trên đơn ¥3.000. Stripe **chặn** ở đúng chỗ này.

**Sửa:** mô hình theo `markOrderPaidFromIntent`, không phải `recordStripeWebhookPayment`.

## R3 — ~~`reference_no` không phải khoá idempotent~~ · **ĐÃ ĐÓNG bởi (a)+(b)+(c)**

Cả ba vế của mục "Sửa" đã vào cây: đơn được lock, `idempotency_key` stamp bằng
`merchantPaymentId` để dùng unique `(customer_order_id, idempotency_key)` đã có
sẵn, và `EloquentOrderPaymentLedgerWriter` đã **phân biệt được hai loại vi phạm
unique** — đụng chốt idempotency thì ném thẳng, chỉ đụng `payment_code` mới
retry.

Ghim bằng `Plan054LedgerWriterUniqueViolationTest` (3 test, đúng ba hành vi:
*ném thẳng lỗi unique khi đụng chốt idempotency* · *chỉ đúng MỘT lần chèn, không
thử lại 4 lần nữa* · *vẫn thử lại như cũ khi va chạm thật sự là payment_code*) và
`Plan054PayPayWebhookLedgerTest::"is idempotent when the same notification is
applied twice"`.

Phép đo (2026-08-06): cùng lệnh pest ở R1 → **13 passed (33 assertions)**.

Giữ nguyên mô tả gốc bên dưới — nó giải thích vì sao "thêm unique lên
`reference_no`" là cách sửa SAI, thứ mà người sau rất dễ nghĩ lại.

<details><summary>Mô tả cũ (round 1) — vì sao từng là BLOCKER</summary>

`order_payments` chỉ unique trên `payment_code` và `(customer_order_id, idempotency_key)`
([migration:82,88](../../backend/database/migrations/omnify/2000_01_01_000138_create_order_payments_table.php#L82)).
`reference_no` là `varchar(100)` nullable **không index**. `recordStripeWebhookPayment`
không set `idempotency_key` → unique thành `(order_id, NULL)`, MySQL cho phép
NULL trùng vô hạn.

Hôm nay an toàn **chỉ vì** mọi caller giữ `lockForUpdate()`. Plan có hai writer ở
**hai process khác nhau** (queue worker + HTTP poll 2s), không chia sẻ lock nào.
Mặc định MySQL REPEATABLE READ → cả hai cùng thấy trống, cùng INSERT.

Thêm unique lên `reference_no` **không cứu**: `EloquentOrderPaymentLedgerWriter::createWithUniqueCode`
([:102-116](../../backend/app/Services/Payment/Orchestration/Internal/EloquentOrderPaymentLedgerWriter.php#L102-L116))
bắt `UniqueConstraintViolationException` rồi **retry 5 lần với payment_code mới** —
không phân biệt được "trùng reference" với "đụng code".

**Sửa:** (a) lock đơn; **và** (b) stamp `idempotency_key = $merchantPaymentId` để
dùng unique **đã có sẵn**; (c) dạy writer phân biệt hai loại vi phạm.

</details>

## R4 — Refund ghi sổ mà không gọi PayPay · **BLOCKER**

[`OrderPaymentService::refund():1027`](../../backend/app/Services/Customer/OrderPaymentService.php#L1027)
gate đường hoàn tiền thật bằng `isStripePayment()`, đòi `reference_no` bắt đầu
`pi_` **và** method code `stripe` ([:1237-1248](../../backend/app/Services/Customer/OrderPaymentService.php#L1237-L1248)).
Row PayPay → false.

Nút refund của staff sẽ: lật row gốc thành `refunded` (`:1056`), ghi row âm
`succeeded` (`:1090`), hạ `paid_amount`, void hoá đơn (`:1118`), và **phát
適格返還請求書** (`:1121`) — cho khoản tiền **chưa bao giờ rời PayPay**. Kill
switch `stripe_live_refunds_enabled` và hạn mức mỗi lần refund nằm cùng nhánh
chết → PayPay refund **không có cả hai**.

**Sửa (D5):** chặn 409 cho mọi row `paypay` ở pilot. Wiring thật để plan sau.

## R5 — `retrievePayment` gọi sai endpoint → dead-letter · **BLOCKER**

Xác nhận ở tầng SDK: `Code::getPaymentDetails` → `/v2/codes/payments/{mpid}`;
`Payment::getPaymentDetails` → `endpointByPaymentType()` chỉ đặc biệt hoá case
`'pending'`, mọi thứ khác rơi `default` → `/v2/payments/{mpid}`. **Không có
tham số nào lách được.**

Adapter dùng `$client->payment->getPaymentDetails`
([PayPayPaymentGateway.php:332](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L332)).

Round 0 đoán "kẹt ProviderPending". Thực tế **tệ hơn**: `Controller::doCall` ném
khi HTTP ≥ 400, `PayPaySdkCallGuard` chỉ nuốt 401/403 → `recoverAttempt` ném →
job retry 5 lần → **dead-letter `PROCESSING_EXHAUSTED`**. Khách đã trả tiền; dấu
vết duy nhất là một warning.

Mitigation round 0 (**"adapter rẽ nhánh theo capability"**) **không viết được**:
`RetrievePaymentCommand` chỉ mang connection + request + payment reference.

**Sửa:** phải chọn và ghi vào TASKS — hoặc quy ước prefix trên mpid để adapter
dispatch, hoặc thêm field optional vào `RetrievePaymentCommand` (đụng command
dùng chung với Stripe), hoặc gateway class riêng dưới registry key khác.

**Kèm theo (R5b):** `resolvePayPayPaymentId():392` khi không lấy được id thì
**âm thầm trả `merchantPaymentId`** như thể là PayPay paymentId. Và
`mapPaymentResponse:96-99` **không hề đọc `resultInfo.code`** → 200-kèm-lỗi
thành `rawStatus = 'UNKNOWN'` lặng lẽ.

## R6 — `merchantPaymentId` theo order = một payment vĩnh viễn · **BLOCKER**

`payment_attempts` có unique **thật** trên `(connection_id, environment, provider_object_id)`
([migration:81](../../backend/database/migrations/omnify/2000_01_01_000136_create_payment_attempts_table.php#L81)).

- Tổng tiền đơn **đổi được** sau khi mint QR: `apply-coupon` là route public
  ([customer.php:106](../../backend/routes/api/customer.php#L106)), thêm món dine-in cũng vậy.
  Draft trả lại QR **cũ với số tiền cũ**.
- Retry sau khi QR terminal → vi phạm unique, và ném **sau khi** QR mới đã live.
- **Split bill đã tồn tại** ([customer.php:114](../../backend/routes/api/customer.php#L114)).
  Stripe split tính `total − paid` dưới lock
  ([CustomerOrderController.php:210-218](../../backend/app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php#L210-L218));
  draft dùng `total_amount` trần → khách trả ¥1.000 bằng Stripe rồi chọn PayPay
  → QR full total → **overpay chắc chắn**.
- Sau một lần refund, `reference_no` xuất hiện trên **hai** row (`:1103`) → lần
  trả sau không bao giờ ghi sổ được, và `->first()` có thể trả row âm.

**Sửa:** mpid theo **attempt**, không theo order. Amount = `total − paid` dưới
lock. Nhánh "trả QR cũ" phải kiểm số tiền chưa đổi, khác thì `deleteQRCode` +
mint mpid mới.

## R7 — QR sinh trước attempt → QR mồ côi · **MAJOR**

Draft xếp: tạo QR (bước 5) → tạo attempt (bước 6). Attempt **có thể ném thật**:
`resolvePolicyRevisionId` ném khi không có `payment_policy_revisions` khớp
([:533-545](../../backend/app/Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php#L533-L545)),
cộng unique ở R6. Lúc đó QR đã live → khách trả → webhook không thấy attempt →
`paypay_no_matching_attempt` → event đánh dấu **succeeded**, tiền không vào sổ,
**không alert**.

**Sửa:** reserve attempt trước (attempt sở hữu mpid), rồi mới tạo QR; lỗi tạo QR
thì huỷ attempt. Và `paypay_no_matching_attempt` với mpid hình dạng của ta phải
**kêu**, không được trả outcome hiền lành.

Kèm: `prepareAttemptSkeleton:492` hardcode `'channel' => Pos`; Stripe sửa lại sau
bằng `attachCustomerWebPrepareReference`. Không làm tương tự thì attempt bị gắn `pos`.

## R8 — ~~Thiếu `payment_policy_revisions`~~ · **ĐÃ ĐÓNG bởi D8**

Bootstrap kiểu Stripe tự sinh policy revision (`ensurePolicyRevision`), nên rủi
ro này biến mất. Giữ mô tả bên dưới làm ghi chú cho ai định quay lại phương án
artisan command.

<details><summary>Mô tả cũ</summary>

Resolver trả null khi `revision < 1`
([:93-96](../../backend/app/Services/Payment/Orchestration/Internal/CustomerWebStripeConnectionResolver.php#L93-L96)),
và `EloquentPaymentAuthorityVerificationPort:65-70` kiểm lại đúng revision lúc
prepare. Revision **chỉ** do `publishIfChanged` ghi, gọi được từ các method admin.
Artisan command chỉ INSERT 3 row thì **không publish gì**.

Stripe che được nhờ `PaymentGatewayOrchestrationBootstrap::ensurePolicyRevision`
fabricate; **PayPay không có fallback**.

**Sửa:** T5.1 phải publish qua `PaymentPolicyRevisionPublisher`; runbook phải
verify row revision tồn tại.

</details>

## R9 — ~~Entitlement scope theo brand~~ · **HẠ xuống MINOR bởi D9**

Round 1 xếp MAJOR vì tưởng đây là lỗi. **Không phải** — brand bật là bật hết,
chi nhánh nào không muốn thì tự `preference = Disabled`. Đó **đúng là hành vi
mong muốn** (D9).

Còn lại hai việc, không phải rủi ro:
- Runbook phải nói rõ để không ai bất ngờ.
- **T5.6**: `updateShopOption` hiện **từ chối** giá trị `enabled`
  ([:131-133](../../backend/app/Services/Payment/Policy/Admin/PaymentPolicyEvaluationService.php#L131-L133)) —
  phải kiểm `disabled` có qua được không. Không có đường tắt cấp shop thì D9 chỉ
  đúng một nửa.

<details><summary>Mô tả cũ (round 1)</summary>

`loadForBranch` query connection theo `organization_id` + `brand_id`, **không
theo branch** ([:67-78](../../backend/app/Services/Payment/Policy/Persistence/EloquentPaymentPolicyCandidateLoader.php#L67-L78)).
Và thiếu `shop_payment_options` chỉ cho ra `Inherit`, resolver deny **chỉ khi**
`Disabled`/`Blocked` ([:208-215](../../backend/app/Services/Payment/Policy/PaymentPolicyResolver.php#L208-L215)).

→ **Cấp entitlement một lần = bật PayPay cho MỌI chi nhánh trong brand.**
Câu chuyện an toàn của round 0 ("thiếu row thì không hiện") sai ngược.

**Sửa:** muốn pilot 1 quán thì phải ghi `shop_payment_options.preference =
Disabled` cho mọi branch khác — **hành động chủ động**, không phải mặc định.

</details>

## R23 — Bẫy `environment` · **ĐÃ ĐÓNG bởi D8**

Round 1 (B14) phát hiện: đặt `environment = 'sandbox'` cho pilot sandbox sẽ ra
`EnvironmentMismatch` → PayPay **vô hình, không báo lỗi ở đâu cả**, vì policy tính
`app()->environment('production') ? Live : Test`. Connection tạo tay lúc verify đã
dính đúng bẫy này.

D8 đóng nó **by construction**: bootstrap dùng **đúng cùng một biểu thức** với
policy loader, nên hai bên không thể lệch. Không copy cách suy từ secret của
Stripe (`str_contains($secret, '_live_')`) — PayPay không có dấu hiệu đó trong key.

Test P-08 giữ lại làm hàng rào hồi quy.

## R24 — Hạn QR không ép được theo settings shop · **MINOR** (D7 đã xử lý)

Probe sandbox: PayPay trả `expiryDate = tạo + 301s`, và `CreateQrCodePayload`
**không có setter** cho hạn QR. Shop đặt `takeaway_payment_timeout_minutes = 60`
thì QR vẫn chết sau 5 phút.

D7 giải bằng hai tầng: `payment_due_at` là hạn thật của đơn; QR chỉ là cửa sổ con,
hết thì mint mã mới. Việc còn lại là UX phải rõ — khách không được hiểu nhầm "QR
hết hạn" thành "đơn hết hạn".

**Ràng buộc:** QR mới **không bao giờ** được sống quá `payment_due_at`.

## R10 — Currency hardcode `'JPY'` trong policy request · **MAJOR**

[`requestForOption():380`](../../backend/app/Services/Payment/Policy/Admin/PaymentPolicyEvaluationService.php#L380)
truyền cứng `'JPY'` (và `'browser'` ở `:378`). Nên check currency
([resolver:318-321](../../backend/app/Services/Payment/Policy/PaymentPolicyResolver.php#L318-L321))
luôn đánh giá JPY bất kể `branches.currency`. Chi nhánh VND vẫn được báo là có
PayPay, rồi draft gửi `amount = total_amount` (số VND) sang PayPay **như yên**.

**Sửa:** service phải tự khẳng định `branch.currency === 'JPY'`, fail closed,
độc lập với policy. P-07 **không viết được** qua `effectiveOptions()`.

## R11 — `option_code` không tồn tại; không phân biệt được QR với preauth · **MAJOR**

[`EffectivePaymentOptionsPresenter:62-83`](../../backend/app/Services/Payment/Policy/Admin/EffectivePaymentOptionsPresenter.php#L62-L83)
trả `id`, `provider`, `rail`, `method_type`, `display_name` — **không có catalog
code**. Hai option PayPay trùng nhau cả ba trường phân loại (provider `paypay`,
rail `wallet`, method_type `paypay`).

→ "chọn option PayPay theo provider" **có thể chọn nhầm preauth**, mà preauth thì
ném cho khách guest.

Thêm: `display_name` chỉ là `methodType ?? 'payment'`
([:267](../../backend/app/Services/Payment/Policy/Admin/PaymentPolicyEvaluationService.php#L267))
→ tên option ja/en/vi seed ở T1.2 **không ai đọc**, và frontend không có nguồn nhãn.

## R12 — `payment-context` public sẽ lộ nội bộ · **MAJOR**

Docblock ghi rõ *"Ids only, never provider/connection detail: this is a public
endpoint"* ([CustomerBranchController.php:203](../../backend/app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php#L203)).
Presenter mang `connection_id`, `connection_option_id`, `shop_option_id`,
`owner_scope`, `operator_org_unit_id` và **toàn bộ `trace` từng tầng policy**.
DESIGN §4 round 0 đã đưa `connection_id` vào payload public.

**Sửa:** whitelist tường minh; bỏ `trace`; không trả connection id.

## R13 — Backstop không nhìn thấy attempt của plan · **MAJOR**

[`ReconcilePaymentAttempts:40`](../../backend/app/Console/Commands/ReconcilePaymentAttempts.php#L40)
chỉ chọn `state = ReconciliationRequired`. Attempt của plan là `ProviderPending`.
`payments:expire-stale` chỉ đụng `order_payments` có `expires_at`, không đụng
attempt. **Không có job nào quét `ProviderPending`.**

→ Bật `--execute` (T7.3 round 0) **không cứu gì cho PayPay**. Exit criteria #6
round 0 không chứng minh điều gì.

**Sửa:** cần sweeper riêng cho `ProviderPending` quá hạn, hoặc chuyển attempt
sang `ReconciliationRequired` khi QR hết hạn.

## R14 — Prepare nằm sau feature flag · **MAJOR**

[`shouldRouteStripePrepare()`](../../backend/app/Services/Payment/Orchestration/OrderPaymentOrchestrationCompat.php#L192-L194)
+ [config/payments.php:144-158](../../backend/config/payments.php#L144-L158): với
`PAYMENT_ORCHESTRATOR_TRANSPORT_CUSTOMER_WEB=false`, `prepare*` trả `null` **im lặng**.

Stripe chịu được vì đường ghi sổ của nó key theo intent id, không theo attempt.
PayPay thì webhook khớp bằng attempt → copy nguyên = QR live mà **không có
attempt**, webhook không bao giờ khớp.

**Sửa:** PayPay phải **fail closed** — cờ tắt thì từ chối tạo QR.

## R15 — Credentials là toàn cục, không theo connection · **MINOR**

[`PayPayCredentialsResolver:14-19`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayCredentialsResolver.php#L14-L19)
đọc `config('services.paypay.*')` cho **mọi** connection; chỉ `assumeMerchant`
thay đổi. → "provision per branch" chỉ có nghĩa trong **một** master merchant
PayPay, và webhook secret là **một** cho tất cả.

## R16 — Bẫy chữ hoa/thường khi provision · **MINOR**

`PaymentGatewayCapabilityMapper:104` truyền `approved_currencies` **không chuẩn
hoá**, còn resolver so `in_array('JPY', …, true)` **strict**. Lưu `["jpy"]` là
deny câm. Tương tự `approved_channels` / `approved_device_classes`.

## R17 — Till/shift · **MINOR**

`TillSessionService::gapPreview:400-418` liệt kê **mọi** row succeeded
`till_session_id IS NULL` trong branch, **không lọc channel** → payment PayPay của
khách sẽ hiện trên panel claim lúc mở ca. Đã đúng như vậy với Stripe hôm nay (là
nợ sẵn có, plan chỉ tăng lượng). Nhưng `:2393-2397` map nhóm tender theo
**PaymentMethod code** (`card`/`transfer`/`e_wallet`) — code `paypay` **không rơi
vào nhóm nào** → expected theo nhóm không cân được.

**Sửa:** dùng lại row `e_wallet` đã seed (code `e_wallet`, type `qr`) thay vì tạo
code `paypay` mới, hoặc mở rộng bảng map. Round 0 tự mâu thuẫn ở T2.3.

## R18 — `qr_pay` mang hai nghĩa · **MINOR**

Round 0 đề xuất phân biệt bằng `gateway_option_id` — **không tin được**, cột đó
copy từ `$attempt?->connectionOption?->option_id`, null đúng trong ca mồ côi R7.
Phân biệt bằng `payment_methods.code`.

## R19 — Business time (#1091) vắng mặt · **MINOR**

Đếm ngược/hết hạn của plan là wall clock thuần. Gom nhóm theo ngày và "QR còn mở
lúc chốt ngày kinh doanh" phải đi qua `BusinessClock::forBranch`.

## R20 — `resolveForOrder` có fallback bịa ra Stripe · **MINOR**

`CustomerWebStripeConnectionResolver:42-48` fallback sang
`PaymentGatewayOrchestrationBootstrap::resolveStripeCustomerWebForOrder`, hàm này
**fabricate** provider/option/connection/revision Stripe. Caller PayPay lỡ chạm
vào sẽ bị đóng dấu **option id của Stripe lên payment PayPay**.
→ DESIGN phải ghi rõ `resolveForOrder` **vĩnh viễn chỉ dành cho Stripe**.

## R21 — Alias bằng container binding sẽ TypeError · **MINOR**

`CustomerWebStripeConnectionResolver:25` là `final`; hai caller inject bằng
typehint **class cụ thể**. `bind(Old::class, fn () => app(New::class))` trả về
instance `New`, **không thoả** typehint nào. Chỉ `class_alias()` từ file PSR-4,
hoặc bỏ `final` và cho class cũ extends class mới, mới chạy.

## R22 — Hai lần đánh giá policy mỗi request · **MINOR**

Giữ cả `resolveForBranch` lẫn thêm `resolveAllForBranch` trong cùng controller =
chạy `effectiveOptions()` hai lần, mỗi lần lặp lại ownership lookup + candidate
load + resolve từng option. Suy `gateway_option_id` cũ ra từ **một** kết quả.

---

## Exit criteria (viết lại)

1. Khách thật quét QR sandbox → đơn `paid`, `order_payments` có
   `gateway_connection_id`/`gateway_option_id` đúng. *(bằng chứng vào `evidence/`)*
2. Chứng minh **đường webhook** ghi được sổ — không chỉ poll (R1).
3. Webhook + poll chạy **song song thật** → đúng 1 row (R3).
4. Đơn đã đủ tiền + PayPay COMPLETED → tiền mắc kẹt **được hoàn tự động**, không
   ghi sổ (R2). Đây là ca webhook, không 409 được cho ai.
5. Refund một row `paypay` → **409**, không phải reversal ledger-only (R4/D5).
6. Coupon đổi tổng tiền sau khi mint QR → QR cũ bị vô hiệu, mint được QR mới (R6).
7. Chi nhánh **không** được bật → PayPay `effective=false` kèm reason code; xác
   nhận rõ scope brand-wide (R9) và ghi vào runbook.
8. Chi nhánh currency ≠ JPY → từ chối ở service, không phụ thuộc policy (R10).
9. `PAYMENT_ORCHESTRATOR_TRANSPORT_CUSTOMER_WEB=false` → **từ chối tạo QR**, không
   tạo QR mồ côi (R14).
10. Attempt `ProviderPending` quá hạn có đường thu hồi (R13).
