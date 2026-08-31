# Plan 055 — Tasks

Xếp theo **cổng**, không theo repo. Thứ tự giữa các cổng là ràng buộc cứng: đảo
là mất tiền, không phải mất thời gian.

Checkbox để trống cho tới khi plan được duyệt.

## Gate 1 — Số đo trước, thay đổi sau

- [x] **T1.1** Thêm số đo `% payment có gateway_option_id`, tách theo transport,
      vào `payments:legacy-removal-readiness` (hoặc `payments:observation-report`).
      Không có số này thì G2/G3 không có điều kiện ra.
      **Xong (#1814)**: precondition `client_sends_gateway_option_id` trên cổng
      `legacy_payment_method_resolver`, cửa sổ `--since-days` (mặc định 7), tách
      theo `channel` và **có bucket `unknown`**. Cửa sổ rỗng ⇒ `met=false`.
- [x] **T1.2** Thêm số đo độ phủ revision **theo org**, không chỉ tổng — một org
      phủ 100% vẫn có thể bị che bởi org khác đang 0%.
      **Xong (#1817)**: `policy_revision_coverage` giờ kèm phân tách theo org
      (xấu nhất trước) + `organizations_incomplete` để biết đi backfill ở đâu.
      Kèm đính chính bảng "4/9" trong README plan — thật ra là **1/9**.
- [x] **T1.3** Chạy cả hai số trên **production** và ghi lại làm baseline
      (artifact JSON trong `plans/plan-055/artifacts/`).
      **Quy trình chạy được: `plans/plan-055/artifacts/README.md` (#1867)** — lệnh
      chính xác, đọc trường nào, ngưỡng nào là đạt, cho cả sáu task production.
      ⚖️ **Không phải cổng chặn (ruling 2026-08-05).** Issue chỉ quan tâm dev;
      sandbox pass ⇒ production mặc định OK. Tick theo RULING, không phải vì đã
      chạy trên prod. Quy trình khi cần chạy thật: `artifacts/README.md`.

## Gate 2 — Phủ policy revision

- [x] **T2.1** Lệnh backfill publish revision cho mọi branch active chưa có,
      dựng từ **phương thức shop đang thực sự nhận**, không phải "bật hết".
      Chế độ báo cáo mặc định, `--apply` mới ghi.
      **Xong (#1821)**: `payments:backfill-policy-revisions`. Snapshot KHÔNG dựng
      trong lệnh — gọi `PaymentPolicyEvaluationService::publishInitialRevision()`
      (đường publish sẵn có, dựng từ CẤU HÌNH) nên "bật hết cho an toàn" không
      xảy ra được.
- [x] **T2.2** Chạy báo cáo trên production, **người đọc và duyệt** danh sách
      branch + option trước khi apply. Đây là nơi dễ đóng dấu "bật hết" nhất.
      ⚖️ **Không phải cổng chặn (ruling 2026-08-05).** Issue chỉ quan tâm dev;
      sandbox pass ⇒ production mặc định OK. Tick theo RULING, không phải vì đã
      chạy trên prod. Quy trình khi cần chạy thật: `artifacts/README.md`.
- [x] **T2.3** Apply, rồi xác nhận `policy_revision_coverage` = `N/N`.
      ⚠️ **`N/N` KHÔNG đủ — điều kiện ra này thiếu, phát hiện khi chạy T2.1.**
      Báo cáo trên dev: cả 8 branch chưa phủ đều sẽ có **0 effective option**.
      Publish revision cho chúng làm coverage lên `9/9` nhưng chúng **vẫn** ăn
      `throwDisabled('No effective payment options are available for checkout.')`
      khi flip cờ — tức vẫn từ chối tiền thật, chỉ là con số nhìn đã xanh.
      Điều kiện ra ĐÚNG: **mọi branch có revision VÀ ≥1 effective option**.
      Cột `Effective options` của lệnh backfill in sẵn số này; hàng `0` là hàng
      phải xử lý trước, không phải hàng bỏ qua.
      **Đã đưa vào LỆNH, không còn chỉ nằm trong ghi chú này (#1838).**
      `policy_revision_coverage` giờ đo cả hai vế và chỉ `met` khi cả hai đạt;
      nó tách riêng "thiếu revision" với "có revision nhưng 0 option" (hai lỗi,
      hai cách sửa khác nhau — backfill không cứu được cái thứ hai) và liệt kê
      đích danh branch hỏng kèm lý do. Ghi chú trong file này không đủ: người
      flip đọc **lệnh**, và lệnh trước đây trả lời "đã phủ" cho một câu hỏi nó
      không đo.
      ⚖️ **Không phải cổng chặn (ruling 2026-08-05).** Issue chỉ quan tâm dev;
      sandbox pass ⇒ production mặc định OK. Tick theo RULING, không phải vì đã
      chạy trên prod. Quy trình khi cần chạy thật: `artifacts/README.md`.

## Gate 3 — Client gửi option id (chưa cưỡng chế)

- [x] **T3.1** `pos-web`: gửi `gateway_option_id` + `policy_revision` trên mọi
      lệnh thanh toán. — #1830. **pos-web KHÔNG phải sửa gì**: nó đã gửi đúng
      tên chuẩn từ trước (`payment-dialog.tsx`, có test). Cái hỏng nằm ở đầu
      kia: bản nhúng trong workstation (`/pos`, #1169) dùng URL tương đối nên
      đi vào Go, mà Go đọc `payment_option_id` ⇒ **rơi ở biên LAN**. Sửa bằng
      alias phía Go (workstation-app `59d049a`).
- [x] **T3.2** `godx-kiosk`: như trên. Không ép cập nhật được ⇒ đây là một trong
      hai cái quyết định độ dài cửa sổ chờ. — #1830, kiosk `fb2e226`.
      Kiosk gửi **tên thứ ba** (`option_id`) mà **không đích nào** đọc: Go đọc
      `payment_option_id`, Cloud đọc `gateway_option_id`. Rơi ở CẢ HAI đường
      (LAN và fallback lên Cloud).
      **Cửa sổ chờ không còn do T3.1/T3.2 quyết định.** Cả ba bản sửa đều đặt ở
      PHÍA ĐỌC (Go alias + Cloud alias `option_id`), nên mọi pos-web/kiosk đang
      cài bắt đầu mang định danh mà không cập nhật máy nào. Việc kiosk không ép
      cập nhật được vẫn đúng — nó chỉ thôi là ràng buộc.
- [x] **T3.3** `workstation-app`: như trên, **cộng** đường sync-UP và hàng đợi
      offline. Cái chậm nhất trong ba. — #1829, umbrella `e4ae831f3`,
      workstation-app `6721abd`.
      **Hoá ra không phải "chưa gửi" mà là GỬI SAI TÊN.** Workstation đã stamp
      danh tính từ plan-047 T6.5 và vẫn forward đều; nó gửi `payment_option_id`
      / `connection_id`, Cloud validate `gateway_option_id` /
      `gateway_connection_id` và không đọc tên cũ ở đâu cả. Một trường request
      không được đọc thì đơn giản là vắng mặt — không lỗi, không log — nên
      `fromPaymentData()` thấy null và bỏ qua kiểm policy cho toàn bộ fleet.
      Sửa ở CẢ HAI phía, nhưng alias phía Cloud là cái có tác dụng ngay: nó làm
      mọi máy **đang chạy** bắt đầu mang danh tính từ payment kế tiếp, không cần
      cập nhật fleet — nên T3.3 không còn là cái quyết định độ dài cửa sổ chờ ở
      Gate 5 nữa; chỉ T3.1/T3.2 còn là.
- [x] **T3.4** Server: chấp nhận thiếu như cũ. **Không** đổi hành vi ở gate này.
      — #1834. **Hoá ra chính T3.1/T3.2 đã phá điều này, và tôi là người phá.**
      Alias (#1829/#1830) sửa đúng một chỗ rơi, nhưng nó khiến kiểm policy CHẠY
      cho đúng nhóm client trước giờ được miễn trong im lặng. Đo trên branch
      chưa publish revision — trạng thái phần lớn production cho tới khi Gate 2
      chạy — cùng một request đi từ **201 + ghi payment** sang **422 + không ghi
      gì**. Tức từ chối tiền thật, trước cả cổng quan sát lẫn cổng flip.
      **Sửa:** định danh đến QUA ALIAS thì policy thất bại là **quan sát**
      (`payment_policy_alias_would_refuse`), không phải từ chối, cho tới khi cờ
      Gate 6 bật. Dấu nằm ở attributes bag phía server — client không khai được.
      pos-web đi **thẳng lên Cloud** không bị nới: nó đã bị cưỡng chế thật từ
      plan-047. Nói "pos-web không bị nới" thì quá rộng — bản pos-web **nhúng
      trong workstation** (#1169) đi qua Go, và Go phát lại bằng tên cũ
      `payment_option_id` khi sync-UP, nên những payment đó CÓ được nới. Chỉ
      hình dạng đi-thẳng-Cloud là không đổi.
      Phụ phẩm: Gate 4 giờ quan sát được cả nhóm client này, đúng thứ nó cần.

✅ **Vật cản #1831 đã gỡ (2026-08-05).**
Phủ CẢ HAI nhánh — client không gửi
định danh (pos-web) và client CÓ gửi định danh option internal (kiosk) — cộng
掛売, thứ không có option catalog nào. Nhánh có định danh đòi **cả hai** vế:
phương thức là internal tender VÀ option được gửi chính là option internal; chỉ
nhìn phương thức sẽ waive nhầm "method tiền mặt + option gateway đã bị shop
tắt" (acceptance B11 bắt được).
⚠️ **Thêm một điều kiện phải kiểm TRƯỚC khi flip Gate 6 (#1855):** không option
ACTIVE nào của provider khác `internal` được ánh xạ sang mã `card_terminal`.
`payment_gateway_options` KHÔNG có phạm vi theo org, nên một hàng duy nhất tác
động mọi shop ở mọi org. `cash` và `debt` đã được rào cứng
(`NEVER_GATEWAY_ROUTABLE`, có test) vì tiền mặt là vật lý và 掛売 là bút toán —
một hàng catalog khai gateway định tuyến chúng là LỖI DỮ LIỆU. `card_terminal`
thì CỐ Ý vẫn chịu phép trừ, vì `card_present` là hàng thật.
Cưỡng chế áp cho tiền đi qua GATEWAY;
internal tender (tiền mặt · máy thẻ rời · ghi nợ) được miễn theo trạng thái
SERVER sở hữu — `PaymentMethod` resolve từ DB, thiết bị không khai được. Điều
kiện ra của Gate 5/6 bổ sung: phải chứng minh **trong cùng một lượt bật cờ** rằng
tiền mặt ĐI QUA và payment gateway thiếu option id BỊ CHẶN
(`InternalTenderSurvivesEnforcementTest`). Ghi chú gốc giữ lại bên dưới vì nó
giải thích vì sao đây là sai phạm trù chứ không phải nới lỏng.

⚠️ **Ghi chú gốc — Gate 3 xong KHÔNG tự mở đường cho Gate 5 (#1831).** Internal
tender (tiền mặt, máy thẻ rời) **cố ý** không mang gateway identity, vì resolver
fail-closed không bao giờ surface được option không có connection. Bật
`policy_enforcement.required` sẽ ném `POLICY_OPTION_REQUIRED` vào **mọi giao dịch
tiền mặt**. Lúc đó `PolicyOptionEnforcementTest` mã hoá đúng hành vi ấy và đang
xanh — ta tự viết ra rồi ghim lại, chỉ là chưa ai đọc nó như một cảnh báo.
**Không còn đúng ở thì hiện tại:** test đó nay dùng phương thức đi gateway, vì
tiền mặt đã được miễn (#1831). Giữ đoạn này làm SỬ LIỆU, đừng đọc như mô tả trạng
thái hôm nay.

## Gate 4 — Quan sát

- [x] **T4.1** Chế độ cảnh báo-không-chặn: thiếu option id ⇒ vẫn cho qua, ghi
      `payment_policy_option_missing` kèm transport · device · branch · org.
- [x] **T4.2** Bật ở staging trước, rồi production. Đọc log để có **danh sách
      chính xác** ai sẽ chết khi flip — không ước lượng.
      ⚖️ **Không phải cổng chặn (ruling 2026-08-05).** Issue chỉ quan tâm dev;
      sandbox pass ⇒ production mặc định OK. Tick theo RULING, không phải vì đã
      chạy trên prod. Quy trình khi cần chạy thật: `artifacts/README.md`.
- [x] **T4.3** Điều kiện ra: log rỗng qua một cửa sổ quan sát đủ dài để phủ chu
      kỳ phát hành chậm nhất (workstation).
      ⚠️ **HAI log, không phải một (sửa 2026-08-05, #1834).** T3.4 chuyển toàn bộ
      hạm đội legacy sang `payment_policy_alias_would_refuse`; chúng KHÔNG còn
      xuất hiện trong `payment_policy_option_missing`. Đọc mỗi vế đầu là đọc một
      con số **đã xanh sẵn** trong khi mọi workstation và kiosk vẫn trượt policy.
      Điều kiện ra đúng: **cả hai** log rỗng.
      ⚖️ **Không phải cổng chặn (ruling 2026-08-05).** Issue chỉ quan tâm dev;
      sandbox pass ⇒ production mặc định OK. Tick theo RULING, không phải vì đã
      chạy trên prod. Quy trình khi cần chạy thật: `artifacts/README.md`.

## Gate 5 — Ranh giới đơn offline

> **Thứ tự đã sửa 2026-08-05:** trước ghi *"T5 phụ thuộc T3.3"* — sai, đó là hệ
> quả của (a-yếu). Chốt (a-mạnh) không đổi client nào, nên **Gate 5 chạy được
> ngay, song song với Gate 3**, không phải sau nó.

- [x] **T5.1** Cloud **tự ghi dấu đơn đến từ offline replay** — thêm trường trên
      `CustomerOrder` qua **Omnify YAML** (không viết migration tay), set trong
      `EloquentOrderPersistence::insertOfflineReplay()` ngay sau `assertTrusted()`.
      Rồi `handleMissingPolicyOption()` miễn trừ khi đơn mang dấu đó, kèm log
      `payment_policy_replay_bypass`.
      **Chốt (a-MẠNH), không phải (a-yếu):** marker do CLOUD ghi từ evidence đã
      verify, KHÔNG phải client khai. Client tự khai `taken_offline_at` là miễn
      trừ vĩnh viễn — chính lỗ đang vá, đổi tên.
      **⇒ T5.x KHÔNG còn phụ thuộc T3.3** (không đổi client). Xem NOTES 2026-08-05.
      ⚠️ Regen chạm submodule — `tal submodule <path>` TRƯỚC `omnify:gen` (bẫy #7).

- [x] **T5.2** Test: đơn ký offline dưới revision **cũ**, replay sau khi flag
      bật, vẫn vào sổ và vẫn giữ `till_session_id` đúng.
      Chặn bởi T5.1. (Cờ `PAYMENT_POLICY_ENFORCEMENT_REQUIRED` **đã có** — #1823.)
- [x] **T5.3** Test: đơn **online** dùng phương thức **ĐI GATEWAY**, thiếu option
      id, sau khi flag bật thì 422 —
      chứng minh miễn trừ chỉ áp cho replay, không phải một lỗ mới.
      Làm được ngay — cờ đã có (#1823). Không phụ thuộc T5.1.

## Gate 6 — Flip

- [x] **T6.1** Cờ `PAYMENT_POLICY_ENFORCEMENT_REQUIRED` (mặc định `false`), tài
      liệu ở `docs/guide/payment-go-live.md` cùng chỗ với đường lùi khác.
- [x] **T6.2** Bật staging, quan sát; bật production theo từng org nếu cần.
      ⚖️ **Không phải cổng chặn (ruling 2026-08-05).** Issue chỉ quan tâm dev;
      sandbox pass ⇒ production mặc định OK. Tick theo RULING, không phải vì đã
      chạy trên prod. Quy trình khi cần chạy thật: `artifacts/README.md`.
- [x] **T6.3** Mã lỗi có cấu trúc `POLICY_OPTION_REQUIRED` + hướng dẫn refresh,
      để client cũ hiện thông báo đúng thay vì "lỗi không xác định".
      **T4.1+T6.1+T6.3 làm chung ở #1823** — chúng là hai nhánh của MỘT chỗ rẽ
      (thiếu option id → cảnh báo hay chặn), và một cờ không định nghĩa lỗi thì
      không cài được. Cờ mặc định `false`; ⛔ **chưa được bật** vì T5.1 còn chặn.

## Gate 7 — Thu hồi legacy

- [ ] **T7.1** Kiosk + workstation resolve method qua effective options; bỏ
      `LegacyPaymentMethodResolver` khỏi hai controller.
      ⚠️ **KHÁC với sáu task trên: đây KHÔNG phải chuyện production.** Hai
      controller nhận `payment_method` dạng **chuỗi mã legacy** rồi resolve ra
      `PaymentMethod`. Gỡ resolver nghĩa là mọi kiosk và workstation ĐANG CHẠY
      phải gửi thứ khác — đây là ràng buộc TƯƠNG THÍCH CLIENT, sandbox pass
      không nói gì về nó. Để mở.
      ⛔ **VẪN MỞ — và #1887 KHÔNG đóng được nó.** Task này có HAI vế; #1887
      chỉ làm vế sau. Class đã xoá thật (nó chạy truy vấn tương đương
      `PosEffectivePaymentOptionEnricher`, nên gỡ được ngay, hạm đội không phải
      đổi gì). Nhưng vế đầu — *"resolve method qua effective options"* — chưa
      xảy ra: kiosk và workstation vẫn POST `payment_method` là CHUỖI MÃ và
      server vẫn tra y như cũ. Tôi đã tick task này dựa trên `call_sites=0` của
      cổng theo TÊN CLASS, tức lấy một phép đo về tên trả lời cho một câu hỏi về
      đường đi. Người review bắt được.
      Cổng đo đúng vế đầu giờ là `legacy_payment_method_code_path` — hiện báo
      **2 call site**, và nó chỉ đóng được bằng cách làm thật.
- [x] **T7.2** Xoá class + xác nhận `payments:legacy-removal-readiness` cho cổng
      `legacy_payment_method_resolver` = `already removed`.
      ✅ **Xong (#1887)** — nhưng chỉ đúng phạm vi của chính nó: class đã xoá,
      cổng `legacy_payment_method_resolver` báo `code_present=false`,
      `call_sites=[]` → `already removed`.
      ⚠️ **Đây KHÔNG phải bằng chứng client đã di trú.** Ô này đóng được bằng
      cách xoá một class; nợ thật là ĐƯỜNG ĐI, đo ở
      `legacy_payment_method_code_path` (T7.1, còn mở). Hai cổng cố ý bất đồng
      cho tới khi client gửi effective-option id; `LegacyRemovalReadinessTest`
      ghim sự bất đồng đó để không ai đọc nhầm ô này thành "xong hết".
- [x] **T7.3** Cập nhật `plans/plan-047/TASKS.md` T7.6 cho khớp trạng thái mới —
      **và không viết bảng điều kiện mới vào đó**; bảng sống trong lệnh readiness,
      vì bảng trong file markdown đã cũ một lần rồi mà không ai thấy. — #1836.
      Tìm thấy đúng hai thứ T7.3 dựng ra để chặn: (1) T7.6 kết bằng *"closes in
      Plan 048 (#1087)"* mà **#1087 đã đóng**, nội dung chuyển sang hai file docs
      — con trỏ dẫn tới một issue không còn việc; (2) bảng "Remaining T7.6 debt"
      vẫn mang cột **"Status 2026-07-26"**, tức đã cũ **lần thứ hai**.
      Bảng tĩnh bị gỡ; giữ lại phần lệnh KHÔNG đo được, là **lý do** mỗi shim
      không phải một lệnh xoá đơn thuần. Lệnh có trường `code_present` (`--json`;
      bảng người đọc hiện qua cột `Call sites`) nên bắt được
      cả trường hợp **điều kiện đã đạt mà code vẫn còn** — hôm nay
      `payment_status_compatibility` chính là thế, và bảng markdown không hề nói.

## Cổng duyệt

Dừng ở đây. Không thực thi task nào cho tới khi plan được duyệt.
