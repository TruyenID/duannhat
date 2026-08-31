package handler

import (
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/update"
)

// updateRestartDelay is the gap between answering the apply request and tearing
// the process down. It exists so the 200 actually reaches the operator's
// browser: the response is flushed while this handler returns, and only then
// may the graceful shutdown — which waits for in-flight requests — begin.
// Variable so tests can shrink it.
var updateRestartDelay = 1500 * time.Millisecond

func (s *Server) handleUpdateStatus(w http.ResponseWriter, r *http.Request) {
	if s.updater == nil {
		writeJSON(w, http.StatusOK, update.Status{
			State:       update.StateIdle,
			BlockReason: "updater_unavailable",
		})
		return
	}
	st := s.updater.Status()
	st.ShiftOpen = s.hasInProgressShift()
	st.ManualDownloadURL = s.downloadsPageURL()
	if st.ShiftOpen && st.CanApply {
		st.CanApply = false
		st.BlockReason = "shift_in_progress"
	}
	writeJSON(w, http.StatusOK, st)
}

func (s *Server) handleUpdateDownload(w http.ResponseWriter, r *http.Request) {
	if s.updater == nil {
		writeError(w, http.StatusServiceUnavailable, "updater unavailable")
		return
	}
	s.updater.KickDownload(true)
	st := s.updater.Status()
	st.ShiftOpen = s.hasInProgressShift()
	st.ManualDownloadURL = s.downloadsPageURL()
	writeJSON(w, http.StatusAccepted, st)
}

func (s *Server) handleUpdateApply(w http.ResponseWriter, r *http.Request) {
	if s.updater == nil {
		writeError(w, http.StatusServiceUnavailable, "updater unavailable")
		return
	}
	if s.hasInProgressShift() {
		writeJSON(w, http.StatusConflict, map[string]any{
			"message": "shift_in_progress",
			"code":    "shift_in_progress",
		})
		return
	}
	// The pre-install database copy comes first and its failure is terminal
	// (#2659). Taken unconditionally, without first asking Status() whether
	// Apply would have refused anyway: Apply is the only authority on whether
	// the swap happens, and inferring "it would have refused" from a second
	// predicate is how an install eventually proceeds with no copy behind it.
	// The cost of the inversion is a wasted VACUUM on a misclick — bounded,
	// loopback-only, and rotated away after three.
	if _, err := s.snapshotBeforeUpdate(s.updater.Status().ExpectedVersion); err != nil {
		slog.Error("assisted update: refusing to install without a pre-update backup", "err", err)
		writeJSON(w, http.StatusConflict, map[string]any{
			"message": err.Error(),
			"code":    "backup_failed",
		})
		return
	}

	if err := s.updater.Apply(); err != nil {
		if update.IsDirNotWritable(err) {
			writeJSON(w, http.StatusConflict, map[string]any{
				"message":             err.Error(),
				"code":                "dir_not_writable",
				"manual_download_url": s.downloadsPageURL(),
			})
			return
		}
		if update.IsApplyInFlight(err) {
			writeJSON(w, http.StatusConflict, map[string]any{
				"message": err.Error(),
				"code":    "apply_in_progress",
			})
			return
		}
		writeError(w, http.StatusConflict, err.Error())
		return
	}
	// The binary is swapped; the restart is deliberately NOT done inline. It
	// closes the very listener this response has to travel through, and the
	// graceful shutdown waits for this request to finish — so answer first,
	// then hand over.
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "restarting": true})
	s.scheduleUpdateRestart()
}

// scheduleUpdateRestart tears the process down and starts the newly installed
// binary, once the apply response has had time to reach the client.
func (s *Server) scheduleUpdateRestart() {
	updater := s.updater
	if updater == nil {
		return
	}
	time.AfterFunc(updateRestartDelay, func() {
		updater.Restart(func() {
			// The same ordered teardown SIGTERM would run: background loops,
			// puller, hub, sync engine, then a graceful HTTP shutdown. Skipping
			// it (the old os.Exit) cut print jobs and sync pushes mid-flight.
			if err := s.Stop(); err != nil {
				slog.Warn("assisted update: shutdown before restart", "err", err)
			}
		})
	})
}

func (s *Server) downloadsPageURL() string {
	base := strings.TrimRight(s.cloudAPIURL(), "/")
	if base == "" {
		return "/downloads"
	}
	return base + "/downloads"
}
