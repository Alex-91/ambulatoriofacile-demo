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
	if timeline[0].DeliveryStatus != "sent" || timeline[0].DeliveredAt != nil || timeline[0].ReadAt != nil {
		t.Fatalf("unexpected initial delivery status: %+v", timeline[0])
	}

	deliveredAt := outgoing.ReceivedAt.Add(15 * time.Second)
	updated, err := registry.UpdateMessageReceipt(ctx, 42, "primary", outgoing.MessageID, "delivered", deliveredAt)
	if err != nil || !updated {
		t.Fatalf("UpdateMessageReceipt delivered: updated=%v err=%v", updated, err)
	}
	readAt := deliveredAt.Add(30 * time.Second)
	updated, err = registry.UpdateMessageReceipt(ctx, 42, "primary", outgoing.MessageID, "read", readAt)
	if err != nil || !updated {
		t.Fatalf("UpdateMessageReceipt read: updated=%v err=%v", updated, err)
	}
	// A late delivery receipt must never downgrade a message that is already read.
	updated, err = registry.UpdateMessageReceipt(ctx, 42, "primary", outgoing.MessageID, "delivered", readAt.Add(time.Second))
	if err != nil || !updated {
		t.Fatalf("UpdateMessageReceipt late delivery: updated=%v err=%v", updated, err)
	}

	timeline, err = registry.ListMessages(ctx, 42, "primary", 10)
	if err != nil {
		t.Fatalf("ListMessages after receipts: %v", err)
	}
	if timeline[0].DeliveryStatus != "read" || timeline[0].DeliveredAt == nil || timeline[0].ReadAt == nil {
		t.Fatalf("delivery receipt state was not persisted: %+v", timeline[0])
	}
	if !timeline[0].DeliveredAt.Equal(deliveredAt) || !timeline[0].ReadAt.Equal(readAt) {
		t.Fatalf("unexpected receipt timestamps: delivered=%v read=%v", timeline[0].DeliveredAt, timeline[0].ReadAt)
	}
	updated, err = registry.UpdateMessageReceipt(ctx, 84, "primary", outgoing.MessageID, "read", readAt)
	if err != nil || updated {
		t.Fatalf("receipt tenant isolation failed: updated=%v err=%v", updated, err)
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
	if messages[0].DeliveryStatus != "received" {
		t.Fatalf("legacy delivery status was not normalized: %+v", messages[0])
	}
}
