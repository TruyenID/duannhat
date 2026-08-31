// Package label is a minimal scaffold for the workstation label-printer module
// (godx-jp/godx-tempo#253).
//
// Scope so far: format a small prep/ingredient label (name, prepared/expiry
// dates, free-form detail lines) into printer bytes using the shared escpos
// encoder, so a label printer reuses the same StarPRNT transport + cut path as
// receipt/kitchen printers (see #438 for why the cut command matters).
//
// TODO(#253): the "larger" parts still to design and wire up:
//   - real 1D/2D barcode encoding (Code128 / QR) instead of the plain-text lot
//     line below — Star mC-Label vs mC-Print differ here;
//   - a label-size aware layout (die-cut label height, gap/black-mark feed)
//     rather than the continuous-receipt assumptions used here;
//   - a service entry point that pulls material/batch data (name, lot, expiry,
//     allergens) and a handler/route to trigger a label print, mirroring
//     print_service.go + handleTestDevice.
package label

import (
	"fmt"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
)

// Label is the data for a single printed label.
type Label struct {
	Title      string     // product / material name (printed large + centered)
	Lines      []string   // free-form detail lines, e.g. "Lot: A1234", "Qty: 3"
	PreparedAt time.Time  // when the item was prepared
	ExpiresAt  *time.Time // optional expiry; omitted when nil
	LotCode    string     // optional lot/traceability code
}

// defaultWidth is the character width for a typical 40mm label at font A.
const defaultWidth = 32

// Format renders the label to StarPRNT bytes via the shared encoder.
// width is the character width of the label; <= 0 falls back to defaultWidth.
func Format(l Label, width int) []byte {
	if width <= 0 {
		width = defaultWidth
	}

	e := escpos.New()
	e.Align(escpos.AlignCenter)

	e.Bold(true)
	e.Size(escpos.DoubleHeight)
	e.Line(l.Title)
	e.Size(escpos.NormalSize)
	e.Bold(false)

	e.Align(escpos.AlignLeft)
	e.Separator(width)

	if !l.PreparedAt.IsZero() {
		e.Line(fmt.Sprintf("Prep:  %s", l.PreparedAt.Format("2006-01-02 15:04")))
	}
	if l.ExpiresAt != nil {
		e.Line(fmt.Sprintf("Exp:   %s", l.ExpiresAt.Format("2006-01-02 15:04")))
	}
	for _, line := range l.Lines {
		e.Line(line)
	}

	if l.LotCode != "" {
		// TODO(#253): print this as a scannable Code128/QR barcode instead of text.
		e.Line(fmt.Sprintf("Lot:   %s", l.LotCode))
	}

	e.FullCut()
	return e.Bytes()
}
