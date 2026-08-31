package handler

import (
	"bytes"
	"encoding/json"
	"io"
	"net"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// escGSyPrint is the StarPRNT "print QR code" command (ESC GS y P). It only
// appears in the customer/kiosk QR slip (FormatDeltaQRTicket) — the kitchen
// ticket carries no QR — so its presence in the printed stream is proof that
// the QR slip was emitted alongside the kitchen ticket. See encoder.QRCode.
var escGSyPrint = []byte{0x1B, 0x1D, 0x79, 0x50}

// seedFireOrder inserts a minimal SKU + order of the given type + one item per
// printer_group. Mirrors seedSpotOrder but parameterizes the order type so a
// takeaway order can be fired. Uses INSERT OR IGNORE on the catalog rows so it
// is safe to call more than once per DB.
func seedFireOrder(t *testing.T, s *Server, orderType string, groups []string) *service.Order {
	t.Helper()
	if _, err := s.db.Exec(
		`INSERT OR IGNORE INTO pos_products (id, name) VALUES ('p1', 'Phở')`,
	); err != nil {
		t.Fatal(err)
	}
	if _, err := s.db.Exec(`
		INSERT OR IGNORE INTO pos_product_skus (id, product_id, name, sku, selling_price, is_active)
		VALUES ('sku-1', 'p1', 'Regular', 'SKU-1', 50000, 1)`); err != nil {
		t.Fatal(err)
	}

	o, err := s.orders.Create(service.CreateOrderInput{OrderType: orderType}, nil)
	if err != nil {
		t.Fatalf("create %s order: %v", orderType, err)
	}
	items := make([]service.CreateItemInput, 0, len(groups))
	for range groups {
		items = append(items, service.CreateItemInput{ProductSkuID: "sku-1", Quantity: 1})
	}
	if _, err := s.orders.AddItems(o.ID, items); err != nil {
		t.Fatalf("add items: %v", err)
	}
	o, _ = s.orders.GetByID(o.ID)
	for i, item := range o.Items {
		if i >= len(groups) {
			break
		}
		if _, err := s.db.Exec(
			`UPDATE order_items SET printer_group = ? WHERE id = ?`,
			groups[i], item.ID,
		); err != nil {
			t.Fatal(err)
		}
	}
	o, _ = s.orders.GetByID(o.ID)
	return o
}

// captureKitchenFire registers a loopback TCP "printer" as the kitchen printer,
// seeds an order of the given type, fires it, and returns the order plus every
// byte the printer received. The loopback address (127.0.0.1) passes the
// printer address validator (loopback is a permitted private range), so this
// exercises the real Connect → Print → Disconnect path.
func captureKitchenFire(t *testing.T, s *Server, orderType string) (*service.Order, []byte) {
	t.Helper()

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer ln.Close()

	done := make(chan []byte, 1)
	go func() {
		conn, err := ln.Accept()
		if err != nil {
			done <- nil
			return
		}
		b, _ := io.ReadAll(conn)
		conn.Close()
		done <- b
	}()

	if _, err := s.devices.AddPrinter(
		"kitchen",
		[]printer.DeviceType{printer.TypeKitchenPrinter},
		printer.ConnNetwork,
		ln.Addr().String(),
		printer.PrinterConfig{PaperWidth: 80},
	); err != nil {
		t.Fatalf("add printer: %v", err)
	}

	o := seedFireOrder(t, s, orderType, []string{"kitchen"})
	fired, _, ferrs := s.fireKitchenForOrder(o, "vi")
	if fired < 1 {
		t.Fatalf("%s: want fired>=1, got %d (errors=%+v)", orderType, fired, ferrs)
	}

	select {
	case b := <-done:
		return o, b
	case <-time.After(3 * time.Second):
		t.Fatalf("%s: timed out waiting for printer bytes", orderType)
		return o, nil
	}
}

// TestFireKitchen_PrintsCustomerQRSlip_AllTypes proves EVERY order type — most
// importantly takeaway — prints the customer/kiosk QR slip as the second slip on
// a kitchen fire. Takeaway used to skip it (issue #456); it must not anymore, so
// the kiosk / pickup counter can scan the QR to reconcile the food.
func TestFireKitchen_PrintsCustomerQRSlip_AllTypes(t *testing.T) {
	for _, orderType := range []string{"takeaway", "dine_in", "spot"} {
		orderType := orderType
		t.Run(orderType, func(t *testing.T) {
			s := newFireTestServer(t)
			_, data := captureKitchenFire(t, s, orderType)
			if !bytes.Contains(data, escGSyPrint) {
				t.Errorf("%s: kitchen fire did not emit the QR slip (no ESC GS y P in %d printed bytes)", orderType, len(data))
			}
		})
	}
}

// TestFireKitchen_Takeaway_QREncodesOrderJSON pins the QR payload to the JSON
// shape customer-web emits — {"orderId","orderCode","type"} — so the kiosk's
// hardware-scan fast-path reads `orderCode` off the parsed JSON and resolves the
// order via Cloud (GET /api/v1/kiosk/orders?code=). A bare order.ID (the old
// payload) matched neither the JSON fast-path nor the /customer/qr/{token}
// route, so a scanned slip always 404'd.
func TestFireKitchen_Takeaway_QREncodesOrderJSON(t *testing.T) {
	s := newFireTestServer(t)
	o, data := captureKitchenFire(t, s, "takeaway")

	if !bytes.Contains(data, escGSyPrint) {
		t.Fatalf("takeaway fire did not emit the QR slip (no ESC GS y P)")
	}
	// The encoder writes the raw payload bytes verbatim into the ESC/POS stream,
	// so the exact JSON must appear. Reproduce it with the same struct tags the
	// producer uses (service.kioskQRPayload) to get byte-identical output.
	want, err := json.Marshal(struct {
		OrderID   string `json:"orderId"`
		OrderCode string `json:"orderCode"`
		Type      string `json:"type"`
	}{o.ID, o.OrderCode, o.OrderType})
	if err != nil {
		t.Fatalf("marshal expected QR payload: %v", err)
	}
	if !bytes.Contains(data, want) {
		t.Errorf("QR slip payload is not the customer-web JSON shape; want substring %s in printed bytes", want)
	}
}
