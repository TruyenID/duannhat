package service

// Batch snapshots for the 5-second customer-order pull (#2984).
//
// The pull used to rediscover the same local state for every order and item:
// old status, local ownership, pending mutations and SKU fallback names. A
// full 500-row page therefore issued thousands of SELECTs against the same
// eight-connection SQLite pool used by LAN handlers. Load each relation in
// bounded IN-clause chunks once, then keep the existing one-order-per-write-
// transaction failure boundary.

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
)

const (
	pullSnapshotChunkSize        = 400 // 2×400 params stays below SQLite's legacy 999 limit.
	pullOrderProcessingChunkSize = 25  // bounds snapshot→write race without returning to per-row reads.
)

type pullItemPreserve struct {
	status   bool
	void     bool
	qty      bool
	note     bool
	toppings bool
}

type pullOrderSnapshot struct {
	locallyOwned     bool
	oldStatus        sql.NullString
	oldUpdatedAt     sql.NullString
	itemStatuses     map[string]string
	preserve         map[string]*pullItemPreserve
	pendingLifecycle int
}

type pullBatchSnapshot struct {
	orders   map[string]*pullOrderSnapshot
	skuNames map[string]string
}

func (s *pullOrderSnapshot) keep(itemID string) *pullItemPreserve {
	if s.preserve[itemID] == nil {
		s.preserve[itemID] = &pullItemPreserve{}
	}
	return s.preserve[itemID]
}

func newPullOrderSnapshot() *pullOrderSnapshot {
	return &pullOrderSnapshot{
		itemStatuses: make(map[string]string),
		preserve:     make(map[string]*pullItemPreserve),
	}
}

func newPullBatchSnapshot(orders []cloudOrderPayload) (*pullBatchSnapshot, []string, []string) {
	batch := &pullBatchSnapshot{
		orders:   make(map[string]*pullOrderSnapshot, len(orders)),
		skuNames: make(map[string]string),
	}
	orderIDs := make([]string, 0, len(orders))
	skuIDs := make([]string, 0)
	for _, order := range orders {
		if strings.TrimSpace(order.ID) == "" {
			continue
		}
		if _, exists := batch.orders[order.ID]; !exists {
			batch.orders[order.ID] = newPullOrderSnapshot()
			orderIDs = append(orderIDs, order.ID)
		}
		for _, item := range order.Items {
			if item.ProductSkuID != "" && strings.TrimSpace(item.MenuItemName) == "" && nameFromStub(item.ProductSku) == "" {
				skuIDs = append(skuIDs, item.ProductSkuID)
			}
		}
	}
	orderIDs = uniquePullStrings(orderIDs)
	return batch, orderIDs, uniquePullStrings(skuIDs)
}

func (p *SyncPuller) loadPullOrderHeaderSnapshot(orders []cloudOrderPayload) (*pullBatchSnapshot, error) {
	batch, orderIDs, _ := newPullBatchSnapshot(orders)
	if len(orderIDs) == 0 {
		return batch, nil
	}
	if err := p.loadExistingOrderSnapshots(batch, orderIDs); err != nil {
		return nil, err
	}
	return batch, nil
}

func (p *SyncPuller) loadPullBatchSnapshot(orders []cloudOrderPayload) (*pullBatchSnapshot, error) {
	batch, orderIDs, skuIDs := newPullBatchSnapshot(orders)
	if len(orderIDs) == 0 {
		return batch, nil
	}

	if err := p.loadExistingOrderSnapshots(batch, orderIDs); err != nil {
		return nil, err
	}
	if err := p.loadOldItemStatuses(batch, orderIDs); err != nil {
		return nil, err
	}
	if err := p.loadPendingItemStatuses(batch, orderIDs); err != nil {
		return nil, err
	}
	if err := p.loadPendingOrderOperations(batch, orderIDs); err != nil {
		return nil, err
	}
	if err := p.loadPullSKUFallbackNames(batch, skuIDs); err != nil {
		return nil, err
	}
	return batch, nil
}

func (p *SyncPuller) loadExistingOrderSnapshots(batch *pullBatchSnapshot, orderIDs []string) error {
	return forPullStringChunks(orderIDs, func(ids []string) error {
		clause := pullPlaceholders(len(ids))
		args := make([]any, 0, len(ids)*2)
		for _, id := range ids {
			args = append(args, id)
		}
		for _, id := range ids {
			args = append(args, id)
		}
		rows, err := p.db.Query(fmt.Sprintf(`
			SELECT id, cloud_id, status, updated_at
			  FROM orders
			 WHERE id IN (%s) OR cloud_id IN (%s)`, clause, clause), args...)
		if err != nil {
			return fmt.Errorf("snapshot existing orders: %w", err)
		}
		defer rows.Close()
		for rows.Next() {
			var id string
			var cloudID, status, updatedAt sql.NullString
			if err := rows.Scan(&id, &cloudID, &status, &updatedAt); err != nil {
				return fmt.Errorf("scan existing order snapshot: %w", err)
			}
			if state := batch.orders[id]; state != nil {
				state.oldStatus = status
				state.oldUpdatedAt = updatedAt
			}
			if cloudID.Valid && cloudID.String != id {
				if state := batch.orders[cloudID.String]; state != nil {
					state.locallyOwned = true
				}
			}
		}
		return rows.Err()
	})
}

func (p *SyncPuller) loadOldItemStatuses(batch *pullBatchSnapshot, orderIDs []string) error {
	return forPullStringChunks(orderIDs, func(ids []string) error {
		rows, err := p.db.Query(fmt.Sprintf(`
			SELECT customer_order_id, id, status
			  FROM order_items
			 WHERE customer_order_id IN (%s)`, pullPlaceholders(len(ids))), pullArgs(ids)...)
		if err != nil {
			return fmt.Errorf("snapshot order item statuses: %w", err)
		}
		defer rows.Close()
		for rows.Next() {
			var orderID, itemID, status string
			if err := rows.Scan(&orderID, &itemID, &status); err != nil {
				return fmt.Errorf("scan order item snapshot: %w", err)
			}
			if state := batch.orders[orderID]; state != nil {
				state.itemStatuses[itemID] = status
			}
		}
		return rows.Err()
	})
}

func (p *SyncPuller) loadPendingItemStatuses(batch *pullBatchSnapshot, orderIDs []string) error {
	return forPullStringChunks(orderIDs, func(ids []string) error {
		rows, err := p.db.Query(fmt.Sprintf(`
			SELECT oi.customer_order_id, sq.entity_id
			  FROM sync_queue sq
			  JOIN order_items oi ON oi.id = sq.entity_id
			 WHERE sq.entity_type = 'customer_order_item'
			   AND sq.operation IN ('update_status', 'revert_status')
			   AND sq.synced_at IS NULL AND sq.dead_lettered_at IS NULL
			   AND oi.customer_order_id IN (%s)`, pullPlaceholders(len(ids))), pullArgs(ids)...)
		if err != nil {
			return fmt.Errorf("snapshot pending item statuses: %w", err)
		}
		defer rows.Close()
		for rows.Next() {
			var orderID, itemID string
			if err := rows.Scan(&orderID, &itemID); err != nil {
				return fmt.Errorf("scan pending item status: %w", err)
			}
			if state := batch.orders[orderID]; state != nil {
				state.keep(itemID).status = true
			}
		}
		return rows.Err()
	})
}

func (p *SyncPuller) loadPendingOrderOperations(batch *pullBatchSnapshot, orderIDs []string) error {
	return forPullStringChunks(orderIDs, func(ids []string) error {
		rows, err := p.db.Query(fmt.Sprintf(`
			SELECT entity_id, operation, payload
			  FROM sync_queue
			 WHERE entity_type = 'order'
			   AND operation IN ('item_update', 'item_void', 'item_delete',
			                     'confirm', 'checkout', 'void', 'delete')
			   AND synced_at IS NULL AND dead_lettered_at IS NULL
			   AND entity_id IN (%s)`, pullPlaceholders(len(ids))), pullArgs(ids)...)
		if err != nil {
			return fmt.Errorf("snapshot pending order operations: %w", err)
		}
		defer rows.Close()
		for rows.Next() {
			var orderID, operation, raw string
			if err := rows.Scan(&orderID, &operation, &raw); err != nil {
				return fmt.Errorf("scan pending order operation: %w", err)
			}
			state := batch.orders[orderID]
			if state == nil {
				continue
			}
			switch operation {
			case "confirm", "checkout", "void", "delete":
				state.pendingLifecycle = 1
			case "item_update", "item_void", "item_delete":
				applyPendingItemOperation(state, operation, raw)
			}
		}
		return rows.Err()
	})
}

func applyPendingItemOperation(state *pullOrderSnapshot, operation, raw string) {
	var op struct {
		ItemID string `json:"item_id"`
		Patch  struct {
			Quantity *int             `json:"quantity"`
			Note     *string          `json:"note"`
			Status   *string          `json:"status"`
			Toppings *json.RawMessage `json:"toppings"`
		} `json:"patch"`
	}
	if json.Unmarshal([]byte(raw), &op) != nil || op.ItemID == "" {
		return
	}
	item := state.keep(op.ItemID)
	if operation == "item_void" || operation == "item_delete" {
		item.void = true
		return
	}
	if op.Patch.Quantity != nil {
		item.qty = true
	}
	if op.Patch.Note != nil {
		item.note = true
	}
	if op.Patch.Status != nil {
		item.status = true
	}
	if op.Patch.Toppings != nil {
		item.toppings = true
	}
}

func (p *SyncPuller) loadPullSKUFallbackNames(batch *pullBatchSnapshot, skuIDs []string) error {
	return forPullStringChunks(skuIDs, func(ids []string) error {
		rows, err := p.db.Query(fmt.Sprintf(`
			SELECT ps.id, p.name
			  FROM pos_product_skus ps
			  JOIN pos_products p ON p.id = ps.product_id
			 WHERE ps.id IN (%s)`, pullPlaceholders(len(ids))), pullArgs(ids)...)
		if err != nil {
			return fmt.Errorf("snapshot SKU fallback names: %w", err)
		}
		defer rows.Close()
		for rows.Next() {
			var id, name string
			if err := rows.Scan(&id, &name); err != nil {
				return fmt.Errorf("scan SKU fallback name: %w", err)
			}
			if strings.TrimSpace(name) != "" {
				batch.skuNames[id] = name
			}
		}
		return rows.Err()
	})
}

func forPullStringChunks(values []string, fn func([]string) error) error {
	for start := 0; start < len(values); start += pullSnapshotChunkSize {
		end := start + pullSnapshotChunkSize
		if end > len(values) {
			end = len(values)
		}
		if err := fn(values[start:end]); err != nil {
			return err
		}
	}
	return nil
}

func uniquePullStrings(values []string) []string {
	seen := make(map[string]struct{}, len(values))
	out := make([]string, 0, len(values))
	for _, value := range values {
		if value == "" {
			continue
		}
		if _, exists := seen[value]; exists {
			continue
		}
		seen[value] = struct{}{}
		out = append(out, value)
	}
	return out
}

func pullPlaceholders(count int) string {
	return strings.TrimSuffix(strings.Repeat("?,", count), ",")
}

func pullArgs(values []string) []any {
	args := make([]any, len(values))
	for i, value := range values {
		args[i] = value
	}
	return args
}
