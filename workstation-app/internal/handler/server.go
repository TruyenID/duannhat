package handler

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"net/http"
	"path/filepath"
	stdsync "sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/config"
	"github.com/dxs-platform/workstation-app/internal/device/glory"
	"github.com/dxs-platform/workstation-app/internal/monitor"
	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
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
	imageFetcher *service.ImageFetcher     // local image cache for offline pos-web (plan-023)

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

	// Lifecycle guards. startOnce makes Start() idempotent so a Wails window
	// reload (or an over-eager caller) cannot double-spawn background loops.
	// bgWg tracks the goroutines Start() spawns directly so Stop() can wait
	// for them to drain before declaring shutdown done.
	startOnce stdsync.Once
	bgWg      stdsync.WaitGroup
}

type Dependencies struct {
	Config  *config.Manager
	DB      *store.DB
	Orders  *service.OrderEngine
	Devices *printer.Manager
	Sync    *service.SyncEngine
	Audit   *audit.Logger
	Monitor *monitor.Monitor
	Port    int
	Assets  fs.FS                       // embedded frontend dist
	CodeGen *service.LocalCodeGenerator // offline-safe order code generator
}

func New(deps Dependencies) *Server {
	if deps.Port == 0 {
		deps.Port = 8080
	}

	hub := NewHub()

	bgCtx, bgCancel := context.WithCancel(context.Background())

	coupons := service.NewCouponEngine(deps.DB, deps.Orders)

	s := &Server{
		hub:      hub,
		config:   deps.Config,
		db:       deps.DB,
		orders:   deps.Orders,
		coupons:  coupons,
		devices:  deps.Devices,
		sync:     deps.Sync,
		audit:    deps.Audit,
		monitor:  deps.Monitor,
		port:     deps.Port,
		codeGen:  deps.CodeGen,
		bgCtx:    bgCtx,
		bgCancel: bgCancel,
	}

	// Bind LAN clients that authenticate as an SSO user (pos-web cashier,
	// empty BranchID) to this workstation's own branch so they receive
	// branch-scoped broadcasts (e.g. KDS order_item.status_changed).
	hub.SetBranchFallback(s.workstationBranchID)

	// Local replica auth: SHA-256 token cache + forward-verify to Cloud /me.
	s.authCache = service.NewAuthCacheStore(s.db, service.DefaultAuthCacheTTL)
	verifier := service.NewCloudVerifier(s.cloudAPIURL)
	s.seenBuffer = service.NewDeviceSeenBuffer(s.db)
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

	// LAN 釣銭機 (Glory YRT-R08-MN). The adapter URL comes from the Cloud
	// peripheral registry (type coin_changer) synced DOWN — resolved per request
	// so registering/changing the machine on Cloud takes effect without a
	// restart, and one service instance keeps its session state. The endpoints
	// 503 when no machine is configured (registry empty + env unset).
	collector := glory.NewCollector(glory.NewResolving(s.cashChangerURL, nil))
	s.cashChanger = service.NewCashChangerService(collector, s)

	// P400 card-terminal bridge — always built (the frontend drives the P400; Go
	// only relays). The /pos/terminal charge endpoint gates on the host config.
	s.terminalBridge = service.NewTerminalBridge(s)

	// Make sync engine read cloud_api_url from settings on every push so device
	// re-pairing takes effect without a restart.
	if s.sync != nil {
		s.sync.SetCloudURLResolver(s.cloudAPIURL)

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
	s.puller.SetOnOrderPaid(func(orderID string, amount int) {
		s.handleOrderPaidAutoPrint(orderID, amount)
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

	// Outer ring: lanOnly. The server binds 0.0.0.0:8080 (so mDNS-discovered
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

		if s.seenBuffer != nil {
			s.bgWg.Add(1)
			go func() {
				defer s.bgWg.Done()
				s.seenBuffer.Run(s.bgCtx, 30*time.Second)
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

// cloudAPIURL reads `cloud_api_url` from the settings table on every call so
// updates from device pairing take effect without a server restart. Empty
// return means either unpaired or DB failure; sql.ErrNoRows is normal but
// other errors get logged so a misconfig doesn't disappear silently.
func (s *Server) cloudAPIURL() string {
	var url string
	err := s.db.QueryRow("SELECT value FROM settings WHERE key = 'cloud_api_url'").Scan(&url)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		slog.Warn("cloud_api_url lookup failed", "err", err)
	}
	return url
}

// workstationBranchID returns this workstation's own branch_id, used for
// cross-branch protection in AuthMiddleware. Empty disables the check.
func (s *Server) workstationBranchID() string {
	var b string
	err := s.db.QueryRow("SELECT value FROM settings WHERE key = 'workstation_branch_id'").Scan(&b)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		slog.Warn("workstation_branch_id lookup failed", "err", err)
	}
	return b
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
	// Try to serve static file
	path := r.URL.Path
	if path == "/" {
		path = "index.html"
	} else if path[0] == '/' {
		path = path[1:]
	}

	if _, err := fs.Stat(h.assets, path); err == nil {
		h.fs.ServeHTTP(w, r)
		return
	}

	// SPA fallback: serve index.html for all unmatched routes
	r.URL.Path = "/"
	h.fs.ServeHTTP(w, r)
}
