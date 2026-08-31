# Chính sách test — vai CODE chạy hẹp, cổng review chạy đủ

**CẤM vai code chạy full suite.** Backend có hàng nghìn test; mỗi lần chạy tốn hàng chục
phút và ăn hết lease. Vòng code lặp nhiều lần nên phải nhanh.

## Chạy đúng phạm vi mình chạm

```sh
# Backend (native, KHÔNG qua docker — nhanh hơn, dùng DB test trong phpunit.xml)
cd backend
php -d memory_limit=-1 vendor/bin/pest --compact --filter=TenTestLienQuan
php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/ThuMucLienQuan
```

- **Web app**: typecheck **đúng app đó** — `cd web/admin && pnpm typecheck` (hoặc
  `web/customer`, `web/pos`, `app/kds`…). KHÔNG chạy `pnpm typecheck` ở gốc, nó quét cả
  ba app web.
- **Go (`workstation/`)**: `go test ./internal/<package>/...`, không `./...`.
- **Không có test liên quan nào tồn tại** → viết test cho đúng phần vừa sửa rồi chạy riêng
  nó. Đó là ngoại lệ duy nhất được thêm test.

## Khi nào được mở rộng phạm vi

Chỉ khi **chính bạn thấy dấu hiệu lan**, và phải nói rõ trong PR vì sao:

- sửa migration (migration chạy trước **mỗi** test — một migration chết là mọi test chạm
  DB đều đỏ);
- sửa model/service dùng chung nhiều domain;
- chạy `npm run omnify:gen` (regen chạm nhiều file generated);
- sửa thứ liên quan thời gian nghiệp vụ → chạy thêm `composer test:timezones`.

## Lệnh chỉ chạy trong container

Mọi artisan chạm DB: `docker compose exec app php artisan …`. Chạy native từ host sẽ trỏ
vào MySQL của Herd — một database khác — và im lặng không làm gì cho stack docker.

## Full suite

Là việc của **cổng review**, chạy đúng một lần trước khi đóng dấu `agent:review-passed`.
Lệnh khai trong `fullSuite` của `.claude/agent-loop.json`. Vai code không chạy.

## Bẫy của Pest/PHP — thuộc repo này, không phải luật chung

Skill chỉ mang luật chung ("test xanh chưa chứng minh gì — chạy chiều ngược lại").
Ba bẫy dưới đây là của ngôn ngữ/framework repo này dùng, nên sống ở đây.

### `toContain` + một câu văn = test LUÔN XANH

```php
expect($src)->not->toContain($needle, 'giải thích vì sao cấm');   // ← LUÔN XANH
```

`toContain` nhận `...$needles`. Câu giải thích ở đối số hai bị đọc thành **needle
thứ hai**, và `not` thoả mãn ngay khi **một** needle vắng mặt — mà một câu tiếng
Việt thì không bao giờ nằm trong mã nguồn. Test xanh vĩnh viễn, kể cả khi thứ nó
cấm quay lại nguyên vẹn. Đã trả giá: 5 ca ratchet của
`InventoryCatalogPublishedPortsTest` chưa bao giờ nổ.

Viết đúng: `expect(str_contains($src, $needle))->toBeFalse('vì sao cấm');`

**Nhiều đối số KHÔNG tự nó là lỗi** — repo có ~29 file dùng đúng vì chúng truyền
nhiều needle THẬT (`expect($status)->toContain('confirmed', 'succeeded')`). Dấu
hiệu là **văn xuôi cho người đọc nằm sau dấu phẩy**.

Sàng nhanh rồi tự đọc từng chỗ (pattern lỏng hơn trúng 29 file gần như toàn bộ
hợp lệ — một luật kêu oan cỡ đó sẽ bị tắt):

```sh
grep -rnE "toContain\([^)]+,\s*['\"][A-ZĐ][^'\"]*\s[^'\"]*['\"]" <test trong diff>
```

Luật chung rút ra: **matcher biến thiên nào cũng nuốt đối số thêm** — kiểm chữ ký
trước khi truyền thông điệp cho matcher.

### Engine test KHÁC engine production

Test chạy SQLite, production chạy MySQL. Thay đổi là **DDL** thì phải kiểm thêm
trên engine thật và nói rõ đã kiểm ở đâu. `GREATEST` là ca đã cắn: MySQL có,
SQLite không — nhánh dùng nó **chưa bao giờ chạy trong suite** và chỉ hỏng trên
production.

### Migration chạy trước MỖI test

Một migration chết ⇒ mọi test chạm DB đều đỏ. Đỏ hàng loạt cùng một thông điệp
gần như luôn là môi trường/migration, không phải logic của PR.

## Bốn kiểu test vô nghĩa đã lọt qua

Nhận ra chúng trong diff nhanh hơn là chạy lại:

| Dấu hiệu | Vì sao vô nghĩa |
|---|---|
| Test đọc **mã nguồn** (`file_get_contents`, `substr_count`, regex trên file) | Ghim VỊ TRÍ của code, không ghim hành vi. Đỏ khi dời code đúng cách, xanh khi hành vi hỏng mà chuỗi vẫn còn |
| Test **mock** đúng class/method chứa thứ đang kiểm | Mock nuốt luôn guard |
| Tiêm lỗi bằng cách **đập môi trường** (`Schema::drop`) | Lỗi rơi SỚM hơn chỗ đang đo |
| Đầu vào **không kích hoạt** nhánh đang kiểm | Gửi trùng giá trị đang có ⇒ guard "chỉ chạy khi ĐỔI" không chạy lần nào |

Chỗ tiêm lỗi đúng nằm **giữa** hai thứ đang được buộc lại với nhau (model event,
cổng được inject). Tiêm ở đó ghim **thuộc tính**; mock một service thường chỉ ghim
**cách cài đặt**.
