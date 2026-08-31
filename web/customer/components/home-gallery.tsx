/**
 * HomeGallery — dải ảnh không khí quán ở cuối trang chủ (mockup 2026-08).
 *
 * Ghép 6 ảnh thành lưới 4 cột: hai cột ngoài mỗi cột MỘT ảnh dọc cao hết khung,
 * hai cột giữa mỗi cột HAI ảnh xếp chồng. Vị trí đặt tường minh bằng
 * `col-start`/`row-start` chứ không nhờ auto-flow: với hai ô `row-span-2` xen
 * giữa, auto-flow sẽ nhét ảnh 6 vào chỗ trống đầu tiên nó tìm thấy chứ không
 * phải cột 4, và thứ tự trên màn hình sẽ khác thứ tự trong mảng.
 *
 * ⚠️ SÁU ẢNH CHƯA CÓ FILE. Mockup dùng ảnh chụp thật trong quán (đầu bếp chan
 * phở, bàn ăn, khách, nhân viên bưng bát) — chưa ai cấp file. Nhận được ảnh thì
 * thả vào `public/images/` đúng sáu tên ở `PHOTOS`, không phải sửa code.
 *
 * Trong lúc chờ, mỗi ô hiện một mảng gradient ấm. Làm bằng CSS **hai lớp
 * background** (`url(...)` chồng lên gradient) chứ KHÔNG phải `<img onError>`
 * như các section khác trong trang: ảnh 404 ngay khi trình duyệt parse HTML,
 * tức TRƯỚC lúc React hydrate và gắn handler, nên sự kiện `error` rơi vào
 * khoảng trống và ô đứng nguyên với icon ảnh vỡ. Lớp gradient thì không phụ
 * thuộc JS — file có thì ảnh phủ lên, file thiếu thì lộ gradient, không lúc nào
 * hiện icon vỡ.
 *
 * Vì thế component này KHÔNG cần `"use client"`: không state, không handler.
 *
 * Ảnh là trang trí thuần nên không có `alt` — sáu tấm này không mang thông tin
 * nào mà phần chữ quanh nó chưa nói, và `role="presentation"` trên khối lưới
 * nói đúng điều đó với trình đọc màn hình.
 */

const PHOTOS = [
  "/images/gallery-1.webp",
  "/images/gallery-2.webp",
  "/images/gallery-3.webp",
  "/images/gallery-4.webp",
  "/images/gallery-5.webp",
  "/images/gallery-6.webp",
];

/**
 * Vị trí từng ảnh trong lưới desktop. Viết thành class TĨNH chứ không ghép
 * chuỗi `md:col-start-${n}` — Tailwind quét source theo chuỗi nguyên vẹn, class
 * ghép động sẽ không được sinh ra.
 *
 * Dưới md lưới rút về 2 cột auto-flow: bốn cột với hai ô cao gấp đôi thì trên
 * điện thoại mỗi ảnh chỉ còn ~80px bề ngang.
 */
const PLACEMENT = [
  "md:col-start-1 md:row-start-1 md:row-span-2",
  "md:col-start-2 md:row-start-1",
  "md:col-start-2 md:row-start-2",
  "md:col-start-3 md:row-start-1",
  "md:col-start-3 md:row-start-2",
  "md:col-start-4 md:row-start-1 md:row-span-2",
];

/** Nền tạm khi ảnh chưa có — sáu sắc ấm/xanh khác nhau để không thành sáu ô y hệt. */
const PLACEHOLDER_TINTS = [
  "linear-gradient(140deg, #e8f3e6 0%, #c9e2cd 100%)",
  "linear-gradient(140deg, #f3ede1 0%, #e0d4c0 100%)",
  "linear-gradient(140deg, #e4efe6 0%, #cddcd2 100%)",
  "linear-gradient(140deg, #f0e9dd 0%, #dcccb6 100%)",
  "linear-gradient(140deg, #e9f2ea 0%, #cfe0d3 100%)",
  "linear-gradient(140deg, #eef1e6 0%, #d6ddc4 100%)",
];

export default function HomeGallery() {
  return (
    <section className="shrink-0 bg-background">
      <div className="mx-auto w-full max-w-5xl px-4 pb-20 pt-16 md:px-6 md:pb-24 md:pt-20">
        <div
          role="presentation"
          className="grid grid-cols-2 gap-3 md:h-[420px] md:grid-cols-4 md:grid-rows-2 lg:h-[470px]"
          data-reveal-stagger
        >
          {PHOTOS.map((src, idx) => (
            <div
              key={src}
              className={`aspect-[4/3] bg-cover bg-center bg-no-repeat md:aspect-auto md:h-full ${PLACEMENT[idx]}`}
              style={{ backgroundImage: `url(${src}), ${PLACEHOLDER_TINTS[idx]}` }}
            />
          ))}
        </div>
      </div>
    </section>
  );
}
