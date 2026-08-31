package service

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain/generated/enums"
	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

// ─── Status enums (aligned with cloud) ───────────────────────────────────────

type Status string

const (
	StatusPending Status = "pending"
	// StatusConfirmed is a Cloud-origin status: a counter-pay takeaway the
	// guest submitted from customer-web. The workstation never creates one
	// locally — it arrives via pull-DOWN — but the POS engine must accept
	// item mutations on it (staff adjusts the cart at the counter before
	// taking payment), mirroring Cloud's addItems/updateItem/voidItem gates.
	StatusConfirmed Status = "confirmed"
	// StatusAwaitingConfirmation is a Cloud-origin status with no local writer:
	// a counter-pay takeaway the guest submitted from customer-web but has not
	// committed yet. It arrives via pull-DOWN (GET /workstation/orders applies no
	// status filter) and the POS list shows it, because SQLStatusTerminal covers
	// only closed/voided/expired. Cloud moves it two ways and only two —
	// commitAwaitingConfirmation to pending, voidAwaitingConfirmation to voided —
	// so those are the transitions mirrored below (#1268).
	StatusAwaitingConfirmation Status = "awaiting_confirmation"
	StatusOpen                 Status = "open"
	StatusDining               Status = "dining"
	StatusCheckout             Status = "checkout"
	StatusPaying               Status = "paying"
	StatusClosed               Status = "closed"
	StatusVoided               Status = "voided"
	// StatusExpired is a Cloud-origin terminal status: a takeaway counter-pay
	// order whose payment window elapsed, swept by the backend's
	// CancelOverdueTakeawayOrders job. Like StatusConfirmed the workstation
	// never creates one locally — it arrives via pull-DOWN — but every filter
	// that means "still on the floor" must exclude it (see SQLStatusTerminal).
	StatusExpired Status = "expired"
)

// ItemStatus aliases the canonical cloud enum so workstation and cloud agree
// on the order-item lifecycle. The print event is tracked separately via
// PrintStatus + printed_at — it is NOT a step in this state machine.
type ItemStatus = enums.OrderItemStatus

const (
	ItemStatusPending   = enums.OrderItemStatusPending
	ItemStatusPreparing = enums.OrderItemStatusPreparing
	ItemStatusReady     = enums.OrderItemStatusReady
	ItemStatusServed    = enums.OrderItemStatusServed
	ItemStatusVoided    = enums.OrderItemStatusVoided
)

// PrintStatus is a workstation-only enum tracking the kitchen-print workflow
// (POS → Star SDK → giấy bếp). The cloud does not model this — printing is a
// physical concern of the workstation. Keeping it separate from ItemStatus
// lets the kitchen UI surface "đã in" without breaking the cloud contract.
type PrintStatus string

const (
	PrintStatusPending       PrintStatus = "pending"
	PrintStatusSentToKitchen PrintStatus = "sent_to_kitchen"
	PrintStatusFailed        PrintStatus = "failed"
)

// validTransitions defines allowed state transitions (cloud-aligned).
var validTransitions = map[Status][]Status{
	StatusPending: {StatusOpen, StatusVoided},
	// Mirrors Cloud exactly: commit → pending, void → voided. Nothing else.
	// Before #1268 this row was absent, so CanTransitionTo refused every target
	// and a pulled-down counter-pay order sat on the POS list unusable, with an
	// error naming the internal state machine.
	StatusAwaitingConfirmation: {StatusPending, StatusVoided},
	// Mirrors Cloud confirmOrder (pending|confirmed → open) — staff accepts
	// the counter-pay takeaway, or voids it.
	StatusConfirmed: {StatusOpen, StatusVoided},
	StatusOpen:      {StatusDining, StatusCheckout, StatusVoided},
	StatusDining:    {StatusCheckout, StatusVoided},
	StatusCheckout:  {StatusPaying, StatusOpen, StatusVoided},
	StatusPaying:    {StatusClosed, StatusCheckout, StatusVoided},
	StatusClosed:    {},
	StatusVoided:    {},
	// Terminal, and stated rather than implied. Omitting it behaved correctly by
	// accident — a missing key refuses every transition — but left a reader
	// checking "is expired handled?" with nothing to find (#1268).
	StatusExpired: {},
}

func (s Status) CanTransitionTo(next Status) bool {
	allowed, ok := validTransitions[s]
	if !ok {
		return false
	}
	for _, a := range allowed {
		if a == next {
			return true
		}
	}
	return false
}

// ─── Domain structs (full cloud schema mirror) ────────────────────────────────

type Order struct {
	// Identity
	ID          string `json:"id"`
	CloudID     string `json:"cloud_id,omitempty"`
	OrderCode   string `json:"order_code"`
	OrderNumber int    `json:"order_number"`
	OrderType   string `json:"order_type"`

	// Status + timing
	Status     Status     `json:"status"`
	OpenedAt   time.Time  `json:"opened_at"`
	CheckoutAt *time.Time `json:"checkout_at,omitempty"`
	ClosedAt   *time.Time `json:"closed_at,omitempty"`
	VoidedAt   *time.Time `json:"voided_at,omitempty"`
	VoidReason string     `json:"void_reason,omitempty"`

	// Customer / table context
	TableID     string `json:"table_id,omitempty"`
	TableNumber string `json:"table_number,omitempty"`
	// GuestCount is nullable to match backend.customer_orders.guest_count.
	// A nil pointer serialises to JSON `null` and round-trips back as
	// null on subsequent reads. The pre-fix `int` field clamped null →
	// 0 on JSON decode and the engine then forced 0 → 1, so every
	// empty order landed with guest_count=1 in LAN mode (the
	// user-reported bug).
	GuestCount            *int   `json:"guest_count"`
	CustomerID            string `json:"customer_id,omitempty"`
	CustomerTakeawayName  string `json:"customer_takeaway_name,omitempty"`
	CustomerTakeawayPhone string `json:"customer_takeaway_phone,omitempty"`
	// ScheduledPickupTime — when a takeaway customer will collect (ISO-8601,
	// mirrored from Cloud). Printed on the kitchen + serving slips. Empty for
	// dine-in/spot.
	ScheduledPickupTime string `json:"scheduled_pickup_time,omitempty"`
	Note                string `json:"note,omitempty"`
	// Plan-LAN-offline: cashier id that opened the order locally. Synced UP
	// to Cloud's created_by_id so the audit trail records the right user.
	CreatedByID string `json:"created_by_id,omitempty"`

	// Amounts (yen integer) — except TaxAmount, which carries sub-unit precision
	// per the order's tax_rounding_decimals (option-B: 消費税 displays 93.50 while
	// TotalAmount stays whole-yen and payable; the gap is the 端数調整 line).
	Subtotal       int     `json:"subtotal"`
	DiscountAmount int     `json:"discount_amount"`
	ServiceCharge  int     `json:"service_charge"`
	TaxAmount      float64 `json:"tax_amount"`
	TotalTip       int     `json:"total_tip"`
	TotalAmount    int     `json:"total_amount"`
	PaidAmount     int     `json:"paid_amount"`
	PaymentMethod  string  `json:"payment_method,omitempty"`

	// plan-043 — tax-mode snapshot taken at order creation (mirrors
	// customer_orders.is_tax_included). Drives the engine's excluded (add-on)
	// vs included (総額表示 extraction) branch. Stable for the order's life.
	IsTaxIncluded bool `json:"is_tax_included"`

	// plan-045 — consumption-tax rounding snapshot taken at order creation
	// (mirrors customer_orders.tax_rounding_mode / tax_rounding_decimals). The
	// engine reads these off the ORDER ROW, never the live shop_settings, so a
	// settings change never re-rounds a historical order. Mode defaults "round"
	// (round/ceil/floor; legacy half_up/round_up/round_down still alias);
	// TaxRoundingDecimals nil → currency step (pre-plan-045).
	TaxRoundingMode     string `json:"tax_rounding_mode,omitempty"`
	TaxRoundingDecimals *int   `json:"tax_rounding_decimals,omitempty"`

	// Tenancy
	OrganizationID string `json:"organization_id"`
	BrandID        string `json:"brand_id"`
	BranchID       string `json:"branch_id"`

	// Tracking
	CreatedAt time.Time  `json:"created_at"`
	UpdatedAt time.Time  `json:"updated_at"`
	SyncedAt  *time.Time `json:"synced_at,omitempty"`

	// Nested
	Items []Item `json:"items"`

	// #2071 — the order's `order_conditions` discount rows (type='discount'),
	// ONE PER RATE GROUP, loaded from the ledger by the PRINT paths only
	// (handler.loadOrderDiscountLines inside normalizeOrderForPrint). Not a
	// column set: the ledger is the source, `discount_amount` above stays the
	// REQUESTED figure (#2031) and the print layer never re-derives one from
	// the other. Empty for orders whose ledger has no discount rows — the slip
	// then simply prints no discount block, it never falls back to the column.
	Discounts []OrderDiscountLine `json:"discounts,omitempty"`

	// #2170 — the order's `order_conditions` tax rows (type='tax'), ONE PER
	// RATE GROUP, loaded from the ledger by the PRINT paths only
	// (OrderEngine.OrderTaxLines inside normalizeOrderForPrint, same funnel as
	// Discounts above). When present, `buildReceiptTaxSummary` prints THESE
	// figures — post-discount, service-charge tax merged in (gap #7) — instead
	// of recomputing from gross line prices; empty means "not priced yet" and
	// the print layer falls back to the computation so an old/offline order
	// still gets paper.
	TaxLines []OrderTaxLine `json:"tax_lines,omitempty"`
}

// OrderDiscountLine is one ledger discount row as the print layer sees it
// (#2071): the applied deduction for one rate group, verbatim from
// `order_conditions` — Amount is NEGATIVE for a deduction, rounded to minor
// units, and Rate is nil for the no-rate-group fallback row the writer creates
// when an order has no taxable line. Deliberately label-free: the slip prints
// the LOCALIZED catalog word (printLabels.Discount), because the ledger label
// is frozen sale-time data ("Discount" / a coupon code) that is neither
// localized nor guaranteed Shift_JIS-encodable.
type OrderDiscountLine struct {
	Rate   *float64 `json:"rate"`
	Amount int      `json:"amount"`
}

type ItemTopping struct {
	ID                 string `json:"id"`
	OrderItemID        string `json:"order_item_id"`
	ToppingGroupItemID string `json:"topping_group_item_id"`
	ProductSkuID       string `json:"product_sku_id"`
	Name               string `json:"name,omitempty"`
	ModifierType       string `json:"modifier_type"` // "add" | "remove"
	ToppingGroupID     string `json:"topping_group_id,omitempty"`
	ToppingGroupName   string `json:"topping_group_name,omitempty"`
	Quantity           int    `json:"quantity"`
	UnitPrice          int    `json:"unit_price"`
	Note               string `json:"note,omitempty"`
}

type Item struct {
	ID              string `json:"id"`
	CustomerOrderID string `json:"customer_order_id"`
	ProductSkuID    string `json:"product_sku_id,omitempty"`
	MenuItemID      string `json:"menu_item_id,omitempty"`
	// FloatingSectionProductID records WHICH SURFACE this line was sold from —
	// set only for a spotlight line (#1392). It is what every later
	// re-resolution keys off, so the price, the rate and the topping tier stay
	// the ones the cashier was looking at.
	FloatingSectionProductID string      `json:"floating_section_product_id,omitempty"`
	MenuItemName             string      `json:"menu_item_name"`
	SkuVariantName           string      `json:"sku_variant_name,omitempty"`
	Quantity                 int         `json:"quantity"`
	UnitPrice                int         `json:"unit_price"`
	Subtotal                 int         `json:"subtotal"`
	Note                     string      `json:"note,omitempty"`
	PrinterGroup             string      `json:"printer_group"`
	Status                   ItemStatus  `json:"status"`
	PrintStatus              PrintStatus `json:"print_status"`
	// PrintedQuantity is how many units of this line have been sent to the
	// kitchen. The unprinted delta is `Quantity - PrintedQuantity` (clamp 0) —
	// a quantity bump on an already-fired line reprints only the new units.
	PrintedQuantity int           `json:"printed_quantity"`
	Toppings        []ItemTopping `json:"toppings,omitempty"`
	ServedAt        *time.Time    `json:"served_at,omitempty"`
	VoidedAt        *time.Time    `json:"voided_at,omitempty"`
	VoidReason      string        `json:"void_reason,omitempty"`
	// VoidReasonID — plan-051 (#1149): the picked VoidReason master row, when
	// staff chose from the mirrored list instead of typing free text. The
	// label snapshot still lives in VoidReason (text) so history stands alone.
	VoidReasonID string     `json:"void_reason_id,omitempty"`
	PrintedAt    *time.Time `json:"printed_at,omitempty"`
	CreatedAt    time.Time  `json:"created_at"`
	UpdatedAt    time.Time  `json:"updated_at"`

	// Promotion snapshot — set by createItem when a Happy-Hour-style match
	// fires. Cloud's CustomerOrderItemResource carries the same fields,
	// pos-web's CustomerOrderItem renders strike-through when present.
	OriginalUnitPrice *int   `json:"original_unit_price,omitempty"`
	PromotionID       string `json:"applied_promotion_id,omitempty"`
	PromotionLabel    string `json:"applied_promotion_label,omitempty"`
	// Sum of toppings × qty × unit_price after free_up_to_n. Cloud has
	// this on the item; pos-web reads `topping_subtotal` to compute total.
	ToppingSubtotal int `json:"topping_subtotal"`

	// plan-043 — consumption-tax snapshot (immutable, engine-stamped at
	// add-item time). TaxRate is a pointer so an unstamped line (nil) is
	// distinguishable from an explicit 0% line — the engine DROPS nil-rate
	// lines from pricing with a warning (#2188; the branch legacy-rate
	// fallback was deleted). TaxAmount is per-line (informational; the
	// engine recomputes per RATE GROUP, never per line).
	TaxTypeID string   `json:"tax_type_id,omitempty"`
	TaxRate   *float64 `json:"tax_rate,omitempty"`
	TaxAmount float64  `json:"tax_amount"` // option-B: sub-unit precision (display)

	// plan-045 — refund support. RefundOfItemID is the ORIGINAL line's id on a
	// refund line (empty on a normal line); its presence makes the line a refund
	// line (carries negative Quantity/Subtotal/TaxAmount, copied ProductSkuID +
	// tax snapshot). RefundedQuantity is the accumulator on the ORIGINAL line
	// (Σ refunded units; over-refund guard is RefundedQuantity + qty ≤ Quantity).
	RefundOfItemID   string `json:"refund_of_item_id,omitempty"`
	RefundedQuantity int    `json:"refunded_quantity"`
}

// IsRefund reports whether this line is a refund line (has a back-link to the
// original line it reverses). Mirrors CustomerOrderItem::is_refund (Cloud).
func (i Item) IsRefund() bool { return i.RefundOfItemID != "" }

type CreateOrderInput struct {
	// TableID is the legacy single-table field. Kept for backward compat
	// with handy + kiosk callers; pos-web sends TableIDs[]. When both are
	// present the array wins. Empty when the order is a quick / takeaway
	// with no table binding.
	TableID  string   `json:"table_id,omitempty"`
	TableIDs []string `json:"table_ids,omitempty"`
	// CustomerID binds the order to a loyalty / contact record (NOT a
	// snapshot). Cloud's POST /api/v1/pos/orders validates this against
	// the customers table; on the LAN side we trust pos-web's prior
	// `customers/find-or-create` round-trip.
	CustomerID string `json:"customer_id,omitempty"`
	OrderType  string `json:"order_type,omitempty"` // default 'spot' if empty
	// GuestCount is nullable to match backend.customer_orders.guest_count.
	// pos-web sends `undefined` (omits the field) when the cashier
	// hasn't entered a guest count — JSON decode leaves the pointer
	// nil, the engine persists it as NULL, and Cloud's `init` endpoint
	// can later first-write it without overwriting an existing value.
	GuestCount            *int              `json:"guest_count"`
	CustomerTakeawayName  string            `json:"customer_takeaway_name,omitempty"`
	CustomerTakeawayPhone string            `json:"customer_takeaway_phone,omitempty"`
	Note                  string            `json:"note,omitempty"`
	Items                 []CreateItemInput `json:"items"`
	// Status is a handler-set override for the initial order status (NOT read
	// from the request body — `json:"-"`). Staff-driven creators (POS / Handy)
	// set it to "open" so a counter takeaway opens directly instead of waiting
	// in `pending`, which is reserved for self-service (kiosk / customer)
	// takeaway orders a staff member must confirm. Empty = engine default
	// (takeaway → pending, everything else → open).
	Status string `json:"-"`
}

// resolvedTableIDs returns the canonical list of table UUIDs for this
// order — TableIDs[] wins when non-empty, falling back to the legacy
// single TableID. De-duped while preserving first-occurrence order
// (matters because tables[0] becomes the "primary" written to
// orders.table_id, mirroring CustomerOrderService::insertOrder).
func (i CreateOrderInput) resolvedTableIDs() []string {
	src := i.TableIDs
	if len(src) == 0 && i.TableID != "" {
		src = []string{i.TableID}
	}
	out := make([]string, 0, len(src))
	seen := map[string]bool{}
	for _, id := range src {
		if id == "" || seen[id] {
			continue
		}
		seen[id] = true
		out = append(out, id)
	}
	return out
}

type ToppingInput struct {
	ToppingGroupItemID string `json:"topping_group_item_id"`
	ProductSkuID       string `json:"product_sku_id"`
	Name               string `json:"name,omitempty"`
	ModifierType       string `json:"modifier_type,omitempty"` // "add" | "remove"
	ToppingGroupID     string `json:"topping_group_id,omitempty"`
	ToppingGroupName   string `json:"topping_group_name,omitempty"`
	Quantity           int    `json:"quantity"`
	UnitPrice          int    `json:"unit_price,omitempty"`
	Note               string `json:"note,omitempty"`
}

type CreateItemInput struct {
	MenuItemID     string `json:"menu_item_id,omitempty"`
	ProductSkuID   string `json:"product_sku_id,omitempty"`
	SkuVariantName string `json:"sku_variant_name,omitempty"`
	Quantity       int    `json:"quantity"`
	// UnitPrice is the per-unit price including promotion discount and topping
	// surcharges, as computed by the client (handy/POS). When non-zero it takes
	// precedence over the menu_items.price lookup so the stored unit_price
	// matches what the customer was shown. Falls back to menu_items.price when
	// zero (e.g. kiosk flow that doesn't send a price).
	UnitPrice int            `json:"selling_price,omitempty"`
	Note      string         `json:"note,omitempty"`
	Toppings  []ToppingInput `json:"toppings,omitempty"`
	// FloatingSectionProductID names the SPOTLIGHT membership the cashier
	// tapped ("this product, bought from this floating section"), as handed to
	// the client by GET /api/v1/pos/floating-sections. Empty for an ordinary
	// menu line, which is the common case. Untrusted — validated against the
	// local replica by resolveFloatingLine before anything is priced off it.
	FloatingSectionProductID string `json:"floating_section_product_id,omitempty"`
}

// ─── OrderEngine ──────────────────────────────────────────────────────────────

type OrderEngine struct {
	db       *store.DB
	promoEng *PromotionEngine
	// noTaxTypeWarnOnce keeps the "no tax type resolved" warning to one line per
	// engine instead of one per order line.
	noTaxTypeWarnOnce sync.Once
}

// warnUnstampedLinesDropped is the readable trail an unstamped line leaves
// behind when the engine drops it from the rate groups (#2188, same shape as
// warnPrintTaxOmitted / #2067): creation always stamps tax_rate, so a NULL
// here is broken input — the total goes visibly short and this line says why.
func warnUnstampedLinesDropped(where, orderID string, dropped int) {
	slog.Warn("pricing: dropped order lines with no tax_rate snapshot from the rate groups (#2188 — never priced at an invented rate)",
		"where", where,
		"order_id", orderID,
		"dropped_lines", dropped,
	)
}

// promotionSnapshotPtr returns the original unit price as a SQL-friendly
// any only when a promotion actually applied. Without a promotion the
// original_unit_price column stays NULL so the receipt UI knows to skip
// the strike-through rendering.
func promotionSnapshotPtr(original int, promotionID string) any {
	if promotionID == "" {
		return nil
	}
	return original
}

func NewOrderEngine(database *store.DB) *OrderEngine {
	return &OrderEngine{
		db:       database,
		promoEng: NewPromotionEngine(database),
	}
}

// PromotionEngine exposes the lazily-constructed engine so handlers /
// CouponEngine can re-use the same instance.
func (e *OrderEngine) PromotionEngine() *PromotionEngine { return e.promoEng }

// Create inserts a new order and its items. codeGen provides the offline-safe
// order_code; tenancy (org/brand/branch) is read from the settings table.
func (e *OrderEngine) Create(input CreateOrderInput, codeGen *LocalCodeGenerator) (*Order, error) {
	orderID := uuid.New().String()
	now := time.Now().UTC()

	orderNumber, err := e.nextOrderNumber()
	if err != nil {
		return nil, fmt.Errorf("get order number: %w", err)
	}

	var orderCode string
	if codeGen != nil {
		orderCode, err = codeGen.Next()
		if err != nil {
			return nil, fmt.Errorf("gen order code: %w", err)
		}
	} else {
		orderCode = fmt.Sprintf("WS-%06d", orderNumber)
	}

	// GuestCount intentionally NOT defaulted to 1 anymore — backend's
	// customer_orders.guest_count is nullable, and pos-web sends
	// `undefined` for an empty order. The pre-fix `if input.GuestCount
	// <= 0 { input.GuestCount = 1 }` defaulting was the user-reported
	// "tạo order trống mà tự gán 1 khách" bug. We persist NULL when
	// nil; positive integers pass through. Negative values (defensive)
	// are coerced to NULL — Cloud's spec is `minimum: 1`, so anything
	// <=0 we treat as "no value yet".
	if input.GuestCount != nil && *input.GuestCount <= 0 {
		input.GuestCount = nil
	}

	tableIDs := input.resolvedTableIDs()
	primaryTableID := ""
	if len(tableIDs) > 0 {
		primaryTableID = tableIDs[0]
	}

	orderType := input.OrderType
	if orderType == "" {
		if primaryTableID != "" {
			orderType = "dine_in"
		} else {
			orderType = "spot"
		}
	}

	// Status mirrors backend CustomerOrderService::insertOrder: a self-service
	// takeaway order (kiosk / customer) starts as `pending` (staff must confirm
	// before checkout); every other order_type goes straight to `open`. Skipping
	// this in LAN mode let cashiers check out takeaway orders Cloud still treated
	// as awaiting confirmation — sync UP then rejected them, leaving local +
	// Cloud diverged. A STAFF-driven creator (POS / Handy) instead passes an
	// explicit `open` — the cashier at the counter is the confirmation, and the
	// sync UP now sends that status so Cloud agrees (no divergence).
	status := StatusOpen
	if orderType == "takeaway" {
		status = StatusPending
	}
	if input.Status != "" {
		status = Status(input.Status)
	}

	// Pull tenancy from settings table (populated by device pairing).
	orgID := e.settingValue("organization_id")
	brandID := e.settingValue("brand_id")
	branchID := e.settingValue("workstation_branch_id")

	// plan-043 — snapshot the tax mode (総額表示) at order creation. Immutable
	// for the order's life; drives the engine's excluded vs included branch.
	includeTax := e.pricesIncludeTax()

	// plan-045 — snapshot the consumption-tax rounding rule (mode + decimals)
	// from shop_settings at creation. Immutable; the engine reads this off the
	// order row so a later settings change never re-rounds a historical order.
	taxRoundingMode := e.taxRoundingModeSetting()
	taxRoundingDecimals := e.taxRoundingDecimalsSetting()

	order := &Order{
		ID:                    orderID,
		OrderCode:             orderCode,
		OrderNumber:           orderNumber,
		OrderType:             orderType,
		Status:                status,
		OpenedAt:              now,
		TableID:               primaryTableID,
		GuestCount:            input.GuestCount,
		CustomerID:            input.CustomerID,
		CustomerTakeawayName:  input.CustomerTakeawayName,
		CustomerTakeawayPhone: input.CustomerTakeawayPhone,
		Note:                  input.Note,
		OrganizationID:        orgID,
		BrandID:               brandID,
		BranchID:              branchID,
		IsTaxIncluded:         includeTax,
		TaxRoundingMode:       taxRoundingMode,
		TaxRoundingDecimals:   taxRoundingDecimals,
		CreatedAt:             now,
		UpdatedAt:             now,
	}

	// #1114 — stamp the catalog gate the order is being priced under. Read
	// OUTSIDE the tx (settings are upserted by the pull loop, no lock needed);
	// zero-values mean "no revision pulled yet" and the order is never signed.
	catalogRevision, catalogHasToppings := e.catalogGate()

	err = e.db.Transaction(func(tx *sql.Tx) error {
		_, err := tx.Exec(`
			INSERT INTO orders (
				id, order_code, order_number, order_type, status,
				opened_at, table_id, guest_count,
				customer_id,
				customer_takeaway_name, customer_takeaway_phone, note,
				subtotal, discount_amount, service_charge, tax_amount,
				total_tip, total_amount, paid_amount,
				is_tax_included,
				tax_rounding_mode, tax_rounding_decimals,
				catalog_revision, catalog_has_toppings,
				organization_id, brand_id, branch_id,
				created_at, updated_at
			) VALUES (
				?, ?, ?, ?, ?,
				?, ?, ?,
				?,
				?, ?, ?,
				0, 0, 0, 0,
				0, 0, 0,
				?,
				?, ?,
				?, ?,
				?, ?, ?,
				?, ?
			)
		`,
			order.ID, order.OrderCode, order.OrderNumber, order.OrderType, string(order.Status),
			now.Format(time.RFC3339), nullableString(order.TableID), intPtrToNullable(order.GuestCount),
			nullableString(order.CustomerID),
			nullableString(order.CustomerTakeawayName), nullableString(order.CustomerTakeawayPhone), nullableString(order.Note),
			boolToInt(includeTax),
			taxRoundingMode, intPtrToNullable(taxRoundingDecimals),
			catalogRevision, catalogHasToppings,
			order.OrganizationID, order.BrandID, order.BranchID,
			now.Format(time.RFC3339), now.Format(time.RFC3339),
		)
		if err != nil {
			return err
		}

		// Multi-table binding mirrors backend insertOrder():
		//   * orders.table_id holds tableIDs[0] (primary) for legacy
		//     reads.
		//   * order_tables pivot holds every table the order spans —
		//     merged / shared-table flows lose the secondary bindings
		//     without it, and pos-web's TablesOverview renders them
		//     as free while a cashier is still mid-bill.
		//
		// We don't touch the local `tables.status` column. PullTables
		// runs every 5 s and wipes the row; Cloud's status is the
		// authority once the sync UP completes. The /pos/tables
		// handler derives "occupied" from the order_tables pivot
		// against local non-terminal orders, so secondary tables show
		// occupied immediately without needing a local stamp that
		// the next sync would overwrite.
		for idx, tid := range tableIDs {
			if _, err := tx.Exec(`
				INSERT INTO order_tables (order_id, table_id, sort_order, bound_at)
				VALUES (?, ?, ?, ?)
				ON CONFLICT(order_id, table_id) DO NOTHING`,
				orderID, tid, idx, now.Format(time.RFC3339)); err != nil {
				return fmt.Errorf("bind order_tables: %w", err)
			}
		}

		defaultStatus := e.shopSettingString("default_order_item_status", string(ItemStatusPending))
		for _, itemInput := range input.Items {
			item, err := e.createItem(tx, orderID, itemInput, defaultStatus, now)
			if err != nil {
				return err
			}
			order.Items = append(order.Items, *item)
		}

		// plan-043 §8 per-rate engine — groups order.Items by their per-line
		// snapshot rate (stamped in createItem) and runs the shared algorithm
		// so LAN totals match Cloud to the yen. tax_amount + service_charge
		// both surface to pos-web's cart breakdown.
		order.Subtotal = e.calculateSubtotal(order.Items)
		order.TaxAmount, order.ServiceCharge, order.TotalAmount =
			e.computeOrderTotalsForItems(order.Items, order.DiscountAmount, includeTax)

		if _, err = tx.Exec(`
			UPDATE orders SET subtotal = ?, tax_amount = ?, service_charge = ?, total_amount = ?, updated_at = ?
			WHERE id = ?
		`, order.Subtotal, order.TaxAmount, order.ServiceCharge, order.TotalAmount, now.Format(time.RFC3339), order.ID); err != nil {
			return err
		}
		// plan-043 — allocate the group tax back to the lines (Σ line == group)
		// and reflect the allocated figures onto the returned items.
		if err := e.stampLineTaxAmounts(tx, order.ID); err != nil {
			return err
		}
		return e.refreshItemTaxAmounts(tx, order.ID, order.Items)
	})
	if err != nil {
		return nil, fmt.Errorf("create order: %w", err)
	}

	return order, nil
}

func (e *OrderEngine) GetByID(id string) (*Order, error) {
	order := &Order{}
	var openedAt, createdAt, updatedAt string
	var cloudID, checkoutAt, closedAt, voidedAt, voidReason sql.NullString
	var tableID, tableNumber, custTakeawayName, custTakeawayPhone, scheduledPickup, note sql.NullString
	var paymentMethod, syncedAt sql.NullString
	var customerID sql.NullString
	var guestCount sql.NullInt64
	var isTaxIncluded int
	var taxRoundingMode sql.NullString
	var taxRoundingDecimals sql.NullInt64

	err := e.db.QueryRow(`
		SELECT
			o.id, o.cloud_id, o.order_code, o.order_number, o.order_type,
			o.status, o.opened_at, o.checkout_at, o.closed_at, o.voided_at, o.void_reason,
			o.table_id, COALESCE(t.name, COALESCE(o.table_number,'')), o.guest_count,
			o.customer_takeaway_name, o.customer_takeaway_phone, o.scheduled_pickup_time, o.note,
			o.subtotal, o.discount_amount, o.service_charge, o.tax_amount,
			o.total_tip, o.total_amount, o.paid_amount, o.payment_method,
			o.organization_id, o.brand_id, o.branch_id,
			o.created_at, o.updated_at, o.synced_at,
			o.customer_id, COALESCE(o.is_tax_included, 0),
			o.tax_rounding_mode, o.tax_rounding_decimals
		FROM orders o
		LEFT JOIN tables t ON t.id = o.table_id
		WHERE o.id = ?
	`, id).Scan(
		&order.ID, &cloudID, &order.OrderCode, &order.OrderNumber, &order.OrderType,
		&order.Status, &openedAt, &checkoutAt, &closedAt, &voidedAt, &voidReason,
		&tableID, &tableNumber, &guestCount,
		&custTakeawayName, &custTakeawayPhone, &scheduledPickup, &note,
		&order.Subtotal, &order.DiscountAmount, &order.ServiceCharge, &order.TaxAmount,
		&order.TotalTip, &order.TotalAmount, &order.PaidAmount, &paymentMethod,
		&order.OrganizationID, &order.BrandID, &order.BranchID,
		&createdAt, &updatedAt, &syncedAt,
		&customerID, &isTaxIncluded,
		&taxRoundingMode, &taxRoundingDecimals,
	)
	if err != nil {
		return nil, fmt.Errorf("get order: %w", err)
	}
	order.IsTaxIncluded = isTaxIncluded == 1
	// plan-045 — carry the rounding snapshot; blank mode reads as round.
	order.TaxRoundingMode = "round"
	if taxRoundingMode.Valid && taxRoundingMode.String != "" {
		order.TaxRoundingMode = taxRoundingMode.String
	}
	if taxRoundingDecimals.Valid {
		v := int(taxRoundingDecimals.Int64)
		order.TaxRoundingDecimals = &v
	}

	if guestCount.Valid {
		v := int(guestCount.Int64)
		order.GuestCount = &v
	}
	order.CloudID = cloudID.String
	order.TableID = tableID.String
	order.TableNumber = tableNumber.String
	order.CustomerID = customerID.String
	order.CustomerTakeawayName = custTakeawayName.String
	order.CustomerTakeawayPhone = custTakeawayPhone.String
	order.ScheduledPickupTime = scheduledPickup.String
	order.Note = note.String
	order.VoidReason = voidReason.String
	order.PaymentMethod = paymentMethod.String
	order.OpenedAt, _ = time.Parse(time.RFC3339, openedAt)
	order.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
	order.UpdatedAt, _ = time.Parse(time.RFC3339, updatedAt)
	if checkoutAt.Valid && checkoutAt.String != "" {
		t, _ := time.Parse(time.RFC3339, checkoutAt.String)
		order.CheckoutAt = &t
	}
	if closedAt.Valid && closedAt.String != "" {
		t, _ := time.Parse(time.RFC3339, closedAt.String)
		order.ClosedAt = &t
	}
	if voidedAt.Valid && voidedAt.String != "" {
		t, _ := time.Parse(time.RFC3339, voidedAt.String)
		order.VoidedAt = &t
	}
	if syncedAt.Valid && syncedAt.String != "" {
		t, _ := time.Parse(time.RFC3339, syncedAt.String)
		order.SyncedAt = &t
	}

	items, err := e.getItems(order.ID)
	if err != nil {
		return nil, err
	}
	order.Items = items

	return order, nil
}

// ListFilters mirrors the OrderListFilters interface pos-web's
// orderService.list builds — the LAN handler decodes the URL query into
// this struct and the engine applies them with parameterised SQL.
type ListFilters struct {
	// Comma-separated list of statuses. Empty = no status filter.
	Statuses  []string
	OrderType string
	// BranchID scopes results to one branch. The workstation is paired to a
	// single branch, but its local DB can hold OTHER branches' orders (dev seed
	// data, or rows kept across a re-pair per plan-818). The LAN /pos/orders
	// handler sets this to the paired branch so it never leaks another branch's
	// orders into the overview / takeaway feed — matching Cloud, which scopes
	// by the shop slug. Empty = no branch filter (e.g. customer-scoped lookups).
	BranchID string
	// TableID scopes results to orders bound to one table — the per-table
	// history view (pos-web). The link is persistent locally: the primary
	// orders.table_id plus any merged tables in the order_tables pivot, and
	// neither is cleared on close/void — so this returns full history for the
	// table, unlike Cloud which only keeps the live tables.current_order_id.
	TableID    string
	CustomerID string
	Search     string
	DateFrom   string // YYYY-MM-DD inclusive
	DateTo     string // YYYY-MM-DD inclusive
	Sort       string // "opened_at_desc" (default) | "opened_at_asc"
	Page       int    // 1-based
	PerPage    int    // capped 1..200
	IncludeAll bool   // when true, no implicit "not closed/voided" filter
}

// ListByFilters mirrors Cloud's CustomerOrderController::index. Returns
// rows + total for the meta envelope so the handler can render the
// paginated response shape pos-web's PaginatedResponse expects.
func (e *OrderEngine) ListByFilters(f ListFilters) (rows []Order, total int, err error) {
	if f.Page < 1 {
		f.Page = 1
	}
	if f.PerPage < 1 {
		f.PerPage = 20
	}
	if f.PerPage > 200 {
		f.PerPage = 200
	}

	clauses := []string{}
	args := []any{}

	if len(f.Statuses) > 0 {
		placeholders := strings.Repeat("?,", len(f.Statuses))
		placeholders = placeholders[:len(placeholders)-1]
		clauses = append(clauses, "o.status IN ("+placeholders+")")
		for _, s := range f.Statuses {
			args = append(args, s)
		}
	} else if !f.IncludeAll {
		// Default: same as ListActive — exclude terminal states.
		clauses = append(clauses, "o.status NOT IN "+SQLStatusTerminal)
	}
	if f.OrderType != "" {
		clauses = append(clauses, "o.order_type = ?")
		args = append(args, f.OrderType)
	}
	if f.BranchID != "" {
		clauses = append(clauses, "o.branch_id = ?")
		args = append(args, f.BranchID)
	}
	if f.TableID != "" {
		// Primary table OR any merged table in the pivot — mirrors the join in
		// handleLocalPosTables. Both survive close/void, so history is complete.
		clauses = append(clauses,
			"(o.table_id = ? OR EXISTS (SELECT 1 FROM order_tables ot WHERE ot.order_id = o.id AND ot.table_id = ?))")
		args = append(args, f.TableID, f.TableID)
	}
	if f.CustomerID != "" {
		clauses = append(clauses, "o.customer_id = ?")
		args = append(args, f.CustomerID)
	}
	if f.Search != "" {
		clauses = append(clauses, "(o.order_code LIKE ? OR COALESCE(o.note,'') LIKE ?)")
		like := "%" + f.Search + "%"
		args = append(args, like, like)
	}
	if f.DateFrom != "" {
		clauses = append(clauses, "DATE(o.opened_at) >= DATE(?)")
		args = append(args, f.DateFrom)
	}
	if f.DateTo != "" {
		clauses = append(clauses, "DATE(o.opened_at) <= DATE(?)")
		args = append(args, f.DateTo)
	}

	where := ""
	if len(clauses) > 0 {
		where = "WHERE " + strings.Join(clauses, " AND ")
	}

	// Total first so we can populate the meta envelope.
	if err = e.db.QueryRow("SELECT COUNT(*) FROM orders o "+where, args...).Scan(&total); err != nil {
		return nil, 0, fmt.Errorf("count orders: %w", err)
	}

	order := "o.opened_at DESC"
	if f.Sort == "opened_at_asc" {
		order = "o.opened_at ASC"
	}

	offset := (f.Page - 1) * f.PerPage
	pageArgs := append(args, f.PerPage, offset)

	sqlRows, err := e.db.Query(`
		SELECT
			o.id, o.cloud_id, o.order_code, o.order_number, o.order_type,
			o.status, o.opened_at, o.checkout_at, o.closed_at, o.voided_at,
			COALESCE(o.void_reason,''),
			COALESCE(o.table_id,''), COALESCE(t.name, COALESCE(o.table_number,'')),
			o.guest_count,
			COALESCE(o.customer_takeaway_name,''), COALESCE(o.customer_takeaway_phone,''),
			COALESCE(o.note,''),
			o.subtotal, o.discount_amount, o.service_charge, o.tax_amount,
			o.total_tip, o.total_amount, o.paid_amount,
			COALESCE(o.payment_method,''),
			o.organization_id, o.brand_id, o.branch_id,
			o.created_at, o.updated_at,
			COALESCE(o.customer_id,'')
		FROM orders o
		LEFT JOIN tables t ON t.id = o.table_id
		`+where+`
		ORDER BY `+order+`
		LIMIT ? OFFSET ?`, pageArgs...)
	if err != nil {
		return nil, 0, fmt.Errorf("list orders: %w", err)
	}
	defer sqlRows.Close()

	for sqlRows.Next() {
		var o Order
		var cloudID, checkoutAt, closedAt, voidedAt sql.NullString
		var openedAt, createdAt, updatedAt string
		var guestCount sql.NullInt64
		if err := sqlRows.Scan(
			&o.ID, &cloudID, &o.OrderCode, &o.OrderNumber, &o.OrderType,
			&o.Status, &openedAt, &checkoutAt, &closedAt, &voidedAt,
			&o.VoidReason,
			&o.TableID, &o.TableNumber, &guestCount,
			&o.CustomerTakeawayName, &o.CustomerTakeawayPhone, &o.Note,
			&o.Subtotal, &o.DiscountAmount, &o.ServiceCharge, &o.TaxAmount,
			&o.TotalTip, &o.TotalAmount, &o.PaidAmount,
			&o.PaymentMethod,
			&o.OrganizationID, &o.BrandID, &o.BranchID,
			&createdAt, &updatedAt,
			&o.CustomerID,
		); err != nil {
			return nil, 0, fmt.Errorf("scan order: %w", err)
		}
		if guestCount.Valid {
			v := int(guestCount.Int64)
			o.GuestCount = &v
		}
		o.CloudID = cloudID.String
		o.OpenedAt, _ = time.Parse(time.RFC3339, openedAt)
		o.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
		o.UpdatedAt, _ = time.Parse(time.RFC3339, updatedAt)
		if checkoutAt.Valid && checkoutAt.String != "" {
			t, _ := time.Parse(time.RFC3339, checkoutAt.String)
			o.CheckoutAt = &t
		}
		if closedAt.Valid && closedAt.String != "" {
			t, _ := time.Parse(time.RFC3339, closedAt.String)
			o.ClosedAt = &t
		}
		if voidedAt.Valid && voidedAt.String != "" {
			t, _ := time.Parse(time.RFC3339, voidedAt.String)
			o.VoidedAt = &t
		}
		rows = append(rows, o)
	}
	return rows, total, nil
}

// listOrdersWith runs the order-board projection with a caller-supplied
// WHERE/ORDER BY/LIMIT tail. ListActive, ListRecentClosed and
// ListRecentCancelled differ only in that tail; they used to be hand-copied
// bodies, so every fix had to be applied three times or not at all.
func (e *OrderEngine) listOrdersWith(tail string, args ...any) ([]Order, error) {
	rows, err := e.db.Query(`
		SELECT
			o.id, o.order_code, o.order_number, o.order_type, o.status, o.opened_at,
			o.table_id, COALESCE(t.name, COALESCE(o.table_number,'')), o.guest_count, o.note,
			o.subtotal, o.discount_amount, o.service_charge, o.tax_amount,
			o.total_tip, o.total_amount, o.paid_amount, o.payment_method,
			o.organization_id, o.brand_id, o.branch_id,
			o.created_at, o.updated_at
		FROM orders o
		LEFT JOIN tables t ON t.id = o.table_id
		`+tail, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var orders []Order
	for rows.Next() {
		var o Order
		var openedAt, createdAt, updatedAt string
		var tableID, tableNumber, note, paymentMethod sql.NullString
		var guestCount sql.NullInt64

		if err := rows.Scan(
			&o.ID, &o.OrderCode, &o.OrderNumber, &o.OrderType, &o.Status, &openedAt,
			&tableID, &tableNumber, &guestCount, &note,
			&o.Subtotal, &o.DiscountAmount, &o.ServiceCharge, &o.TaxAmount,
			&o.TotalTip, &o.TotalAmount, &o.PaidAmount, &paymentMethod,
			&o.OrganizationID, &o.BrandID, &o.BranchID,
			&createdAt, &updatedAt,
		); err != nil {
			return nil, err
		}
		if guestCount.Valid {
			v := int(guestCount.Int64)
			o.GuestCount = &v
		}
		o.TableID = tableID.String
		o.TableNumber = tableNumber.String
		o.Note = note.String
		o.PaymentMethod = paymentMethod.String
		o.OpenedAt, _ = time.Parse(time.RFC3339, openedAt)
		o.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
		o.UpdatedAt, _ = time.Parse(time.RFC3339, updatedAt)

		items, err := e.getItems(o.ID)
		if err != nil {
			return nil, err
		}
		o.Items = items
		orders = append(orders, o)
	}
	return orders, rows.Err()
}

// ListActive returns the orders still on the floor — everything that is not
// terminal. `expired` counts as terminal: it is Cloud's auto-cancellation of an
// unpaid takeaway, not a bill anyone will ever collect (#149).
func (e *OrderEngine) ListActive() ([]Order, error) {
	orders, err := e.listOrdersWith(`
		WHERE o.status NOT IN ` + SQLStatusTerminal + `
		ORDER BY o.opened_at DESC
	`)
	if err != nil {
		return nil, fmt.Errorf("list active orders: %w", err)
	}
	return orders, nil
}

// ListRecentClosed returns the most recently paid/closed orders (newest first),
// capped at limit. Powers the workstation's "paid bills" view so kiosk/customer
// orders confirmed in Cloud — which arrive already closed via pull-down and are
// therefore invisible on the active board — stay visible to staff.
func (e *OrderEngine) ListRecentClosed(limit int) ([]Order, error) {
	orders, err := e.listOrdersWith(`
		WHERE o.status = 'closed'
		ORDER BY o.updated_at DESC
		LIMIT ?
	`, clampListLimit(limit))
	if err != nil {
		return nil, fmt.Errorf("list closed orders: %w", err)
	}
	return orders, nil
}

// ListRecentCancelled returns the most recently cancelled orders (newest
// first), capped at limit — staff voids AND Cloud-expired takeaways together,
// because to the operator both are simply "đã huỷ". Powers the workstation's
// cancelled-orders tab: before #149 these orders had no view at all (a void
// vanished from the board, an expiry never left it).
func (e *OrderEngine) ListRecentCancelled(limit int) ([]Order, error) {
	orders, err := e.listOrdersWith(`
		WHERE o.status IN `+SQLStatusCancelled+`
		ORDER BY o.updated_at DESC
		LIMIT ?
	`, clampListLimit(limit))
	if err != nil {
		return nil, fmt.Errorf("list cancelled orders: %w", err)
	}
	return orders, nil
}

func clampListLimit(limit int) int {
	if limit <= 0 || limit > 500 {
		return 100
	}
	return limit
}

func (e *OrderEngine) ListByDate(date string) ([]Order, error) {
	rows, err := e.db.Query(`
		SELECT
			o.id, o.order_code, o.order_number, o.order_type, o.status, o.opened_at,
			o.table_id, COALESCE(t.name, COALESCE(o.table_number,'')), o.guest_count, o.note,
			o.subtotal, o.discount_amount, o.service_charge, o.tax_amount,
			o.total_tip, o.total_amount, o.paid_amount, o.payment_method,
			o.closed_at, o.voided_at,
			o.organization_id, o.brand_id, o.branch_id,
			o.created_at, o.updated_at
		FROM orders o
		LEFT JOIN tables t ON t.id = o.table_id
		WHERE date(o.opened_at) = ?
		ORDER BY o.opened_at DESC
	`, date)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var orders []Order
	for rows.Next() {
		var o Order
		var openedAt, createdAt, updatedAt string
		var tableID, tableNumber, note, paymentMethod, closedAt, voidedAt sql.NullString
		var guestCount sql.NullInt64

		if err := rows.Scan(
			&o.ID, &o.OrderCode, &o.OrderNumber, &o.OrderType, &o.Status, &openedAt,
			&tableID, &tableNumber, &guestCount, &note,
			&o.Subtotal, &o.DiscountAmount, &o.ServiceCharge, &o.TaxAmount,
			&o.TotalTip, &o.TotalAmount, &o.PaidAmount, &paymentMethod,
			&closedAt, &voidedAt,
			&o.OrganizationID, &o.BrandID, &o.BranchID,
			&createdAt, &updatedAt,
		); err != nil {
			return nil, err
		}
		if guestCount.Valid {
			v := int(guestCount.Int64)
			o.GuestCount = &v
		}
		o.TableID = tableID.String
		o.TableNumber = tableNumber.String
		o.Note = note.String
		o.PaymentMethod = paymentMethod.String
		o.OpenedAt, _ = time.Parse(time.RFC3339, openedAt)
		o.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
		o.UpdatedAt, _ = time.Parse(time.RFC3339, updatedAt)
		if closedAt.Valid && closedAt.String != "" {
			t, _ := time.Parse(time.RFC3339, closedAt.String)
			o.ClosedAt = &t
		}
		if voidedAt.Valid && voidedAt.String != "" {
			t, _ := time.Parse(time.RFC3339, voidedAt.String)
			o.VoidedAt = &t
		}

		items, _ := e.getItems(o.ID)
		o.Items = items
		orders = append(orders, o)
	}
	return orders, nil
}

func (e *OrderEngine) UpdateStatus(id string, newStatus Status) error {
	order, err := e.GetByID(id)
	if err != nil {
		return err
	}

	if !order.Status.CanTransitionTo(newStatus) {
		return fmt.Errorf("cannot transition from %s to %s", order.Status, newStatus)
	}

	now := time.Now().UTC().Format(time.RFC3339)

	// Set timing columns for terminal transitions.
	switch newStatus {
	case StatusClosed:
		_, err = e.db.Exec(
			"UPDATE orders SET status = ?, closed_at = ?, updated_at = ? WHERE id = ?",
			newStatus, now, now, id,
		)
	case StatusVoided:
		_, err = e.db.Exec(
			"UPDATE orders SET status = ?, voided_at = ?, updated_at = ? WHERE id = ?",
			newStatus, now, now, id,
		)
	case StatusCheckout:
		_, err = e.db.Exec(
			"UPDATE orders SET status = ?, checkout_at = ?, updated_at = ? WHERE id = ?",
			newStatus, now, now, id,
		)
	default:
		_, err = e.db.Exec(
			"UPDATE orders SET status = ?, updated_at = ? WHERE id = ?",
			newStatus, now, id,
		)
	}
	return err
}

func (e *OrderEngine) RecordPayment(orderID string, method string) error {
	now := time.Now().UTC()
	// Fetch total_amount to set paid_amount. Returning sql.ErrNoRows here
	// means the caller asked us to mark a non-existent order as paid —
	// previously we silently swallowed the error and ran the UPDATE with
	// total_amount=0 (UPDATE matched 0 rows, but the handler still
	// broadcast order_paid + wrote an audit row for an order that
	// doesn't exist). Fail fast so the handler can return 404.
	var totalAmount int
	if err := e.db.QueryRow(
		"SELECT total_amount FROM orders WHERE id = ?", orderID,
	).Scan(&totalAmount); err != nil {
		return fmt.Errorf("record payment: lookup order %s: %w", orderID, err)
	}

	res, err := e.db.Exec(`
		UPDATE orders SET
			status = ?,
			payment_method = ?,
			closed_at = ?,
			paid_amount = ?,
			updated_at = ?
		WHERE id = ?
	`, StatusClosed, method, now.Format(time.RFC3339), totalAmount, now.Format(time.RFC3339), orderID)
	if err != nil {
		return err
	}
	// Defence in depth: belt + braces. The lookup above already rules out
	// a missing order, but a race (concurrent void) could delete the row
	// between SELECT and UPDATE. Surfacing the no-op keeps the audit log
	// honest.
	if n, _ := res.RowsAffected(); n == 0 {
		return fmt.Errorf("record payment: order %s not found or already closed", orderID)
	}
	return nil
}

func (e *OrderEngine) AddItems(orderID string, items []CreateItemInput) ([]Item, error) {
	order, err := e.GetByID(orderID)
	if err != nil {
		return nil, err
	}
	if !OrderItemsMutable(order.Status) {
		return nil, errOrderItemsNotMutable(order.Status)
	}

	now := time.Now().UTC()
	var created []Item

	// Read once outside the transaction so all items in this batch get the
	// same default status. Falls back to pending when setting is absent.
	defaultStatus := e.shopSettingString("default_order_item_status", string(ItemStatusPending))

	err = e.db.Transaction(func(tx *sql.Tx) error {
		for _, input := range items {
			item, err := e.createItem(tx, orderID, input, defaultStatus, now)
			if err != nil {
				return err
			}
			created = append(created, *item)
		}

		// plan-043 §8 per-rate engine — group the order's non-voided lines by
		// their per-line snapshot rate directly in SQL (the tx sees the rows
		// createItem just inserted; `e.getItems` would use a separate pooled
		// connection that still reads the pre-tx snapshot). Reads the order's
		// stored discount_amount + is_tax_included so a later AddItems doesn't
		// drop a discount/coupon already applied or flip the tax mode.
		subtotal, _, taxAmount, serviceCharge, totalAmount, pricing, err := e.computeOrderTotalsFromDB(tx, orderID)
		if err != nil {
			return err
		}

		if _, err = tx.Exec(`
			UPDATE orders SET subtotal = ?, tax_amount = ?, service_charge = ?, total_amount = ?, updated_at = ?
			WHERE id = ?
		`, subtotal, taxAmount, serviceCharge, totalAmount, now.Format(time.RFC3339), orderID); err != nil {
			return err
		}
		// plan-043 — allocate the group tax back to every non-voided line (Σ line
		// == group) and reflect the allocated figures onto the returned items.
		// #2032 — sổ điều kiện phải sinh ra ngay tại máy trạm, không đợi sync UP:
		// POS/KDS đọc chính bảng này qua `conditions[]`.
		if err := e.writeOrderConditionsTx(tx, orderID, pricing, pricing.Discount); err != nil {
			return err
		}
		if err := e.stampLineTaxAmounts(tx, orderID); err != nil {
			return err
		}
		return e.refreshItemTaxAmounts(tx, orderID, created)
	})

	return created, err
}

// MarkItemPrinted records that `printedQty` units of the line have now been
// sent to the kitchen. Pass the line's full current quantity after a normal
// fire — `printed_quantity` then equals `quantity` so the unprinted delta is
// 0. After a later quantity bump the delta reappears and only the new units
// reprint.
//
// Print is a workstation-local concern, not an item-lifecycle transition.
// Cloud sees status pending → preparing → ready → served; "đã in xuống bếp"
// lives on print_status + printed_quantity + printed_at so the kitchen UI
// keeps the signal without polluting the cloud enum.
func (e *OrderEngine) MarkItemPrinted(itemID string, printedQty int, printedAt string) error {
	if printedQty < 0 {
		printedQty = 0
	}
	_, err := e.db.Exec(
		"UPDATE order_items SET print_status = ?, printed_quantity = ?, printed_at = ?, updated_at = ? WHERE id = ?",
		string(PrintStatusSentToKitchen), printedQty, printedAt, printedAt, itemID,
	)
	return err
}

// MarkItemPrintFailed flags a line whose kitchen print did not succeed (printer
// offline or errored). Unlike MarkItemPrinted it deliberately does NOT advance
// printed_quantity, so the unprinted delta stays open and a later re-fire
// reprints the same units. print_status='failed' is what the KDS
// "show only fired" filter treats as visible, so the kitchen still sees the
// item on the KDS tablet even though no paper came out.
func (e *OrderEngine) MarkItemPrintFailed(itemID string, at string) error {
	_, err := e.db.Exec(
		"UPDATE order_items SET print_status = ?, updated_at = ? WHERE id = ?",
		string(PrintStatusFailed), at, itemID,
	)
	return err
}

func (e *OrderEngine) createItem(tx *sql.Tx, orderID string, input CreateItemInput, defaultItemStatus string, now time.Time) (*Item, error) {
	// Resolve the menu_items row for name, price, and printer_group.
	//
	// Lookup priority:
	//   1. product_sku_id matches menu_items.sku_id  — handy sends the Cloud SKU UUID
	//      from the handy_menu_cache (migration 013 added the sku_id column).
	//   2. id matches directly                        — legacy path: menu_item_id or
	//      local-only items where sku_id IS NULL.
	var menuItemID, name, printerGroup string
	var price int

	lookupID := input.ProductSkuID
	if lookupID == "" {
		lookupID = input.MenuItemID
	}

	// #1239 — resolve by the MENU LINE the caller tapped, when it told us which
	// one. sku_id is NOT unique in menu_items: the workstation pulls every
	// active menu for the day (Cloud uses listActiveBranchMenusForShopByDay,
	// plural) and each menu line lands as its own row, deduped ON CONFLICT(id).
	// One product on both a dine-in and a takeaway menu is not an edge case —
	// it is how consumption context is modelled, the takeaway line carrying the
	// REDUCED rate. Preferring sku_id picked an arbitrary one of the two, and
	// with no ORDER BY the winner was whatever SQLite happened to return first.
	err := sql.ErrNoRows
	if input.MenuItemID != "" {
		err = e.db.QueryRow(
			"SELECT id, name, price, printer_group FROM menu_items WHERE id = ? AND is_active = 1", input.MenuItemID,
		).Scan(&menuItemID, &name, &price, &printerGroup)
	}

	if err != nil {
		err = e.db.QueryRow(
			"SELECT id, name, price, printer_group FROM menu_items WHERE sku_id = ? AND is_active = 1 LIMIT 1", lookupID,
		).Scan(&menuItemID, &name, &price, &printerGroup)
	}
	if err != nil {
		// Fall back: lookup by primary key (local-only items, or legacy callers).
		err = e.db.QueryRow(
			"SELECT id, name, price, printer_group FROM menu_items WHERE id = ?", lookupID,
		).Scan(&menuItemID, &name, &price, &printerGroup)
	}
	if err != nil {
		// FINAL FALLBACK: pos-web LAN mode loads its menu from the
		// pos_* schema (plan-022) which is the canonical source for
		// every SKU shown on the catalog tiles. menu_items is only
		// populated by the legacy /workstation/menu sync — it can
		// be empty or stale for shops migrated to the new replica.
		// Resolve here so add-to-cart never returns 500 when the
		// SKU exists in pos_product_skus + pos_products.
		//
		// menu_items.printer_group is workstation-local config (which
		// kitchen station prints the ticket). pos_products has no
		// equivalent; default 'kitchen' matches the column's own
		// default + lets staff configure it later without breaking
		// orders today.
		var (
			productName  string
			variantName  sql.NullString
			sellingPrice int
		)
		err2 := e.db.QueryRow(`
			SELECT p.name, ps.name, ps.selling_price
			FROM pos_product_skus ps
			JOIN pos_products p ON p.id = ps.product_id
			WHERE ps.id = ? AND ps.is_active = 1`,
			lookupID,
		).Scan(&productName, &variantName, &sellingPrice)
		if err2 == nil {
			menuItemID = lookupID
			if variantName.Valid && variantName.String != "" {
				name = fmt.Sprintf("%s · %s", productName, variantName.String)
			} else {
				name = productName
			}
			price = sellingPrice
			printerGroup = "kitchen"
			err = nil
		}
	}
	if err != nil {
		return nil, fmt.Errorf("menu item %s not found: %w", lookupID, err)
	}
	if input.MenuItemID == "" {
		input.MenuItemID = menuItemID
	}

	// Resolve sku_variant_name from pos_product_skus when pos-web
	// didn't include it. Cloud's CustomerOrderItemResource sets this
	// to ProductSku.name (the variant label like "Regular" / "Large"),
	// and pos-web reads it to display variant suffix on the cart line.
	// Without the lookup the LAN-mode cart line shows just the product
	// name + no variant — visible drift vs cloud mode.
	if input.SkuVariantName == "" && input.ProductSkuID != "" {
		var variantName sql.NullString
		_ = e.db.QueryRow(
			`SELECT name FROM pos_product_skus WHERE id = ?`, input.ProductSkuID,
		).Scan(&variantName)
		if variantName.Valid {
			input.SkuVariantName = variantName.String
		}
	}

	// PRICE PARITY (menu ↔ order): pos-web's catalog tile prices every SKU
	// from pos_product_skus.selling_price (local_pos_menus.go::loadProductSkus).
	// menu_items.price is the LEGACY /workstation/menu sync value and can be
	// stale for shops migrated to the pos_* replica — using it makes the cart
	// line disagree with the tile the operator just tapped. So when we have a
	// real ProductSku UUID and the replica has its selling_price, that value is
	// the source of truth for the base price. menu_items still supplies name +
	// printer_group above and remains the price fallback for SKUs absent from
	// the pos_* replica (the product_sku_id == "" kiosk/handy legacy path is
	// untouched — no ProductSku UUID → no override). is_active=1 matches the
	// tile query so a deactivated SKU never silently reprices.
	if input.ProductSkuID != "" {
		var sellingPrice int
		if err := e.db.QueryRow(
			`SELECT selling_price FROM pos_product_skus WHERE id = ? AND is_active = 1`,
			input.ProductSkuID,
		).Scan(&sellingPrice); err == nil {
			price = sellingPrice
		}
	}

	// #1392 — the SPOTLIGHT surface, when the client named one. Validated here
	// (and only here) for the whole line: the same attribution then drives the
	// tax tier and the topping tier below, so a line cannot be priced from the
	// spotlight and taxed from the menu.
	floating, isFloating := e.resolveFloatingLine(input.FloatingSectionProductID, input.ProductSkuID)
	if !isFloating {
		// Nothing resolved → the id names no membership this SKU belongs to.
		// Drop it rather than persist a reference the reading side would have
		// to second-guess forever.
		input.FloatingSectionProductID = ""
	} else if floating.PromoPrice.Valid {
		// Mirror Cloud's precedence exactly (CustomerOrderPricingResolution:
		// "floating-section price if LOWER (never higher)"). A spotlight is a
		// promotion; a membership priced ABOVE the menu must not raise what the
		// customer pays just because the tile was tapped.
		if promo := int(floating.PromoPrice.Int64); promo < price {
			price = promo
		}
	}

	// Client-supplied unit price (includes promotion + toppings) takes
	// precedence over the catalogue price from menu_items.
	if input.UnitPrice > 0 {
		price = input.UnitPrice
	}

	if input.Quantity <= 0 {
		input.Quantity = 1
	}

	// Apply Happy-Hour / scheduled promotions. The snapshot of the price
	// BEFORE the discount goes into original_unit_price so receipts can
	// render "X̶ Y" and refunds know what to undo.
	originalPrice := price
	var promotionID, promotionLabel string
	if e.promoEng != nil && input.ProductSkuID != "" {
		final, match, perr := e.promoEng.ApplyToItem(input.ProductSkuID, price, now)
		if perr == nil && match != nil && final != price {
			price = final
			promotionID = match.ID
			promotionLabel = match.Name
		}
	}

	// Resolve topping snapshot fields from the local pos_* menu replica
	// before pricing. pos-web's ToppingSelection payload only carries
	// (topping_group_item_id, product_sku_id, quantity, note) — Cloud's
	// CustomerOrderService snapshots name/group/modifier_type/unit_price
	// server-side from ProductToppingGroup; workstation must do the same
	// or the cart sidebar renders blank topping rows with zero price.
	// #1392 — a spotlight line takes the spotlight's tier-1 overrides INSTEAD
	// of the menu's (the table exists for exactly that, 067 last block), so the
	// guest is charged what the tile they tapped displayed.
	owner := menuToppingOwner(e.menuProductIDForSku(input.ProductSkuID))
	if isFloating {
		owner = floatingToppingOwner(floating.ID)
	}
	for i := range input.Toppings {
		e.resolveToppingSnapshot(&input.Toppings[i], input.ProductSkuID, owner)
	}

	// Topping pricing — apply each group's price_strategy / free_quantity
	// (flat vs free_up_to_n) exactly like pos-web's topping-pricing.ts and
	// Cloud's ToppingPricingService, so the LAN cart line matches the menu
	// tile + dialog running total. A flat sum here over-charged free_up_to_n
	// groups (BR-OI06 subtotal drift). priceLineAcrossGroups runs AFTER the
	// snapshot loop so ToppingGroupID + UnitPrice are hydrated.
	groupPricing := e.loadToppingGroupPricing(input.Toppings)
	priced := make([]pricedTopping, 0, len(input.Toppings))
	for _, t := range input.Toppings {
		// Quantity < 1 is normalized to 1 inside priceLine (mirroring the TS
		// Math.max(1, quantity)), so pass it through as-is.
		priced = append(priced, pricedTopping{
			ToppingGroupID: t.ToppingGroupID,
			UnitPrice:      t.UnitPrice,
			Quantity:       t.Quantity,
		})
	}
	toppingSubtotal := priceLineAcrossGroups(priced, groupPricing)
	itemSubtotal := input.Quantity * (price + toppingSubtotal)

	// BR-OI06 merge: before inserting a new row, look for a still-
	// pending order_item on this order with identical (product_sku_id,
	// unit_price, topping_subtotal, note) AND an identical topping
	// tuple. If found, bump quantity + subtotal of the existing row
	// instead of creating a duplicate. Without this, two consecutive
	// taps on the same "+" in pos-web's catalog produced two cart
	// lines instead of stacking the quantity — visible drift vs Cloud
	// mode which has merged for years (BR-OI06).
	mergeKey := buildToppingMergeKey(input.Toppings)
	merged, err := e.findMergeableItem(tx, orderID, input, price, toppingSubtotal, mergeKey, defaultItemStatus)
	if err != nil {
		return nil, fmt.Errorf("merge lookup: %w", err)
	}
	if merged != nil {
		newQty := merged.Quantity + input.Quantity
		newSubtotal := newQty * (price + toppingSubtotal)
		if _, err := tx.Exec(`
			UPDATE order_items
			SET quantity = ?, subtotal = ?, updated_at = ?
			WHERE id = ?`,
			newQty, newSubtotal, now.Format(time.RFC3339), merged.ID,
		); err != nil {
			return nil, fmt.Errorf("merge update: %w", err)
		}
		merged.Quantity = newQty
		merged.Subtotal = newSubtotal
		merged.UpdatedAt = now
		return merged, nil
	}

	// #1099 §7 — resolve + stamp the immutable per-line tax snapshot. The
	// menu_items row supplies the line's tax_type_id (resolved MenuProduct→
	// Product override, synced); the resolver applies the chain default
	// fallback — ONE rate, no order-type branch, no special-casing.
	// Resolution is offline-safe: a shop with nothing synced yields a
	// no-snapshot line (tax_rate NULL), which the engine then DROPS from
	// pricing with a warning — 0%, never an invented rate (#2188).
	// #1239 — take tax from the ROW the name and price came from, rather than
	// re-running the same ambiguous lookup. Passing `lookupID` let the two
	// queries land on DIFFERENT rows for one line: one menu's name beside
	// another menu's rate. menuItemID is that row's primary key, so they cannot
	// diverge. Falls back to lookupID when nothing resolved (local-only line,
	// kiosk flat row).
	// #1392 — for a spotlight line the tier-1 input is the MEMBERSHIP's
	// collapsed type, not the menu row's. Reading menu_items here would rate
	// the line from a surface the cashier never touched — and for a product
	// that is on a menu AND in a spotlight with two different types, that is
	// one rate printed and another booked.
	taxTier1 := ""
	if isFloating {
		taxTier1 = floating.TaxTypeID
	} else {
		taxLookupID := menuItemID
		if taxLookupID == "" {
			taxLookupID = lookupID
		}
		taxTier1 = e.menuItemTaxTypeID(tx, taxLookupID)
	}
	res := e.resolveLineTax(taxTier1)

	// Per-line tax_amount column is informational (the engine recomputes per
	// RATE GROUP, never per line — 端数処理は税率ごとに1回). Excluded: rate% of
	// the line subtotal; included: extracted from it. Rounded at the currency
	// step so it never carries raw float noise into the column.
	lineTax := 0.0
	if res.HasSnapshot {
		step := currencyStep(e.currencyCode())
		// #2108 — the ORDER's is_tax_included snapshot, not the live branch
		// flag: a 総額表示 flip between order creation and a later AddItems
		// must not switch the informational per-line tax mode mid-order (the
		// order row is always inserted before its lines, so this read is
		// well-defined on both the create and the add-items path).
		if e.orderIsTaxIncluded(tx, orderID) {
			lineTax = float64(itemSubtotal) - roundHalfUpToStep(float64(itemSubtotal)/(1+res.Rate/100.0), step)
		} else {
			lineTax = roundHalfUpToStep(float64(itemSubtotal)*res.Rate/100.0, step)
		}
	}

	item := &Item{
		ID:                       uuid.New().String(),
		CustomerOrderID:          orderID,
		MenuItemID:               input.MenuItemID,
		FloatingSectionProductID: input.FloatingSectionProductID,
		ProductSkuID:             input.ProductSkuID,
		SkuVariantName:           input.SkuVariantName,
		MenuItemName:             name,
		Quantity:                 input.Quantity,
		UnitPrice:                price,
		ToppingSubtotal:          toppingSubtotal,
		Subtotal:                 itemSubtotal,
		Note:                     input.Note,
		PrinterGroup:             printerGroup,
		Status:                   ItemStatus(defaultItemStatus),
		PrintStatus:              PrintStatusPending,
		TaxTypeID:                res.TaxTypeID,
		TaxAmount:                lineTax,
		CreatedAt:                now,
		UpdatedAt:                now,
	}
	if res.HasSnapshot {
		r := res.Rate
		item.TaxRate = &r
	}

	_, err = tx.Exec(`
		INSERT INTO order_items (
			id, customer_order_id, menu_item_id, product_sku_id,
			floating_section_product_id,
			menu_item_name, sku_variant_name, quantity, unit_price, subtotal,
			topping_subtotal,
			note, printer_group, status, print_status,
			original_unit_price, promotion_id, promotion_label,
			tax_type_id, tax_rate, tax_amount,
			created_at, updated_at
		) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	`,
		item.ID, item.CustomerOrderID, nullableString(item.MenuItemID), nullableString(item.ProductSkuID),
		nullableString(item.FloatingSectionProductID),
		item.MenuItemName, nullableString(item.SkuVariantName), item.Quantity, item.UnitPrice, item.Subtotal,
		item.ToppingSubtotal,
		nullableString(item.Note), item.PrinterGroup, string(item.Status), string(item.PrintStatus),
		promotionSnapshotPtr(originalPrice, promotionID), nullableString(promotionID), nullableString(promotionLabel),
		res.taxTypeIDNullable(), res.taxRateNullable(), item.TaxAmount,
		now.Format(time.RFC3339), now.Format(time.RFC3339),
	)
	if err != nil {
		return nil, err
	}

	for _, t := range input.Toppings {
		if t.Quantity <= 0 {
			t.Quantity = 1
		}
		modType := t.ModifierType
		if modType == "" {
			modType = "add"
		}
		toppingID := uuid.New().String()
		_, err = tx.Exec(`
			INSERT INTO order_item_toppings (
				id, order_item_id, topping_group_item_id, product_sku_id,
				name, modifier_type, topping_group_id, topping_group_name,
				quantity, unit_price, note, created_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		`,
			toppingID, item.ID, t.ToppingGroupItemID, t.ProductSkuID,
			nullableString(t.Name), modType, nullableString(t.ToppingGroupID), nullableString(t.ToppingGroupName),
			t.Quantity, t.UnitPrice, nullableString(t.Note), now.Format(time.RFC3339),
		)
		if err != nil {
			return nil, fmt.Errorf("insert topping: %w", err)
		}
		item.Toppings = append(item.Toppings, ItemTopping{
			ID:                 toppingID,
			OrderItemID:        item.ID,
			ToppingGroupItemID: t.ToppingGroupItemID,
			ProductSkuID:       t.ProductSkuID,
			Name:               t.Name,
			ModifierType:       modType,
			ToppingGroupID:     t.ToppingGroupID,
			ToppingGroupName:   t.ToppingGroupName,
			Quantity:           t.Quantity,
			UnitPrice:          t.UnitPrice,
			Note:               t.Note,
		})
	}

	return item, nil
}

func (e *OrderEngine) getItems(orderID string) ([]Item, error) {
	rows, err := e.db.Query(`
		SELECT
			id, customer_order_id,
			COALESCE(product_sku_id, ''), COALESCE(menu_item_id, ''),
			COALESCE(floating_section_product_id, ''),
			menu_item_name, COALESCE(sku_variant_name, ''), quantity, unit_price, subtotal,
			COALESCE(note, ''), printer_group, status, print_status,
			COALESCE(printed_quantity, 0),
			served_at, voided_at, COALESCE(void_reason, ''),
			COALESCE(void_reason_id, ''),
			printed_at, created_at, updated_at,
			original_unit_price, COALESCE(promotion_id, ''),
			COALESCE(promotion_label, ''), topping_subtotal,
			tax_type_id, tax_rate, COALESCE(tax_amount, 0),
			COALESCE(refund_of_item_id, ''), COALESCE(refunded_quantity, 0)
		FROM order_items WHERE customer_order_id = ? ORDER BY created_at
	`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []Item
	for rows.Next() {
		var item Item
		var servedAt, voidedAt, printedAt sql.NullString
		var createdAt, updatedAt string
		var origUnit sql.NullInt64
		var taxTypeID sql.NullString
		var taxRate sql.NullFloat64

		if err := rows.Scan(
			&item.ID, &item.CustomerOrderID,
			&item.ProductSkuID, &item.MenuItemID,
			&item.FloatingSectionProductID,
			&item.MenuItemName, &item.SkuVariantName, &item.Quantity, &item.UnitPrice, &item.Subtotal,
			&item.Note, &item.PrinterGroup, &item.Status, &item.PrintStatus,
			&item.PrintedQuantity,
			&servedAt, &voidedAt, &item.VoidReason,
			&item.VoidReasonID,
			&printedAt, &createdAt, &updatedAt,
			&origUnit, &item.PromotionID, &item.PromotionLabel, &item.ToppingSubtotal,
			&taxTypeID, &taxRate, &item.TaxAmount,
			&item.RefundOfItemID, &item.RefundedQuantity,
		); err != nil {
			return nil, err
		}
		item.TaxTypeID = taxTypeID.String
		if taxRate.Valid {
			r := taxRate.Float64
			item.TaxRate = &r
		}
		item.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
		item.UpdatedAt, _ = time.Parse(time.RFC3339, updatedAt)
		if origUnit.Valid {
			v := int(origUnit.Int64)
			item.OriginalUnitPrice = &v
		}
		if servedAt.Valid && servedAt.String != "" {
			t, _ := time.Parse(time.RFC3339, servedAt.String)
			item.ServedAt = &t
		}
		if voidedAt.Valid && voidedAt.String != "" {
			t, _ := time.Parse(time.RFC3339, voidedAt.String)
			item.VoidedAt = &t
		}
		if printedAt.Valid && printedAt.String != "" {
			t, _ := time.Parse(time.RFC3339, printedAt.String)
			item.PrintedAt = &t
		}
		items = append(items, item)
	}

	if err := e.loadItemToppings(items); err != nil {
		return nil, err
	}
	return items, nil
}

// loadItemToppings hydrates every item in one query. The prior implementation
// issued one SELECT per line, so a large order made its mutation response slower
// with every item added even though all data was in the same local SQLite file.
func (e *OrderEngine) loadItemToppings(items []Item) error {
	if len(items) == 0 {
		return nil
	}
	itemIndex := make(map[string]int, len(items))
	args := make([]any, len(items))
	for i := range items {
		itemIndex[items[i].ID] = i
		args[i] = items[i].ID
	}
	placeholders := strings.TrimSuffix(strings.Repeat("?,", len(items)), ",")
	rows, err := e.db.Query(`
		SELECT id, order_item_id, topping_group_item_id, product_sku_id,
			COALESCE(name, ''), modifier_type,
			COALESCE(topping_group_id, ''), COALESCE(topping_group_name, ''),
			quantity, unit_price, COALESCE(note, '')
		FROM order_item_toppings
		WHERE order_item_id IN (`+placeholders+`)
		ORDER BY rowid
	`, args...)
	if err != nil {
		return err
	}
	defer rows.Close()

	for rows.Next() {
		var t ItemTopping
		if err := rows.Scan(
			&t.ID, &t.OrderItemID, &t.ToppingGroupItemID, &t.ProductSkuID,
			&t.Name, &t.ModifierType,
			&t.ToppingGroupID, &t.ToppingGroupName,
			&t.Quantity, &t.UnitPrice, &t.Note,
		); err != nil {
			return err
		}
		if i, ok := itemIndex[t.OrderItemID]; ok {
			items[i].Toppings = append(items[i].Toppings, t)
		}
	}
	return rows.Err()
}

// resolveToppingSnapshot fills in the snapshot fields (name, modifier_type,
// topping_group_id, topping_group_name, unit_price) that pos-web's LAN
// payload omits. Cloud's CustomerOrderService does the same resolution
// server-side from ProductToppingGroup attachments; without it, every
// LAN-mode cart line shows blank topping rows with zero price.
//
// `parentSkuID` is the order item's product_sku_id — used to look up
// the parent product so per-product override prices can apply.
// `owner` names the surface whose tier-1 overrides apply: the parent line's
// pos_menu_products.id (resolved by menuProductIDForSku; "" when the SKU is on
// no published menu), or — for a spotlight line — its
// pos_floating_section_products.id (#1392). Never both.
//
// Pricing priority (high → low) — mirrors the tile the operator tapped
// (local_pos_menus.go::resolveToppingItemSkus for a menu, the spotlight's own
// overrides for a floating section) so the cart line matches:
//  1. the OWNER's tier-1 override_price (shop-level). A tier-1 ROW that exists
//     suppresses tier-2 even when its override_price IS NULL; is_hidden=1 is
//     honored.
//  2. pos_product_topping_item_overrides.override_price (HQ/product-level)
//  3. pos_topping_group_item_skus.extra_price            (catalogue base)
//
// Name format: "<product_name>" or "<product_name> · <variant_name>" when
// the topping has a non-default variant, matching the menu_item_name
// convention on the parent item.
func (e *OrderEngine) resolveToppingSnapshot(t *ToppingInput, parentSkuID string, owner toppingOwner) {
	if t == nil || t.ToppingGroupItemID == "" {
		return
	}

	var (
		groupID       sql.NullString
		groupName     sql.NullString
		modifierType  sql.NullString
		productName   sql.NullString
		variantName   sql.NullString
		basePrice     sql.NullInt64
		overridePrice sql.NullInt64
	)

	// Single query resolves group + topping product + variant + price.
	// LEFT JOINs degrade gracefully when the local replica is missing
	// rows (e.g. pos-web sent a topping the workstation hasn't synced
	// yet) — we just leave the client-supplied values in place.
	err := e.db.QueryRow(`
		SELECT
			tgi.topping_group_id,
			g.name              AS group_name,
			g.modifier_type     AS modifier_type,
			p.name              AS product_name,
			ps.name             AS variant_name,
			its.extra_price     AS base_price,
			ovr.override_price  AS override_price
		FROM pos_topping_group_items tgi
		LEFT JOIN pos_topping_groups g
			ON g.id = tgi.topping_group_id
		LEFT JOIN pos_products p
			ON p.id = tgi.product_id
		LEFT JOIN pos_product_skus ps
			ON ps.id = ?
		LEFT JOIN pos_topping_group_item_skus its
			ON its.topping_group_item_id = tgi.id
			AND its.product_sku_id = ?
		LEFT JOIN pos_product_skus parent_ps
			ON parent_ps.id = ?
		LEFT JOIN pos_product_topping_item_overrides ovr
			ON ovr.topping_group_item_id = tgi.id
			AND ovr.product_sku_id = ?
			AND ovr.product_id = parent_ps.product_id
		WHERE tgi.id = ?
		LIMIT 1
	`,
		t.ProductSkuID, t.ProductSkuID,
		parentSkuID, t.ProductSkuID,
		t.ToppingGroupItemID,
	).Scan(
		&groupID, &groupName, &modifierType,
		&productName, &variantName,
		&basePrice, &overridePrice,
	)
	if err != nil {
		// Row missing → silently leave whatever the client sent. Better
		// to render a UUID than to drop the topping entirely.
		return
	}

	if t.ToppingGroupID == "" && groupID.Valid {
		t.ToppingGroupID = groupID.String
	}
	if t.ToppingGroupName == "" && groupName.Valid {
		t.ToppingGroupName = groupName.String
	}
	if t.ModifierType == "" && modifierType.Valid {
		t.ModifierType = modifierType.String
	}
	if t.Name == "" {
		// The "product · variant" suffix (mirroring the parent item's
		// menu_item_name convention) only carries information when the
		// variant is a distinct label. A topping product with a single
		// default SKU whose name equals the product name would otherwise
		// render doubled — "Fish sauce · Fish sauce" — in the pos-web cart.
		// Skip the suffix in that case. See workstation-app#101.
		variant := strings.TrimSpace(variantName.String)
		product := strings.TrimSpace(productName.String)
		switch {
		case productName.Valid && variant != "" && !strings.EqualFold(variant, product):
			t.Name = fmt.Sprintf("%s · %s", productName.String, variantName.String)
		case productName.Valid:
			t.Name = productName.String
		case variantName.Valid:
			t.Name = variantName.String
		}
	}
	// Tier-1 (shop-level) override — highest precedence, keyed by
	// (owner, topping_group_item_id, product_sku_id). Mirrors
	// local_pos_menus.go::resolveToppingItemSkus so the cart line prices the
	// topping identically to the tile.
	//
	// #1203 — a tier-1 row only outranks tier-2 when it SAYS something: a
	// price, or a hide. An empty row (no price, not hidden) used to suppress
	// tier-2 purely by existing, the opposite of Cloud, whose tier-1 query
	// filters on override_price NOT NULL. Same basket, two prices depending on
	// Cloud vs LAN — and an offline order priced here and re-priced from the
	// Cloud snapshot is rejected as tampered. The API now refuses to store an
	// empty row; this keeps the reading side correct for any that predate it.
	//
	// #1392 — WHICH tier-1 table is read follows the surface the line was sold
	// from: the spotlight's own overrides for a floating line, the menu's for a
	// menu line. Same shape, same #1203 semantics; see tier1Override.
	tier1Found, tier1Price, tier1Hidden := e.tier1Override(owner, t.ToppingGroupItemID, t.ProductSkuID)

	if t.UnitPrice == 0 {
		// Mirror resolveToppingItemSkus exactly: start at the tier-3 base
		// extra_price; a tier-1 row that carries a price or a hide wins and
		// suppresses tier-2. An EMPTY tier-1 row says nothing, so tier-2 is
		// consulted just as it would be with no row at all (#1203).
		price := 0
		if basePrice.Valid {
			price = int(basePrice.Int64)
		}
		tier1Speaks := tier1Found && (tier1Price.Valid || (tier1Hidden.Valid && tier1Hidden.Int64 == 1))
		switch {
		case tier1Speaks:
			if tier1Hidden.Valid && tier1Hidden.Int64 == 1 {
				price = 0 // hidden at menu level — treat as unavailable/free
			} else if tier1Price.Valid {
				price = int(tier1Price.Int64)
			}
		case overridePrice.Valid:
			price = int(overridePrice.Int64)
		}
		t.UnitPrice = price
	}
}

func (e *OrderEngine) calculateSubtotal(items []Item) int {
	total := 0
	for _, item := range items {
		total += item.UnitPrice * item.Quantity
	}
	return total
}

func (e *OrderEngine) nextOrderNumber() (int, error) {
	// #1091 — the counter KEY is the shop's calendar date, deliberately local:
	// the daily reset must land on the shop's midnight, not UTC's. This is safe
	// because the value is only ever compared against other keys written the
	// same way; it is never matched against a stored UTC timestamp (the bug the
	// dashboard had).
	today := time.Now().Format("2006-01-02")
	var counter int

	err := e.db.Transaction(func(tx *sql.Tx) error {
		err := tx.QueryRow(
			"SELECT counter FROM order_counters WHERE date = ?", today,
		).Scan(&counter)
		if err == sql.ErrNoRows {
			counter = 1
			_, err = tx.Exec(
				"INSERT INTO order_counters (date, counter) VALUES (?, ?)", today, counter,
			)
		} else if err == nil {
			counter++
			_, err = tx.Exec(
				"UPDATE order_counters SET counter = ? WHERE date = ?", counter, today,
			)
		}
		return err
	})

	return counter, err
}

// NextKitchenTicketNumber returns a daily-resetting counter for kitchen ticket
// STT (phiếu bếp). Independent of order_number — counts fire-to-kitchen events,
// not orders. Resets to 1 on each new calendar day.
func (e *OrderEngine) NextKitchenTicketNumber() (int, error) {
	// Shop-local key, same reasoning as nextOrderNumber (#1091).
	today := time.Now().Format("2006-01-02")
	var counter int

	err := e.db.Transaction(func(tx *sql.Tx) error {
		err := tx.QueryRow(
			"SELECT counter FROM kitchen_ticket_counters WHERE date = ?", today,
		).Scan(&counter)
		if err == sql.ErrNoRows {
			counter = 1
			_, err = tx.Exec(
				"INSERT INTO kitchen_ticket_counters (date, counter) VALUES (?, ?)", today, counter,
			)
		} else if err == nil {
			counter++
			_, err = tx.Exec(
				"UPDATE kitchen_ticket_counters SET counter = ? WHERE date = ?", counter, today,
			)
		}
		return err
	})

	return counter, err
}

// settingValue reads a single value from the settings table.
func (e *OrderEngine) settingValue(key string) string {
	var val string
	_ = e.db.QueryRow("SELECT value FROM settings WHERE key = ?", key).Scan(&val)
	return val
}

// shopSettingString reads a value from the shop_settings table (synced from
// cloud on every pull). Returns defaultVal when the key is absent or empty.
func (e *OrderEngine) shopSettingString(key, defaultVal string) string {
	var val string
	if err := e.db.QueryRow("SELECT value FROM shop_settings WHERE key = ?", key).Scan(&val); err != nil || val == "" {
		return defaultVal
	}
	return val
}

// effectiveServiceChargeRate returns the per-shop service-charge rate as a
// percent (e.g. 5 for 5%). Source of truth is shop_settings.service_charge_rate
// (synced from Cloud via PullBranch). Falls back to 0 — service charge is
// optional and most shops disable it, so a missing/zero rate must produce
// service_charge=0 rather than an arbitrary default.
func (e *OrderEngine) effectiveServiceChargeRate() float64 {
	raw := e.shopSettingString("service_charge_rate", "")
	rate := 0.0
	fmt.Sscanf(raw, "%f", &rate)
	if rate < 0 {
		rate = 0
	}
	return rate
}

// serviceChargeTaxRate returns the per-shop service-charge TAX rate as a
// percent (plan-043 — its own configurable rate, decision #3). Source:
// shop_settings.service_charge_tax_rate. Missing/negative → 0.
func (e *OrderEngine) serviceChargeTaxRate() float64 {
	raw := e.shopSettingString("service_charge_tax_rate", "")
	rate := 0.0
	fmt.Sscanf(raw, "%f", &rate)
	if rate < 0 {
		rate = 0
	}
	return rate
}

// pricesIncludeTax returns the per-shop 総額表示 flag (plan-043) from
// shop_settings.prices_include_tax. Cloud serializes booleans as "1"/"0" or
// "true"/"false" depending on the driver; accept both. Default false.
func (e *OrderEngine) pricesIncludeTax() bool {
	raw := e.shopSettingString("prices_include_tax", "")
	switch raw {
	case "1", "true", "TRUE", "True":
		return true
	default:
		return false
	}
}

// orderIsTaxIncluded reads the ORDER's immutable 総額表示 snapshot
// (orders.is_tax_included, stamped at creation). #2108 ruling: after creation
// every consumer must read this snapshot — never pricesIncludeTax() (the LIVE
// shop_settings flag), which may have flipped since the order was opened and
// would make later mutations (add item, refund) disagree with the order's own
// frozen totals. Missing row / NULL collapse to false, matching the column
// default on both Cloud and workstation.
func (e *OrderEngine) orderIsTaxIncluded(q rowQueryer, orderID string) bool {
	var v int
	_ = q.QueryRow(`SELECT COALESCE(is_tax_included, 0) FROM orders WHERE id = ?`, orderID).Scan(&v)
	return v == 1
}

// allowItemEditAnyStatus returns the per-shop item-edit policy from
// shop_settings.allow_item_edit_any_status (synced DOWN via PullBranch). false
// (default) keeps the pending-only rule (BR-OI05) for item edit/remove/void;
// true skips the item-status gate so a preparing/ready/served line can be
// edited or voided (the order must still be open). Mirrors the Cloud
// CustomerOrderService gate. Booleans arrive as "1"/"true" — accept both.
func (e *OrderEngine) allowItemEditAnyStatus() bool {
	switch e.shopSettingString("allow_item_edit_any_status", "") {
	case "1", "true", "TRUE", "True":
		return true
	default:
		return false
	}
}

// ─── plan-051 — per-status void matrix + VoidReason master ───────────────────

// activeItemStatusOrder is the canonical ordering of the four active item
// statuses (the lifecycle order). ResolveVoidableStatuses normalizes its
// result to this order so callers and tests see a stable list.
var activeItemStatusOrder = []string{"pending", "preparing", "ready", "served"}

// ResolveVoidableStatuses resolves the per-shop void matrix with the exact
// semantics of Cloud's resolveVoidableStatuses (plan-051 DESIGN §1.2):
//
//   - itemVoidableStatusesJSON present (a JSON array, mirrored from Cloud's
//     branch settings `item_voidable_statuses`): use it, ALWAYS unioned with
//     "pending" (the hard floor — a pending line is voidable no matter what
//     was persisted). Unknown/garbage entries are dropped; result keeps the
//     canonical lifecycle order.
//   - absent / null / unparsable (old Cloud that predates the column): fall
//     back to the legacy allow_item_edit_any_status flag exactly like the
//     Cloud resolver — true → all four active statuses, false → ["pending"].
//
// Exposed as a pure function so the order engine, the LAN settings handler
// and tests all share ONE implementation of the semantics.
func ResolveVoidableStatuses(itemVoidableStatusesJSON string, allowAnyStatus bool) []string {
	raw := strings.TrimSpace(itemVoidableStatusesJSON)
	if raw != "" && raw != "null" {
		var list []string
		if err := json.Unmarshal([]byte(raw), &list); err == nil {
			set := map[string]bool{"pending": true} // pending is a hard floor
			for _, s := range list {
				set[strings.TrimSpace(s)] = true
			}
			out := make([]string, 0, len(activeItemStatusOrder))
			for _, s := range activeItemStatusOrder {
				if set[s] {
					out = append(out, s)
				}
			}
			return out
		}
		// Unparsable mirror → treat as absent (fall through to the flag).
	}
	if allowAnyStatus {
		return append([]string(nil), activeItemStatusOrder...)
	}
	return []string{"pending"}
}

// voidableItemStatuses returns the resolved per-shop void matrix from the
// mirrored shop_settings rows (item_voidable_statuses JSON, synced DOWN via
// PullBranch's generic settings flatten; allow_item_edit_any_status as the
// old-Cloud fallback).
func (e *OrderEngine) voidableItemStatuses() []string {
	return ResolveVoidableStatuses(
		e.shopSettingString("item_voidable_statuses", ""),
		e.allowItemEditAnyStatus(),
	)
}

// VoidableItemStatuses is the exported accessor for the LAN settings handler.
func (e *OrderEngine) VoidableItemStatuses() []string { return e.voidableItemStatuses() }

// VoidReason is one row of the brand-scoped VoidReason master, mirrored DOWN
// from Cloud's branch settings payload (`data.settings.void_reasons`, label
// already localized Cloud-side) into shop_settings.void_reasons as JSON.
type VoidReason struct {
	ID           string `json:"id"`
	Label        string `json:"label"`
	StockEffect  string `json:"stock_effect"` // waste | restock | none
	RequiresNote bool   `json:"requires_note"`
	SortOrder    int    `json:"sort_order"`
}

// ParseVoidReasons decodes the mirrored void_reasons JSON. Absent / null /
// unparsable → empty list (offline-first: the picker simply has nothing to
// offer and staff void with free text, which stays valid).
func ParseVoidReasons(rawJSON string) []VoidReason {
	raw := strings.TrimSpace(rawJSON)
	if raw == "" || raw == "null" {
		return []VoidReason{}
	}
	var reasons []VoidReason
	if err := json.Unmarshal([]byte(raw), &reasons); err != nil {
		return []VoidReason{}
	}
	return reasons
}

// VoidReasons returns the mirrored VoidReason master list for this branch.
func (e *OrderEngine) VoidReasons() []VoidReason {
	return ParseVoidReasons(e.shopSettingString("void_reasons", ""))
}

// voidReasonIDResolves reports whether the given id exists in the mirrored
// VoidReason master. Used by the #1148 real-reason gate: a resolvable picked
// reason satisfies "a real reason" even when the accompanying text is one of
// the junk defaults (Cloud applies the same OR — valid void_reason_id OR real
// text). An empty/unknown id resolves to false, which falls back to the text
// requirement — matching Cloud's converge-not-reject degradation.
func (e *OrderEngine) voidReasonIDResolves(id string) bool {
	if id == "" {
		return false
	}
	for _, vr := range e.VoidReasons() {
		if vr.ID == id {
			return true
		}
	}
	return false
}

// taxRoundingModeSetting returns the shop's plan-045 consumption-tax rounding
// mode from shop_settings.tax_rounding_mode (synced DOWN via PullBranch). It
// normalizes to the rev-B names (round/ceil/floor) and accepts the legacy
// aliases (half_up/round_up/round_down); missing/unknown → "round". Read ONCE at
// order-create time to stamp the order snapshot; the engine never re-reads it.
func (e *OrderEngine) taxRoundingModeSetting() string {
	switch e.shopSettingString("tax_rounding_mode", "") {
	case "ceil", "round_up":
		return "ceil"
	case "floor", "round_down":
		return "floor"
	default:
		return "round"
	}
}

// taxRoundingDecimalsSetting returns the shop's plan-045 tax_rounding_decimals
// from shop_settings (synced DOWN via PullBranch), or nil when unset / out of
// the 0–3 range (nil → currency step, pre-plan-045 behaviour). Stamped onto the
// order at create time.
func (e *OrderEngine) taxRoundingDecimalsSetting() *int {
	raw := e.shopSettingString("tax_rounding_decimals", "")
	if raw == "" {
		return nil
	}
	var d int
	if _, err := fmt.Sscanf(raw, "%d", &d); err != nil {
		return nil
	}
	if d < 0 || d > 3 {
		return nil
	}
	return &d
}

// currencyCode returns the shop's display currency for rounding-step resolution.
// Mirrors the pos-web order-settings fallback chain: currency_code → currency →
// "VND" (project default). This is the fix for the kiosk hardcoded "JPY".
func (e *OrderEngine) currencyCode() string {
	if c := e.shopSettingString("currency_code", ""); c != "" {
		return c
	}
	if c := e.shopSettingString("currency", ""); c != "" {
		return c
	}
	return "VND"
}

// rateSubtotalsFromItems groups an order's non-voided lines by their snapshot
// tax_rate → Σ (quantity × (unit_price + topping_subtotal)). Mirrors
// OrderPricingCalculator::rateSubtotalsForOrder (PHP).
//
// #2188 — a line with no snapshot rate is DROPPED (returned in `dropped` so
// the caller leaves a warning), never priced at an invented rate: creation
// always stamps, so an unstamped line is broken input, and a visibly short
// total beats a silently mis-taxed one (#2067 pattern).
func rateSubtotalsFromItems(items []Item) (out map[string]float64, dropped int) {
	out = map[string]float64{}
	for _, it := range items {
		if string(it.Status) == string(StatusVoided) {
			continue
		}
		// plan-045 — refund lines are EXCLUDED from the positive group-once tax
		// (parity with computeOrderTotalsFromDB + Cloud); their negated snapshot
		// is folded directly by applyRefundLines. Without this the item-slice
		// path would net a negative line into its rate group and re-round — NOT
		// an exact reversal.
		if it.IsRefund() {
			continue
		}
		if it.TaxRate == nil {
			dropped++
			continue
		}
		lineSubtotal := float64(it.Quantity) * float64(it.UnitPrice+it.ToppingSubtotal)
		out[rateKey(*it.TaxRate)] += lineSubtotal
	}
	return out, dropped
}

// survivingLineGross is one positive line's gross still on the order after
// partial refunds (#2240 / #2253). Uses refunded_quantity — the same single
// source Cloud's survivingLineGross reads.
func survivingLineGross(it Item) float64 {
	unitGross := float64(it.UnitPrice + it.ToppingSubtotal)
	surviving := float64(it.Quantity - it.RefundedQuantity)
	if surviving < 0 {
		surviving = 0
	}
	return surviving * unitGross
}

// survivingGrossByRateFromItems groups surviving gross by snapshot tax_rate for
// discount pro-rata (#2240). Same filters as rateSubtotalsFromItems.
func survivingGrossByRateFromItems(items []Item) map[string]float64 {
	out := map[string]float64{}
	for _, it := range items {
		if string(it.Status) == string(StatusVoided) {
			continue
		}
		if it.IsRefund() {
			continue
		}
		if it.TaxRate == nil {
			continue
		}
		out[rateKey(*it.TaxRate)] += survivingLineGross(it)
	}
	return out
}

// survivingGrossByRateFromDB is the SQL path for computeOrderTotalsFromDB.
func survivingGrossByRateFromDB(q rowQueryer, orderID string) map[string]float64 {
	rows, err := q.Query(`
		SELECT tax_rate AS rate,
		       COALESCE(SUM(
		           CASE
		             WHEN (quantity - COALESCE(refunded_quantity, 0)) > 0
		             THEN (quantity - COALESCE(refunded_quantity, 0))
		                  * (unit_price + COALESCE(topping_subtotal, 0))
		             ELSE 0
		           END
		       ), 0) AS sub
		FROM order_items
		WHERE customer_order_id = ?
		  AND tax_rate IS NOT NULL
		  AND (status IS NULL OR status != 'voided')
		  AND (refund_of_item_id IS NULL OR refund_of_item_id = '')
		GROUP BY rate`, orderID)
	if err != nil {
		slog.Warn("pricing: surviving gross by rate query failed — discount pro-rata falls back to gross THÔ (#2253)",
			"order_id", orderID, "err", err)
		return nil
	}
	defer rows.Close()

	out := map[string]float64{}
	for rows.Next() {
		var rate, sub float64
		if err := rows.Scan(&rate, &sub); err != nil {
			slog.Warn("pricing: surviving gross scan failed (#2253)", "order_id", orderID, "err", err)
			return nil
		}
		out[rateKey(rate)] += sub
	}
	if err := rows.Err(); err != nil {
		slog.Warn("pricing: surviving gross rows failed (#2253)", "order_id", orderID, "err", err)
		return nil
	}
	return out
}

// priceRateSubtotals runs the §8 engine over an already-grouped rate map with
// the order's tax mode + the shop's service-charge / currency settings, and
// returns the integer money fields (yen) the caller persists. The engine's
// float results land on the currency step (1 for JPY/VND) so the int cast is
// exact.
func (e *OrderEngine) priceRateSubtotals(rateSubtotals map[string]float64, discount int, includeTax bool) (tax float64, serviceCharge, total int) {
	// Legacy overload — no order snapshot, so tax rounds half-up to the currency
	// step (pre-plan-045 behaviour, byte-identical; round ≡ the old half_up).
	tax, serviceCharge, total, _ = e.priceRateSubtotalsWithRounding(rateSubtotals, discount, includeTax, "round", nil, nil, nil)
	return tax, serviceCharge, total
}

// priceRateSubtotalsWithRounding runs the §8 engine with the order's plan-045
// rounding snapshot (mode + decimals) AND folds any appended refund lines'
// negated snapshot directly (excluded from the positive group-once). `refunds`
// may be nil for the pre-refund path. `discountWeights` mirrors Cloud
// survivingGrossByRate — nil keeps legacy gross-THÔ pro-rata. Returns the
// integer money fields (yen).
func (e *OrderEngine) priceRateSubtotalsWithRounding(
	rateSubtotals map[string]float64,
	discount int,
	includeTax bool,
	taxMode string,
	taxDecimals *int,
	refunds []RefundLine,
	discountWeights map[string]float64,
) (tax float64, serviceCharge, total int, res PricingResult) {
	step := currencyStep(e.currencyCode())
	tStep := taxStep(taxDecimals, e.currencyCode())
	if taxMode == "" {
		taxMode = "round"
	}
	res = priceGroups(
		rateSubtotals,
		float64(discount),
		e.effectiveServiceChargeRate(),
		e.serviceChargeTaxRate(),
		includeTax,
		step,
		tStep,
		taxMode,
		discountWeights,
	)
	// plan-045 — fold appended refund lines' negated snapshot into the result
	// (exact reversal; refund lines never entered the group-once above).
	res = applyRefundLines(res, refunds)
	// TaxAmount keeps sub-unit precision (option-B display); serviceCharge + total
	// stay whole-yen (payable) — TotalAmount was already rounded to $step above.
	return res.TaxAmount, int(res.ServiceCharge), int(res.TotalAmount), res
}

// computeOrderTotalsForItems is the plan-043 replacement for the old single-rate
// computeOrderTotals: it groups the given items by their per-line snapshot rate
// and runs the §8 engine. `includeTax` is the order's is_tax_included snapshot.
func (e *OrderEngine) computeOrderTotalsForItems(items []Item, discount int, includeTax bool) (tax float64, serviceCharge, total int) {
	rateSubtotals, dropped := rateSubtotalsFromItems(items)
	if dropped > 0 {
		orderID := ""
		if len(items) > 0 {
			orderID = items[0].CustomerOrderID
		}
		warnUnstampedLinesDropped("computeOrderTotalsForItems", orderID, dropped)
	}
	weights := survivingGrossByRateFromItems(items)
	tax, serviceCharge, total, _ = e.priceRateSubtotalsWithRounding(
		rateSubtotals, discount, includeTax, "round", nil, nil, weights,
	)
	return tax, serviceCharge, total
}

// computeOrderTotalsFromDB groups the order's non-voided lines by snapshot rate
// directly in SQL (used inside a write tx where the in-memory Item slice isn't
// materialised) and runs the §8 engine. Reads the order's is_tax_included +
// discount. Mirrors the item-based path to the yen.
func (e *OrderEngine) computeOrderTotalsFromDB(q rowQueryer, orderID string) (subtotal, discount int, tax float64, serviceCharge, total int, res PricingResult, err error) {
	// Sum non-voided POSITIVE lines grouped by their snapshot tax_rate. #2188 —
	// a NULL tax_rate line is DROPPED (counted + warned below), never collapsed
	// onto an invented bucket: creation always stamps, so an unstamped line is
	// broken input and a visibly short total beats a silently mis-taxed one
	// (#2067 pattern). plan-045 — refund lines (refund_of_item_id set) are
	// EXCLUDED from the positive group-once tax; their negated snapshot is
	// folded in directly below.
	rows, err := q.Query(`
		SELECT tax_rate AS rate,
		       COALESCE(SUM(quantity * (unit_price + COALESCE(topping_subtotal, 0))), 0) AS sub
		FROM order_items
		WHERE customer_order_id = ?
		  AND tax_rate IS NOT NULL
		  AND (status IS NULL OR status != 'voided')
		  AND (refund_of_item_id IS NULL OR refund_of_item_id = '')
		GROUP BY rate`, orderID)
	if err != nil {
		return 0, 0, 0, 0, 0, res, fmt.Errorf("group items by rate: %w", err)
	}
	defer rows.Close()

	rateSubtotals := map[string]float64{}
	for rows.Next() {
		var rate, sub float64
		if err := rows.Scan(&rate, &sub); err != nil {
			return 0, 0, 0, 0, 0, res, err
		}
		rateSubtotals[rateKey(rate)] += sub
		subtotal += int(sub)
	}
	if err := rows.Err(); err != nil {
		return 0, 0, 0, 0, 0, res, err
	}

	// #2188 — the dropped-line warning for the DB path: count the unstamped
	// non-voided lines the SELECT above excluded and leave the trail.
	var unstamped int
	_ = q.QueryRow(`
		SELECT COUNT(*) FROM order_items
		WHERE customer_order_id = ? AND tax_rate IS NULL
		  AND (status IS NULL OR status != 'voided')`, orderID).Scan(&unstamped)
	if unstamped > 0 {
		warnUnstampedLinesDropped("computeOrderTotalsFromDB", orderID, unstamped)
	}

	// plan-045 — collect appended refund lines' stored (negated) subtotal +
	// tax_amount + rate. These are added DIRECTLY (exact reversal) rather than
	// re-entering the group-once rounding. subtotal accumulates them too so the
	// order's stored subtotal reflects the refund (matches Cloud).
	refunds, refundSubtotal, err := e.refundLinesFromDB(q, orderID)
	if err != nil {
		return 0, 0, 0, 0, 0, res, err
	}
	subtotal += refundSubtotal

	var storedSubtotal, includeTax int
	_ = q.QueryRow(`SELECT COALESCE(subtotal, 0), COALESCE(is_tax_included, 0) FROM orders WHERE id = ?`, orderID).
		Scan(&storedSubtotal, &includeTax)
	_ = q.QueryRow(`SELECT COALESCE(discount_amount, 0) FROM orders WHERE id = ?`, orderID).Scan(&discount)
	taxMode, taxDecimals := e.orderRoundingSnapshot(q, orderID)

	// #2188 — the stored-subtotal-only fallback (a single invented rate group
	// priced off orders.subtotal when the order had no line rows) was REMOVED
	// with the legacy ruling. An order with money but no lines is broken input
	// (or a mid-sync header): it prices to zero groups and leaves a warning
	// instead of a fabricated single-rate breakdown (#2067 pattern). Mirrors
	// OrderPricingCalculator::forOrder (PHP).
	if len(rateSubtotals) == 0 && len(refunds) == 0 && storedSubtotal > 0 && unstamped == 0 {
		slog.Warn("pricing: order has a stored subtotal but no line rows — pricing zero groups, not inventing one (#2188)",
			"order_id", orderID,
			"stored_subtotal", storedSubtotal,
		)
	}

	// #2232 (nửa Go của #2182) — khoản giảm ÁP DỤNG không bao giờ lớn hơn giỏ
	// SỐNG. priceGroups đã kẹp min(discount, subtotal), nhưng subtotal ấy là
	// tổng GỘP các dòng DƯƠNG — nó không co lại khi hàng được trả (dòng hoàn là
	// dòng âm riêng), nên với giỏ đã hoàn hết phép kẹp kia vẫn thấy nguyên gộp
	// và để nguyên khoản giảm: giảm giá TAY (thứ cố ý KHÔNG được đánh giá lại)
	// treo trên giỏ rỗng cho total ÂM — đơn khẳng định quán NỢ khách một khoản
	// khách chưa từng trả. Kẹp phần ÁP DỤNG, KHÔNG ghi đè cột: discount_amount
	// giữ số YÊU CẦU, sổ order_conditions (nhận pricing.Discount) giữ số THỰC
	// TẾ — cùng luật #2083. `subtotal` ở đây đã gấp refundSubtotal (âm) vào nên
	// chính là giỏ sống.
	// #2240 / #2253 — mẫu số pro-rata của khoản giảm là gross CÒN SỐNG từng
	// nhóm (trừ refunded_quantity), không phải gross thô.
	survivingGross := survivingGrossByRateFromDB(q, orderID)
	survivingSum := 0.0
	for _, w := range survivingGross {
		survivingSum += w
	}

	live := subtotal
	if live < 0 {
		live = 0
	}
	applied := float64(min(discount, live))
	if survivingSum < applied {
		applied = survivingSum
	}
	appliedDiscount := int(applied)
	if appliedDiscount < 0 {
		appliedDiscount = 0
	}

	tax, serviceCharge, total, res = e.priceRateSubtotalsWithRounding(
		rateSubtotals, appliedDiscount, includeTax == 1, taxMode, taxDecimals, refunds, survivingGross,
	)
	return subtotal, discount, tax, serviceCharge, total, res, nil
}

// liveGrossSubtotal returns the LIVE basket the applied discount is clamped to
// (#2232 / Cloud #2182): Σ gross of the order's stamped, non-voided POSITIVE
// lines plus the Σ stored (negative) subtotal of its refund lines — i.e. gross
// minus what has been returned, floored at 0. Mirrors Cloud applyPricing's
// `$liveSubtotal = max(0, Σ rateSubtotals + refundedSubtotalFor(items))`.
func (e *OrderEngine) liveGrossSubtotal(q rowQueryer, orderID string) (float64, error) {
	var positive, refunded float64
	if err := q.QueryRow(`
		SELECT COALESCE(SUM(quantity * (unit_price + COALESCE(topping_subtotal, 0))), 0)
		FROM order_items
		WHERE customer_order_id = ? AND tax_rate IS NOT NULL
		  AND (status IS NULL OR status != 'voided')
		  AND (refund_of_item_id IS NULL OR refund_of_item_id = '')`, orderID,
	).Scan(&positive); err != nil {
		return 0, err
	}
	if err := q.QueryRow(`
		SELECT COALESCE(SUM(subtotal), 0)
		FROM order_items
		WHERE customer_order_id = ? AND tax_rate IS NOT NULL
		  AND refund_of_item_id IS NOT NULL AND refund_of_item_id != ''
		  AND (status IS NULL OR status != 'voided')`, orderID,
	).Scan(&refunded); err != nil {
		return 0, err
	}
	live := positive + refunded
	if live < 0 {
		live = 0
	}
	return live, nil
}

// refundLinesFromDB reads an order's appended refund lines (refund_of_item_id
// set, non-voided) as RefundLine inputs for applyRefundLines. Returns the refund
// slice + the Σ refund subtotal (negative) so the caller can fold it into the
// order's stored subtotal. #2188 — a refund line with a NULL tax_rate is
// DROPPED with a warning (matches the positive path): the snapshot is copied
// from a stamped source line, so NULL here is broken input.
func (e *OrderEngine) refundLinesFromDB(q rowQueryer, orderID string) ([]RefundLine, int, error) {
	rows, err := q.Query(`
		SELECT tax_rate AS rate,
		       COALESCE(subtotal, 0) AS sub,
		       COALESCE(tax_amount, 0) AS tax
		FROM order_items
		WHERE customer_order_id = ?
		  AND tax_rate IS NOT NULL
		  AND refund_of_item_id IS NOT NULL AND refund_of_item_id != ''
		  AND (status IS NULL OR status != 'voided')`, orderID)
	if err != nil {
		return nil, 0, fmt.Errorf("read refund lines: %w", err)
	}
	defer rows.Close()

	var refunds []RefundLine
	refundSubtotal := 0
	for rows.Next() {
		var rate, sub, tax float64
		if err := rows.Scan(&rate, &sub, &tax); err != nil {
			return nil, 0, err
		}
		refunds = append(refunds, RefundLine{Subtotal: sub, TaxAmount: tax, Rate: rate})
		refundSubtotal += int(sub)
	}
	if err := rows.Err(); err != nil {
		return nil, 0, err
	}
	return refunds, refundSubtotal, nil
}

// orderRoundingSnapshot reads an order's plan-045 rounding snapshot (mode +
// decimals). A blank/absent mode defaults to "round"; a NULL decimals returns
// nil (currency step). Never touches shop_settings — the engine must read the
// order's frozen snapshot so a settings change can't re-round history. Legacy
// values (half_up/round_up/round_down) still price via roundToStep's aliases.
func (e *OrderEngine) orderRoundingSnapshot(q rowQueryer, orderID string) (mode string, decimals *int) {
	var m sql.NullString
	var d sql.NullInt64
	_ = q.QueryRow(
		`SELECT tax_rounding_mode, tax_rounding_decimals FROM orders WHERE id = ?`, orderID,
	).Scan(&m, &d)
	mode = "round"
	if m.Valid && m.String != "" {
		mode = m.String
	}
	if d.Valid {
		v := int(d.Int64)
		decimals = &v
	}
	return mode, decimals
}

// NormalizedTotals recomputes an order's money fields from its items using the
// plan-043 §8 per-rate engine + shop settings. It is read-only and idempotent:
// for an order that reached the local DB with zeroed totals (e.g. synced down
// from Cloud, or a raw insert), it derives the correct breakdown so the kiosk
// bill screen and Cloud agree.
//
// subtotal = Σ over non-voided lines of quantity × (unit_price + topping_subtotal)
// — the same definition the engine sums in AddItems (order_items.subtotal).
// discount is taken from the order's stored discount_amount, tax mode from the
// order's is_tax_included snapshot.
func (e *OrderEngine) NormalizedTotals(o *Order) (subtotal, discount int, tax float64, serviceCharge, total int) {
	// Authoritative path (#501): an order Cloud already costed (sync-down) or the
	// local engine already costed carries a non-zero total_amount + a full stored
	// breakdown. Return it VERBATIM so the kiosk bill + payable amount match the
	// number the customer already saw. Recomputing here re-derived tax / service /
	// discount from local rates + synced item prices and drifted — e.g. a promo
	// order the customer saw as 2,200đ rang up 2,298đ on the kiosk because the
	// recompute didn't reproduce Cloud's exact discount/rounding.
	if o.TotalAmount > 0 {
		subtotal, discount, tax, serviceCharge, total =
			o.Subtotal, o.DiscountAmount, o.TaxAmount, o.ServiceCharge, o.TotalAmount
		// Safety net: a pre-#501 sync-down stored the authoritative total but not
		// service_charge (0). Back the missing charge out of the total so the bill
		// breakdown still sums (total = subtotal − discount + tax + service).
		if derived := total - (subtotal - discount + int(tax+0.5)); serviceCharge == 0 && derived > 0 {
			serviceCharge = derived
		}
		return subtotal, discount, tax, serviceCharge, total
	}

	// Fallback: an uncosted order (legacy zeroed sync-down row, or a fresh local
	// order before its first recalc) — derive the breakdown from its items so the
	// bill isn't blank.
	for _, it := range o.Items {
		if string(it.Status) == string(StatusVoided) {
			continue
		}
		subtotal += it.Quantity * (it.UnitPrice + it.ToppingSubtotal)
	}
	discount = o.DiscountAmount
	tax, serviceCharge, total = e.computeOrderTotalsForItems(o.Items, discount, o.IsTaxIncluded)
	return subtotal, discount, tax, serviceCharge, total
}

// buildToppingMergeKey mirrors CustomerOrderService::toppingMergeKey:
// sorted tuples of (topping_group_item_id, product_sku_id, quantity)
// joined by "::". Same input → same string on both sides so workstation
// + Cloud agree on whether two add-item requests should stack onto one
// cart line (BR-OI06). Empty toppings → empty string, which means a
// plain SKU always matches another plain SKU of the same price.
func buildToppingMergeKey(toppings []ToppingInput) string {
	if len(toppings) == 0 {
		return ""
	}
	tuples := make([]string, 0, len(toppings))
	for _, t := range toppings {
		qty := t.Quantity
		if qty <= 0 {
			qty = 1
		}
		tuples = append(tuples, fmt.Sprintf("%s|%s|%d",
			t.ToppingGroupItemID, t.ProductSkuID, qty))
	}
	sort.Strings(tuples)
	return strings.Join(tuples, "::")
}

// findMergeableItem looks for an order_item on this order that we can stack
// the new input onto. It matches on the shop's DEFAULT item status, not on
// `pending`: a shop with `default_order_item_status = served` never has a
// pending line, and requiring one there would mean the merge could never fire
// at all — which is exactly the bug Cloud shipped until #2522.
//
// This does NOT mirror Cloud's BR-OI06 query, and the difference is deliberate
// (see docs/guide/item-edit-and-void-policy.md §2b). The workstation stacks
// without a time limit because it knows `printed_quantity` per line and reprints
// only the delta, so the kitchen is always told about exactly the new units.
// Cloud has no such per-line signal, so it bounds the same rule with a short
// window plus "no kitchen ticket issued yet". Same rule, weaker evidence.
//
// Uses the open tx so we observe rows the same transaction just
// inserted (relevant when AddItems is called with a batch of duplicate
// items: the SECOND one in the batch should merge into the FIRST).
//
// Returns nil when no candidate matches. Caller then proceeds to
// INSERT a fresh row.
func (e *OrderEngine) findMergeableItem(
	tx *sql.Tx,
	orderID string,
	input CreateItemInput,
	unitPrice, toppingSubtotal int,
	mergeKey, defaultItemStatus string,
) (*Item, error) {
	if input.ProductSkuID == "" {
		return nil, nil
	}
	// Status check against the shop's DEFAULT, not the literal `pending`.
	// A line that has MOVED past where it was born (a KDS bump to preparing,
	// or a void) is a different batch and must stay separate; a line still
	// sitting at the born status has not been touched by anyone.
	//
	// The variable keeps the name `pendingStatus` for the query below, but its
	// VALUE is the shop default — do not read the name as the rule.
	pendingStatus := string(ItemStatus(defaultItemStatus))
	// `note` semantics: NULL on both sides match; otherwise exact equality.
	var rows *sql.Rows
	var err error
	// #1392 — the SURFACE is part of the line's identity. Two lines of the same
	// SKU that happen to agree on price (a spotlight priced level with the
	// menu, or a client-supplied price) may still carry different tax types and
	// different topping tiers, so merging them would silently re-attribute one
	// of the two and re-rate it on the next re-resolution.
	if input.Note == "" {
		rows, err = tx.Query(`
			SELECT id, quantity, subtotal, COALESCE(note, '')
			FROM order_items
			WHERE customer_order_id = ?
			  AND product_sku_id = ?
			  AND status = ?
			  AND unit_price = ?
			  AND COALESCE(topping_subtotal, 0) = ?
			  AND COALESCE(floating_section_product_id, '') = ?
			  AND note IS NULL
			ORDER BY created_at`,
			orderID, input.ProductSkuID, pendingStatus, unitPrice, toppingSubtotal,
			input.FloatingSectionProductID)
	} else {
		rows, err = tx.Query(`
			SELECT id, quantity, subtotal, COALESCE(note, '')
			FROM order_items
			WHERE customer_order_id = ?
			  AND product_sku_id = ?
			  AND status = ?
			  AND unit_price = ?
			  AND COALESCE(topping_subtotal, 0) = ?
			  AND COALESCE(floating_section_product_id, '') = ?
			  AND note = ?
			ORDER BY created_at`,
			orderID, input.ProductSkuID, pendingStatus, unitPrice, toppingSubtotal,
			input.FloatingSectionProductID, input.Note)
	}
	if err != nil {
		return nil, err
	}
	type candidate struct {
		id       string
		quantity int
		subtotal int
		note     string
	}
	cands := []candidate{}
	for rows.Next() {
		var c candidate
		if err := rows.Scan(&c.id, &c.quantity, &c.subtotal, &c.note); err != nil {
			rows.Close()
			return nil, err
		}
		cands = append(cands, c)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()

	// For each candidate, build its topping merge key from
	// order_item_toppings + compare to the input mergeKey.
	for _, c := range cands {
		existingKey, err := e.existingItemMergeKey(tx, c.id)
		if err != nil {
			return nil, err
		}
		if existingKey == mergeKey {
			return &Item{
				ID:              c.id,
				CustomerOrderID: orderID,
				ProductSkuID:    input.ProductSkuID,
				Quantity:        c.quantity,
				UnitPrice:       unitPrice,
				ToppingSubtotal: toppingSubtotal,
				Subtotal:        c.subtotal,
				Note:            c.note,
				Status:          ItemStatus(defaultItemStatus),
			}, nil
		}
	}
	return nil, nil
}

// existingItemMergeKey rebuilds the BR-OI06 merge key from the
// persisted order_item_toppings rows of an existing item — used to
// disambiguate merge candidates that share product_sku_id +
// unit_price + topping_subtotal but differ in WHICH toppings they
// reference.
func (e *OrderEngine) existingItemMergeKey(tx *sql.Tx, orderItemID string) (string, error) {
	rows, err := tx.Query(`
		SELECT topping_group_item_id, product_sku_id, quantity
		FROM order_item_toppings
		WHERE order_item_id = ?`, orderItemID)
	if err != nil {
		return "", err
	}
	defer rows.Close()
	tuples := []string{}
	for rows.Next() {
		var gItem, skuID string
		var qty int
		if err := rows.Scan(&gItem, &skuID, &qty); err != nil {
			return "", err
		}
		if qty <= 0 {
			qty = 1
		}
		tuples = append(tuples, fmt.Sprintf("%s|%s|%d", gItem, skuID, qty))
	}
	if err := rows.Err(); err != nil {
		return "", err
	}
	if len(tuples) == 0 {
		return "", nil
	}
	sort.Strings(tuples)
	return strings.Join(tuples, "::"), nil
}
