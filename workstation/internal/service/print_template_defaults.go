package service

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"sort"
	"sync"
)

// plan-053 M3 (#1171) — LAYER 0, shipped INSIDE THE BINARY (TR-05).
//
// A workstation that has never been online — a machine unboxed this morning, or
// one whose cache was wiped — must still print. Only a definition compiled into
// the software can promise that; a seeded Cloud row cannot reach a machine that
// has never talked to Cloud.
//
// These definitions are the SAME slips the hard-coded formatters produce today,
// expressed as templates. print_renderer_golden_test.go proves that claim byte
// for byte per kind (TR-40): the day the registry ships, no shop's slip changes.
//
// They intentionally mirror Cloud's `SystemTemplateDefaults` (composed from
// `config/print_blocks.php` + `config/print_templates.php`) — where the two
// disagree today, the divergence is listed in docs/guide/print-templates.md
// under "catalog gaps", because Cloud's copy is the one a brand's first publish
// starts from and a mismatch there WOULD change slips.

//go:embed print_templates_default.json
var systemPrintTemplatesJSON []byte

var (
	systemPrintTemplatesOnce sync.Once
	systemPrintTemplates     map[string]*PrintTemplateDefinition
	systemPrintTemplatesRaw  map[string]json.RawMessage
	systemPrintTemplatesErr  error
)

func loadSystemPrintTemplates() {
	systemPrintTemplatesOnce.Do(func() {
		raw := map[string]json.RawMessage{}
		if err := json.Unmarshal(systemPrintTemplatesJSON, &raw); err != nil {
			systemPrintTemplatesErr = fmt.Errorf("embedded system print templates: %w", err)
			return
		}
		defs := make(map[string]*PrintTemplateDefinition, len(raw))
		for kind, body := range raw {
			def, err := ParsePrintTemplateDefinition(body)
			if err != nil {
				systemPrintTemplatesErr = fmt.Errorf("embedded system print template %q: %w", kind, err)
				return
			}
			if def.Kind == "" {
				def.Kind = kind
			}
			defs[kind] = def
		}
		systemPrintTemplates = defs
		systemPrintTemplatesRaw = raw
	})
}

// SystemPrintTemplate returns the embedded layer-0 definition for a kind.
//
// The returned pointer is SHARED: definitions are immutable at runtime (the
// renderer never writes to one), and re-parsing 13 JSON documents on every
// kitchen ticket would be a needless allocation on the hot path.
func SystemPrintTemplate(kind string) (*PrintTemplateDefinition, error) {
	loadSystemPrintTemplates()
	if systemPrintTemplatesErr != nil {
		return nil, systemPrintTemplatesErr
	}
	def, ok := systemPrintTemplates[kind]
	if !ok {
		return nil, fmt.Errorf("no system print template for kind %q", kind)
	}
	return def, nil
}

// SystemPrintTemplateRaw returns the embedded definition's JSON bytes — what a
// cache row would hold if Cloud had sent it. Used to seed a cold cache and by
// the checksum tests.
func SystemPrintTemplateRaw(kind string) ([]byte, error) {
	loadSystemPrintTemplates()
	if systemPrintTemplatesErr != nil {
		return nil, systemPrintTemplatesErr
	}
	body, ok := systemPrintTemplatesRaw[kind]
	if !ok {
		return nil, fmt.Errorf("no system print template for kind %q", kind)
	}
	return append([]byte(nil), body...), nil
}

// SystemPrintTemplateKinds lists every kind the binary ships a default for,
// sorted so tests and logs are deterministic.
func SystemPrintTemplateKinds() []string {
	loadSystemPrintTemplates()
	out := make([]string, 0, len(systemPrintTemplates))
	for k := range systemPrintTemplates {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}
