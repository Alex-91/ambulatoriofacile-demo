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

	outgoing := message
	outgoing.MessageID = "message-003"
	outgoing.From = ""
	outgoing.To = "+393335374044"
	outgoing.Peer = "+393335374044"
	outgoing.Text = "risposta dello studio"
	outgoing.ReceivedAt = message.ReceivedAt.Add(2 * time.Minute)
	if stored, err = registry.StoreOutgoing(ctx, outgoing); err != nil || !stored {
		t.Fatalf("StoreOutgoing: stored=%v err=%v", stored, err)
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

	timeline, err := registry.ListMessages(ctx, 42, "primary", 10)
	if err != nil {
		t.Fatalf("ListMessages: %v", err)
	}
	if len(timeline) != 3 || timeline[0].MessageID != "message-003" {
		t.Fatalf("unexpected timeline: %+v", timeline)
	}
	if timeline[0].Direction != "outgoing" || timeline[0].To != "+393335374044" || timeline[0].Peer != "+393335374044" {
		t.Fatalf("unexpected outgoing message: %+v", timeline[0])
	}

	isolated, err := registry.ListIncoming(ctx, 84, "primary", 50)
	if err != nil {
		t.Fatalf("ListIncoming other tenant: %v", err)
	}
	if len(isolated) != 1 || isolated[0].TenantID != 84 {
		t.Fatalf("tenant isolation failed: %+v", isolated)
	}
}

func TestRegistryMigratesLegacyIncomingMessages(t *testing.T) {
	ctx := context.Background()
	dsn := "file:" + filepath.ToSlash(filepath.Join(t.TempDir(), "gateway.db")) + "?_foreign_keys=on"
	registry, err := OpenRegistry(ctx, dsn)
	if err != nil {
		t.Fatalf("OpenRegistry: %v", err)
	}
	t.Cleanup(func() { _ = registry.Close() })

	when := time.Date(2026, time.August, 31, 16, 30, 0, 0, time.UTC).Format(time.RFC3339Nano)
	_, err = registry.db.ExecContext(ctx, `
INSERT INTO gateway_incoming_messages (
    tenant_id, account_id, message_id, chat_jid, sender_jid, sender_address,
    sender_name, text_content, message_type, is_group, received_at, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		42,
		"primary",
		"legacy-001",
		"393335374044@s.whatsapp.net",
		"393335374044@s.whatsapp.net",
		"+393335374044",
		"Mario Rossi",
		"messaggio precedente",
		"text",
		false,
		when,
		when,
	)
	if err != nil {
		t.Fatalf("insert legacy message: %v", err)
	}
	if err := registry.migrate(ctx); err != nil {
		t.Fatalf("migrate legacy messages: %v", err)
	}

	messages, err := registry.ListIncoming(ctx, 42, "primary", 10)
	if err != nil {
		t.Fatalf("ListIncoming: %v", err)
	}
	if len(messages) != 1 || messages[0].MessageID != "legacy-001" || messages[0].Direction != "incoming" {
		t.Fatalf("legacy message was not migrated: %+v", messages)
	}
}
