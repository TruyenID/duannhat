package update

// Package is the optional artifact catalog attached to expected-build.
// When nil / absent the workstation still warns but cannot auto-download;
// the UI falls back to the public /downloads page.
type Package struct {
	Version   string     `json:"version"`
	Platforms []Platform `json:"platforms"`
}

// Platform is one downloadable binary for a catalog id
// (e.g. "linux-amd64", "windows-amd64.exe").
type Platform struct {
	ID     string `json:"id"`
	URL    string `json:"url"`
	SHA256 string `json:"sha256"`
	Size   int64  `json:"size"`
}

// State is the download/apply lifecycle surfaced to the Settings UI.
type State string

const (
	StateIdle           State = "idle"
	StateUpToDate       State = "up_to_date"
	StateDownloading    State = "downloading"
	StateReady          State = "ready"
	StateError          State = "error"
	StateNeedsManual    State = "needs_manual"
	StateUnsupported    State = "unsupported"
	StateDirNotWritable State = "dir_not_writable"
)

// Status is the JSON shape for GET /api/update/status.
type Status struct {
	CurrentVersion  string `json:"current_version"`
	ExpectedVersion string `json:"expected_version"`
	Reason          string `json:"reason,omitempty"`
	// AutoApply mirrors the feed's `auto_apply` flag (#2635): HQ marked this
	// build as allowed to install unattended in the shop's night window. It is
	// a SEPARATE flag, never derived from severity — severity says how bad the
	// bug is, this says whether the machine may restart with nobody standing
	// there.
	AutoApply         bool   `json:"auto_apply"`
	State             State  `json:"state"`
	ProgressPercent   int    `json:"progress_percent"`
	StagedPath        string `json:"staged_path,omitempty"`
	CanApply          bool   `json:"can_apply"`
	BlockReason       string `json:"block_reason,omitempty"`
	ShiftOpen         bool   `json:"shift_open"`
	PackageAvailable  bool   `json:"package_available"`
	ManualDownloadURL string `json:"manual_download_url,omitempty"`
	Error             string `json:"error,omitempty"`
	PlatformID        string `json:"platform_id,omitempty"`
}
