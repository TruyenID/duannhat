# Hotfix — làn khẩn cấp, có chủ đích

Ba script, mỗi bề mặt một làn. Tất cả sinh ra từ sự cố 2026-08-18 (Tsukiji:
image_fetcher nện 404 mỗi 5s, poke chết 3 tầng) — hôm đó các bước này chạy
bằng tay và máy quán sống lại trong ~3 phút, trong khi làn CI/CD mất ~40 phút
vì hàng đợi runner + vòng sửa cổng.

| Bề mặt | Lệnh | Bỏ qua gì | Thời gian |
|---|---|---|---|
| Một máy trạm cụ thể | `hotfix-workstation.sh <ssh-host>` | GitHub toàn phần — build local, scp thẳng | ~2–4 phút |
| Web (Amplify) | `hotfix-web.sh <admin\|customer\|pos>` | Hàng đợi runner + cổng CI (Amplify tự build từ main) | ~4–6 phút |
| Backend (xserver) | `hotfix-backend.sh [--preempt]` | Rào giờ phục vụ; `--preempt` dọn hàng đợi runner | ~5–10 phút |

## Luật chơi — đọc trước khi chạy

1. **Commit vẫn phải LÊN MAIN.** `hotfix-web` và `hotfix-backend` deploy từ
   main — chúng chỉ bỏ qua *hàng đợi*, không bỏ qua *lịch sử*. Riêng
   `hotfix-workstation` build từ cây local (kể cả chưa commit — binary sẽ tự
   khai `-dirty`), nên nó đi kèm NGHĨA VỤ: vá xong phải đưa commit lên
   dev→main ngay, để bản release kế tiếp chứa nó. Máy hotfix là nhánh tạm,
   không phải trạng thái đích.
2. **Smoke không phải thứ để bỏ.** Mỗi script kết thúc bằng phép đo (service
   active / build-info.json / smoke của workflow). Cái làm hotfix nhanh là bỏ
   HÀNG ĐỢI, không phải bỏ MẮT.
3. **Backend không có làn "không CI/CD".** Key xserver chỉ nằm trong GitHub
   secret, và bước deploy ghi migrate + seed vào DB tiền thật — một đường
   duy nhất, được review, là tính năng chứ không phải hạn chế. Muốn nhanh
   hơn nữa thì đăng ký thêm một runner riêng cho job deploy (label riêng),
   đừng mở đường ssh thứ hai.
4. **`--preempt` có giá.** Nó hủy các run còn queued trên main — dấu X đỏ
   phải được re-run sau khi dập lửa, đừng để main đỏ qua đêm.
5. **Fleet vẫn đi đường chính.** Hotfix một máy không thay thế release:
   `WORKSTATION_EXPECTED_VERSION` + trang /downloads mới là đường đưa bản vá
   tới mọi quán (auto-apply 02:00–04:00 giờ quán).

## Vì sao không tắt hẳn CI cho nhanh

Repo này đã trả giá đủ để biết cổng nào cũng từng cứu một sự cố thật
(buildspec chết #3229, seeder đè lựa chọn quán #2777, bundle cũ giả vờ mới
#3231…). Làn hotfix tồn tại để KHÔNG phải chọn giữa "chờ 40 phút" và "tắt
cổng" — nó đi vòng qua hàng đợi và giữ lại phép đo.
