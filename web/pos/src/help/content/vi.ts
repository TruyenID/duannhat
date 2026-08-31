/**
 * Hướng dẫn vận hành pos-web — tiếng Việt.
 *
 * Mỗi mục là MỘT màn hình / hộp thoại. Phần `setup` cố ý nói về những thứ nằm
 * NGOÀI pos-web (admin-web, HQ, chính sách thanh toán, workstation, thiết bị)
 * vì phần lớn "POS không làm được X" không phải lỗi mà là X đang tắt ở nơi khác.
 */

import type { HelpCatalogue } from "../types";

export const helpVi: HelpCatalogue = {
  // ──────────────────────────────────────────────────────────────────────────
  //  Trang
  // ──────────────────────────────────────────────────────────────────────────
  pairing: {
    title: "Kết nối thiết bị POS",
    subtitle: "ペアリング",
    purpose:
      "Màn hình đầu tiên khi máy này chưa được ghép nối với cửa hàng nào. Nhập mã 6 ký tự do trang quản trị tạo ra để đổi lấy device token — khoá mà POS gửi kèm mọi lệnh gọi về sau.",
    setup: [
      "admin-web → Cửa hàng → Thiết bị: tạo một thiết bị loại POS cho ĐÚNG chi nhánh, rồi bấm tạo mã ghép nối. Mã có 6 ký tự và sống 15 phút.",
      "Chi nhánh gắn với thiết bị quyết định cửa hàng mà POS mở ra sau khi ghép nối — không có màn chọn cửa hàng sau đó.",
      "Máy phải với tới được máy chủ: bản Cloud gọi thẳng backend; bản do workstation phục vụ (địa chỉ kết thúc bằng /pos/) gọi qua workstation rồi workstation chuyển tiếp lên Cloud.",
      "Toàn bộ thiết bị trong hệ thống (POS · kiosk · KDS · TMS · workstation) dùng CHUNG một cổng ghép nối. Không có cổng riêng cho POS.",
    ],
    usage: [
      "Mở admin-web ở máy khác, vào Thiết bị của chi nhánh và tạo mã ghép nối.",
      "Gõ 6 ký tự vào ô. Ô tự viết hoa và tự loại ký tự đặc biệt, nên không cần bận tâm hoa/thường.",
      "Bấm “Kết nối”. Thành công thì POS tự nhảy vào màn bán hàng của chi nhánh đó (hoặc màn mở ca nếu chưa có ca nào đang mở).",
    ],
    checks: [
      "Mã hết hạn sau 15 phút → báo “mã đã hết hạn”, phải quay lại admin-web tạo mã mới. Không có cách gia hạn.",
      "Mã sai hoặc đã dùng → báo “mã không hợp lệ”. Mỗi mã chỉ dùng được một lần.",
      "Token lưu trong localStorage VÀ cookie, hạn 1 năm. Xoá dữ liệu trình duyệt = phải ghép nối lại.",
      "Ghép nhầm chi nhánh thì phải thu hồi thiết bị ở admin-web rồi ghép lại — POS không tự đổi chi nhánh được.",
    ],
    glossary: [
      {
        term: "Mã ghép nối",
        description:
          "Chuỗi 6 ký tự, dùng một lần, sống 15 phút, do quản trị tạo cho từng thiết bị.",
      },
      {
        term: "device token",
        description:
          "Khoá Bearer sinh ra sau khi ghép nối. Khác với tài khoản người dùng: đăng xuất tài khoản không huỷ ghép nối thiết bị.",
      },
    ],
  },

  "pos-main": {
    title: "Màn hình bán hàng",
    subtitle: "POS メイン",
    purpose:
      "Bàn làm việc chính của thu ngân: dải tab đơn đang mở ở trên, thực đơn bên trái, giỏ hàng bên phải. Mỗi tab là một đơn độc lập nên có thể phục vụ nhiều bàn cùng lúc mà không mất ngữ cảnh.",
    setup: [
      "Phải có MỘT CA đang mở. Chưa mở ca thì POS tự chuyển sang màn レジ開け và không cho bán.",
      "HQ phải có thực đơn gắn lịch cho hôm nay, có sản phẩm đang bật. Không có thực đơn nào cho hôm nay thì lưới món trống.",
      "Bàn và khu vực khai ở admin-web; không có bàn thì vẫn bán được nhưng chỉ ở dạng đơn không bàn (spot/mang về).",
      "Đơn vị tiền, phí phục vụ, 総額表示 (giá đã gồm thuế), chế độ đặt-đơn-nhanh, ma trận huỷ món — tất cả nằm ở admin-web → Cửa hàng → Cài đặt đơn hàng. POS đọc lại mỗi 60 giây và ngay khi chuyển tab trình duyệt.",
      "Các tính năng LAN (in bếp, in bill, 釣銭機, máy quẹt thẻ P400) chỉ hiện khi máy này đã trỏ tới một workstation. Không có workstation thì các nút đó ẩn hẳn, không phải hỏng.",
    ],
    usage: [
      "Bấm “+” trên dải tab (hoặc chạm một bàn trống ở Tổng quan) để mở đơn mới.",
      "Chọn món ở lưới bên trái. Món có nhiều biến thể hoặc có nhóm topping sẽ mở hộp thoại tuỳ chọn; món đơn giản được thêm ngay một chạm.",
      "Kiểm giỏ bên phải: sửa số lượng, sửa topping, huỷ dòng, gán bàn, đổi số khách.",
      "Bấm “Tính tiền” để chốt đơn — lúc này mới hiện ô nhập mã giảm giá và bảng thuế/phí.",
      "Bấm “Thu tiền” (hoặc “Chia bill” nếu đơn có nhiều khách) để nhận tiền.",
    ],
    checks: [
      "Đóng tab (dấu ×) trên đơn ĐÃ CÓ MÓN là XOÁ CỨNG, không để lại vết kiểm toán. Muốn giữ vết thì dùng “Huỷ đơn” kèm lý do.",
      "Mọi hộp thoại đều reset khi đổi tab — chủ ý, để không thao tác nhầm sang đơn khác. Riêng màn biên lai sau thanh toán thì cố ý sống sót.",
      "Đổi ngôn ngữ ở thanh trên sẽ nạp lại tên món của các đơn đang mở theo ngôn ngữ mới.",
      "Mất mạng: đọc dữ liệu đã cache thì vẫn xem được, nhưng thu tiền / mở ca / đóng ca bị KHOÁ HẲN, không xếp hàng chờ. pos-web không bán offline — đó là vai của workstation.",
    ],
  },

  "tables-overview": {
    title: "Tổng quan bàn",
    subtitle: "テーブル一覧",
    purpose:
      "Sơ đồ toàn bộ bàn của cửa hàng, nhóm theo khu vực, kèm bốn con số nhanh: bàn đang phục vụ, đơn đang mở, tổng khách, ghế đang dùng. Chạm bàn đang phục vụ để mở đơn của nó; chạm bàn trống để mở đơn mới ngay tại bàn đó.",
    setup: [
      "Bàn và khu vực khai ở admin-web → Cửa hàng → Bàn. Bàn phải đang bật (is_active) thì mới chạm được.",
      "Danh sách khu vực chỉ hiện thành dải lọc khi cửa hàng có từ 2 khu vực trở lên.",
      "Ô “Mang về” luôn nằm ở ô đầu tiên vì đơn mang về không gắn bàn nên không bao giờ xuất hiện trên lưới này.",
    ],
    usage: [
      "Lọc theo khu vực bằng dải nút phía trên (nếu có).",
      "Chạm bàn màu hổ phách (đang phục vụ) để mở tab đơn của bàn đó.",
      "Chạm bàn trống để tạo đơn mới đã gán sẵn bàn đó.",
      "Dùng nút “⋯” góc trên mỗi bàn để xem lịch sử bàn, hoặc đổi trạng thái bàn.",
    ],
    checks: [
      "Chỉ đổi được trạng thái của bàn KHÔNG có đơn đang mở. Bàn đang phục vụ thì trạng thái do đơn quyết định — đóng đơn trước.",
      "Bàn “dọn dẹp” / “ngừng phục vụ” / “đã đặt trước” không chạm để tạo đơn được; hãy đổi về “trống” trước.",
      "“Đang phục vụ” nhưng không hiện mã đơn nghĩa là đơn đó không nằm trong trang 100 đơn đang mở gần nhất — mở qua dải tab hoặc lịch sử.",
    ],
  },

  takeaway: {
    title: "Đơn mang về",
    subtitle: "テイクアウト",
    purpose:
      "Danh sách các đơn mang về đang hoạt động. Đơn mang về không gắn bàn nên không thể hiện trên lưới bàn; tab này là lối vào duy nhất của chúng.",
    setup: [
      "Đơn vào đây khi được tạo với loại “Mang về”, hoặc do khách tự đặt từ customer-web / kiosk và chuyển về quầy.",
      "Thực đơn dành cho mang về khai ở HQ với loại phục vụ Takeaway. Thuế của món đi theo dòng thực đơn được chọn, nên chọn nhầm thực đơn là sai thuế.",
      "Nguồn dữ liệu là một luồng riêng (order_type=takeaway) để sàn ăn tại chỗ đông không đẩy đơn mang về ra khỏi danh sách chung.",
    ],
    usage: [
      "Chạm một thẻ đơn để mở tab đơn đó, xem giỏ và thu tiền như đơn thường.",
      "Đơn do khách gửi lên ở trạng thái chờ/đã xác nhận phải bấm “Tiếp nhận đơn” trong giỏ trước khi tính tiền.",
      "Có thể thêm món cho đơn đã xác nhận ngay tại quầy trước khi thu tiền.",
    ],
    checks: [
      "Số trên huy hiệu tab là số đơn mang về đang hoạt động, không phải số món.",
      "Đơn mang về không có bàn nên các thao tác ghép/đổi/tách bàn không áp dụng.",
    ],
  },

  "table-history": {
    title: "Lịch sử một bàn",
    subtitle: "テーブル履歴",
    purpose:
      "Toàn bộ đơn từng phát sinh ở MỘT bàn, nhóm theo ngày, kèm câu chuyện đầy đủ của đơn được chọn: mở lúc nào, thêm món lúc nào, món nào bị huỷ (lúc nào, vì sao) và đã thu tiền thế nào.",
    setup: [
      "Lịch sử đầy đủ do workstation phục vụ (nó giữ table_id bền và bảng ghép order-bàn). Không có workstation thì Cloud chỉ trả về đơn đang sống của bàn.",
      "Mở từ nút “⋯” trên thẻ bàn ở Tổng quan, mục “Xem lịch sử”.",
    ],
    usage: [
      "Chọn một đơn ở cột trái để xem chi tiết bên phải.",
      "Đọc mục thanh toán để biết phương thức, tiền khách đưa và tiền thối của từng lần thu.",
      "Bấm “Đóng” để quay lại màn hình trước.",
    ],
    checks: [
      "Đây là màn hình CHỈ ĐỌC — không sửa được đơn cũ từ đây.",
      "Nếu chỉ thấy đúng một đơn, gần như chắc chắn máy đang chạy chế độ Cloud chứ không qua workstation.",
    ],
  },

  "order-history": {
    title: "Lịch sử đơn hàng",
    subtitle: "注文履歴",
    purpose:
      "Lịch sử đơn của TOÀN cửa hàng theo ngày / tháng / năm, mọi trạng thái, nhóm theo ngày và gắn nhãn nơi bán (bàn nào, mang về, hay tại quầy). Cột phải kể chi tiết đơn được chọn — giống hệt màn lịch sử theo bàn.",
    setup: [
      "Vào từ biểu tượng đồng hồ lịch sử trên thanh trên cùng của POS.",
      "Chạy tốt nhất khi có workstation (LAN) — dữ liệu đầy đủ và nhanh; không có thì rơi về Cloud.",
    ],
    usage: [
      "Chọn mức thời gian: Ngày / Tháng / Năm.",
      "Dùng mũi tên trái–phải hoặc các ô chọn năm/tháng/ngày để đổi kỳ.",
      "Bấm “Xem thêm” ở cuối danh sách để nạp tiếp — danh sách nạp dần chứ không đổ hết một lần.",
      "Chọn một đơn để xem chi tiết ở cột phải.",
      "In giấy tờ của đơn đã chọn bằng dải nút dưới phần đầu: hoá đơn · hoá đơn đỏ · phiếu bếp · phiếu order.",
      "Hai chứng từ tiền có CẶP nút: “In gốc” cho tờ đầu tiên, “In lại” cho các tờ sau. Đúng một trong hai luôn bấm được.",
      "Bill chia: mỗi dòng ở mục Thanh toán có cặp nút RIÊNG của khách đó. Nút ở dải trên in tờ của khách thanh toán cuối cùng.",
    ],
    checks: [
      "Dòng “tổng đã thu” chỉ hiện khi đã nạp hết mọi trang của kỳ đang xem — nếu còn nút “Xem thêm” thì con số đó chưa đủ.",
      "Không đi tới được kỳ tương lai: nút “sau” bị khoá khi đã tới kỳ hiện tại.",
      "Đây là màn xem lại. Muốn đảo tiền thì làm ở màn thu tiền / hoàn tiền của chính đơn đó.",
      "Nút in chỉ có khi máy này đã ghép với workstation — máy in nằm ở LAN, không có đường in từ Cloud.",
      "Hoá đơn và hoá đơn đỏ chỉ hiện trên đơn đã hoàn tất và đã có tiền vào. Đơn đang phục vụ vẫn in lại được phiếu bếp và phiếu order.",
      "“In lại” bị xám nghĩa là chứng từ đó CHƯA từng in cho phạm vi này — bấm “In gốc”, ra đúng tờ giấy đó. In xong thì hai nút đổi vai.",
      "Chỉ nhánh “In lại” hỏi lý do, và lý do KHÔNG bắt buộc: để trống vẫn in được. Nó chỉ vào sổ nhật ký in.",
      "“In lại phiếu bếp” chỉ đẩy giấy — nó KHÔNG điều món lại, không đẩy đơn trở lại màn hình bếp.",
      "Từ bản in thứ hai, hoá đơn mang dấu “BẢN IN #N” và mỗi lượt in đều vào nhật ký. Giấy không miễn phí.",
    ],
  },

  revenue: {
    title: "Báo cáo doanh thu",
    subtitle: "売上レポート",
    purpose:
      "Ba góc nhìn về doanh thu: theo THỜI GIAN (biểu đồ ngày/tháng/năm kèm trung bình theo thứ và cơ cấu phương thức thanh toán), theo SẢN PHẨM (xếp hạng theo doanh thu hoặc số lượng, mức sản phẩm hoặc mức biến thể), và HUỶ (đơn huỷ / món huỷ, lý do, giá trị mất).",
    setup: [
      "Đơn vị tiền hiển thị lấy từ admin-web → Cài đặt đơn hàng → currency_code. Đây là hiển thị thuần, hệ thống không quy đổi tỷ giá.",
      "Phần lý do huỷ chỉ có ý nghĩa khi thương hiệu đã khai danh mục lý do huỷ ở HQ; nếu không, mọi dòng rơi vào lý do tự do.",
      "Cột danh mục ở tab Sản phẩm lấy từ loại/nhóm sản phẩm khai ở HQ.",
    ],
    usage: [
      "Chọn góc nhìn ở dải trên: Thời gian · Sản phẩm · Huỷ.",
      "Chọn mức Ngày/Tháng/Năm, hoặc bấm biểu tượng lịch để chọn khoảng tuỳ ý.",
      "Ở tab Sản phẩm: chọn mức Sản phẩm hay Biến thể (SKU), rồi chọn sắp xếp theo doanh thu hay theo số lượng.",
      "Dùng thanh phân trang dưới bảng để đi qua các trang; đổi số dòng mỗi trang nếu cần.",
    ],
    checks: [
      "Ngày làm việc theo múi giờ CHI NHÁNH, không theo múi giờ của máy hay của người đăng nhập. Quản lý ở Hà Nội mở báo cáo cửa hàng Tokyo sẽ thấy ngày làm việc của Tokyo.",
      "Đơn có mã tạm (chưa được Cloud cấp mã chính thức) được đánh dấu riêng — đó là đơn LAN chưa đồng bộ xong, không phải đơn hỏng.",
      "Số ở đây là doanh thu theo đơn. Đối soát tiền mặt theo CA thì đọc màn 精算, không đọc màn này.",
    ],
    glossary: [
      {
        term: "Doanh thu vs Đã thu",
        description:
          "Doanh thu tính theo đơn đã ghi nhận; “đã thu” là tiền thực nhận. Đơn treo (ghi nợ) làm hai con số lệch nhau.",
      },
      {
        term: "Giá trị mất do huỷ",
        description:
          "Tổng tiền của các dòng bị huỷ. Món huỷ sau khi bếp đã nấu vẫn tốn nguyên liệu — con số này không phản ánh chi phí đó.",
      },
    ],
  },

  settings: {
    title: "Cài đặt máy thu ngân",
    subtitle: "レジ設定",
    purpose:
      "Bật/tắt từng khối trên phiếu 精算 (phiếu kết ca in ra máy in nhiệt). Đây là những trường DUY NHẤT mà pos-web được phép ghi vào cài đặt cửa hàng; mọi cài đặt khác thuộc về admin-web.",
    setup: [
      "Các cài đặt còn lại (đơn vị tiền, phí phục vụ, giá đã gồm thuế, đặt-đơn-nhanh, ma trận huỷ món, thời điểm trừ kho) nằm ở admin-web → Cửa hàng → Cài đặt đơn hàng.",
      "Khối “Thuế theo từng mức” là một CHỐT KIỂM TOÁN: chỉ người dùng đã đăng nhập mới bật/tắt được. Máy chỉ có device token sẽ bị từ chối và hiện thông báo “chỉ quản lý mới đổi được”.",
      "Cần một máy in nhiệt gắn với workstation thì các khối này mới có chỗ để in. Không có máy in thì thiết lập vẫn lưu nhưng không thấy tác dụng.",
    ],
    usage: [
      "Gạt công tắc của khối muốn hiện hay ẩn trên phiếu. Công tắc nhảy ngay, việc lưu chạy nền.",
      "Thứ tự các khối trên màn này đúng thứ tự chúng in ra trên phiếu.",
      "Bấm “Quay lại” để về màn bán hàng.",
    ],
    checks: [
      "Chỉ ảnh hưởng PHIẾU IN NHIỆT. Bản PDF Z-report luôn có đầy đủ phần thuế theo mức, bất kể công tắc này.",
      "Lưu thất bại thì công tắc tự bật lại về giá trị máy chủ — nếu nó nhảy ngược, việc lưu đã hỏng chứ không phải bạn bấm nhầm.",
      "Cài đặt áp cho cả CỬA HÀNG, không riêng máy này. Đổi ở đây là đổi cho mọi máy POS của cửa hàng.",
    ],
  },

  "menu-availability": {
    title: "Tồn món",
    subtitle: "在庫切れ設定",
    purpose:
      "Tắt món đã hết ngay tại quán, và bật lại khi có hàng. Tắt ở đây là ẨN KHỎI MÀN BÁN — món biến mất khỏi danh sách chọn, khách và nhân viên không thấy nữa.",
    setup: [
      "Món nào có trong menu là do TỔNG CÔNG TY quyết, không đổi được ở đây. Màn này chỉ bật/tắt món đã có sẵn.",
      "GIÁ chỉ để xem, không sửa được tại đây. Muốn đổi giá thì vào admin-web → Cửa hàng → Menu, và cần quyền Quản lý trở lên.",
      "Ai cũng bật/tắt được — không cần quyền quản lý. Hết nguyên liệu là việc của người đứng bếp, không phải quyết định quản trị.",
      "Có workstation thì màn này CHẠY ĐƯỢC KHI MẤT MẠNG: ghi vào máy trạm trước, tự đẩy lên khi có mạng lại.",
      "Không có workstation (chạy thẳng lên cloud) thì mất mạng sẽ không bật/tắt được.",
    ],
    usage: [
      "Chọn menu ở ô trên cùng bên trái. Mọi menu của quán đều hiện, kể cả menu chưa tới giờ chạy.",
      "Gạt công tắc bên phải mỗi món để tắt hoặc bật. Tắt thì hiện ô chọn lý do; bật thì một chạm là xong.",
      "Bấm mũi tên để mở danh sách BIẾN THỂ (cỡ, loại) và tắt riêng từng biến thể — ví dụ hết cỡ lớn nhưng cỡ nhỏ vẫn bán.",
      "Hai nút ở đầu mỗi nhóm bật/tắt CẢ NHÓM. Nút tắt sẽ hỏi lý do và nói rõ sẽ ảnh hưởng bao nhiêu món.",
      "Bật “Chỉ hiện món đang tắt” để soát lại cuối ca xem còn món nào quên bật lại.",
    ],
    checks: [
      "TẮT MÓN KHÔNG HUỶ MÓN ĐÃ GỌI. Đơn đang mở ở máy khác giữ nguyên, bếp vẫn làm tiếp.",
      "Tắt CẢ MÓN thì mọi biến thể của nó tắt theo, dù công tắc riêng của biến thể vẫn đang bật.",
      "Lý do KHÔNG bị bắt lỗi độ dài. Chạm một nút gợi ý là đủ; gõ thêm hay không tuỳ bạn.",
      "Công tắc nhảy ngược lại = việc ghi đã hỏng, không phải bạn bấm nhầm.",
      "Quản lý sửa cùng lúc trên admin-web thì BẢN CỦA CLOUD THẮNG sau khi máy trạm đồng bộ xong.",
      "Máy POS khác cập nhật trong khoảng 15 giây; không cần tải lại trang.",
    ],
    glossary: [
      {
        term: "Món / Biến thể",
        description:
          "Món là “Phở bò”. Biến thể là “cỡ nhỏ”, “cỡ lớn”. Tắt món là tắt tất cả; tắt biến thể chỉ bỏ đúng cỡ đó.",
      },
      {
        term: "Tắt vs Xoá",
        description:
          "Tắt là tạm ẩn, bật lại lúc nào cũng được. Xoá món khỏi menu là việc của tổng công ty và không làm ở đây.",
      },
    ],
  },
  "shift-open": {
    title: "Mở ca",
    subtitle: "レジ開け",
    purpose:
      "Đếm tiền đầu ca và mở phiên thu ngân. Không có ca đang mở thì POS không cho bán — mọi khoản tiền đều phải thuộc về một ca để cuối ca đối soát được.",
    setup: [
      "Chi nhánh phải có một két (till) đã cấu hình, và bộ mệnh giá cho đúng đơn vị tiền. Mệnh giá khai ở admin-web → Cửa hàng → Cài đặt → Mệnh giá.",
      "Đơn vị tiền LẤY TỪ cài đặt đơn hàng của cửa hàng, không cho chọn tại đây — để mọi ca của cùng cửa hàng không lệch đơn vị.",
      "Danh sách nhân viên trong ô “Người mở ca” lấy từ nhân sự của cửa hàng; không nạp được thì vẫn mở ca bằng lựa chọn “Người khác” và gõ tay tên.",
      "Muốn in phiếu レジ開け thì cần workstation + máy in. Không có thì bỏ qua im lặng, ca vẫn mở bình thường.",
    ],
    usage: [
      "Kiểm tra thông tin ca: cửa hàng, thiết bị, người mở, thời điểm.",
      "Nếu có bảng đối soát khoản hụt (gap), tick những khoản thuộc về ca này và xác nhận đã giữ riêng tiền mặt.",
      "Đếm tiền trong két và nhập số tờ/số đồng cho từng mệnh giá. Tổng cộng hiện ở thanh dưới bảng.",
      "Ghi chú nếu cần, rồi bấm “Mở ca”.",
    ],
    checks: [
      "Phải nhập ít nhất một mệnh giá có số lượng > 0 mới bấm được nút mở ca.",
      "Một két chỉ có ĐÚNG MỘT ca mở tại một thời điểm. Nếu ca đã mở ở máy khác, màn này tự đưa bạn về màn bán hàng.",
      "Mất mạng thì nút mở ca bị KHOÁ. Một ca “mở offline” sẽ là ca ma, nên hệ thống không cho phép.",
      "Nếu ca này nối tiếp một ca bàn giao trước đó, hệ thống báo vị trí trong chuỗi. Vẫn phải đếm lại từ đầu — cố ý đếm mù, không hiện số của ca trước.",
    ],
    glossary: [
      {
        term: "Quỹ đầu ca",
        description: "Tổng tiền mặt đếm được lúc mở ca; là mốc để tính thừa/thiếu khi kết ca.",
      },
      {
        term: "Chuỗi ca",
        description:
          "Một dãy ca liên tiếp trên cùng một két, nối nhau bằng bàn giao và kết thúc bằng kết ca cuối.",
      },
    ],
  },

  "shift-close": {
    title: "Kết ca / Bàn giao ca",
    subtitle: "精算・引き継ぎ",
    purpose:
      "Đếm lại két, đối chiếu với số máy tính được, khai số liệu của máy quẹt thẻ, rồi kết ca. Đây là màn quyết định con số 過不足 (thừa/thiếu) của ca.",
    setup: [
      "Chỉ mở được khi có ca đang ở trạng thái mở hoặc đang kết. Ca đã bị đóng ở nơi khác sẽ đưa bạn về màn mở ca.",
      "Đơn vị tiền được CHỤP LẠI lúc mở ca và không đọc lại cài đặt cửa hàng — để quản trị đổi đơn vị giữa ca không làm hỏng đối soát.",
      "Danh mục loại tender (thẻ / QR / tiền điện tử / hạng mục tự khai) và các máy thanh toán đã đăng ký khai ở admin-web. Chưa khai thì chỉ hiện một khối chung.",
      "Ngưỡng dung sai thừa/thiếu lấy từ cấu hình két. Vượt ngưỡng thì bắt buộc phải nhập lý do.",
      "In phiếu 精算 cần workstation + máy in. Không có thì ca vẫn kết bình thường, chỉ không ra giấy.",
    ],
    usage: [
      "Đọc ba ô tổng ở đầu: tiền mặt đếm được, tiền mặt hệ thống tính, và chênh lệch.",
      "Đếm két và nhập theo mệnh giá. Tiền lẻ nhỏ hơn mệnh giá nhỏ nhất thì gõ vào ô “tiền lẻ / điều chỉnh”.",
      "Với từng máy thanh toán: nhập doanh thu và số huỷ cho từng loại tender, và nhập tổng theo phiếu tổng kết của máy.",
      "Nhập lý do cho mọi khối đang lệch quá dung sai; lệch tiền mặt thì ghi vào ô ghi chú kết ca.",
      "Bấm “Bàn giao ca” nếu ca sau tiếp tục cùng chuỗi, hoặc “Kết ca” nếu kết thúc chuỗi.",
    ],
    checks: [
      "BÀN GIAO và KẾT CA khác nhau: bàn giao kết toán ca này nhưng GIỮ chuỗi mở, ca sau đếm lại quỹ đầu ca; kết ca ĐÓNG chuỗi và in phiếu tổng hợp của cả chuỗi. Nút xác nhận trong hộp thoại gọi đúng tên việc bạn sắp làm — đọc nó.",
      "Còn khối nào lệch quá dung sai mà chưa có lý do thì cả hai nút đều bị khoá — đúng như máy chủ sẽ từ chối.",
      "“Lưu nháp” giữ lại số đã đếm; mở lại màn này sẽ nạp lại bản nháp đó. Chỉ ca đang ở trạng thái “đang kết” mới có nháp để nạp.",
      "Đơn CHƯA thanh toán không chặn kết ca — chúng tự trôi sang ca sau. Chỉ đơn đã thanh toán mới tính vào ca này.",
      "Mất mạng thì cả hai nút bị khoá: Cloud phải tính lại ảnh chụp uy quyền của ca.",
    ],
    glossary: [
      {
        term: "過不足 (thừa/thiếu)",
        description: "Tiền mặt đếm được trừ đi tiền mặt hệ thống tính. Âm là thiếu, dương là thừa.",
      },
      {
        term: "端末日計 (tổng theo phiếu máy)",
        description:
          "Con số in trên phiếu tổng kết ngày của chính máy quẹt thẻ. Nhập để đối chiếu với tổng bạn vừa khai.",
      },
      {
        term: "Nháp",
        description:
          "Bản lưu tạm số đã đếm, chưa kết ca. Ca chuyển sang trạng thái “đang kết” nhưng vẫn chưa chốt.",
      },
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Cài đặt cửa hàng (nằm ở admin-web, quyết định POS)
  // ──────────────────────────────────────────────────────────────────────────
  "shop-settings": {
    title: "Cài đặt cửa hàng ảnh hưởng tới POS",
    subtitle: "店舗設定 → POS",
    summary: "Bản đồ tổng: cài đặt nào ở đâu, ai đổi được, bao lâu POS thấy.",
    purpose:
      "Bản đồ của mọi thứ nằm NGOÀI pos-web nhưng quyết định pos-web hiển thị gì và cho làm gì. Gần như mọi câu “POS không làm được X” đều kết thúc ở một trong các nhóm dưới đây, chứ không phải ở một lỗi.",
    setup: [
      "Cài đặt đơn hàng (đặt-đơn-nhanh, trạng thái món mặc định, đơn vị tiền, phí phục vụ, thuế, huỷ món, trừ kho, ngôn ngữ phiếu in): admin-web → Cửa hàng → Cài đặt → thẻ “Đơn hàng”.",
      "Mệnh giá tiền và loại tender: cùng trang Cài đặt, thẻ “Mệnh giá” và “Loại tender”.",
      "Chính sách thanh toán (phương thức nào hiện ở màn thu tiền): admin-web → Cửa hàng → Cài đặt → Thanh toán (4 mục: Sở hữu · Kết nối · Tuỳ chọn · Thiết bị).",
      "Bàn và khu vực: admin-web → Cửa hàng → Bàn. Nhân sự: → Nhân viên.",
      "Thực đơn, sản phẩm, biến thể, topping, combo, loại thuế, khuyến mại, mã giảm giá, lý do huỷ: HQ (cấp thương hiệu), không phải cấp cửa hàng.",
    ],
    usage: [
      "Xác định triệu chứng thuộc nhóm nào rồi mở đúng mục hướng dẫn của nhóm đó (các nút ? ngay bên dưới màn hình này).",
      "Đổi xong ở admin-web thì quay lại POS — phần lớn cài đặt đơn hàng tự cập nhật trong vòng 60 giây, hoặc ngay khi bạn chuyển sang tab trình duyệt của POS.",
      "Nếu vẫn chưa thấy đổi, kiểm tra mình đang sửa ĐÚNG CHI NHÁNH — cài đặt là theo từng cửa hàng, không phải theo thương hiệu.",
    ],
    checks: [
      "POS đọc lại cài đặt đơn hàng mỗi 60 giây và mỗi lần tab được kích hoạt lại. Riêng chế độ đặt-đơn-nhanh được đọc lại NGAY tại thời điểm bấm “+”, nên nó có tác dụng tức thì.",
      "Ba thay đổi bị CHẶN khi còn ca đang mở, máy chủ trả lỗi 409: đổi đơn vị tiền, đổi chế độ giá đã-gồm-thuế, và đổi quy tắc làm tròn thuế. Kết ca xong mới đổi được — cố ý, vì đổi giữa ca làm hỏng đối soát.",
      "Một số cài đặt là ba trạng thái: “Theo HQ” (để trống) / Bật / Tắt. Để trống nghĩa là kế thừa thương hiệu, không phải là tắt.",
      "Cài đặt là của CỬA HÀNG, không phải của máy. Đổi một chỗ là đổi cho mọi máy POS của cửa hàng đó.",
      "Ngược lại, địa chỉ workstation và chế độ LAN/Cloud là của TỪNG MÁY và không nằm ở admin-web — chúng ở ngay trên POS, trong huy hiệu kết nối.",
    ],
  },

  "settings-order-flow": {
    title: "Luồng đơn & sửa món",
    subtitle: "注文フロー設定",
    summary: "Đặt đơn nhanh, trạng thái món khi thêm, quyền sửa/huỷ, trừ kho.",
    purpose:
      "Nhóm cài đặt quyết định đơn được tạo ra sao và thu ngân được sửa gì sau đó. Đây là nhóm hay bị hiểu nhầm là lỗi nhất, vì nó làm các nút biến mất chứ không báo gì.",
    setup: [
      "Tất cả ở admin-web → Cửa hàng → Cài đặt → thẻ “Đơn hàng”.",
    ],
    usage: [
      "ĐẶT ĐƠN NHANH (enable_quick_order): bật thì dấu “+” tạo ngay một đơn trống, bỏ qua hộp thoại tạo đơn. Tắt thì “+” mở hộp thoại để chọn loại đơn, bàn, số khách, số điện thoại.",
      "TRẠNG THÁI MÓN MẶC ĐỊNH (default_order_item_status): trạng thái mà mỗi món SINH RA khi được thêm vào đơn. Bốn giá trị: chờ chế biến · đang chế biến · sẵn sàng · đã phục vụ. Để trống = chờ chế biến.",
      "SỬA MÓN MỌI TRẠNG THÁI (allow_item_edit_any_status) và MA TRẬN HUỶ MÓN (item_voidable_statuses): quyết định dòng nào còn được huỷ. Ma trận là bản mới và thắng; cờ cũ chỉ dùng khi chưa khai ma trận.",
      "THỜI ĐIỂM TRỪ KHO (stock_deduction_timing): on_close (khi đóng đơn) · on_preparing (khi món chuyển sang đang chế biến) · on_add (ngay khi thêm món).",
      "CHUẨN BỊ TRƯỚC KHI THANH TOÁN (prep_before_payment) và TRẠNG THÁI BÀN SAU KHI THU (table_status_after_payment): cả hai là ba trạng thái, để trống = theo HQ.",
    ],
    checks: [
      "Cạm bẫy lớn nhất: đặt TRẠNG THÁI MÓN MẶC ĐỊNH khác “chờ chế biến” thì món vừa thêm đã KHÔNG SỬA ĐƯỢC NỮA. Sửa số lượng / ghi chú / topping là việc chỉ-khi-còn-chờ-chế-biến, nên nút −/+ và liên kết “sửa” sẽ không xuất hiện. Nó trông y như POS hỏng.",
      "Cài đặt đó cũng đổi cách trừ kho: với thời điểm on_preparing, chỉ những dòng SINH RA từ “đang chế biến” trở đi mới trừ ngay — đó là hình dạng dành cho quán không dùng màn hình bếp.",
      "Ma trận huỷ món luôn cho phép huỷ dòng “chờ chế biến”; các trạng thái khác là do bạn khai. Huỷ món đã nấu thì lệch kho, vì dòng bị huỷ được loại khỏi phần trừ nguyên liệu.",
      "Đặt-đơn-nhanh làm mất bước nhập SỐ KHÁCH và SỐ ĐIỆN THOẠI. Không có số khách thì không có nút chia bill; không có khách hàng thì không được trả thiếu. Cả hai vẫn bổ sung được sau trong giỏ.",
      "Trạng thái bàn sau khi thu chỉ nhận “trống” hoặc “đang dọn”. Chọn “đang dọn” thì bàn vừa thu tiền không chạm để mở đơn mới được cho tới khi có người đổi lại.",
    ],
  },

  "settings-money": {
    title: "Tiền, thuế và làm tròn",
    subtitle: "金額・税設定",
    summary: "Đơn vị tiền, phí phục vụ, giá đã gồm thuế, làm tròn thuế và chia bill.",
    purpose:
      "Nhóm quyết định mọi con số tiền mà thu ngân đọc lên cho khách. Ba trong số này bị KHOÁ khi còn ca đang mở, vì đổi giữa ca sẽ làm hỏng đối soát của chính ca đó.",
    setup: ["admin-web → Cửa hàng → Cài đặt → thẻ “Đơn hàng”, khu vực tiền & thuế."],
    usage: [
      "ĐƠN VỊ TIỀN (currency_code): chọn trong danh sách có sẵn. Nó quyết định định dạng số trên toàn POS, bộ nút tiền nhanh ở màn thu tiền, và bộ mệnh giá ở màn mở/kết ca. Đây là hiển thị thuần — hệ thống KHÔNG quy đổi tỷ giá.",
      "PHÍ PHỤC VỤ (service_charge_rate, 0–100%) và THUẾ CỦA PHÍ PHỤC VỤ (service_charge_tax_rate): đặt 0 thì dòng phí biến mất khỏi giỏ.",
      "GIÁ ĐÃ GỒM THUẾ (prices_include_tax, 総額表示): bật thì thẻ món và giỏ hiện giá 税込; tắt thì hiện 税抜 và thuế cộng thêm ở dưới.",
      "LOẠI THUẾ MẶC ĐỊNH (default_tax_type_id): mức thuế dùng khi món không có khai riêng.",
      "LÀM TRÒN THUẾ (tax_rounding_mode + tax_rounding_decimals): làm tròn / lên / xuống, và 0–3 chữ số thập phân. LÀM TRÒN CHIA BILL (split_bill_rounding_mode) là quy tắc riêng, không liên quan.",
    ],
    checks: [
      "Ba cài đặt bị CHẶN khi còn ca mở, máy chủ trả 409: đơn vị tiền, giá-đã-gồm-thuế, và làm tròn thuế. Phải kết ca trước.",
      "Đơn vị tiền của một ca được CHỤP LẠI lúc mở ca. Màn kết ca cố ý dùng bản chụp đó chứ không đọc lại cài đặt, nên dù ai đó đổi được giữa chừng thì đối soát vẫn đúng.",
      "Quy tắc làm tròn thuế được chụp cứng vào TỪNG ĐƠN lúc tạo. Đổi hôm nay không viết lại đơn hôm qua — và đó là lý do báo cáo cũ vẫn cộng đúng.",
      "Ở chế độ giá-đã-gồm-thuế, dòng “(trong đó thuế …)” dưới tổng là GHI CHÚ. Cộng nó vào tổng lần nữa là sai — tổng đã có thuế bên trong.",
      "Đổi đơn vị tiền KHÔNG đổi con số. 100.000 ₫ thành 100.000 ¥ chứ không thành giá trị tương đương.",
      "Mức thuế thật của một món đến từ DÒNG THỰC ĐƠN nó được thêm vào, không phải từ cài đặt này. Cài đặt chỉ là mức dự phòng.",
    ],
  },

  "settings-till": {
    title: "Két, mệnh giá và loại tender",
    subtitle: "レジ・金種設定",
    summary: "Mệnh giá đếm tiền, danh mục tender, ngưỡng dung sai thừa/thiếu.",
    purpose:
      "Nhóm dựng nên hai màn hình đếm tiền: mở ca và kết ca. Thiếu nhóm này thì bảng đếm trống và ca không mở được.",
    setup: [
      "MỆNH GIÁ: admin-web → Cửa hàng → Cài đặt → thẻ “Mệnh giá”. Khai theo từng đơn vị tiền, phân loại tiền giấy / tiền xu.",
      "LOẠI TENDER và NHÓM TENDER: cùng trang, thẻ “Loại tender”. Bốn nhóm hệ thống (tiền mặt · thẻ · QR · tiền điện tử) có sẵn; nhóm tự khai thì tự hiện thêm ở màn kết ca.",
      "KÉT (till) và NGƯỠNG DUNG SAI thừa/thiếu: cấu hình ở phần quản lý két của cửa hàng.",
      "MÁY THANH TOÁN đã đăng ký: admin-web → Cửa hàng → Cài đặt → Thanh toán → Thiết bị. Mỗi máy sinh một khối đối soát riêng ở màn kết ca.",
    ],
    usage: [
      "Khai đủ mệnh giá cho đúng đơn vị tiền của cửa hàng trước khi mở ca lần đầu.",
      "Bật các loại tender mà cửa hàng thật sự nhận — màn kết ca chỉ liệt kê những loại đang bật.",
      "Đặt ngưỡng dung sai theo thực tế quán. Vượt ngưỡng thì màn kết ca bắt nhập lý do và khoá nút cho tới khi có.",
    ],
    checks: [
      "Bảng mệnh giá trống thì màn MỞ CA không bấm được nút nào — nút yêu cầu ít nhất một mệnh giá có số lượng lớn hơn 0.",
      "Mệnh giá khai theo ĐƠN VỊ TIỀN. Đổi đơn vị tiền của cửa hàng mà quên khai bộ mệnh giá tương ứng là mở ca bị kẹt.",
      "Tiền lẻ nhỏ hơn mệnh giá nhỏ nhất không gõ vào bảng được — dùng ô “tiền lẻ / điều chỉnh” ở màn kết ca.",
      "Không khai máy thanh toán thì màn kết ca chỉ có MỘT khối đối soát chung. Đó là hành vi hợp lệ cho quán một máy, không phải thiếu sót.",
      "Ngưỡng dung sai đặt quá rộng thì lệch tiền thật lọt qua mà không ai phải giải trình.",
    ],
  },

  "settings-payments": {
    title: "Chính sách thanh toán",
    subtitle: "決済ポリシー",
    summary: "Quyết định ô phương thức nào hiện ra ở màn thu tiền.",
    purpose:
      "Nhóm quyết định thu ngân được bấm những ô nào ở màn thu tiền. Đây là câu trả lời cho “màn thu tiền báo chưa cấu hình phương thức nào”.",
    setup: [
      "admin-web → Cửa hàng → Cài đặt → Thanh toán, gồm bốn mục: Sở hữu (ai đứng tên) · Kết nối (cổng thanh toán) · Tuỳ chọn (bật/tắt từng phương thức) · Thiết bị (máy quẹt thẻ, máy đếm tiền).",
      "POS chỉ hiện những lựa chọn ĐANG HIỆU LỰC và được đánh dấu dùng được ở POS. Một lựa chọn bật ở cấp thương hiệu nhưng chưa hiệu lực ở cửa hàng thì không hiện.",
      "Tiền mặt và máy quẹt thẻ độc lập đến từ danh mục nội bộ, không cần cổng thanh toán nào.",
    ],
    usage: [
      "Mở mục Tuỳ chọn để bật/tắt từng phương thức cho cửa hàng.",
      "Xem khối xem-trước hiệu lực trên chính trang đó — nó cho biết POS sẽ thấy đúng những gì.",
      "Đăng ký máy quẹt thẻ ở mục Thiết bị nếu quán dùng máy vật lý.",
    ],
    checks: [
      "Phân biệt hai màn trống ở POS: lưới trống CÓ nút “thử lại” là lỗi kết nối, thu ngân tự xử được. Lưới trống KHÔNG có nút thử lại nghĩa là chính sách không trả về lựa chọn nào — việc của quản lý, không phải của thu ngân.",
      "Ô “Ghi nợ” cần một phương thức kiểu on_account đang bật. Thiếu nó thì nút ghi nợ báo chưa cấu hình.",
      "Ô yêu cầu nhập tiền khách đưa hay không là do chính lựa chọn đó khai, không phải do POS đoán.",
      "Máy quẹt thẻ và máy đếm tiền chỉ chạy khi máy POS này trỏ tới một workstation — chúng nằm trong LAN của quán, Cloud không có đường tới.",
      "Danh mục thương hiệu tender ở bước chọn phụ chỉ để quy nhóm khi đối soát; nó không đổi số tiền và không bao giờ chặn việc thu.",
    ],
  },

  "settings-printing": {
    title: "In ấn và phiếu",
    subtitle: "印刷設定",
    summary: "Ngôn ngữ phiếu, mã số thuế, các khối trên phiếu 精算, in tự động.",
    purpose:
      "Nhóm quyết định giấy in ra trông thế nào và bằng ngôn ngữ nào. Phần lớn nằm ở admin-web, riêng các khối của phiếu 精算 thì sửa được ngay trên POS.",
    setup: [
      "NGÔN NGỮ PHIẾU IN (print_label_locale): admin-web → Cửa hàng → Cài đặt → Đơn hàng. ja / en / vi, hoặc để trống để theo mặc định.",
      "MÃ SỐ THUẾ / 登録番号 người bán và công tắc hiện nó trên phiếu (show_seller_registration_on_receipt): khai ở cấp thương hiệu, cửa hàng ghi đè được.",
      "IN TỰ ĐỘNG: phiếu mở ca (print_shift_open_report) và phiếu khi thu xong một bàn (print_table_paid).",
      "CÁC KHỐI CỦA PHIẾU 精算: sửa ngay trên POS, ở chính màn hình này.",
      "MÁY IN: khai ở workstation. Không có workstation thì mọi nút in đều ẩn.",
    ],
    usage: [
      "Chọn ngôn ngữ phiếu in cho cửa hàng.",
      "Nhập mã số thuế người bán ở HQ hoặc ở cửa hàng, rồi bật công tắc hiện trên phiếu nếu cần.",
      "Bật/tắt in tự động theo thói quen của quán.",
      "Bật/tắt từng khối của phiếu 精算 ngay trên màn hình này.",
    ],
    checks: [
      "NGÔN NGỮ PHIẾU IN KHÔNG PHẢI ngôn ngữ giao diện POS. Đổi ngôn ngữ trên POS chỉ đổi màn hình; giấy in theo cài đặt riêng. Đây là chủ ý: nếu phiếu chạy theo máy nào gọi lệnh in thì máy A (tiếng Nhật) gửi bếp và máy B (tiếng Việt) in biên lai sẽ ra hai tờ gọi cùng một món bằng hai tên khác nhau, và nhân viên không khớp được lúc giao đồ.",
      "Thứ tự ưu tiên ngôn ngữ phiếu: lựa chọn riêng của chính workstation → cài đặt cửa hàng này → ngôn ngữ chi nhánh → mặc định. Máy trạm đứng TRÊN cùng, để một trạm lẻ sửa được mà không phải đổi cả cửa hàng.",
      "Các công tắc khối 精算 chỉ ảnh hưởng PHIẾU IN NHIỆT. Bản PDF Z-report luôn có đủ phần thuế theo mức.",
      "Khối thuế theo từng mức là chốt kiểm toán: chỉ người dùng đã đăng nhập mới bật/tắt được; máy chỉ có device token bị từ chối.",
      "Không nhập mã số thuế thì phiếu không có dòng đó, và hệ thống KHÔNG cảnh báo — hộ kinh doanh không đăng ký là hợp lệ.",
      "Phiếu thanh toán ghi tên người mua in THẲNG, không lưu bản ghi nào. Không có “sổ hoá đơn” để tra lại; huy hiệu “đã in ×N” là thứ duy nhất đếm được.",
    ],
  },

  "settings-catalog": {
    title: "Thực đơn, bàn và dữ liệu nền",
    subtitle: "カタログ・マスタ",
    summary: "Thực đơn + lịch, món và topping, thuế, khuyến mại, bàn, lý do huỷ, nhân sự.",
    purpose:
      "Nhóm dữ liệu nền dựng nên nội dung POS hiển thị. Khác các nhóm trên, phần lớn nằm ở HQ (cấp thương hiệu) chứ không ở cấp cửa hàng.",
    setup: [
      "THỰC ĐƠN + LỊCH (thứ trong tuần, khung giờ) và LOẠI PHỤC VỤ (店内 / 持ち帰り / dùng chung): HQ → Thực đơn.",
      "SẢN PHẨM, BIẾN THỂ, NHÓM TOPPING, COMBO: HQ → Sản phẩm.",
      "LOẠI THUẾ (10% / 8% / 0%) gắn vào dòng thực đơn, nhóm món, hoặc sản phẩm: HQ → Thuế.",
      "KHUYẾN MẠI (Happy Hour) và MÃ GIẢM GIÁ, kèm quy tắc không-cộng-dồn: HQ.",
      "LÝ DO HUỶ MÓN: HQ. Thiếu thì hộp thoại huỷ rơi về ô nhập tự do.",
      "BÀN và KHU VỰC: admin-web → Cửa hàng → Bàn. NHÂN SỰ: → Nhân viên (danh sách người mở ca).",
    ],
    usage: [
      "Xuất bản thực đơn cho hôm nay với khung giờ đúng — POS chỉ nạp thực đơn của ngày hiện tại.",
      "Gắn loại phục vụ cho từng thực đơn để đơn tại chỗ và đơn mang về thấy đúng bảng giá và đúng mức thuế.",
      "Gán loại thuế cho sản phẩm hoặc dòng thực đơn.",
      "Khai bàn theo khu vực để lưới bàn ở POS nhóm được.",
    ],
    checks: [
      "Không có thực đơn nào cho HÔM NAY thì lưới món trống. Đây là nguyên nhân phổ biến nhất của “POS không hiện món nào”.",
      "Mức thuế đi theo DÒNG THỰC ĐƠN mà món được thêm vào, và được chụp cứng vào đơn. Đổi loại đơn sau đó KHÔNG tính lại thuế — chọn nhầm thực đơn là sai thuế trên hoá đơn thật.",
      "Sản phẩm không được gán loại thuế thì thẻ món không có nhãn 税込/税抜. Nhãn vắng nghĩa là chưa gán, không phải bằng không.",
      "Khối “nổi bật” (khuyến mại có khung giờ) do WORKSTATION tính theo đồng hồ quán. Máy chạy Cloud thuần sẽ không thấy — bình thường, không phải lỗi.",
      "Bàn phải đang bật thì mới chạm được ở POS. Bàn ngừng phục vụ hiện mờ.",
      "Không có lý do huỷ nào ở HQ thì mọi lượt huỷ rơi vào ô tự do, và báo cáo huỷ mất phần thống kê theo lý do.",
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Panel trong trang
  // ──────────────────────────────────────────────────────────────────────────
  "menu-catalog": {
    title: "Thực đơn",
    subtitle: "メニュー",
    purpose:
      "Lưới món để thêm vào đơn đang mở, kèm ô tìm kiếm, ô chọn thực đơn và dải nhảy nhanh tới từng nhóm món.",
    setup: [
      "Thực đơn, nhóm món, sản phẩm, biến thể và nhóm topping khai ở HQ. Lịch chạy của thực đơn (thứ trong tuần + khung giờ) cũng khai ở đó.",
      "POS chỉ nạp thực đơn của HÔM NAY, và lọc theo loại phục vụ của đơn đang mở: đơn tại chỗ chỉ thấy thực đơn 店内 (hoặc dùng chung), đơn mang về chỉ thấy 持ち帰り.",
      "Hệ thống tự chọn thực đơn theo khung giờ hiện tại. Với đơn “tại quầy”, việc tự chọn cố ý KHÔNG rơi vào thực đơn mang về — muốn dùng thì chọn tay.",
      "Giá hiện là 税込 hay 税抜 do cài đặt “giá đã gồm thuế” của cửa hàng quyết định (admin-web → Cài đặt đơn hàng).",
      "Khối “nổi bật” (khuyến mại có khung giờ) do WORKSTATION tính theo đồng hồ cửa hàng. Máy chạy Cloud thuần sẽ không thấy khối này — đó là bình thường.",
    ],
    usage: [
      "Chọn thực đơn ở ô bên phải nếu muốn khác với thực đơn được chọn tự động.",
      "Gõ từ khoá rồi Enter (hoặc bấm kính lúp) để tìm món.",
      "Dùng dải nhóm món phía trên để nhảy tới nhóm; dải này cũng tự sáng theo nhóm đang xem.",
      "Chạm thẻ món để thêm. Món nhiều biến thể hoặc có topping sẽ mở hộp thoại tuỳ chọn trước.",
    ],
    checks: [
      "Không có tab đơn nào đang mở, hoặc đơn đã qua bước tính tiền, thì lưới bị khoá — mở/chọn đơn trước.",
      "Thuế của món đi theo DÒNG THỰC ĐƠN được chọn và được chụp cứng vào đơn. Đổi loại đơn sau đó KHÔNG tính lại thuế. Chọn nhầm thực đơn = sai thuế.",
      "Nhãn 税込 / 税抜 dưới giá chỉ hiện khi món có mức thuế đã xác định. Không thấy nhãn nghĩa là chưa gán loại thuế ở HQ.",
      "Món khuyến mại (Happy Hour) hiện giá gạch ngang. Nếu đơn đã gắn mã giảm giá, thêm món khuyến mại sẽ bị chặn và hỏi lại.",
    ],
  },

  "order-cart": {
    title: "Giỏ hàng & chốt đơn",
    subtitle: "カート",
    purpose:
      "Nội dung đơn đang mở, thao tác trên bàn/khách, bảng thuế–phí, và hai nút đưa đơn đi tiếp: “Tính tiền” rồi “Thu tiền”.",
    setup: [
      "Phí phục vụ (%) và mức thuế phí phục vụ khai ở admin-web → Cài đặt đơn hàng. Đặt 0 thì dòng phí không hiện.",
      "Cách hiện giá (税込/税抜) và đơn vị tiền cũng từ cài đặt đó.",
      "Quyền huỷ MÓN theo từng trạng thái do ma trận huỷ món của cửa hàng quyết định. Mặc định chỉ huỷ được món còn “chờ chế biến”.",
      "Danh mục lý do huỷ khai ở HQ. Không có thì hộp thoại huỷ rơi về ô nhập lý do tự do.",
      "Nút “In phiếu order” và nút tròn “Gửi bếp” chỉ hiện khi máy đã trỏ tới workstation có máy in.",
      "Mã giảm giá và khuyến mại khai ở HQ.",
    ],
    usage: [
      "Gán bàn / ghép bàn / đổi bàn / tách bàn bằng hàng nút dưới mã đơn; sửa số khách bằng bút chì cạnh biểu tượng người.",
      "Trên từng dòng: đổi số lượng bằng −/+, sửa topping bằng liên kết “sửa”, huỷ dòng bằng nút thùng rác, đổi trạng thái chế biến bằng viên nhãn trạng thái.",
      "Bấm “Tính tiền” — bảng thuế/phí mở ra và hiện ô nhập mã giảm giá.",
      "Nhập mã giảm giá nếu có, rồi bấm “Chốt đơn”.",
      "Bấm “Thu tiền”, hoặc “Chia bill” khi đơn có nhiều hơn một khách.",
    ],
    checks: [
      "Sửa số lượng / ghi chú / topping CHỈ làm được khi dòng còn “chờ chế biến”. Khi bếp đã nhận, cách duy nhất là huỷ-có-lý-do rồi thêm dòng mới.",
      "ĐỔI BIẾN THỂ là không thể sửa tại chỗ: hệ thống huỷ dòng cũ và thêm dòng mới.",
      "Món bị huỷ không biến mất — nó chuyển sang trạng thái “đã huỷ” và ẩn đi; bấm dòng chữ dưới danh sách để xem lại.",
      "Huỷ món ĐÃ NẤU sẽ làm lệch kho, vì món huỷ bị loại khỏi phần trừ nguyên liệu.",
      "Ở chế độ 総額表示, dòng “(trong đó thuế …)” dưới tổng chỉ là ghi chú — ĐỪNG cộng nó vào tổng lần nữa.",
      "Dòng “điều chỉnh làm tròn” xuất hiện khi thuế có phần lẻ dưới đơn vị tiền nhỏ nhất; nó làm cột số cộng đúng bằng tổng phải trả.",
    ],
    glossary: [
      {
        term: "Tính tiền vs Thu tiền",
        description:
          "“Tính tiền” chốt đơn và khoá việc thêm món; “Thu tiền” mới là nhận tiền của khách.",
      },
      {
        term: "Phí phục vụ (税込)",
        description:
          "Dòng phí luôn hiện đã gồm thuế của chính nó. Bấm vào nhãn để mở phần tách 税抜 + thuế.",
      },
    ],
  },

  "pos-tabs": {
    title: "Dải tab đơn",
    subtitle: "注文タブ",
    purpose:
      "Mỗi tab là một đơn đang mở. Hai tab đầu được ghim: “Tổng quan” (lưới bàn) và “Mang về”. Dấu “+” mở đơn mới.",
    setup: [
      "Nếu cửa hàng bật chế độ ĐẶT ĐƠN NHANH (admin-web → Cài đặt đơn hàng), dấu “+” bỏ qua hộp thoại và tạo ngay một đơn trống. Tắt thì “+” mở hộp thoại tạo đơn.",
      "POS đọc lại cài đặt này ngay tại thời điểm bấm “+”, nên quản lý đổi ở admin-web là có tác dụng ngay, không cần tải lại POS.",
    ],
    usage: [
      "Chạm tab để chuyển sang đơn đó.",
      "Chạm “+” để mở đơn mới.",
      "Chạm dấu × trên tab để đóng tab.",
    ],
    checks: [
      "Nhãn tab luôn là MÃ ĐƠN, không phải tên bàn — để nó không đổi khi ghép/đổi bàn giữa chừng.",
      "Đóng tab của đơn ĐÃ CÓ MÓN sẽ hỏi lại, vì đó là xoá cứng không để lại vết. Muốn có vết kiểm toán thì “Huỷ đơn”.",
      "Tab đang có màn thu tiền hoặc màn biên lai mở sẽ được GHIM lại, để tín hiệu “đã thanh toán” từ workstation không đóng mất luồng đang dở.",
      "Đơn hiện “đang cấp mã” là đơn tạo ở LAN chưa nhận mã chính thức từ Cloud. Chờ đồng bộ, không phải lỗi.",
    ],
  },

  connection: {
    title: "Kết nối LAN / Cloud",
    subtitle: "接続",
    purpose:
      "Cho biết mọi lệnh gọi của POS đang đi qua workstation trong cửa hàng (LAN) hay đi thẳng lên máy chủ (Cloud), và cho phép đổi hướng ngay giữa ca.",
    setup: [
      "Chế độ mặc định phụ thuộc bản dựng: bản mở từ Internet mặc định Cloud; bản do workstation phục vụ (địa chỉ .../pos/) mặc định LAN, vì lúc đó workstation chính là nơi đã gửi trang này.",
      "Mỗi cửa hàng chạy workstation trên cổng riêng do người vận hành chọn, nên địa chỉ workstation KHÔNG nằm trong bản dựng — phải nhập tại đây và lưu theo từng máy.",
      "Không có workstation thì mọi tính năng LAN (in bếp, in bill, 釣銭機, máy quẹt thẻ) ẩn hẳn. Đây là im lặng có chủ ý, không phải hỏng.",
      "Trang mở qua HTTPS từ Internet KHÔNG gọi được thiết bị LAN chạy HTTP — trình duyệt chặn. Cửa hàng nhiều máy nên mở POS từ chính workstation.",
    ],
    usage: [
      "Bấm huy hiệu kết nối trên thanh trên cùng để mở bảng.",
      "Nhập/sửa địa chỉ workstation (ví dụ http://192.168.1.50:8080) rồi lưu.",
      "Chọn chế độ: Tự động · Workstation · Cloud.",
      "Bấm “Kiểm tra kết nối” để thử ngay; hai bộ đếm LAN/Cloud bên dưới cho biết thực tế lệnh gọi đang đi đường nào.",
    ],
    checks: [
      "Chế độ TỰ ĐỘNG sẽ rơi về Cloud sau khi workstation lỗi mạng, và chờ 30 giây mới thử lại. Trên bản do workstation phục vụ, rơi về Cloud là rơi ra ngoài Internet — cửa hàng có thể không có.",
      "Cloud KHÔNG phục vụ một số đường chỉ có ở workstation (máy quẹt thẻ, một phần dữ liệu két). Chọn Cloud khi đang cần chúng sẽ ra lỗi 404.",
      "Địa chỉ workstation lưu theo TỪNG MÁY, không đồng bộ sang máy POS khác.",
      "Trên bản do workstation phục vụ, địa chỉ đã lưu bị bỏ qua — nơi phục vụ trang chính là workstation.",
    ],
    glossary: [
      {
        term: "LAN",
        description:
          "Đi qua workstation đặt trong cửa hàng. Nhanh, chạy được cả khi mất Internet, và là con đường duy nhất tới máy in và thiết bị.",
      },
      {
        term: "Cloud",
        description:
          "Đi thẳng lên máy chủ qua Internet. Luôn có nếu còn mạng, nhưng không với tới được thiết bị trong cửa hàng.",
      },
    ],
  },

  "gap-reconcile": {
    title: "Đối soát khoản hụt giữa hai ca",
    subtitle: "ギャップ精算",
    purpose:
      "Trong khoảng từ lúc ca trước kết đến lúc ca này mở, cửa hàng vẫn có thể thu tiền. Những khoản đó chưa thuộc ca nào. Bảng này liệt kê chúng để thu ngân xác nhận khoản nào thuộc về ca mới.",
    setup: [
      "Chỉ hiện khi thực sự có khoản tiền rơi vào khoảng trống đó. Không có thì bảng không xuất hiện — bình thường.",
      "Không có hàng đợi tự động chuyển: hệ thống cố ý bắt con người xác nhận, vì chỉ con người biết tiền mặt đang nằm ở đâu.",
    ],
    usage: [
      "Đọc danh sách, tick những khoản thuộc về ca sắp mở.",
      "Nếu có tick khoản TIỀN MẶT, phải tick thêm xác nhận “đã giữ riêng tiền mặt” thì mới mở ca được.",
      "Bỏ qua bảng này (không tick gì) cũng hợp lệ — các khoản sẽ tiếp tục chờ.",
    ],
    checks: [
      "Chỉ tick khoản tiền mặt khi tiền THẬT SỰ được giữ riêng, không gộp vào quỹ đầu ca. Tick sai làm ca này thừa tiền và ca trước thiếu tiền.",
      "Việc tick không tạo ra hay xoá đi khoản tiền nào — nó chỉ nói khoản đó thuộc ca nào.",
    ],
  },

  "unresolved-orders": {
    title: "Đơn còn treo tiền từ ca trước",
    subtitle: "未精算伝票",
    purpose:
      "Những đơn vẫn đang thanh toán hoặc checkout khi ca trước đóng. Khác với khoản gap: tiền này có thể chưa bao giờ vào. Thu ngân phải thu phần còn thiếu, ghi nợ, hoặc huỷ có lý do — không gắn vào ca này.",
    setup: [
      "Chỉ hiện khi có ít nhất một đơn paying/checkout sinh trước lúc ca trước đóng. Không có thì bảng không xuất hiện — bình thường.",
      "Đơn mà bàn đã trống là đơn mồ côi — sơ đồ bàn không hiện nó. Vì vậy danh sách này đi từ đơn, không từ bàn.",
    ],
    usage: [
      "Đọc từng dòng: mã đơn, trạng thái, số còn thiếu.",
      "Mở đơn trên sơ đồ (hoặc tìm theo mã) rồi thu phần còn lại, hoặc ghi nợ.",
      "Mở ca không bị chặn. Danh sách là cảnh báo, không phải cổng.",
    ],
    checks: [
      "Đừng coi các dòng này là khoản gap. Gắn chúng vào ca này là ghi tiền chưa bao giờ thu.",
      "Huy hiệu “bàn đã nhả” nghĩa là bàn đã trống — tìm theo mã đơn, đừng đi quanh quán.",
    ],
  },

  "shift-gate-error": {
    title: "Không mở được cửa hàng",
    subtitle: "接続エラー",
    purpose:
      "Màn chặn khi POS không đọc được trạng thái két của cửa hàng. Có hai nhóm nguyên nhân rất khác nhau: sai ngữ cảnh cửa hàng (404/403) hoặc không với tới được đích đang chọn (lỗi mạng).",
    setup: [
      "404 = mã cửa hàng không tồn tại. 403 = thiết bị này không có quyền với cửa hàng đó. Cả hai phải sửa ở admin-web, đổi LAN/Cloud không cứu được.",
      "Không kèm mã lỗi = lỗi mạng: hoặc Cloud đang không với tới được, hoặc địa chỉ workstation đã sai (cửa hàng đổi cổng chẳng hạn).",
    ],
    usage: [
      "Bấm “Thử lại” trước.",
      "Nếu là lỗi mạng, dùng nút chuyển sang phía còn lại (Workstation ↔ Cloud).",
      "Đang ở chế độ workstation thì sửa lại địa chỉ ngay trong ô bên dưới rồi thử lại.",
    ],
    checks: [
      "Màn này cố ý chặn hẳn — không thể đi tiếp vào bán hàng khi chưa biết cửa hàng có ca hay không.",
      "Nút “Thử lại” đơn thuần gọi lại đúng địa chỉ cũ. Nếu địa chỉ sai thì phải sửa địa chỉ, thử lại bao nhiêu lần cũng vậy.",
    ],
  },

  "shift-expired": {
    title: "Ca đã kết thúc",
    subtitle: "シフト終了",
    purpose:
      "Thông báo chặn khi ca đang mở biến mất giữa chừng — bị hết hạn theo lịch, bị kết ở máy khác, hoặc bị quản lý huỷ. Bắt buộc bấm xác nhận, không tự chuyển trang.",
    setup: [
      "Hệ thống có tác vụ theo giờ tự cho hết hạn các ca bị bỏ quên. Quản lý cũng có thể ép huỷ hoặc kết tay một ca đã hết hạn.",
      "Ngưỡng bỏ quên và quyền ép huỷ cấu hình phía máy chủ, không sửa được từ POS.",
    ],
    usage: [
      "Đọc thông báo, hoàn tất việc đang cầm trên tay (ghi lại số vừa đếm nếu cần).",
      "Bấm xác nhận để về màn mở ca.",
    ],
    checks: [
      "Không đóng được bằng phím Esc hay bấm ra ngoài — cố ý, để việc mất ngữ cảnh không diễn ra âm thầm.",
      "Việc đang gõ dở trên màn nền KHÔNG được lưu. Ghi lại trước khi bấm xác nhận.",
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Hộp thoại dựng đơn
  // ──────────────────────────────────────────────────────────────────────────
  "create-order": {
    title: "Tạo đơn mới",
    subtitle: "新規注文",
    purpose:
      "Mở một đơn. Mọi ô đều KHÔNG bắt buộc — chỉ những ô bạn điền mới được gửi đi, nên đơn có thể từ “tại chỗ có bàn có khách” tới “đơn trống hoàn toàn”.",
    setup: [
      "Danh sách bàn và khu vực lấy từ admin-web → Cửa hàng → Bàn.",
      "Nhập số điện thoại sẽ tìm-hoặc-tạo khách hàng và gắn vào đơn. Chỉ đơn CÓ khách mới được phép trả thiếu (ghi nợ) sau này.",
      "Nếu cửa hàng bật đặt-đơn-nhanh thì hộp thoại này không mở ra nữa — “+” tạo đơn trống luôn.",
    ],
    usage: [
      "Chọn loại đơn: Tại quầy · Tại chỗ · Mang về.",
      "Chọn một hoặc nhiều bàn (bỏ trống nếu là đơn không bàn).",
      "Nhập số khách nếu biết — cần nó thì mới hiện nút Chia bill sau này.",
      "Nhập số điện thoại khách nếu cần ghi nợ hoặc tra cứu về sau.",
      "Bấm “Tạo đơn”.",
    ],
    checks: [
      "Số khách quyết định việc CHIA BILL: đơn không có số khách > 1 sẽ không thấy nút chia bill ở bước thu tiền.",
      "Không nhập điện thoại = đơn khách vãng lai, và khách vãng lai BẮT BUỘC trả đủ, không được ghi nợ.",
      "Loại đơn quyết định thực đơn nào hiện ra ở lưới món, mà thực đơn lại quyết định mức thuế. Chọn đúng ngay từ đầu.",
      "Hệ thống nhớ khu vực của bàn bạn chọn lần trước và mở sẵn khu vực đó ở lần tạo đơn sau.",
    ],
  },

  "product-options": {
    title: "Tuỳ chọn món",
    subtitle: "オプション選択",
    purpose:
      "Chọn biến thể (size / loại) và topping cho một món trước khi thêm vào đơn. Cũng là hộp thoại dùng để SỬA một dòng đã có trong giỏ.",
    setup: [
      "Biến thể và nhóm topping khai ở HQ theo từng sản phẩm; số lượng chọn tối thiểu/tối đa của mỗi nhóm cũng khai ở đó.",
      "Món dạng combo (loại sản phẩm “combo”) hiện huy hiệu riêng và số nhóm bắt buộc phải chọn.",
      "Món chỉ có một biến thể và không có nhóm topping sẽ KHÔNG mở hộp thoại này — chạm một cái là thêm ngay.",
      "Giá hiện ở đây theo đúng chế độ 税込/税抜 của cửa hàng.",
    ],
    usage: [
      "Chọn biến thể.",
      "Chọn topping theo từng nhóm; nhóm bắt buộc sẽ không cho gửi khi chưa đủ.",
      "Nhập ghi chú cho bếp nếu cần.",
      "Xem lại giá tạm tính ở khung bên phải rồi bấm thêm vào đơn.",
    ],
    checks: [
      "Ở chế độ SỬA: chỉ sửa được dòng còn “chờ chế biến”. Đổi BIẾN THỂ sẽ thành huỷ dòng cũ + thêm dòng mới, chứ không sửa tại chỗ.",
      "Topping dạng “bỏ bớt” hiện dấu − và không cộng tiền; topping dạng “thêm” có thể cộng tiền.",
      "Ghi chú là chữ tự do in ra phiếu bếp — không phải lệnh cho hệ thống, không đổi giá hay thuế.",
    ],
  },

  "assign-table": {
    title: "Gán bàn",
    subtitle: "テーブル割当",
    purpose:
      "Gắn một hoặc nhiều bàn vào một đơn CHƯA có bàn nào (đơn nổi). Dùng khi tạo đơn trước rồi mới xếp chỗ cho khách.",
    setup: [
      "Chỉ bàn đang trống mới chọn được; bàn đang phục vụ / dọn dẹp / ngừng phục vụ hiện mờ.",
      "Bàn khai ở admin-web → Cửa hàng → Bàn.",
    ],
    usage: [
      "Chọn khu vực nếu cần, rồi tick một hoặc nhiều bàn.",
      "Bấm xác nhận. Đơn được ghi lại với đúng danh sách bàn đã chọn.",
    ],
    checks: [
      "Chỉ dùng cho đơn CHƯA có bàn. Đơn đã có bàn thì dùng Ghép bàn / Đổi bàn / Tách bàn.",
      "Đơn đã qua bước tính tiền thì không gán bàn được nữa.",
    ],
  },

  "change-table": {
    title: "Đổi bàn",
    subtitle: "テーブル移動",
    purpose:
      "Chuyển đơn từ bàn hiện tại sang một bàn khác. Chỉ dùng được khi đơn đang gắn ĐÚNG MỘT bàn.",
    setup: ["Bàn đích phải đang trống."],
    usage: [
      "Chọn bàn đích.",
      "Bấm xác nhận. Hệ thống ghép bàn mới rồi tách bàn cũ, theo đúng thứ tự đó.",
    ],
    checks: [
      "Việc đổi bàn gồm HAI bước. Nếu bước tách bàn cũ hỏng, đơn sẽ tạm nằm ở cả hai bàn và giỏ hiện băng cảnh báo kèm nút thử lại — hãy bấm thử lại, đừng bỏ qua.",
      "Đơn đang gắn từ 2 bàn trở lên thì nút này bị khoá; dùng Tách bàn trước.",
    ],
  },

  "merge-table": {
    title: "Ghép bàn",
    subtitle: "テーブル結合",
    purpose:
      "Thêm bàn vào một đơn đã có bàn — dùng khi nhóm khách đông thêm và cần nối bàn bên cạnh vào cùng một hoá đơn.",
    setup: ["Bàn muốn ghép phải đang trống."],
    usage: [
      "Tick các bàn muốn ghép thêm.",
      "Bấm xác nhận. Hệ thống ghép lần lượt từng bàn.",
    ],
    checks: [
      "Ghép chạy tuần tự. Hỏng giữa chừng thì các bàn đã ghép xong vẫn giữ nguyên — đọc lại danh sách bàn trên giỏ trước khi thử lại.",
      "Ghép bàn KHÔNG gộp hai đơn: nó chỉ thêm bàn vào đơn hiện tại.",
    ],
  },

  "unmerge-table": {
    title: "Tách bàn",
    subtitle: "テーブル分離",
    purpose: "Gỡ một hoặc nhiều bàn khỏi một đơn đang gắn từ 2 bàn trở lên.",
    setup: ["Chỉ hiện khi đơn đang gắn ít nhất 2 bàn."],
    usage: ["Tick các bàn muốn gỡ.", "Bấm xác nhận."],
    checks: [
      "Không thể gỡ HẾT bàn: đơn tại chỗ phải còn ít nhất một bàn. Giao diện đã chặn sẵn nên không chọn được tất cả.",
      "Gỡ chạy tuần tự; hỏng giữa chừng thì phần đã gỡ vẫn giữ nguyên.",
    ],
  },

  "guest-count": {
    title: "Số khách",
    subtitle: "人数",
    purpose:
      "Nhập hoặc sửa số khách của đơn. Con số này không chỉ để thống kê — nó mở khoá tính năng chia bill.",
    usage: ["Nhập số khách.", "Bấm xác nhận."],
    checks: [
      "Số khách phải LỚN HƠN 1 thì nút “Chia bill” mới hiện ở bước thu tiền.",
      "Số khách cũng là số phần chia gợi ý sẵn khi mở màn chia đều.",
      "Đơn đã qua bước tính tiền thì không sửa được số khách nữa.",
    ],
  },

  "void-item": {
    title: "Huỷ món",
    subtitle: "商品取消",
    purpose:
      "Ghi nhận lý do rồi huỷ MỘT dòng trong giỏ. Dòng không bị xoá mà chuyển sang trạng thái “đã huỷ” và vẫn nằm trong lịch sử.",
    setup: [
      "Danh mục lý do huỷ khai ở HQ. Lý do được chọn quyết định ảnh hưởng tới kho ở phía máy chủ.",
      "Không có danh mục (hoặc không nạp được) thì hộp thoại tự rơi về ô nhập lý do tự do — vẫn huỷ được bình thường.",
      "Trạng thái nào được phép huỷ do MA TRẬN HUỶ MÓN của cửa hàng quyết định (admin-web → Cài đặt đơn hàng). Trạng thái “chờ chế biến” thì luôn huỷ được.",
    ],
    usage: [
      "Chọn lý do trong danh sách.",
      "Lý do nào yêu cầu ghi chú thì phải nhập thêm ghi chú.",
      "Bấm xác nhận huỷ.",
    ],
    checks: [
      "Dòng đã huỷ là TRẠNG THÁI CUỐI — không khôi phục được. Cần lại món thì thêm dòng mới.",
      "Huỷ món ĐÃ NẤU làm lệch kho: món huỷ bị loại khỏi phần trừ nguyên liệu, trong khi nguyên liệu đã dùng thật.",
      "Lý do quấy quá (gõ cho có) sẽ bị máy chủ từ chối.",
    ],
  },

  "void-order": {
    title: "Huỷ đơn",
    subtitle: "注文取消",
    purpose:
      "Huỷ toàn bộ đơn kèm lý do. Đây là cách đúng để bỏ một đơn đã có phát sinh, vì nó để lại vết kiểm toán.",
    usage: [
      "Nhập lý do huỷ (tối thiểu 10 ký tự).",
      "Bấm xác nhận.",
    ],
    checks: [
      "Không hoàn tác được.",
      "Khác với đóng tab: đóng tab là XOÁ CỨNG không để lại vết; huỷ đơn giữ lại đơn với trạng thái đã huỷ và lý do.",
      "Đơn đã thu tiền thì không huỷ như thế này được — phải đi đường hoàn tiền.",
    ],
  },

  "close-tab": {
    title: "Đóng tab đơn",
    subtitle: "タブを閉じる",
    purpose:
      "Nút ✕ trên tab chỉ dọn màn hình: nó gỡ tab đi và KHÔNG đụng gì tới đơn. Đơn ở lại trạng thái mở, không huỷ, không xoá. Muốn kết thúc đơn thì dùng nút “Huỷ đơn” trong giỏ — đó mới là chỗ ghi lý do và giữ nhật ký.",
    usage: [
      "Bấm ✕ để cất tab đi khi bạn không làm việc trên đơn đó nữa.",
      "Mở lại đơn có bàn: về màn Tổng quan, chạm vào bàn đang phục vụ.",
      "Mở lại đơn takeaway: về màn Tổng quan, mở ngăn kéo “Đơn takeaway”.",
    ],
    checks: [
      "Đóng tab KHÔNG huỷ và KHÔNG xoá đơn — không có lệnh nào gửi lên máy chủ.",
      "Đơn Nhanh và đơn tại bàn CHƯA GÁN BÀN không có lối mở lại, nên POS sẽ hỏi lại trước khi đóng. Muốn giữ lối vào thì gán bàn cho đơn trước.",
    ],
  },

  "stacking-conflict": {
    title: "Xung đột khuyến mại và mã giảm giá",
    subtitle: "併用不可",
    purpose:
      "Xuất hiện khi bạn thêm món đang có khuyến mại (Happy Hour) vào đơn đã gắn mã giảm giá. Hai ưu đãi này không được cộng dồn, nên hệ thống hỏi giữ cái nào.",
    setup: [
      "Quy tắc không-cộng-dồn khai theo từng khuyến mại / mã giảm giá ở HQ.",
    ],
    usage: [
      "Muốn giữ mã giảm giá: bấm huỷ, và không thêm món khuyến mại đó.",
      "Muốn thêm món khuyến mại: bấm đồng ý — hệ thống sẽ gỡ mã giảm giá rồi thêm món.",
    ],
    checks: [
      "Chọn đồng ý là GỠ HẲN mã giảm giá khỏi đơn; muốn dùng lại thì nhập lại mã.",
      "Ở chiều ngược lại (đơn đã có món khuyến mại rồi mới nhập mã), lỗi hiện ngay tại ô nhập mã trong giỏ, kèm nút “dùng mã thay cho khuyến mại”.",
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Hộp thoại tiền
  // ──────────────────────────────────────────────────────────────────────────
  payment: {
    title: "Thu tiền",
    subtitle: "会計",
    purpose:
      "Nhận tiền cho đơn đang mở, và nếu khách còn nợ cũ thì gộp luôn vào lần thu này. Mỗi khoản nợ được thu thành một giao dịch riêng, không trộn vào đơn hiện tại.",
    setup: [
      "Các ô phương thức lấy từ CHÍNH SÁCH THANH TOÁN của cửa hàng: chỉ những lựa chọn đang hiệu lực VÀ được đánh dấu dùng được ở POS mới hiện. Cấu hình ở admin-web → Cửa hàng → Cài đặt → Thanh toán.",
      "Lưới trống mà có nút “Thử lại” = lệnh gọi không tới nơi (lỗi kết nối), thu ngân tự xử lý được. Lưới trống KHÔNG có nút thử lại = cửa hàng chưa cấu hình phương thức nào, phải gọi quản lý.",
      "Nút máy quẹt thẻ P400 chỉ hoạt động khi máy này đã trỏ tới workstation — máy quẹt nằm sau NAT của cửa hàng, Cloud không với tới được.",
      "Nút 釣銭機 chỉ hiện khi cửa hàng có workstation và phương thức đang chọn cần nhập tiền khách đưa. Máy đếm tiền nói HTTP không mã hoá trong LAN nên bắt buộc đi qua workstation.",
      "Các thương hiệu thẻ/QR trong bước chọn phụ lấy từ danh mục tender của chi nhánh; nó chỉ để quy về đúng nhóm khi đối soát, không đổi số tiền.",
      "Bộ nút tiền nhanh (5k/10k/…) đổi theo đơn vị tiền của cửa hàng.",
    ],
    usage: [
      "Nếu đơn nhiều khách và muốn tách, bấm khối “Chia bill” ở đầu hộp thoại.",
      "Có nợ cũ thì quyết định gộp hay bỏ qua bằng ô tick trên thẻ nợ.",
      "Chọn ô phương thức.",
      "Với tiền mặt: gõ số tiền khách đưa, hoặc tick “đưa đủ”, hoặc bấm các nút mệnh giá (bấm nhiều lần sẽ CỘNG DỒN).",
      "Bấm xác nhận. Xong thì màn biên lai mở ra.",
    ],
    checks: [
      "Khách VÃNG LAI (không có số điện thoại) BẮT BUỘC trả đủ. Đưa thiếu thì nút xác nhận bị khoá kèm giải thích.",
      "Khách CÓ hồ sơ thì được trả thiếu — phần còn lại thành nợ và sẽ hiện lại ở lần sau.",
      "Thu nhiều khoản (nợ cũ + đơn này) chạy tuần tự, nợ cũ trước. Hỏng giữa chừng thì bấm lại — hệ thống có khoá chống trùng nên không thu hai lần.",
      "Đổi phương thức sẽ XOÁ số tiền khách đưa đã gõ — cố ý, để tiền mặt của dòng này không trôi sang dòng thẻ.",
      "Không được bấm quẹt thẻ lần hai khi lần đầu chưa rõ kết quả. Hộp thoại giữ nguyên và nói rõ điều đó — quẹt lại theo linh cảm là cách khách bị trừ tiền hai lần.",
      "Mất mạng thì nút xác nhận bị KHOÁ, không xếp hàng chờ.",
      "Nút “Ghi nợ toàn bộ” chuyển cả phần còn lại thành nợ của khách thay vì thu tiền bây giờ; nó bị khoá với khách vãng lai.",
    ],
    glossary: [
      {
        term: "Tiền khách đưa / Tiền thối",
        description:
          "Chỉ để in trên phiếu. Sổ sách và mọi báo cáo két luôn cộng theo SỐ TIỀN CỦA GIAO DỊCH, không theo tiền khách đưa.",
      },
      {
        term: "Thương hiệu tender",
        description:
          "Nhãn ghi lại thực tế khách bấm gì trên máy quẹt (thẻ tín dụng, PayPay, ID…). Thuần để đối soát; bỏ trống vẫn thu được.",
      },
    ],
  },

  "split-bill": {
    title: "Chia bill",
    subtitle: "会計分割",
    purpose:
      "Tách một đơn thành nhiều lần thu. Ba cách chia: CHIA ĐỀU theo số người, CHIA THEO MÓN (gán từng món cho từng người), và CHIA THEO SỐ TIỀN (tự nhập từng khoản).",
    setup: [
      "Chỉ mở được khi đơn đã qua bước tính tiền (trạng thái chốt đơn hoặc đang thu).",
      "Nút chia bill chỉ hiện khi đơn có số khách lớn hơn 1.",
      "Danh sách phương thức của từng dòng lấy từ cùng chính sách thanh toán như màn thu tiền thường.",
      "Ô nhập tiền khách đưa chỉ hiện trên dòng có phương thức yêu cầu nhập tiền mặt.",
    ],
    usage: [
      "Chọn tab kiểu chia. Chuyển qua lại giữa các tab KHÔNG mất số liệu đã nhập.",
      "Chia đều: chỉnh số người; chia theo món: chọn người rồi chạm món để gán; chia theo tiền: gõ số tiền cho từng dòng.",
      "Với mỗi dòng: chọn phương thức, gõ tiền khách đưa nếu là tiền mặt, rồi bấm “Thu”.",
      "Thu xong hết các dòng thì màn biên lai chia bill mở ra.",
    ],
    checks: [
      "Chia theo SỐ TIỀN yêu cầu tổng các dòng BẰNG ĐÚNG tổng đơn — không thiếu, không thừa.",
      "Ô tiền khách đưa để trống nghĩa là “đưa đúng”, và ô đã hiện sẵn số phần chia. Gõ thiếu thì nút Thu bị khoá, vì máy chủ cũng sẽ từ chối.",
      "Đổi phương thức của một dòng sẽ xoá số tiền khách đưa của dòng đó.",
      "Tiền khách đưa có trần 99.999.999. Thừa một chữ số sẽ làm giao dịch kẹt lại vĩnh viễn khi đồng bộ.",
      "Đóng hộp thoại giữa chừng KHÔNG mất các dòng đã thu — tab đơn vẫn mở để thu tiếp; tab chỉ đóng khi đơn đã thu đủ.",
      "Muốn quay về thu một lần duy nhất thì bấm huỷ chia — hộp thoại thu tiền thường sẽ mở lại.",
    ],
  },

  "payment-receipt": {
    title: "Thanh toán thành công",
    subtitle: "会計完了",
    purpose:
      "Màn xác nhận sau khi đã thu đủ. Liệt kê từng giao dịch vừa ghi nhận, tổng đã thu, tiền khách đưa và tiền thối, kèm hai việc có thể làm với giấy: “In biên lai” và “In phiếu thanh toán” (bản có ghi tên người mua).",
    setup: [
      "Hai nút in chỉ hiện khi máy này đã trỏ tới workstation có máy in. Không có thì màn hình vẫn đúng, chỉ không có nút in.",
      "Mã số thuế / 登録番号 in trên phiếu lấy từ thiết lập của thương hiệu hoặc chi nhánh. Chưa nhập thì phiếu không có dòng đó — hợp lệ, hệ thống không cảnh báo.",
    ],
    usage: [
      "Đọc lại các dòng giao dịch và tổng tiền cùng khách.",
      "Bấm “In biên lai” nếu khách cần. Nhãn nút đổi thành “Đã in” rồi “In lại” theo số lần bấm.",
      "Bấm “In phiếu thanh toán” nếu khách cần tờ có ghi TÊN NGƯỜI MUA. Khác với “In biên lai” ở ngay trên: cùng nội dung, chỉ thêm dòng tên.",
      "Bấm đóng — lúc này tab đơn mới được đóng lại.",
    ],
    checks: [
      "Tab đơn CỐ Ý chờ bạn đóng màn này mới đóng, để không mất ngữ cảnh giữa chừng.",
      "Mỗi lần in lại đều được ghi vào lịch sử in và nhật ký kiểm toán. Giấy in ra không phải là miễn phí.",
      "Nếu đơn chưa thu đủ, bạn sẽ thấy màn “Đơn treo” màu hổ phách chứ không phải màn này.",
    ],
  },

  "on-hold-receipt": {
    title: "Đơn treo",
    subtitle: "未収",
    purpose:
      "Màn kết thúc phiên thu khi cửa hàng CHƯA nhận đủ tiền — khách còn nợ. Nó cố ý khác hẳn màn xanh “thanh toán thành công”, vì trạng thái khác thì màn hình phải khác.",
    setup: [
      "Chỉ đơn có gắn khách hàng mới rơi vào trạng thái này được; khách vãng lai buộc phải trả đủ.",
      "Khoản nợ sẽ hiện lại ở màn “Tra cứu nợ” và ở lần thu tiền sau của chính khách đó.",
    ],
    usage: [
      "Đọc lý do đơn bị treo ghi ngay đầu màn.",
      "Đối chiếu số còn nợ với khách.",
      "Bấm đóng.",
    ],
    checks: [
      "Màn này CỐ Ý không có nút “In biên lai” và không có nút “In phiếu thanh toán” — chưa thu đủ thì chưa có gì để chứng nhận là đã thanh toán.",
      "Đừng đọc nhầm sang màn xanh: màu hổ phách nghĩa là cửa hàng đang cho nợ.",
      "Muốn thu nốt thì mở lại đơn của khách đó, hoặc dùng “Tra cứu nợ” khi khách quay lại.",
    ],
  },

  "split-bill-receipt": {
    title: "Biên lai chia bill",
    subtitle: "分割会計 完了",
    purpose:
      "Màn xác nhận sau khi MỌI dòng của phiên chia bill đã thu xong. Cho phép in biên lai cho từng khách, hoặc in tất cả một lượt.",
    setup: [
      "Nút in cần workstation có máy in.",
      "Hệ thống in tuần tự với khoảng nghỉ giữa hai phiếu, để máy in nhiệt kịp cắt giấy.",
    ],
    usage: [
      "Chọn các dòng khách cần in.",
      "Bấm in — trạng thái từng dòng hiện chờ → đang in → đã in / lỗi.",
      "Bấm đóng khi xong.",
    ],
    checks: [
      "Phiếu của từng khách tra theo MÃ GIAO DỊCH chứ không theo số tiền — chia đều làm nhiều khách có cùng số tiền, tra theo tiền sẽ in nhầm người.",
      "Một dòng in lỗi không chặn các dòng còn lại; in lại đúng dòng đó.",
    ],
  },

  "print-result": {
    title: "Kết quả ghi nhận & in",
    subtitle: "印刷確認",
    purpose:
      "Hiện ra sau khi một khoản nợ (hoặc chứng từ) đã được GHI NHẬN thành công, để hỏi riêng câu “có in không”. Ghi nhận và in là hai quyết định khác nhau.",
    setup: [
      "Cần workstation có máy in thì mới in được. Không có thì cứ đóng — dữ liệu đã lưu rồi.",
    ],
    usage: ["Bấm in nếu khách cần giấy.", "Bấm đóng nếu không cần."],
    checks: [
      "Việc ghi nhận ĐÃ XONG trước khi màn này mở. Không có gì ở đây làm mất tiền được.",
      "In lỗi thì màn hình báo và vẫn mở, để bạn thử lại — không cần làm lại thao tác ghi nhận.",
    ],
  },

  "red-invoice": {
    title: "Phiếu thanh toán (có tên người mua)",
    subtitle: "領収書",
    purpose:
      "In phiếu như biên lai đã thanh toán nhưng có thêm dòng TÊN NGƯỜI MUA. Dùng khi khách cần chứng từ mang tên mình.",
    setup: [
      "Bắt buộc có workstation và máy in — đường này in THẲNG, không ghi bản ghi nào vào cơ sở dữ liệu.",
      "Mã số thuế người bán in trên phiếu lấy từ thiết lập thương hiệu / chi nhánh.",
      "Khi đang chia bill, phiếu nhắm vào ĐÚNG một người trả để không in gộp cả đơn.",
    ],
    usage: [
      "Nhập tên người mua nếu khách cung cấp.",
      "Bỏ trống cũng được — máy in ra dòng gạch để viết tay.",
      "Bấm in.",
    ],
    checks: [
      "Không có bản ghi nào được tạo. Đây thuần tuý là in giấy.",
      "Tờ này TỪNG được gọi là “hoá đơn đỏ” (và ở bản Nhật là 赤伝). Tên đó đã bỏ ở #2062/#2070 vì nó tự nhận là một chứng từ LUẬT ĐỊNH mà nó không phải: nó không có số hoá đơn, không được lưu, không vào sổ nào. Khách cần hoá đơn GTGT thật thì đây không phải thứ để đưa.",
      "Huy hiệu “đã in ×N” cho biết phạm vi này đã in bao nhiêu bản. Không thấy huy hiệu nghĩa là workstation đời cũ không trả lời được câu đó — ĐỪNG hiểu là “chưa in”.",
    ],
  },

  "debt-search": {
    title: "Tra cứu nợ",
    subtitle: "未収検索",
    purpose:
      "Trả lời câu hỏi toàn cửa hàng “ai đang nợ, nợ bao nhiêu”, và nếu đủ điều kiện thì thu luôn tại đây. Nút nằm ở thanh trên cùng để hỏi được cả khi màn hình không có đơn nào.",
    setup: [
      "Chỉ nợ của khách CÓ hồ sơ mới xuất hiện; khách vãng lai không thể nợ.",
      "Danh sách phương thức thu lấy từ chính sách thanh toán như màn thu tiền thường.",
    ],
    usage: [
      "Tìm theo tên hoặc số điện thoại, hoặc duyệt danh sách khách đang nợ.",
      "Chọn một khách để mở danh sách từng khoản nợ.",
      "Chọn khoản cần thu, chọn phương thức, nhập tiền khách đưa nếu là tiền mặt, rồi thu.",
    ],
    checks: [
      "Muốn THU nợ thì cửa hàng phải đang có MỘT ĐƠN SỐNG của chính khách đó (đơn ở bước chốt đơn hoặc đang thu). Đây là quy tắc phía máy chủ, không phải hạn chế giao diện.",
      "Không có đơn sống thì hộp thoại nói rõ và không hiện nút thu — thay vì đưa ra một nút chắc chắn lỗi.",
      "Đơn nợ gốc luôn đã đóng, nên khoản thu được ghi lên đơn sống hiện tại kèm liên kết trỏ về khoản nợ được thanh toán.",
    ],
  },

  "card-terminal": {
    title: "Máy quẹt thẻ",
    subtitle: "決済端末",
    purpose:
      "Điều khiển máy quẹt thẻ đặt tại quầy qua workstation. Workstation là bên nói chuyện với máy và cũng là bên GHI NHẬN khoản thanh toán khi được duyệt.",
    setup: [
      "Bắt buộc có workstation. Máy quẹt nằm sau NAT trong LAN cửa hàng, Cloud không có đường tới nó.",
      "Máy quẹt phải được đăng ký ở phần thiết bị thanh toán của cửa hàng.",
      "Không có workstation thì nút bị khoá và có dòng chữ giải thích ngay dưới nút.",
    ],
    usage: [
      "Bấm nút máy quẹt thẻ.",
      "Đưa máy cho khách thao tác.",
      "Chờ kết quả. Được duyệt thì hộp thoại tự đóng và đơn được cập nhật.",
    ],
    checks: [
      "Nếu KHÔNG có kết quả trả về, hộp thoại giữ nguyên và đơn giữ nguyên. ĐỪNG quẹt lại theo linh cảm — đó là cách một thẻ bị trừ tiền hai lần.",
      "Khi được duyệt, khoản thanh toán do WORKSTATION ghi. POS không tạo thêm giao dịch nào.",
      "Nút “Huỷ” trên màn chờ là huỷ phiên quẹt ở máy, không phải hoàn tiền.",
    ],
  },

  "cash-changer": {
    title: "Máy đếm tiền / 釣銭機",
    subtitle: "釣銭機",
    purpose:
      "Nhận tiền mặt bằng máy đếm–thối tiền tự động. Máy đếm tiền, thối tiền và workstation ghi nhận khoản thanh toán; POS chỉ theo dõi và làm mới đơn.",
    setup: [
      "Bắt buộc có workstation: máy nói HTTP không mã hoá trong LAN kèm danh sách IP cho phép, nên trang HTTPS không gọi thẳng được. Cửa hàng muốn dùng 釣銭機 thì phải lắp workstation.",
      "Lệnh bắt đầu chỉ gửi MÃ ĐƠN — số tiền do workstation đọc ở phía máy chủ, vì tiền mà máy đếm thật thì không thể để máy khách tự khai.",
    ],
    usage: [
      "Bấm nút 釣銭機 trong màn thu tiền.",
      "Mời khách bỏ tiền vào máy.",
      "Theo dõi trạng thái trên màn phủ toàn hình cho tới khi kết thúc.",
    ],
    checks: [
      "HUỶ và HẾT GIỜ KHÔNG giống nhau: huỷ là máy TRẢ LẠI tiền cho khách; hết giờ / lỗi / dừng đột ngột nghĩa là máy VẪN GIỮ tiền. Màn hình nói rõ và cố ý không cho đóng dễ dàng.",
      "Khi kết thúc thành công, khoản thanh toán đã do workstation ghi. TUYỆT ĐỐI không tạo thêm giao dịch tay — sẽ thu tiền khách hai lần.",
      "Không rõ kết quả thì phải kiểm tra ngăn tiền của máy trước khi làm bất cứ việc gì tiếp theo.",
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Hộp thoại ca
  // ──────────────────────────────────────────────────────────────────────────
  "cash-event": {
    title: "Thu / chi tiền mặt trong ca",
    subtitle: "入金・出金",
    purpose:
      "Ghi nhận tiền mặt ra vào két ngoài việc bán hàng: nộp thêm tiền lẻ, rút tiền về két sắt, chi vặt. Không ghi thì cuối ca sẽ lệch đúng bằng khoản đó.",
    setup: ["Phải có ca đang mở; mục này nằm trong trình đơn tài khoản ở thanh trên cùng."],
    usage: [
      "Chọn loại: nhập tiền vào hay rút tiền ra.",
      "Nhập số tiền và lý do.",
      "Bấm xác nhận. Số liệu đối soát của màn kết ca cập nhật ngay.",
    ],
    checks: [
      "Đây KHÔNG phải doanh thu. Nó chỉ điều chỉnh số tiền mặt hệ thống kỳ vọng có trong két.",
      "Ghi sót một lần rút tiền là ca sẽ thiếu đúng số đó và bạn sẽ phải giải trình lý do lệch.",
    ],
  },

  "abandon-shift": {
    title: "Huỷ ca mở nhầm",
    subtitle: "シフト破棄",
    purpose:
      "Bỏ một ca vừa mở nhầm (sai người, sai số đếm, mở nhầm máy). Ca bị đánh dấu huỷ chứ không kết toán.",
    setup: ["Chỉ dùng khi ca CHƯA phát sinh khoản thanh toán nào."],
    usage: ["Nhập lý do.", "Bấm xác nhận. POS quay về màn mở ca."],
    checks: [
      "Ca đã có khoản thanh toán thì máy chủ TỪ CHỐI huỷ. Lúc đó phải đi đường kết ca bình thường.",
      "Không hoàn tác được. Muốn mở lại thì mở ca mới và đếm lại từ đầu.",
    ],
  },

  "shift-settle-confirm": {
    title: "Xác nhận kết toán ca",
    subtitle: "精算確認",
    purpose:
      "Bước xác nhận cuối trước khi chốt ca. Tóm tắt lại ba con số quan trọng: tiền mặt hệ thống tính, tiền mặt đếm được, và chênh lệch.",
    usage: [
      "Đọc lại ba con số trong bảng tóm tắt.",
      "Đọc TÊN trên nút xác nhận — nó nói đúng việc bạn sắp làm là bàn giao hay kết ca.",
      "Bấm xác nhận.",
    ],
    checks: [
      "BÀN GIAO giữ chuỗi ca mở để ca sau đi tiếp; KẾT CA đóng chuỗi và in phiếu tổng hợp cả chuỗi. Hai việc khác nhau nên hai nút được đặt tên khác nhau.",
      "Sau khi xác nhận thành công, POS chuyển ngay sang màn mở ca; việc in phiếu chạy nền, máy in nguội không giữ bạn lại.",
      "In hỏng chỉ hiện cảnh báo — ca ĐÃ chốt rồi, không vì thế mà mở lại.",
    ],
  },
};
