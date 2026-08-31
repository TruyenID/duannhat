import { describe, it } from "node:test";
import assert from "node:assert/strict";

import {
  callingCodeFor,
  DEFAULT_PHONE_COUNTRY,
  flagEmoji,
  isSupportedPhoneCountry,
  phonePlaceholderFor,
  reformatForCountry,
  SUPPORTED_PHONE_COUNTRIES,
  toE164,
  validatePhoneForCountry,
} from "./phone.ts";

// #1845 — bộ chọn mã vùng của trang đăng ký. Ba lựa chọn này là hợp đồng với
// giao diện: thêm/bớt một nước mà quên gợi ý hoặc mã vùng của nó thì ô nhập
// hiện chuỗi rỗng chứ không hỏng ở đâu cả, nên chốt bằng test.
describe("danh sách quốc gia được hỗ trợ", () => {
  it("đúng ba nước, mặc định là Nhật", () => {
    assert.deepEqual([...SUPPORTED_PHONE_COUNTRIES], ["JP", "VN", "GB"]);
    assert.equal(DEFAULT_PHONE_COUNTRY, "JP");
  });

  it("mỗi nước đều có mã vùng và gợi ý nhập", () => {
    for (const country of SUPPORTED_PHONE_COUNTRIES) {
      assert.match(callingCodeFor(country), /^\+\d+$/, country);
      assert.notEqual(phonePlaceholderFor(country), "", country);
    }
  });

  it("mã vùng đúng là +81 / +84 / +44", () => {
    assert.equal(callingCodeFor("JP"), "+81");
    assert.equal(callingCodeFor("VN"), "+84");
    assert.equal(callingCodeFor("GB"), "+44");
  });

  it("mã quốc gia rác trả về chuỗi rỗng chứ KHÔNG ném", () => {
    // `getCountryCallingCode` ném với mã lạ; một giá trị rác không được phép
    // làm trắng cả trang đăng ký.
    assert.equal(callingCodeFor("ZZ"), "");
    assert.equal(callingCodeFor(""), "");
  });

  it("isSupportedPhoneCountry chặn giá trị ngoài danh sách", () => {
    assert.equal(isSupportedPhoneCountry("JP"), true);
    assert.equal(isSupportedPhoneCountry("US"), false);
    assert.equal(isSupportedPhoneCountry(null), false);
  });
});

describe("gợi ý nhập đi theo NƯỚC, không theo ngôn ngữ", () => {
  it("mỗi nước một dạng số riêng", () => {
    assert.equal(phonePlaceholderFor("JP"), "90 1234 5678");
    assert.equal(phonePlaceholderFor("VN"), "912 345 678");
    assert.equal(phonePlaceholderFor("GB"), "7400 123456");
  });

  it("nước không hỗ trợ thì không gợi ý gì (chứ không gợi ý sai)", () => {
    assert.equal(phonePlaceholderFor("US"), "");
  });
});

describe("reformatForCountry", () => {
  // Lý do hàm này tồn tại: `formatAsYouType` nối thêm vào chuỗi ĐANG có, nên
  // đổi nước mà không tước nhóm cũ thì số giữ nguyên cách chia của nước cũ —
  // hiện một con số mà chính ô đó vừa thôi chấp nhận.
  it("chia lại nhóm chữ số theo nước mới", () => {
    const vietnamese = "033 690 9454";
    const asJapanese = reformatForCountry(vietnamese, "JP");
    assert.notEqual(asJapanese, vietnamese);
    assert.equal(asJapanese.replace(/\D/g, ""), "0336909454");
  });

  it("giữ nguyên đủ chữ số, không nuốt số nào", () => {
    for (const country of SUPPORTED_PHONE_COUNTRIES) {
      assert.equal(reformatForCountry("090-1234-5678", country).replace(/\D/g, ""), "09012345678");
    }
  });

  it("ô rỗng vẫn rỗng", () => {
    assert.equal(reformatForCountry("", "JP"), "");
    assert.equal(reformatForCountry("   ", "VN"), "");
  });
});

describe("kiểm tra + E.164 theo nước đang chọn", () => {
  it("nhận số hợp lệ của cả ba nước", () => {
    assert.equal(validatePhoneForCountry("090 1234 5678", "JP").valid, true);
    assert.equal(validatePhoneForCountry("033 690 9454", "VN").valid, true);
    assert.equal(validatePhoneForCountry("07400 123456", "GB").valid, true);
  });

  it("cùng một chuỗi số đổi nước thì đổi kết luận", () => {
    assert.equal(validatePhoneForCountry("033 690 9454", "GB").valid, false);
    assert.equal(validatePhoneForCountry("07400 123456", "VN").valid, false);
    assert.equal(validatePhoneForCountry("090 1234 5678", "VN").valid, false);
  });

  it("số hợp lệ ở HAI nước vẫn ra hai E.164 khác nhau", () => {
    // Đây là lý do bộ chọn không phải thứ trang trí, và là cái bẫy im lặng
    // nhất của nó: `090 1234 5678` là số di động Nhật, NHƯNG cũng là một số
    // hợp lệ của Anh (dải 09 trả phí). Không có lỗi nào hiện ra — chỉ là DB
    // nhận `+449012345678` thay vì `+819012345678`, và POS tra số đó thì
    // không thấy khách nữa.
    assert.equal(validatePhoneForCountry("090 1234 5678", "JP").valid, true);
    assert.equal(validatePhoneForCountry("090 1234 5678", "GB").valid, true);
    assert.equal(toE164("090 1234 5678", "JP"), "+819012345678");
    assert.equal(toE164("090 1234 5678", "GB"), "+449012345678");
  });

  it("E.164 mang đúng mã vùng của nước đang chọn", () => {
    assert.equal(toE164("090 1234 5678", "JP"), "+819012345678");
    assert.equal(toE164("033 690 9454", "VN"), "+84336909454");
    assert.equal(toE164("07400 123456", "GB"), "+447400123456");
  });

  it("ô trống báo thiếu, không báo sai định dạng", () => {
    assert.equal(validatePhoneForCountry("", "JP").errorKey, "phoneRequired");
  });
});

describe("flagEmoji", () => {
  it("dựng cờ từ mã ISO hai chữ", () => {
    assert.equal(flagEmoji("JP"), "🇯🇵");
    assert.equal(flagEmoji("vn"), "🇻🇳");
    assert.equal(flagEmoji("GB"), "🇬🇧");
  });

  it("đầu vào không phải mã hai chữ thì trả rỗng", () => {
    assert.equal(flagEmoji("JPN"), "");
    assert.equal(flagEmoji("+81"), "");
  });
});
