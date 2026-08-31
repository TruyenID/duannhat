package handler

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestPOSRequestMetrics_ReportsBoundedP95WithoutRequestData(t *testing.T) {
	s := &Server{}
	h := s.withPOSRequestMetrics(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		time.Sleep(12 * time.Millisecond)
		w.WriteHeader(http.StatusNoContent)
	}))

	for i := 0; i < 3; i++ {
		h.ServeHTTP(httptest.NewRecorder(), httptest.NewRequest(http.MethodGet, "/api/v1/pos/orders?customer=secret", nil))
	}
	snapshot := s.posRequestLatencySnapshot()
	if snapshot.Count != 3 {
		t.Errorf("count = %d, want 3", snapshot.Count)
	}
	if snapshot.P95MS != 25 {
		t.Errorf("p95 bucket = %dms, want 25ms", snapshot.P95MS)
	}
	if snapshot.AverageMS < 10 || snapshot.MaxMS < 10 {
		t.Errorf("latency not recorded: %+v", snapshot)
	}
}

func TestPOSRequestMetrics_P95IsNotDistortedByAHandfulOfOutliers(t *testing.T) {
	s := &Server{}
	for range 95 {
		s.recordPOSRequestLatency(4 * time.Millisecond)
	}
	for range 5 {
		s.recordPOSRequestLatency(4 * time.Second)
	}

	snapshot := s.posRequestLatencySnapshot()
	if snapshot.Count != 100 {
		t.Fatalf("count = %d, want 100", snapshot.Count)
	}
	if snapshot.P95MS != 5 {
		t.Fatalf("p95 = %dms, want the 5ms bucket", snapshot.P95MS)
	}
	if snapshot.MaxMS != 4000 {
		t.Fatalf("max = %.1fms, want 4000ms", snapshot.MaxMS)
	}
	if snapshot.AverageMS < 203 || snapshot.AverageMS > 204 {
		t.Fatalf("average = %.3fms, want the exact mixed-distribution mean near 203.8ms", snapshot.AverageMS)
	}
}
