package handler

import (
	"net/http"
	"strings"
	"time"
)

// lanTraceMiddleware records mutating requests from LAN clients (kiosk / pos /
// kds) into the shared sync tracer so the Đồng bộ page shows incoming client
// activity alongside UP/DOWN sync events. Only POST/PUT/PATCH/DELETE on the
// kiosk/pos/kds route groups are recorded — GET polling (menu, order status)
// fires every few seconds and would drown out the meaningful entries.
//
// The writer is only wrapped for matched requests; everything else (WS hijack
// on /ws, SSE, the SPA) passes through untouched.
func (s *Server) lanTraceMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		client := lanClientForPath(r.URL.Path)
		if client == "" || !isMutatingMethod(r.Method) || s.sync == nil {
			next.ServeHTTP(w, r)
			return
		}
		rec := &statusCaptureWriter{ResponseWriter: w, status: http.StatusOK}
		start := time.Now()
		next.ServeHTTP(rec, r)
		s.sync.Tracer().LAN(client, r.Method+" "+r.URL.Path, "", rec.status, time.Since(start).Milliseconds())
	})
}

// lanClientForPath maps an /api/v1/{kiosk,pos,kds}/... path to its subsystem
// label, or "" when the path is not a traced LAN client group.
func lanClientForPath(path string) string {
	switch {
	case strings.HasPrefix(path, "/api/v1/kiosk/"):
		return "kiosk"
	case strings.HasPrefix(path, "/api/v1/pos/"):
		return "pos"
	case strings.HasPrefix(path, "/api/v1/kds/"):
		return "kds"
	default:
		return ""
	}
}

func isMutatingMethod(m string) bool {
	switch m {
	case http.MethodPost, http.MethodPut, http.MethodPatch, http.MethodDelete:
		return true
	default:
		return false
	}
}

// statusCaptureWriter records the response status code for tracing while
// delegating everything to the wrapped ResponseWriter. It forwards Flush so
// streaming handlers keep working.
type statusCaptureWriter struct {
	http.ResponseWriter
	status      int
	wroteHeader bool
}

func (w *statusCaptureWriter) WriteHeader(code int) {
	if !w.wroteHeader {
		w.status = code
		w.wroteHeader = true
	}
	w.ResponseWriter.WriteHeader(code)
}

func (w *statusCaptureWriter) Write(b []byte) (int, error) {
	if !w.wroteHeader {
		w.wroteHeader = true // implicit 200
	}
	return w.ResponseWriter.Write(b)
}

func (w *statusCaptureWriter) Flush() {
	if f, ok := w.ResponseWriter.(http.Flusher); ok {
		f.Flush()
	}
}
