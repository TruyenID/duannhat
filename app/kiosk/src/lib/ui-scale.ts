/**
 * Phép toán canvas ảo của kiosk (#2).
 *
 * Giao diện được vẽ theo point cố định (`text-2xl`, `w-[340px]`…), nên thay vì
 * scale từng giá trị một, cả cây được đặt lên một canvas ảo rồi scale ĐỒNG NHẤT
 * một lần. Neo vào một scalar duy nhất giữ hai trục khoá nhau — không méo — và
 * suy canvas ra TỪ scale đó khiến nó luôn phủ kín màn hình, không có dải đen.
 *
 * Tách khỏi `app/_layout.tsx` vì đây là thứ chi phối MỌI màn: sai một chút thì
 * hỏng trên mọi thiết bị cùng lúc, mà lại là kiểu hỏng không có test đơn vị nào
 * chạm tới nếu phép toán còn nằm lẫn trong một component có JSX.
 */

/** Bề ngang thiết kế, tính bằng point. Landscape tablet ~13". */
export const BASE_WIDTH = 1366;

/**
 * Hệ số phóng chủ quan, KHÔNG phải một phần của phép quy đổi độ phân giải.
 *
 *   >1.0 = to hơn (chữ/nút lớn, lọt ít nội dung hơn)
 *   <1.0 = nhỏ hơn (lọt nhiều hơn)
 *
 * Chỉnh theo khoảng cách đứng nhìn của kiosk trên iPad 11"/Air (~1194pt). Hạ
 * xuống nếu nội dung cao cố định bắt đầu bị cắt ở đáy — phóng to là đánh đổi
 * chiều dọc lấy kích cỡ.
 */
export const UI_ZOOM = 1.2;

export interface Canvas {
  /** Hệ số truyền vào `transform: [{ scale }]`. */
  scale: number;
  /** Bề ngang canvas tính bằng đơn vị thiết kế. */
  width: number;
  /** Bề cao canvas tính bằng đơn vị thiết kế. */
  height: number;
}

/**
 * Suy canvas ảo từ kích thước cửa sổ thật.
 *
 * Trả về `scale` cùng bộ kích thước mà — sau khi scale — phủ đúng kín màn hình.
 * Cố ý neo vào bề NGANG: thiết kế là landscape, chiều ngang là trục có nội dung
 * cạnh nhau (sidebar + lưới), còn chiều dọc thì cuộn được.
 */
export function computeCanvas(windowWidth: number, windowHeight: number): Canvas {
  const scale = (windowWidth / BASE_WIDTH) * UI_ZOOM;
  return {
    scale,
    width: windowWidth / scale,
    height: windowHeight / scale,
  };
}
