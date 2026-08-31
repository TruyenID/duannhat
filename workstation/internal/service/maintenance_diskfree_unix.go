//go:build !windows

package service

import "syscall"

// diskFreeBytes reports the space available to an unprivileged process on the
// filesystem holding dir. Bavail (not Bfree) is the right field: the reserved
// blocks root can still use are not room this process has.
func diskFreeBytes(dir string) (uint64, error) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(dir, &st); err != nil {
		return 0, err
	}
	return uint64(st.Bavail) * uint64(st.Bsize), nil
}
