package handler

import (
	"net"
	"net/http"
	"sync"
	"time"

	"golang.org/x/time/rate"
)

// rateLimiterPool keeps one token-bucket limiter per remote IP. Wraps
// the standard pattern from the docs of golang.org/x/time/rate so we
// can plug it as a middleware in front of brute-forceable endpoints
// (device pairing, payments, audit dumps).
//
// Cleanup loop drops idle entries every 10 minutes; under our scale
// (one workstation per restaurant, a handful of LAN clients) memory
// pressure is negligible, but the loop keeps the map from growing if
// a wandering attacker sweeps the LAN.
type rateLimiterPool struct {
	mu       sync.Mutex
	visitors map[string]*ipLimiter
	// per-IP allowance — 5 token/min default but the constructor lets callers
	// pick different policies per route. r = events/sec, b = burst.
	r      rate.Limit
	b      int
	stopCh chan struct{} // closed by Stop() to terminate the gc goroutine
}

type ipLimiter struct {
	limiter  *rate.Limiter
	lastSeen time.Time
}

func newRateLimiterPool(perMinute float64, burst int) *rateLimiterPool {
	p := &rateLimiterPool{
		visitors: make(map[string]*ipLimiter),
		r:        rate.Limit(perMinute / 60.0), // events per second
		b:        burst,
		stopCh:   make(chan struct{}),
	}
	go p.gc(15*time.Minute, 30*time.Minute)
	return p
}

// Stop terminates the GC loop. Safe to call multiple times.
func (p *rateLimiterPool) Stop() {
	select {
	case <-p.stopCh: // already stopped
	default:
		close(p.stopCh)
	}
}

func (p *rateLimiterPool) getLimiter(ip string) *rate.Limiter {
	p.mu.Lock()
	defer p.mu.Unlock()
	if v, ok := p.visitors[ip]; ok {
		v.lastSeen = time.Now()
		return v.limiter
	}
	l := rate.NewLimiter(p.r, p.b)
	p.visitors[ip] = &ipLimiter{limiter: l, lastSeen: time.Now()}
	return l
}

func (p *rateLimiterPool) gc(every, idle time.Duration) {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		select {
		case <-p.stopCh:
			return
		case <-t.C:
			cutoff := time.Now().Add(-idle)
			p.mu.Lock()
			for ip, v := range p.visitors {
				if v.lastSeen.Before(cutoff) {
					delete(p.visitors, ip)
				}
			}
			p.mu.Unlock()
		}
	}
}

// Middleware returns a handler that 429s requests over the per-IP rate.
// Identifies the caller by `r.RemoteAddr` host portion — NOT
// X-Forwarded-For — because workstations have no reverse proxy in
// front (LAN-only), and accepting XFF would let a LAN client lie about
// its IP to bypass the limit. Audit logs already capture the real IP
// via `clientIP()` for forensics.
func (p *rateLimiterPool) Middleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ip := remoteIP(r)
		if !p.getLimiter(ip).Allow() {
			w.Header().Set("Retry-After", "60")
			writeError(w, http.StatusTooManyRequests, "rate limit exceeded")
			return
		}
		next.ServeHTTP(w, r)
	})
}

// remoteIP strips the port off RemoteAddr; falls back to the raw string
// if SplitHostPort fails (test artefacts, unix sockets). Never honors
// XFF — see Middleware doc comment.
func remoteIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}
