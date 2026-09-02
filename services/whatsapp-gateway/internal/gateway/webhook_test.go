package gateway

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"

	"github.com/Alex-91/ambulatoriofacile-demo/services/whatsapp-gateway/internal/auth"
)

func TestIncomingWebhookDispatcherSignsAndDeliversMessage(t *testing.T) {
	const secret = "a-very-long-webhook-secret-for-tests-only"
	const keyID = "gateway-app"
	const tenantID int64 = 42

	var received incomingWebhookPayload
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, err := io.ReadAll(r.Body)
		if err != nil {
			t.Fatalf("read body: %v", err)
		}
		if err := json.Unmarshal(body, &received); err != nil {
			t.Fatalf("decode body: %v", err)
		}
		timestamp, err := time.Parse(time.RFC3339, received.Data.ReceivedAt.Format(time.RFC3339))
		if err != nil || timestamp.IsZero() {
			t.Fatalf("invalid received timestamp: %v", received.Data.ReceivedAt)
		}
		provided := r.Header.Get("X-AmbulatorioFacile-Signature")
		unixTimestamp, err := strconv.ParseInt(r.Header.Get("X-AmbulatorioFacile-Timestamp"), 10, 64)
		if err != nil {
			t.Fatalf("invalid signature timestamp: %v", err)
		}
		expected := auth.Sign(secret, http.MethodPost, r.URL.RequestURI(), tenantID, unixTimestamp, r.Header.Get("X-AmbulatorioFacile-Request-ID"), body)
		if provided != expected {
			t.Fatalf("unexpected signature: got %s want %s", provided, expected)
		}
		if r.Header.Get("X-AmbulatorioFacile-Key-ID") != keyID {
			t.Fatalf("unexpected key id")
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true}`))
	}))
	defer server.Close()

	dispatcher, err := NewIncomingWebhookDispatcher(server.URL+"/api/whatsapp-gateway/incoming", keyID, secret, 3*time.Second, slog.Default())
	if err != nil {
		t.Fatalf("create dispatcher: %v", err)
	}
	messageTime := time.Now().UTC()
	body, err := json.Marshal(incomingWebhookPayload{
		EventType: "message_received",
		TenantID:  tenantID,
		AccountID: "primary",
		Data: incomingWebhookMessageData{
			MessageID:   "msg-001",
			From:        "+393331234567",
			Text:        "1",
			MessageType: "text",
			ReceivedAt:  messageTime,
		},
	})
	if err != nil {
		t.Fatalf("encode expected payload: %v", err)
	}
	if err := dispatcher.deliverOnce(context.Background(), tenantID, body); err != nil {
		t.Fatalf("deliver webhook: %v", err)
	}
	if received.TenantID != tenantID || received.Data.MessageID != "msg-001" || received.Data.Text != "1" {
		t.Fatalf("unexpected webhook payload: %+v", received)
	}
}
