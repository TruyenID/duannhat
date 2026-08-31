package domain

type DeviceType string

const (
	DeviceTypeHoldPrinter    DeviceType = "hold_printer"
	DeviceTypeKitchenPrinter DeviceType = "kitchen_printer"
	DeviceTypeBarPrinter     DeviceType = "bar_printer"
	DeviceTypeStaffCaller    DeviceType = "staff_caller"
	DeviceTypePOS            DeviceType = "pos"
)

type ConnectionType string

const (
	ConnUSB     ConnectionType = "usb"
	ConnNetwork ConnectionType = "network"
)

type DeviceStatus string

const (
	DeviceStatusOnline   DeviceStatus = "online"
	DeviceStatusOffline  DeviceStatus = "offline"
	DeviceStatusError    DeviceStatus = "error"
	DeviceStatusPrinting DeviceStatus = "printing"
)
