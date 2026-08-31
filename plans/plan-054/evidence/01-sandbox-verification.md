# Evidence 01 — PayPay sandbox verification (2026-07-29)

Chạy từ container `tempo-app`, connection sandbox, merchant `991602796635988897`.
Không có tiền thật di chuyển.

## SDK

```
host:      backend/vendor/godx-jp/paypayopa-php-sdk 2.1.0        ✅
container: /var/www/html/vendor/godx-jp/paypayopa-php-sdk 2.1.0  ✅
class_exists(PayPay\OpenPaymentAPI\Client) = true (cả 2)
```

Lưu ý: `docker-compose.yml:16` mount `app-vendor` là **named volume**, nên
`composer install` phải chạy **hai lần** (host cho pest native, container cho
artisan/HTTP). Cài một nơi thì nơi kia vẫn báo thiếu class.

## Test suite

```
tests/Unit/Services/Payment/PayPayPaymentGatewayAdapterTest.php
tests/Unit/Services/Payment/PayPayPaymentGatewayContractTest.php
tests/Unit/Services/Payment/PayPayPaymentGatewayCertificationTest.php

Tests: 19 passed (277 assertions)   Duration: 0.35s
```

Trước khi cài SDK: 18 passed / 1 failed — `test_composer_installs_godx_paypay_sdk`
(`…AdapterTest.php:43`).

## Credentials — có negative control

```
[KEY ĐÚNG]  createQRCode : {"code":"SUCCESS","message":"Success","codeId":"08100001"}
[KEY SAI]   createQRCode : ClientControllerException code=401
```

Chỉ đổi `PAYPAY_API_SECRET` thành chuỗi sai → PayPay trả **401**. Nên các
`SUCCESS` ở đây là chữ ký HMAC hợp lệ thật, không phải sandbox nhận bừa.

## Vòng đời QR trên `stg-api.paypay.ne.jp`

| Thao tác | Kết quả |
|---|---|
| `code->createQRCode` (¥100, ORDER_QR) | `SUCCESS`, `url=https://qr-stg.sandbox.paypay.ne.jp/…`, `codeId=04-…` |
| `code->getPaymentDetails(mpid)` | `SUCCESS`, `status=CREATED` (chưa quét) |
| `code->deleteQRCode(codeId)` | `SUCCESS` |

### Hạn QR — đo thật, không cấu hình được

```
mpid       probe-1785313151
data keys  ["codeId","url","expiryDate","merchantPaymentId","amount","codeType","isAuthorization","deeplink"]
expiryDate 1785313452
→ 1785313452 − 1785313151 = 301 giây ≈ 5 phút, do PayPay tự đặt
```

`CreateQrCodePayload` chỉ có setter cho `codeType` · `storeInfo` · `redirectUrl` ·
`redirectType` · `userAgent` · `isAuthorization` · `authorizationExpiry`
(`vendor/godx-jp/paypayopa-php-sdk/src/Models/CreateQrCodePayload.php:65-209`).
**Không có setter nào cho hạn QR** — `authorizationExpiry` chỉ dùng cho preauth.

→ Không thể ép QR sống theo `takeaway_payment_timeout_minutes` của shop (5–120 phút).
Đây là cơ sở của D7.

QR đã tạo trong lúc verify: `e2e-1785308612` (¥500), `verify-1785310390` (¥100),
`tempo-1785310878` (¥100) — tất cả `CREATED`, **chưa có ai quét**. Chưa chứng
minh được đường tiền thật COMPLETED → đó là exit criteria #1 của plan, còn nợ.

## Webhook intake

```
POST http://localhost:5400/api/v1/webhooks/payment/paypay
  không header signature                      → 400
  + header PayPay-Signature: sandbox-unsigned → 200 {"received":true}
```

Resolver tự khớp connection qua `merchant_id` trong payload (không cần
`?connection=`). Queue worker xử lý ra:

```
event_type : paypay.payment.notification
state      : succeeded
outcome     : paypay_no_matching_attempt
```

`paypay_no_matching_attempt` là **đúng** ở thời điểm này — chưa có
`PaymentAttempt` nào để khớp. Sau plan-054 outcome phải là
`orchestrator_paypay_attempt_recovered` (TESTS, exit criteria #4).

## Trạng thái dữ liệu policy

```
payment_gateway_providers          3 rows  (internal, paypay, stripe)
payment_gateway_options            4 rows  (có paypay.preauth.wallet.v1)
payment_gateway_connections        0 rows
payment_gateway_connection_options 0 rows
shop_payment_options               0 rows
```

Connection sandbox tôi tạo tay trong lúc verify (`019facae-…`) đã **mất** sau
một lần DB bị reseed giữa phiên (provider id đổi từ `019fac0c-…` sang
`019facb8-…`). Đây chính là R1: không có đường nhập cấu hình bền vững.

## Chưa verify được

- Khách quét thật → `COMPLETED` → tiền về.
- Đường webhook khớp attempt (cần có attempt trước).
- Refund một payment tạo qua `code->` (xem R2 — endpoint `/v2/codes/…` khác
  `/v2/payments/…`).
