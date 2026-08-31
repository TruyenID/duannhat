package domain

import "time"

// Zone is mirrored from Cloud via sync DOWN. Cloud is source of truth.
type Zone struct {
	ID             string     `json:"id"`
	Name           string     `json:"name"`
	SortOrder      int        `json:"sort_order"`
	CloudUpdatedAt *time.Time `json:"cloud_updated_at,omitempty"`
	LocalSyncedAt  time.Time  `json:"local_synced_at"`
}
