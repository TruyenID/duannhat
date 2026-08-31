package glory

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// Client talks HTTP/JSON to one YRT-R08-MN adapter on the LAN. It is pure
// transport — no SQLite, no ledger. baseURL is like "http://192.168.0.10"
// (port 80, no TLS). It implements Machine, so the transaction state machine
// (Collector) depends on the interface and a future serial transport (method B)
// can drop in without touching the state machine.
type Client struct {
	baseURL string
	// resolve, when set, supplies the adapter base URL per request (Cloud
	// peripheral registry synced DOWN, changeable at runtime). It wins over the
	// static baseURL; a false second return means "no machine configured" →
	// ErrNotConfigured.
	resolve func() (string, bool)
	http    *http.Client
}

// ErrNotConfigured is returned when a resolving Client has no adapter URL — the
// caller (handler) maps it to 503.
var ErrNotConfigured = fmt.Errorf("glory: no cash changer configured")

// New builds a Client for the adapter at baseURL. A nil httpClient gets a
// sensible default (10s timeout — start/fix/cancel are quick; the long wait is
// the poll loop in Collector, bounded by its own context).
func New(baseURL string, httpClient *http.Client) *Client {
	return &Client{baseURL: strings.TrimRight(baseURL, "/"), http: defaultHTTP(httpClient)}
}

// NewResolving builds a Client whose adapter URL comes from resolve() on every
// request, so a Cloud-registered machine (synced DOWN) takes effect without a
// restart and a single Collector keeps its session state across config changes.
func NewResolving(resolve func() (string, bool), httpClient *http.Client) *Client {
	return &Client{resolve: resolve, http: defaultHTTP(httpClient)}
}

func defaultHTTP(httpClient *http.Client) *http.Client {
	if httpClient == nil {
		return &http.Client{Timeout: 10 * time.Second}
	}
	return httpClient
}

// base returns the adapter URL for this request (resolver-first).
func (c *Client) base() (string, error) {
	if c.resolve != nil {
		u, ok := c.resolve()
		if !ok || u == "" {
			return "", ErrNotConfigured
		}
		return strings.TrimRight(u, "/"), nil
	}
	if c.baseURL == "" {
		return "", ErrNotConfigured
	}
	return c.baseURL, nil
}

var _ Machine = (*Client)(nil)

// StartTransaction — 取引開始 API. Returns the transactionId used to poll.
func (c *Client) StartTransaction(ctx context.Context, r StartRequest) (string, error) {
	var out startResponse
	if err := c.do(ctx, http.MethodPost, "/api/v1/transactions", r, &out); err != nil {
		return "", err
	}
	return out.TransactionID, nil
}

// GetTransaction — 取引取得 API. Poll this at ≥1s intervals until the status is
// terminal. During deposit a machine error surfaces as a 503 *Error (title
// "error"); during dispense/pull-out it surfaces as a 200 with status=failure.
func (c *Client) GetTransaction(ctx context.Context, id string) (Transaction, error) {
	var out Transaction
	err := c.do(ctx, http.MethodGet, "/api/v1/transactions/"+id, nil, &out)
	return out, err
}

// FixDeposit — 入金完了 API. Call once deposit >= total (only when the request
// was started with ShowFixDepositButton=false). The machine then stops
// accepting cash and dispenses change.
func (c *Client) FixDeposit(ctx context.Context) error {
	return c.do(ctx, http.MethodPost, "/api/v1/transactions/fix-deposit", nil, nil)
}

// Cancel — 取引キャンセル API. Returns the deposited cash. Only valid before the
// deposit is fixed.
func (c *Client) Cancel(ctx context.Context) error {
	return c.do(ctx, http.MethodPost, "/api/v1/transactions/cancel", nil, nil)
}

// GetStatus — 状態取得 API. Safe to call during a transaction (monitoring).
func (c *Client) GetStatus(ctx context.Context) (StatusInfo, error) {
	var out StatusInfo
	err := c.do(ctx, http.MethodGet, "/api/v1/machine/status", nil, &out)
	return out, err
}

// GetInventory — 在高取得 API. Safe to call during a transaction (monitoring).
func (c *Client) GetInventory(ctx context.Context) (Inventory, error) {
	var out Inventory
	err := c.do(ctx, http.MethodGet, "/api/v1/machine/cash", nil, &out)
	return out, err
}

// SetDate — 日時設定 API. Syncs the adapter+recycler clock (ISO-8601, 2000..2037).
// NOTE: changing the adapter clock shifts seqNo — callers holding a "latest
// seqNo" must reset it after this call.
func (c *Client) SetDate(ctx context.Context, iso8601 string) error {
	return c.do(ctx, http.MethodPost, "/api/v1/machine/date", map[string]string{"date": iso8601}, nil)
}

// do performs one HTTP request. On 2xx it decodes the body into out (when
// non-nil). On any other status it decodes {title,detail} into an *Error;
// a non-JSON body (e.g. the HTML 404 for a bad path) yields an *Error with an
// empty Title and the raw body in Detail.
func (c *Client) do(ctx context.Context, method, path string, body, out any) error {
	var reqBody io.Reader
	if body != nil {
		buf, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("glory: marshal request: %w", err)
		}
		reqBody = bytes.NewReader(buf)
	}

	base, err := c.base()
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, method, base+path, reqBody)
	if err != nil {
		return fmt.Errorf("glory: build request: %w", err)
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	resp, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("glory: %s %s: %w", method, path, err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		ge := &Error{HTTPStatus: resp.StatusCode}
		var parsed struct {
			Title  string `json:"title"`
			Detail string `json:"detail"`
		}
		if json.Unmarshal(raw, &parsed) == nil && parsed.Title != "" {
			ge.Title = parsed.Title
			ge.Detail = parsed.Detail
		} else {
			ge.Detail = strings.TrimSpace(string(raw))
		}
		return ge
	}

	if out != nil && len(raw) > 0 {
		if err := json.Unmarshal(raw, out); err != nil {
			return fmt.Errorf("glory: decode %s response: %w", path, err)
		}
	}
	return nil
}
