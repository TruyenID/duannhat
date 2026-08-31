package service

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain"
)

// Identity is the result of Cloud-side token verification. It abstracts over
// device tokens (kiosk/tms/workstation paired via /devices/pair) and SSO user
// tokens (pos-web users logged via dxs-console SSO).
//
// For Type=domain.IdentityTypeDevice: DeviceID/DeviceType populated.
// For Type=domain.IdentityTypeUser:   UserID/UserName/UserEmail populated.
type Identity struct {
	Type           string // "device" | "user"
	DeviceID       string // device tokens only
	DeviceType     string // "kiosk" | "tms" | "workstation"
	UserID         string // SSO tokens only
	UserName       string
	UserEmail      string
	BranchID       string
	OrganizationID string
}

// DeviceInfo is retained for backward compatibility with existing callers that
// only need device-token semantics. New code should use Identity instead.
type DeviceInfo struct {
	ID             string `json:"id"`
	Name           string `json:"name"`
	Type           string `json:"type"`
	Status         string `json:"status"`
	BranchID       string `json:"branch_id"`
	OrganizationID string `json:"organization_id"`
}

type kioskMeResponse struct {
	Data DeviceInfo `json:"data"`
}

// meContextResponse mirrors GET /api/v1/me/context — the existing SSO-auth'd
// endpoint backed by dxs-sso. See backend/app/Http/Controllers/Api/V1/UserContextController.php.
type meContextResponse struct {
	User struct {
		ID       string `json:"id"`
		Name     string `json:"name"`
		Email    string `json:"email"`
		Locale   string `json:"locale"`
		Timezone string `json:"timezone"`
	} `json:"user"`
	BrandCount int `json:"brand_count"`
	ShopCount  int `json:"shop_count"`
}

// Verifier errors.
var (
	ErrUnauthorized     = errors.New("cloud rejected token")
	ErrCloudUnreachable = errors.New("cloud unreachable")
	ErrCloudNotConfig   = errors.New("cloud URL not configured")
)

// CloudVerifier forwards a Bearer token to Cloud and returns the resolved
// Identity. Endpoint selected by token shape:
//
//	"{id}|{hash}" (Sanctum personal access token) → /api/v1/me/context
//	plain 64-char hex                              → /api/v1/devices/me
type CloudVerifier struct {
	httpClient *http.Client
	cloudURLFn func() string
}

func NewCloudVerifier(cloudURLFn func() string) *CloudVerifier {
	return &CloudVerifier{
		httpClient: &http.Client{Timeout: 5 * time.Second},
		cloudURLFn: cloudURLFn,
	}
}

// detectTokenType inspects a Bearer token to choose the verify endpoint.
// Sanctum tokens always contain a '|' separator between record id and the
// underlying random hash; device tokens generated via /devices/pair are plain
// random strings without that separator.
func detectTokenType(token string) string {
	if strings.Contains(token, "|") {
		return domain.IdentityTypeUser
	}
	return domain.IdentityTypeDevice
}

// Verify forwards the token to the appropriate Cloud endpoint and returns the
// resolved Identity. Returns ErrUnauthorized on 401/403, ErrCloudUnreachable
// on network/timeout.
func (v *CloudVerifier) Verify(ctx context.Context, token string) (*Identity, error) {
	baseURL := strings.TrimRight(v.cloudURLFn(), "/")
	if baseURL == "" {
		return nil, ErrCloudNotConfig
	}

	identityType := detectTokenType(token)
	path := "/api/v1/devices/me"
	if identityType == domain.IdentityTypeUser {
		path = "/api/v1/me/context"
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, baseURL+path, nil)
	if err != nil {
		return nil, fmt.Errorf("build request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")

	resp, err := v.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("%w: %v", ErrCloudUnreachable, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return nil, ErrUnauthorized
	}
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<10))
		return nil, fmt.Errorf("verify failed (HTTP %d): %s", resp.StatusCode, string(body))
	}

	if identityType == domain.IdentityTypeUser {
		return parseSSOResponse(resp.Body)
	}
	return parseDeviceResponse(resp.Body)
}

func parseDeviceResponse(body io.Reader) (*Identity, error) {
	var parsed kioskMeResponse
	if err := json.NewDecoder(body).Decode(&parsed); err != nil {
		return nil, fmt.Errorf("parse devices/me response: %w", err)
	}
	if parsed.Data.ID == "" {
		return nil, fmt.Errorf("malformed devices/me response: missing device id")
	}
	return &Identity{
		Type:           domain.IdentityTypeDevice,
		DeviceID:       parsed.Data.ID,
		DeviceType:     parsed.Data.Type,
		BranchID:       parsed.Data.BranchID,
		OrganizationID: parsed.Data.OrganizationID,
	}, nil
}

func parseSSOResponse(body io.Reader) (*Identity, error) {
	var parsed meContextResponse
	if err := json.NewDecoder(body).Decode(&parsed); err != nil {
		return nil, fmt.Errorf("parse me/context response: %w", err)
	}
	if parsed.User.ID == "" {
		return nil, fmt.Errorf("malformed me/context response: missing user id")
	}
	// /me/context does not return branch_id directly. Branch context is
	// resolved at request time from the workstation's own branch (one
	// workstation = one branch in the demo deployment) — see
	// AuthMiddleware.branchOK SSO branch.
	return &Identity{
		Type:      domain.IdentityTypeUser,
		UserID:    parsed.User.ID,
		UserName:  parsed.User.Name,
		UserEmail: parsed.User.Email,
	}, nil
}
