package update

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"strings"
)

func downloadAndVerify(
	ctx context.Context,
	client *http.Client,
	updatesRoot, expected string,
	pkg *Package,
	goos, goarch string,
	onProgress func(int),
) (string, error) {
	ver := safeVersion(expected)
	if ver == "" {
		return "", fmt.Errorf("invalid expected version %q", expected)
	}
	platformID, err := MustPlatformID(goos, goarch)
	if err != nil {
		return "", err
	}
	plat, err := findPlatform(pkg, platformID)
	if err != nil {
		return "", err
	}
	if strings.TrimSpace(plat.URL) == "" {
		return "", fmt.Errorf("package platform %s has empty url", platformID)
	}
	wantSHA := strings.ToLower(strings.TrimSpace(plat.SHA256))
	if wantSHA == "" {
		return "", fmt.Errorf("package platform %s missing sha256", platformID)
	}

	dir := filepath.Join(updatesRoot, ver)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", fmt.Errorf("create updates dir: %w", err)
	}

	filename := filenameFromURL(plat.URL, platformID)
	finalPath := filepath.Join(dir, filename)
	partialPath := finalPath + ".partial"

	// Already staged + still matches hash? Reuse.
	if fileExists(finalPath) {
		if ok, _ := verifyFileSHA256(finalPath, wantSHA); ok {
			if onProgress != nil {
				onProgress(100)
			}
			return finalPath, nil
		}
		_ = os.Remove(finalPath)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, plat.URL, nil)
	if err != nil {
		return "", err
	}
	resp, err := client.Do(req)
	if err != nil {
		return "", fmt.Errorf("download: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("download: unexpected status %d", resp.StatusCode)
	}

	tmp, err := os.OpenFile(partialPath, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return "", fmt.Errorf("open partial: %w", err)
	}

	hasher := sha256.New()
	writer := io.MultiWriter(tmp, hasher)

	total := resp.ContentLength
	if total <= 0 && plat.Size > 0 {
		total = plat.Size
	}
	var written int64
	buf := make([]byte, 32*1024)
	for {
		n, readErr := resp.Body.Read(buf)
		if n > 0 {
			if _, werr := writer.Write(buf[:n]); werr != nil {
				tmp.Close()
				_ = os.Remove(partialPath)
				return "", werr
			}
			written += int64(n)
			if onProgress != nil && total > 0 {
				pct := int(written * 100 / total)
				if pct > 99 {
					pct = 99
				}
				onProgress(pct)
			}
		}
		if readErr == io.EOF {
			break
		}
		if readErr != nil {
			tmp.Close()
			_ = os.Remove(partialPath)
			return "", fmt.Errorf("download read: %w", readErr)
		}
	}
	if err := tmp.Close(); err != nil {
		_ = os.Remove(partialPath)
		return "", err
	}

	got := hex.EncodeToString(hasher.Sum(nil))
	if got != wantSHA {
		_ = os.Remove(partialPath)
		return "", fmt.Errorf("sha256 mismatch: got %s want %s", got, wantSHA)
	}

	if err := os.Chmod(partialPath, 0o755); err != nil {
		_ = os.Remove(partialPath)
		return "", fmt.Errorf("chmod staged: %w", err)
	}
	if err := os.Rename(partialPath, finalPath); err != nil {
		_ = os.Remove(partialPath)
		return "", fmt.Errorf("atomic rename staged: %w", err)
	}
	if onProgress != nil {
		onProgress(100)
	}
	return finalPath, nil
}

func findPlatform(pkg *Package, id string) (Platform, error) {
	if pkg == nil {
		return Platform{}, fmt.Errorf("package missing")
	}
	for _, p := range pkg.Platforms {
		if p.ID == id {
			return p, nil
		}
	}
	return Platform{}, fmt.Errorf("package has no platform %s", id)
}

// filenameFromURL names the staged artifact after the catalog URL's last path
// element. It must resolve to a plain file INSIDE <updates>/<version>/: a
// catalog entry ending in `..` would otherwise make filepath.Join climb out of
// the version directory and write beside the updates root.
func filenameFromURL(raw, platformID string) string {
	u, err := url.Parse(raw)
	if err == nil {
		base := path.Base(u.Path)
		if base != "" && base != "." && base != ".." && base != "/" &&
			!strings.ContainsAny(base, `/\`) {
			return base
		}
	}
	return "ws-server-" + platformID
}

func verifyFileSHA256(path, wantHex string) (bool, error) {
	f, err := os.Open(path)
	if err != nil {
		return false, err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return false, err
	}
	got := hex.EncodeToString(h.Sum(nil))
	return got == strings.ToLower(strings.TrimSpace(wantHex)), nil
}
