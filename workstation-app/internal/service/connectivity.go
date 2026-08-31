package service

import (
	"log/slog"
	"net/http"
	"sync"
	"time"
)

type ConnStatus string

const (
	ConnOnline  ConnStatus = "online"
	ConnOffline ConnStatus = "offline"
)

type ConnMonitor struct {
	mu          sync.RWMutex
	status      ConnStatus
	checkURL    string
	interval    time.Duration
	maxInterval time.Duration
	stopCh      chan struct{}
	stopOnce    sync.Once
	onChange    func(ConnStatus)
}

func NewConnMonitor(cloudURL string, onChange func(ConnStatus)) *ConnMonitor {
	checkURL := cloudURL
	if checkURL == "" {
		checkURL = "https://www.google.com" // Fallback connectivity check
	}

	return &ConnMonitor{
		status:      ConnOffline,
		checkURL:    checkURL,
		interval:    10 * time.Second,
		maxInterval: 5 * time.Minute,
		stopCh:      make(chan struct{}),
		onChange:    onChange,
	}
}

func (m *ConnMonitor) Start() {
	go m.loop()
}

// Stop is safe to call from multiple owners (it sits behind SyncEngine.Stop,
// which itself is idempotent). The first call closes the stop channel and
// the loop returns; subsequent calls are no-ops.
func (m *ConnMonitor) Stop() {
	m.stopOnce.Do(func() {
		close(m.stopCh)
	})
}

func (m *ConnMonitor) Status() ConnStatus {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return m.status
}

func (m *ConnMonitor) IsOnline() bool {
	return m.Status() == ConnOnline
}

func (m *ConnMonitor) loop() {
	currentInterval := m.interval

	for {
		select {
		case <-m.stopCh:
			return
		case <-time.After(currentInterval):
			online := m.check()

			m.mu.Lock()
			prev := m.status
			if online {
				m.status = ConnOnline
				currentInterval = m.interval // Reset to normal interval
			} else {
				m.status = ConnOffline
				// Exponential backoff
				currentInterval = currentInterval * 2
				if currentInterval > m.maxInterval {
					currentInterval = m.maxInterval
				}
			}
			changed := prev != m.status
			m.mu.Unlock()

			if changed {
				slog.Info("connectivity changed", "status", m.status)
				if m.onChange != nil {
					m.onChange(m.status)
				}
			}
		}
	}
}

func (m *ConnMonitor) check() bool {
	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Head(m.checkURL)
	if err != nil {
		return false
	}
	resp.Body.Close()
	return resp.StatusCode < 500
}
