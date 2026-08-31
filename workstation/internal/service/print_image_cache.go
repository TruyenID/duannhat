package service

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// #1957 mảnh B — cache ảnh in ở máy trạm.
//
// Lời hứa duy nhất của tệp này, giống hệt print_template_cache.go: **một vấn đề
// về ảnh không bao giờ được chặn một lần bán hàng.** Cloud chết, cache rỗng, hash
// lệch, byte hỏng — mọi đường đều kết thúc ở "in phiếu không có khối ảnh", kèm
// một dòng log, và phiếu vẫn ra khỏi máy in (TR-05).
//
// Hai bước kéo về, cố ý:
//
//  1. `GET /workstation/print-images` — danh mục: mỗi ảnh có hiệu lực kèm hash
//     và kích thước từng biến thể bề rộng. Nhỏ, kéo mỗi tick.
//  2. `GET /workstation/print-images/{hash}` — byte, CHỈ cho hash chưa có.
//
// Gộp byte vào bước 1 sẽ đẩy vài trăm KB qua đường truyền của quán mỗi tick chỉ
// để nói "chưa có gì đổi". Vì byte bất biến theo hash, bước 2 chạy đúng một lần
// cho mỗi phiên bản logo, mãi mãi.
//
// Giờ chi nhánh KHÔNG có khoá riêng ở đây: nó đã được `print_templates` ghi vào
// settings mỗi lượt pull, và hai bản sao của cùng một sự thật sẽ lệch nhau.
const pullPathPrintImages = "/api/v1/workstation/print-images"

type printImageVariant struct {
	MaxWidthDots int    `json:"max_width_dots"`
	WidthDots    int    `json:"width_dots"`
	HeightDots   int    `json:"height_dots"`
	ContentHash  string `json:"content_hash"`
	ByteLength   int    `json:"byte_length"`
}

type printImageEntry struct {
	Source        string              `json:"source"`
	Scope         string              `json:"scope"`
	Version       int                 `json:"version"`
	EffectiveFrom *string             `json:"effective_from"`
	UpdatedAt     *string             `json:"updated_at"`
	Variants      []printImageVariant `json:"variants"`
}

type printImagePayload struct {
	Data        []printImageEntry `json:"data"`
	GeneratedAt string            `json:"generated_at"`
}

type printImageBlobPayload struct {
	Data struct {
		ContentHash  string `json:"content_hash"`
		MaxWidthDots int    `json:"max_width_dots"`
		WidthDots    int    `json:"width_dots"`
		HeightDots   int    `json:"height_dots"`
		ByteLength   int    `json:"byte_length"`
		Data         string `json:"data"`
	} `json:"data"`
}

// PrintImage là thứ tầng in nhận được: đủ để phát một lệnh raster, không hơn.
type PrintImage struct {
	ContentHash string
	WidthDots   int
	HeightDots  int
	Data        []byte
}

// PullPrintImages kéo danh mục rồi tải byte cho những hash chưa có.
func (p *SyncPuller) PullPrintImages(ctx context.Context) error {
	var payload printImagePayload
	if err := p.cloudGet(ctx, pullPathPrintImages, &payload); err != nil {
		// Cloud chết. Cache trên đĩa CHÍNH LÀ câu trả lời — không ghi gì, không
		// vô hiệu hoá gì, quán không nhận ra điều gì khác.
		return err
	}

	now := time.Now().UTC().Format(time.RFC3339)

	for _, entry := range payload.Data {
		for _, v := range entry.Variants {
			if err := p.ensureBlob(ctx, v, now); err != nil {
				// Một biến thể hỏng KHÔNG được kéo theo các biến thể khác, và
				// càng không được làm hỏng cả lượt tick. Bỏ qua nó; con trỏ cũ
				// (nếu có) vẫn trỏ vào byte cũ vẫn in được.
				slog.Warn("print_images: bỏ qua biến thể",
					"source", entry.Source, "width", v.MaxWidthDots, "err", err)

				continue
			}

			if err := p.upsertCurrentImage(entry, v, now); err != nil {
				slog.Warn("print_images: không cập nhật được con trỏ",
					"source", entry.Source, "width", v.MaxWidthDots, "err", err)
			}
		}
	}

	return nil
}

// ensureBlob tải byte cho một hash nếu chưa có, và XÁC MINH hash trước khi lưu.
func (p *SyncPuller) ensureBlob(ctx context.Context, v printImageVariant, now string) error {
	if v.ContentHash == "" {
		return fmt.Errorf("biến thể không có content_hash")
	}

	var exists int
	err := p.db.QueryRow(`SELECT COUNT(*) FROM print_image_blobs WHERE content_hash = ?`, v.ContentHash).Scan(&exists)
	if err != nil {
		return err
	}
	if exists > 0 {
		// Byte bất biến theo hash, nên "đã có" là câu trả lời cuối cùng. Đây là
		// chỗ khiến việc đổi logo chỉ tốn đúng một lượt tải, không phải một lượt
		// mỗi tick.
		return nil
	}

	var blob printImageBlobPayload
	if err := p.cloudGet(ctx, pullPathPrintImages+"/"+v.ContentHash, &blob); err != nil {
		return err
	}

	raw, err := base64.StdEncoding.DecodeString(blob.Data.Data)
	if err != nil {
		return fmt.Errorf("base64 hỏng: %w", err)
	}

	// Tự băm lại và tự so. Một lượt tải đứt gánh KHÔNG được phép thay thế một
	// bitmap đang chạy tốt — cùng luật với `checksum` của print_templates
	// (TR-24). Hash cũng chính là KHOÁ, nên tin nó mà không kiểm nghĩa là để
	// một dãy byte cụt sống dưới tên của dãy byte đúng, vĩnh viễn.
	sum := sha256.Sum256(raw)
	if got := hex.EncodeToString(sum[:]); got != v.ContentHash {
		return fmt.Errorf("hash lệch: manifest nói %s, byte tải về băm ra %s", v.ContentHash, got)
	}

	_, err = p.db.Exec(`
		INSERT INTO print_image_blobs (content_hash, width_dots, height_dots, byte_length, data, fetched_at)
		VALUES (?, ?, ?, ?, ?, ?)
		ON CONFLICT(content_hash) DO NOTHING`,
		v.ContentHash, v.WidthDots, v.HeightDots, len(raw), raw, now)

	return err
}

func (p *SyncPuller) upsertCurrentImage(entry printImageEntry, v printImageVariant, now string) error {
	var effectiveFrom, updatedAt any
	if entry.EffectiveFrom != nil {
		effectiveFrom = *entry.EffectiveFrom
	}
	if entry.UpdatedAt != nil {
		updatedAt = *entry.UpdatedAt
	}

	_, err := p.db.Exec(`
		INSERT INTO print_image_current
			(source, max_width_dots, content_hash, version, effective_from, cloud_updated_at, fetched_at)
		VALUES (?, ?, ?, ?, ?, ?, ?)
		ON CONFLICT(source, max_width_dots) DO UPDATE SET
			content_hash     = excluded.content_hash,
			version          = excluded.version,
			effective_from   = excluded.effective_from,
			cloud_updated_at = excluded.cloud_updated_at,
			fetched_at       = excluded.fetched_at`,
		entry.Source, v.MaxWidthDots, v.ContentHash, entry.Version, effectiveFrom, updatedAt, now)

	return err
}

// PrintImageStore đọc cache ảnh lúc in.
type PrintImageStore struct {
	db *store.DB
}

func NewPrintImageStore(db *store.DB) *PrintImageStore {
	return &PrintImageStore{db: db}
}

// Lookup trả bitmap cho một (source, bề rộng), hoặc `nil, nil` nếu không có.
//
// `nil, nil` là kết quả HỢP LỆ và là đường đi phổ biến: máy chưa từng online,
// brand chưa tải logo, khối ảnh bật mà chưa có ảnh. Tầng gọi vẽ khối rỗng rồi đi
// tiếp (TR-05). KHÔNG trả lỗi cho những trường hợp đó — một lỗi ở đây sẽ leo lên
// thành "không in được phiếu", tức lấy doanh thu của quán đổi lấy một cái logo.
//
// `branchWallClock` là giờ treo tường của CHI NHÁNH ("YYYY-MM-DD HH:MM:SS"),
// không phải instant (#1091). Chuỗi RỖNG nghĩa là chưa biết giờ chi nhánh; khi
// đó `effective_from` bị BỎ QUA thay vì đoán — đoán sai sẽ lật logo sớm hoặc
// muộn đúng bằng độ lệch múi giờ, và đó là cả một ngày kinh doanh in sai.
func (s *PrintImageStore) Lookup(source string, maxWidthDots int, branchWallClock string) (*PrintImage, error) {
	if s == nil || s.db == nil {
		return nil, nil
	}

	// So sánh là so CHUỖI, đúng vì định dạng này sắp xếp được — cùng lý do
	// print_templates so chuỗi thay vì ép về timestamp.
	const q = `
		SELECT b.content_hash, b.width_dots, b.height_dots, b.data
		FROM print_image_current c
		JOIN print_image_blobs b ON b.content_hash = c.content_hash
		WHERE c.source = ? AND c.max_width_dots = ?
		  AND (c.effective_from IS NULL OR ? = '' OR c.effective_from <= ?)`

	var img PrintImage
	err := s.db.QueryRow(q, source, maxWidthDots, branchWallClock, branchWallClock).
		Scan(&img.ContentHash, &img.WidthDots, &img.HeightDots, &img.Data)

	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		// Cả lỗi DB cũng KHÔNG được leo lên: một cache hỏng không phải lý do để
		// ngừng bán hàng. Kêu to trong log rồi in phiếu không có logo.
		slog.Warn("print_images: tra cache thất bại", "source", source, "width", maxWidthDots, "err", err)

		return nil, nil
	}

	// Byte trên đĩa phải khớp hash của chính nó. Đĩa hỏng, ghi dở, hay một lượt
	// tải cũ lọt qua trước khi có kiểm tra — mọi thứ đó cho ra một bitmap in
	// thành nhiễu. Thà không có logo còn hơn in ra một dải mực đen.
	sum := sha256.Sum256(img.Data)
	if hex.EncodeToString(sum[:]) != img.ContentHash {
		slog.Error("print_images: byte trong cache lệch hash — bỏ qua khối ảnh",
			"source", source, "hash", img.ContentHash)

		return nil, nil
	}

	return &img, nil
}
