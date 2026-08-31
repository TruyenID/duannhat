package update

import (
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
)

var (
	errNotReady = errors.New("update is not staged and ready to apply")
	// errApplyInFlight guards the binary swap. Without it two overlapping
	// Apply calls both pass the precondition, and the second one's
	// `rm .bak` + `rename current→.bak` destroys the first one's backup —
	// leaving the shop with a new binary and NO rollback copy.
	errApplyInFlight = errors.New("an update is already being applied")
)

// IsApplyInFlight reports whether Apply refused because another apply is
// already running.
func IsApplyInFlight(err error) bool { return errors.Is(err, errApplyInFlight) }

type dirNotWritableError struct{ msg string }

func (e dirNotWritableError) Error() string { return e.msg }

func errDirNotWritable(msg string) error {
	return dirNotWritableError{msg: msg}
}

// IsDirNotWritable reports whether Apply failed because the executable
// directory cannot be written (common for system installs / root-owned dirs).
func IsDirNotWritable(err error) bool {
	var e dirNotWritableError
	return errors.As(err, &e)
}

func resolveExecutable(fn func() (string, error)) (string, error) {
	if fn != nil {
		return fn()
	}
	exe, err := os.Executable()
	if err != nil {
		return "", err
	}
	return filepath.EvalSymlinks(exe)
}

func executableDirWritable(fn func() (string, error)) (bool, string) {
	exe, err := resolveExecutable(fn)
	if err != nil {
		return false, err.Error()
	}
	dir := filepath.Dir(exe)
	f, err := os.CreateTemp(dir, ".ws-update-write-*")
	if err != nil {
		return false, err.Error()
	}
	name := f.Name()
	_ = f.Close()
	_ = os.Remove(name)
	return true, ""
}

// replaceBinary swaps the running executable for the staged file using the
// classic .new / .bak rename dance so Windows can replace a running binary
// (Windows allows rename of a locked executable; overwrite does not).
func replaceBinary(current, staged string) error {
	newPath := current + ".new"
	bakPath := current + ".bak"

	if err := copyFile(staged, newPath, 0o755); err != nil {
		return fmt.Errorf("write .new: %w", err)
	}

	// Best-effort cleanup of a previous leftover backup.
	_ = os.Remove(bakPath)

	if err := os.Rename(current, bakPath); err != nil {
		_ = os.Remove(newPath)
		return fmt.Errorf("rename current→.bak: %w", err)
	}
	if err := os.Rename(newPath, current); err != nil {
		// Try to restore the previous binary so the shop is not left without one.
		_ = os.Rename(bakPath, current)
		_ = os.Remove(newPath)
		return fmt.Errorf("rename .new→current: %w", err)
	}
	_ = os.Chmod(current, 0o755)
	return nil
}

func copyFile(src, dst string, mode os.FileMode) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	out, err := os.OpenFile(dst, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, mode)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		_ = os.Remove(dst)
		return err
	}
	if err := out.Close(); err != nil {
		_ = os.Remove(dst)
		return err
	}
	return os.Chmod(dst, mode)
}

func restartProcess(exe string, args, env []string) error {
	_, err := spawnReplacement(exe, args, env)
	return err
}

// spawnReplacement starts the replacement process and returns a handle so a
// supervised restart (#2635) can kill a child whose boot never became healthy.
// The plain restart path discards the handle — the parent exits immediately.
func spawnReplacement(exe string, args, env []string) (Process, error) {
	argv := args
	if len(argv) == 0 {
		argv = []string{exe}
	} else {
		// Preserve argv[0] semantics but force the new path as the process image.
		argv = append([]string{exe}, argv[1:]...)
	}
	cmd := exec.Command(exe, argv[1:]...)
	cmd.Env = env
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	cmd.Stdin = os.Stdin
	if dir := filepath.Dir(exe); dir != "" {
		cmd.Dir = dir
	}
	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("spawn replacement process: %w", err)
	}
	return cmd.Process, nil
}

// rollbackBinary undoes a replaceBinary AFTER the swap succeeded but the new
// binary proved dead on boot: the current path holds the NEW build, `.bak`
// holds the one that was running. The failed build is kept next to the
// executable as `.failed` — evidence for whoever debugs the release, and it
// keeps the rename dance identical to the forward path (rename, never
// overwrite, so Windows can move a recently-executed file).
func rollbackBinary(current string) error {
	bakPath := current + ".bak"
	if _, err := os.Stat(bakPath); err != nil {
		return fmt.Errorf("no .bak to roll back to: %w", err)
	}
	failedPath := current + ".failed"
	_ = os.Remove(failedPath)
	if err := os.Rename(current, failedPath); err != nil {
		return fmt.Errorf("rename current→.failed: %w", err)
	}
	if err := os.Rename(bakPath, current); err != nil {
		// Try to put the (broken but present) binary back — a shop with a dead
		// binary on the right path beats a shop with no binary at all.
		_ = os.Rename(failedPath, current)
		return fmt.Errorf("rename .bak→current: %w", err)
	}
	_ = os.Chmod(current, 0o755)
	return nil
}
