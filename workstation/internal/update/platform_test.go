package update

import "testing"

func TestPlatformID(t *testing.T) {
	cases := []struct {
		goos, goarch, want string
	}{
		{"linux", "amd64", "linux-amd64"},
		{"linux", "arm64", "linux-arm64"},
		{"darwin", "arm64", "darwin-arm64"},
		{"darwin", "amd64", "darwin-amd64"},
		{"windows", "amd64", "windows-amd64.exe"},
		{"freebsd", "amd64", ""},
		{"linux", "386", ""},
	}
	for _, c := range cases {
		if got := PlatformID(c.goos, c.goarch); got != c.want {
			t.Errorf("PlatformID(%s/%s) = %q, want %q", c.goos, c.goarch, got, c.want)
		}
	}
}
