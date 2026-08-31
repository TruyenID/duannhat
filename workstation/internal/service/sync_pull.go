package service

// Sync DOWN — polling worker that mirrors Cloud-owned entities into local
// SQLite so kiosk/customer/TMS LAN endpoints can read from the local replica.
//
// Ownership matrix (see docs/plan/README.md §2):
//   - zones, tables, menu, branch+settings  → Cloud is source of truth (DOWN)
//   - orders, payments                       → workstation is source of truth (UP, sync_service.go)
//
// Cadence (#1175): a single 5 s manifest tick (sync_manifest.go) gates the
// versioned feeds; the kitchen-orders loop keeps its own 5 s pull. Against
// an old Cloud without the manifest endpoint every tick falls back to the
// legacy full pull (pullFast + pullSettings + pullSlow).
//
// Atomicity (Decision 2): replace-all inside a single SQLite transaction so
// readers never observe an intermediate empty state (DELETE … then INSERT …
// happen as one COMMIT). Empty Cloud responses are treated as "no data —
// keep local" rather than wipe, defending against transient Cloud bugs.
//
// Menu decision (A1): flatten Cloud nested shape into existing flat
// menu_items schema, preserving the workstation-local printer_group on
// updates. Options/toppingGroups/combo/promotion data are dropped because
// kiosk v1 is payment-only and has no menu customization UI (verified
// 2026-05-19 — godx-kiosk/app/ has no menu screens).

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/google/uuid"
)

const (
	// #1175 — manifest-driven pull. One 5 s tick drives a single
	// GET /workstation/sync-manifest with If-None-Match; a 304 (the common
	// case) ends the tick, a 200 runs only the Pull* funcs whose feed
	// version changed (see sync_manifest.go). The 5 s tick IS the freshness
	// SLA (2026-06-15 product decision: cloud edits must reach cashier
	// devices within the same window as kitchen orders) — the manifest just
	// makes the idle tick nearly free instead of ~11 full-payload GETs
	// (the 2026-06-12 "consolidate with If-None-Match" debt, now paid).
	//
	// When Cloud predates the manifest endpoint (404 / network / 5xx) the
	// tick FALLS BACK to the legacy full pull (pullFast + pullSettings +
	// pullSlow, concurrent like the old per-loop goroutines) so a new
	// workstation against an old Cloud behaves exactly like the pre-#1175
	// build. Deploy-order independent by construction.
	//
	//   - Overrun: loopWithKick drops queued ticks via the drain after
	//     fn() — so a slow pull doesn't pile up requests.
	//   - Poke (sync_poke.go) only makes this tick arrive EARLY via the
	//     kick channel; it is never a condition for the tick to run.
	pullIntervalManifest = 5 * time.Second
	pullIntervalKitchen  = 5 * time.Second // customer orders (KDS freshness) — NOT manifest-gated (realtime domain)

	// #1095/#1114 — offline-evidence catalog gate, stamped by PullMenu.
	// The order-create path reads these to sign offline orders.
	catalogRevisionKey            = "sync.catalog.revision"
	catalogRevisionHasToppingsKey = "sync.catalog.has_toppings"

	pullPathZones          = "/api/v1/tms/zones"
	pullPathTables         = "/api/v1/tms/tables"
	pullPathMenu           = "/api/v1/workstation/menu"
	pullPathMenuHandy      = "/api/v1/workstation/menu/handy"
	pullPathBranch         = "/api/v1/workstation/branch"
	pullPathLots           = "/api/v1/workstation/lots"
	pullPathCustomerOrders = "/api/v1/workstation/orders"

	settingsCursorKey = "sync.customer_orders.last_pulled"

	// customerOrdersPullLimit mirrors the Cloud default so a full page is
	// RECOGNISABLE as truncation. The workstation used to send no limit at
	// all, which meant it could not tell "that was everything" from "Cloud cut
	// me off at 500" — and that distinction decides whether the cursor may be
	// clamped (see clampCursorToCloudClock).
	customerOrdersPullLimit = 500

	// lotsCloudLimit phản chiếu `LotController::index` → `->limit(200)`. Chỉ dùng
	// để BIẾT đã chạm trần; đổi số ở Cloud mà quên ở đây thì rào
	// `workstation-feeds-declare-their-cap` sẽ kêu.
	lotsCloudLimit = 200

	// cursorClockSafetyMargin is how far behind Cloud's stated render time the
	// cursor is allowed to land. See clampCursorToCloudClock — Cloud stamps
	// `generated_at` after reading the rows, so clamping exactly onto it would
	// skip whatever changed mid-render. Re-reading a minute of orders costs
	// idempotent upserts; skipping one loses it permanently.
	cursorClockSafetyMargin = time.Minute
)

type SyncPuller struct {
	// #1806 — alert centre. Nil-safe.
	alerts         *AlertEmitter
	updater        BuildUpdater // assisted workstation update; nil-safe
	db             *store.DB
	httpClient     *http.Client
	cloudURL       string        // static fallback
	cloudURLFn     func() string // dynamic resolver (preferred when set)
	tokenFn        func() string // workstation device token from settings
	onUnauthorized func()        // called once when cloud returns 401
	stopCh         chan struct{}
	stopOnce       sync.Once
	// #148 — Stop() must OUTLIVE these three goroutines, not merely ask them
	// to leave. Signalling alone lets Stop() return while a pull is still
	// writing, and the caller then closes the database underneath it
	// ("sql: database is closed" in the logs). Under a test that shows up as
	// t.TempDir() finding SQLite's WAL files still there; in production it is
	// a process exiting part-way through a write.
	workers sync.WaitGroup
	hub     BroadcastHub // optional — nil disables WS broadcasts
	// tracer is the shared sync-event ring buffer (see sync_trace.go), set via
	// SetTracer to the SyncEngine's instance so UP and DOWN events land in one
	// feed. Optional; nil-safe (all recorders no-op on a nil tracer).
	tracer *SyncTracer
	// Auto-print the payment receipt when a paid (kiosk/customer) order first
	// syncs down — those payments are confirmed in Cloud, so the local payment
	// endpoint that prints on confirm never fires for them. Server wires this.
	// branchID rides along so the same hook can also release the table +
	// broadcast to POS clients scoped to that branch (issue #2564).
	onOrderPaid func(orderID, branchID string, amount int)
	// onOrderArrived fires once when an order is first inserted locally from a
	// pull-down (no prior row). Powers the prep-first auto-print: a fresh
	// online takeaway order that isn't paid yet gets its kitchen ticket printed
	// on arrival. See issue #456.
	onOrderArrived func(orderID, orderType, status string)
	// onOrderMerged fires on every SUBSEQUENT pull-down merge of an order that
	// already existed locally (a prior row was present) — i.e. an update / "add
	// more" round, NOT the first insert. Powers dine-in auto-print of later
	// batches: onOrderArrived catches the first round, this catches the appends.
	// Split from onOrderArrived so the first insert never double-invokes both.
	// Dedup is the caller's job (fireKitchenForOrder is delta-idempotent).
	onOrderMerged func(orderID, orderType, status string)
	// onOrderSynced fires after a pulled-DOWN order is committed locally AND
	// something actually changed (first insert, or a moved updated_at). The
	// handler layer uses it to broadcast the full LAN order shape to pos-web /
	// KDS — the puller can't do that itself because the shape lives in the
	// handler package. `isNew` selects order_created vs order_updated.
	onOrderSynced func(orderID, branchID string, isNew bool)
	// onPrintersSynced fires after PullPrinters commits a changed cloud printer
	// set. The handler layer uses it to reload the in-memory printer manager
	// (LoadFromDB) so newly-synced cloud printers route without a restart — the
	// puller can't do that itself because the manager lives in the printer
	// package, wired through the handler.
	onPrintersSynced func()
	// kickCh coalesces poke-driven "check the manifest early" requests
	// (#1175). Buffered 1 + non-blocking sends. This channel is the ONLY
	// shared state between the poke client and the pull loop — losing the
	// poke connection changes nothing but update latency.
	kickCh chan struct{}
	// kitchenKickCh is the same coalescing hint for GET /workstation/orders.
	// Catalog poke used to wake only the manifest loop; PayPay close lives on
	// the kitchen feed, which is not manifest-gated.
	kitchenKickCh chan struct{}
	// manifestDown tracks the legacy-fallback log state so the fallback is
	// logged once per state change, not once per 5 s tick. Touched only by
	// the manifest loop goroutine (and same-goroutine tests).
	manifestDown bool
}

// SetTracer wires the shared sync tracer so pull-DOWN outcomes appear in the
// same merged feed as UP pushes. Pass syncEngine.Tracer().
func (p *SyncPuller) SetTracer(t *SyncTracer) { p.tracer = t }

// pull runs a named DOWN feed once, timing it and recording the outcome to the
// shared tracer (errors also stay as slog.Warn for the terminal log). Success
// events are recorded only when count > 0 so idle 5 s ticks across ~11 feeds
// don't flood the ring buffer and push out the meaningful entries.
func (p *SyncPuller) pull(name string, count int, err error, latencyMS int64) {
	if err != nil {
		slog.Warn("sync_pull "+name, "err", err)
	}
	if p.tracer == nil {
		return
	}
	if err == nil && count == 0 {
		return
	}
	p.tracer.down(name, latencyMS, count, err)
}

// tracedPull is the common wrapper for feeds whose Pull* returns only an error
// (no row count) — records an event only on failure.
func (p *SyncPuller) tracedPull(name string, fn func() error) {
	_ = p.tracedPullErr(name, fn)
}

// tracedPullErr is tracedPull returning the underlying error, so the
// manifest-gated pull (#1175) can stamp a feed version only on success while
// keeping the exact same telemetry names + slog format as the legacy loops.
func (p *SyncPuller) tracedPullErr(name string, fn func() error) error {
	start := time.Now()
	err := fn()
	p.pull(name, 0, err, time.Since(start).Milliseconds())
	return err
}

func NewSyncPuller(db *store.DB, cloudURL string, tokenFn func() string) *SyncPuller {
	return &SyncPuller{
		db:            db,
		cloudURL:      cloudURL,
		tokenFn:       tokenFn,
		httpClient:    &http.Client{Timeout: 15 * time.Second},
		stopCh:        make(chan struct{}),
		kickCh:        make(chan struct{}, 1),
		kitchenKickCh: make(chan struct{}, 1),
	}
}

// SetOnUnauthorized registers a callback invoked when cloud returns 401.
// Use this to clear the device token and redirect to the pair screen.
func (p *SyncPuller) SetOnUnauthorized(fn func()) {
	p.onUnauthorized = fn
}

// SetOnOrderPaid registers a callback fired when an order first transitions to
// the paid/closed state via pull-down, so the workstation can auto-print its
// receipt for a Cloud-confirmed kiosk/customer payment. Dedup lives in
// upsertOrder (fires only when the prior local status wasn't already closed).
func (p *SyncPuller) SetOnOrderPaid(fn func(orderID, branchID string, amount int)) {
	p.onOrderPaid = fn
}

// SetOnOrderArrived registers a callback fired once when an order is first
// inserted locally from a pull-down (prior local status was absent). Used by
// the prep-first auto-print flow to fire a kitchen ticket the moment a fresh
// online takeaway order arrives (issue #456). Dedup lives in upsertOrder.
func (p *SyncPuller) SetOnOrderArrived(fn func(orderID, orderType, status string)) {
	p.onOrderArrived = fn
}

// SetOnOrderMerged registers a callback fired on every pull-down merge of an
// order that already existed locally (an update / "add more" round — NOT the
// first insert, which fires onOrderArrived instead). Used by dine-in auto-print
// to send a later appended batch to the kitchen + hold printer. The delta lives
// in fireKitchenForOrder (printed_quantity), so re-firing an unchanged order is
// a no-op. Dedup lives in upsertOrder (fires only when a prior row was present).
// SetOnOrderSynced registers a callback fired after a pulled-DOWN order is
// committed and genuinely changed. Used to broadcast order_created /
// order_updated to LAN clients so pos-web sees customer-web / kiosk orders in
// realtime instead of waiting for a manual refresh.
func (p *SyncPuller) SetOnOrderSynced(fn func(orderID, branchID string, isNew bool)) {
	p.onOrderSynced = fn
}

func (p *SyncPuller) SetOnOrderMerged(fn func(orderID, orderType, status string)) {
	p.onOrderMerged = fn
}

// SetOnPrintersSynced registers a callback fired after PullPrinters commits a
// changed cloud-printer set, so the handler can reload the printer manager from
// the local table without a restart.
func (p *SyncPuller) SetOnPrintersSynced(fn func()) {
	p.onPrintersSynced = fn
}

func (p *SyncPuller) SetCloudURLResolver(fn func() string) {
	p.cloudURLFn = fn
}

// SetHub injects the BroadcastHub so pullCustomerOrders can emit
// order_item.status_changed WS events to LAN KDS clients. Pass nil to
// disable broadcasts (e.g., in unit tests that do not test WS).
func (p *SyncPuller) SetHub(h BroadcastHub) {
	p.hub = h
}

func (p *SyncPuller) resolveURL() string {
	if p.cloudURLFn != nil {
		if u := p.cloudURLFn(); u != "" {
			return u
		}
	}
	return p.cloudURL
}

// Start kicks off the manifest-driven pull loop (#1175 — one 5 s tick +
// poke kicks, immediate first pass so the replica warms at boot), the
// kitchen orders loop (5 s, untouched by #1175 — realtime domain; it waits
// a full interval before its first tick so boot-time DB migrations settle),
// and the poke client (pure optimization; silently off when unconfigured).
func (p *SyncPuller) Start() {
	for _, run := range []func(){
		func() { p.loopWithKick(pullIntervalManifest, p.manifestTick) },
		p.runKitchenLoop,
		p.runPokeClient,
	} {
		p.workers.Add(1)
		go func(fn func()) {
			defer p.workers.Done()
			fn()
		}(run)
	}
	slog.Info("sync puller started")
}

// Stop shuts down all three puller goroutines (manifest loop, kitchen loop,
// poke client) and BLOCKS until they have actually returned. Safe to call from
// several owners; the wait is outside stopOnce so a second caller blocks too,
// rather than racing ahead of the first while goroutines are still writing.
func (p *SyncPuller) Stop() {
	p.stopOnce.Do(func() {
		close(p.stopCh)
	})

	p.workers.Wait()
	slog.Info("sync puller stopped")
}

// pullFast, pullSettings and pullSlow are the LEGACY full-pull groups —
// pre-#1175 these each had their own 5 s goroutine; now they run only as the
// per-tick fallback when Cloud has no /workstation/sync-manifest endpoint
// (see fullPullWithoutManifest in sync_manifest.go, which still runs the three groups
// concurrently so a slow endpoint in one group doesn't serialize the others).
func (p *SyncPuller) pullFast() {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	p.tracedPull("zones", func() error { return p.PullZones(ctx) })
	p.tracedPull("tables", func() error { return p.PullTables(ctx) })
	// #1806 S4 — hỏi HQ bản mong đợi. Đặt CUỐI vòng có chủ đích: nó không cấp
	// dữ liệu cho việc bán hàng, nên không được đứng trước những feed có.
	p.tracedPull("expected_build", func() error { return p.PullExpectedBuild(ctx) })
	p.tracedPull("menu", func() error { return p.PullMenu(ctx) })
	p.tracedPull("handy_menu", func() error { return p.PullHandyMenu(ctx) })
}

func (p *SyncPuller) pullSettings() {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	p.tracedPull("branch", func() error { return p.PullBranch(ctx) })
}

func (p *SyncPuller) pullSlow() {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	p.tracedPull("lots", func() error { return p.PullLots(ctx) })

	// plan-053 M3 (#1171) — the print-template registry. Low volume (13 rows
	// per branch, text) and delta-fetched with `?since=`, so it rides the slow
	// loop beside the invoices. A failure here is deliberately non-fatal for
	// the tick: the workstation keeps printing from the cache it already has,
	// and from the binary's system default if it has none (TR-05 / TR-14).
	p.tracedPull("print_templates", func() error { return p.PullPrintTemplates(ctx) })

	// #1957 mảnh B — ảnh in. Đi ngay sau template vì cùng vòng đời (brand phát
	// hành → mọi máy quán nhận ở lượt kéo kế) và vì template là nơi ghi giờ chi
	// nhánh mà lookup ảnh dùng lại. Byte bất biến theo hash nên lượt này gần như
	// luôn chỉ là một GET danh mục nhỏ; chỉ khi HQ đổi logo mới có một lượt tải.
	// Hỏng ở đây cố ý KHÔNG làm hỏng tick: quán in tiếp bằng cache đang có, và
	// nếu chưa có gì thì in phiếu không có khối ảnh (TR-05).
	p.tracedPull("print_images", func() error { return p.PullPrintImages(ctx) })

	// POS replica feeds (payment_methods, customers, menu_schedules). Without
	// this call the local payment_methods table stays empty/stale, pos-web
	// then sends payment_method IDs that don't exist on Cloud → POST
	// /pos/orders/{id}/payments fails with `payment_method_id: The selected
	// payment_method_id is invalid` (Laravel exists rule on a stale ID).
	p.pullSlowPos()
}

func (p *SyncPuller) runKitchenLoop() {
	ticker := time.NewTicker(pullIntervalKitchen)
	defer ticker.Stop()
	pull := func() {
		if err := p.pullCustomerOrders(context.Background()); err != nil {
			slog.Warn("pull customer_orders failed", "err", err)
		}
	}
	for {
		select {
		case <-p.stopCh:
			return
		case <-ticker.C:
			pull()
		case <-p.kitchenKickCh:
			pull()
		}
	}
}

// ─── Customer orders pull-DOWN (5 s tick) ────────────────────────────────

// cloudOrderPayload is the JSON shape returned by
// GET /api/v1/workstation/orders?updated_since=<cursor>.
type cloudOrderPayload struct {
	ID          string `json:"id"`
	OrderCode   string `json:"order_code"`
	OrderType   string `json:"order_type"`
	Status      string `json:"status"`
	OpenedAt    string `json:"opened_at"`
	UpdatedAt   string `json:"updated_at"`
	TableID     string `json:"table_id"`
	TableNumber string `json:"table_number"`
	// Nullable to match backend's customer_orders.guest_count column —
	// pre-fix `int` decoded JSON null to Go 0, which the INSERT below
	// then persisted as "0 guests" instead of NULL. ON CONFLICT skips
	// guest_count so existing rows aren't clobbered, but the FIRST
	// pull-down of a brand-new Cloud order was writing 0. *int decodes
	// JSON null as nil and intPtrToNullable(nil) → SQL NULL.
	GuestCount *int `json:"guest_count"`
	// #2063 — cờ ĐANG TREO của Cloud. Con trỏ vì BA trạng thái phải sống
	// sót: nil = Cloud chưa nói, false = Cloud nói không treo, true = treo.
	//
	// `bool` thường sẽ giải mã JSON null thành false, tức biến "chưa biết"
	// thành "không treo" — đúng bẫy số 2, và hậu quả là hai nút in hiện
	// lại trên đơn vừa bị chặn. Cùng lý do `GuestCount` là *int.
	IsOnHold *bool  `json:"is_on_hold"`
	Note     string `json:"note"`
	// Language the guest ordered in (customer-web app_locale, stamped by Cloud
	// at create). Drives the auto-printed dine-in kitchen + hold slips so they
	// come out in the customer's language. Empty when Cloud is older than the
	// field or the order wasn't customer-placed → the ON CONFLICT below keeps
	// whatever is stored locally.
	CustomerLocale string `json:"customer_locale"`
	// Takeaway contact + linked loyalty customer. Cloud has always serialized
	// these (CustomerOrderResourceBase) but the pull dropped them on the floor,
	// so a customer-web takeaway order reached pos-web with a blank name/phone —
	// which is exactly what the takeaway card renders and what its search box
	// filters on. Empty string when absent; the upsert COALESCEs so an older
	// cloud (or a POS-created order) never LOSES a locally-known value.
	CustomerTakeawayName  string `json:"customer_takeaway_name"`
	CustomerTakeawayPhone string `json:"customer_takeaway_phone"`
	// ScheduledPickupTime — when the takeaway customer will collect. Cloud sends
	// it as ISO-8601 (CustomerOrderResourceBase). Printed on the kitchen + serving
	// slips so staff know the collection time. Empty for dine-in/spot.
	ScheduledPickupTime string `json:"scheduled_pickup_time"`
	CustomerID          string `json:"customer_id"`
	BranchID            string `json:"branch_id"`
	BrandID             string `json:"brand_id"`
	OrgID               string `json:"organization_id"`
	// #2934 — reporting attribution for Cloud-origin orders. This is kept on
	// the order header, never copied to payments.till_session_id: online money
	// did not enter the drawer and must not affect cash reconciliation.
	CloudTillSessionID string `json:"till_session_id"`
	// Tables the order currently spans (Cloud's reverse Table.current_order_id
	// relation). POINTER so "Cloud omitted the key" (nil → leave the local
	// order_tables pivot alone, protecting a POS-created merge) is distinct from
	// "Cloud sent an explicit list" (non-nil → replace-all, including the empty
	// list when the tables were released at checkout). Without this the pivot
	// stayed empty for every customer-web QR order and pos-web rendered
	// "Chưa có bàn" — loadOrderTables reads order_tables, not orders.table_id.
	Tables *[]cloudOrderTablePayload `json:"tables"`
	// Monetary fields — without these the local order header stayed at 0, so
	// receipts printed a 0 total for cloud-synced (kiosk/customer) orders even
	// though the items synced fine. (Only the boot-time Recover path decoded
	// them; the recurring pull did not.)
	Subtotal       json.Number `json:"subtotal"`
	DiscountAmount json.Number `json:"discount_amount"`
	TaxAmount      json.Number `json:"tax_amount"`
	// #501 — service_charge was NOT parsed before, so synced orders stored 0 and
	// the kiosk bill breakdown didn't add up to the (correct) total. Cloud does
	// serialize it (CustomerOrderResourceBase::service_charge).
	ServiceCharge json.Number `json:"service_charge"`
	TotalTip      json.Number `json:"total_tip"`
	TotalAmount   json.Number `json:"total_amount"`
	PaidAmount    json.Number `json:"paid_amount"`
	// plan-043 (T3.3) — tax mode snapshot. *bool: nil when an old cloud omits
	// the field → the local upsert keeps its existing value (default 0).
	IsTaxIncluded *bool `json:"is_tax_included"`
	// plan-045 — rounding snapshot. Absent-safe: an old cloud omits them → the
	// upsert's COALESCE keeps the local value. Decimals is a *json.Number so a
	// JSON null stays NULL (currency step) distinct from an explicit 0.
	TaxRoundingMode     string                  `json:"tax_rounding_mode"`
	TaxRoundingDecimals *json.Number            `json:"tax_rounding_decimals"`
	Items               []cloudOrderItemPayload `json:"items"`
	// plan-045 — the order_conditions ledger (tax/discount regenerated, refund
	// append-only). Pointer so an old cloud that omits the key (nil) leaves the
	// local rows untouched; a non-nil (even empty) list replaces the order's rows.
	Conditions *[]cloudOrderConditionPayload `json:"conditions"`
	// #1282/#2934 — the methods and net reporting amounts of Cloud-confirmed
	// payments. An online payment (customer-web / Stripe / PayPay) never
	// produces a local `payments` row, so receipts and revenue reports read this
	// order-header projection instead. It is never materialized into `payments`:
	// that table drives drawer reconciliation, and online money there would
	// present itself as claimable till cash.
	// Pointer so "old cloud omitted the key" (nil → keep whatever is stored)
	// stays distinct from "Cloud says this order has no settled payment".
	PaymentSummary *[]cloudOrderPaymentPayload `json:"payment_summary"`
}

// cloudOrderPaymentPayload mirrors one entry of Cloud's read-only
// payment_summary projection (#1282/#2934).
type cloudOrderPaymentPayload struct {
	ID                string                           `json:"id"`
	PaymentMethodID   string                           `json:"payment_method_id"`
	PaymentMethodCode string                           `json:"payment_method_code"`
	PaymentMethodName string                           `json:"payment_method_name"`
	Amount            json.Number                      `json:"amount"`
	NetAmount         *json.Number                     `json:"net_amount"`
	Refunds           []cloudOrderPaymentRefundPayload `json:"refunds"`
	Status            string                           `json:"status"`
	PaidAt            string                           `json:"paid_at"`
}

type cloudOrderPaymentRefundPayload struct {
	ID     string      `json:"id"`
	Amount json.Number `json:"amount"`
	Status string      `json:"status"`
	PaidAt string      `json:"paid_at"`
}

// cloudOrderTablePayload mirrors one entry of Cloud's `tables[]` on the order
// resource. Only `id` is load-bearing for the pivot; the rest of the summary
// (code/name/status) already lives in the locally-pulled `tables` replica,
// which is what loadOrderTables joins against for the display label.
type cloudOrderTablePayload struct {
	ID string `json:"id"`
}

// cloudOrderConditionPayload mirrors one order_conditions row as served by Cloud
// (plan-045). amount is SIGNED; meta is passed through as raw JSON.
type cloudOrderConditionPayload struct {
	ID                string       `json:"id"`
	ConditionableType string       `json:"conditionable_type"`
	ConditionableID   string       `json:"conditionable_id"`
	Type              string       `json:"type"`
	Source            string       `json:"source"`
	Label             string       `json:"label"`
	Rate              *json.Number `json:"rate"`
	Amount            json.Number  `json:"amount"`
	// #2031 — nền chịu thuế theo mức (税率ごとに区分した対価の額). CHỈ có nghĩa
	// với `type=tax`; Cloud gửi null cho các loại khác. Con trỏ để phân biệt
	// "Cloud bản cũ chưa gửi field" với "Cloud gửi 0".
	TaxableBase  *json.Number    `json:"taxable_base"`
	CurrencyCode string          `json:"currency_code"`
	Meta         json.RawMessage `json:"meta"`
	CreatedAt    string          `json:"created_at"`
	UpdatedAt    string          `json:"updated_at"`
}

type cloudOrderItemPayload struct {
	ID           string      `json:"id"`
	ProductSkuID string      `json:"product_sku_id"`
	MenuItemID   string      `json:"menu_item_id"`
	MenuItemName string      `json:"menu_item_name"`
	Quantity     json.Number `json:"quantity"`
	UnitPrice    json.Number `json:"unit_price"`
	Subtotal     json.Number `json:"subtotal"`
	// Per-unit topping surcharge Cloud already folded into `subtotal`. The pull
	// dropped it, so a cloud-origin line with extras stored topping_subtotal = 0
	// locally — which is the exact figure the LAN cart shape and the tax
	// breakdown re-derive the gross line total from, so the POS cart disagreed
	// with the bill on every order that had toppings.
	ToppingSubtotal json.Number `json:"topping_subtotal"`
	// Pre-promotion unit price. Nil when the line carries no promotion (Cloud
	// emits null), which is the same signal the local column uses.
	OriginalUnitPrice *json.Number `json:"original_unit_price"`
	// #2618 (tầng 2 của #2273) — nguồn quyết định giá dòng. "" nghĩa là Cloud
	// KHÔNG mang field (bản cũ), không phải "không có nguồn"; upsert giữ
	// nguyên giá trị cục bộ trong ca đó thay vì ghi đè bằng rỗng.
	PriceSource string `json:"price_source"`
	Note        string `json:"note"`
	Status      string `json:"status"`
	ServedAt    string `json:"served_at"`
	VoidedAt    string `json:"voided_at"`
	UpdatedAt   string `json:"updated_at"`

	// plan-043 (T3.3) — per-line tax snapshot (mirror of Cloud's
	// CustomerOrderItemResource). Pointers/absent-safe: an old cloud omits them
	// → nil → the local column keeps its default (NULL rate → legacy fallback,
	// 0 escalation). No panic on a missing field.
	TaxTypeID string       `json:"tax_type_id"`
	TaxRate   *json.Number `json:"tax_rate"`
	TaxAmount json.Number  `json:"tax_amount"`

	// plan-045 — refund fields. A refund line arrives as an ordinary negative-qty
	// item carrying refund_of_item_id; the original line carries refunded_quantity.
	// Absent-safe: an old cloud omits them → "" / nil → normal-line defaults.
	RefundOfItemID   string       `json:"refund_of_item_id"`
	RefundedQuantity *json.Number `json:"refunded_quantity"`

	// ProductSku is the nested SKU object Cloud ships with each order item.
	// IMPORTANT: Cloud does NOT serialize `menu_item_name` on this endpoint —
	// it's a model $appends accessor that CustomerOrderItemResource's explicit
	// schemaArray() drops, so MenuItemName above is ALWAYS blank for synced
	// orders. The món name therefore has to come from this nested object, which
	// is exactly what pos-web reads (`product_sku.product.name` + variant). The
	// concrete resource snake-cases the key to `product_sku` (Eloquent's
	// `productSku` relation), so that's the JSON tag here — not `productSku`.
	ProductSku *cloudProductSkuStub `json:"product_sku"`

	// Toppings is the item's extras/modifiers (kiosk "extras"). A POINTER so we
	// can distinguish "Cloud omitted the field" (nil → leave local toppings
	// untouched, protecting POS-created orders that own their toppings locally)
	// from "Cloud sent an explicit list" (non-nil → replace-all to mirror it,
	// including the empty list when all toppings were removed).
	//
	// CONTRACT: Cloud's CustomerOrderItemResource serializes this as `toppings: [...]`
	// only when the `orderItemToppings` relation is eager-loaded. The
	// /api/v1/workstation/orders index eager-loads it so the bill/kitchen ticket
	// renders extras for synced orders too.
	Toppings *[]cloudOrderItemToppingPayload `json:"toppings"`
}

// cloudOrderItemToppingPayload mirrors one order_item_toppings row as served by
// Cloud. Field names match the local order_item_toppings columns 1:1 so the
// pull upsert is a direct copy.
type cloudOrderItemToppingPayload struct {
	ID                 string      `json:"id"`
	ToppingGroupItemID string      `json:"topping_group_item_id"`
	ProductSkuID       string      `json:"product_sku_id"`
	Name               string      `json:"name"`
	ModifierType       string      `json:"modifier_type"` // "add" | "remove"
	ToppingGroupID     string      `json:"topping_group_id"`
	ToppingGroupName   string      `json:"topping_group_name"`
	Quantity           json.Number `json:"quantity"`
	UnitPrice          json.Number `json:"unit_price"`
	// #2619 (tầng 3 của #2273) — số đơn vị được `free_up_to_n` miễn. Cloud cũ
	// bỏ field ⇒ "" ⇒ decimalToInt trả 0, đúng bằng mặc định của cột.
	WaivedQuantity json.Number `json:"waived_quantity"`
	Note           string      `json:"note"`
}

// cloudProductSkuStub is the subset of Cloud's ProductSkuResource the order
// sync needs to freeze a display name. Matches pos-web's read shape:
// `product_sku.product.name` (product), `product_sku.name` (variant suffix),
// `product_sku.sku` (human SKU code fallback).
type cloudProductSkuStub struct {
	Name    string `json:"name"` // variant label (e.g. "Large")
	Sku     string `json:"sku"`  // human SKU code (e.g. PV-1234-AB)
	Product *struct {
		Name string `json:"name"`
	} `json:"product"`
}

// nameFromStub derives a display name from the nested Cloud SKU object,
// mirroring createItem()'s "Product · Variant" convention (and pos-web's
// "Product — Variant" cart line) so a synced order line reads the same as a
// locally-added one. Returns "" when the stub carries nothing usable.
func nameFromStub(stub *cloudProductSkuStub) string {
	if stub == nil {
		return ""
	}
	product := ""
	if stub.Product != nil {
		product = strings.TrimSpace(stub.Product.Name)
	}
	variant := strings.TrimSpace(stub.Name)
	switch {
	case product != "" && variant != "":
		return product + " · " + variant
	case product != "":
		return product
	case variant != "":
		return variant
	default:
		return strings.TrimSpace(stub.Sku)
	}
}

// pullCustomerOrders fetches orders updated since the local cursor from cloud
// and upserts them into the local orders + order_items tables. On status
// change detected during upsert, broadcasts order_item.status_changed WS
// event with source="pull_down" so LAN KDS clients see cloud-fallback bumps.
func (p *SyncPuller) pullCustomerOrders(ctx context.Context) error {
	token := ""
	if p.tokenFn != nil {
		token = p.tokenFn()
	}
	if token == "" {
		return nil // not paired yet — no-op
	}

	cursor := p.getCursor(settingsCursorKey)

	start := time.Now()
	// `limit` is sent explicitly so a full page is recognisable as truncation
	// below; the cursor is escaped because Cloud may hand back an offset form
	// (`+09:00`) whose `+` would otherwise decode as a space.
	path := fmt.Sprintf("%s?limit=%d&updated_since=%s",
		pullPathCustomerOrders, customerOrdersPullLimit, url.QueryEscape(cursor))
	var payload struct {
		Data []cloudOrderPayload `json:"data"`
		// GeneratedAt is Cloud's own clock at render time — the bound the
		// cursor is held to. See clampCursorToCloudClock.
		GeneratedAt string `json:"generated_at"`
	}
	if err := p.cloudGet(ctx, path, &payload); err != nil {
		p.tracer.down("customer_orders", time.Since(start).Milliseconds(), 0, err)
		return err
	}

	// BEFORE the empty-return, not after: a cursor stuck in the future is
	// exactly what makes ticks come back empty, so a machine in that state
	// would never reach a repair placed below this point.
	cursor = p.healCursorAhead(settingsCursorKey, cursor, payload.GeneratedAt)

	if len(payload.Data) == 0 {
		return nil
	}

	// The bulk-print decision needs only old order headers. Mutation/item state
	// is loaded again in small processing chunks below so a cashier edit cannot
	// sit behind a stale 500-order snapshot while the page is being written.
	headerBatch, err := p.loadPullOrderHeaderSnapshot(payload.Data)
	if err != nil {
		p.tracer.down("customer_orders", time.Since(start).Milliseconds(), 0, err)
		return err
	}

	var maxUpdated time.Time
	if cursor != "" {
		if t, err := time.Parse(time.RFC3339, cursor); err == nil {
			maxUpdated = t
		}
	}

	// Bulk-pull guard (#141): a Cloud re-seed / backfill makes many orders
	// NEWLY appear or transition to closed in one tick, storming the kitchen /
	// receipt printers. But a normal tick can ALSO return many orders that are
	// just plain RE-PULLS (same status, no hook fires) — counting the raw tick
	// size wrongly suppressed a lone live payment riding along with those
	// re-pulls. So count only the orders that would actually fire a print hook:
	// a brand-new local row (arrived / first-seen-paid) or an existing order that
	// just flipped to closed (paid). Only a bulk of THOSE is a re-seed.
	firingCount := 0
	for _, order := range payload.Data {
		state := headerBatch.orders[order.ID]
		old := sql.NullString{}
		if state != nil {
			old = state.oldStatus
		}
		isNew := !old.Valid
		becameClosed := old.Valid && old.String != "closed" && order.Status == "closed"
		if isNew || becameClosed {
			firingCount++
		}
	}
	bulkMax := p.autoPrintBulkMax()
	suppressAutoPrint := firingCount > bulkMax
	if suppressAutoPrint {
		slog.Warn("bulk order pull — auto-print suppressed (likely Cloud re-seed/backfill)",
			"firing", firingCount, "tick", len(payload.Data), "max", bulkMax)
	}

	upserted := 0
	for chunkStart := 0; chunkStart < len(payload.Data); chunkStart += pullOrderProcessingChunkSize {
		chunkEnd := chunkStart + pullOrderProcessingChunkSize
		if chunkEnd > len(payload.Data) {
			chunkEnd = len(payload.Data)
		}
		chunk := payload.Data[chunkStart:chunkEnd]
		batch, err := p.loadPullBatchSnapshot(chunk)
		if err != nil {
			p.tracer.down("customer_orders", time.Since(start).Milliseconds(), upserted, err)
			return err
		}
		for _, order := range chunk {
			// Periodic mirror: skip orders this workstation created locally to
			// avoid inserting a duplicate keyed by the cloud id (plan-041).
			if err := p.upsertOrderCtxWithSnapshot(
				order,
				true,
				suppressAutoPrint,
				batch.orders[order.ID],
				batch.skuNames,
			); err != nil {
				slog.Warn("upsert order failed", "order_id", order.ID, "err", err)
				continue
			}
			upserted++
			if t, err := time.Parse(time.RFC3339, order.UpdatedAt); err == nil {
				if t.After(maxUpdated) {
					maxUpdated = t
				}
			}
		}
	}
	p.tracer.down("customer_orders", time.Since(start).Milliseconds(), upserted, nil)

	// A full page means Cloud had more rows to give. Clamping here would pin
	// the cursor below the backlog and re-serve the same page every tick —
	// the same silent blindness this fix exists to remove, just triggered by
	// volume instead of by a bad timestamp. Let a genuine backlog walk forward
	// on its own timestamps; the clamp resumes once the page stops being full.
	pageFull := len(payload.Data) >= customerOrdersPullLimit
	if !pageFull {
		maxUpdated = clampCursorToCloudClock(maxUpdated, payload.GeneratedAt)
	}

	newCursor := maxUpdated.UTC().Format(time.RFC3339)

	// Liveness backstop. A full page whose rows all share the cursor's own
	// second leaves newCursor == cursor, so nothing is written and Cloud
	// re-serves the identical page forever — blind again, by a different road.
	// A mass UPDATE reaches this: the tax-snapshot and tax-type backfills stamp
	// one timestamp across every row they touch, and the feed is `>=` inclusive
	// against a second-precision cursor.
	//
	// Stepping one second past it can skip rows still unseen inside that
	// second. That is the lesser loss by a wide margin: the alternative is
	// every future order, forever. Logged at ERROR because a shop that hits
	// this has lost data and needs a human, not a warning it will never read.
	if pageFull && newCursor == cursor && !maxUpdated.IsZero() {
		stepped := maxUpdated.Add(time.Second)
		slog.Error("customer_orders cursor stalled on a full page — stepping past it; rows sharing this second may be skipped",
			"cursor", cursor, "stepped_to", stepped.UTC().Format(time.RFC3339),
			"rows", len(payload.Data), "limit", customerOrdersPullLimit)
		// Comment ngay trên nói đúng điều này cần: "một con người, không phải một
		// warning nó sẽ không bao giờ đọc". Đây là chỗ alert centre trả lời.
		p.alerts.Raise(KindSyncStalled, "customer_orders",
			"Con trỏ đồng bộ đơn hàng bị kẹt — có thể mất dữ liệu",
			map[string]any{"cursor": cursor, "stepped_to": stepped.UTC().Format(time.RFC3339)})

		maxUpdated = stepped
		newCursor = stepped.UTC().Format(time.RFC3339)
	}

	if newCursor != cursor && !maxUpdated.IsZero() {
		if err := p.setCursor(settingsCursorKey, newCursor); err != nil {
			slog.Warn("save customer_orders cursor", "err", err)
		}
	}
	return nil
}

// pullOrderNowTimeout caps the on-demand Cloud GET so a slow Cloud doesn't
// freeze a cashier's "Gửi bếp" click. 1.5 s matches the user-perceptible
// threshold for a button press on a thermal printer (which itself adds
// ~0.5–1 s of cut/feed time). On timeout the handler returns 504 to pos-web
// so the cashier sees a clear "Workstation đang đồng bộ, thử lại sau" toast.
const pullOrderNowTimeout = 1500 * time.Millisecond

// ErrOrderNotFoundOnCloud signals that the on-demand projection returned
// zero rows. Handler maps this to 404 — distinct from a timeout (504) or
// Cloud 5xx (503) so the UI copy can be specific.
var ErrOrderNotFoundOnCloud = fmt.Errorf("order not found on cloud")

// PullOrderNow performs a synchronous Cloud GET for a specific order id and
// upserts it into local SQLite. Called by the LAN print handlers when the
// local row is missing — closes the 5 s sync race that would otherwise
// silently 404 the print after pos-web just created the order on Cloud.
//
// Returns:
//   - nil on success (row is upserted, caller can re-read from db)
//   - ErrOrderNotFoundOnCloud when Cloud responds 200 with empty data
//   - context.DeadlineExceeded when the 1.5 s budget is exhausted
//   - other errors for Cloud 4xx/5xx or transport failures
//
// Does NOT advance the customer_orders cursor — the on-demand projection
// is a side-channel that mustn't shift the periodic puller's resume point.
func (p *SyncPuller) PullOrderNow(ctx context.Context, orderID string) error {
	if orderID == "" {
		return fmt.Errorf("order_id required")
	}
	ctx, cancel := context.WithTimeout(ctx, pullOrderNowTimeout)
	defer cancel()

	path := pullPathCustomerOrders + "?id=" + orderID
	var payload struct {
		Data []cloudOrderPayload `json:"data"`
	}
	if err := p.cloudGet(ctx, path, &payload); err != nil {
		return fmt.Errorf("force-pull %s: %w", orderID, err)
	}
	if len(payload.Data) == 0 {
		return ErrOrderNotFoundOnCloud
	}
	for _, order := range payload.Data {
		// On-demand force-pull for a row the workstation is missing: never
		// skip — its whole job is to materialise the requested order.
		if err := p.upsertOrder(order, false); err != nil {
			return fmt.Errorf("upsert force-pulled order %s: %w", order.ID, err)
		}
	}
	return nil
}

// statusChange records a status transition for post-commit WS broadcasting.
type statusChange struct {
	orderID  string
	itemID   string
	oldSt    string
	newSt    string
	branchID string
}

// upsertOrder writes one cloud order (header + items) into the local SQLite
// replica inside a single transaction. Status changes on order_items are
// collected during the transaction and broadcast after commit so the DB write
// is never held open by the hub's mutex.
// resolveMenuItemName freezes an order item's display name into order_items at
// import time (the "store name at order time" fix). Resolution priority:
//  1. the name Cloud already sent — but note this endpoint never serializes
//     menu_item_name (see cloudOrderItemPayload), so `current` is ~always blank
//     for synced orders; kept first only for the rare caller that does set it;
//  2. the nested productSku Cloud ships with the order — POS-parity: the món
//     name travels WITH the order so an item whose SKU is missing from the local
//     catalog mirror still prints a real name instead of "(unknown)";
//  3. the local product catalog (pos_product_skus → pos_products) — last-ditch
//     backfill for payloads that carry neither a name nor a nested SKU.
//
// Returns "" only when none of the above resolve — the print path's
// SKU-code/UUID fallback then handles it.
func (p *SyncPuller) resolveMenuItemName(productSkuID, current string, stub *cloudProductSkuStub) string {
	if strings.TrimSpace(current) != "" {
		return current
	}
	if n := nameFromStub(stub); n != "" {
		return n
	}
	if productSkuID == "" {
		return current
	}
	var productName string
	if err := p.db.QueryRow(
		`SELECT p.name
		   FROM pos_product_skus ps
		   JOIN pos_products p ON p.id = ps.product_id
		  WHERE ps.id = ?`, productSkuID,
	).Scan(&productName); err == nil && strings.TrimSpace(productName) != "" {
		return productName
	}
	return current
}

func (p *SyncPuller) resolveMenuItemNameCached(
	productSkuID, current string,
	stub *cloudProductSkuStub,
	skuNames map[string]string,
) string {
	if skuNames == nil {
		return p.resolveMenuItemName(productSkuID, current, stub)
	}
	if strings.TrimSpace(current) != "" {
		return current
	}
	if name := nameFromStub(stub); name != "" {
		return name
	}
	if name := skuNames[productSkuID]; strings.TrimSpace(name) != "" {
		return name
	}
	return current
}

// upsertOrder mirrors one Cloud order into local SQLite and fires the
// arrived/merged/paid auto-print hooks. Thin wrapper over upsertOrderCtx with
// auto-print always enabled — the default for on-demand single-order pulls.
func (p *SyncPuller) upsertOrder(order cloudOrderPayload, skipLocallyOwned bool) error {
	return p.upsertOrderCtx(order, skipLocallyOwned, false)
}

// upsertOrderCtx is upsertOrder with an explicit suppressAutoPrint flag. The
// periodic mirror sets it when a single pull tick returns a bulk of orders
// (a Cloud re-seed / backfill), so those orders are mirrored + broadcast to LAN
// clients but do NOT auto-fire kitchen tickets / receipts (#141). The DB upsert
// and the onOrderSynced LAN broadcast are unaffected — only the three print
// hooks (onOrderArrived / onOrderMerged / onOrderPaid) are gated.
func (p *SyncPuller) upsertOrderCtx(order cloudOrderPayload, skipLocallyOwned, suppressAutoPrint bool) error {
	batch, err := p.loadPullBatchSnapshot([]cloudOrderPayload{order})
	if err != nil {
		return err
	}
	return p.upsertOrderCtxWithSnapshot(
		order,
		skipLocallyOwned,
		suppressAutoPrint,
		batch.orders[order.ID],
		batch.skuNames,
	)
}

func (p *SyncPuller) upsertOrderCtxWithSnapshot(
	order cloudOrderPayload,
	skipLocallyOwned bool,
	suppressAutoPrint bool,
	state *pullOrderSnapshot,
	skuNames map[string]string,
) error {
	if state == nil {
		state = newPullOrderSnapshot()
	}
	// plan-041 — duplicate-order guard.
	//
	// Orders this workstation CREATED locally keep their LOCAL uuid as the
	// primary key and carry `cloud_id = <cloud order id>` once they sync UP.
	// The upsert below keys the row by the CLOUD id (`order.ID`), so for a
	// locally-created order (local pk ≠ cloud id) the INSERT does NOT collide
	// on the primary key and writes a SECOND row — duplicating the order (same
	// order_code, two rows). The workstation is the source of truth for orders
	// it created, so there is nothing to mirror back: skip them.
	//
	// The guard only matches the locally-created shape (`cloud_id = order.ID
	// AND id <> order.ID`). Cloud-origin orders (other devices / customer-web)
	// have no such row — their pulled mirror uses id == cloud_id — so they
	// upsert normally. Only the periodic 60s pull passes skipLocallyOwned=true;
	// the on-demand PullOrderNow (missing-row force-pull for the print path)
	// passes false so it always materialises the requested order.
	if skipLocallyOwned && state.locallyOwned {
		return nil
	}

	// Pending-local-mutation guard (the PullTables pattern, applied to order
	// items). While an item edit is still waiting in the sync queue, Cloud's
	// copy PREDATES the edit — adopting it would revert the operator's change
	// on the very next 5s tick (the "served flips back to pending" bug: the
	// cashier bumps a line, the pull clobbers it before the bump lands on
	// Cloud). Preserve exactly the locally-edited fields; once the op syncs
	// (or dead-letters), the row drops out of this set and Cloud — which by
	// then echoes the edit — rules again. Convergence is guaranteed either
	// way: a Cloud 409 on the status op reverts the local row itself
	// (KdsBumpSyncHandler), and a dead-letter simply re-opens Cloud wins.
	// Order header: any unsynced lifecycle op (confirm / checkout / void /
	// delete) means the LOCAL order status is ahead of Cloud's copy — adopting
	// Cloud's would un-do the operator's action on the next tick (accepted →
	// snaps back to confirmed and the checkout CTA vanishes; checkout → snaps
	// back to open; voided → resurrects). Cloud's terminal states (closed /
	// voided) still always win — see the status CASE below.
	// Snapshot the order's prior local status so onOrderPaid fires exactly
	// once, on the transition into "closed" (a fresh cloud-paid order, or an
	// open→closed edit). A re-pull of an already-closed order finds the same
	// status and does not reprint.
	// oldUpdatedAt rides along so the post-commit LAN broadcast can tell a real
	// change from the cursor-boundary re-pull. The cursor is stored at second
	// precision and applied with `>=`, so the newest order comes back on EVERY
	// 5 s tick; broadcasting unconditionally would re-push it to pos-web forever.
	oldStatuses := state.itemStatuses
	preserve := state.preserve
	pendingLifecycleOp := state.pendingLifecycle
	oldOrderStatus := state.oldStatus
	oldUpdatedAt := state.oldUpdatedAt

	openedAt := order.OpenedAt
	if openedAt == "" {
		openedAt = order.UpdatedAt
	}

	var changes []statusChange

	if err := p.atomic(func(tx *sql.Tx) error {
		// Upsert order header (post-Sprint-4 aligned schema).
		// UPDATE only cloud-authoritative fields; leave workstation-local
		// fields (order_number, etc.) untouched on conflict.
		if _, err := tx.Exec(`
			INSERT INTO orders (
				id, cloud_id, order_code, order_type, status, opened_at,
				table_id, table_number, guest_count, note, customer_locale,
				customer_takeaway_name, customer_takeaway_phone, scheduled_pickup_time, customer_id,
				branch_id, brand_id, organization_id, cloud_till_session_id,
				subtotal, discount_amount, tax_amount, service_charge, total_tip, total_amount, paid_amount,
				is_tax_included,
				tax_rounding_mode, tax_rounding_decimals,
				cloud_payment_summary,
				is_on_hold,
				created_at, updated_at, synced_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON CONFLICT(id) DO UPDATE SET
				-- Order lifecycle is workstation-owned (source of truth). The 60s
				-- mirror must NOT reopen an order the workstation already closed on a
				-- full local payment — Cloud's copy can lag (the payment hasn't been
				-- pushed yet), and clobbering 'closed' → 'open' drops the order back
				-- into ListActive and makes the customer look unpaid. Keep the local
				-- 'closed'; adopt Cloud's status for every other transition.
				status = CASE
					WHEN orders.status = 'closed' AND excluded.status NOT IN ('closed','voided')
						THEN orders.status
					-- A lifecycle op (confirm/checkout/void/delete) is still
					-- unsynced: the local status is AHEAD of Cloud's copy —
					-- keep it, or the operator's action un-does itself on the
					-- next tick. Cloud's terminal states still win.
					WHEN ? = 1 AND excluded.status NOT IN ('closed','voided')
						THEN orders.status
					ELSE excluded.status
				END,
				opened_at       = excluded.opened_at,
				note            = excluded.note,
				-- Cloud owns the order code for a cloud-origin order; adopt it
				-- whenever Cloud sent one. Guarded by COALESCE + NULLIF so an
				-- older/partial payload can never blank out a code pos-web is
				-- already showing on its tab.
				order_code      = COALESCE(NULLIF(excluded.order_code, ''), orders.order_code),
				-- The serving table can be assigned/released after the first
				-- pull (QR seat, checkout release). Previously table_id was
				-- INSERT-only, so a later assignment never landed.
				table_id        = COALESCE(excluded.table_id, orders.table_id),
				table_number    = COALESCE(excluded.table_number, orders.table_number),
				guest_count     = COALESCE(excluded.guest_count, orders.guest_count),
				-- #2063 — COALESCE, KHÔNG gán thẳng: một payload không mang cờ
				-- (đường GHI của Cloud) phải để nguyên giá trị đang có, chứ không
				-- xoá nó về NULL. Ghi đè chỉ xảy ra khi Cloud thật sự nói.
				is_on_hold      = COALESCE(excluded.is_on_hold, orders.is_on_hold),
				-- Takeaway contact: adopt Cloud's when present, never blank out
				-- a value the workstation already holds.
				customer_takeaway_name  = COALESCE(excluded.customer_takeaway_name, orders.customer_takeaway_name),
				customer_takeaway_phone = COALESCE(excluded.customer_takeaway_phone, orders.customer_takeaway_phone),
				scheduled_pickup_time   = COALESCE(excluded.scheduled_pickup_time, orders.scheduled_pickup_time),
				customer_id             = COALESCE(excluded.customer_id, orders.customer_id),
				-- #2934 — order attribution is reporting-only. It never flows
				-- into payments.till_session_id or the drawer reconcile.
				cloud_till_session_id   = COALESCE(excluded.cloud_till_session_id, orders.cloud_till_session_id),
				-- Keep the local value when Cloud sent none (old cloud / non
				-- customer-placed order) so a mirrored order never LOSES the
				-- locale its slips were printing in.
				customer_locale = COALESCE(excluded.customer_locale, orders.customer_locale),
				subtotal        = excluded.subtotal,
				discount_amount = excluded.discount_amount,
				tax_amount      = excluded.tax_amount,
				service_charge  = excluded.service_charge,
				total_tip       = excluded.total_tip,
				total_amount    = excluded.total_amount,
				paid_amount     = excluded.paid_amount,
				-- plan-043 — adopt Cloud's tax mode only when it sent one (an old
				-- cloud omits is_tax_included → COALESCE keeps the local value).
				is_tax_included = COALESCE(excluded.is_tax_included, orders.is_tax_included),
				-- plan-045 — adopt Cloud's rounding snapshot only when it sent one
				-- (an old cloud omits both → COALESCE keeps the local value).
				tax_rounding_mode     = COALESCE(excluded.tax_rounding_mode, orders.tax_rounding_mode),
				tax_rounding_decimals = COALESCE(excluded.tax_rounding_decimals, orders.tax_rounding_decimals),
				-- #1282 — adopt Cloud's payment-method summary only when it sent
				-- one. A cloud older than the field omits the key → NULL here →
				-- COALESCE keeps what we already hold, so a receipt reprinted
				-- after a partial-payload tick never LOSES the method it printed.
				cloud_payment_summary = COALESCE(excluded.cloud_payment_summary, orders.cloud_payment_summary),
				updated_at      = excluded.updated_at,
				synced_at       = excluded.synced_at
		`,
			order.ID, order.ID, order.OrderCode, order.OrderType, order.Status, openedAt,
			nullableString(order.TableID), nullableString(order.TableNumber),
			intPtrToNullable(order.GuestCount), nullableString(order.Note),
			nullableString(order.CustomerLocale),
			nullableString(order.CustomerTakeawayName), nullableString(order.CustomerTakeawayPhone),
			nullableString(order.ScheduledPickupTime),
			nullableString(order.CustomerID),
			order.BranchID, order.BrandID, order.OrgID, nullableString(order.CloudTillSessionID),
			decimalToInt(order.Subtotal), decimalToInt(order.DiscountAmount),
			decimalToInt(order.TaxAmount), decimalToInt(order.ServiceCharge), decimalToInt(order.TotalTip),
			decimalToInt(order.TotalAmount), decimalToInt(order.PaidAmount),
			boolPtrToNullableInt(order.IsTaxIncluded),
			nullableString(order.TaxRoundingMode), decimalPtrToNullableInt(order.TaxRoundingDecimals),
			encodeCloudPaymentSummary(order.PaymentSummary),
			// #2063 — nil ⇒ NULL, giữ nguyên "Cloud chưa nói".
			boolPtrToNullableInt(order.IsOnHold),
			openedAt, order.UpdatedAt, order.UpdatedAt,
			pendingLifecycleOp,
		); err != nil {
			return fmt.Errorf("upsert orders: %w", err)
		}

		for _, item := range order.Items {
			createdAt := item.UpdatedAt
			if createdAt == "" {
				createdAt = order.UpdatedAt
			}

			// Pending-local-mutation guard flags for this line (see the
			// collection above). A Cloud-side VOID always wins — the in-flight
			// status op will 409 on Cloud and KdsBumpSyncHandler reverts the
			// local row, so refusing the void here would only delay it.
			ip := preserve[item.ID]
			keepStatus := boolToInt(ip != nil && (ip.status || ip.void) && item.Status != "voided")
			keepVoid := boolToInt(ip != nil && ip.void && item.Status != "voided")
			keepNote := boolToInt(ip != nil && ip.note)
			keepQty := boolToInt(ip != nil && ip.qty)

			if _, err := tx.Exec(`
				INSERT INTO order_items (
					id, customer_order_id, product_sku_id, menu_item_id, menu_item_name,
					quantity, unit_price, subtotal, topping_subtotal, original_unit_price,
					price_source,
					note, status, served_at, voided_at,
					tax_type_id, tax_rate, tax_amount,
					refund_of_item_id, refunded_quantity,
					created_at, updated_at, synced_at, printer_group
				) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
				ON CONFLICT(id) DO UPDATE SET
					status     = CASE WHEN ? = 1 THEN order_items.status ELSE excluded.status END,
					served_at  = CASE WHEN ? = 1 THEN order_items.served_at ELSE excluded.served_at END,
					voided_at  = CASE WHEN ? = 1 THEN order_items.voided_at ELSE excluded.voided_at END,
					note       = CASE WHEN ? = 1 THEN order_items.note ELSE excluded.note END,
					quantity   = CASE WHEN ? = 1 THEN order_items.quantity ELSE excluded.quantity END,
					unit_price = excluded.unit_price,
					subtotal   = excluded.subtotal,
					-- Cloud is authoritative for a cloud-origin line's extras
					-- surcharge; the LAN cart shape and the tax breakdown both
					-- rebuild the gross line total from it.
					topping_subtotal   = excluded.topping_subtotal,
					original_unit_price = COALESCE(excluded.original_unit_price, order_items.original_unit_price),
					-- #2618 — dấu vết nguồn giá. COALESCE + NULLIF: Cloud cũ gửi
					-- field rỗng/thiếu ⇒ NULL ⇒ giữ giá trị đã có, chứ không xoá
					-- một dấu vết hợp lệ bằng chuỗi rỗng.
					price_source          = COALESCE(NULLIF(excluded.price_source, ''), order_items.price_source),
					-- Mark the mirrored line as already-synced. Without this
					-- reconcileUnsyncedItems (which keys on cloud_id != '' AND
					-- synced_at IS NULL) re-enqueues an order.item_add op for
					-- every item of every order we just pulled DOWN, echoing
					-- cloud-origin lines straight back UP to Cloud.
					synced_at  = excluded.synced_at,
					-- plan-043 — adopt Cloud's per-line tax snapshot (authoritative
					-- for cloud-origin orders). An old cloud omits tax_rate → NULL
					-- → the engine's legacy fallback still prices the line.
					tax_type_id           = excluded.tax_type_id,
					tax_rate              = excluded.tax_rate,
					tax_amount            = excluded.tax_amount,
					-- plan-045 — refund columns. A refund line arrives as a negative-
					-- qty item with refund_of_item_id; the original carries the bumped
					-- refunded_quantity. COALESCE keeps the local value when Cloud omits.
					refund_of_item_id     = COALESCE(excluded.refund_of_item_id, order_items.refund_of_item_id),
					refunded_quantity     = COALESCE(excluded.refunded_quantity, order_items.refunded_quantity),
					-- Backfill a blank/"(unknown)" snapshot once a name resolves
					-- (e.g. a later payload carries the nested product_sku, or the
					-- catalog finished syncing). A name already frozen at order
					-- time is kept verbatim — re-sync must never clobber a good
					-- label.
					menu_item_name = CASE
						WHEN TRIM(COALESCE(order_items.menu_item_name, '')) IN ('', '(unknown)')
							THEN excluded.menu_item_name
						ELSE order_items.menu_item_name
					END,
					updated_at = excluded.updated_at
			`,
				item.ID, order.ID,
				nullableString(item.ProductSkuID), nullableString(item.MenuItemID),
				p.resolveMenuItemNameCached(
					item.ProductSkuID,
					item.MenuItemName,
					item.ProductSku,
					skuNames,
				),
				decimalToInt(item.Quantity), decimalToInt(item.UnitPrice), decimalToInt(item.Subtotal),
				decimalToInt(item.ToppingSubtotal), decimalPtrToNullableInt(item.OriginalUnitPrice),
				nullableString(item.PriceSource),
				nullableString(item.Note), item.Status,
				nullableString(item.ServedAt), nullableString(item.VoidedAt),
				nullableString(item.TaxTypeID), decimalPtrToNullable(item.TaxRate),
				decimalToInt(item.TaxAmount),
				nullableString(item.RefundOfItemID), decimalPtrToNullableInt(item.RefundedQuantity),
				createdAt, item.UpdatedAt, item.UpdatedAt, "kitchen",
				keepStatus, keepStatus, keepVoid, keepNote, keepQty,
			); err != nil {
				return fmt.Errorf("upsert order_items: %w", err)
			}

			// Mirror the item's toppings/extras when Cloud sends them. Pointer is
			// nil when Cloud omits the field → leave local toppings untouched (do
			// NOT wipe a POS-created order's own toppings). Non-nil → replace-all
			// so the bill + kitchen ticket render the same extras the customer
			// chose. Replace-all (DELETE then re-INSERT) is the only correct way
			// to reflect a removed topping.
			if item.Toppings != nil && !(ip != nil && ip.toppings) {
				if _, err := tx.Exec(`DELETE FROM order_item_toppings WHERE order_item_id = ?`, item.ID); err != nil {
					return fmt.Errorf("clear order_item_toppings: %w", err)
				}
				for _, t := range *item.Toppings {
					modType := t.ModifierType
					if modType == "" {
						modType = "add"
					}
					tid := t.ID
					if strings.TrimSpace(tid) == "" {
						tid = uuid.NewString()
					}
					if _, err := tx.Exec(`
						INSERT INTO order_item_toppings (
							id, order_item_id, topping_group_item_id, product_sku_id,
							name, modifier_type, topping_group_id, topping_group_name,
							quantity, unit_price, waived_quantity, note, created_at
						) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
					`,
						tid, item.ID, t.ToppingGroupItemID, t.ProductSkuID,
						nullableString(t.Name), modType, nullableString(t.ToppingGroupID), nullableString(t.ToppingGroupName),
						decimalToInt(t.Quantity), decimalToInt(t.UnitPrice), decimalToInt(t.WaivedQuantity),
						nullableString(t.Note), createdAt,
					); err != nil {
						return fmt.Errorf("insert order_item_toppings: %w", err)
					}
				}
			}

			// Collect status changes for post-commit broadcast.
			// New rows (no prior snapshot) don't trigger an event. A preserved
			// status did NOT change on disk — broadcasting Cloud's stale value
			// here is exactly the phantom "revert" pos-web used to render.
			if keepStatus == 1 {
				continue
			}
			if oldSt, exists := oldStatuses[item.ID]; exists && oldSt != item.Status {
				changes = append(changes, statusChange{
					orderID:  order.ID,
					itemID:   item.ID,
					oldSt:    oldSt,
					newSt:    item.Status,
					branchID: order.BranchID,
				})
			}
		}

		// plan-045 — upsert the order_conditions ledger. Cloud is authoritative
		// for tax/discount (regenerated) + refund (append-only) rows; a non-nil
		// list replaces the order's rows (order-level + item-level) so a deleted
		// condition on Cloud disappears locally. Nil (old cloud) leaves them alone.
		if order.Conditions != nil {
			if err := p.upsertOrderConditions(tx, order.ID, *order.Conditions); err != nil {
				return err
			}
		}

		// Mirror the tables the order spans into the order_tables pivot. That
		// pivot — not orders.table_id — is what the LAN order shape reads for
		// `tables[]`, so before this a customer-web QR order arrived with the
		// right table_id but rendered "Chưa có bàn" in pos-web's cart. Nil
		// (Cloud omitted the key) leaves a POS-created merge alone.
		if order.Tables != nil {
			if err := upsertOrderTables(tx, order.ID, *order.Tables); err != nil {
				return err
			}
		}
		return nil
	}); err != nil {
		return err
	}

	// Broadcast after commit — hub must not block the DB transaction.
	if p.hub != nil {
		for _, ch := range changes {
			p.hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
				"order_id":        ch.orderID,
				"item_id":         ch.itemID,
				"previous_status": ch.oldSt,
				"status":          ch.newSt,
				"source":          "pull_down",
			}, ch.branchID)
		}
	}

	// Auto-print the receipt for an order that just became paid/closed via
	// pull-down. Kiosk/customer payments are confirmed in Cloud, so the
	// workstation's local payment endpoint (which prints on confirm) never
	// fires for them — this hook closes that gap. Dedup: only on the
	// transition into closed, so the re-pull of the same order doesn't
	// reprint. Runs after commit so printer I/O never holds the transaction.
	if !suppressAutoPrint && p.onOrderPaid != nil && order.Status == "closed" &&
		(!oldOrderStatus.Valid || oldOrderStatus.String != "closed") {
		amount := decimalToInt(order.PaidAmount)
		if amount == 0 {
			amount = decimalToInt(order.TotalAmount)
		}
		p.onOrderPaid(order.ID, order.BranchID, amount)
	}

	// Fire the "order arrived" hook exactly once, when the order is first
	// inserted locally (no prior row). Dedup: a re-pull finds an existing
	// status and skips. The handler decides whether to print a kitchen ticket
	// based on the prep_before_payment setting + order type (issue #456).
	// suppressAutoPrint gates it during a bulk re-seed / backfill tick (#141).
	if !suppressAutoPrint && p.onOrderArrived != nil && !oldOrderStatus.Valid {
		p.onOrderArrived(order.ID, order.OrderType, string(order.Status))
	}
	// Fire the "order merged" hook on every SUBSEQUENT merge (a prior row
	// existed) — an update / "add more" round. Dine-in auto-print uses this to
	// send a later appended batch to the kitchen. Mutually exclusive with the
	// arrived hook above (first insert → arrived; updates → merged) so the two
	// never double-invoke for the same pull. Gated by suppressAutoPrint so a
	// bulk re-seed can't reprint kitchen tickets through this path — the one the
	// opened_at age gate can't guard without breaking legit late appends (#141).
	if !suppressAutoPrint && p.onOrderMerged != nil && oldOrderStatus.Valid {
		p.onOrderMerged(order.ID, order.OrderType, string(order.Status))
	}

	// Push the mirrored order to LAN clients (pos-web / KDS).
	//
	// This is the seam that made customer-web orders invisible: pos-web turns
	// its list polling OFF while the workstation socket is up (see
	// `pos/page.tsx` — refetchInterval only when unreachable) and relies purely
	// on `order_created` / `order_updated` to patch its React Query cache. The
	// pull path emitted neither, so an order placed from customer-web landed in
	// SQLite and stayed there until someone reloaded the tab.
	//
	// Fired only on a REAL change — a brand-new local row, or an updated_at
	// that actually moved. The pull cursor is second-precision and inclusive, so
	// the newest order is re-fetched on every 5 s tick; without this guard we
	// would re-broadcast it forever.
	if p.onOrderSynced != nil {
		isNew := !oldOrderStatus.Valid
		if isNew || !oldUpdatedAt.Valid || oldUpdatedAt.String != order.UpdatedAt {
			p.onOrderSynced(order.ID, order.BranchID, isNew)
		}
	}
	return nil
}

// upsertOrderTables replaces the order_tables pivot for an order with the set
// Cloud reports. Replace-all (DELETE + re-INSERT) is the only correct mirror:
// a table released at checkout has to disappear, and Cloud sends the full list
// every time. `table_id` carries no FK (see migration 005), so a table not yet
// present in the local `tables` replica still binds and resolves its label on
// the next TMS pull rather than failing the whole order transaction.
func upsertOrderTables(tx *sql.Tx, orderID string, tables []cloudOrderTablePayload) error {
	if _, err := tx.Exec(`DELETE FROM order_tables WHERE order_id = ?`, orderID); err != nil {
		return fmt.Errorf("clear order_tables: %w", err)
	}
	for i, t := range tables {
		if strings.TrimSpace(t.ID) == "" {
			continue
		}
		if _, err := tx.Exec(`
			INSERT INTO order_tables (order_id, table_id, sort_order)
			VALUES (?, ?, ?)
			ON CONFLICT(order_id, table_id) DO UPDATE SET sort_order = excluded.sort_order
		`, orderID, t.ID, i); err != nil {
			return fmt.Errorf("insert order_tables: %w", err)
		}
	}
	return nil
}

// upsertOrderConditions replaces the order_conditions ledger for an order with
// Cloud's authoritative set (plan-045). It deletes the current order-level +
// item-level rows for the order, then re-inserts the incoming ones — replace-all
// is the only correct way to reflect a tax/discount row Cloud regenerated (or a
// refund never re-touched). Runs inside the caller's pull transaction.
func (p *SyncPuller) upsertOrderConditions(tx *sql.Tx, orderID string, conditions []cloudOrderConditionPayload) error {
	// Clear the order's existing conditions (order-level + all its items).
	if _, err := tx.Exec(`
		DELETE FROM order_conditions
		WHERE (conditionable_type = 'order' AND conditionable_id = ?)
		   OR (conditionable_type = 'order_item'
		       AND conditionable_id IN (SELECT id FROM order_items WHERE customer_order_id = ?))`,
		orderID, orderID,
	); err != nil {
		return fmt.Errorf("clear order_conditions: %w", err)
	}

	for _, c := range conditions {
		id := c.ID
		if strings.TrimSpace(id) == "" {
			id = uuid.NewString()
		}
		var meta any
		if len(c.Meta) > 0 && string(c.Meta) != "null" {
			meta = string(c.Meta)
		}
		var rate any
		if c.Rate != nil && *c.Rate != "" {
			if f, err := c.Rate.Float64(); err == nil {
				rate = f
			}
		}
		// Giữ NULL khi Cloud không gửi (bản cũ) hoặc gửi null — `taxable_base`
		// chỉ có nghĩa với dòng `tax`, và 0 là một tuyên bố khác hẳn "không biết".
		var taxableBase any
		if c.TaxableBase != nil && *c.TaxableBase != "" {
			if f, err := c.TaxableBase.Float64(); err == nil {
				taxableBase = f
			}
		}
		amount := 0.0
		if c.Amount != "" {
			amount, _ = c.Amount.Float64()
		}
		currency := c.CurrencyCode
		if currency == "" {
			currency = "VND"
		}
		if _, err := tx.Exec(`
			INSERT INTO order_conditions (
				id, conditionable_type, conditionable_id, type, source, label,
				rate, amount, taxable_base, currency_code, meta, created_at, updated_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON CONFLICT(id) DO UPDATE SET
				conditionable_type = excluded.conditionable_type,
				conditionable_id   = excluded.conditionable_id,
				type               = excluded.type,
				source             = excluded.source,
				label              = excluded.label,
				rate               = excluded.rate,
				amount             = excluded.amount,
				taxable_base       = excluded.taxable_base,
				currency_code      = excluded.currency_code,
				meta               = excluded.meta,
				updated_at         = excluded.updated_at`,
			id, c.ConditionableType, c.ConditionableID, c.Type,
			nullableString(c.Source), c.Label, rate, amount, taxableBase, currency, meta,
			nullableString(c.CreatedAt), nullableString(c.UpdatedAt),
		); err != nil {
			return fmt.Errorf("insert order_condition: %w", err)
		}
	}
	return nil
}

// getCursor reads the pull cursor for the given settings key. Returns empty
// string if the key does not exist yet (first pull after pairing).
func (p *SyncPuller) getCursor(key string) string {
	var val string
	_ = p.db.QueryRow(`SELECT value FROM settings WHERE key = ?`, key).Scan(&val)
	return val
}

// parseCloudTime parses a timestamp exactly as Cloud serialises it. Cloud emits
// RFC3339 with either a `Z` or a numeric offset (`generated_at` carries
// `+09:00`), and both must land on the same instant.
func parseCloudTime(s string) (time.Time, bool) {
	if s == "" {
		return time.Time{}, false
	}
	t, err := time.Parse(time.RFC3339, s)
	if err != nil {
		return time.Time{}, false
	}
	return t.UTC(), true
}

// clampCursorToCloudClock bounds a pull cursor by the instant Cloud rendered
// the response at.
//
// WHY THIS EXISTS. The cursor only ever moved forward, to max(updated_at) of
// whatever Cloud returned, with no upper bound. One row carrying a timestamp in
// the future (demo seed data spread across a whole day, a backfill, a skewed
// writer) pushed the cursor past every real order, and the workstation went
// blind to new orders — silently, because a cursor above the newest row simply
// returns nothing to advance on. Observed in production: the cursor sat ~11h
// ahead and no order reached the shop for hours, so nothing printed at all.
//
// WHY CLOUD'S CLOCK, NOT time.Now(). The obvious bound is the local clock, and
// it is wrong. The workstation runs on a shop PC whose clock nobody audits; if
// it runs fast the clamp is a no-op and the bug survives while the tests go
// green, and if it runs slow the cursor is pinned in the past forever. Cloud
// already stamps `generated_at` on the very response the timestamps came from —
// the same clock that wrote them. Comparing like with like is the only version
// of this that cannot be defeated by a bad local clock.
//
// A Cloud too old to send `generated_at` yields ok=false and no clamp: degrading
// to today's behaviour beats refusing to sync, and it keeps this deployable
// without ordering it against a backend release.
//
// WHY THE MARGIN. `generated_at` is stamped AFTER the rows are read and
// serialised, so it is strictly later than the snapshot they came from. Landing
// the cursor exactly on it would skip anything updated while the response was
// being built — and since the feed is `>=` from the cursor, a skipped row is
// gone for good. The margin trades a little re-reading (upserts are idempotent)
// for never dropping an order. It only has to beat the render time of one page,
// so it is generous on purpose: this bound exists to stop the cursor reaching
// the FUTURE, and it does not need to be tight to do that.
func clampCursorToCloudClock(maxUpdated time.Time, generatedAt string) time.Time {
	gen, ok := parseCloudTime(generatedAt)
	if !ok {
		return maxUpdated
	}
	bound := gen.Add(-cursorClockSafetyMargin)
	if maxUpdated.After(bound) {
		return bound
	}
	return maxUpdated
}

// maxCursorString returns the later of two Cloud timestamps, comparing them as
// INSTANTS rather than as text.
//
// The string-typed cursors folded their maximum with `>`, which compares bytes.
// That holds only while every value shares one serialisation, and Cloud emits
// both `…Z` and `…+00:00` for the same moment — where `'+' (0x2B)` sorts BELOW
// `'Z' (0x5A)`. One format change would make every row in the cursor's own
// second compare as older, the maximum would stop moving, and the feed would go
// quiet with nothing to show for it. Comparing instants removes the trap rather
// than documenting it.
//
// Unparseable input is treated as "no information" and never wins, so a garbage
// timestamp cannot drag a cursor anywhere.
func maxCursorString(a, b string) string {
	ta, oka := parseCloudTime(a)
	tb, okb := parseCloudTime(b)
	switch {
	case !oka && !okb:
		return a
	case !oka:
		return b
	case !okb:
		return a
	case tb.After(ta):
		return b
	default:
		return a
	}
}

// boundedCursorString is clampCursorToCloudClock for the string-typed cursors,
// rendered in one canonical form (RFC3339 UTC) so a stored cursor never mixes
// serialisations with itself. Cloud parses either form on the way back in.
//
// Returns the input untouched when either side is unparseable — including a
// Cloud too old to send `generated_at`, which then keeps its previous behaviour
// rather than losing its cursor.
func boundedCursorString(maxUpdated, generatedAt string) string {
	mu, ok := parseCloudTime(maxUpdated)
	if !ok {
		return maxUpdated
	}
	bounded := clampCursorToCloudClock(mu, generatedAt)
	if bounded.Equal(mu) {
		return maxUpdated
	}
	return bounded.Format(time.RFC3339)
}

// healCursorAhead pulls a cursor that already sits in Cloud's future back to the
// present, and reports the cursor to use for this tick.
//
// This is the repair half of clampCursorToCloudClock: clamping on write only
// protects installs that have not been poisoned yet, and every workstation that
// already has a future cursor in SQLite would stay broken until wall-clock time
// caught up — hours, and hands-on DB surgery per machine to shortcut.
//
// It must run BEFORE the caller's "no rows, nothing to do" early return. A
// poisoned cursor is precisely the state that produces empty ticks, so healing
// after that return would never execute on the machines that need it. An empty
// response still carries `generated_at`, which is all this needs.
func (p *SyncPuller) healCursorAhead(key, cursor, generatedAt string) string {
	cur, ok := parseCloudTime(cursor)
	if !ok {
		return cursor // empty or unparseable — the caller already treats it as "from the beginning"
	}
	gen, ok := parseCloudTime(generatedAt)
	if !ok || !cur.After(gen) {
		return cursor
	}

	// Same margin as the write-side clamp, for the same reason: `generated_at`
	// trails the snapshot the rows were read from, so landing exactly on it
	// would skip the render window.
	healed := gen.Add(-cursorClockSafetyMargin).Format(time.RFC3339)
	slog.Warn("pull cursor was ahead of Cloud's clock — healing",
		"key", key, "was", cursor, "now", healed, "ahead_by", cur.Sub(gen).String())
	if err := p.setCursor(key, healed); err != nil {
		slog.Warn("heal cursor failed", "key", key, "err", err)
		return cursor
	}
	return healed
}

// autoPrintBulkMax is the max number of orders a single customer-orders pull
// tick may return before it's treated as a bulk re-seed / backfill and
// auto-print is suppressed for that tick (#141). A live tick returns ~1 order;
// a re-seed returns many. Reads settings['auto_print_bulk_max'], default 5 — a
// wide margin over normal per-tick volume, backed by the opened_at age gate for
// smaller seeds that stay under it.
func (p *SyncPuller) autoPrintBulkMax() int {
	const def = 5
	v := strings.TrimSpace(p.getCursor("auto_print_bulk_max"))
	if v == "" {
		return def
	}
	n, err := strconv.Atoi(v)
	if err != nil || n <= 0 {
		return def
	}
	return n
}

// setCursor persists the pull cursor for the given settings key using an
// UPSERT so it works whether or not the row already exists.
func (p *SyncPuller) setCursor(key, value string) error {
	_, err := p.db.Exec(`
		INSERT INTO settings (key, value) VALUES (?, ?)
		ON CONFLICT(key) DO UPDATE SET value = excluded.value
	`, key, value)
	return err
}

// ─── Entity pulls ─────────────────────────────────────────────────────────

func (p *SyncPuller) PullZones(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID   string `json:"id"`
			Name string `json:"name"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathZones, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		return nil
	}

	return p.atomic(func(tx *sql.Tx) error {
		if _, err := tx.Exec("DELETE FROM zones"); err != nil {
			return err
		}
		stmt, err := tx.Prepare(`INSERT INTO zones (id, name, sort_order, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for i, z := range resp.Data {
			if _, err := stmt.Exec(z.ID, z.Name, i); err != nil {
				return err
			}
		}
		return nil
	})
}

func (p *SyncPuller) PullTables(ctx context.Context) error {
	var resp struct {
		Data []struct {
			ID        string `json:"id"`
			Code      string `json:"code"`
			SeatCount int    `json:"seat_count"`
			Status    string `json:"status"`
			ZoneID    string `json:"zone_id"`
			QRToken   string `json:"qr_token"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathTables, &resp); err != nil {
		return err
	}
	if len(resp.Data) == 0 {
		return nil
	}

	return p.atomic(func(tx *sql.Tx) error {
		// #527 — snapshot tables the workstation put into `cleaning` locally
		// (a post-payment staff-workflow state Cloud's TMS mirror doesn't manage
		// authoritatively yet — the LAN close sets it before the payment/close
		// has reached Cloud). The destructive re-mirror below would otherwise
		// clobber a table mid-clean back to `free` the moment ANY other table's
		// payment triggers a pull, so preserve it when Cloud still reports free.
		wasCleaning := map[string]bool{}
		if rows, err := tx.Query("SELECT id FROM tables WHERE status = 'cleaning'"); err == nil {
			for rows.Next() {
				var id string
				if rows.Scan(&id) == nil {
					wasCleaning[id] = true
				}
			}
			rows.Close()
		}

		// Preserve the local status for tables with an UNSYNCED `table.status` op
		// (a pos-web LAN status change whose sync UP to Cloud hasn't applied yet).
		// The destructive re-mirror below would otherwise revert the cashier's
		// optimistic change on the very next tick. Once the op syncs (Cloud now
		// reports the same value) it drops out of this set and the two converge.
		// The `cleaning` preservation above is the legacy special case of this.
		pendingStatus := map[string]string{}
		if rows, err := tx.Query(`
			SELECT t.id, t.status
			FROM tables t
			JOIN sync_queue q ON q.entity_id = t.id
			WHERE q.entity_type = 'table' AND q.operation = 'status'
			  AND q.synced_at IS NULL AND q.dead_lettered_at IS NULL`); err == nil {
			for rows.Next() {
				var id, st string
				if rows.Scan(&id, &st) == nil && st != "" {
					pendingStatus[id] = st
				}
			}
			rows.Close()
		}

		if _, err := tx.Exec("DELETE FROM tables"); err != nil {
			return err
		}
		// Store table code in `name` column — local schema has no `code` column.
		stmt, err := tx.Prepare(`INSERT INTO tables (id, qr_token, name, zone_id, status, capacity, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, tb := range resp.Data {
			status := tb.Status
			if status == "" {
				// Match Cloud's enum default (see backend
				// 2000_01_01_000011_create_tables_table.php:
				// `string('status', 50)->default('free')`). Using
				// "available" here caused pos-web's TablePicker to
				// crash on `meta.label` of undefined because its
				// STATUS_STYLE map only knows free / occupied /
				// reserved / cleaning / out_of_service.
				status = "free"
			}
			// Keep a local `cleaning` when Cloud still reports the table free —
			// staff clear it when done (that transition syncs Cloud → `free`).
			// A genuine re-occupation (Cloud=`occupied`/`reserved`) still wins.
			if status == "free" && wasCleaning[tb.ID] {
				status = "cleaning"
			}
			// A pending LAN status change wins over Cloud's (soon-to-be-stale) value.
			if ps, ok := pendingStatus[tb.ID]; ok {
				status = ps
			}
			if _, err := stmt.Exec(tb.ID, tb.QRToken, tb.Code, tb.ZoneID, status, tb.SeatCount); err != nil {
				return err
			}
		}
		return nil
	})
}

// PullMenu flattens Cloud menu (categories→items) into the existing flat
// menu_items schema. UPSERT on cloud_id matches existing rows so the
// workstation-local printer_group column is preserved across syncs.
//
// Cloud-originated rows (cloud_id NOT NULL) are marked is_active=0 up-front
// then re-flagged active by the upsert for items currently in the menu; this
// makes items that Cloud removed disappear from the kiosk without touching
// local-only items (cloud_id IS NULL) added by workstation admin via Wails.
func (p *SyncPuller) PullMenu(ctx context.Context) error {
	var resp struct {
		Data *cloudMenuPayload `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathMenu, &resp); err != nil {
		return err
	}
	// data=null means the branch has no active menu — skip the upsert entirely
	// so locally-created items (cloud_id IS NULL) are preserved.
	if resp.Data == nil {
		return nil
	}

	// #1095/#1114 — persist the catalog gate BEFORE the item upsert: the
	// offline signer must never claim a revision newer than the menu it is
	// actually selling from, and an older claim is merely conservative.
	if resp.Data.CatalogRevision > 0 {
		if err := p.setCursor(catalogRevisionKey, strconv.Itoa(resp.Data.CatalogRevision)); err != nil {
			return err
		}
		hasToppings := "0"
		if resp.Data.CatalogRevisionHasToppings {
			hasToppings = "1"
		}
		if err := p.setCursor(catalogRevisionHasToppingsKey, hasToppings); err != nil {
			return err
		}
	}

	return p.atomic(func(tx *sql.Tx) error {
		// plan-043 (T3.3) — upsert the brand's tax types FIRST so a fresh pull
		// has them available when the local resolver reads the default. Absent
		// (old cloud) → skip, keep the existing mirror.
		if err := upsertTaxTypes(tx, resp.Data.TaxTypes); err != nil {
			return err
		}

		// Soft-deactivate kiosk-flat Cloud rows (sku_id IS NULL).
		// Rows upserted by PullHandyMenu carry a real product_sku_id in sku_id
		// and must NOT be touched here — they are managed by their own pull cycle.
		if _, err := tx.Exec("UPDATE menu_items SET is_active = 0 WHERE cloud_id IS NOT NULL AND sku_id IS NULL"); err != nil {
			return err
		}

		stmt, err := tx.Prepare(`
			INSERT INTO menu_items (
				id, cloud_id, sku_id, name, description,
				category, price, discount_price, discount_pct,
				image_url, is_active, sort_order, printer_group,
				tax_type_id, menu_product_sku_id,
				cloud_updated_at, local_updated_at
			)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'kitchen', ?, ?, datetime('now'), datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
				cloud_id       = excluded.cloud_id,
				sku_id         = excluded.sku_id,
				name           = excluded.name,
				description    = excluded.description,
				category       = excluded.category,
				price          = excluded.price,
				discount_price = excluded.discount_price,
				discount_pct   = excluded.discount_pct,
				image_url      = excluded.image_url,
				is_active      = excluded.is_active,
				sort_order     = excluded.sort_order,
				tax_type_id    = excluded.tax_type_id,
				menu_product_sku_id = excluded.menu_product_sku_id,
				cloud_updated_at  = excluded.cloud_updated_at,
				local_updated_at  = excluded.local_updated_at
				-- printer_group, name_ja preserved (workstation-local fields)
		`)
		if err != nil {
			return err
		}
		defer stmt.Close()

		order := 0
		for _, cat := range resp.Data.Categories {
			for _, it := range cat.Items {
				isActive := 0
				if it.Status == "available" {
					isActive = 1
				}
				image := ""
				if it.Image != nil {
					image = *it.Image
				}
				var discountPrice *int
				var discountPct *float64
				if it.ActivePromotion != nil {
					dp := int(it.ActivePromotion.DiscountedPrice.Float())
					discountPrice = &dp
					pct := it.ActivePromotion.DiscountPercent.Float()
					discountPct = &pct
				}
				if _, err := stmt.Exec(
					it.ID, it.ID, nullableString(it.SkuID),
					it.Name, nullableString(it.Description),
					cat.Name, int(it.Price), discountPrice, discountPct,
					nullableString(image), isActive, order,
					nullableString(it.TaxTypeID),
					nullableString(it.MenuProductSkuID),
				); err != nil {
					return err
				}
				order++
			}
		}

		// Persist menu-level scalars (cart timeout, deadline, menu identity).
		cartTimeout := resp.Data.CartTimeoutMinutes
		if _, err := tx.Exec(`
			INSERT INTO menu_meta (id, cloud_menu_id, cloud_menu_name, cart_timeout_minutes, cart_deadline_iso, synced_at)
			VALUES ('current', ?, ?, ?, ?, datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
				cloud_menu_id       = excluded.cloud_menu_id,
				cloud_menu_name     = excluded.cloud_menu_name,
				cart_timeout_minutes = excluded.cart_timeout_minutes,
				cart_deadline_iso   = excluded.cart_deadline_iso,
				synced_at           = excluded.synced_at
		`, nullableString(resp.Data.MenuID), nullableString(resp.Data.MenuName),
			cartTimeout, nullableString(resp.Data.CartDeadlineISO),
		); err != nil {
			return err
		}

		return nil
	})
}

// cloudMenuPayload is the JSON shape returned by GET /api/v1/workstation/menu.
type cloudMenuPayload struct {
	MenuID             string              `json:"menu_id"`
	MenuName           string              `json:"menu_name"`
	CartTimeoutMinutes int                 `json:"cart_timeout_minutes"`
	CartDeadlineISO    string              `json:"cart_deadline_iso"`
	Categories         []cloudMenuCategory `json:"categories"`
	// #1095/#1114 — the catalog version this menu snapshot represents, and
	// whether that revision carries topping prices. The offline signer stamps
	// the revision onto every order it signs; the toppings flag gates whether
	// a topping-bearing order may be signed at all. Absent (old Cloud) →
	// zero-values → the device signs nothing new, exactly the old behaviour.
	CatalogRevision            int  `json:"catalog_revision"`
	CatalogRevisionHasToppings bool `json:"catalog_revision_has_toppings"`
	// plan-043 (T3.3) — the brand's tax types, additive top-level array. An
	// OLD cloud omits this → nil → no upsert, the workstation keeps whatever
	// it already mirrored (or nothing → legacy fallback pricing). No panic.
	TaxTypes []cloudTaxType `json:"tax_types"`
}

// cloudTaxType mirrors one entry of the workstation menu's tax_types[] array.
//
// #1099 single-rate: Cloud sends `rate`, always — `MenuController` phát
// `'rate' => (float) $t->rate` không điều kiện. Shim `rate_takeaway` (fallback
// cho một Cloud chưa deploy) ĐÃ GỠ ở #1128 sau khi chủ sản phẩm xác nhận mọi
// môi trường Cloud phục vụ hạm đội đã lên #1099.
type cloudTaxType struct {
	ID   string `json:"id"`
	Code string `json:"code"`
	Name string `json:"name"`
	// Rate STAYS a pointer, and that is the whole point of #1128 — see
	// effectiveRate below.
	Rate      *flexFloat `json:"rate"`
	IsDefault bool       `json:"is_default"`
	IsActive  bool       `json:"is_active"`
}

// effectiveRate returns the single tax rate, or an error when Cloud sent none.
//
// ── Vì sao NÉM chứ không trả 0, và vì sao `Rate` vẫn là con trỏ (#1128) ──
//
// Shim `rate_takeaway` bị gỡ ở #1128 vì Cloud không còn phát trường đó
// (`MenuController` luôn gửi `'rate' => (float) $t->rate`) và hạm đội đã lên
// #1099 hết.
//
// Issue gốc còn đề nghị bỏ luôn con trỏ. KHÔNG làm, và đây là lý do: **0 là
// một thuế suất HỢP LỆ** — 非課税 là một trong ba loại của plan-043 (標準 10 ·
// 軽減 8 · 非課税 0). Bỏ con trỏ thì "Cloud không gửi rate" và "loại thuế 0%"
// decode ra cùng một giá trị, nên một payload thiếu trường sẽ đóng dấu 0% lên
// mọi dòng và **trông hoàn toàn hợp lệ** trên phiếu.
//
// Đó đúng là lỗi mà shim cũ sinh ra để chặn, chỉ đổi hình dạng. Con trỏ là thứ
// duy nhất phân biệt được "vắng mặt" với "bằng không", nên nó ở lại — nhưng
// nhánh nil giờ HỎNG ỒN ÀO thay vì im lặng lấy giá trị khác.
//
// Hỏng ở đây làm cả lượt upsert thất bại, nên bảng `tax_types` giữ nguyên bản
// đúng lần trước thay vì bị ghi đè bằng 0%. Giữ-bản-cũ tốt hơn ghi-số-sai trên
// đường thuế.
func (t cloudTaxType) effectiveRate() (float64, error) {
	if t.Rate == nil {
		return 0, fmt.Errorf("tax type %q (%s): cloud sent no `rate` — refusing to assume 0%%", t.ID, t.Code)
	}

	return t.Rate.Float(), nil
}

type cloudMenuCategory struct {
	ID    string          `json:"id"`
	Name  string          `json:"name"`
	Items []cloudMenuItem `json:"items"`
}

type cloudMenuItem struct {
	ID              string              `json:"id"`
	SkuID           string              `json:"sku_id"`
	Name            string              `json:"name"`
	Description     string              `json:"description"`
	Price           float64             `json:"price"`
	Image           *string             `json:"image"`
	Status          string              `json:"status"`
	ActivePromotion *cloudMenuPromotion `json:"active_promotion"`
	// plan-043 (T3.3) — per-item tax resolution input. TaxTypeID is the
	// resolved MenuProduct→Product override ("" / absent = inherit branch/brand
	// default locally). Additive: an old cloud omits it → legacy fallback.
	TaxTypeID string `json:"tax_type_id"`
	// #514/#1114 — the EXACT menu line whose price is shown. The offline
	// signer anchors each signed selection line to this id; Cloud's verifier
	// prices by it. Absent (old Cloud) → the line cannot be signed.
	MenuProductSkuID string `json:"menu_product_sku_id"`
}

// upsertTaxTypes mirrors the brand's tax types into the local `tax_types`
// table (plan-043 T3.3). Deactivation propagates: any type NOT in the incoming
// set is flagged is_active=0 (so a removed/deactivated cloud type stops being a
// candidate) without deleting it — historical order_item snapshots reference
// the id. Empty/absent input (old cloud) is a no-op so the mirror survives.
func upsertTaxTypes(tx *sql.Tx, types []cloudTaxType) error {
	if len(types) == 0 {
		return nil
	}
	// Soft-deactivate every mirrored type first; the upsert re-activates the
	// ones still present + is_active.
	if _, err := tx.Exec(`UPDATE tax_types SET is_active = 0`); err != nil {
		return err
	}
	stmt, err := tx.Prepare(`
		INSERT INTO tax_types (
			id, code, name, rate, is_default, is_active,
			cloud_updated_at, local_synced_at
		) VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
		ON CONFLICT(id) DO UPDATE SET
			code          = excluded.code,
			name          = excluded.name,
			rate          = excluded.rate,
			is_default    = excluded.is_default,
			is_active     = excluded.is_active,
			cloud_updated_at = excluded.cloud_updated_at,
			local_synced_at  = excluded.local_synced_at
	`)
	if err != nil {
		return err
	}
	defer stmt.Close()
	for _, t := range types {
		rate, err := t.effectiveRate()
		if err != nil {
			return err
		}

		if _, err := stmt.Exec(
			t.ID, t.Code, t.Name,
			rate,
			boolToInt(t.IsDefault), boolToInt(t.IsActive),
		); err != nil {
			return err
		}
	}
	return nil
}

// cloudMenuPromotion mirrors the active_promotion block returned by both
// GET /api/v1/workstation/menu (CustomerMenuService) and
// GET /api/v1/workstation/menu/handy (MenuProductResource overlay).
// Fields align with handy's ActivePromotionBlock type in types/pos.ts.
type cloudMenuPromotion struct {
	ID              string    `json:"id"`
	Name            string    `json:"name"`
	DiscountPercent flexFloat `json:"discount_percent"`
	DiscountedPrice flexFloat `json:"discounted_price"`
	EndsAt          string    `json:"ends_at"`
	StackingMode    string    `json:"stacking_mode"`
}

// handyMenuEntry is a minimal parse of each menu_product inside a
// ShopMenuByDayResource returned by GET /api/v1/workstation/menu/handy.
// Only fields needed to upsert menu_items rows are decoded; the full payload
// is also stored verbatim in handy_menu_cache for the LAN handy handler.
type handyMenuEntry struct {
	MenuProductID   string              `json:"id"`
	ActivePromotion *cloudMenuPromotion `json:"active_promotion"`
	Section         *struct {
		Name string `json:"name"`
	} `json:"section"`
	Product *struct {
		Name string `json:"name"`
	} `json:"product"`
	Skus []struct {
		ID           string    `json:"id"`             // menu_product_sku UUID
		ProductSkuID string    `json:"product_sku_id"` // canonical Cloud SKU UUID
		SellingPrice flexFloat `json:"selling_price"`
		IsActive     bool      `json:"is_active"`
		ProductSku   *struct {
			Name string `json:"name"`
		} `json:"product_sku"`
	} `json:"skus"`
}

// PullHandyMenu fetches the full handy-shaped menu from Cloud once per calendar
// day and stores the raw JSON payload in handy_menu_cache. Also upserts
// menu_items from the parsed SKUs so createItem() can resolve product_sku_id →
// name/price/printer_group without the old kiosk-flat PullMenu data.
//
// day_of_week rollover detection: if the cached day differs from today's
// weekday a fresh pull is performed so the menu rotates at midnight without a
// cron job.
func (p *SyncPuller) PullHandyMenu(ctx context.Context) error {
	// Resolve branch timezone from shop_settings so rollover matches the
	// restaurant's local midnight, not the workstation machine's timezone.
	var tzName string
	_ = p.db.QueryRow(`SELECT value FROM shop_settings WHERE key = 'timezone'`).Scan(&tzName)
	loc := time.UTC
	if tzName != "" {
		if l, err := time.LoadLocation(tzName); err == nil {
			loc = l
		}
	}
	nowDOW := int(time.Now().In(loc).Weekday()) // 0=Sun … 6=Sat

	var wrapper struct {
		Data json.RawMessage `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathMenuHandy, &wrapper); err != nil {
		return err
	}
	if wrapper.Data == nil {
		return nil
	}

	// Parse menus to extract per-SKU rows for menu_items upsert.
	type menuShape struct {
		MenuProducts []handyMenuEntry `json:"menu_products"`
	}
	var menus []menuShape
	if err := json.Unmarshal(wrapper.Data, &menus); err != nil {
		return fmt.Errorf("handy menu parse: %w", err)
	}

	return p.atomic(func(tx *sql.Tx) error {
		// 1. Persist raw JSON cache.
		if _, err := tx.Exec(`
			INSERT INTO handy_menu_cache (id, day_of_week, payload, fetched_at)
			VALUES ('current', ?, ?, datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
				day_of_week = excluded.day_of_week,
				payload     = excluded.payload,
				fetched_at  = excluded.fetched_at
		`, nowDOW, string(wrapper.Data)); err != nil {
			return err
		}

		// 2. Soft-deactivate handy SKU rows only (sku_id IS NOT NULL).
		// Kiosk-flat rows (sku_id IS NULL) are managed by PullMenu, not here.
		if _, err := tx.Exec(`UPDATE menu_items SET is_active = 0 WHERE cloud_id IS NOT NULL AND sku_id IS NOT NULL`); err != nil {
			return err
		}

		stmt, err := tx.Prepare(`
			INSERT INTO menu_items (
				id, cloud_id, sku_id, name, category,
				price, discount_price, discount_pct,
				is_active, sort_order, printer_group,
				cloud_updated_at, local_updated_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'kitchen', datetime('now'), datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
				sku_id           = excluded.sku_id,
				name             = excluded.name,
				category         = excluded.category,
				price            = excluded.price,
				discount_price   = excluded.discount_price,
				discount_pct     = excluded.discount_pct,
				is_active        = excluded.is_active,
				sort_order       = excluded.sort_order,
				cloud_updated_at = excluded.cloud_updated_at,
				local_updated_at = excluded.local_updated_at
				-- printer_group, name_ja preserved (workstation-local fields)
		`)
		if err != nil {
			return err
		}
		defer stmt.Close()

		order := 0
		seen := make(map[string]bool) // deduplicate across menus
		for _, menu := range menus {
			for _, mp := range menu.MenuProducts {
				productName := ""
				if mp.Product != nil {
					productName = mp.Product.Name
				}
				category := ""
				if mp.Section != nil {
					category = mp.Section.Name
				}

				// Resolve promotion from the menu_product level (applies to all SKUs).
				var discountPrice *int
				var discountPct *float64
				if mp.ActivePromotion != nil {
					dp := int(mp.ActivePromotion.DiscountedPrice.Float())
					discountPrice = &dp
					pct := mp.ActivePromotion.DiscountPercent.Float()
					discountPct = &pct
				}

				for _, sku := range mp.Skus {
					if !sku.IsActive || sku.ProductSkuID == "" {
						continue
					}
					// Use menu_product_sku UUID as local id so it's stable and
					// unique across SKUs of the same product.
					localID := sku.ID
					if seen[localID] {
						continue
					}
					seen[localID] = true

					skuName := ""
					if sku.ProductSku != nil && sku.ProductSku.Name != "" {
						skuName = sku.ProductSku.Name
					}
					displayName := productName
					if skuName != "" {
						displayName = productName + " · " + skuName
					}

					// When there are multiple SKUs and a promotion is set, the
					// discounted_price from the menu_product level is the default-SKU
					// price. For non-default SKUs we recompute from the discount_pct
					// so each SKU gets its own accurate discounted price.
					skuDiscountPrice := discountPrice
					sellingPrice := sku.SellingPrice.Float()
					if discountPct != nil && len(mp.Skus) > 1 {
						dp := int(sellingPrice * (100 - *discountPct) / 100)
						skuDiscountPrice = &dp
					}

					price := int(sellingPrice)
					if _, err := stmt.Exec(
						localID, localID, sku.ProductSkuID,
						displayName, category,
						price, skuDiscountPrice, discountPct,
						1, order,
					); err != nil {
						return err
					}
					order++
				}
			}
		}
		return nil
	})
}

// ─── Custom JSON unmarshal for Order / Item ───────────────────────────────────
//
// Cloud serialises decimal(15,2) columns as JSON strings ("50000.00").
// Our local struct stores int (yen). The alias trick breaks infinite recursion.

func (o *Order) UnmarshalJSON(data []byte) error {
	type Alias Order
	aux := &struct {
		Subtotal       json.Number `json:"subtotal"`
		DiscountAmount json.Number `json:"discount_amount"`
		ServiceCharge  json.Number `json:"service_charge"`
		TaxAmount      json.Number `json:"tax_amount"`
		TotalTip       json.Number `json:"total_tip"`
		TotalAmount    json.Number `json:"total_amount"`
		PaidAmount     json.Number `json:"paid_amount"`
		*Alias
	}{Alias: (*Alias)(o)}

	if err := json.Unmarshal(data, aux); err != nil {
		return err
	}
	o.Subtotal = decimalToInt(aux.Subtotal)
	o.DiscountAmount = decimalToInt(aux.DiscountAmount)
	o.ServiceCharge = decimalToInt(aux.ServiceCharge)
	o.TaxAmount = decimalToFloat(aux.TaxAmount)
	o.TotalTip = decimalToInt(aux.TotalTip)
	o.TotalAmount = decimalToInt(aux.TotalAmount)
	o.PaidAmount = decimalToInt(aux.PaidAmount)
	return nil
}

func (it *Item) UnmarshalJSON(data []byte) error {
	type Alias Item
	aux := &struct {
		Quantity  json.Number `json:"quantity"`
		UnitPrice json.Number `json:"unit_price"`
		Subtotal  json.Number `json:"subtotal"`
		// plan-043 — Cloud serialises tax_rate decimal(5,2) + tax_amount
		// decimal(15,2) as JSON strings; override so they don't fail the int /
		// *float64 decode on the aliased struct. TaxTypeID (string) +
		// decode fine via the alias.
		TaxRate   *json.Number `json:"tax_rate"`
		TaxAmount json.Number  `json:"tax_amount"`
		*Alias
	}{Alias: (*Alias)(it)}

	if err := json.Unmarshal(data, aux); err != nil {
		return err
	}
	it.Quantity = decimalToInt(aux.Quantity)
	it.UnitPrice = decimalToInt(aux.UnitPrice)
	it.Subtotal = decimalToInt(aux.Subtotal)
	it.TaxAmount = decimalToFloat(aux.TaxAmount)
	if aux.TaxRate != nil && *aux.TaxRate != "" {
		if f, err := aux.TaxRate.Float64(); err == nil {
			it.TaxRate = &f
		}
	}
	return nil
}

// nullableTime converts a *time.Time to nil or a formatted RFC3339 string,
// suitable for passing directly to a SQLite ? parameter.
func nullableTime(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.Format(time.RFC3339)
}

// Recover pulls historical orders from Cloud once after a fresh pair or
// re-pair. Idempotent — UPSERT by id so re-running is safe. Returns the
// number of orders restored. Call as fire-and-forget from handleDevicePair.
//
// `since` is the lookback window (eg. 30*24*time.Hour). Limit hardcoded at
// 500 to match Cloud's default; bump if pilot stores prove busier.
//
// Now that Order mirrors cloud schema exactly, we json.Unmarshal directly
// into []Order — no transformer layer needed. Custom UnmarshalJSON handles
// Cloud's decimal string encoding ("50000.00" → int).
// #3196 — kích thước trang và TRẦN của lượt khôi phục.
//
// 500 khớp mặc định của Cloud. Trần 20 trang = 10 000 đơn: xa hơn mọi quán
// hiện có (đo 2026-08-18: quán bận nhất 421 đơn/30 ngày) nhưng vẫn là một con
// số HỮU HẠN — nó giữ cho một Cloud trả `has_more` mãi mãi tốn một số request
// có hạn, thay vì quay vòng tới khi máy bỏ cuộc.
//
// Trần này KHÔNG phải giới hạn hiển thị: chạm nó thì có alert, vì một lượt
// khôi phục thiếu mà im lặng chính là lỗi #3196.
const (
	recoverPageSize = 500
	recoverMaxPages = 20
)

func (p *SyncPuller) Recover(ctx context.Context, since time.Duration) (int, error) {
	sinceTS := time.Now().Add(-since).UTC().Format(time.RFC3339)

	// #3196 — TRƯỚC ĐÂY: đúng một request `limit=500` rồi dừng. Đường này chạy
	// sau khi pair lại hoặc crash, tức đúng lúc máy không còn state cục bộ và
	// phụ thuộc hoàn toàn vào lượt kéo này — nên một lượt khôi phục THIẾU đọc
	// lên y hệt một lượt khôi phục ĐỦ, vì hàm chỉ trả về số dòng nó nhận được.
	//
	// Đo 2026-08-18 trên production: 本郷 391 đơn / 30 ngày, 人形町 421 — tức
	// 84% của trần, và `tổng = 30 ngày` ở cả hai quán nên tốc độ ~400 đơn/tháng.
	// Chạm 500 trong khoảng một tuần. Đây là bản vá TRƯỚC khi nổ, không phải sau.
	//
	// Cloud không đi trang được nếu chỉ có `since`: nhánh đó sắp `created_at`
	// DESC và `since` là cận DƯỚI, nên không có đường với tới phần cũ hơn. Vì
	// thế bản vá này đi kèm `offset` + `has_more` mới ở phía Cloud — deploy
	// backend TRƯỚC workstation, đúng luật cũ.
	var all []Order
	seen := map[string]bool{}
	offset := 0
	truncated := false
	ignoredOffset := false

	for page := 0; ; page++ {
		if page >= recoverMaxPages {
			// Cắt có trần mà im lặng chính là lỗi hàm này đang chữa, chỉ lùi xa
			// hơn một bậc. 20 trang = 10 000 đơn, vượt xa mọi quán hiện có.
			truncated = true

			break
		}

		path := fmt.Sprintf("/api/v1/workstation/orders?limit=%d&offset=%d&since=%s",
			recoverPageSize, offset, sinceTS)

		var resp struct {
			Data    []Order `json:"data"`
			Count   int     `json:"count"`
			HasMore bool    `json:"has_more"`
		}
		if err := p.cloudGet(ctx, path, &resp); err != nil {
			return 0, err
		}

		// Khử trùng theo id. KHÔNG phải phòng xa: nếu đầu kia LỜ `offset` —
		// proxy nuốt query param, validate() strip vì thiếu rule (#2622), hay
		// Cloud chưa cài bản này — thì mọi trang trả về CÙNG một tập dòng.
		// Không khử trùng thì `all` phình lên theo số trang và hàm trả về một
		// con số ĐỌC NHƯ THÀNH CÔNG: đo được n=40 trong khi SQLite có 2 đơn.
		fresh := 0
		for _, o := range resp.Data {
			if seen[o.ID] {
				continue
			}
			seen[o.ID] = true
			all = append(all, o)
			fresh++
		}

		// `has_more` vắng mặt (Cloud cũ) ⇒ false ⇒ dừng sau trang đầu, đúng
		// hành vi cũ. Bản vá này không đòi hai đầu deploy cùng lúc.
		if !resp.HasMore || len(resp.Data) == 0 {
			break
		}

		// Trang trả về đầy nhưng KHÔNG thêm được dòng nào ⇒ đầu kia không tôn
		// trọng `offset`. Đi tiếp không thể thêm gì, chỉ tốn thêm 19 lượt tải
		// cùng một payload — và quan trọng hơn, việc dừng ở đây biến một lượt
		// khôi phục sai thành một lượt khôi phục ĐÚNG (đủ những gì trang đầu
		// có) thay vì một con số bịa.
		if fresh == 0 {
			ignoredOffset = true

			break
		}
		offset += recoverPageSize
	}

	if ignoredOffset {
		slog.Error("recover: đầu kia không tôn trọng `offset` — khôi phục chỉ có trang đầu",
			"restored", len(all), "page_size", recoverPageSize)
		p.alerts.Raise(KindSyncStalled, "customer_orders",
			"Khôi phục đơn hàng: Cloud không phân trang — có thể thiếu dữ liệu",
			map[string]any{"restored": len(all)})
	}

	if truncated {
		slog.Error("recover: chạm trần số trang — khôi phục có thể THIẾU đơn",
			"pages", recoverMaxPages, "page_size", recoverPageSize, "got", len(all))
		p.alerts.Raise(KindSyncStalled, "customer_orders",
			"Khôi phục đơn hàng chạm trần — có thể thiếu dữ liệu",
			map[string]any{"pages": recoverMaxPages, "restored": len(all)})
	}

	resp := struct {
		Data []Order
	}{Data: all}

	restored := 0
	err := p.atomic(func(tx *sql.Tx) error {
		orderStmt, err := tx.Prepare(`
			INSERT INTO orders (
				id, cloud_id, order_code, order_type, status,
				opened_at, checkout_at, closed_at, voided_at, void_reason,
				table_id, guest_count, customer_takeaway_name, customer_takeaway_phone, note,
				subtotal, discount_amount, service_charge, tax_amount,
				total_tip, total_amount, paid_amount,
				is_tax_included,
				tax_rounding_mode, tax_rounding_decimals,
				organization_id, brand_id, branch_id,
				created_at, updated_at, synced_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
				status      = excluded.status,
				closed_at   = excluded.closed_at,
				voided_at   = excluded.voided_at,
				paid_amount = excluded.paid_amount,
				total_amount = excluded.total_amount,
				is_tax_included = COALESCE(excluded.is_tax_included, orders.is_tax_included),
				tax_rounding_mode     = COALESCE(excluded.tax_rounding_mode, orders.tax_rounding_mode),
				tax_rounding_decimals = COALESCE(excluded.tax_rounding_decimals, orders.tax_rounding_decimals),
				updated_at  = excluded.updated_at,
				synced_at   = datetime('now')
		`)
		if err != nil {
			return err
		}
		defer orderStmt.Close()

		itemStmt, err := tx.Prepare(`
			INSERT INTO order_items (
				id, customer_order_id, product_sku_id, menu_item_name,
				quantity, unit_price, subtotal, note, status,
				served_at, voided_at,
				tax_type_id, tax_rate, tax_amount,
				refund_of_item_id, refunded_quantity,
				created_at, updated_at
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON CONFLICT(id) DO UPDATE SET
				status     = excluded.status,
				served_at  = excluded.served_at,
				voided_at  = excluded.voided_at,
				tax_type_id           = excluded.tax_type_id,
				tax_rate              = excluded.tax_rate,
				tax_amount            = excluded.tax_amount,
				refund_of_item_id     = COALESCE(excluded.refund_of_item_id, order_items.refund_of_item_id),
				refunded_quantity     = COALESCE(excluded.refunded_quantity, order_items.refunded_quantity),
				updated_at = excluded.updated_at
		`)
		if err != nil {
			return err
		}
		defer itemStmt.Close()

		for _, o := range resp.Data {
			// plan-041 — skip orders this workstation created locally. Such a
			// row keeps its LOCAL uuid as primary key (cloud_id set on sync UP);
			// recovery keys rows by the cloud id, so without this guard it would
			// INSERT a second row and duplicate the order. Same guard as the
			// periodic pull's upsertOrder.
			var localDup int
			_ = tx.QueryRow(
				"SELECT 1 FROM orders WHERE cloud_id = ? AND id <> ? LIMIT 1",
				o.ID, o.ID,
			).Scan(&localDup)
			if localDup == 1 {
				continue
			}

			// Recovery rows reuse the Cloud UUID as local id so any future
			// push attempts via sync_queue land on the same Cloud row (no
			// duplicates). synced_at = datetime('now') marks them as
			// "already in sync" so SyncEngine doesn't push them again.
			openedAt := o.OpenedAt.Format(time.RFC3339)
			if o.OpenedAt.IsZero() {
				openedAt = o.CreatedAt.Format(time.RFC3339)
			}
			if _, err := orderStmt.Exec(
				o.ID, o.ID, o.OrderCode, o.OrderType, string(o.Status),
				openedAt,
				nullableTime(o.CheckoutAt), nullableTime(o.ClosedAt),
				nullableTime(o.VoidedAt), nullableString(o.VoidReason),
				nullableString(o.TableID), o.GuestCount,
				nullableString(o.CustomerTakeawayName), nullableString(o.CustomerTakeawayPhone),
				nullableString(o.Note),
				o.Subtotal, o.DiscountAmount, o.ServiceCharge, o.TaxAmount,
				o.TotalTip, o.TotalAmount, o.PaidAmount,
				boolToInt(o.IsTaxIncluded),
				nullableString(o.TaxRoundingMode), intPtrToNullable(o.TaxRoundingDecimals),
				o.OrganizationID, o.BrandID, o.BranchID,
				o.CreatedAt.Format(time.RFC3339), o.UpdatedAt.Format(time.RFC3339),
			); err != nil {
				return err
			}
			restored++

			for _, it := range o.Items {
				if _, err := itemStmt.Exec(
					it.ID, o.ID, nullableString(it.ProductSkuID),
					p.resolveMenuItemName(it.ProductSkuID, it.MenuItemName, nil), it.Quantity, it.UnitPrice, it.Subtotal,
					nullableString(it.Note), string(it.Status),
					nullableTime(it.ServedAt), nullableTime(it.VoidedAt),
					nullableString(it.TaxTypeID), floatPtrToNullable(it.TaxRate),
					it.TaxAmount,
					nullableString(it.RefundOfItemID), it.RefundedQuantity,
					it.CreatedAt.Format(time.RFC3339), it.UpdatedAt.Format(time.RFC3339),
				); err != nil {
					return err
				}
			}
		}
		return nil
	})
	return restored, err
}

// PullLots mirrors Cloud's /api/v1/workstation/lots into local inventory_lots.
// Read-only mirror — Cloud owns stock movements. UPSERT by lot id so quantity
// updates flow but local-only rows (if ever) stay. Pulled every 5 min as part
// of pullSlow, since stock doesn't change minute-by-minute.
func (p *SyncPuller) PullLots(ctx context.Context) error {
	var resp struct {
		Lots []struct {
			ID            string    `json:"id"`
			MaterialID    string    `json:"material_id"`
			MaterialName  string    `json:"material_name"`
			WarehouseID   string    `json:"warehouse_id"`
			WarehouseName string    `json:"warehouse_name"`
			Quantity      flexFloat `json:"quantity"`
			Unit          string    `json:"unit"`
			ExpiresAt     string    `json:"expires_at"`
			Status        string    `json:"status"`
			UpdatedAt     string    `json:"updated_at"`
		} `json:"lots"`
	}
	if err := p.cloudGet(ctx, pullPathLots, &resp); err != nil {
		return err
	}
	if len(resp.Lots) == 0 {
		return nil
	}

	// #3200 — trần 200 của Cloud là quyết định SẢN PHẨM, không phải thiếu sót:
	// `LotController` sắp theo `expiry_date` rồi `->limit(200)`, tức "200 lô sắp
	// hết hạn nhất" là đúng thứ quầy cần thấy.
	//
	// Nhưng cắt IM LẶNG thì không: quán quá 200 lô sẽ thấy tập hiển thị đổi theo
	// hạn dùng — lô lúc có lúc không — và không gì trên màn hình nói rằng đây là
	// một lát cắt. Đo 2026-08-18: production có 0 lô, nên đây là ghi nhận sớm.
	//
	// Không đi hết trang ở đây, có chủ đích: feed này KHÔNG có con trỏ, và thêm
	// một cái chỉ để kéo về phần đuôi mà quầy không cần là đổi hợp đồng sản phẩm
	// bằng một bản vá kỹ thuật. Việc đúng là NÓI RA và để người quyết.
	if len(resp.Lots) >= lotsCloudLimit {
		slog.Warn("lots: chạm trần Cloud — danh sách là LÁT CẮT theo hạn dùng, không phải toàn bộ",
			"limit", lotsCloudLimit)
	}

	return p.atomic(func(tx *sql.Tx) error {
		stmt, err := tx.Prepare(`
			INSERT INTO inventory_lots
				(id, material_id, material_name, warehouse_id, warehouse_name,
				 quantity, unit, expires_at, status, cloud_updated_at, local_synced_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
			ON CONFLICT(id) DO UPDATE SET
				material_name    = excluded.material_name,
				warehouse_id     = excluded.warehouse_id,
				warehouse_name   = excluded.warehouse_name,
				quantity         = excluded.quantity,
				unit             = excluded.unit,
				expires_at       = excluded.expires_at,
				status           = excluded.status,
				cloud_updated_at = excluded.cloud_updated_at,
				local_synced_at  = datetime('now')
		`)
		if err != nil {
			return err
		}
		defer stmt.Close()
		for _, lot := range resp.Lots {
			if _, err := stmt.Exec(
				lot.ID, lot.MaterialID,
				nullableString(lot.MaterialName),
				nullableString(lot.WarehouseID),
				nullableString(lot.WarehouseName),
				lot.Quantity.Float(),
				nullableString(lot.Unit),
				nullableString(lot.ExpiresAt),
				nullableString(lot.Status),
				nullableString(lot.UpdatedAt),
			); err != nil {
				return err
			}
		}
		return nil
	})
}

// PullBranch upserts the branch row (subset of columns that match local
// schema) and flattens the nested `data.settings.*` object plus a handful of
// operational branch-level fields (currency_code etc.) into the key-value
// shop_settings table.
func (p *SyncPuller) PullBranch(ctx context.Context) error {
	var resp struct {
		Data *struct {
			ID       string `json:"id"`
			Slug     string `json:"slug"`
			Name     string `json:"name"`
			Currency string `json:"currency"`
			Timezone string `json:"timezone"`
			Locale   string `json:"locale"`
			// #2000 bước 3 — Cloud GỬI hai trường này từ lâu (BranchController
			// select chúng), máy trạm chỉ chưa bao giờ giải mã. Không có chúng
			// thì hoá đơn in ra không địa chỉ, không điện thoại, dù dữ liệu đã
			// đi hết đường xuống đến đây.
			Address string `json:"address"`
			Phone   string `json:"phone"`
			// #2000 bước 4 — 法人名, tên PHÁP NHÂN. Khác brand (thương hiệu) và
			// khác chi nhánh. 登録番号 thuộc về pháp nhân, nên hoá đơn phải nói
			// đúng chủ thể của con số đó.
			OrganizationName   string         `json:"organization_name"`
			CartTimeoutMinutes *int           `json:"cart_timeout_minutes"`
			Settings           map[string]any `json:"settings"`
			ConsoleBranchID    string         `json:"console_branch_id"`
			ConsoleOrgID       string         `json:"console_organization_id"`
		} `json:"data"`
	}
	if err := p.cloudGet(ctx, pullPathBranch, &resp); err != nil {
		return err
	}
	if resp.Data == nil {
		return nil
	}
	br := resp.Data

	if err := p.atomic(func(tx *sql.Tx) error {
		consoleBranchID := br.ConsoleBranchID
		if consoleBranchID == "" {
			consoleBranchID = br.ID
		}
		consoleOrgID := br.ConsoleOrgID
		if consoleOrgID == "" {
			consoleOrgID = "unknown"
		}

		// Workstation owns exactly one branch. Delete-then-insert avoids
		// conflict between the PK (id) and the UNIQUE(console_branch_id)
		// constraint when Cloud returns a different id for the same branch.
		if _, err := tx.Exec(`DELETE FROM branches`); err != nil {
			return err
		}

		_, err := tx.Exec(`
			INSERT INTO branches (id, console_branch_id, console_organization_id, slug, name, currency, timezone, locale, is_active, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, datetime('now'))
		`, br.ID, consoleBranchID, consoleOrgID, br.Slug, br.Name, br.Currency, br.Timezone, br.Locale)
		if err != nil {
			return err
		}

		// Flatten settings → shop_settings key-value rows.
		//
		// BLOCKER-WS-TAX — STALE, and measured stale on 2026-08-07 (#2067). This
		// comment claimed Cloud "STILL selects + ships" a flat `tax_rate` under
		// `data.settings.tax_rate`. It does not, and cannot: plan-043 T6.2
		// DROPPED the `shop_order_settings.tax_rate` column, and the workstation
		// branch feed is an explicit allowlist
		// (`Workstation\BranchController::show`) that names `default_tax_type_id`
		// and `service_charge_tax_rate` but not `tax_rate`.
		//
		// So `shop_settings.tax_rate` is EMPTY on every register — and since
		// #2188 nothing reads it at all: the engine's fallback accessor was
		// DELETED, an unstamped line is dropped from the rate groups with a
		// warning, and the print layer stopped fabricating 10% from this same
		// empty value back in #2067 (see `stampedTaxRow`).
		//
		// Left flattening the generic settings map: if a future Cloud reinstates
		// a rate key it lands here without a code change. Nothing in the print
		// path may consume it (`TestPrintLayerDerivesNoTaxRate`).
		//
		// plan-045 — the generic flatten also carries `tax_rounding_mode` +
		// `tax_rounding_decimals` from data.settings.* into shop_settings when
		// Cloud ships them, so a new order stamps the shop's configured rounding
		// rule (see OrderEngine.taxRoundingModeSetting / taxRoundingDecimalsSetting).
		// A nil `tax_rounding_decimals` stringifies to "" → nil → currency step.
		settings := map[string]string{}
		for k, v := range br.Settings {
			settings[k] = stringifyValue(v)
		}
		if br.CartTimeoutMinutes != nil {
			settings["cart_timeout_minutes"] = strconv.Itoa(*br.CartTimeoutMinutes)
		}
		if br.Currency != "" {
			settings["currency"] = br.Currency
		}

		upsertStmt, err := tx.Prepare(`
			INSERT INTO shop_settings (key, value, cloud_updated_at, local_synced_at)
			VALUES (?, ?, datetime('now'), datetime('now'))
			ON CONFLICT(key) DO UPDATE SET
				value = excluded.value,
				cloud_updated_at = excluded.cloud_updated_at,
				local_synced_at = excluded.local_synced_at
		`)
		if err != nil {
			return err
		}
		defer upsertStmt.Close()
		for k, v := range settings {
			if _, err := upsertStmt.Exec(k, v); err != nil {
				return err
			}
		}
		return nil
	}); err != nil {
		return err
	}
	// Write branch identity into the settings table so settingValue() can read it.
	//
	// Ghi cả khi RỖNG, khác với tên: một chi nhánh xoá số điện thoại đi thì máy
	// trạm phải quên nó, chứ không giữ số cũ in mãi. Tên thì không có ca "xoá
	// tên quán" hợp lệ nên nó giữ nguyên luật cũ.
	if br.Name != "" {
		_ = p.setCursor("workstation_branch_name", br.Name)
	}
	_ = p.setCursor("workstation_branch_address", br.Address)
	_ = p.setCursor("workstation_branch_phone", br.Phone)
	_ = p.setCursor("workstation_organization_name", br.OrganizationName)

	return nil
}

// ─── HTTP + DB helpers ────────────────────────────────────────────────────

func (p *SyncPuller) cloudGet(ctx context.Context, path string, out any) error {
	token := ""
	if p.tokenFn != nil {
		token = p.tokenFn()
	}
	if token == "" {
		return nil // No token paired → silently skip (boot-time race)
	}

	baseURL := p.resolveURL()
	if baseURL == "" {
		return fmt.Errorf("cloud URL not configured")
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, baseURL+path, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)

	resp, err := p.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("cloud GET %s: %w", path, err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))

	if resp.StatusCode == http.StatusUnauthorized {
		if p.onUnauthorized != nil {
			p.onUnauthorized()
		}
		return fmt.Errorf("cloud 401 on %s: %s", path, string(body))
	}
	// Surface 429 specifically — the slow-loop tick rate × number of
	// per-tick endpoints can push the per-device throttle over its limit,
	// at which point every pull silently 429s and replica tables stay
	// empty. A dedicated error message + retry-after passthrough makes
	// this fault visible in logs instead of being lumped under "cloud
	// 429 on /path: ..." with no context.
	if resp.StatusCode == http.StatusTooManyRequests {
		retryAfter := resp.Header.Get("Retry-After")
		return fmt.Errorf("cloud 429 RATE_LIMITED on %s (Retry-After=%q) — raise workstation throttle middleware OR slow the puller interval", path, retryAfter)
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("cloud %d on %s: %s", resp.StatusCode, path, string(body))
	}

	return json.NewDecoder(bytes.NewReader(body)).Decode(out)
}

func (p *SyncPuller) atomic(fn func(tx *sql.Tx) error) error {
	return p.db.Transaction(fn)
}

// decimalToInt converts Cloud's stringy decimal ("10000.00") or numeric JSON
// into the int yen workstation uses everywhere. Empty/unparseable → 0.
func decimalToInt(n json.Number) int {
	if n == "" {
		return 0
	}
	f, err := n.Float64()
	if err != nil {
		return 0
	}
	return int(f)
}

// decimalToFloat parses a Cloud decimal (e.g. tax_amount "93.50") to float64,
// preserving sub-unit precision (option-B tax display). Empty/unparseable → 0.
func decimalToFloat(n json.Number) float64 {
	if n == "" {
		return 0
	}
	f, err := n.Float64()
	if err != nil {
		return 0
	}
	return f
}

// decimalPtrToNullable maps a *json.Number (Cloud tax_rate, absent-safe) to a
// SQL-friendly nullable REAL: NULL when nil/empty/unparseable so an unstamped
// line stays NULL (→ legacy fallback), an explicit "0" stays 0 (plan-043).
func decimalPtrToNullable(n *json.Number) any {
	if n == nil || *n == "" {
		return nil
	}
	f, err := n.Float64()
	if err != nil {
		return nil
	}
	return f
}

// CloudPaymentSummaryEntry is one settled payment as Cloud described it, stored
// on the local order header for receipt method names (#1282), POS history and
// revenue reporting (#2934). It must never become a row in `payments` (the till
// would treat online money as drawer cash) — see migrations 066 and 095.
type CloudPaymentSummaryEntry struct {
	ID                string `json:"id"`
	PaymentMethodID   string `json:"payment_method_id"`
	PaymentMethodCode string `json:"payment_method_code"`
	PaymentMethodName string `json:"payment_method_name"`
	Amount            int    `json:"amount"`
	// NetAmount is the amount Cloud still holds after signed refund rows. nil
	// means an older Cloud omitted the field; zero is an explicit full refund.
	NetAmount *int `json:"net_amount,omitempty"`
	// Refunds preserves signed reversal instants for period reports. nil means
	// an older Cloud omitted the field; [] means the new Cloud saw no reversal.
	Refunds []CloudPaymentSummaryRefund `json:"refunds"`
	// Status is what Cloud says became of this payment. Cloud has always sent
	// it and this struct used to drop it — which was harmless while the blob
	// only named a method, and stopped being harmless the moment anything read
	// an AMOUNT out of here: without a status there is no way to tell a
	// settled payment from a refunded one, and a receipt would announce money
	// that has already gone back to the customer. Kept as Cloud's raw string
	// (see PaymentSettled) rather than a bool, so a status this build has
	// never heard of reads as "not settled" instead of silently as "paid".
	Status string `json:"status"`
	PaidAt string `json:"paid_at,omitempty"`
}

type CloudPaymentSummaryRefund struct {
	ID     string `json:"id"`
	Amount int    `json:"amount"`
	Status string `json:"status"`
	PaidAt string `json:"paid_at,omitempty"`
}

func (r *CloudPaymentSummaryRefund) UnmarshalJSON(data []byte) error {
	aux := struct {
		ID     string          `json:"id"`
		Amount json.RawMessage `json:"amount"`
		Status string          `json:"status"`
		PaidAt string          `json:"paid_at"`
	}{}
	if err := json.Unmarshal(data, &aux); err != nil {
		return err
	}
	r.ID = aux.ID
	r.Amount = parseSummaryAmount(aux.Amount)
	r.Status = aux.Status
	r.PaidAt = aux.PaidAt
	return nil
}

// UnmarshalJSON accepts amount as int, float, or decimal string ("297.00").
// Older / hand-written blobs and Cloud's string money format must not make
// json.Unmarshal reject the whole payment_summary array — that used to wipe
// POS history payments[] to empty ("Chưa có thanh toán").
func (e *CloudPaymentSummaryEntry) UnmarshalJSON(data []byte) error {
	aux := struct {
		ID                string          `json:"id"`
		PaymentMethodID   string          `json:"payment_method_id"`
		PaymentMethodCode string          `json:"payment_method_code"`
		PaymentMethodName string          `json:"payment_method_name"`
		Amount            json.RawMessage `json:"amount"`
		NetAmount         json.RawMessage `json:"net_amount"`
		Refunds           json.RawMessage `json:"refunds"`
		Status            string          `json:"status"`
		PaidAt            string          `json:"paid_at"`
	}{}
	if err := json.Unmarshal(data, &aux); err != nil {
		return err
	}
	e.ID = aux.ID
	e.PaymentMethodID = aux.PaymentMethodID
	e.PaymentMethodCode = aux.PaymentMethodCode
	e.PaymentMethodName = aux.PaymentMethodName
	e.Status = aux.Status
	e.PaidAt = aux.PaidAt
	e.Amount = parseSummaryAmount(aux.Amount)
	e.NetAmount = nil
	if len(aux.NetAmount) > 0 && string(aux.NetAmount) != "null" {
		net := parseSummaryAmount(aux.NetAmount)
		e.NetAmount = &net
	}
	e.Refunds = nil
	if len(aux.Refunds) > 0 && string(aux.Refunds) != "null" {
		if err := json.Unmarshal(aux.Refunds, &e.Refunds); err != nil {
			return err
		}
	}
	return nil
}

func parseSummaryAmount(raw json.RawMessage) int {
	if len(raw) == 0 || string(raw) == "null" {
		return 0
	}
	var n json.Number
	if err := json.Unmarshal(raw, &n); err == nil {
		return decimalToInt(n)
	}
	var s string
	if err := json.Unmarshal(raw, &s); err == nil {
		return decimalToInt(json.Number(strings.TrimSpace(s)))
	}
	return 0
}

// PaymentSettled reports whether a Cloud payment status means the customer's
// money is actually with the shop.
//
// Deliberately an allow-list. New payment states get invented (pending,
// disputed, partially_refunded…), and the failure directions are not
// symmetric: refusing to print a receipt for a state we do not recognise is an
// inconvenience, while printing 「お支払い済み」 over a refund is a false
// financial record.
func PaymentSettled(status string) bool {
	switch strings.ToLower(strings.TrimSpace(status)) {
	case "succeeded", "confirmed", "paid", "completed":
		return true
	default:
		return false
	}
}

type CloudPaymentReportPart struct {
	ID     string
	Amount int
	PaidAt string
}

// PaymentReportParts returns the sale and its signed refund rows separately.
// Keeping their own timestamps is what makes a daily report book a later refund
// on the refund day instead of silently rewriting the original sale day.
func PaymentReportParts(entry CloudPaymentSummaryEntry) []CloudPaymentReportPart {
	status := strings.ToLower(strings.TrimSpace(entry.Status))
	if !PaymentSettled(status) && status != "refunded" && status != "partially_refunded" {
		return nil
	}
	// A refunded row from an older Cloud has neither refund details nor a safe
	// period attribution. Fail closed rather than report its original gross.
	if (status == "refunded" || status == "partially_refunded") && entry.Refunds == nil {
		return nil
	}
	parts := make([]CloudPaymentReportPart, 0, 1+len(entry.Refunds))
	if entry.ID != "" && entry.Amount > 0 {
		parts = append(parts, CloudPaymentReportPart{ID: entry.ID, Amount: entry.Amount, PaidAt: entry.PaidAt})
	}
	for _, refund := range entry.Refunds {
		if refund.ID == "" || refund.Amount >= 0 || !PaymentSettled(refund.Status) {
			continue
		}
		parts = append(parts, CloudPaymentReportPart{ID: refund.ID, Amount: refund.Amount, PaidAt: refund.PaidAt})
	}
	return parts
}

// PaymentVisibleInHistory reports whether a Cloud payment_summary row should
// appear in POS history payments[]. Broader than PaymentSettled: Cloud's
// buildPaymentSummary also ships `refunded` rows, and hiding those makes a
// fully-refunded online order look unpaid ("Chưa có thanh toán").
// Pending / failed / unknown stay out.
func PaymentVisibleInHistory(status string) bool {
	if PaymentSettled(status) {
		return true
	}
	switch strings.ToLower(strings.TrimSpace(status)) {
	case "refunded", "partially_refunded":
		return true
	default:
		return false
	}
}

// encodeCloudPaymentSummary renders Cloud's payment_summary into the JSON blob
// stored in orders.cloud_payment_summary.
//
// nil (Cloud omitted the key — an older backend) → SQL NULL, so the upsert's
// COALESCE keeps whatever is already stored. An explicit empty list → "[]", a
// positive statement that Cloud sees no settled payment, which overwrites.
func encodeCloudPaymentSummary(entries *[]cloudOrderPaymentPayload) any {
	if entries == nil {
		return nil
	}
	out := make([]CloudPaymentSummaryEntry, 0, len(*entries))
	for _, e := range *entries {
		var netAmount *int
		if e.NetAmount != nil {
			net := decimalToInt(*e.NetAmount)
			netAmount = &net
		}
		var refunds []CloudPaymentSummaryRefund
		if e.Refunds != nil {
			refunds = make([]CloudPaymentSummaryRefund, 0, len(e.Refunds))
			for _, refund := range e.Refunds {
				refunds = append(refunds, CloudPaymentSummaryRefund{
					ID: refund.ID, Amount: decimalToInt(refund.Amount), Status: refund.Status, PaidAt: refund.PaidAt,
				})
			}
		}
		out = append(out, CloudPaymentSummaryEntry{
			ID:                e.ID,
			PaymentMethodID:   e.PaymentMethodID,
			PaymentMethodCode: e.PaymentMethodCode,
			PaymentMethodName: e.PaymentMethodName,
			Amount:            decimalToInt(e.Amount),
			NetAmount:         netAmount,
			Refunds:           refunds,
			Status:            e.Status,
			PaidAt:            e.PaidAt,
		})
	}
	blob, err := json.Marshal(out)
	if err != nil {
		return nil
	}
	return string(blob)
}

// boolPtrToNullableInt maps a *bool (Cloud is_tax_included, absent-safe) to a
// nullable 0/1 int: NULL when the field was omitted, so the upsert's COALESCE
// keeps the local value instead of resetting it to 0.
func boolPtrToNullableInt(b *bool) any {
	if b == nil {
		return nil
	}
	return boolToInt(*b)
}

// decimalPtrToNullableInt maps a *json.Number (Cloud tax_rounding_decimals,
// absent-safe) to a nullable INTEGER: NULL when nil/empty/unparseable so the
// upsert's COALESCE keeps the local value (nil decimals = currency step),
// distinct from an explicit 0 (step 1). plan-045.
func decimalPtrToNullableInt(n *json.Number) any {
	if n == nil || *n == "" {
		return nil
	}
	f, err := n.Float64()
	if err != nil {
		return nil
	}
	return int(f)
}

// stringifyValue normalises arbitrary JSON values into the TEXT shape that
// shop_settings expects.
func stringifyValue(v any) string {
	switch x := v.(type) {
	case nil:
		return ""
	case string:
		return x
	case bool:
		if x {
			return "true"
		}
		return "false"
	case float64:
		// json.Unmarshal of integers comes back as float64.
		if x == float64(int64(x)) {
			return strconv.FormatInt(int64(x), 10)
		}
		return strconv.FormatFloat(x, 'f', -1, 64)
	default:
		b, err := json.Marshal(v)
		if err != nil {
			return ""
		}
		return string(b)
	}
}

// SetAlerts nối alert centre vào puller.
//
// Setter thay vì tham số hàm dựng: `NewSyncPuller` đã có 8 tham số và được gọi
// từ nhiều chỗ (kể cả test); thêm tham số thứ 9 buộc mọi call site phải đổi để
// truyền nil, đúng loại thay đổi ồn ào che mất thay đổi thật.
func (p *SyncPuller) SetAlerts(a *AlertEmitter) {
	if p != nil {
		p.alerts = a
	}
}

// SetUpdater wires the assisted-update planner so PullExpectedBuild can stage
// a verified binary when HQ attaches a package. Nil-safe.
func (p *SyncPuller) SetUpdater(u BuildUpdater) {
	if p != nil {
		p.updater = u
	}
}
