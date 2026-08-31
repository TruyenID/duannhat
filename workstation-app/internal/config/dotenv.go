package config

// dotenv.go — minimal .env loader.
//
// The app is installed on unattended shop PCs where nobody sets process
// environment variables by hand, so the deployment knobs (cloud URL, port,
// LAN IP override) need a file the installer can drop next to the binary.
//
// Deliberately hand-rolled instead of pulling in a dependency: the format we
// accept is small and fully specified below, and the Windows-specific traps
// (CRLF, BOM) are exactly what a generic parser tends to get wrong for us.
//
// Format accepted:
//
//	# comment line
//	KEY=value                 # trailing comment (unquoted values only)
//	export KEY=value          # `export ` prefix is tolerated
//	KEY="quoted value"        # \n \r \t \" \\ escapes honoured
//	KEY='raw value'           # no escaping, no interpolation
//	KEY=                      # empty value
//
// Precedence: a variable already present in the real process environment ALWAYS
// wins. The file only fills gaps. That keeps `WS_APP_CLOUD_URL=… ws-app.exe`
// (and the Makefile/Taskfile dev overrides) authoritative over a stale .env.

import (
	"bufio"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
)

// envFileName is the file looked for in each search location.
const envFileName = ".env"

// envFileOverride names an explicit path, bypassing the search order entirely.
const envFileOverride = "WS_APP_ENV_FILE"

// LoadDotEnv finds and applies the first .env file it can read, and returns the
// path it used ("" when none was found — not an error; running without a .env
// is the normal case for development).
//
// Search order, first hit wins:
//
//  1. $WS_APP_ENV_FILE           — explicit path, for installers/services
//  2. <dir of the executable>/.env — how a packaged install ships its config
//  3. <config dir>/.env           — ~/.ws-app/.env, editable post-install
//  4. ./.env                      — developer convenience
//
// The executable directory comes before the config dir so that reinstalling
// with a new .env takes effect without anyone having to clean ~/.ws-app.
//
// MUST be called before NewManager(): the config manager reads these variables
// during construction, and on first run persists the resolved values into
// config.json.
func LoadDotEnv() string {
	for _, path := range envFilePaths() {
		if path == "" {
			continue
		}
		applied, err := loadEnvFile(path)
		if err != nil {
			if !os.IsNotExist(err) {
				slog.Warn("env file unreadable; skipping", "path", path, "err", err)
			}
			continue
		}
		slog.Info("env file loaded", "path", path, "applied", applied)
		return path
	}
	return ""
}

func envFilePaths() []string {
	paths := []string{os.Getenv(envFileOverride)}

	if exe, err := os.Executable(); err == nil {
		// Resolve symlinks so a /usr/local/bin/ws-app -> /opt/... install reads
		// the .env next to the REAL binary, not next to the symlink.
		if resolved, err := filepath.EvalSymlinks(exe); err == nil {
			exe = resolved
		}
		paths = append(paths, filepath.Join(filepath.Dir(exe), envFileName))
	}

	// configDir() honours WS_APP_CONFIG_DIR, which may itself only be set by a
	// .env we have not loaded yet. That is intentional and documented: the
	// config-dir override has to come from the real environment.
	if dir, err := configDir(); err == nil {
		paths = append(paths, filepath.Join(dir, envFileName))
	}

	return append(paths, envFileName)
}

// loadEnvFile parses path and sets any variable not already in the environment.
// Returns how many variables it actually applied.
func loadEnvFile(path string) (int, error) {
	f, err := os.Open(path)
	if err != nil {
		return 0, err
	}
	defer f.Close()

	applied := 0
	scanner := bufio.NewScanner(f)
	// Values are short, but a generous cap beats a silent truncation.
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	first := true
	for scanner.Scan() {
		line := scanner.Text()
		if first {
			// Notepad and PowerShell's Out-File write a UTF-8 BOM, which would
			// otherwise become part of the first key name.
			line = strings.TrimPrefix(line, "\ufeff")
			first = false
		}

		key, value, ok := parseEnvLine(line)
		if !ok {
			continue
		}
		if _, exists := os.LookupEnv(key); exists {
			continue // real environment wins
		}
		if err := os.Setenv(key, value); err != nil {
			slog.Warn("env file: setenv failed", "key", key, "err", err)
			continue
		}
		applied++
	}
	if err := scanner.Err(); err != nil {
		return applied, err
	}
	return applied, nil
}

// parseEnvLine splits one line into key/value. ok is false for blank lines,
// comments, and anything malformed (which is skipped, not fatal — a typo in one
// line must not stop the shop PC from booting).
func parseEnvLine(line string) (key, value string, ok bool) {
	// bufio.Scanner strips \n but leaves \r from CRLF files. Left in place it
	// ends up INSIDE the value: "http://host:8080\r" — a URL that fails in a
	// way that is genuinely hard to see in logs.
	line = strings.TrimRight(line, "\r")
	line = strings.TrimSpace(line)

	if line == "" || strings.HasPrefix(line, "#") {
		return "", "", false
	}

	line = strings.TrimPrefix(line, "export ")

	eq := strings.IndexByte(line, '=')
	if eq <= 0 {
		return "", "", false
	}

	key = strings.TrimSpace(line[:eq])
	if !validEnvKey(key) {
		return "", "", false
	}

	raw := strings.TrimSpace(line[eq+1:])
	return key, parseEnvValue(raw), true
}

func parseEnvValue(raw string) string {
	if len(raw) >= 2 {
		switch raw[0] {
		case '\'':
			if idx := strings.IndexByte(raw[1:], '\''); idx >= 0 {
				// Single quotes are literal: no escapes, no interpolation.
				return raw[1 : idx+1]
			}
		case '"':
			if idx := indexUnescapedQuote(raw[1:]); idx >= 0 {
				return unescapeDoubleQuoted(raw[1 : idx+1])
			}
		}
	}

	// Unquoted: an inline comment ends the value. Requiring whitespace before
	// the '#' keeps values that legitimately contain one (a URL fragment, a
	// password) intact.
	if idx := strings.Index(raw, " #"); idx >= 0 {
		raw = raw[:idx]
	}
	if idx := strings.Index(raw, "\t#"); idx >= 0 {
		raw = raw[:idx]
	}
	return strings.TrimSpace(raw)
}

// indexUnescapedQuote returns the offset of the first '"' not preceded by a
// backslash, or -1.
func indexUnescapedQuote(s string) int {
	for i := 0; i < len(s); i++ {
		if s[i] != '"' {
			continue
		}
		backslashes := 0
		for j := i - 1; j >= 0 && s[j] == '\\'; j-- {
			backslashes++
		}
		if backslashes%2 == 0 {
			return i
		}
	}
	return -1
}

func unescapeDoubleQuoted(s string) string {
	if !strings.ContainsRune(s, '\\') {
		return s
	}
	var b strings.Builder
	b.Grow(len(s))
	for i := 0; i < len(s); i++ {
		if s[i] != '\\' || i+1 >= len(s) {
			b.WriteByte(s[i])
			continue
		}
		i++
		switch s[i] {
		case 'n':
			b.WriteByte('\n')
		case 'r':
			b.WriteByte('\r')
		case 't':
			b.WriteByte('\t')
		case '"', '\\', '\'':
			b.WriteByte(s[i])
		default:
			// Unknown escape: keep both bytes so a Windows path written
			// unquoted-ish ("C:\shop") survives instead of losing separators.
			b.WriteByte('\\')
			b.WriteByte(s[i])
		}
	}
	return b.String()
}

// validEnvKey keeps us from exporting garbage when a line is actually prose
// that happens to contain '='.
func validEnvKey(k string) bool {
	if k == "" {
		return false
	}
	for i := 0; i < len(k); i++ {
		c := k[i]
		switch {
		case c >= 'A' && c <= 'Z', c >= 'a' && c <= 'z', c == '_':
		case c >= '0' && c <= '9':
			if i == 0 {
				return false // a key may not start with a digit
			}
		default:
			return false
		}
	}
	return true
}
