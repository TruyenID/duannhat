package service

import (
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// #1957 mảnh C — emitter cho khối `logo`.
//
// MỘT hàm cho cả 13 kind, đối xứng với `LogoBlock::emit` bên PHP. Không phải để
// gọn: khối này phải phát ra byte **giống hệt** phía Cloud, và mười ba bản sao
// của cùng một chuỗi lệnh là mười ba cơ hội để một bản lệch đi mà
// `print_cloud_parity_test` chỉ báo "hash không khớp" chứ không nói bản nào.
//
// ## Không có ảnh là chuyện BÌNH THƯỜNG (TR-05)
//
// Brand chưa tải logo, máy chưa từng online, byte trong cache lệch hash — cả ba
// đều kết thúc ở đây: **không phát byte nào**. Phiếu vẫn in, chỉ thiếu khối.
//
// ## TR-40 — hệ thống chưa ai tải logo in ra byte y hệt hôm nay
//
// Khối không có trong definition hoặc `enabled=false` thì vòng duyệt không gọi
// tới đây; và khi tới đây mà không có ảnh, không byte nào được phát. Đó là điều
// khiến mảnh C triển khai được mà không đụng vào một quán nào đang chạy.

// logoDefaultMaxWidthDots là bề rộng khi definition không khai `max_width_dots`.
//
// Trùng khổ 80mm in được, và trùng hằng cùng tên bên PHP. Không chọn "vừa đúng
// ảnh": không khai bề rộng nghĩa là "to hết mức giấy cho phép", còn co theo kích
// thước tình cờ của tệp được tải lên sẽ khiến đổi ảnh làm đổi bố cục.
const logoDefaultMaxWidthDots = 576

func emitLogo(c *printRenderCtx, b *PrintTemplateBlock) {
	if c == nil || b == nil || c.images == nil {
		return
	}

	source := b.Source
	if source == "" {
		return
	}

	img, err := c.images.Lookup(source, logoWidthOf(b), c.branchWallClock)
	if err != nil || img == nil {
		return
	}

	// Thứ tự lệnh dưới đây LÀ hợp đồng với phía PHP (`LogoBlock::emit`). Đổi nó
	// ở một phía là làm hai bên in ra hai tờ giấy khác nhau từ cùng definition.
	c.e.Align(logoAlignOf(b))
	c.e.Raster(img.WidthDots, img.Data)
	c.e.Align(escpos.AlignLeft)
}

func logoWidthOf(b *PrintTemplateBlock) int {
	if b.MaxWidthDots > 0 {
		return b.MaxWidthDots
	}

	return logoDefaultMaxWidthDots
}

func logoAlignOf(b *PrintTemplateBlock) []byte {
	// Mặc định CĂN GIỮA, khác với mọi khối chữ (mặc định trái). Một logo lệch
	// trái trên giấy 80mm trông như lỗi in, và không ai thiết kế phiếu lại muốn
	// thế — nên mặc định phải là thứ người ta sẽ chọn.
	switch b.Align {
	case "left":
		return escpos.AlignLeft
	case "right":
		return escpos.AlignRight
	default:
		return escpos.AlignCenter
	}
}
