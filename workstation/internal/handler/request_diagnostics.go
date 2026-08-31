package handler

import (
	"net/http"
	"time"
)

var posLatencyBounds = [...]time.Duration{
	5 * time.Millisecond,
	10 * time.Millisecond,
	25 * time.Millisecond,
	50 * time.Millisecond,
	100 * time.Millisecond,
	250 * time.Millisecond,
	500 * time.Millisecond,
	time.Second,
	3 * time.Second,
}

type requestLatencyHistogram struct {
	count      uint64
	totalNanos uint64
	maxNanos   uint64
	buckets    [len(posLatencyBounds) + 1]uint64
}

type requestLatencySnapshot struct {
	Count     uint64  `json:"count"`
	AverageMS float64 `json:"average_ms"`
	P95MS     int64   `json:"p95_ms"`
	MaxMS     float64 `json:"max_ms"`
}

func (s *Server) withPOSRequestMetrics(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		started := time.Now()
		next.ServeHTTP(w, r)
		s.recordPOSRequestLatency(time.Since(started))
	})
}

func (s *Server) recordPOSRequestLatency(elapsed time.Duration) {
	s.posLatencyMu.Lock()
	defer s.posLatencyMu.Unlock()

	nanos := uint64(elapsed.Nanoseconds())
	s.posLatency.count++
	s.posLatency.totalNanos += nanos
	if nanos > s.posLatency.maxNanos {
		s.posLatency.maxNanos = nanos
	}
	bucket := len(posLatencyBounds)
	for i, bound := range posLatencyBounds {
		if elapsed <= bound {
			bucket = i
			break
		}
	}
	s.posLatency.buckets[bucket]++
}

func (s *Server) posRequestLatencySnapshot() requestLatencySnapshot {
	s.posLatencyMu.Lock()
	defer s.posLatencyMu.Unlock()

	if s.posLatency.count == 0 {
		return requestLatencySnapshot{}
	}
	target := (s.posLatency.count*95 + 99) / 100
	var cumulative uint64
	p95 := int64(3001)
	for i, count := range s.posLatency.buckets {
		cumulative += count
		if cumulative < target {
			continue
		}
		if i < len(posLatencyBounds) {
			p95 = posLatencyBounds[i].Milliseconds()
		}
		break
	}
	return requestLatencySnapshot{
		Count:     s.posLatency.count,
		AverageMS: float64(s.posLatency.totalNanos) / float64(s.posLatency.count) / float64(time.Millisecond),
		P95MS:     p95,
		MaxMS:     float64(s.posLatency.maxNanos) / float64(time.Millisecond),
	}
}
