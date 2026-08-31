package service

// #1175 — REAL-Reverb end-to-end test for the poke chain. Skipped unless
// REVERB_E2E=1: it needs a live Reverb server (any Pusher-protocol server
// works — that's the point of the provider-agnostic design).
//
//	cd backend && CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
//	  php artisan reverb:start --host=127.0.0.1 --port=8091 &
//	cd workstation-app && REVERB_E2E=1 go test ./internal/service/ -run Reverb_E2E -count=1 -v
//
// What is REAL here (vs the unit-test fake pusher):
//   - the actual Reverb websocket handshake (pusher:connection_established),
//   - a private-channel subscribe whose HMAC signature Reverb VERIFIES
//     (the auth stub signs with the app secret exactly like Cloud's
//     /devices/broadcasting/auth does — only the authz decision is stubbed,
//     which the backend channel-auth Pest suite covers),
//   - event delivery through Reverb's Pusher REST API — the same wire call
//     Laravel's reverb broadcaster makes for WorkstationSyncPoke.
//
// Overridable via env: REVERB_E2E_HOST/PORT/KEY/SECRET/APP_ID (defaults match
// backend/.env bootstrap credentials).

import (
	"crypto/hmac"
	"crypto/md5"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"sync"
	"testing"
	"time"
)

func reverbEnv(key, def string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return def
}

func Test_Poke_Reverb_E2E(t *testing.T) {
	if os.Getenv("REVERB_E2E") != "1" {
		t.Skip("REVERB_E2E=1 not set — needs a live Reverb server (see file header)")
	}

	host := reverbEnv("REVERB_E2E_HOST", "127.0.0.1")
	port := reverbEnv("REVERB_E2E_PORT", "8091")
	appKey := reverbEnv("REVERB_E2E_KEY", "bootstrap-key")
	secret := reverbEnv("REVERB_E2E_SECRET", "bootstrap-secret")
	appID := reverbEnv("REVERB_E2E_APP_ID", "bootstrap")
	branch := fmt.Sprintf("e2e-%d", time.Now().UnixNano())
	channel := pokeChannelPrefix + branch

	// Cloud stub: manifest (v1 then 304) + a broadcasting-auth endpoint that
	// signs the subscription with the REAL app secret — Reverb rejects the
	// subscribe if this signature is wrong, so the protocol leg is genuine.
	var mu sync.Mutex
	manifestHits := 0
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case pullPathSyncManifest:
			mu.Lock()
			manifestHits++
			mu.Unlock()
			if r.Header.Get("If-None-Match") == `"v1"` {
				w.WriteHeader(http.StatusNotModified)
				return
			}
			_, _ = w.Write([]byte(`{"data":{"manifest_version":"v1","feeds":{}}}`))
		case pokeAuthPath:
			_ = r.ParseForm()
			socketID := r.PostForm.Get("socket_id")
			channelName := r.PostForm.Get("channel_name")
			mac := hmac.New(sha256.New, []byte(secret))
			mac.Write([]byte(socketID + ":" + channelName))
			sig := hex.EncodeToString(mac.Sum(nil))
			_, _ = fmt.Fprintf(w, `{"auth":"%s:%s"}`, appKey, sig)
		default:
			_, _ = w.Write([]byte(feedCannedResponse(r.URL.Path)))
		}
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-E2E"))
	for k, v := range map[string]string{
		pokeSettingAppKey: appKey,
		pokeSettingHost:   host,
		pokeSettingPort:   port,
		pokeSettingScheme: "http",
	} {
		if _, err := db.Exec(`INSERT INTO shop_settings (key, value) VALUES (?, ?)`, k, v); err != nil {
			t.Fatal(err)
		}
	}
	if err := p.setCursor("workstation_branch_id", branch); err != nil {
		t.Fatal(err)
	}

	done := make(chan struct{})
	go func() {
		p.loopWithKick(time.Hour, p.manifestTick) // only a poke can re-check
		close(done)
	}()
	go p.runPokeClient()
	defer func() { p.Stop(); <-done }()

	// Give the client time to connect + subscribe against the real server.
	time.Sleep(2 * time.Second)

	// Fire the poke through Reverb's Pusher REST API — the exact call the
	// Laravel reverb broadcaster makes. Signed per the Pusher REST spec.
	deadline := time.Now().Add(25 * time.Second)
	fired := 0
	for {
		mu.Lock()
		n := manifestHits
		mu.Unlock()
		if n >= 2 {
			break // poke received → early manifest re-check ran: chain proven
		}
		if time.Now().After(deadline) {
			t.Fatalf("real-Reverb poke never triggered a manifest re-check (manifest hits=%d, pokes fired=%d)", n, fired)
		}
		if err := firePusherRestEvent(host+":"+port, appID, appKey, secret, channel); err != nil {
			t.Logf("REST trigger attempt failed (will retry): %v", err)
		} else {
			fired++
		}
		time.Sleep(500 * time.Millisecond)
	}
}

// firePusherRestEvent POSTs a signed Pusher-REST events call — byte-for-byte
// the contract Laravel's reverb/pusher drivers speak.
func firePusherRestEvent(hostPort, appID, key, secret, channel string) error {
	body := fmt.Sprintf(`{"name":"sync.poke","channel":"%s","data":"{}"}`, channel)
	bodyMD5 := md5.Sum([]byte(body))

	q := url.Values{}
	q.Set("auth_key", key)
	q.Set("auth_timestamp", fmt.Sprintf("%d", time.Now().Unix()))
	q.Set("auth_version", "1.0")
	q.Set("body_md5", hex.EncodeToString(bodyMD5[:]))

	path := "/apps/" + appID + "/events"
	toSign := "POST\n" + path + "\n" + q.Encode()
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(toSign))
	q.Set("auth_signature", hex.EncodeToString(mac.Sum(nil)))

	req, err := http.NewRequest(http.MethodPost, "http://"+hostPort+path+"?"+q.Encode(), strings.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("events API status %d", resp.StatusCode)
	}
	return nil
}
