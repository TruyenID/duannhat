package service

// #2000 bước 2 — các dòng cửa hàng khai thêm trong `store_info.fields`.
//
// Đối xứng với `StoreInfoBlock::emitDetailLines` bên PHP. MỘT hàm cho cả ba họ
// phiếu: ba bản sao của cùng một vòng lặp là ba cơ hội để một bản quên một
// field, và kiểu hỏng đó không kêu ở đâu — nó chỉ làm một loại phiếu thiếu một
// dòng.
//
// ## Vì sao `store_name` KHÔNG đi qua đây
//
// Mỗi họ vẫn tự in tên quán vô điều kiện. Cho nó theo `fields` nghĩa là cho phép
// publish một phiếu KHÔNG TÊN QUÁN — mà chính các emitter đó đã có bản dự phòng
// "Store" để tránh đúng chuyện ấy.
//
// ## `store_phone` đã có đường (#2000 bước 3)
//
// Cloud gửi `phone` trong feed branch từ lâu; bước 3 cho `PullBranch` giải mã nó
// vào `workstation_branch_phone` và thêm ô `StorePhone`, nên khai field này giờ
// ra giấy thật.
//
// ## Một khác biệt CÓ SẴN, không phải do bài này
//
// `renderDocHeader` in `StoreSubName` khi phiếu KHÔNG có tiêu đề — một luật cũ
// không dính gì tới `fields`. Bài này không gỡ nó: gỡ sẽ đổi byte của những
// phiếu đang chạy, mà đó là thay đổi thấy được trên giấy và phải là quyết định
// riêng. Hệ quả: ở đúng ca đó, tên thương hiệu có thể in HAI lần nếu definition
// cũng khai `store_sub_name`. Bản mặc định không khai, nên hôm nay không ai gặp.
// emitStoreAbove in các dòng đứng TRƯỚC dòng "chi nhánh + tiêu đề".
//
// Điểm cắt là vị trí của `store_name` trong `fields`. Không thêm ô cấu hình nào
// cho việc này: thứ tự khai VỐN ĐÃ nói lên bố cục, và một ô riêng sẽ là nguồn
// sự thật thứ hai cho cùng một câu hỏi. Quy ước hoá đơn Nhật đặt 法人名 và
// thương hiệu lên trên tên cửa hàng, địa chỉ và TEL xuống dưới.
func emitStoreAbove(c *printRenderCtx) { emitStoreDetailLines(c, true) }

// emitStoreBelow in các dòng đứng SAU dòng đó.
func emitStoreBelow(c *printRenderCtx) { emitStoreDetailLines(c, false) }

func emitStoreDetailLines(c *printRenderCtx, before bool) {
	if c == nil || c.def == nil {
		return
	}

	var block *PrintTemplateBlock
	for i := range c.def.Blocks {
		if c.def.Blocks[i].ID == "store_info" {
			block = &c.def.Blocks[i]

			break
		}
	}

	if block == nil || !block.isEnabled() {
		return
	}

	for _, line := range StoreDetailValues(c.cfg, block.Fields, before) {
		c.e.Line(line)
	}
}

// StoreDetailValues tra `fields` ra các dòng thật, ở MỘT phía của mốc `store_name`.
//
// Hàm thuần, và đó là điểm chính: đường in qua template (renderer) và đường dự
// phòng (formatter cũ) đều gọi nó, nên hai bên khớp nhau THEO CẤU TẠO chứ không
// nhờ ai nhớ sửa cả hai. `TestProductionProfile_ByteIdenticalToLegacy` ghim đúng
// sự khớp đó — và nếu bảng ánh xạ này bị nhân bản, nó sẽ là chỗ hai bản trôi ra.
//
// `store_name` KHÔNG có trong bảng: nó chỉ là mốc chia trên/dưới, và mỗi đường
// tự in tên cửa hàng theo cách của mình (kèm tiêu đề, đậm, căn hai đầu).
func StoreDetailValues(cfg PrintJobConfig, fields []string, before bool) []string {
	pivot := -1
	for i, f := range fields {
		if f == "store_name" {
			pivot = i

			break
		}
	}

	var out []string

	for i, field := range fields {
		if (i < pivot) != before {
			continue
		}

		var value string

		switch field {
		case "store_organization":
			value = cfg.StoreOrganization
		case "store_sub_name":
			value = cfg.StoreSubName
		case "store_address":
			value = cfg.StoreAddress
		case "store_phone":
			value = cfg.StorePhone
		}

		if value != "" {
			out = append(out, value)
		}
	}

	return out
}

// StoreFieldsForKind trả danh sách `store_info.fields` của bản mặc định hệ thống
// cho một kind.
//
// Đường dự phòng (formatter cũ) gọi nó để in ĐÚNG bộ dòng mà đường template sẽ
// in. Đọc từ layer-0 đã nhúng chứ không gõ lại danh sách: hai bản danh sách là
// hai thứ sẽ trôi khỏi nhau, và chỗ trôi đó chính là nơi phiếu dự phòng bắt đầu
// khác phiếu thường — im lặng, đúng lúc renderer đang hỏng.
//
// Lỗi hoặc thiếu kind → trả nil, tức formatter cũ in như trước bài này. Đó là
// hành vi đúng cho một đường khẩn cấp: in được quan trọng hơn in đủ.
func StoreFieldsForKind(kind string) []string {
	def, err := SystemPrintTemplate(kind)
	if err != nil || def == nil {
		return nil
	}

	for i := range def.Blocks {
		if def.Blocks[i].ID == "store_info" && def.Blocks[i].isEnabled() {
			return def.Blocks[i].Fields
		}
	}

	return nil
}
