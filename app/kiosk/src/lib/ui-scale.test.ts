import { describe, it, expect } from 'vitest';
import { computeCanvas, BASE_WIDTH, UI_ZOOM } from './ui-scale';

/**
 * Ghim phép toán canvas (#2).
 *
 * Tính chất được ghim ở đây là "phủ kín, không méo, không dải đen" — chứ không
 * phải các con số cụ thể. `UI_ZOOM` là hệ số chỉnh theo cảm quan và người sau
 * ĐƯỢC PHÉP đổi nó (docblock nói thẳng thế); một test khoá cứng `1.2` sẽ biến
 * mỗi lần chỉnh cỡ chữ thành một test đỏ phải sửa, và test nào cũng phải sửa
 * khi đổi thì cuối cùng sẽ bị sửa mà không ai đọc.
 */
describe('computeCanvas (#2)', () => {
  // Các thiết bị thật mà kiosk chạy trên đó, cộng hai cực để bắt lỗi tràn số.
  const DEVICES: Array<[string, number, number]> = [
    ['iPad 11" landscape', 1194, 834],
    ['iPad Air landscape', 1180, 820],
    ['iPad Pro 13" landscape', 1366, 1024],
    ['iPad mini landscape', 1024, 768],
    ['màn hình rộng 1920', 1920, 1080],
    ['tablet nhỏ 800', 800, 600],
  ];

  it.each(DEVICES)('%s: canvas sau khi scale phủ ĐÚNG kín màn hình', (_name, w, h) => {
    const { scale, width, height } = computeCanvas(w, h);

    // Đây là bất biến thật sự: scale rồi thì phải bằng chính màn hình. Lệch
    // dương = tràn, lệch âm = dải đen — cả hai đều là lỗi issue này mô tả.
    expect(width * scale).toBeCloseTo(w, 6);
    expect(height * scale).toBeCloseTo(h, 6);
  });

  it.each(DEVICES)('%s: hai trục dùng CHUNG một scale nên không méo', (_name, w, h) => {
    const { scale, width, height } = computeCanvas(w, h);

    // Tỉ lệ khung hình của canvas phải trùng tỉ lệ khung hình vật lý. Nếu ai đó
    // đổi sang scale riêng từng trục (fit-both-axes), phép này sẽ đổ.
    expect(width / height).toBeCloseTo(w / h, 6);
    expect(scale).toBeGreaterThan(0);
  });

  it('ở đúng bề ngang thiết kế, scale chính là UI_ZOOM', () => {
    expect(computeCanvas(BASE_WIDTH, 1024).scale).toBeCloseTo(UI_ZOOM, 10);
  });

  it('màn rộng gấp đôi thì scale gấp đôi — tuyến tính theo bề NGANG', () => {
    const a = computeCanvas(960, 700);
    const b = computeCanvas(1920, 700);

    expect(b.scale).toBeCloseTo(a.scale * 2, 10);
    // Bề ngang canvas không đổi: đó là ý nghĩa của "đơn vị thiết kế".
    expect(b.width).toBeCloseTo(a.width, 10);
  });

  it('chiều cao canvas co theo màn thấp — đây là trục bị phóng to ăn mất', () => {
    const tall = computeCanvas(1194, 900);
    const short = computeCanvas(1194, 700);

    expect(short.height).toBeLessThan(tall.height);
    // Cùng bề ngang thì cùng scale — chiều cao không được kéo scale theo.
    expect(short.scale).toBeCloseTo(tall.scale, 10);
  });

  it('UI_ZOOM là hệ số nhân thuần, không dính vào phép quy đổi độ phân giải', () => {
    // Bỏ UI_ZOOM ra thì còn đúng tỉ lệ màn/thiết kế. Ghim điều này để một lần
    // "chỉnh cho chữ to hơn" không lặng lẽ trở thành đổi cách quy đổi.
    expect(computeCanvas(1194, 834).scale / UI_ZOOM).toBeCloseTo(1194 / BASE_WIDTH, 10);
  });
});
