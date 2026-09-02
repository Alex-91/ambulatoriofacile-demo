package gateway

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/Alex-91/ambulatoriofacile-demo/services/whatsapp-gateway/internal/auth"
)

type IncomingWebhookDispatcher struct {
	url           string
	requestTarget string
	keyID         string
	secret        string
	client        *http.Client
	logger        *slog.Logger
}

type incomingWebhookPayload struct {
	EventType string                     `json:"event_type"`
	TenantID  int64                      `json:"tenant_id"`
	AccountID string                     `json:"account_id"`
	Data      incomingWebhookMessageData `json:"data"`
}

type incomingWebhookMessageData struct {
	MessageID   string    `json:"message_id"`
	From        string    `json:"from"`
	SenderName  string    `json:"sender_name,omitempty"`
	Text        string    `json:"text"`
	MessageType string    `json:"message_type"`
	IsGroup     bool      `json:"is_group"`
	ReceivedAt  time.Time `json:"received_at"`
}

func NewIncomingWebhookDispatcher(
	address, keyID, secret string,
	timeout time.Duration,
	logger *slog.Logger,
) (*IncomingWebhookDispatcher, error) {
	parsed, err := url.Parse(strings.TrimSpace(address))
	if err != nil || parsed.Scheme == "" || parsed.Host == "" {
		return nil, fmt.Errorf("invalid incoming webhook URL")
	}
	if parsed.Scheme != "https" && !(parsed.Scheme == "http" && (parsed.Hostname() == "127.0.0.1" || parsed.Hostname() == "localhost")) {
		return nil, fmt.Errorf("incoming webhook URL must use HTTPS")
	}
	if strings.TrimSpace(keyID) == "" || len(strings.TrimSpace(secret)) < 32 {
		return nil, fmt.Errorf("invalid incoming webhook credentials")
	}
	if timeout < time.Second || timeout > time.Minute {
		return nil, fmt.Errorf("invalid incoming webhook timeout")
	}
	if logger == nil {
		logger = slog.Default()
	}

	return &IncomingWebhookDispatcher{
		url:           parsed.String(),
		requestTarget: parsed.RequestURI(),
		keyID:         strings.TrimSpace(keyID),
		secret:        strings.TrimSpace(secret),
		client:        &http.Client{Timeout: timeout},
		logger:        logger,
	}, nil
}

func (d *IncomingWebhookDispatcher) Deliver(message IncomingMessage) {
	payload := incomingWebhookPayload{
		EventType: "message_received",
		TenantID:  message.TenantID,
		AccountID: message.AccountID,
		Data: incomingWebhookMessageData{
			MessageID:   message.MessageID,
			From:        message.From,
			SenderName:  message.SenderName,
			Text:        message.Text,
			MessageType: message.MessageType,
			IsGroup:     message.IsGroup,
			ReceivedAt:  message.ReceivedAt,
		},
	}
	body, err := json.Marshal(payload)
	if err != nil {
		d.logger.Error("failed to encode incoming WhatsApp webhook", "error", err)
		return
	}

	delays := []time.Duration{0, 750 * time.Millisecond, 3 * time.Second, 10 * time.Second}
	var lastErr error
	for attempt, delay := range delays {
		if delay > 0 {
			time.Sleep(delay)
		}
		if err := d.deliverOnce(context.Background(), message.TenantID, body); err == nil {
			d.logger.Info(
				"incoming WhatsApp webhook delivered",
				"tenant_id", message.TenantID,
				"account_id", message.AccountID,
				"message_id", message.MessageID,
				"attempt", attempt+1,
			)
			return
		} else {
			lastErr = err
		}
	}

	d.logger.Error(
		"incoming WhatsApp webhook delivery failed",
		"tenant_id", message.TenantID,
		"account_id", message.AccountID,
		"message_id", message.MessageID,
		"error", lastErr,
	)
}

func (d *IncomingWebhookDispatcher) deliverOnce(ctx context.Context, tenantID int64, body []byte) error {
	timestamp := time.Now().Unix()
	requestID, err := webhookRequestID()
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, d.url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-AmbulatorioFacile-Key-ID", d.keyID)
	req.Header.Set("X-AmbulatorioFacile-Tenant-ID", strconv.FormatInt(tenantID, 10))
	req.Header.Set("X-AmbulatorioFacile-Timestamp", strconv.FormatInt(timestamp, 10))
	req.Header.Set("X-AmbulatorioFacile-Request-ID", requestID)
	req.Header.Set("X-AmbulatorioFacile-Signature", auth.Sign(d.secret, http.MethodPost, d.requestTarget, tenantID, timestamp, requestID, body))

	response, err := d.client.Do(req)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 1<<20))
	if response.StatusCode < http.StatusOK || response.StatusCode >= http.StatusMultipleChoices {
		return fmt.Errorf("webhook returned HTTP %d", response.StatusCode)
	}
	return nil
}

func webhookRequestID() (string, error) {
	buffer := make([]byte, 16)
	if _, err := rand.Read(buffer); err != nil {
		return "", err
	}
	return "hook-" + hex.EncodeToString(buffer), nil
}
