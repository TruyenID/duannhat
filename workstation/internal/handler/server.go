package handler

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"net/http"
	stdpath "path"
	"path/filepath"
	"strings"
	stdsync "sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/config"
	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/monitor"
	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printjob"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
	"github.com/dxs-platform/workstation-app/internal/update"
)

type Server struct {
	httpServer *http.Server
	hub        *Hub
	config     *config.Manager
	db         *store.DB
	orders     *service.OrderEngine
	coupons    *service.CouponEngine
	devices    *printer.Manager
	sync       *service.SyncEngine
	audit      *audit.Logger
	alerts     *service.AlertEmitter
	updater    updatePlanner
	monitor    *monitor.Monitor
	port       int
	codeGen    *service.LocalCodeGenerator

	// Local replica (Phase 1)
	authCache    *service.AuthCacheStore
	authVerifier AuthVerifier // used by /ws first-message auth handshake
	authMW       *AuthMiddleware
	puller       *service.SyncPuller
	seenBuffer   *service.DeviceSeenBuffer
	idempotency  *service.IdempotencyStore // KDS bump dedup (Task 2.2)

	// printJournal records every print this workstation performs (plan-052
	// T1.2). It writes LOCALLY and only locally — the sync engine drains it UP
	// afterwards. Nothing on the print path may ever wait on Cloud, because a
	// Cloud outage must never become a printing outage (RISKS PR2).
	// printTemplates là bảng cache template do Cloud đẩy xuống, cộng đường
	// render TR-14 (`RenderSlip`). Dựng ở đây và CHỈ ở đây (#1913): trước đó
	// `NewPrintTemplateStore` chưa từng được gọi ở phía production, nên toàn bộ
	// đường template là code chết dù cache vẫn được `PullPrintTemplates` đổ đầy.
	//
	// Có store KHÔNG có nghĩa là template publish được dùng — seam
	// `renderMoneySlip` mặc định BẬT renderer layer 0; chỉ explicit off hoặc
	// `print_template_use_published_templates` mới đổi đường.
	printTemplates *service.PrintTemplateStore

	printJournal *printjob.Journal
	imageFetcher *service.ImageFetcher // local image cache for offline pos-web (plan-023)

	// posAssets is the embedded pos-web SPA bundle served at /pos (#1169) so
	// LAN tablets reach pos-web same-origin over http (no mixed-content wall).
	// nil in tests / when the binary was built without the bundle — the /pos
	// mount and the version endpoint are then simply not registered.
	posAssets fs.FS

	// cashChanger drives a LAN Glory 釣銭機 (YRT-R08-MN). Always built; the
	// adapter URL is resolved per request from the Cloud peripheral registry
	// (type coin_changer, synced DOWN), env WS_APP_CASH_CHANGER_URL as dev
	// fallback. The LAN endpoints 503 when neither is set.
	// See docs/guide/cash-changer-glory-adapter.md.
	cashChanger *service.CashChangerService

	// terminalBridge relays P400 (VescaJS) card charges: pos-web → this bridge →
	// the workstation frontend runs VescaJS → P400. Always built; the /pos/terminal
	// charge endpoint 503s when WS_APP_CARD_TERMINAL_HOST is unset.
	// See docs/guide/pos-card-terminal-p400-vesca.md.
	terminalBridge *service.TerminalBridge

	// cloudReachable reports whether the Cloud uplink is currently up. When
	// nil it defaults to s.sync.IsOnline(); a seam so tests can force the
	// online/offline read-routing branch in handleLocalKioskOrders.
	cloudReachable func() bool

	// kioskHealCooldown rate-limits the on-read force-pull that heals
	// "(unknown)" món names (orderID → last attempt time). Without it a món
	// genuinely unresolvable on Cloud would trigger a 1.5s pull on every
	// kiosk poll. See healKioskOrderNames.
	kioskHealCooldown stdsync.Map

	// Paired branch identity is process state: it changes only on pair/unpair.
	// Cache it so auth, health and request scoping do not each borrow SQLite.
	// branchCached distinguishes a legitimately unpaired empty value from a
	// Server assembled by a narrow test that has not primed the snapshot yet.
	branchMu     stdsync.RWMutex
	branchID     string
	branchCached bool

	posLatencyMu stdsync.Mutex
	posLatency   requestLatencyHistogram

	// Per-IP rate limiters — defense in depth against brute force on
	// pairing-code (~2M combinations) and against a compromised LAN
	// device hammering payment endpoints. Cloud already throttles at the
	// SaaS layer; workstation now does the same on the LAN edge.
	pairLimiter    *rateLimiterPool
	paymentLimiter *rateLimiterPool

	// Background loops
	bgCtx       context.Context
	bgCancel    context.CancelFunc
	maintenance *service.Maintenance

	// #2635 — unattended-install scheduler state. lastAutoApplyNight latches
	// one attempt per shop-local night (see tryScheduledAutoApply); only the
	// scheduler goroutine touches it. autoRestartFn is a test seam over the
	// supervised restart — nil means production (superviseAutoUpdateRestart).
	lastAutoApplyNight string
	autoRestartFn      func(fromVersion, toVersion string)

	// Lifecycle guards. startOnce makes Start() idempotent so a Wails window
	// reload (or an over-eager caller) cannot double-spawn background loops.
	// bgWg tracks the goroutines Start() spawns directly so Stop() can wait
	// for them to drain before declaring shutdown done.
	startOnce stdsync.Once
	bgWg      stdsync.WaitGroup
}

type Dependencies struct {
	Config    *config.Manager
	DB        *store.DB
	Orders    *service.OrderEngine
	Devices   *printer.Manager
	Sync      *service.SyncEngine
	Audit     *audit.Logger
	Monitor   *monitor.Monitor
	Port      int
	Assets    fs.FS                       // embedded frontend dist (Wails admin UI)
	PosAssets fs.FS                       // embedded pos-web SPA bundle, served at /pos (#1169)
	CodeGen   *service.LocalCodeGenerator // offline-safe order code generator
}

func New(deps Dependencies) *Server {
	if deps.Port == 0 {
		deps.Port = 6969
	}

	hub := NewHub()

	bgCtx, bgCancel := context.WithCancel(context.Background())

	coupons := service.NewCouponEngine(deps.DB, deps.Orders)

	s := &Server{
		printTemplates: service.NewPrintTemplateStore(deps.DB),

		hub:       hub,
		config:    deps.Config,
		db:        deps.DB,
		orders:    deps.Orders,
		coupons:   coupons,
		devices:   deps.Devices,
		sync:      deps.Sync,
		audit:     deps.Audit,
		monitor:   deps.Monitor,
		port:      deps.Port,
		codeGen:   deps.CodeGen,
		posAssets: deps.PosAssets,
		bgCtx:     bgCtx,
		bgCancel:  bgCancel,
	}
	s.refreshWorkstationBranchID()

	// Bind LAN clients that authenticate as an SSO user (pos-web cashier,
	// empty BranchID) to this workstation's own branch so they receive
	// branch-scoped broadcasts (e.g. KDS order_item.status_changed).
	hub.SetBranchFallback(s.workstationBranchID)

	// Local replica auth: SHA-256 token cache + forward-verify to Cloud /me.
	s.authCache = service.NewAuthCacheStore(s.db, service.DefaultAuthCacheTTL)
	verifier := service.NewCloudVerifier(s.cloudAPIURL)
	s.seenBuffer = service.NewDeviceSeenBuffer(s.db)
	// #1806 S1 — alert centre. Store đã có từ trước nhưng không ai gọi;
	// emitter là thứ biến nó từ dead code thành đường dẫn thật.
	s.alerts = service.NewAlertEmitter(service.NewAlertStore(s.db), nil)
	hub.alerts = s.alerts
	// #1806 S2 — nối đường phát. Scoped theo branch: alert của quán này không
	// được rơi sang màn hình quán khác.
	s.alerts.SetBroadcaster(func(eventType string, payload any) {
		hub.BroadcastEventScoped(eventType, payload, s.workstationBranchID())
	})
	s.authMW = NewAuthMiddleware(s.authCache, verifier, s.workstationBranchID, s.seenBuffer)
	// WS handshake now routes through the AuthMiddleware so cache hits
	// + stale tolerance apply uniformly. Previously /ws called the raw
	// CloudVerifier and a Cloud outage cut every LAN realtime channel.
	s.authVerifier = &authMiddlewareVerifier{mw: s.authMW}

	// Rate limiters. Pairing: 5/min/IP, burst 5 — matches cloud's
	// `throttle:5,1` on the same endpoint, and human-typed 6-char codes
	// can't realistically exceed this. Payments: 60/min/IP, burst 10 —
	// human checkout rate is well under this, but allows a busy POS to
	// rapid-fire a few transactions without 429.
	s.pairLimiter = newRateLimiterPool(5, 5)
	s.paymentLimiter = newRateLimiterPool(60, 10)
	s.idempotency = service.NewIdempotencyStore(s.db)
	s.printJournal = printjob.NewJournal(s.db)

	// LAN 釣銭機 (Glory YRT-R08-MN). The adapter URL comes from the Cloud
	// peripheral registry (type coin_changer) synced DOWN — resolved per request
	// so registering/changing the machine on Cloud takes effect without a
	// restart, and one service instance keeps its session state. The endpoints
	// 503 when no machine is configured (registry empty + env unset).
	// The deposit timeout resolves per transaction too (#2422): it is the number
	// that decides how long the machine waits before giving up and KEEPING the
	// customer's cash, so a shop must be able to tune it without a restart.
	machine := glory.NewResolving(s.cashChangerURL, nil)
	collector := glory.NewCollector(
		machine,
		glory.WithDepositTimeoutResolver(s.cashChangerDepositTimeout),
		// #2535 B10 — đóng dấu id giao dịch xuống đĩa NGAY khi máy trả nó. Đây
		// là khoảnh khắc sớm nhất "máy có thể đang giữ tiền" trở nên đúng, và
		// là thứ duy nhất làm lượt đối soát khởi động hỏi được máy.
		glory.WithTransactionStarted(s.stampRunningCashSession),
	)
	s.cashChanger = service.NewCashChangerService(collector, s)
	s.cashChanger.SetSessionStore(s)
	s.cashChanger.SetTransactionQuerier(machine)
	s.cashChanger.SetAlerts(s.alerts)
	s.cashChanger.SetDepositWaitResolver(s.cashChangerDepositTimeout)
	// #2535 B9 — cùng lý do resolver như trên: máy đăng ký ở Cloud rồi sync
	// DOWN, và đổi máy không được đòi khởi động lại.
	s.cashChanger.SetServerIDResolver(s.cashChangerServerID)
	// #2878 — UUID thiết bị cho sổ đi lên Cloud. Hai resolver vì hai định danh:
	// serial cho audit tại chỗ, UUID cho khoá Cloud.
	s.cashChanger.SetDeviceIDResolver(s.cashChangerDeviceID)
	// #2879/#2882 — sổ 在高 + sổ sự cố.
	s.cashChanger.SetDeviceObserver(s)

	// P400 card-terminal bridge — always built (the frontend drives the P400; Go
	// only relays). The /pos/terminal charge endpoint gates on the host config.
	s.terminalBridge = service.NewTerminalBridge(s)

	// Make sync engine read cloud_api_url from settings on every push so device
	// re-pairing takes effect without a restart.
	if s.sync != nil {
		s.sync.SetCloudURLResolver(s.cloudAPIURL)

		// #2127 A — cảnh báo Cloud ghi đè tiền phải lên được màn hình, không
		// chỉ nằm trong file log rồi biến mất khi log xoay vòng.
		s.sync.SetAlerts(s.alerts)

		// plan-052 T1.2 — drain the local print journal UP. Registered on the
		// sync engine (never called from the print path) so a Cloud outage
		// only ever delays the LEDGER, never the paper.
		s.sync.RegisterPrintJournal(s.printJournal)

		// Register KDS bump sync handler (Task 2.7). Hub + device token + branch ID
		// are all available here; injected as closures so runtime re-pairing is
		// picked up without restart.
		kdsBumpHandler := service.NewKdsBumpSyncHandler(
			s.db,
			s.cloudAPIURL,
			s.GetDeviceToken,
			hub,
			s.workstationBranchID,
		)
		kdsBumpHandler.SetTracer(s.sync.Tracer())
		s.sync.RegisterHandler("customer_order_item.update_status", kdsBumpHandler.Handle)
		// A KDS revert (e.g. ready→preparing) enqueues operation "revert_status".
		// The forwarding handler is status-agnostic — it PATCHes the payload's
		// target status to Cloud regardless of direction — so a revert reuses the
		// same Handle. Without this registration the revert has no dispatch key,
		// pushToCloud misses, and processQueue silently marks it synced while
		// Cloud keeps the pre-revert status. See godx-jp/godx-tempo#534.
		s.sync.RegisterHandler("customer_order_item.revert_status", kdsBumpHandler.Handle)

		// plan-041 — when an order.create sync reconciles the Cloud-minted
		// ORD-#### code back onto the local order, broadcast the swap so LAN
		// clients (pos-web/KDS) replace the provisional code on screen.
		s.sync.SetOrderCodeAssignedCallback(func(orderID, orderCode string) {
			hub.BroadcastEvent("order.code_assigned", map[string]any{
				"id":         orderID,
				"order_code": orderCode,
			})
		})
	}

	// Image cache fetcher — downloads product / sku / gallery URLs the
	// menu replica surfaces and stores the bytes locally so the LAN
	// HTTP server can serve them off `/api/lan/images/{hash}` even
	// when Cloud is unreachable. Same 5 s tick as the slow puller.
	s.imageFetcher = service.NewImageFetcher(s.db)

	// Sync DOWN puller — polls Cloud for zones/tables/menu/branch/orders and
	// mirrors into local SQLite. Token + URL resolved from settings on each
	// tick so device re-pairing is picked up automatically.
	s.puller = service.NewSyncPuller(s.db, s.cloudAPIURL(), s.GetDeviceToken)
	// SAU khi puller tồn tại. Đặt trước dòng trên là gọi trên nil và im lặng
	// không làm gì — đúng loại lỗi mà alert centre này sinh ra để chống.
	s.puller.SetAlerts(s.alerts)
	// Assisted update planner — stages under ~/.ws-app/updates/. Both
	// cmd/ws-server and cmd/workstation go through handler.New, so one wire
	// covers both entry points.
	configDir := ""
	if deps.Config != nil {
		configDir = deps.Config.Dir()
	}
	s.updater = update.NewPlanner(configDir)
	s.puller.SetUpdater(s.updater)
	s.puller.SetCloudURLResolver(s.cloudAPIURL)
	s.puller.SetHub(hub)
	if s.sync != nil {
		s.puller.SetTracer(s.sync.Tracer()) // merge DOWN events into the UP feed
	}
	// On 401 from cloud, clear the device token so the Wails UI redirects
	// to the pair screen on next load instead of retrying with a bad token.
	s.puller.SetOnUnauthorized(func() {
		s.clearDeviceToken()
	})

	// Auto-print orchestration for pulled-down orders (issue #456). Kiosk/
	// customer payments are confirmed in Cloud, so the local payment endpoint
	// that normally prints on confirm never fires for them — these hooks close
	// that gap and decide the kitchen-ticket vs receipt timing from the shop's
	// prep_before_payment setting. Best-effort: a print failure (no/offline
	// printer) must never disrupt the sync loop. See handler/auto_print.go.
	//
	// #2564 — this same hook is the ONLY place a payment confirmed by a
	// gateway webhook (PayPay/Stripe) becomes visible on the workstation: the
	// confirm round-trip runs entirely on Cloud (POS's confirm request falls
	// through the /api/v1/pos/ catch-all proxy — see routes.go), so nothing
	// local ever calls applyTableStatusAfterPayment or broadcasts order_paid
	// for it. Before this fix the order/table transition only reached the LAN
	// once someone reloaded pos-web, because the pull-DOWN path (upsertOrderCtx)
	// writes `orders.status` but never touches `tables`. The auto-confirm path
	// (handleLocalPosCreatePayment, cash/manual-card) already does both inline
	// and is unaffected by this change. Mirrors the order_synced fix below for
	// the same "pull-DOWN emits no signal" gap.
	s.puller.SetOnOrderPaid(func(orderID, branchID string, amount int) {
		s.handleOrderPaidAutoPrint(orderID, amount)
		s.applyTableStatusAfterPayment([]string{orderID})
		s.hub.BroadcastEventScoped("order_paid", map[string]any{
			"order_id": orderID,
			"source":   "pull_down",
		}, branchID)
	})
	// Prep-first (Mode B): a fresh online takeaway order fires its kitchen
	// ticket on arrival, before payment. Dine-in / spot customer-web orders also
	// fire their kitchen + hold slip here on arrival (gated by auto_print_kitchen).
	s.puller.SetOnOrderArrived(func(orderID, orderType, status string) {
		s.handleOrderArrivedAutoPrint(orderID, orderType, status)
	})
	// Later "add more" rounds (the order already exists locally) fire on merge —
	// dine-in appends only, gated on customer-web origin so POS manual-fire is
	// unaffected. See handler/auto_print.go.
	s.puller.SetOnOrderMerged(func(orderID, orderType, status string) {
		s.handleOrderMergedAutoPrint(orderID, orderType, status)
	})
	// Realtime for cloud-origin orders (customer-web QR + takeaway, kiosk).
	//
	// pos-web disables its list polling while this socket is up and relies
	// entirely on WS events to patch its React Query cache. The pull-DOWN path
	// emitted none, so an order placed from customer-web sat in local SQLite
	// invisibly until the operator reloaded the tab. `order_synced` closes that.
	//
	// Deliberately a lightweight signal ({order_id, is_new}) rather than the
	// full order shape: shaping needs the *http.Request for the operator's
	// Accept-Language and for rewriting image URLs onto the LAN image cache
	// host. Letting the client refetch through GET /api/v1/pos/orders keeps both
	// correct instead of guessing them from a background goroutine.
	s.puller.SetOnOrderSynced(func(orderID, branchID string, isNew bool) {
		s.hub.BroadcastEventScoped("order_synced", map[string]any{
			"order_id": orderID,
			"is_new":   isNew,
			"source":   "pull_down",
		}, branchID)
	})

	// After a cloud printer sync, reload the in-memory manager so newly-synced
	// (or removed) cloud printers route without a restart. Best-effort: a reload
	// error leaves the previous device set in place rather than an empty one.
	s.puller.SetOnPrintersSynced(func() {
		if s.devices == nil {
			return
		}
		if err := s.devices.Reload(); err != nil {
			slog.Warn("printer manager reload after sync failed", "err", err)
		}
	})

	backupDir := filepath.Join(filepath.Dir(s.db.Path()), "backups")
	s.maintenance = service.NewMaintenance(s.db, service.MaintenanceConfig{
		BackupDir: backupDir,
	})
	// Wire the bump-dedup table into the maintenance loop. Migration 010
	// promised "Cleaned up by job > 24h old" but no job existed before this
	// — the table grew unbounded on busy kitchens.
	s.maintenance.SetIdempotencyStore(s.idempotency)

	mux := http.NewServeMux()
	s.registerRoutes(mux)
	s.registerLocalReplicaRoutes(mux)

	// Serve frontend SPA from embedded assets
	if deps.Assets != nil {
		spaHandler := newSPAHandler(deps.Assets)
		mux.Handle("/", spaHandler)
	}

	// #1169 — serve the embedded pos-web SPA at /pos so LAN tablets load it
	// same-origin over http (no HTTPS mixed-content wall). Go 1.22 ServeMux
	// prefers the more specific "/pos/" over "/", and auto-redirects /pos →
	// /pos/. StripPrefix maps /pos/assets/x → assets/x inside the bundle, and
	// the SPA fallback (newSPAHandler) serves index.html for client routes. The
	// bundle is built with base "/pos/" so its asset URLs resolve here. Whole
	// mount lives inside the lanOnly+cors ring applied below.
	if deps.PosAssets != nil {
		// posSPAHandler injects the Cloud backend URL (WS_APP_CLOUD_URL, via
		// cloudURLForPairing) into index.html so pos-web's Cloud mode reads it at
		// runtime — one workstation .env drives both pairing and Cloud mode.
		mux.Handle("/pos/", http.StripPrefix("/pos", newPosSPAHandler(deps.PosAssets, s.cloudURLForPairing)))
	}

	// Outer ring: lanOnly. The server binds 0.0.0.0:6969 (so mDNS-discovered
	// LAN tablets can connect) — without this guard a misconfigured router
	// or VPN bridge could expose the workstation to the public internet.
	// localhost passes (IsLoopback in isPrivateIP). The corsMiddleware sits
	// inside lanOnly so CORS preflight from an external attacker is rejected
	// at IP level before the allow-list is consulted.
	var handler http.Handler = lanOnly(corsMiddleware(s.lanTraceMiddleware(mux)))
	if deps.Monitor != nil {
		handler = deps.Monitor.Middleware(handler)
	}

	s.httpServer = &http.Server{
		Addr:    fmt.Sprintf(":%d", deps.Port),
		Handler: handler,
		// ReadHeaderTimeout caps the time spent reading headers — without
		// it, a slowloris-style attacker can hold connections open by
		// sending headers one byte at a time, indefinitely. ReadTimeout
		// covers the body but not pre-body headers.
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	return s
}

func (s *Server) Start() error {
	// Idempotent: a Wails reload or stray caller cannot stack duplicate
	// background goroutines on top of the first Start().
	s.startOnce.Do(func() {
		s.bgWg.Add(1)
		go func() {
			defer s.bgWg.Done()
			s.hub.Run()
		}()
		if s.puller != nil {
			s.puller.Start()
		}
		if s.imageFetcher != nil {
			s.imageFetcher.Start()
		}

		// Auth cache cleanup: run every 10 min, drop entries expired > 1h.
		s.bgWg.Add(1)
		go func() {
			defer s.bgWg.Done()
			s.authCache.RunCleanupLoop(s.bgCtx, 10*time.Minute, 1*time.Hour)
		}()

		// One-shot: heal tables left stuck `occupied` by a past void/delete whose
		// Cloud release never applied. Delayed so the first tables pull settles
		// first; PullTables preserves any pending table.status op so it converges.
		s.bgWg.Add(1)
		go func() {
			defer s.bgWg.Done()
			select {
			case <-s.bgCtx.Done():
				return
			case <-time.After(15 * time.Second):
			}
			s.reconcileStuckTables()
		}()

		if s.maintenance != nil {
			s.maintenance.Start(s.bgCtx)
		}

		// #1875 — settle copy-number reservations orphaned by a process that
		// died between taking the number and finishing the print. Startup is
		// exactly the right moment: the only way a reservation outlives its
		// print is the workstation stopping, so this run IS the recovery.
		//
		// They become `needs_attention`, never `failed` — nobody knows whether
		// that sheet came out, and claiming it did not would have the shop
		// reprint a slip the customer may already be holding.
		// #2535 B10 — cùng khoảnh khắc, cùng lý do: cách duy nhất một phiên thu
		// tiền mặt sống lâu hơn lượt thu của nó là workstation dừng lại, nên
		// LƯỢT CHẠY NÀY chính là lượt phục hồi. Hỏi thẳng máy từng giao dịch
		// còn dở; ca không hỏi được thì báo người, không đoán.
		if s.cashChanger != nil {
			if n, err := s.cashChanger.ReconcileUnfinishedSessions(s.bgCtx); err != nil {
				slog.Warn("đối soát phiên 釣銭機 thất bại (không chặn khởi động)", "err", err)
			} else if n > 0 {
				slog.Warn("đã đối soát phiên thu tiền mặt còn dở từ lần chạy trước", "count", n)
			}
		}

		if s.printJournal != nil {
			if n, err := s.printJournal.SweepStaleReservations(printjob.StaleReservationAge); err != nil {
				slog.Warn("print reservation sweep failed (non-fatal)", "err", err)
			} else if n > 0 {
				slog.Warn("print reservations abandoned by a previous run",
					"rows", n, "action", "resolve them in the Cloud print ledger")
			}
		}

		if s.seenBuffer != nil {
			s.bgWg.Add(1)
			go func() {
				defer s.bgWg.Done()
				s.seenBuffer.Run(s.bgCtx, 30*time.Second)
			}()
		}

		// #2635 — same startup-is-the-recovery pattern as the sweeps above: if
		// the previous run's unattended update rolled back, this boot is the
		// only process that can raise the alert + audit for it.
		s.consumeAutoUpdateOutcome()

		// #2635 — unattended-install scheduler (02:00–04:00 shop time, only
		// for builds HQ flagged auto_apply, never while a shift is open).
		if s.updater != nil {
			s.bgWg.Add(1)
			go func() {
				defer s.bgWg.Done()
				s.runAutoUpdateLoop(s.bgCtx)
			}()
		}
	})

	slog.Info("local server starting", "port", s.port)
	if err := s.httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		return fmt.Errorf("server: %w", err)
	}
	return nil
}

// Stop tears the server down in dependency order so no background goroutine
// outlives the call. Order: cancel ctx-driven loops → stop puller → stop
// hub (signals per-client pumps to exit) → stop sync engine + monitor →
// graceful HTTP shutdown → wait for tracked goroutines to actually return.
func (s *Server) Stop() error {
	if s.bgCancel != nil {
		s.bgCancel()
	}
	if s.puller != nil {
		s.puller.Stop()
	}
	if s.imageFetcher != nil {
		s.imageFetcher.Stop()
	}
	if s.hub != nil {
		s.hub.Stop()
	}
	if s.sync != nil {
		s.sync.Stop()
	}
	if s.monitor != nil {
		s.monitor.Stop()
	}
	if s.pairLimiter != nil {
		s.pairLimiter.Stop()
	}
	if s.paymentLimiter != nil {
		s.paymentLimiter.Stop()
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	err := s.httpServer.Shutdown(ctx)
	s.bgWg.Wait()
	return err
}

func (s *Server) Hub() *Hub                          { return s.hub }
func (s *Server) Port() int                          { return s.port }
func (s *Server) AuthCache() *service.AuthCacheStore { return s.authCache }

// cloudAPIURL resolves the Cloud base URL for auth verify + sync.
// Prefer the settings row (written at pair / Settings UI) so a runtime change
// takes effect without restart; if missing (fresh DB, unpaired), fall back to
// config.json / WS_APP_CLOUD_URL / built-in default. Without that fallback,
// CloudVerifier saw "" → ErrCloudNotConfig → POS got 503
// "auth verification unavailable" even when tempo.godx.jp was reachable.
func (s *Server) cloudAPIURL() string {
	var url string
	err := s.db.QueryRow("SELECT value FROM settings WHERE key = 'cloud_api_url'").Scan(&url)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		slog.Warn("cloud_api_url lookup failed", "err", err)
	}
	if url != "" {
		return url
	}
	if s.config != nil {
		return s.config.Get().CloudAPIURL
	}
	return ""
}

// cloudURLForPairing is the pre-pair Cloud URL. Identical resolution to
// cloudAPIURL() (settings → config) — kept as a named alias because the
// pairing call sites read very differently from the verify/sync ones, and the
// whole point since #2431 is that there is exactly ONE ladder: the loopback
// POST /api/device/pair, the pos-web relay of POST /api/v1/devices/pair, and
// the cloud URL injected into the /pos bundle must never disagree about where
// Cloud is. If you add a second resolution order here, you are rebuilding the
// bug: paired against one host, verified against another.
func (s *Server) cloudURLForPairing() string {
	return s.cloudAPIURL()
}

// workstationBranchID returns this workstation's own branch_id, used for
// cross-branch protection in AuthMiddleware. Empty disables the check.
func (s *Server) workstationBranchID() string {
	s.branchMu.RLock()
	if s.branchCached {
		branchID := s.branchID
		s.branchMu.RUnlock()
		return branchID
	}
	s.branchMu.RUnlock()
	return s.refreshWorkstationBranchID()
}

func (s *Server) refreshWorkstationBranchID() string {
	var b string
	err := s.db.QueryRow("SELECT value FROM settings WHERE key = 'workstation_branch_id'").Scan(&b)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		slog.Warn("workstation_branch_id lookup failed", "err", err)
	}
	s.setWorkstationBranchIDSnapshot(b)
	return b
}

func (s *Server) setWorkstationBranchIDSnapshot(branchID string) {
	s.branchMu.Lock()
	s.branchID = branchID
	s.branchCached = true
	s.branchMu.Unlock()
}

// cachedWorkstationBranchID never touches SQLite. It is used by the liveness
// endpoint, which must answer even when every DB connection is occupied.
func (s *Server) cachedWorkstationBranchID() string {
	s.branchMu.RLock()
	defer s.branchMu.RUnlock()
	return s.branchID
}

// workstationBranchSlug returns the paired branch's slug — derived from the
// local `branches` table (omnify-managed). pos-web in LAN mode sends an
// X-Shop-Slug header from its URL `/shop/:slug`, which may not match this
// workstation's paired branch; the proxy + local handlers use this value
// to lock every request to the paired branch (workstation is by design a
// single-shop device).
//
// Returns empty string when:
//   - workstation_branch_id not set (unpaired) — caller should reject.
//   - `branches` table doesn't exist (test env without omnify migrations).
//   - the paired branch row was deleted upstream — caller should reject.
func (s *Server) workstationBranchSlug() string {
	branchID := s.workstationBranchID()
	if branchID == "" {
		return ""
	}
	var slug string
	err := s.db.QueryRow(
		"SELECT slug FROM branches WHERE id = ? LIMIT 1",
		branchID,
	).Scan(&slug)
	if err != nil {
		// Table-missing in tests, or row deleted upstream — return empty so
		// callers can fall back to "skip the check" semantics.
		if !errors.Is(err, sql.ErrNoRows) {
			slog.Debug("workstation_branch_slug lookup", "err", err)
		}
		return ""
	}
	return slug
}

// NOTE: the old policy-free `authed` helper was removed in Phase 2. Every authed
// LAN route now goes through s.authedTypes(policy, h) (or s.authMW.Wrap +
// s.requireType for the proxy handlers), so a route cannot be mounted authed
// without declaring which identity/device types it accepts (fail-closed).

func (s *Server) Broadcast(eventType string, payload any) {
	s.hub.BroadcastEvent(eventType, payload)
}

// GetLANAddress returns the local network IP address.
// Implementation moved to lanaddr.go — the prefix-matching version picked
// virtual adapters (VirtualBox 192.168.56.1, Hyper-V/WSL 172.x) that no LAN
// client can route to.

// spaHandler serves the SPA: static files first, fallback to index.html
type spaHandler struct {
	fs     http.Handler
	assets fs.FS
}

func newSPAHandler(assets fs.FS) *spaHandler {
	return &spaHandler{
		fs:     http.FileServerFS(assets),
		assets: assets,
	}
}

func (h *spaHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// Try to serve static file. `p` is deliberately not named `path` — that
	// would shadow the stdlib package used for the extension test below.
	p := r.URL.Path
	if p == "/" {
		p = "index.html"
	} else if p[0] == '/' {
		p = p[1:]
	}

	if _, err := fs.Stat(h.assets, p); err == nil {
		h.fs.ServeHTTP(w, r)
		return
	}

	// Nothing on disk. This handler is mounted at "/" — the CATCH-ALL — so
	// everything the mux did not match lands here, API paths included. An
	// unconditional index.html fallback therefore answered a mistyped or
	// not-yet-implemented endpoint with 200 + HTML (#1746), and the caller,
	// seeing 2xx, carried on until it died parsing HTML as JSON far from the
	// cause. Two exits before the fallback:

	// 1. API namespaces never render HTML. A 404 here is the honest answer and
	//    it arrives in the shape every other error on these paths uses.
	//    /api/v1/pos/* does not reach this handler at all — routes.go has a
	//    catch-all proxy for it — so this covers /api/lan/*, /api/v1/kds/*,
	//    /api/device/* and anything else unregistered.
	if rp := r.URL.Path; rp == "/api" || strings.HasPrefix(rp, "/api/") ||
		rp == "/ws" || strings.HasPrefix(rp, "/ws/") {
		writeError(w, http.StatusNotFound, "not found")
		return
	}

	// 2. Anything that LOOKS like a file 404s (same rule as the /pos mount,
	//    #1735): the frontend's client routes carry no extension, everything
	//    the bundle emits does. Without this, a webview holding a stale
	//    index.html after an app update asks for the old asset hash, gets HTML,
	//    and shows a blank screen with only a MIME error to go on.
	if stdpath.Ext(p) != "" {
		http.NotFound(w, r)
		return
	}

	// SPA fallback: serve index.html for unmatched CLIENT routes only.
	r.URL.Path = "/"
	h.fs.ServeHTTP(w, r)
}

// raiseAlert là lối tắt an toàn cho mọi call site trong package handler.
//
// Nil-safe có chủ đích: rất nhiều test dựng Server trực tiếp mà không đi qua
// NewServer, và một alert không được phép là lý do khiến test — hay việc bán
// hàng — chết.
func (s *Server) raiseAlert(kind service.AlertKind, subject, title string, detail map[string]any) {
	if s == nil || s.alerts == nil {
		return
	}
	s.alerts.Raise(kind, subject, title, detail)
}

// resolveAlert đóng alert khi điều kiện đã hết (chỉ với kind tự-resolve được).
func (s *Server) resolveAlert(kind service.AlertKind, subject string) {
	if s == nil || s.alerts == nil {
		return
	}
	s.alerts.Resolve(kind, subject)
}
