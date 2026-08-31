package service

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"sort"
	"testing"
)

// #1181 — THE CROSS-REPO GATE.
//
// The TR-40 migration gate next door proves that the WORKSTATION's embedded
// layer 0 renders byte-identical to the hard-coded formatters. It says nothing
// about Cloud's layer 0, and Cloud's is the one that matters commercially: the
// admin UI (#1171 M4) shows a brand the CLOUD default and lets it edit from
// there, so whatever Cloud thinks a receipt looks like becomes what the shop
// prints the moment anybody presses Publish.
//
// Before #1181 those two copies disagreed on all thirteen kinds — most
// expensively in the money footer, where Cloud placed the per-rate 内税 block
// ABOVE the grand total while every receipt in every till prints it BELOW
// (#1042). Publishing Cloud's default as-is would have silently rewritten the
// layout of every slip in the fleet, which is exactly the outcome plan-053
// promises can never happen (TR-40).
//
// So this test renders CLOUD's definitions with the workstation's REAL renderer
// and holds the bytes against the same recorded golden hashes:
//
//	renderer(cloud layer 0) == recorded golden hash == renderer(go layer 0)
//
// Because both sides are compared to the same fixture, a green run means the
// two definitions are behaviourally identical — not merely similar-looking
// JSON. Props may still differ harmlessly (Cloud omits an empty `i18n` where
// Go writes `{}`, Cloud states `enabled: true` where Go leaves it absent);
// anything that would move a single byte on paper fails here.
//
// The fixture is generated, never hand-edited:
//
//	cd backend && php artisan print-templates:export-defaults \
//	  --out=../workstation-app/internal/service/testdata/cloud_print_templates_default.json
//
// If this test fails, fix the CLOUD catalog (`backend/config/print_blocks.php`
// + `config/print_templates.php`) and re-export. Never regenerate the golden
// hashes to make it pass — those hashes are the paper in the till.
const cloudDefaultsPath = "testdata/cloud_print_templates_default.json"

// loadCloudDefaults reads the exported Cloud layer 0, keyed by kind.
func loadCloudDefaults(t *testing.T) map[string]json.RawMessage {
	t.Helper()
	raw, err := os.ReadFile(cloudDefaultsPath)
	if err != nil {
		t.Fatalf("read %s: %v (regenerate with `php artisan print-templates:export-defaults`)", cloudDefaultsPath, err)
	}
	var byKind map[string]json.RawMessage
	if err := json.Unmarshal(raw, &byKind); err != nil {
		t.Fatalf("parse %s: %v", cloudDefaultsPath, err)
	}
	if len(byKind) == 0 {
		t.Fatalf("%s is empty", cloudDefaultsPath)
	}
	return byKind
}

// TestCloudDefaults_ParseAsDefinitions is the cheap half of the gate: every
// exported kind must survive ParsePrintTemplateDefinition at all.
//
// This is not a formality. PHP cannot tell an empty map from an empty list, so
// `'i18n' => []` encoded as `[]` and Go rejected the WHOLE definition with
// "cannot unmarshal array into Go struct field .i18n". The workstation then did
// the safe thing (TR-14) and printed from its embedded default — meaning every
// brand and shop edit would have been silently discarded while the registry
// looked perfectly healthy from Cloud's side. See DefinitionNormalizer.
func TestCloudDefaults_ParseAsDefinitions(t *testing.T) {
	for kind, raw := range loadCloudDefaults(t) {
		t.Run(kind, func(t *testing.T) {
			def, err := ParsePrintTemplateDefinition(raw)
			if err != nil {
				t.Fatalf("cloud default for %q does not parse: %v", kind, err)
			}
			if len(def.Blocks) == 0 {
				t.Fatalf("cloud default for %q has no blocks", kind)
			}
		})
	}
}

// TestCloudDefaults_MatchGoDefaults_BlockOrder pins the ORDER independently of
// the byte gate, so a failure names the block that moved instead of leaving a
// human to diff two ESC/POS streams.
//
// Order is not cosmetic here: `blocks` is a list, the renderer walks it in
// sequence, and the merge in TR-02 uses the BASE's order as the defence against
// an overlay reordering the compliance blocks. If Cloud's order drifts, so does
// the floor under every brand definition derived from it.
func TestCloudDefaults_MatchGoDefaults_BlockOrder(t *testing.T) {
	cloud := loadCloudDefaults(t)

	for _, kind := range SystemPrintTemplateKinds() {
		t.Run(kind, func(t *testing.T) {
			raw, ok := cloud[kind]
			if !ok {
				t.Fatalf("cloud ships no default for kind %q", kind)
			}
			cloudDef, err := ParsePrintTemplateDefinition(raw)
			if err != nil {
				t.Fatalf("parse cloud %q: %v", kind, err)
			}
			goDef, err := SystemPrintTemplate(kind)
			if err != nil {
				t.Fatalf("embedded default for %q: %v", kind, err)
			}

			want := blockIDs(goDef)
			got := blockIDs(cloudDef)
			if len(want) != len(got) {
				t.Fatalf("block count differs for %q\n  go   : %v\n  cloud: %v", kind, want, got)
			}
			for i := range want {
				if want[i] != got[i] {
					t.Fatalf("block %d differs for %q: go %q, cloud %q\n  go   : %v\n  cloud: %v",
						i, kind, want[i], got[i], want, got)
				}
			}
		})
	}
}

// TestCloudDefaults_Golden_ByteParity is the gate proper: Cloud's layer 0,
// rendered by the production renderer, over the full 13 × 3 locales × 3 widths
// matrix, must hash to the recorded golden bytes.
func TestCloudDefaults_Golden_ByteParity(t *testing.T) {
	restore := freezePrintClock(t)
	defer restore()

	recorded := map[string]string{}
	raw, err := os.ReadFile(printGoldenPath)
	if err != nil {
		t.Fatalf("read %s: %v", printGoldenPath, err)
	}
	if err := json.Unmarshal(raw, &recorded); err != nil {
		t.Fatalf("parse %s: %v", printGoldenPath, err)
	}

	cloud := loadCloudDefaults(t)

	for _, tc := range goldenMatrix() {
		t.Run(fmt.Sprintf("%s/%s/%dcol", goldenKindLabel(tc), tc.Locale, tc.Paper), func(t *testing.T) {
			defRaw, ok := cloud[tc.Kind]
			if !ok {
				t.Fatalf("cloud ships no default for kind %q", tc.Kind)
			}
			def, err := ParsePrintTemplateDefinition(defRaw)
			if err != nil {
				t.Fatalf("parse cloud %q: %v", tc.Kind, err)
			}

			// #1493 — fixture phải mang QUỐC GIA, nếu không nó mô tả một cửa hàng
			// không tồn tại và hai renderer rẽ theo hai tín hiệu khác nhau.
			cfg := goldenConfigFor(tc.Kind, tc.Locale, tc.Paper)
			res, err := RenderPrintTemplate(def, goldenRenderDataFor(tc, cfg), PrintRenderProfile{Columns: tc.Paper}, tc.Locale)
			if err != nil {
				t.Fatalf("render cloud %q: %v", tc.Kind, err)
			}

			sum := sha256.Sum256(res.Bytes())
			got := hex.EncodeToString(sum[:])

			key := goldenKey(tc)
			want, ok := recorded[key]
			if !ok {
				t.Fatalf("golden %s missing from %s", key, printGoldenPath)
			}
			if want != got {
				// Re-render the workstation's own default so the failure shows
				// the actual byte divergence rather than two opaque digests.
				goDef, gerr := SystemPrintTemplate(tc.Kind)
				if gerr != nil {
					t.Fatalf("cloud default for %s differs from golden (%s != %s)", key, got, want)
				}
				goRes, gerr := RenderPrintTemplate(goDef, goldenRenderDataFor(tc, cfg), PrintRenderProfile{Columns: tc.Paper}, tc.Locale)
				if gerr != nil {
					t.Fatalf("cloud default for %s differs from golden (%s != %s)", key, got, want)
				}
				t.Fatalf("#1181 cloud/Go parity FAILED for %s\n%s",
					key, diffBytes(goRes.Bytes(), res.Bytes()))
			}
		})
	}
}

// TestCloudDefaults_CoverEveryKind guards the other direction: a kind the
// workstation can render but Cloud ships no default for would leave a brand
// with nothing to edit, and a kind Cloud ships that the workstation cannot
// render would be an unprintable template nobody discovers until a shop
// publishes it.
func TestCloudDefaults_CoverEveryKind(t *testing.T) {
	cloud := loadCloudDefaults(t)

	for _, kind := range SystemPrintTemplateKinds() {
		if _, ok := cloud[kind]; !ok {
			t.Errorf("workstation ships a default for %q but cloud does not", kind)
		}
	}
	var extra []string
	for kind := range cloud {
		if _, ok := printKindPlans[kind]; !ok {
			extra = append(extra, kind)
		}
	}
	sort.Strings(extra)
	for _, kind := range extra {
		t.Errorf("cloud ships a default for %q but the workstation has no renderer for it", kind)
	}
}

func blockIDs(d *PrintTemplateDefinition) []string {
	out := make([]string, 0, len(d.Blocks))
	for i := range d.Blocks {
		out = append(out, d.Blocks[i].ID)
	}
	return out
}
