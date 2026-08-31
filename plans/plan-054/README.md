---
plan: 054
title: PayPay dynamic-QR cho customer-web (khách vãng lai quét trả tiền)
slug: paypay-customer-web-qr
issue: 1898
status: shipped
review_round: 2
branch: ""
created: 2026-07-29
updated: 2026-08-06
parent: plan-048
---

# Plan 054 — PayPay dynamic-QR cho customer-web

Khách đặt trên customer-web chọn PayPay → sinh QR riêng cho đúng đơn, đúng số
tiền → khách quét → tiền về, đơn tự chuyển paid.

~~Hôm nay nút "PayPay" **không gọi PayPay** — chỉ ghi nhãn
`payment_method: "qr_pay"` rồi nhân viên xử lý tay.~~

## Trạng thái 2026-08-06 — 63/65, phần còn lại KHÔNG phải code

Đo lại toàn bộ 65 task trên `dev` (epic #1898). `status` chuyển `draft` →
`shipped`: con số `0/65` trong `TASKS.md` chưa bao giờ phản ánh cây — code vào
repo qua các issue khác mà không ai tick lại. M1 đo ra 5/6, M2 7/7, M4 10/12,
M5 7/8, M6+M8+M9 **19/19**.

Còn hở **đúng hai task, cả hai chặn bởi PayPay chứ không bởi repo**:

| Task | Chặn bởi | Trong repo đã sẵn gì |
|---|---|---|
| **T7.1** đăng ký webhook URL + origin công khai cho `redirectUrl` | PayPay merchant support — không có console self-service | route webhook sống; `redirectUrl` cố ý còn `null` cho tới khi có origin đã đăng ký |
| **T7.2** `PAYPAY_WEBHOOK_SECRET` | PayPay cấp giá trị | config + `.env.example` + verify HMAC đã nối đủ |

Và một điều kiện thoát nằm ngoài mọi task: **chưa ai quét một QR thật end-to-end**
([evidence/01](evidence/01-sandbox-verification.md)).

⚠️ **Refund vẫn KHÔNG hoạt động** — cố ý (D5), có guard 409 chặn, không phải nợ.

`branch` để rỗng (2026-08-06, #1977): `feature/customer-paypay-qr` là **nhánh
ma, chưa từng tồn tại** — `git branch -a --list '*customer-paypay*'` và
`git ls-remote origin 'refs/heads/*customer-paypay*'` đều trả về rỗng. Code land
qua các nhánh `issue-*`.

## Review round 1 đã đổi gì

Draft round 0 **không an toàn để build**. Ba subagent review độc lập đều dừng ở
cùng một chỗ: draft mô tả việc nối PayPay như "dùng lại đường Stripe", nhưng
đường Stripe an toàn nhờ những guard nằm ở **class khác** với class mà draft
định sao chép, và nhờ **một entry point thứ hai** mà PayPay không có.

| # | Round 0 nói | Sự thật |
|---|---|---|
| B1 | "Webhook không phải viết mới" | Đường webhook **không ghi `order_payments`** dòng nào. `finalizeAttempt` chỉ update `payment_attempts`. Stripe sống được vì có **webhook route thứ hai** ghi sổ ([customer.php:146](../../backend/routes/api/customer.php#L146) → [StripePaymentService.php:990](../../backend/app/Services/Customer/StripePaymentService.php#L990)). PayPay chỉ có inbox. → Khách trả tiền, attempt `succeeded`, **đơn vẫn pending, không có đồng nào vào sổ.** |
| B2 | "Sao chép `recordStripeWebhookPayment()`" | Hàm đó là SELECT-rồi-INSERT trần. Row lock, chặn overpay, chặn lệch currency, auto-refund tiền mắc kẹt — **tất cả ở `StripePaymentService::markOrderPaidFromIntent`** ([:892-1018](../../backend/app/Services/Customer/StripePaymentService.php#L892-L1018)). Sao chép đúng như draft = đơn ¥3.000 đã trả cash, khách quét QR → ghi thành ¥6.000, im lặng. |
| B3 | `merchantPaymentId` suy từ order id | Một order = một payment PayPay **vĩnh viễn**. Coupon/thêm món đổi tổng tiền → QR cũ sai số; PayPay từ chối mpid trùng → **đơn không bao giờ trả được bằng PayPay nữa**. Split bill đã tồn tại ([customer.php:114](../../backend/routes/api/customer.php#L114)) và bị bỏ qua hoàn toàn. |
| B4 | Refund "vẫn tham chiếu" | `isStripePayment()` đòi `reference_no` bắt đầu `pi_` ([OrderPaymentService.php:1237-1248](../../backend/app/Services/Customer/OrderPaymentService.php#L1237-L1248)). Row PayPay rơi thẳng xuống nhánh **ledger-only**: sổ báo đã hoàn, **tiền vẫn ở PayPay**, và còn phát 適格返還請求書 (#1123) cho khoản chưa hoàn. |
| B5 | R2 "adapter rẽ nhánh theo capability" | **Không viết được.** `RetrievePaymentCommand` chỉ mang connection + request + payment reference — không có option id, capability hay channel. |
| B6 | "Thiếu 1 trong 3 row → không hiện" | **Sai.** Thiếu `shop_payment_options` → `Inherit`, resolver chỉ deny khi `Disabled`/`Blocked`. **Connection + connection_option là đủ.** Và `loadForBranch` scope theo **org+brand, không theo branch** ([:67-78](../../backend/app/Services/Payment/Policy/Persistence/EloquentPaymentPolicyCandidateLoader.php#L67-L78)) → cấp quyền một lần là **bật cho mọi chi nhánh trong brand**. |
| B7 | 3 row là đủ để PayPay hiện | Thiếu **row thứ 4**: `payment_policy_revisions`. Resolver trả null khi `revision < 1`. Revision chỉ do `publishIfChanged` ghi. Stripe che được nhờ bootstrap fabricate; **PayPay không có fallback**. |
| B8 | R3: bật `--execute` là có backstop | Sweep chỉ quét `state = ReconciliationRequired`; attempt của plan nằm `ProviderPending` → **không bao giờ là candidate**. Bật cờ không cứu gì. |
| B9 | R1: 503 do thiếu `PAYMENT_GATEWAY_KEYRING_PATH` | Sai nguyên nhân. 503 do connection không có `api_secret_ref`. Keyring là yêu cầu của `/rotate`. |
| B10 | `payment-context` trả `options[]` kèm `connection_id` | Endpoint **public, không auth, không throttle**, docblock ghi rõ *"Ids only, never provider/connection detail"* ([CustomerBranchController.php:203](../../backend/app/Http/Controllers/Api/V1/Customer/CustomerBranchController.php#L203)). Presenter còn mang `operator_org_unit_id` + `trace` đầy đủ. |
| B11 | "PayPay không effective → không hiện" | **Sẽ làm biến mất nút PayPay đang chạy được ở MỌI chi nhánh** — vì theo chính audit của plan, hiện **0 chi nhánh** có cấu hình. Ship một regression rồi tự xếp là rủi ro THẤP. → đảo lại: **nâng cấp hành vi, không đổi hiển thị**. |
| B12 | Trỏ `checkout-page.tsx:1314-1330` là "cái radio hardcode" | **Sai block** — đó là dine-in pay-before, gần như không tới được. Có **6 surface** chọn phương thức, round 0 bỏ sót 4. Dine-in thật nằm ở `payment-view.tsx` — đó là câu trả lời cho Q1: **việc riêng, không phải checkbox**. |
| B13 | "sang màn QR" + poll 2s + cần thêm lib QR | Màn QR **đã tồn tại**: `/orders/[id]/pay` đã chống reload, chặn khách khác, chặn đơn đã trả, **đã vẽ QR** (`QRCodeSVG:400`), **đã subscribe realtime**. `qrcode.react` đã là dependency. `useOrderPaidRealtime` có docblock mô tả **đúng** kịch bản này, và backend đã broadcast sẵn → **R4 hạ xuống THẤP**. |
| B14 | `environment = 'sandbox'` cho pilot sandbox | Policy tính `app()->environment('production') ? Live : Test` → phải là **`'test'`**. Chọn `sandbox` = `EnvironmentMismatch` = PayPay **vô hình, không báo lỗi ở đâu cả**. (Connection tôi tạo tay lúc verify đã dính đúng bẫy này.) |
| B15 | Test frontend F-01…F-05 | **Không có hạ tầng component test** — `package.json` chỉ có `node --test 'lib/**/*.test.ts'`, không vitest config, không testing-library, không jsdom. |

Chi tiết + cách sửa: [DESIGN.md](DESIGN.md). Findings chưa đóng: [RISKS.md](RISKS.md).

## Đã verify (sandbox thật) — [evidence/01](evidence/01-sandbox-verification.md)

| | |
|---|---|
| SDK 2.1.0 host + container | ✅ (phải `composer install` **hai lần** — `app-vendor` là named volume) |
| Credentials, có negative control | ✅ đổi 1 ký tự secret → **401** |
| `createQRCode`/`getPaymentDetails`/`deleteQRCode` | ✅ SUCCESS trên `stg-api.paypay.ne.jp` |
| Test suite PayPay | ✅ 19/19 |
| Webhook intake → inbox | ✅ 200, outcome `paypay_no_matching_attempt` |
| Khách quét thật → COMPLETED | ⏳ **chưa** — exit criteria #1 còn nợ |
| connections / connection_options / shop_options / policy_revisions | ❌ 0 / 0 / 0 / 0 |

Sandbox hay live do **cột `environment` của connection** quyết, không do key
([PayPaySdkClientFactory](../../backend/app/Services/Payment/Gateway/PayPay/PayPaySdkClientFactory.php)
chỉ bật production khi `Live`) — khác Stripe, nơi key `pk_test_`/`pk_live_` quyết.
Lệch cặp key/environment → 401, fail closed cả hai chiều.

## Decisions locked

### D1 — Dynamic QR, KHÔNG preauth
Preauth cần `userAuthorizationId`, adapter ném nếu thiếu
([PayPayPaymentGateway.php:252-254](../../backend/app/Services/Payment/Gateway/PayPay/PayPayPaymentGateway.php#L252-L254)).
Khách takeaway là guest, không có bước liên kết tài khoản.
→ capability MỚI `paypay.web_payment.qr.v1`, không sửa cái cũ.

### D2 — Gọi SDK trực tiếp + bắc cầu sang attempt (sửa lại cách diễn đạt round 0)

Round 0 nói "không trở thành caller đầu tiên của `preparePayment()`". Diễn đạt đó
**gây hiểu nhầm**: `PaymentOrchestrator::prepare()` [không bao giờ gọi gateway](../../backend/app/Services/Payment/Orchestration/PaymentOrchestrator.php#L40-L60)
— nó chỉ reserve attempt. **Đoạn glue "orchestrator điều khiển adapter" chưa tồn
tại cho bất kỳ ai.** Nội dung thật của D2 là: *plan này không viết đoạn glue đó.*

Vẫn giữ quyết định, nhưng phải nói rõ mất gì:

1. **Circuit breaker** — [PaymentGatewayRegistry:69-77](../../backend/app/Services/Payment/Gateway/PaymentGatewayRegistry.php#L69-L77) bọc adapter, và breaker **chỉ chặn `preparePayment`**. Gọi SDK thẳng là đúng cái call breaker sinh ra để chặn. (Cờ mặc định OFF — [config/payments.php:258](../../backend/config/payments.php#L258) — nên là mất trong tương lai.)
2. **Idempotency cấp provider** — [`PayPayMutationIdempotency`](../../backend/app/Services/Payment/Gateway/PayPay/PayPayMutationIdempotency.php) key theo connection+operation+idempotency key, ném `IdempotencyPayloadMismatch` khi fingerprint đổi. Plan phải tự dựng lại (xem DESIGN §5).
3. **Capability assertion của adapter** — `assertCapability()`.
4. **Bộ contract test chung** — `PayPayQrCodeClient` không phải `PaymentGatewayContract` nên **không chạy được** `PaymentGatewayContractTestCase`. C-01 round 0 bất khả thi.

Đổi lại: giữ nguyên authority verification + attempt reservation + ledger-net,
vì `prepareStripePaymentIntent` đã đi qua `PaymentOrchestrator::prepare` →
`PaymentAuthorityVerificationPort` (pinned ở [config/domain_mutation.php:31-35](../../backend/config/domain_mutation.php#L31-L35)).

### D3 — ~~Policy tập trung là bắt buộc~~ → **Đi đúng đường Stripe đang đi** (sửa 2026-07-29)

Round 2 chứng minh D3 bản round 0/1 **không thực hiện được**:

[AppServiceProvider:175-180](../../backend/app/Providers/AppServiceProvider.php#L175-L180)
bind `BranchManagementProjectionSource` → `UnavailableBranchManagementProjectionSource`,
class này trả `unresolved` cho **mọi** truy vấn. Lý do: fail closed có chủ ý —
không được suy quyền sở hữu thương nhân từ dữ liệu cục bộ. Nguồn chân lý là
**Platform**; nó đã có cả hai vế (brand→org = sở hữu, branch→org = vận hành)
nhưng chưa công bố vòng đời grant và endpoint đọc (đo 2026-08-06; docblock của
class đó ghi đủ hệ quả và cách tự kiểm).

`PaymentPolicyResolver:52-65` deny ngay **cổng 1**, trước khi chạm dòng dữ liệu nào.
Mọi test policy xanh vì chúng `bind()` fixture trước; production không có adapter.

**Hệ quả đã có sẵn, không do plan này gây ra:**
- Mọi đơn Stripe customer-web chạy qua đường bypass `customer_web_stripe_bootstrap_fallback`
  — chỉ số plan-048 định làm bằng chứng cutover đang **ghim ở 100%**.
- `payment-context` hôm nay trả `policy_revision: null, gateway_option_id: null`
  cho **mọi** chi nhánh trên production.

**Quyết định: PayPay đi cùng đường với Stripe.** Không phát minh đường thứ ba,
không chặn plan này sau một adapter chưa ai deploy.

**Kéo theo, phải nói rõ:**
- Đường tạo payment dùng khuôn `resolveForOrder` (**có** bootstrap fallback,
  [:42-48](../../backend/app/Services/Payment/Orchestration/Internal/CustomerWebStripeConnectionResolver.php#L42-L48)),
  **không** dùng `resolveForBranch` (không có fallback → null trên production).
- `payment-context` cũng phải có fallback, nếu không **nút PayPay không bao giờ
  hiện** — nhưng nhớ D-B11: không hiện ≠ không render, xem §10.2.
- DESIGN §5 bước 4 ("resolve qua resolver, không có → 422") phải viết lại theo
  khuôn này.

**Nợ ghi nhận:** mở issue riêng cho "policy engine chưa resolve được trên
production". Đó là nợ plan-047 T2.5, không phải của plan-054, và nó chặn cả Stripe.

### D4 — Seeder, chưa ship migration production
Theo lý do có sẵn ở [migration:31-36](../../backend/database/seeders/PaymentGatewayCatalogSeeder.php#L31-L36).

### D6 — Dine-in CÓ trong phạm vi (chốt 2026-07-29)

Nhưng dine-in **không đi qua `/checkout`** — nó có màn tiền riêng
[`dine-in/[shop]/table/[qrToken]/components/payment-view.tsx`](../../customer-web/app/[locale]/dine-in/[shop]/table/[qrToken]/components/payment-view.tsx)
(file 106KB, bộ chọn `online|counter` riêng ở `:1724-1745`).
→ Tách thành **M8 riêng**, không nhét vào M6. Backend dùng chung, chỉ khác surface.

### D7 — Hạn QR: hai tầng, ĐƠN là chủ (chốt 2026-07-29)

Probe sandbox thật: PayPay trả `expiryDate` = **tạo + 301 giây (~5 phút)**, và
`CreateQrCodePayload` **không có setter nào** cho hạn QR (chỉ `authorizationExpiry`
dành cho preauth). → **Không thể ép QR sống theo settings của shop.**

`takeaway_payment_timeout_minutes` (shop → brand → 15, hợp lệ 5–120 phút, resolve ở
[`EffectiveOrderPolicyService:145-159`](../../backend/app/Services/Shop/EffectiveOrderPolicyService.php#L145-L159))
là hạn của **đơn**, và nó stamp `payment_due_at`.

```
payment_due_at  ── hạn THẬT của đơn, theo settings shop (5–120 phút)
  ├── QR #1  ~5 phút  ─┐
  ├── QR #2  ~5 phút  ─┼─ hết hạn thì mint mã mới, ĐƠN VẪN SỐNG
  └── QR #3  ~5 phút  ─┘
```

- QR hết hạn mà đơn còn hạn → nút "Làm mới mã", `deleteQRCode` cũ + mint mpid mới.
- Chỉ `payment_due_at` mới kết liễu đơn — đúng theo settings.
- Settings = 5 phút thì hai tầng trùng nhau, vẫn đúng.
- QR mới **không bao giờ** được sống quá `payment_due_at`.

### D8 — Provisioning: bootstrap y như Stripe (chốt 2026-07-29)

Stripe không có UI cấu hình vì nó **không cần** —
[`PaymentGatewayOrchestrationBootstrap`](../../backend/app/Services/Payment/Orchestration/Internal/PaymentGatewayOrchestrationBootstrap.php)
tự sinh provider + catalog option + connection + connection_option + policy
revision, **lười theo từng order**, idempotent, trong một `DB::transaction`.

→ `PayPayCustomerWebBootstrap` sao khuôn. **Round 2 bác bỏ 3/3 "khác biệt" tôi đề
xuất ở lượt đầu** — bản đúng như sau.

#### ① `merchant_account_id` — id tổng hợp per-org, KHÔNG phải id PayPay thật

Lượt đầu tôi định dùng `991602796635988897`. **Sai:** unique là
`(provider_id, environment, merchant_account_id)` — **không có `organization_id`**
([migration:63](../../backend/database/migrations/omnify/2000_01_01_000046_create_payment_gateway_connections_table.php#L63)).
Org thứ hai checkout → hoặc dùng nhầm row của org thứ nhất (→ *"Payment connection
is not active for this tenant"*), hoặc vi phạm unique. **500 tại checkout cho mọi
tenant trừ tenant đầu tiên.**

Stripe né bằng cách nhét orgId vào (`bootstrap:111`) và
[`StripeConnectScope:49`](../../backend/app/Services/Payment/Gateway/Stripe/StripeConnectScope.php#L49)
bỏ qua mọi reference không khớp `^acct_` → id tổng hợp không bao giờ tới Stripe.

→ Dùng `'orchestrator:customer-web:'.$organizationId`, **và thêm guard tương tự
vào `PayPayCredentialsResolver`**: reference bắt đầu bằng `orchestrator:` thì bỏ
qua, rơi về `config('services.paypay.merchant_id')` (nhánh fallback đã có sẵn ở
[:16](../../backend/app/Services/Payment/Gateway/PayPay/PayPayCredentialsResolver.php#L16)).
Credentials PayPay vốn **toàn cục** (một key cho cả deployment) nên merchant id
không thuộc về row per-tenant.

#### ② `environment` — suy từ credential đang cầm, KHÔNG từ `APP_ENV`

Lượt đầu tôi đề xuất `app()->environment('production') ? Live : Test` và nói nó
"giết bẫy B14 by construction". **Ngược.**

`PayPaySdkClientFactory::productionMode()` chọn **endpoint production của PayPay**
từ cột `environment`. Suy nó từ `APP_ENV` nghĩa là deploy production sẽ gọi
**API live của PayPay dù đang giữ key sandbox** — đúng tư thế pilot bình thường.
Stripe suy từ credential đang cầm (`str_contains($secret, '_live_')`), đó mới là
chiều an toàn.

Tệ hơn: "luật policy loader" tôi trích là **dead code** —
`EloquentPaymentPolicyCandidateLoader::resolveEnvironment` gán `$environment` ở
`:52` rồi **không ai đọc**; candidate lấy environment từ `$connection->environment`
(`:107-109`). Không có luật `APP_ENV` nào để khớp cả.

→ Dùng biến tường minh `PAYPAY_ENV` / `services.paypay.production_mode`, **mặc
định sandbox**, và **fail closed** nếu nó nói `live` trong khi `APP_ENV` không
phải production.

#### ③ Revision — TUYỆT ĐỐI không ghi tay

Đây là hỏng nặng nhất. `bootstrap:188-199` ghi `source => 'orchestrator_bootstrap'`,
**không phải** một case của `PaymentPolicyPublicationSource`.
`EloquentPaymentPolicyRevisionPersistence::publishAtomically:60-62` chạy
`storedRevisionIsValid` trên revision mới nhất → `verify()` fail → `scope` thiếu →
`source` không hợp lệ → **`RuntimeException('Stored payment policy snapshot failed
integrity verification.')`**.

→ **Mọi thao tác settings thanh toán trên chi nhánh đó chết vĩnh viễn.** Và
T5.6 (công tắc tắt PayPay cấp shop) gọi `updateShopOption` — đi thẳng vào bẫy.

Thêm: `where('revision', 1)` sẽ trả **revision 1 cũ mèm** trên chi nhánh đã publish
tới revision 5 — không lỗi, không trùng, chỉ âm thầm đóng dấu attempt bằng revision
cổ và bắn drift log mãi mãi.

→ Publish qua `PaymentPolicyRevisionPublisher` với một enum case mới, **hoặc**
bootstrap **no-op khi đã tồn tại bất kỳ revision nào**. Không được hand-write.

#### ④ Concurrency — phải khoá

`bootstrap:26` là `DB::transaction(fn)` với `SELECT … first()` rồi `create()` —
**không `lockForUpdate`, không retry**. Hai khách checkout đầu tiên cùng lúc trên
chi nhánh mới: cả hai cùng trượt, cả hai cùng insert, kẻ thua đụng unique →
`QueryException` → rollback → **500 tại checkout**.

Khuôn đúng có sẵn ngay trong repo: `publishAtomically` dùng `lockForUpdate` +
`transaction(…, 5)`.

→ Bootstrap phải **idempotent theo branch**, có lock + retry, và **tốt nhất là ra
khỏi đường checkout nóng** (chạy lúc provision, không phải lúc khách bấm trả tiền).

#### ⑤ `.env` không phải kill switch (sửa lại tuyên bố lượt đầu)

Cổng env chỉ chặn **việc tạo row lần đầu**. Row tồn tại rồi thì runtime **không
ai đọc lại** `services.paypay.*` — không resolver, không authority port. Gỡ
`PAYPAY_MERCHANT_ID` sau đó **không** ẩn PayPay đi; nó chỉ tạo ra lệnh gọi PayPay
với credential rỗng → 401 → `GatewayAuthenticationFailed`, mà exception này
**không có handler nào đăng ký** → **500 chưa bắt ngay tại checkout của khách**.

→ Muốn env là kill switch thật thì phải kiểm **ở biên resolve/prepare**, không
chỉ ở bootstrap. Và phải đăng ký handler cho `GatewayAuthenticationFailed`.

---

**Lợi thực sự của D8 sau khi sửa:** giết R8 (policy revision) và phần lớn R1
(không cần artisan command, không cần UI để chạy được pilot).
**Không** giết B14 như tôi nói lượt đầu — B14 được xử bằng ② chứ không phải by
construction.

Vẫn còn: `syncConnectionOptions` không sinh được row QR (T1.3) vì nó khớp theo
`$capabilities->id`, mà adapter chỉ trả một capability set hardcode preauth.
Bootstrap tự ghi row nên `/validate` không nằm trên đường tới hạn — nhưng T1.3
vẫn phải làm nếu muốn `/validate` có ý nghĩa với PayPay.

### D9 — Brand bật = bật hết, shop tự tắt được (chốt 2026-07-29)

Đây **đúng là hành vi mong muốn**, không phải lỗi. Round 1 xếp R9 là MAJOR vì
tưởng ngược lại.

- Bật entitlement ở **brand** → mọi chi nhánh trong brand có PayPay.
- Chi nhánh muốn tắt → ghi `shop_payment_options.preference = Disabled`.
- Thiếu row = `Inherit` = theo brand. Đúng ý.

→ R9 **hạ xuống MINOR** (chỉ còn là điều phải ghi rõ trong runbook).
→ Nhưng kéo theo yêu cầu UI: **cần công tắc tắt PayPay ở cấp shop** (M5).

### D10 — POS / chốt ca / workstation NẰM NGOÀI phạm vi (chốt 2026-07-29)

Round 2 phát hiện việc này đang lan sang máy chốt ca, Z-report, settlement và
workstation sync. **Cắt.** Nguyên tắc thay thế:

> **Tiền PayPay customer-web hành xử y hệt tiền Stripe customer-web hôm nay.**
> Không hơn, không kém. Mọi hạn chế của Stripe cũng là hạn chế của PayPay, và
> đó là hạn chế **đã có sẵn**, không phải do plan này sinh ra.

Cụ thể, **chấp nhận và ghi vào tài liệu**, không sửa trong plan này:

| Hạng mục | Hành vi | Giống Stripe? |
|---|---|---|
| `till_session_id` | luôn `NULL` (không ngăn kéo nào thu tiền này) | ✅ y hệt |
| Doanh thu theo ca / Z-report 税率別 | **không** thấy tiền PayPay | ✅ y hệt |
| Dashboard "hôm nay" của shop | **không** thấy | ✅ y hệt |
| Settlement snapshot plan-046 | **không** thấy | ✅ y hệt |
| Workstation | ⚠️ **NGOẠI LỆ — vẫn phải sync**, xem D11 | ❌ không cắt |
| `PosRevenueService::byPaymentMethod` | **có** thấy (hàm này channel-blind) | ✅ y hệt |

**Kéo theo hai việc BẮT BUỘC vẫn phải làm** — đây là *xây tường*, không phải
tích hợp POS:

1. **Loại `channel = customer_web` khỏi `gapPreview`.** Không làm thì tiền PayPay
   hiện trên panel claim lúc mở ca, thu ngân claim vào → dính `till_session_id`
   → rơi vào nhóm tender sai → `close()` **abort 422 `VARIANCE_REASON_REQUIRED`**
   → **thu ngân không đóng được ca**. Một dòng filter, và nó cũng sửa luôn nợ
   sẵn có của Stripe.
2. **`payment_methods` code riêng `paypay`** (không tái dùng `e_wallet`).
   `e_wallet` rơi vào nhóm `emoney` của `reconcile()` — **sai nhóm**;
   `tender_vocabulary.php:31` đã khai `paypay` thuộc `qr`. Vì đã dựng tường ở (1)
   nên code riêng không gây lệch nhóm, mà lại phân biệt được PayPay với 電子マネー
   quẹt tại quầy trong báo cáo.

**Rủi ro đóng lại nhờ D10:** B-A · B-D · M-7 · R17 → chuyển thành *hạn chế đã ghi
nhận*. Không còn là blocker.

**Không đóng được:** M-1 (plan-050 sẽ báo động giả ở T+45 ngày vì không có PayPay
settlement ingest) — vẫn phải xử ở M7, chỉ cần một dòng config.

### D11 — Workstation VẪN PHẢI sync (chốt 2026-07-29) — ngoại lệ của D10

Lý do không cắt được: khách trả PayPay online, thu ngân ở quán **thu tiền lần
nữa** vì máy không biết đơn đã trả. Sync-UP của workstation khi đó đụng
`abort(409, "Order must be in 'checkout' or 'paying' status")`
([OrderPaymentService.php:134](../../backend/app/Services/Customer/OrderPaymentService.php#L134))
→ dead-letter → **tiền mặt trong ngăn kéo, cloud không có dòng nào**. Đây là mất
tiền thật, không phải thiếu báo cáo.

**Backend có thể đã đủ.** `GET /workstation/orders` trả `status`, `total_amount`,
`paid_amount` **vô điều kiện**
([CustomerOrderResourceBase:54,65,66](../../backend/app/Omnify/Modules/CustomerOrder/Resources/CustomerOrderResourceBase.php#L54));
chỉ `payments[]` bị khoá sau `whenLoaded` (`:105`) và không được eager-load.

→ Nhu cầu tối thiểu ("đừng thu lần hai") có thể **không cần feed mới** — chỉ cần
phía Go đọc `paid_amount`/`status` trên vòng pull sẵn có. Cần xác minh, rồi mới
quyết là sửa Go hay phải mở feed payment.

**Cách làm: theo đúng Stripe** — tiền PayPay customer-web đi tới workstation
bằng đúng cơ chế tiền Stripe customer-web đang đi. Không mở feed mới nếu không
phải mở.

### ⚠️ Đính chính: finding "workstation không nhận payment" đọc nhầm bản cũ

Checkout local là `e0b35fb6` (2026-04-13, **chậm 3,5 tháng**) trong khi umbrella
ghi `7a9408a7`. Bản tháng 4 mới chỉ có sync UP. Kết luận round 2 dựa trên nó là
**không chính xác**.

Đọc thẳng `7a9408a7` (không checkout, không đụng working tree — có 3 file sửa tay
của phiên khác: build flag, 3 dep tiptap, đổi endpoint pair) cho thấy workstation
**đã có sẵn** phần lớn thứ D11 cần:

| Thứ | Bằng chứng ở `7a9408a7` |
|---|---|
| Đường customer-web → workstation → pos-web, test end-to-end | `internal/handler/customer_web_order_realtime_test.go` — *"End-to-end coverage for the customer-web → workstation → pos-web path"* |
| Pull đơn từ Cloud | `GET /api/v1/workstation/orders?id=…`, envelope thật |
| **Đọc `paid_amount`** | `customer_order_shape.go:80` `paidAmount := o.PaidAmount` |
| **Phơi số tiền còn lại cho pos-web** | `:96-99` — `"paid_amount"`, `"remaining_amount"` |
| Đối soát payment giữa local và Cloud | `sync_reconcile_payments_test.go`, audit action `payment.rehomed_to_workstation` |
| Hạ tầng pull + poke | `sync_pull.go`, `sync_pull_pos.go`, `sync_poke_reverb_e2e_test.go` |
| Biết `paypay` là tender key nhóm `qr` | snapshot trong `lan_chain_report_realdata_test.go` |

Và phía Cloud, `paid_amount`/`status`/`total_amount` đã nằm trong response
**vô điều kiện** ([CustomerOrderResourceBase:54,65,66](../../backend/app/Omnify/Modules/CustomerOrder/Resources/CustomerOrderResourceBase.php#L54)).
Chỉ `payments[]` bị khoá sau `whenLoaded` — nhưng nhu cầu "đừng thu lần hai"
**không cần** danh sách payment, chỉ cần `paid_amount`.

→ Nhiều khả năng PayPay **hội tụ miễn phí**, y hệt Stripe hôm nay.

### M9 — ĐÃ XÁC MINH: hội tụ miễn phí, chỉ cần test hồi quy

Truy đủ chuỗi, mỗi mắt xích đều đã tồn tại:

```
1. khách trả PayPay → recordPayPayPayment → syncLedgerCacheAndSettleIfPaid
2. → refreshPaymentCache → $order->update(['paid_amount' => …])
        WritesCustomerOrders.php:1017  ← Eloquent update ⇒ BUMP updated_at
3. → workstation tick 5 giây:
        GET /api/v1/workstation/orders?updated_since=<cursor>
        sync_pull.go:527,758 (7a9408a7) — endpoint nhận `updated_since`
        (OrderController.php:210)
4. → orders.paid_amount local được cập nhật
5. → customerOrderShape:80,96-99 phát paid_amount + remaining_amount cho pos-web
6. → thu ngân thấy còn lại 0 ⇒ KHÔNG thu lần hai
```

Docblock của `sync_pull_customer_order_test.go` nói thẳng: *"the workstation only
through the 5 s `GET /workstation/orders?updated_since=`"*.

→ **Không cần feed payment mới, không cần poke, không cần code Go mới.** PayPay
hội tụ y hệt Stripe vì `paid_amount` là cột trên order, không phải quan hệ payment.

**M9 rút gọn còn:**
- [ ] **T9.1** Test hồi quy: đơn customer-web được trả bằng PayPay → trong ≤ 2 chu
      kỳ pull, `orders.paid_amount` local khớp Cloud và `remaining_amount` về 0.
- [ ] **T9.2** Ghi vào runbook: workstation **không** thấy dòng payment PayPay
      (chỉ thấy `paid_amount`) — đúng như với Stripe. Nếu sau này cần chi tiết
      tender trên LAN thì đó là plan khác.
- [ ] **T9.3** Kiểm ca ngược: thu ngân bấm thu tiền mặt **trước** khi tick pull kịp
      → sync-UP đụng 409. Xác nhận nó vào dead-letter **có cảnh báo**, không im lặng.

**Không cần checkout submodule** — mọi thứ trên đọc trực tiếp từ `7a9408a7`.
Working tree của bạn (3 file sửa tay) không bị đụng tới.

### D5 — **Refund bị chặn cứng ở pilot** (chốt 2026-07-29, có ghi chú)
Không có đường hoàn tiền PayPay nào chạy được (B4/B5). Ledger-only reversal là
lựa chọn **duy nhất không chấp nhận được** — nó nói dối sổ sách và phát 赤伝.
→ Row `order_payments` có method code `paypay` bị **409** ở mọi đường refund,
kèm thông báo chỉ staff sang PayPay merchant portal. Wiring thật để plan sau.

## Luồng runtime (sửa theo round 1)

```
1. GET  payment-context                → danh sách option đã whitelist (không lộ connection id)
2. POST orders                         → tạo đơn
3. POST orders/{id}/paypay-qr
     a. khoá đơn, tính amount = total − paid       ← B3
     b. RESERVE ATTEMPT TRƯỚC (attempt sinh mpid)  ← B3/M5: QR mồ côi
     c. createQRCode(mpid, amount)
     d. lỗi ở (c) → huỷ attempt, không để QR sống mồ côi
4. khách quét → PayPay COMPLETED
5. webhook → inbox → applicator → recoverAttempt → **+ ghi sổ (PHẢI VIẾT MỚI)**  ← B1
   HOẶC poll → cùng một hàm ghi sổ, cùng row lock                                ← B2
6. syncLedgerCacheAndSettleIfPaid → đơn paid
```

## Documents

- [DESIGN.md](DESIGN.md) · `TASKS.md` · `TESTS.md` · [RISKS.md](RISKS.md)
- [evidence/01-sandbox-verification.md](evidence/01-sandbox-verification.md)

## Ranh giới — **đã sửa**

Round 0 ghi "plan-047 inbox: dùng lại, **không sửa**". **Sai.** B1 buộc phải sửa
`ProviderEventApplicator` / recovery — một đường **dùng chung với Stripe**. Blast
radius lớn hơn draft nói; xếp vào phần rủi ro cao.

Không đụng kiosk/POS: catalog khai `channels: [customer_web]`. Lưu ý cách diễn
đạt: `effective-payment-options` **vẫn trả** option kèm `effective=false` +
`ChannelUnsupported`, chứ không bỏ khỏi danh sách.

## Câu hỏi — đã chốt hết (2026-07-29)

| | Câu hỏi | Trả lời | Decision |
|---|---|---|---|
| Q1 | Dine-in? | **Có** | D6 — tách M8, vì dine-in có màn tiền riêng |
| Q2 | Hạn QR? | Theo settings expiry của đơn | D7 — hai tầng, PayPay không cho set hạn QR |
| Q3 | Đường cấu hình? | **Làm như Stripe** | D8 — bootstrap tự sinh, env là công tắc |
| Q4 | Phạm vi bật? | Brand bật hết, shop tự tắt được | D9 — đúng hành vi hiện tại; cần UI tắt cấp shop |
| Q5 | Không refund được? | **Được, nhưng phải ghi chú** | D5 — ghi vào runbook + cảnh báo đỏ trong admin |

**Ghi chú bắt buộc cho Q5** (đưa vào runbook M7 và Settings admin):

> PayPay ở giai đoạn pilot **không hoàn tiền được qua hệ thống**. Mọi yêu cầu
> hoàn tiền phải thao tác tay trên PayPay merchant portal, và **ghi lại thủ công**
> — sổ trong TempoFast sẽ **không** tự phản ánh khoản hoàn đó. Hệ thống trả 409
> khi staff bấm hoàn tiền trên đơn PayPay, đây là **cố ý**, không phải lỗi.

Không còn câu hỏi chặn nào. Round 2 sẽ review lại bản đã sửa theo D5–D9.
