package gateway

import (
	"context"
	"path/filepath"
	"testing"
	"time"
)

func TestRegistryStoresIncomingMessagesWithoutDuplicates(t *testing.T) {
	ctx := context.Background()
	dsn := "file:" + filepath.ToSlash(filepath.Join(t.TempDir(), "gateway.db")) + "?_foreign_keys=on"
	registry, err := OpenRegistry(ctx, dsn)
	if err != nil {
		t.Fatalf("OpenRegistry: %v", err)
	}
	t.Cleanup(func() { _ = registry.Close() })

	message := IncomingMessage{
		TenantID:    42,
		AccountID:   "primary",
		MessageID:   "message-001",
		ChatJID:     "393335374044@s.whatsapp.net",
		SenderJID:   "393335374044@s.whatsapp.net",
		From:        "+393335374044",
		SenderName:  "Mario Rossi",
		Text:        "prima risposta",
		MessageType: "text",
		ReceivedAt:  time.Date(2026, time.August, 31, 16, 0, 0, 0, time.UTC),
	}
	stored, err := registry.StoreIncoming(ctx, message)
	if err != nil || !stored {
		t.Fatalf("first StoreIncoming: stored=%v err=%v", stored, err)
	}
	stored, err = registry.StoreIncoming(ctx, message)
	if err != nil || stored {
		t.Fatalf("duplicate StoreIncoming: stored=%v err=%v", stored, err)
	}

	newer := message
	newer.MessageID = "message-002"
	newer.Text = "seconda risposta"
	newer.ReceivedAt = message.ReceivedAt.Add(time.Minute)
	if stored, err = registry.StoreIncoming(ctx, newer); err != nil || !stored {
		t.Fatalf("newer StoreIncoming: stored=%v err=%v", stored, err)
	}

	otherTenant := message
	otherTenant.TenantID = 84
	if stored, err = registry.StoreIncoming(ctx, otherTenant); err != nil || !stored {
		t.Fatalf("other tenant StoreIncoming: stored=%v err=%v", stored, err)
	}

	messages, err := registry.ListIncoming(ctx, 42, "primary", 1)
	if err != nil {
		t.Fatalf("ListIncoming: %v", err)
	}
	if len(messages) != 1 || messages[0].MessageID != "message-002" {
		t.Fatalf("unexpected ordered messages: %+v", messages)
	}
	if messages[0].From != "+393335374044" || messages[0].Text != "seconda risposta" {
		t.Fatalf("unexpected stored message: %+v", messages[0])
	}

	isolated, err := registry.ListIncoming(ctx, 84, "primary", 50)
	if err != nil {
		t.Fatalf("ListIncoming other tenant: %v", err)
	}
	if len(isolated) != 1 || isolated[0].TenantID != 84 {
		t.Fatalf("tenant isolation failed: %+v", isolated)
	}
}
