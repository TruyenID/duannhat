package store

import (
	"fmt"
	"strings"
	"testing"
)

func TestMigration093_OrderFeedPlansAvoidTemporarySort(t *testing.T) {
	db := openTestDB(t)
	for _, index := range []string{"idx_orders_branch_opened", "idx_orders_branch_type_opened"} {
		assertIndexExists(t, db, index)
	}

	for i := 0; i < 2000; i++ {
		orderType := "dine_in"
		if i%2 == 0 {
			orderType = "takeaway"
		}
		status := "open"
		if i%5 == 0 {
			status = "closed"
		}
		if _, err := db.Exec(`INSERT INTO orders
			(id, order_code, order_type, status, opened_at, organization_id, brand_id, branch_id, created_at, updated_at)
			VALUES (?, ?, ?, ?, printf('2026-08-%02dT00:00:00Z', ?), 'org', 'brand', 'branch-A',
			        '2026-08-01T00:00:00Z', '2026-08-01T00:00:00Z')`,
			fmt.Sprintf("order-%04d", i), fmt.Sprintf("WS-%04d", i), orderType, status, i%28+1, i%28+1); err != nil {
			t.Fatalf("seed order %d: %v", i, err)
		}
	}

	cases := []struct {
		name      string
		query     string
		args      []any
		wantIndex string
	}{
		{
			name: "active",
			query: `EXPLAIN QUERY PLAN SELECT id FROM orders
				WHERE branch_id = ? AND status NOT IN ('closed','voided','expired')
				ORDER BY opened_at DESC LIMIT 100`,
			args:      []any{"branch-A"},
			wantIndex: "idx_orders_branch_opened",
		},
		{
			name: "takeaway",
			query: `EXPLAIN QUERY PLAN SELECT id FROM orders
				WHERE branch_id = ? AND order_type = ? AND status NOT IN ('closed','voided','expired')
				ORDER BY opened_at DESC LIMIT 100`,
			args:      []any{"branch-A", "takeaway"},
			wantIndex: "idx_orders_branch_type_opened",
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			rows, err := db.Query(tc.query, tc.args...)
			if err != nil {
				t.Fatalf("explain: %v", err)
			}
			defer rows.Close()
			var details []string
			for rows.Next() {
				var id, parent, unused int
				var detail string
				if err := rows.Scan(&id, &parent, &unused, &detail); err != nil {
					t.Fatalf("scan plan: %v", err)
				}
				details = append(details, detail)
			}
			plan := strings.Join(details, "\n")
			if strings.Contains(plan, "USE TEMP B-TREE") {
				t.Errorf("query still sorts through a temporary B-tree:\n%s", plan)
			}
			if !strings.Contains(plan, tc.wantIndex) {
				t.Errorf("query plan does not use %s:\n%s", tc.wantIndex, plan)
			}
		})
	}
}

func TestMigration093_IsSafeToReapply(t *testing.T) {
	db := openTestDB(t)
	sqlBytes, err := localMigrationsFS.ReadFile("migrations/093_order_list_composite_indexes.sql")
	if err != nil {
		t.Fatalf("read migration: %v", err)
	}
	if _, err := db.Exec(string(sqlBytes)); err != nil {
		t.Fatalf("reapply migration: %v", err)
	}
}
