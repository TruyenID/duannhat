package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestParseEnvLine(t *testing.T) {
	tests := []struct {
		name    string
		line    string
		wantKey string
		wantVal string
		wantOK  bool
	}{
		{"simple", "FOO=bar", "FOO", "bar", true},
		{"spaces around equals", "FOO = bar", "FOO", "bar", true},
		{"export prefix", "export FOO=bar", "FOO", "bar", true},
		{"empty value", "FOO=", "FOO", "", true},
		{"blank line", "", "", "", false},
		{"comment", "# FOO=bar", "", "", false},
		{"indented comment", "   # comment", "", "", false},
		{"no equals", "FOO", "", "", false},
		{"leading equals", "=bar", "", "", false},
		{"prose with equals", "this is not = a var", "", "", false},
		{"digit-leading key", "1FOO=bar", "", "", false},
		{"underscore key", "_FOO_BAR=baz", "_FOO_BAR", "baz", true},

		// The Windows trap: a CRLF file leaves \r glued to the value.
		{"CRLF", "FOO=http://host:8080\r", "FOO", "http://host:8080", true},
		{"CRLF quoted", "FOO=\"http://host:8080\"\r", "FOO", "http://host:8080", true},

		// Quoting
		{"double quoted", `FOO="bar baz"`, "FOO", "bar baz", true},
		{"single quoted", `FOO='bar baz'`, "FOO", "bar baz", true},
		{"double quoted escapes", `FOO="a\nb\tc"`, "FOO", "a\nb\tc", true},
		{"double quoted inner quote", `FOO="say \"hi\""`, "FOO", `say "hi"`, true},
		{"single quotes are literal", `FOO='a\nb'`, "FOO", `a\nb`, true},
		{"quoted preserves hash", `FOO="a#b"`, "FOO", "a#b", true},
		{"windows path unquoted-escape kept", `FOO="C:\shop\data"`, "FOO", `C:\shop\data`, true},

		// Inline comments (unquoted only)
		{"inline comment", "FOO=bar # note", "FOO", "bar", true},
		{"inline comment tab", "FOO=bar\t# note", "FOO", "bar", true},
		{"hash without space is part of value", "FOO=bar#baz", "FOO", "bar#baz", true},
		{"url fragment survives", "FOO=http://h/p#frag", "FOO", "http://h/p#frag", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			key, val, ok := parseEnvLine(tt.line)
			if ok != tt.wantOK {
				t.Fatalf("ok = %v, want %v (line %q)", ok, tt.wantOK, tt.line)
			}
			if !ok {
				return
			}
			if key != tt.wantKey {
				t.Errorf("key = %q, want %q", key, tt.wantKey)
			}
			if val != tt.wantVal {
				t.Errorf("value = %q, want %q", val, tt.wantVal)
			}
		})
	}
}

// The real environment must beat the file, otherwise `WS_APP_CLOUD_URL=x make dev`
// and the Taskfile dev overrides would be silently ignored on a machine that has
// a production .env sitting next to the binary.
func TestLoadEnvFile_RealEnvironmentWins(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env")
	content := "WS_APP_TEST_PRESET=from-file\nWS_APP_TEST_FRESH=from-file\n"
	if err := os.WriteFile(path, []byte(content), 0600); err != nil {
		t.Fatal(err)
	}

	t.Setenv("WS_APP_TEST_PRESET", "from-environment")
	os.Unsetenv("WS_APP_TEST_FRESH")
	t.Cleanup(func() { os.Unsetenv("WS_APP_TEST_FRESH") })

	applied, err := loadEnvFile(path)
	if err != nil {
		t.Fatalf("loadEnvFile: %v", err)
	}
	if applied != 1 {
		t.Errorf("applied = %d, want 1 (only the unset var)", applied)
	}
	if got := os.Getenv("WS_APP_TEST_PRESET"); got != "from-environment" {
		t.Errorf("preset var = %q, want the real environment to win", got)
	}
	if got := os.Getenv("WS_APP_TEST_FRESH"); got != "from-file" {
		t.Errorf("fresh var = %q, want %q", got, "from-file")
	}
}

// A UTF-8 BOM (what Notepad and PowerShell's Out-File emit by default) must not
// become part of the first key — that would silently drop the first setting.
func TestLoadEnvFile_StripsBOMAndHandlesCRLF(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env")
	content := "\ufeffWS_APP_TEST_BOM=http://192.168.1.50:8080\r\nWS_APP_TEST_SECOND=ok\r\n"
	if err := os.WriteFile(path, []byte(content), 0600); err != nil {
		t.Fatal(err)
	}

	os.Unsetenv("WS_APP_TEST_BOM")
	os.Unsetenv("WS_APP_TEST_SECOND")
	t.Cleanup(func() {
		os.Unsetenv("WS_APP_TEST_BOM")
		os.Unsetenv("WS_APP_TEST_SECOND")
	})

	if _, err := loadEnvFile(path); err != nil {
		t.Fatalf("loadEnvFile: %v", err)
	}
	if got := os.Getenv("WS_APP_TEST_BOM"); got != "http://192.168.1.50:8080" {
		t.Errorf("BOM/CRLF value = %q, want a clean URL", got)
	}
	if got := os.Getenv("WS_APP_TEST_SECOND"); got != "ok" {
		t.Errorf("second value = %q, want %q", got, "ok")
	}
}

// A malformed line must be skipped, not abort the load — a typo in the shop's
// .env must never stop the workstation from booting.
func TestLoadEnvFile_SkipsMalformedLines(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ".env")
	content := "this line is nonsense\n\n# comment\n1BAD=x\nWS_APP_TEST_GOOD=yes\n"
	if err := os.WriteFile(path, []byte(content), 0600); err != nil {
		t.Fatal(err)
	}

	os.Unsetenv("WS_APP_TEST_GOOD")
	t.Cleanup(func() { os.Unsetenv("WS_APP_TEST_GOOD") })

	applied, err := loadEnvFile(path)
	if err != nil {
		t.Fatalf("loadEnvFile: %v", err)
	}
	if applied != 1 {
		t.Errorf("applied = %d, want 1", applied)
	}
	if got := os.Getenv("WS_APP_TEST_GOOD"); got != "yes" {
		t.Errorf("good var = %q, want %q", got, "yes")
	}
}

func TestLoadDotEnv_ExplicitOverrideWins(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "custom.env")
	if err := os.WriteFile(path, []byte("WS_APP_TEST_EXPLICIT=yes\n"), 0600); err != nil {
		t.Fatal(err)
	}

	t.Setenv(envFileOverride, path)
	os.Unsetenv("WS_APP_TEST_EXPLICIT")
	t.Cleanup(func() { os.Unsetenv("WS_APP_TEST_EXPLICIT") })

	if used := LoadDotEnv(); used != path {
		t.Fatalf("LoadDotEnv() = %q, want %q", used, path)
	}
	if got := os.Getenv("WS_APP_TEST_EXPLICIT"); got != "yes" {
		t.Errorf("value = %q, want %q", got, "yes")
	}
}

// Missing files are the normal case in development and must be silent.
func TestLoadDotEnv_NoFileIsNotAnError(t *testing.T) {
	t.Setenv(envFileOverride, filepath.Join(t.TempDir(), "does-not-exist.env"))
	t.Setenv("WS_APP_CONFIG_DIR", t.TempDir())

	// Run from a scratch dir so a stray ./.env in the repo can't satisfy the
	// final search entry.
	wd, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}
	if err := os.Chdir(t.TempDir()); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.Chdir(wd) })

	if used := LoadDotEnv(); used != "" {
		t.Errorf("LoadDotEnv() = %q, want \"\" when nothing exists", used)
	}
}
