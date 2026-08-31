package printer

import "testing"

// TestValidateAddress locks the #85 fix: printer addresses that would let a
// LAN caller drive net.DialTimeout to arbitrary internet hosts (SSRF/port
// scan) or os.OpenFile arbitrary paths (file corruption) are refused.
func TestValidateAddress(t *testing.T) {
	cases := []struct {
		name     string
		connType ConnectionType
		address  string
		wantErr  bool
	}{
		// network — allowed (private / loopback / mDNS)
		{"net private ip", ConnNetwork, "192.168.1.50:9100", false},
		{"net class-a private", ConnNetwork, "10.0.0.5:9100", false},
		{"net loopback", ConnNetwork, "127.0.0.1:9100", false},
		{"net mdns .local", ConnNetwork, "star-printer.local:9100", false},
		// network — refused (SSRF / port-scan)
		{"net public ip", ConnNetwork, "1.2.3.4:9100", true},
		{"net public ip 8888", ConnNetwork, "8.8.8.8:53", true},
		{"net public hostname", ConnNetwork, "metadata.google.internal:80", true},
		{"net missing port", ConnNetwork, "192.168.1.50", true},
		{"net bad port", ConnNetwork, "192.168.1.50:0", true},
		{"net empty", ConnNetwork, "", true},
		// usb — allowed (device nodes)
		{"usb linux lp", ConnUSB, "/dev/usb/lp0", false},
		{"usb linux usblp", ConnUSB, "/dev/usblp0", false},
		{"usb macos cu", ConnUSB, "/dev/cu.usbserial", false},
		{"usb windows com", ConnUSB, "COM3", false},
		// usb — refused (arbitrary file / traversal)
		{"usb etc passwd", ConnUSB, "/etc/passwd", true},
		{"usb traversal", ConnUSB, "/dev/../etc/passwd", true},
		{"usb home file", ConnUSB, "/Users/victim/.ssh/id_rsa", true},
		{"usb empty", ConnUSB, "", true},
		// unknown connection type
		{"unknown type", ConnectionType("carrier-pigeon"), "x", true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			err := ValidateAddress(tc.connType, tc.address)
			if tc.wantErr && err == nil {
				t.Errorf("ValidateAddress(%s, %q): want error, got nil", tc.connType, tc.address)
			}
			if !tc.wantErr && err != nil {
				t.Errorf("ValidateAddress(%s, %q): want nil, got %v", tc.connType, tc.address, err)
			}
		})
	}
}
