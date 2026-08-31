package domain

import "time"

// ShopSetting is a key-value entry mirrored from Cloud (tax_rate, currency,
// service_charge, timezone, ...). Distinct from the local `settings` table
// which stores workstation-only config (device_token, cloud_api_url).
type ShopSetting struct {
	Key            string     `json:"key"`
	Value          string     `json:"value"`
	CloudUpdatedAt *time.Time `json:"cloud_updated_at,omitempty"`
	LocalSyncedAt  time.Time  `json:"local_synced_at"`
}
