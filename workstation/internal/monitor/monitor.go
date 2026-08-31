package monitor

import (
	"net/http"
	"runtime"
	"sync"
	"sync/atomic"
	"time"
)

// LoadSnapshot holds a point-in-time view of server load metrics.
type LoadSnapshot struct {
	ActiveConnections int64   `json:"active_connections"`
	TotalRequests     int64   `json:"total_requests"`
	RequestsPerMinute float64 `json:"requests_per_minute"`
	WSClients         int     `json:"ws_clients"`
	Goroutines        int     `json:"goroutines"`
	MemoryMB          float64 `json:"memory_mb"`
	Uptime            string  `json:"uptime"`
}

// WSClientCounter is implemented by the WebSocket hub to report active clients.
type WSClientCounter interface {
	ClientCount() int
}

// Monitor tracks HTTP request metrics and system resource usage.
type Monitor struct {
	activeConns atomic.Int64
	totalReqs   atomic.Int64
	startTime   time.Time
	wsCounter   WSClientCounter

	// sliding window for requests-per-minute
	recentReqs  atomic.Int64
	prevMinReqs atomic.Int64

	stopCh   chan struct{}
	stopOnce sync.Once
}

// New creates a new Monitor. The wsCounter may be nil if WebSocket tracking
// is not needed.
func New(wsCounter WSClientCounter) *Monitor {
	m := &Monitor{
		startTime: time.Now(),
		wsCounter: wsCounter,
		stopCh:    make(chan struct{}),
	}

	// Rotate the per-minute counter every 60 seconds.
	go func() {
		ticker := time.NewTicker(60 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-m.stopCh:
				return
			case <-ticker.C:
				m.prevMinReqs.Store(m.recentReqs.Swap(0))
			}
		}
	}()

	return m
}

// Stop terminates the per-minute rotation goroutine. Safe to call multiple
// times — subsequent calls are no-ops.
func (m *Monitor) Stop() {
	m.stopOnce.Do(func() {
		close(m.stopCh)
	})
}

// Snapshot returns the current load metrics.
func (m *Monitor) Snapshot() LoadSnapshot {
	var memStats runtime.MemStats
	runtime.ReadMemStats(&memStats)

	wsClients := 0
	if m.wsCounter != nil {
		wsClients = m.wsCounter.ClientCount()
	}

	rpm := float64(m.prevMinReqs.Load())
	// If the monitor just started, use current window as approximation.
	if rpm == 0 {
		elapsed := time.Since(m.startTime).Seconds()
		if elapsed > 0 && elapsed < 60 {
			rpm = float64(m.recentReqs.Load()) / elapsed * 60
		}
	}

	uptime := time.Since(m.startTime).Truncate(time.Second)

	return LoadSnapshot{
		ActiveConnections: m.activeConns.Load(),
		TotalRequests:     m.totalReqs.Load(),
		RequestsPerMinute: rpm,
		WSClients:         wsClients,
		Goroutines:        runtime.NumGoroutine(),
		MemoryMB:          float64(memStats.Alloc) / 1024 / 1024,
		Uptime:            uptime.String(),
	}
}

// Middleware returns an http.Handler that counts requests and active connections.
func (m *Monitor) Middleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		m.activeConns.Add(1)
		m.totalReqs.Add(1)
		m.recentReqs.Add(1)

		defer m.activeConns.Add(-1)

		next.ServeHTTP(w, r)
	})
}
