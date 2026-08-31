package auth

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

const maxBodyBytes int64 = 1 << 20

var requestIDPattern = regexp.MustCompile(`^[A-Za-z0-9._:-]{8,128}$`)

type contextKey string

const (
	tenantIDKey  contextKey = "tenant_id"
	requestIDKey contextKey = "request_id"
)

type Authenticator struct {
	keyID  string
	secret []byte
	skew   time.Duration
	now    func() time.Time
	mu     sync.Mutex
	seen   map[string]time.Time
}

func New(keyID, secret string, skew time.Duration) *Authenticator {
	return &Authenticator{
		keyID:  keyID,
		secret: []byte(secret),
		skew:   skew,
		now:    time.Now,
		seen:   make(map[string]time.Time),
	}
}

func Canonical(method, requestURI string, tenantID int64, timestamp int64, requestID string, body []byte) string {
	bodyHash := sha256.Sum256(body)
	return strings.Join([]string{
		strings.ToUpper(method),
		requestURI,
		strconv.FormatInt(tenantID, 10),
		strconv.FormatInt(timestamp, 10),
		requestID,
		hex.EncodeToString(bodyHash[:]),
	}, "\n")
}

func Sign(secret, method, requestURI string, tenantID, timestamp int64, requestID string, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(Canonical(method, requestURI, tenantID, timestamp, requestID, body)))
	return hex.EncodeToString(mac.Sum(nil))
}

func (a *Authenticator) Middleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, tenantID, requestID, err := a.authenticate(w, r)
		if err != nil {
			writeAuthError(w, err.Error())
			return
		}

		r.Body = io.NopCloser(bytes.NewReader(body))
		ctx := context.WithValue(r.Context(), tenantIDKey, tenantID)
		ctx = context.WithValue(ctx, requestIDKey, requestID)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

func TenantID(ctx context.Context) int64 {
	value, _ := ctx.Value(tenantIDKey).(int64)
	return value
}

func RequestID(ctx context.Context) string {
	value, _ := ctx.Value(requestIDKey).(string)
	return value
}

func (a *Authenticator) authenticate(w http.ResponseWriter, r *http.Request) ([]byte, int64, string, error) {
	providedKeyID := r.Header.Get("X-AmbulatorioFacile-Key-ID")
	if subtle.ConstantTimeCompare([]byte(providedKeyID), []byte(a.keyID)) != 1 {
		return nil, 0, "", fmt.Errorf("invalid credentials")
	}

	tenantID, err := strconv.ParseInt(strings.TrimSpace(r.Header.Get("X-AmbulatorioFacile-Tenant-ID")), 10, 64)
	if err != nil || tenantID <= 0 {
		return nil, 0, "", fmt.Errorf("invalid tenant context")
	}

	timestamp, err := strconv.ParseInt(strings.TrimSpace(r.Header.Get("X-AmbulatorioFacile-Timestamp")), 10, 64)
	if err != nil {
		return nil, 0, "", fmt.Errorf("invalid timestamp")
	}
	requestTime := time.Unix(timestamp, 0)
	now := a.now()
	if requestTime.Before(now.Add(-a.skew)) || requestTime.After(now.Add(a.skew)) {
		return nil, 0, "", fmt.Errorf("request timestamp outside allowed window")
	}

	requestID := strings.TrimSpace(r.Header.Get("X-AmbulatorioFacile-Request-ID"))
	if !requestIDPattern.MatchString(requestID) {
		return nil, 0, "", fmt.Errorf("invalid request id")
	}

	r.Body = http.MaxBytesReader(w, r.Body, maxBodyBytes)
	body, err := io.ReadAll(r.Body)
	if err != nil {
		return nil, 0, "", fmt.Errorf("invalid request body")
	}

	providedSignature, err := hex.DecodeString(strings.TrimSpace(r.Header.Get("X-AmbulatorioFacile-Signature")))
	if err != nil || len(providedSignature) != sha256.Size {
		return nil, 0, "", fmt.Errorf("invalid signature")
	}

	mac := hmac.New(sha256.New, a.secret)
	_, _ = mac.Write([]byte(Canonical(r.Method, r.URL.RequestURI(), tenantID, timestamp, requestID, body)))
	if !hmac.Equal(mac.Sum(nil), providedSignature) {
		return nil, 0, "", fmt.Errorf("invalid signature")
	}

	if !a.markRequestSeen(tenantID, requestID, now) {
		return nil, 0, "", fmt.Errorf("request replay detected")
	}

	return body, tenantID, requestID, nil
}

func (a *Authenticator) markRequestSeen(tenantID int64, requestID string, now time.Time) bool {
	key := strconv.FormatInt(tenantID, 10) + ":" + requestID
	cutoff := now.Add(-a.skew)

	a.mu.Lock()
	defer a.mu.Unlock()

	for seenKey, seenAt := range a.seen {
		if seenAt.Before(cutoff) {
			delete(a.seen, seenKey)
		}
	}
	if _, exists := a.seen[key]; exists {
		return false
	}
	a.seen[key] = now
	return true
}

func writeAuthError(w http.ResponseWriter, message string) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(http.StatusUnauthorized)
	_ = json.NewEncoder(w).Encode(map[string]any{
		"ok":    false,
		"error": message,
	})
}
