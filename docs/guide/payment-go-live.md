---
title: Bật thanh toán trên production — Stripe, PayPay, workstation
category: guide
tags: [payments, stripe, paypay, production, go-live, runbook]
summary: Việc thật để nhận tiền trên prod là đổ key vào .env rồi deploy — không có thang rollout nào cả, vì orchestrator đã mặc định BẬT. Kèm cách tự kiểm từng bước, đường lùi, và quy trình soak workstation khi có máy thật.
related: [payments-overview, payment-gateway-paypay-certification, payment-topology-and-tender-model]
---

# Bật thanh toán trên production

Tài liệu này thay cho bốn issue rollout (#1115 #1116 #1117 #1118 #1119). Chúng
mô tả một **thang bật từng bậc** — và cái thang đó đã hết tác dụng: mặc định
trong code lật sang BẬT từ 2026-07-23, nên orchestrator lên production ngay từ
lần deploy 24/07, trước khi cổng nào kịp chạy.

Đọc mục 1 trước, nếu không sẽ đi bật một thứ vốn đã bật.

## 1. Cái gì đã BẬT SẴN — đừng đi tìm cờ để bật

`backend/config/payments.php`:

```php
'enabled' => (bool) env('PAYMENT_ORCHESTRATOR_RUNTIME', true),          // mặc định TRUE
'pos' | 'kiosk' | 'workstation' | 'customer_web' | 'self_regi'
    => env('PAYMENT_ORCHESTRATOR_TRANSPORT_*', true),                   // TRUE cả 5
```

`.env.example` cũng ship `=true`, và `deploy-xserver.yml` **không ghi key payment
nào** vào `.env` trên server (chỉ ép `SSO_DEV_BYPASS=false`). Nên `.env` của
server giữ nguyên qua mọi lần deploy.

⇒ **Không có bước "bật transport".** Việc duy nhất còn lại là đưa credentials
của nhà cung cấp vào, và deploy.

## 2. Stripe

### Cần gì

Vào `~/apps/tempo/.env` trên XServer:

```
STRIPE_KEY=pk_live_…
STRIPE_SECRET=sk_live_…
STRIPE_WEBHOOK_SECRET=whsec_…       # từ webhook endpoint prod trong Stripe Dashboard
STRIPE_ACCOUNT_ID=acct_…            # tài khoản mà STRIPE_SECRET xác thực thành (#2893)
STRIPE_CURRENCY=jpy
STRIPE_LIVE_REFUNDS_ENABLED=false   # bật khi muốn hoàn tiền THẬT đi qua
```

**`STRIPE_ACCOUNT_ID` quyết định TIỀN QUY VỀ AI** (#2893). Sự kiện webhook của
tài khoản nền (không mang trường `account`) được quy về hàng
`payment_gateway_connections` mang đúng `acct_…` đó; thiếu nó thì mọi bản ghi
settlement rơi vào hàng connection tổng hợp đã ngưng dùng — thuộc một tổ chức
không có thành viên nào — và chủ sở hữu mở màn hình đối soát ra thấy **rỗng**.
Đó chính là lỗi #2893: 747 hàng, ¥939.235. Cùng giá trị ấy còn là phép phân
biệt "tài khoản của ta" với "tài khoản kết nối", nên sai nó thì mọi lượt gọi
Stripe của connection đó bị kèm header `Stripe-Account` sai.

Lấy giá trị bằng `stripe accounts retrieve` (hoặc góc trên Dashboard). Sau khi
đặt, chuyển quy thuộc bản ghi cũ MỘT LẦN:

```sh
php artisan payments:migrate-stripe-attribution              # dry-run, in trước/sau
php artisan payments:migrate-stripe-attribution --apply
```

Thứ tự bắt buộc: **deploy mã trước, chạy lệnh sau** — ngược lại thì hàng mới
vẫn rơi vào chỗ cũ trong khoảng giữa.

**Không cần `PAYMENT_GATEWAY_KEYRING_PATH`.**
`LegacyGlobalStripeConnection:140` đọc thẳng `config('services.stripe.secret')`,
nên key trong env là đủ để customer-web nhận thẻ. Keyring chỉ cần khi muốn nhiều
merchant account tách biệt theo shop.

### Tự kiểm — một lệnh, không cần đăng nhập

```sh
curl -s https://tempo-prod.godx.jp/api/v1/customer/stripe/config
```

`publishable_key` **rỗng nghĩa là Stripe chưa cấu hình** và customer-web **không
thanh toán thẻ được**. `StripeConfigController` trả thẳng
`config('services.stripe.key')`, nên đây là phép đo trực tiếp chứ không phải suy
đoán. (Đo 2026-08-05: rỗng.)

### Kiểm ở local trước bằng Stripe CLI

```sh
stripe config --list          # lấy test_mode_api_key / test_mode_pub_key
stripe listen --print-secret  # whsec_… cho STRIPE_WEBHOOK_SECRET
```

Đổ vào `backend/.env` (file này **gitignore**, đừng commit), rồi:

```sh
cd backend
vendor/bin/pest --compact tests/Unit/Services/Payment/StripePaymentGatewayContractTest.php \
                          tests/Unit/Services/Payment/InMemoryPaymentGatewayContractTest.php
```

Đã chạy 2026-08-05: **20 passed / 506 assertions**, và vòng đời thật
`PaymentIntent::create → retrieve → cancel` (1000 JPY, test mode) chạy đúng.

### Backfill có cần không — KHÔNG, và không còn lệnh nào

`payments:backfill-gateway-identities` cùng runbook riêng của nó bị **xoá ngày
2026-08-08** theo ruling #2188 (legacy không tồn tại: dữ liệu cũ reseed một lần
rồi thôi, không vá lúc chạy). **Đừng đi tìm, đừng dựng lại.**

Từ đây trở đi mọi dòng `order_payments` được đóng dấu gateway ngay lúc ghi, nên
không có "dòng cũ chưa map" để dọn. Muốn biết một môi trường có sạch không thì
đo bằng thứ còn tồn tại: `php artisan payments:legacy-removal-readiness` (mục §8
bên dưới dùng chính nó làm cổng).

## 3. PayPay

PayPay là **provider độc lập**, không phải payment method của Stripe. Chi tiết
kiến trúc + cách test: skill `.claude/skills/paypay-test/SKILL.md` (có sẵn
credentials sandbox và 3 test user).

### Sandbox đã được chứng minh chạy

Đo 2026-08-05, không phải mock:

| Kiểm | Kết quả |
|---|---|
| `createQRCode` tới `stg-api.paypay.ne.jp` | `resultInfo.code = SUCCESS`, QR thật `qr-stg.sandbox.paypay.ne.jp/…` |
| `deleteQRCode` | `SUCCESS` |
| Webhook có header chữ ký | **200** |
| Webhook **không** header | **400** — fail-closed đúng thiết kế |
| Webhook qua tunnel public | **200** |
| 2 POST payload giống hệt | **1 row** `payment_provider_events` — dedup |
| POST payload khác | lên **2 row** — chứng minh 1 row trên là dedup, không phải mất request |
| pest adapter+contract+certification | **19 passed / 277 assertions** |

Row đầu: `state=queued`, `type=paypay.payment.notification`. **2xx ≠ đã xử lý** —
inbox là async, row nằm `queued` tới khi queue worker chạy.

### Còn thiếu gì để gọi là "đã chứng nhận"

Một lần **quét QR thật** bằng app PayPay ở chế độ sandbox (3 test user trong
skill) để đi hết vòng đời. Vòng **preauth** production dùng
(`createPaymentAuth` → `capturePaymentAuth` → `revertAuth`) cần
user-authorization token, nên không tự động hoá được — phải có người cầm điện
thoại. Khoảng 10 phút.

### Lên live

Sandbox hay live **do cột `environment` của connection quyết định**, không phải
do env var: `PayPaySdkClientFactory::productionMode()` chỉ vào production khi
`environment = Live`. Đổi key trong `.env` sang bộ live **và** tạo connection
`environment = live`.

```
PAYPAY_API_KEY=…  PAYPAY_API_SECRET=…  PAYPAY_MERCHANT_ID=…
PAYPAY_ENVIRONMENT=live
# PAYPAY_WEBHOOK_SECRET=…   # optional — chỉ cho HMAC simulation;
# Live OPA dùng IP allowlist (notification_type). Bắt buộc đăng ký URL:
# https://tempo.godx.jp/api/v1/webhooks/payment/paypay  (PayPay contact form)
```

⚠️ Live OPA webhook **không** cần secret nếu payload có `notification_type` và
IP nguồn nằm trong allowlist PayPay. Payload live không có `notification_type`
mà cũng không HMAC thì **fail closed** (#1107). **URL webhook Live phải đăng ký
qua PayPay contact form** — không tự cấu hình được trên Developer portal
(xem `docs/guide/paypay-customer-web-qr.md`). Cho đến khi PayPay xác nhận,
`payments:sweep-paypay-qr` chạy mỗi phút để book COMPLETED (#2445).

## 4. Deploy — điều kiện tiên quyết thật sự

Tính đến 2026-08-05 production còn ở tag `v2026.7.24.1`, **thiếu 637 commit
backend**. Đổ key mà không deploy thì vẫn chạy code cũ.

```sh
gh workflow run deploy-xserver.yml --ref dev     # KHÔNG tick reset_database
```

Workflow đã tự chạy `migrations:realign-history` (#1217) trước `migrate` và
`schema:heal-create-drift` (#1769) sau — hai lệch schema *im lặng* mà `migrate`
một mình không xử lý được. Lần chạy đầu nên đọc log hai khối đó: dry-run in ra
đúng những gì đang lệch.

## 5. Đường lùi và van an toàn

Cả hai là **một dòng env**, không phải một dự án:

```
PAYMENT_ORCHESTRATOR_RUNTIME=false      # quay về ghi ledger trực tiếp
PAYMENT_ORCHESTRATOR_TRANSPORT_POS=false        # hoặc hạ từng transport
PAYMENTS_CIRCUIT_BREAKER_ENABLED=true   # van ngắt khi provider lỗi liên tiếp
PAYMENT_POLICY_ENFORCEMENT_REQUIRED=false       # ⚠️ ĐỌC MỤC 9 TRƯỚC KHI BẬT
```

Circuit breaker (#1105, đã đóng) mặc định **TẮT**. Ngưỡng đã chốt, không chờ gì
nữa: `failure_threshold=5` · `failure_window=120s` · `cooldown=60s` ·
`probe_ttl=30s`. Quan sát sự kiện `payment_provider_circuit_opened` trên log
channel `payment_orchestration`.

## 6. Quan sát

```sh
php artisan payments:observation-report            # bảng người đọc + JSON
php artisan payments:observation-report --strict   # exit≠0 để gate cron/CI
```

Gộp ledger drift, backlog shadow-compare, refund pending, till session đang mở.
Đây là việc trông nom thường xuyên, không phải một task có điểm kết thúc.

## 7. Soak workstation offline — khi nào có máy thật

Phần này **không mô phỏng được bằng CI**, và cũng không cần chặn việc bật thanh
toán cloud. Làm khi có một máy workstation thật:

1. Bán vài đơn bình thường, xác nhận sync-UP sạch.
2. **Rút mạng** máy workstation. Tiếp tục bán, thanh toán, in phiếu ≥ 24h (đủ
   qua một lần đổi ngày kinh doanh).
3. Cắm mạng lại. Kiểm: không đơn nào nhân đôi, không payment nào nhân đôi,
   attribution ca thu ngân (`order_payments.till_session_id`) vẫn đúng.
4. Đối chiếu Z-report / 精算 của ca đó với tiền mặt thật trong két.

Phần "zero duplicate payment" **đã được ghim bằng test tự động**
(`Plan048WorkstationReplayMatrixTest`, `OfflineReplayEvidenceTest`) — cái soak
thật thêm vào là những thứ test không dựng được: pin/mạng rớt giữa giao dịch,
đồng hồ trôi, hàng đợi sync tích tụ qua một đêm, máy in và thiết bị ngoại vi
cùng lúc.

## 8. Nợ phải xoá — có mốc, đừng xoá sớm

Ba mảnh legacy còn sống **có chủ đích**. Chúng thay cho #1087 · #1098 · #1120 —
những issue đó bị đóng vì thứ cần giữ là **điều kiện xoá**, không phải cái thread.

Đừng xoá cái nào chỉ vì thấy nó "legacy": mỗi dòng dưới đây có một điều kiện
chưa thoả, và cả hai đều **hỏng theo kiểu im lặng** nếu xoá sớm.

> **`PaymentStatusCompatibility` ĐÃ XOÁ (#1822, 2026-08-05).** Điều kiện của nó
> là hai vế, và vế thứ hai — *"không còn workstation build cũ trong fleet"* —
> hoá ra không có gì để bảo vệ: chủ repo xác nhận chưa có bản phát hành nào,
> chưa giao quán nào. Phần **hợp đồng poll** của class (`forKioskPoll` /
> `forWorkstationPoll`) không phải legacy và đã tách sang
> `App\Support\PaymentPollStatus`; chỉ phần dịch chuỗi `'confirmed'` bị bỏ.
>
> Cổng `payment_status_compatibility` trong `payments:legacy-removal-readiness`
> **giữ lại làm ratchet**: `code_present` quay lại `true` nghĩa là ai đó thêm
> lại lớp tương thích.

| Mảnh | Điều kiện xoá | Xoá sớm thì sao |
|---|---|---|
| `LegacyGlobalStripeConnection` (14 file) + `StripeCanonicalPaymentMethodProvisioner` (5) | customer-web Stripe đã chạy qua connection thật (backfill xong, xem mục 2) | Đây hiện là đường Stripe **DUY NHẤT** của customer-web ⇒ **gỡ luôn khả năng nhận thẻ** |
| Route `payment-methods` deprecated (POS · shop · HQ CRUD) | **2027-01-01** — mốc đã CÔNG BỐ cho client qua header RFC 8594 | Phá hợp đồng API đã hứa; client còn hạn dùng sẽ 404 |
| ~~`LegacyPaymentMethodResolver`~~ | ✅ **ĐÃ XOÁ (#1887)** — trùng lặp thuần với enricher | — |
| **Đường tra method theo chuỗi mã** (`resolveMethodByCode`, 2 call site: kiosk · workstation) | Client gửi effective-option id thay vì chuỗi mã — tức plan-055 Gate 6 đã bắt buộc | Kiosk/workstation đang chạy gửi chuỗi mã sẽ 422 ⇒ **quầy không thu được tiền** |

**Xoá class ≠ di trú client.** Cổng `legacy_payment_method_resolver` báo
`already removed` từ #1887, và điều đó chỉ có nghĩa là CLASS không còn. Kiosk
và workstation vẫn gửi `payment_method` dạng chuỗi mã; nợ đó đo ở cổng
`legacy_payment_method_code_path` và cổng ấy vẫn mở. Đừng đọc cổng đầu thành
"xong" — chính tôi đã đọc nhầm như thế và tick sai một task vì nó.

**Vì sao resolver xoá được trước mốc 2027 mà route thì không.** Hai thứ này
từng nằm chung một hàng và làm ra ấn tượng sai rằng cả hai bị khoá bởi cùng một
lời hứa. Không phải: mốc 2027-01-01 ràng buộc **hợp đồng API đã công bố** — URL
còn phải trả lời. `LegacyPaymentMethodResolver` chỉ là chi tiết cài đặt phía sau
URL đó, và nó chạy truy vấn TƯƠNG ĐƯƠNG với
`PosEffectivePaymentOptionEnricher::resolveMethodByCode()`. Chuyển hai controller
sang enricher rồi xoá class ⇒ route vẫn trả lời y như cũ, không client nào thấy
khác biệt. Nói cách khác: xoá TRÙNG LẶP không cần chờ mốc; xoá **đường vào** thì
cần.

### Cách kiểm điều kiện, không phải cách đoán

**Drain `confirmed`** — vẫn đáng đo sau khi shim đã xoá (#1822): một hàng
`confirmed` xuất hiện lại lúc này nghĩa là còn đường ghi chưa chuẩn hoá, và câu
trả lời là đi tìm nó chứ không phải thêm lại lớp dịch.

```sql
SELECT COUNT(*) FROM order_payments WHERE status = 'confirmed';
```

Phải là `0` và giữ `0`. Cổng readiness đo đúng con số này.

Phía workstation đã ngừng ghi `confirmed` từ migration
`internal/store/migrations/058_payment_status_succeeded.sql` (`451a8c0`, #1120).
Câu này trước đây kết luận *"việc còn lại thuần là deploy fleet rồi đợi"* — **sai
một bước**, và bước bị bỏ qua là bước chưa tồn tại (#1824):

```
GitHub Release duy nhất : v0.0.1   2026-07-23
451a8c0 (058)           :          2026-07-27
451a8c0 ⊂ v0.0.1        : KHÔNG          ← chưa release nào mang bản sửa
Cơ chế tự cập nhật      : không có
```

`main` **không tự tới máy quán**. Nên trình tự thật có ba bước, không phải một:

1. **Cắt release** `workstation-app` chứa `451a8c0` — chưa có bước này thì hai
   bước sau vô nghĩa;
2. **Đưa release tới từng quán** — việc tay, vì không có auto-update;
3. **Rồi mới** bấm đồng hồ và đợi `confirmed` về 0 và giữ 0.

⚠️ Và trước khi tin con số `0`: **nó được đo trên database nào?** Nếu chưa quán
nào chạy build có `058` thì quán nào còn giao dịch vẫn đang ghi `confirmed` —
một số `0` lúc đó nhiều khả năng nói rằng phép đo chạy trên DB không phải
production, chứ không nói fleet đã sạch. Con số đúng trên sai database vẫn là
con số sai. Theo dõi ở #1822.

**Mốc sunset** khai một chỗ duy nhất:
`app/Http/Support/DeprecatedApiHeaders.php:16` →
`PAYMENT_METHODS_SUNSET = 'Fri, 01 Jan 2027 00:00:00 GMT'`. (Trang này từng
trích `Sat` — đúng cái giá trị đã bị sửa ở `a933bf1` **vì sai**: 2027-01-01 là
thứ **Sáu**, và RFC 7231 HTTP-date mang thứ dư thừa so với ngày, nên cặp lệch
khiến parser dễ tính "sửa" bằng cách dời ngày — Carbon đọc chuỗi `Sat` ra
**2027-01-02**, tặng client thêm một ngày của hợp đồng đã công bố. Đừng copy
lại giá trị cũ từ bất kỳ bản doc nào.) Đổi mốc thì đổi ở
đó, và nhớ rằng nó **đã được gửi ra ngoài** trong header `Sunset` của mọi
response `payment-methods`.

### Một mảnh đã xoá rồi — đừng đi tìm

`LegacyStripeOrchestrationBootstrap` **không còn tồn tại** (0 file trên `dev`,
biến mất ở `ddcacb3f8`). Các bảng kiểm kê cũ của plan-047 vẫn liệt kê nó như còn sống (`TASKS.md` đã xoá
ở #2336 vì chính loại sai lệch này); người audit tiếp theo sẽ mất thời gian đi
tìm một class không có. Đối chiếu bằng `git grep -l` trước khi tin bất kỳ bảng nào.


## 9. `PAYMENT_POLICY_ENFORCEMENT_REQUIRED` — cờ CHƯA ĐƯỢC BẬT

**Mặc định `false`, và phải giữ `false` cho tới khi ba điều kiện dưới đây thoả.**
Đây không phải cờ tinh chỉnh; bật sai lúc là **từ chối tiền đã nằm trong két**.

Cờ này quyết định chuyện gì xảy ra khi một payment **không nêu**
`gateway_option_id`:

| | Hành vi |
|---|---|
| `false` (nay) | cho qua, ghi `payment_policy_option_missing` (transport · device · branch · org) trên log channel `payment_orchestration` |
| `true` | từ chối **422 `POLICY_OPTION_REQUIRED`**, `action: refresh_payment_options`, **không ghi dòng ledger nào** |

Nhánh `false` không phải chỗ giữ chỗ — nó **là phép đo**: tập log đó chính là
danh sách chính xác client nào sẽ chết khi bật, thay cho ước lượng.

**Cờ này có HAI nghĩa, không phải một (T3.4, #1834).** Ngoài "payment không nêu
option id", nó còn quyết định điều gì xảy ra khi một payment CÓ nêu option id
nhưng option đó trượt policy — **và định danh ấy chỉ tới nơi nhờ lớp alias tên
cũ**. Nhóm đó ghi `payment_policy_alias_would_refuse` (kèm cả `gateway_option_id`
và `reason`), và cũng được cho qua cho tới khi cờ bật.

Đây là **cả một hạm đội**: mọi workstation và kiosk đang chạy đều nằm trong nhóm
này, không nằm trong nhóm `payment_policy_option_missing`.

### Ba điều kiện, tất cả đo được

1. **Mọi branch active có policy revision** — `payments:legacy-removal-readiness`,
   precondition `policy_revision_coverage`.
2. **Và mỗi branch có ≥1 effective option.** Vế này dễ quên: coverage `9/9` với
   0 option effective vẫn từ chối mọi checkout. Chính lệnh trên đo cả vế này —
   `policy_revision_coverage` in `"… N of those have 0 effective option"`, và
   `--json` trả `branches_without_effective_option` (kèm lý do từng branch). Đó
   là danh sách phải xử lý.
3. **CẢ HAI log CHẶN đã rỗng** qua một cửa sổ đủ dài để phủ chu kỳ phát hành
   chậm nhất (workstation):
   - `payment_policy_option_missing` — client không gửi option id
   - `payment_policy_alias_would_refuse` — client CÓ gửi (qua tên cũ) nhưng
     trượt policy

   **Chỉ đọc vế đầu là đọc một con số đã xanh sẵn.** Từ #1834, hạm đội legacy
   chuyển hết sang dòng thứ hai; nếu chỉ nhìn `payment_policy_option_missing`
   thì nó rỗng trong khi **mọi** workstation và kiosk vẫn đang trượt policy —
   bật cờ lúc đó là từ chối tiền ở quầy. Đúng cái bẫy "tổng che thực tế" mà
   README của plan-055 đã chỉ ra, chỉ là ở một trục khác.

   **Có dòng log thứ BA, và nó KHÔNG phải điều kiện chặn (#1831).** Internal
   tender — tiền mặt · máy thẻ rời · 掛売 — được miễn kiểm policy **theo thiết
   kế**, và ghi `payment_policy_internal_tender_exempt` (mức `info`, không phải
   `warning`). Nó **không bao giờ** phải rỗng: đòi nó rỗng là đòi shop ngừng
   nhận tiền mặt. Lý do miễn là sai phạm trù chứ không phải nới lỏng — resolver
   fail-closed không bao giờ surface được một option không có connection, nên
   tiền mặt **không thể** có gateway identity để mà kiểm.

   **Vì sao điều kiện 3 mới trở nên khả thi.** Trước #1831 mọi giao dịch tiền
   mặt đều rơi vào `payment_policy_option_missing`, nên vế đầu **không thể**
   rỗng chừng nào quán còn bán tiền mặt — điều kiện ra viết ra ở đây là bất khả
   thi mà không ai thấy. #1831 tách nhóm ấy sang dòng thứ ba; từ đó hai log chặn
   rỗng mới có nghĩa là "mọi client ĐI QUA GATEWAY đều đã gửi option id hợp lệ".

### ⛔ Và một chặn chưa gỡ

Ranh giới **đơn offline replay** (plan-055 T5.1) chưa chốt. Cloud hiện **không
phân biệt được** payment bán offline hôm qua sync hôm nay với payment bán online
vừa xong — cả hai đến `POST /workstation/payments` không mang marker nào.

Bật cờ trước khi giải quyết xong = một máy workstation mất mạng cả ngày, bán
bình thường, rồi sync lên thì **toàn bộ số tiền đó bị từ chối**. Tiền không quay
lại được; nó chỉ để lại đơn mồ côi và một ca thu ngân không khớp.

Chi tiết + ba đường xử lý: `plans/plan-055/NOTES.md`.

**Đã có bản vá một phần, và nó MỞ RỘNG ranh giới này (T5.1 + T3.4).** Cloud tự
đóng dấu `customer_orders.offline_replayed_at` trong `insertOfflineReplay()` sau
`assertTrusted()` — thiết bị không đặt được — và payment trên đơn mang dấu đó
được miễn kiểm policy.

Miễn trừ ấy **cố ý không phân biệt transport**: nó chạy trước cả rào alias, nên
một payment pos-web đi **thẳng lên Cloud** trên đơn đã đóng dấu cũng được miễn —
đường đó vốn cưỡng chế cứng. Hẹp và không client nào với tới được, nhưng nó là
**mở rộng** so với hành vi cũ, không phải giữ nguyên. Ca thực tế: đơn replay còn
dư nợ do trả một phần / tách bill, phần còn lại thu ở quầy sau đó.

Dấu ở cấp ĐƠN là phép xấp xỉ cho một sự thật ở cấp PAYMENT — chính vì Cloud
không phân biệt được ở cấp payment như đoạn trên nói. Ghi ra đây để khi chốt
ranh giới thật thì biết cần thu hẹp chỗ nào.
