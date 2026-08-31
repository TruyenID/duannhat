//go:build windows

package service

import (
	"syscall"
	"unsafe"
)

// The shop fleet runs Windows, so this is the branch that matters in
// production. GetDiskFreeSpaceExW's first out-parameter is the quota-aware
// figure for the calling user, which is the one a write will actually hit.
var (
	kernel32                = syscall.NewLazyDLL("kernel32.dll")
	procGetDiskFreeSpaceExW = kernel32.NewProc("GetDiskFreeSpaceExW")
)

func diskFreeBytes(dir string) (uint64, error) {
	p, err := syscall.UTF16PtrFromString(dir)
	if err != nil {
		return 0, err
	}
	var freeToCaller, totalBytes, totalFree uint64
	r, _, callErr := procGetDiskFreeSpaceExW.Call(
		uintptr(unsafe.Pointer(p)),
		uintptr(unsafe.Pointer(&freeToCaller)),
		uintptr(unsafe.Pointer(&totalBytes)),
		uintptr(unsafe.Pointer(&totalFree)),
	)
	if r == 0 {
		return 0, callErr
	}
	return freeToCaller, nil
}
