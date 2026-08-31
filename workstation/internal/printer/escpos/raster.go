package escpos

// Raster bit-image output (#1957 piece A).
//
// This is the command a printer needs to put a LOGO on paper, and until now
// neither repo had it — `escpos` could set alignment, emphasis, kanji mode and
// cut, but had no way to say "here are some dots". That absence is why the
// `logo` block is togglable in the template catalog while nothing draws it
// (#1949): the catalog offered a capability the byte layer could not express.
//
// # Scope: bytes only
//
// This file turns an ALREADY-DECODED 1-bit bitmap into ESC/POS. It does not
// decode PNG/JPEG, does not fetch anything, and does not know what a brand logo
// is — those belong to the storage/sync piece. Keeping the boundary here is
// what lets the byte format be pinned by a golden fixture shared with PHP: a
// primitive that also decoded images would have to agree with PHP's image
// library too, and two image decoders agreeing pixel-for-pixel is a much larger
// promise than two encoders agreeing on a header.
//
// # Why GS v 0 and not ESC *
//
// `ESC *` is the older 8/24-dot column mode: the caller must slice the image
// into horizontal bands and interleave line feeds, so the same picture becomes
// a different byte stream depending on how it was sliced. `GS v 0` takes the
// whole raster in one command, which is the form two independent
// implementations can be expected to produce identically — the property this
// whole parity effort rests on.
//
// Star mC-Print in StarPRNT emulation accepts `GS v 0`; the profile layer is
// where a machine that needs `ESC *` would be handled, once one exists to test
// against. Inventing that path now would mean committing a second byte format
// no fixture covers.

// rasterMaxDimension bounds a single command. `xL xH` / `yL yH` are 16-bit, so
// the wire format cannot express more, and a caller that computed a larger
// value has a bug worth surfacing rather than silently truncating into a
// printer that will render garbage.
const rasterMaxDimension = 0xFFFF

// Raster emits `GS v 0` for a 1-bit-per-pixel bitmap.
//
// `widthDots` is the image width in dots; `data` is packed MSB-first, each row
// padded to a whole byte — the same layout the command itself uses, so no
// repacking happens here and there is nothing for the two languages to disagree
// about.
//
// Returns false and emits NOTHING when the input cannot describe an image. A
// no-op rather than an error because of TR-05: a workstation that has never
// been online has no logo bytes, and a slip that refuses to print because a
// decoration is missing is a worse failure than a slip without the decoration.
func (e *Encoder) Raster(widthDots int, data []byte) bool {
	if widthDots <= 0 || len(data) == 0 {
		return false
	}

	bytesPerRow := (widthDots + 7) / 8
	if bytesPerRow == 0 || len(data)%bytesPerRow != 0 {
		// A partial final row means the caller packed the image differently
		// from what it declared. Printing it would shear the picture, and the
		// operator would report "the logo looks corrupted" with no way to trace
		// it back here.
		return false
	}

	heightDots := len(data) / bytesPerRow
	if bytesPerRow > rasterMaxDimension || heightDots > rasterMaxDimension {
		return false
	}

	// GS v 0 m xL xH yL yH  — m = 0 (normal density, no scaling). The scaled
	// modes (1/2/3) double width or height ON THE PRINTER, which would make the
	// rendered size depend on the machine rather than on the definition.
	e.buf.Write([]byte{
		0x1D, 0x76, 0x30, 0x00,
		byte(bytesPerRow & 0xFF), byte(bytesPerRow >> 8),
		byte(heightDots & 0xFF), byte(heightDots >> 8),
	})
	e.buf.Write(data)

	return true
}
