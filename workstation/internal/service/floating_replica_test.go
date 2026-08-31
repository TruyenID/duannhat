package service

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// #1180 / #1319 — the spotlight replica.
//
// Two things are pinned here, and they fail in different ways:
//
//   - the PULL persists all five arrays, including the two fields that are the
//     whole point (the pre-collapsed tax_type_id and the promo price) and the
//     three locale name columns the issue's own table left out;
//   - the WINDOW is evaluated on the device clock, including the cases that are
//     easy to get wrong: a wrap past midnight, a section with no schedule rows,
//     and inclusive date bounds.
//
// Both are money-adjacent: the wrong tax_type_id bills the wrong rate, and the
// wrong price sells at the wrong one.

const floatingCatalogJSON = `{"data":{
	"menus":[{"id":"m1","name":"Lunch","status":"published","sort_order":0,"description":""}],
	"sections":[],
	"menu_products":[{"id":"mp1","menu_id":"m1","product_id":"p1","menu_section_id":null,"is_active":true,"display_order":1}],
	"products":[{"id":"p1","name":"Bia","description":"","is_active":true}],
	"skus":[{"id":"s1","product_id":"p1","name":"Chai","sku":"BIA-1","selling_price":50000,"is_active":true}],
	"floating_sections":[
		{"id":"fs1","name":"Happy Hour","name_ja":"ハッピーアワー","name_en":"Happy Hour","name_vi":"Giờ vàng",
		 "priority":5,"is_active":true,"start_date":"2026-07-01","end_date":"2026-08-31"}
	],
	"floating_section_schedules":[
		{"id":"sch1","floating_section_id":"fs1","days_of_week":127,"start_time":"17:00","end_time":"19:00",
		 "start_date":null,"end_date":null,"is_active":true,"priority":0}
	],
	"floating_section_products":[
		{"id":"fsp1","floating_section_id":"fs1","product_id":"p1","tax_type_id":"tax-reduced","is_active":true,"display_order":2},
		{"id":"fsp2","floating_section_id":"fs1","product_id":"p2","tax_type_id":null,"is_active":true,"display_order":3}
	],
	"floating_section_product_skus":[
		{"id":"fss1","floating_section_product_id":"fsp1","product_sku_id":"s1","selling_price":30000,
		 "is_active":true,"is_price_overridden":true}
	],
	"floating_section_topping_overrides":[
		{"id":"fov1","floating_section_product_id":"fsp1","topping_group_id":"tg1","topping_group_item_id":"tgi1",
		 "product_sku_id":null,"is_hidden":false,"override_price":1500}
	]
}}`

func floatingCatalogServer() *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(floatingCatalogJSON))
	}))
}

func TestPullMenuCatalog_PersistsFloatingSections(t *testing.T) {
	cloud := floatingCatalogServer()
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullMenuCatalog(context.Background()); err != nil {
		t.Fatalf("PullMenuCatalog: %v", err)
	}

	// The section, with all three locale names. The issue's own column list
	// omitted name_ja/en/vi; a migration written from it would show the base
	// name in two of the three languages POS runs.
	var name, nameJa, nameVi string
	var priority, active int
	var start, end sql.NullString
	if err := db.QueryRow(`
		SELECT name, name_ja, name_vi, priority, is_active, start_date, end_date
		FROM pos_floating_sections WHERE id = 'fs1'`).
		Scan(&name, &nameJa, &nameVi, &priority, &active, &start, &end); err != nil {
		t.Fatalf("section row: %v", err)
	}
	if name != "Happy Hour" || nameJa != "ハッピーアワー" || nameVi != "Giờ vàng" {
		t.Errorf("locale names lost: %q / %q / %q", name, nameJa, nameVi)
	}
	if priority != 5 || active != 1 {
		t.Errorf("priority/active want 5/1, got %d/%d", priority, active)
	}
	if start.String != "2026-07-01" || end.String != "2026-08-31" {
		t.Errorf("date bounds want 2026-07-01..2026-08-31, got %q..%q", start.String, end.String)
	}

	var dow int
	var startTime, endTime string
	if err := db.QueryRow(`
		SELECT days_of_week, start_time, end_time
		FROM pos_floating_section_schedules WHERE id = 'sch1'`).
		Scan(&dow, &startTime, &endTime); err != nil {
		t.Fatalf("schedule row: %v", err)
	}
	if dow != 127 || startTime != "17:00" || endTime != "19:00" {
		t.Errorf("schedule stored wrong: dow=%d %s-%s", dow, startTime, endTime)
	}

	// The collapsed tax tier, stored verbatim. Cloud already resolved
	// `FloatingSectionProduct.tax_type_id ?? Product.tax_type_id`; re-deriving
	// it here is what would eventually print one rate and book another.
	var taxType sql.NullString
	if err := db.QueryRow(`SELECT tax_type_id FROM pos_floating_section_products WHERE id = 'fsp1'`).
		Scan(&taxType); err != nil {
		t.Fatalf("floating product row: %v", err)
	}
	if !taxType.Valid || taxType.String != "tax-reduced" {
		t.Errorf("tax_type_id want 'tax-reduced', got %q (valid=%v)", taxType.String, taxType.Valid)
	}

	// NULL must stay NULL: it means "inherit", and the resolver walks on to the
	// branch then brand default. An empty string would look up a tax type whose
	// id is "" and silently find nothing.
	var inherited sql.NullString
	if err := db.QueryRow(`SELECT tax_type_id FROM pos_floating_section_products WHERE id = 'fsp2'`).
		Scan(&inherited); err != nil {
		t.Fatalf("inheriting product row: %v", err)
	}
	if inherited.Valid {
		t.Errorf("null tax_type_id must stay NULL (inherit), got %q", inherited.String)
	}

	// The promo price lives here and ONLY here — pos_product_skus keeps the
	// price this SKU is sold at from a normal menu. Same SKU, two prices.
	var promo, menuPrice int
	if err := db.QueryRow(`SELECT selling_price FROM pos_floating_section_product_skus WHERE id = 'fss1'`).
		Scan(&promo); err != nil {
		t.Fatalf("promo sku row: %v", err)
	}
	if err := db.QueryRow(`SELECT selling_price FROM pos_product_skus WHERE id = 's1'`).
		Scan(&menuPrice); err != nil {
		t.Fatalf("menu sku row: %v", err)
	}
	if promo != 30000 {
		t.Errorf("promo price want 30000, got %d", promo)
	}
	if menuPrice != 50000 {
		t.Errorf("menu price must be untouched (50000), got %d", menuPrice)
	}

	var ovPrice sql.NullInt64
	var ovSku sql.NullString
	if err := db.QueryRow(`
		SELECT override_price, product_sku_id FROM pos_floating_section_topping_overrides WHERE id = 'fov1'`).
		Scan(&ovPrice, &ovSku); err != nil {
		t.Fatalf("topping override row: %v", err)
	}
	if !ovPrice.Valid || ovPrice.Int64 != 1500 {
		t.Errorf("override_price want 1500, got %v", ovPrice)
	}
	if ovSku.Valid {
		t.Errorf("null product_sku_id must stay NULL, got %q", ovSku.String)
	}
}

// A second pull must not stack duplicates: every table is full-replace, and the
// primary keys would otherwise collide and abort the whole catalog transaction.
func TestPullMenuCatalog_FloatingIsFullReplace(t *testing.T) {
	cloud := floatingCatalogServer()
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	for i := range 2 {
		if err := p.PullMenuCatalog(context.Background()); err != nil {
			t.Fatalf("PullMenuCatalog pass %d: %v", i+1, err)
		}
	}

	var sections, products int
	db.QueryRow(`SELECT COUNT(*) FROM pos_floating_sections`).Scan(&sections)
	db.QueryRow(`SELECT COUNT(*) FROM pos_floating_section_products`).Scan(&products)
	if sections != 1 || products != 2 {
		t.Errorf("after two pulls want 1 section / 2 products, got %d / %d", sections, products)
	}
}

// A shop can run a promotion without any published menu. The catalog pull bails
// out early when `menus` is empty, so the spotlight has to be written BEFORE
// that bail-out or it gets wiped on every pull and never refilled.
func TestPullMenuCatalog_FloatingSurvivesEmptyMenus(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte(`{"data":{
			"menus":[],
			"floating_sections":[{"id":"fs1","name":"Flash sale","priority":0,"is_active":true,
			                      "start_date":null,"end_date":null}],
			"floating_section_products":[{"id":"fsp1","floating_section_id":"fs1","product_id":"p1",
			                              "tax_type_id":null,"is_active":true,"display_order":0}]
		}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	if err := p.PullMenuCatalog(context.Background()); err != nil {
		t.Fatalf("PullMenuCatalog: %v", err)
	}

	var n int
	db.QueryRow(`SELECT COUNT(*) FROM pos_floating_sections`).Scan(&n)
	if n != 1 {
		t.Errorf("spotlight must survive a menu-less catalog, got %d sections", n)
	}
}

// ── window evaluation ──────────────────────────────────────────────────────
//
// The clock is the shop PC's own, which is correct for this question: the
// process runs in the shop, so its wall clock IS the business clock for "is the
// happy hour open". Every case below fixes an explicit instant rather than
// calling time.Now().

func at(t *testing.T, s string) time.Time {
	t.Helper()
	ts, err := time.Parse("2006-01-02 15:04", s)
	if err != nil {
		t.Fatalf("bad test time %q: %v", s, err)
	}
	return ts
}

const allDays = 127 // 1<<0 … 1<<6

func TestFloatingSectionOpenAt(t *testing.T) {
	evening := []FloatingWindow{{DaysOfWeek: allDays, StartTime: "17:00", EndTime: "19:00", IsActive: true}}

	cases := []struct {
		name    string
		now     string
		active  bool
		start   string
		end     string
		windows []FloatingWindow
		want    bool
	}{
		{"inside the window", "2026-07-15 18:00", true, "", "", evening, true},
		{"before it opens", "2026-07-15 16:59", true, "", "", evening, false},
		{"exactly at open is IN", "2026-07-15 17:00", true, "", "", evening, true},
		// Half-open on purpose: 19:00 is when it closes, not a last minute of
		// promo pricing. A cashier ringing up at 19:00 charges the menu price.
		{"exactly at close is OUT", "2026-07-15 19:00", true, "", "", evening, false},
		{"inactive section is closed even inside the window", "2026-07-15 18:00", false, "", "", evening, false},

		// Date bounds are inclusive calendar dates, compared as Y-m-d strings.
		{"first day of the range", "2026-07-01 18:00", true, "2026-07-01", "2026-07-31", evening, true},
		{"last day of the range", "2026-07-31 18:00", true, "2026-07-01", "2026-07-31", evening, true},
		{"day after the range", "2026-08-01 18:00", true, "2026-07-01", "2026-07-31", evening, false},
		{"day before the range", "2026-06-30 18:00", true, "2026-07-01", "2026-07-31", evening, false},

		// No schedule rows = open for the whole date range. Cloud allows a
		// section with date bounds and nothing else; reading "no rows" as
		// "never" would hide a running promotion.
		{"unscheduled but in range", "2026-07-15 03:00", true, "2026-07-01", "2026-07-31", nil, true},
		{"unscheduled and out of range", "2026-09-15 03:00", true, "2026-07-01", "2026-07-31", nil, false},

		// An inactive schedule row is not a window at all; with no other rows
		// the section falls back to "open all day in range".
		{"only-schedule inactive falls back to all-day", "2026-07-15 03:00", true, "", "",
			[]FloatingWindow{{DaysOfWeek: allDays, StartTime: "17:00", EndTime: "19:00", IsActive: false}}, true},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := FloatingSectionOpenAt(at(t, c.now), c.active, c.start, c.end, c.windows)
			if got != c.want {
				t.Errorf("FloatingSectionOpenAt(%s) = %v, want %v", c.now, got, c.want)
			}
		})
	}
}

// 2026-07-15 is a Wednesday; 2026-07-16 a Thursday.
func TestFloatingSectionOpenAt_DayBitmask(t *testing.T) {
	wednesdayOnly := []FloatingWindow{
		{DaysOfWeek: 1 << 3, StartTime: "17:00", EndTime: "19:00", IsActive: true}, // 3 = Wednesday, 0 = Sunday
	}

	if !FloatingSectionOpenAt(at(t, "2026-07-15 18:00"), true, "", "", wednesdayOnly) {
		t.Error("Wednesday 18:00 must be open for a Wednesday-only window")
	}
	if FloatingSectionOpenAt(at(t, "2026-07-16 18:00"), true, "", "", wednesdayOnly) {
		t.Error("Thursday 18:00 must be closed for a Wednesday-only window")
	}
}

// A late-night promo (22:00 → 02:00) is a real shape, and the naive
// `now >= start && now < end` reads it as an empty window — the promo would
// simply never open. The day bit belongs to the day the window STARTED, so
// 01:00 on Thursday is still Wednesday's promo.
func TestFloatingSectionOpenAt_WrapsPastMidnight(t *testing.T) {
	wednesdayNight := []FloatingWindow{
		{DaysOfWeek: 1 << 3, StartTime: "22:00", EndTime: "02:00", IsActive: true},
	}

	cases := []struct {
		now  string
		want bool
	}{
		{"2026-07-15 22:30", true},  // Wednesday, head of the window
		{"2026-07-16 01:00", true},  // Thursday small hours, tail of Wednesday's
		{"2026-07-16 02:00", false}, // closed on the dot
		{"2026-07-16 22:30", false}, // Thursday night is not Wednesday's promo
		{"2026-07-15 21:59", false},
	}
	for _, c := range cases {
		if got := FloatingSectionOpenAt(at(t, c.now), true, "", "", wednesdayNight); got != c.want {
			t.Errorf("wrap window at %s = %v, want %v", c.now, got, c.want)
		}
	}
}

// Stored times are not consistently zero-padded or second-less across Cloud
// columns; all three spellings must mean the same instant.
func TestFloatingSectionOpenAt_TimeSpellings(t *testing.T) {
	for _, spelling := range [][2]string{
		{"09:00", "11:00"},
		{"9:00", "11:00"},
		{"09:00:00", "11:00:00"},
	} {
		w := []FloatingWindow{{DaysOfWeek: allDays, StartTime: spelling[0], EndTime: spelling[1], IsActive: true}}
		if !FloatingSectionOpenAt(at(t, "2026-07-15 10:00"), true, "", "", w) {
			t.Errorf("10:00 must fall inside %s-%s", spelling[0], spelling[1])
		}
		if FloatingSectionOpenAt(at(t, "2026-07-15 08:59"), true, "", "", w) {
			t.Errorf("08:59 must fall outside %s-%s", spelling[0], spelling[1])
		}
	}
}
