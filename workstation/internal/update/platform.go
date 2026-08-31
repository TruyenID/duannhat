package update

import "fmt"

// PlatformID maps a Go runtime (GOOS/GOARCH) onto the id used in the
// workstation download catalog / expected-build package payload.
//
// Unknown combinations return "" so callers can fail closed rather than
// inventing a URL that will 404.
func PlatformID(goos, goarch string) string {
	switch goos + "/" + goarch {
	case "linux/amd64":
		return "linux-amd64"
	case "linux/arm64":
		return "linux-arm64"
	case "darwin/amd64":
		return "darwin-amd64"
	case "darwin/arm64":
		return "darwin-arm64"
	case "windows/amd64":
		return "windows-amd64.exe"
	default:
		return ""
	}
}

// MustPlatformID is like PlatformID but returns a descriptive error when the
// current platform is not published in the catalog.
func MustPlatformID(goos, goarch string) (string, error) {
	id := PlatformID(goos, goarch)
	if id == "" {
		return "", fmt.Errorf("unsupported platform %s/%s for assisted update", goos, goarch)
	}
	return id, nil
}
