# Xác nhận email khi khách đăng ký (#1680)

Khách tự đăng ký ở Customer Web (`/{locale}/register/{shop}`) **phải bấm link
trong thư** thì tài khoản mới dùng được. Trước #1680, đăng ký xong là có token
và vào thẳng trang mua hàng — địa chỉ email chưa bao giờ được chứng minh là của
khách, trong khi hoá đơn, thông báo đơn hàng và (sau này) khôi phục mật khẩu
đều bám vào chính địa chỉ đó.

## Cổng nằm ở đâu

| Bước | Trước #1680 | Sau #1680 |
|---|---|---|
| `POST /api/v1/customer/auth/register` | 201 + Sanctum token | **202**, không token, thân `{ data: { email, verification_required } }` |
| `POST /api/v1/customer/auth/login` | luôn cho qua, kèm cờ `email_verified` không ai đọc | **403 `email_not_verified`** nếu chưa xác nhận |
| `GET /api/v1/customer/auth/verify/{id}/{hash}` | JSON, middleware `signed` | chuyển hướng về Customer Web kèm `?verified=…` |
| `POST /api/v1/customer/auth/email/resend` | sau `auth:customer` — người cần nó không với tới | **công khai**, `throttle:3,1` |

Cổng thật là ở `login`, không phải ở việc `register` thôi phát token: chỉ bỏ
token lúc đăng ký thì tài khoản chưa xác nhận vẫn đăng nhập được ngay sau đó
bằng chính mật khẩu vừa đặt.

Kiểm "chưa xác nhận" chạy **sau** khi so mật khẩu. Đảo thứ tự là biến endpoint
đăng nhập thành máy dò xem địa chỉ nào đã có tài khoản.

## Link trong thư

`VerifyEmail::createUrlUsing` (ở `AppServiceProvider`) ký thêm hai tham số cho
khách: `locale` và `shop`. Chúng nằm **trong** chữ ký nên không sửa được để lái
khách sang cửa hàng khác, và chúng phải được chốt lúc GỬI — lúc khách bấm link,
request đến từ ứng dụng mail và không mang theo ngữ cảnh nào của phiên đăng ký.

`verify` không dùng middleware `signed` nữa mà tự kiểm chữ ký, để phân biệt:

| `?verified=` | Nghĩa |
|---|---|
| `ok` | vừa xác nhận xong |
| `already` | link bấm lần thứ hai |
| `expired` | quá `auth.verification.expire` (mặc định 60 phút) — bấm gửi lại là xong |
| `invalid` | chữ ký sai / link bị sửa |

Đích quay về là `{CUSTOMER_WEB_URL}/{locale}/login/{shop}?verified=…`, hoặc
`/{locale}/select-branch?verified=…&next=login` với khách không gắn cửa hàng
(tài khoản tạo trước #1505). Ở nhánh chữ ký hỏng, `locale`/`shop` là dữ liệu
người lạ nên vẫn bị lọc lại (whitelist locale + regex slug) trước khi ghép vào
đường dẫn.

`CUSTOMER_WEB_URL` **bỏ trống là hợp lệ**: máy dev/test không cắm Customer Web
thì `verify` trả JSON như cũ thay vì chuyển hướng vào một URL rỗng.

## Thư gửi bằng ngôn ngữ của khách

`App\Notifications\Customer\VerifyCustomerEmail` thay bản tiếng Anh cứng của
framework, nội dung ở `lang/{ja,en,vi}/emails.php` khoá `verify_email.*`.
Locale lấy từ locale đang hoạt động của request (`SetLocale` phân giải từ
`Accept-Language` mà `apiFetch` đóng dấu). Thư gửi **đồng bộ** trong request nên
locale còn nguyên — đẩy notification này xuống queue sau này thì phải bọc
`Notification::locale()` hoặc cho `Customer` implement `HasLocalePreference`.

## Deploy — không còn bước backfill (#1730 → #2318)

Migration `2026_08_04_000000_seeder_customer_email_verified` **đã bị xoá**
(#2318, cùng 11 data migration khác): nó đóng dấu các tài khoản tạo TRƯỚC khi
cổng xác thực bật, tức một trạng thái chỉ tồn tại trên DB đã chạy từ trước. DB
dựng lại từ schema + seeder không có "tài khoản tiền-cổng" nào để đóng dấu.

Mọi tài khoản tạo trước bản này đều có `email_verified_at = NULL` — không phải
vì khách chưa xác nhận mà vì hồi đó không có gì để xác nhận. Không đóng dấu là
**khoá toàn bộ khách hiện tại ra ngoài cùng một lúc**, bằng đúng mật khẩu vẫn
đang đúng. Lệnh đóng dấu theo `created_at` của từng bản ghi (không phải `now()`)
và bỏ qua bản ghi CRM không có mật khẩu.

### Vì sao phải là migration, không phải một dòng trong runbook

Bản đầu của tài liệu này in đậm *"BẮT BUỘC chạy backfill"*, nhưng **không đường
deploy nào chạy nó** — `.github/workflows/deploy-xserver.yml` chỉ gọi `migrate`.
Gắn tag → deploy → mọi khách nhận `403 email_not_verified`, cho tới khi có người
nhớ ra một câu lệnh nằm trong thân một PR đã đóng. Cửa thoát của họ (gửi lại thư)
đi qua chính SMTP mà mục dưới ghi nhận là chưa nối — tức cửa thoát hiểm khoá
cùng lúc với cửa chính.

Chạy trong migration còn ghim **"pre-gate" vào đúng khoảnh khắc deploy**, nên
không còn cửa sổ nào để một tài khoản đăng ký SAU khi cổng bật bị đóng dấu nhầm.

### Lệnh artisan ĐÃ GỠ (#2188) — migration là đường duy nhất

`customers:backfill-email-verified` bị xoá ngày 2026-08-08 cùng cả họ
`Backfill*` (ruling: legacy không tồn tại). **Đừng đi tìm, và đừng dựng lại.**
Migration đóng dấu cũng đã đi theo (#2318) — nó chỉ có nghĩa với DB migrate dần
từ trước khi cổng bật. Đường đóng dấu còn sống là
`CustomerService::markPreGateAccountsVerified()`, gọi khi thật sự cần.

Cần chữa cháy cho **một** tài khoản (khách kêu bị `403 email_not_verified`
trong khi đáng lẽ phải qua): đóng dấu đúng bản ghi đó, đừng quét cả bảng. Mốc
`--before` ngày xưa tồn tại vì một lượt quét không mốc sẽ đóng dấu ĐÃ XÁC NHẬN
lên cả tài khoản vừa đăng ký bằng địa chỉ gõ nhầm — đúng lỗ hổng mà cổng này
sinh ra để bịt. Rủi ro đó không mất đi cùng với lệnh: nó chỉ chuyển thành lý do
để không viết lại một lệnh quét hàng loạt.

Thứ tự: **backend trước, Customer Web sau**. Customer Web cũ + backend mới thì
khách đăng ký xong sẽ thấy màn hình trắng ở bước điều hướng (bản cũ đọc
`data.token` không còn tồn tại); backend cũ + Customer Web mới thì màn "kiểm tra
hộp thư" hiện ra nhưng khách vẫn đăng nhập được ngay — sai lệch nhẹ hơn.

## Gửi thư thật

Staging hiện trỏ `MAIL_MAILER=smtp` → **mailpit** (`docker-compose.yml`), tức
thư không ra khỏi máy. Muốn tới Gmail thật cần SMTP thật + `MAIL_FROM_ADDRESS`
thuộc domain đã có SPF/DKIM, nếu không Gmail xếp thư vào spam — và một thư xác
nhận nằm trong spam thì cổng này trở thành cánh cửa khoá.

## Còn nợ

- **Không có lệnh dọn tài khoản chưa xác nhận quá hạn.** Chúng chiếm địa chỉ
  email (`unique:customers,email` khi đăng ký) vô thời hạn. Dọn hay không là
  quyết định sản phẩm: xoá thì người gõ nhầm địa chỉ đăng ký lại được, không xoá
  thì địa chỉ bị giữ bởi một tài khoản chưa từng dùng.
- Quên mật khẩu vẫn chưa có (nút `forgotPassword` ở trang đăng nhập chưa nối
  vào đâu) — cùng hạ tầng mail, nhưng là việc khác.
