package handler

import "testing"

// #2431 left THREE resolvers for "where is Cloud" and only unified two.
// cloud_proxy.go's cloudBaseURL() kept a config-FIRST ladder, so after an
// operator repointed Cloud in Settings the workstation paired against one host
// while everything routed through proxyToCloud went to another.
func TestCloudBaseURL_SharesTheSingleLadder(t *testing.T) {
	s := &Server{db: newTestDB(t), config: newConfigManager(t, "https://config.example.test")}
	setSetting(t, s, "cloud_api_url", "https://settings.example.test")

	if got, want := s.cloudBaseURL(), s.cloudAPIURL(); got != want {
		t.Fatalf("cloudBaseURL = %q, cloudAPIURL = %q — the two must agree", got, want)
	}
	if got := s.cloudBaseURL(); got != "https://settings.example.test" {
		t.Fatalf("cloudBaseURL = %q, want the settings row to win", got)
	}
}

// Pre-pair, the settings row is empty and the config fallback is what makes the
// proxy usable at all.
func TestCloudBaseURL_FallsBackToConfigBeforePairing(t *testing.T) {
	s := &Server{db: newTestDB(t), config: newConfigManager(t, "https://cloud.example.test")}

	if got := s.cloudBaseURL(); got != "https://cloud.example.test" {
		t.Fatalf("cloudBaseURL on a fresh DB = %q, want the config URL", got)
	}
}
