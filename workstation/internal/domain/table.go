package domain

import "time"

type TableStatus string

const (
	TableStatusAvailable TableStatus = "available"
	TableStatusOccupied  TableStatus = "occupied"
	TableStatusReserved  TableStatus = "reserved"
)

// Table is mirrored from Cloud via sync DOWN. Cloud is source of truth.
type Table struct {
	ID             string      `json:"id"`
	QRToken        string      `json:"qr_token,omitempty"`
	Name           string      `json:"name"`
	ZoneID         string      `json:"zone_id,omitempty"`
	Status         TableStatus `json:"status"`
	Capacity       int         `json:"capacity"`
	CloudUpdatedAt *time.Time  `json:"cloud_updated_at,omitempty"`
	LocalSyncedAt  time.Time   `json:"local_synced_at"`
}
