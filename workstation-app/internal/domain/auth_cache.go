package domain

import "time"

// IdentityType distinguishes auth sources: device tokens (kiosk/tms/workstation
// paired via /devices/pair) vs SSO user tokens (pos-web users logged in via
// dxs-console).
const (
	IdentityTypeDevice = "device"
	IdentityTypeUser   = "user"
)

// AuthTokenCache stores Cloud-verified Bearer tokens locally.
// TokenHash is SHA-256 hex of the plain token (never store plain).
// Entries are valid until ExpiresAt (default 5 min after VerifiedAt).
//
// For device tokens: DeviceID/DeviceType populated, User* empty.
// For SSO tokens:    User* populated, DeviceID empty, DeviceType="" (pos-web).
type AuthTokenCache struct {
	TokenHash    string    `json:"-"`
	IdentityType string    `json:"identity_type"`
	DeviceID     string    `json:"device_id,omitempty"`
	DeviceType   string    `json:"device_type,omitempty"`
	UserID       string    `json:"user_id,omitempty"`
	UserName     string    `json:"user_name,omitempty"`
	UserEmail    string    `json:"user_email,omitempty"`
	BranchID     string    `json:"branch_id,omitempty"`
	DeviceInfo   string    `json:"device_info,omitempty"`
	VerifiedAt   time.Time `json:"verified_at"`
	ExpiresAt    time.Time `json:"expires_at"`
}
