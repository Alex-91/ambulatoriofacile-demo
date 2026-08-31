package auth

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"
)

func TestCanonicalAndSign(t *testing.T) {
	body := []byte(`{"to":"+393331234567","text":"Promemoria"}`)
	got := Sign("a-very-long-test-secret-that-is-safe", http.MethodPost, "/v1/accounts/primary/messages/text", 42, 1725100000, "req-test-0001", body)
	want := "a2b84c49e9c7664018f3466e4c545664db615368a067e80d8e184b997302df32"
	if got != want {
		t.Fatalf("unexpected signature: got %s want %s", got, want)
	}
}

func TestMiddlewareAcceptsSignedRequestAndRejectsReplay(t *testing.T) {
	const secret = "a-very-long-test-secret-that-is-safe"
	now := time.Unix(1725100000, 0)
	authenticator := New("app", secret, 5*time.Minute)
	authenticator.now = func() time.Time { return now }

	handler := authenticator.Middleware(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{"tenant_id": TenantID(r.Context())})
	}))

	request := func() *http.Request {
		body := []byte(`{"text":"hello"}`)
		r := httptest.NewRequest(http.MethodPost, "/v1/accounts/primary/messages/text", bytes.NewReader(body))
		r.Header.Set("X-AmbulatorioFacile-Key-ID", "app")
		r.Header.Set("X-AmbulatorioFacile-Tenant-ID", "7")
		r.Header.Set("X-AmbulatorioFacile-Timestamp", strconv.FormatInt(now.Unix(), 10))
		r.Header.Set("X-AmbulatorioFacile-Request-ID", "req-test-0002")
		r.Header.Set("X-AmbulatorioFacile-Signature", Sign(secret, r.Method, r.URL.RequestURI(), 7, now.Unix(), "req-test-0002", body))
		return r
	}

	first := httptest.NewRecorder()
	handler.ServeHTTP(first, request())
	if first.Code != http.StatusOK {
		t.Fatalf("first request returned %d: %s", first.Code, first.Body.String())
	}

	second := httptest.NewRecorder()
	handler.ServeHTTP(second, request())
	if second.Code != http.StatusUnauthorized {
		t.Fatalf("replayed request returned %d", second.Code)
	}
}
