package gateway

import (
	"testing"
	"time"

	waE2E "go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	"google.golang.org/protobuf/proto"
)

func TestNormalizePhone(t *testing.T) {
	tests := map[string]string{
		"+39 333 123 4567": "393331234567",
		"00393331234567":   "393331234567",
	}
	for input, want := range tests {
		got, err := NormalizePhone(input)
		if err != nil {
			t.Fatalf("NormalizePhone(%q): %v", input, err)
		}
		if got != want {
			t.Fatalf("NormalizePhone(%q) = %q, want %q", input, got, want)
		}
	}
}

func TestNormalizePhoneRejectsLocalAndMalformedNumbers(t *testing.T) {
	for _, input := range []string{"3331234567", "+39abc", ""} {
		if _, err := NormalizePhone(input); err == nil {
			t.Fatalf("NormalizePhone(%q) should fail", input)
		}
	}
}

func TestValidateAccountID(t *testing.T) {
	for _, valid := range []string{"primary", "booking-1", "studio_roma"} {
		if err := ValidateAccountID(valid); err != nil {
			t.Fatalf("ValidateAccountID(%q): %v", valid, err)
		}
	}
	for _, invalid := range []string{"", "Primary", "../primary", "with spaces"} {
		if err := ValidateAccountID(invalid); err == nil {
			t.Fatalf("ValidateAccountID(%q) should fail", invalid)
		}
	}
}

func TestIncomingMessageFromEvent(t *testing.T) {
	receivedAt := time.Date(2026, time.August, 31, 16, 0, 0, 0, time.UTC)
	current := &session{tenantID: 42, accountID: "primary"}
	event := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:   types.NewJID("393335374044", types.DefaultUserServer),
				Sender: types.NewADJID("393335374044", 0, 4),
			},
			ID:        "message-001",
			PushName:  "Mario Rossi",
			Timestamp: receivedAt,
		},
		Message: &waE2E.Message{Conversation: proto.String("  risposta di prova  ")},
	}

	message, ok := incomingMessageFromEvent(current, event)
	if !ok {
		t.Fatal("incoming message should be accepted")
	}
	if message.TenantID != 42 || message.AccountID != "primary" {
		t.Fatalf("unexpected account mapping: %+v", message)
	}
	if message.From != "+393335374044" {
		t.Fatalf("unexpected sender address: %q", message.From)
	}
	if message.SenderName != "Mario Rossi" || message.Text != "risposta di prova" {
		t.Fatalf("unexpected sender or text: %+v", message)
	}
	if message.MessageType != "text" || message.ReceivedAt != receivedAt {
		t.Fatalf("unexpected message metadata: %+v", message)
	}
}

func TestIncomingMessageContentSupportsExtendedTextAndMedia(t *testing.T) {
	text, messageType := incomingMessageContent(&waE2E.Message{
		ExtendedTextMessage: &waE2E.ExtendedTextMessage{Text: proto.String("ciao esteso")},
	})
	if text != "ciao esteso" || messageType != "text" {
		t.Fatalf("unexpected extended text result: %q %q", text, messageType)
	}

	text, messageType = incomingMessageContent(&waE2E.Message{
		ImageMessage: &waE2E.ImageMessage{Caption: proto.String("foto allegata")},
	})
	if text != "foto allegata" || messageType != "image" {
		t.Fatalf("unexpected image result: %q %q", text, messageType)
	}
}

func TestIncomingMessageFromEventIgnoresOutgoingAndBroadcast(t *testing.T) {
	current := &session{tenantID: 42, accountID: "primary"}
	for name, source := range map[string]types.MessageSource{
		"outgoing": {
			Chat:     types.NewJID("393335374044", types.DefaultUserServer),
			Sender:   types.NewJID("393335374044", types.DefaultUserServer),
			IsFromMe: true,
		},
		"status broadcast": {
			Chat:   types.StatusBroadcastJID,
			Sender: types.NewJID("393335374044", types.DefaultUserServer),
		},
	} {
		t.Run(name, func(t *testing.T) {
			event := &events.Message{
				Info: types.MessageInfo{MessageSource: source, ID: "message-ignored"},
				Message: &waE2E.Message{Conversation: proto.String("ignore")},
			}
			if _, ok := incomingMessageFromEvent(current, event); ok {
				t.Fatal("message should be ignored")
			}
		})
	}
}
