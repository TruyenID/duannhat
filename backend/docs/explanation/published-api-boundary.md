---
title: "\"API công bố\" nghĩa là gì (#1583)"
category: explanation
tags: [architecture, boundaries, deptrac, modular-monolith, ordering]
summary: Quyết định định nghĩa ranh giới cross-module cho epic #962 — API công bố là một tầng ĐO ĐƯỢC, không phải một quy ước đặt tên.
related: [money-ledger-architecture, architecture]
---

# "API công bố" nghĩa là gì

ADR 0001 § 2 viết *"cross-module access only through a published API"*. Câu đó
đứng vững cho tới khi có người hỏi **published API là cái gì, đo bằng gì** — và
#1583 là lúc câu hỏi đó chặn việc thật.

## Câu hỏi ban đầu, và vì sao nó hết hiệu lực

#1583 hỏi: *dựng một `MutationContext` có tính là "đi qua API công bố" không, khi
`OrderMutationContextFactory` nằm trong `App\Services\Order\Internal`?* Rồi nêu
hai hướng: công bố một factory (rẻ, "đổi tên chỗ đau"), hay bắt 20+ command nhận
primitive để Ordering tự dựng context (đắt).

Đo lại trước khi chọn — hai sự thật làm câu hỏi tan:

1. **`MutationContext` không phải khái niệm nội bộ của Ordering.** Nó nằm ở
   `App\Services\DomainMutation`, vốn đã khai là `shared` trong
   `config/modules.php`. Mọi module dùng nó là hợp lệ, không sinh một cạnh nào.
   Tiền đề của issue sai ngay ở đây.

2. **Không còn cạnh nào trỏ vào `OrderMutationContextFactory`.** Năm cạnh
   `CouponService → factory` tan khi #1581 dời nửa đơn hàng của `CouponService`
   sang `OrderCouponService` (Ordering). Mọi caller còn lại là controller — tầng
   `Composition`, vốn được phép phụ thuộc mọi module theo thiết kế.

Nói cách khác: cả hướng rẻ lẫn hướng đắt đều giải một bài toán không còn tồn tại.
Đây là lần thứ bảy trong #962 mà thứ tưởng là nợ hoá ra là phép đo cũ.

## Cái thật sự còn lại

9 cạnh, tất cả từ Payments, tất cả **làm đúng**:

```
OrderPaymentService  → OrderMutationFacade · BeginOrderPaymentCommand
                       StampOrderStripeIntentCommand · SettleOrderIfPaidCommand
                       RefreshOrderPaymentCacheCommand
StripePaymentService → OrderMutationFacade · StampOrderStripeIntentCommand
PaymentOrchestrator  → OrderMutationFacade · SettleOrderIfPaidCommand
```

Chúng đi qua `OrderMutationFacade` với command — chính xác cái ADR 0001 mô tả.
Và chúng vẫn bị đếm là nợ, vì ruleset coi **mọi** cạnh module→module là vi phạm.

Đó là hình dạng chung của vấn đề, không riêng gì Ordering: trước #1596, mỗi cổng
mà epic dựng lên đều **cộng thêm** vào con số nó đang kéo xuống.

## Quyết định

**"API công bố" là tư cách thành viên của layer `PublishedContracts`, khai trong
`config/modules.php`.** Không phải "nằm trong thư mục tên `Contracts`", không
phải "là một interface", không phải quy ước đặt tên nào cả.

Ba tính chất làm định nghĩa này chịu lực:

**1. Khai tường minh.** Một class hoặc một namespace phải được viết vào
`published_contracts` / `published_contract_namespaces`. Quét theo thư mục thì
"để vào folder `Contracts` là hết bị soi" — rào biến thành thủ tục.

**2. Không được phụ thuộc module nào.** `PublishedContracts` chỉ được phụ thuộc
`SharedKernel` và `TenancyKernel`. Đây là nửa chịu lực: **một cổng mang model
Eloquent của chủ sở hữu trong chữ ký sẽ đỏ ngay tại chỗ.** Đó là bản cưỡng chế
máy móc của câu "cổng không được rò model", và nó không cần ai nhớ.

Luật này đã ăn thật ngay khi ra đời: nó **loại** `CouponPricing` (nhận
`App\Models\Coupon`) khỏi danh sách, và ở #1595 nó chặn một bản nháp cổng nhận
`CustomerOrderItem` **trước khi bản nháp đó thành code**.

**3. Cạnh tới nó KHÔNG phải nợ.** Đó là điểm của cả việc này. Đi qua cổng công bố
phải rẻ hơn không đi, nếu không thì phép đo đang phạt đúng hành vi nó muốn khuyến
khích.

### Hệ quả cho Ordering

Toàn bộ API lệnh được công bố theo namespace:

```
App\Services\Order\Contracts     App\Services\Order\Commands
App\Services\Order\Results       App\Services\Order\ValueObjects
App\Services\Order\Enums
```

Đã kiểm cả 77 file: **không file nào import `App\Models\`**. Phụ thuộc ngoài của
chúng chỉ có `App\Omnify\Enums` và `App\Services\DomainMutation`, cả hai `shared`.
API lệnh vốn đã sạch model — nó chỉ chưa được khai.

`App\Services\Order\Internal` **không** công bố, và đó là điều đúng: nó chứa
persistence, verifier, context factory. Module ngoài không có việc gì ở đó.

## Cái này KHÔNG nói gì về việc GHI

Một câu hỏi vẫn mở, và nó là phần khó của #1594: Payments hiện **ghi** vào đơn
bằng cách khoá hàng rồi `update()` (`CustomerOrder::lockForUpdate()`,
`stampStripeIntentOnOrder`, `markOrderPaidFromIntent`). Quyết định ở đây làm cho
**đường đúng trở nên miễn phí** — gửi một command giờ không tốn cạnh nào — nhưng
nó không tự chuyển những chỗ ghi ấy sang command.

Việc đó là #1594, và giờ nó không còn chờ một quyết định nào nữa.

## Khi nào một service thuộc `Composition` (#1591)

`Composition` được phép phụ thuộc mọi module, nên nó là chỗ dễ biến thành thùng
rác nhất trong cả đồ thị: khai một service vào đây thì mọi cạnh của nó biến mất
mà không ai phải trả gì.

`App\Http` · `App\Console` · `App\Providers` … thuộc Composition theo bản chất.
Nhưng #1591 thêm mục `App\Services\*` đầu tiên (`App\Services\Dashboard`), và
loại mục đó cần hai điều kiện **đo được**, không phải một lập luận:

1. **0 cạnh VÀO từ module.** Nếu một module phụ thuộc nó thì nó không phải tầng
   nối dây — nó là phụ thuộc chung, và phải sống trong một module hoặc sau một
   cổng công bố.
2. **Không ghi gì.** Composition không sở hữu aggregate nào. Một service có ghi
   là có bất biến để giữ, và bất biến thuộc về một module.

Dashboard thoả cả hai, đo được: consumer duy nhất là hai controller (vốn đã là
Composition), và 821 dòng không có một lời gọi ghi nào. Nó nối orders +
products + tax breakdown để vẽ đúng hai màn hình — cùng loại việc với
controller, chỉ là không nằm trong `App\Http`.

`tests/Feature/Architecture/CompositionMembershipTest.php` cưỡng chế cả hai cho
**mọi** mục `App\Services\*` trong danh sách, nên chỗ này không thể lặng lẽ
thành cái thùng: thêm một service có module gọi tới, hoặc có một dòng ghi, là đỏ.

Đã áp cùng hai tiêu chí cho `App\Services\Workstation` (dựng manifest đồng bộ +
bản sao menu cho API thiết bị) và `App\Services\Payment\Observation` (rà lệch
sổ cho một lệnh CLI) ở #1552. Cả hai: 0 cạnh vào từ module, 0 lời gọi ghi.

Khi chỉ MỘT class trong một namespace thoả hai tiêu chí, dùng
`composition_classes` thay vì kéo cả namespace: `CustomerOrderHistoryService`
là Composition, còn `CustomerService`/`CustomerAuthService` cùng namespace thì
ghi dữ liệu và thuộc CustomerEngagement thật (#1596). Khai cả namespace ở đó là
đưa ba service có ghi vào một tầng không sở hữu gì.

**Một namespace KHÔNG được khai cho cả module lẫn Composition.** Đó là hai người
khai mâu thuẫn, và generator ném lỗi thay vì chọn hộ — Deptrac chỉ *cảnh báo*
"in more than one layer" rồi lấy layer đứng trước, nên bên thua cuộc im lặng
không có tác dụng gì. Đã xảy ra thật với `App\Services\Workstation`: con số
nhúc nhích 2 thay vì 19, và chỉ có dòng cảnh báo mới nói ra tại sao.

## Cách thêm một cổng công bố

1. Viết interface/value object **không nhận model nào** trong chữ ký.
2. Khai vào `published_contracts` (một class) hoặc `published_contract_namespaces`
   (cả một API mặt tiếp giáp).
3. `php artisan architecture:deptrac-config && vendor/bin/deptrac analyse`.
   Đỏ nghĩa là cổng còn rò một model — sửa cổng, đừng nới danh sách.
