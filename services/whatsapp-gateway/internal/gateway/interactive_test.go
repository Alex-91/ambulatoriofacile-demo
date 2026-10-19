package gateway

import (
	"encoding/json"
	"fmt"
	waE2E "go.mau.fi/whatsmeow/proto/waE2E"
	"google.golang.org/protobuf/encoding/protojson"
	"testing"
)

func TestInteractiveReplyRoundTrip(t *testing.T) {
	choices := []ReplyChoice{{ID: "wa:nonce:0", Title: "Prenota"}, {ID: "wa:nonce:1", Title: "Annulla appuntamento"}, {ID: "wa:nonce:2", Title: "Altre opzioni"}}
	msg, err := buildReplyMessage("Scegli", choices)
	if err != nil {
		t.Fatal(err)
	}
	native := msg.GetInteractiveMessage().GetNativeFlowMessage()
	if len(native.GetButtons()) != 3 {
		t.Fatal("missing real WhatsApp buttons")
	}
	for i, b := range native.GetButtons() {
		var params map[string]string
		if b.GetName() != "quick_reply" || json.Unmarshal([]byte(b.GetButtonParamsJSON()), &params) != nil || params["id"] != choices[i].ID || params["display_text"] != choices[i].Title {
			t.Fatal("incorrect quick reply payload")
		}
		raw, _ := json.Marshal(map[string]any{"interactiveResponseMessage": map[string]any{"nativeFlowResponseMessage": map[string]any{"paramsJSON": b.GetButtonParamsJSON()}}})
		reply := &waE2E.Message{}
		if err := protojson.Unmarshal(raw, reply); err != nil {
			t.Fatal(err)
		}
		id, kind := incomingMessageContent(reply)
		if id != choices[i].ID || kind != "text" {
			t.Fatal("reply ID not forwarded to conversation engine")
		}
	}
}
func TestInteractiveReplyRejectsInvalidChoices(t *testing.T) {
	for _, choices := range [][]ReplyChoice{
		{{ID: "plain", Title: "Bad"}},
		{{ID: "wa:0", Title: ""}},
		{{ID: "wa:0", Title: "A"}, {ID: "wa:0", Title: "B"}},
		make([]ReplyChoice, 11),
	} {
		if _, err := buildReplyMessage("test", choices); err == nil {
			t.Fatal("invalid choices accepted")
		}
	}
	msg := &waE2E.Message{}
	if err := protojson.Unmarshal([]byte(`{"interactiveResponseMessage":{"nativeFlowResponseMessage":{"paramsJSON":"{invalid"}}}`), msg); err != nil {
		t.Fatal(err)
	}
	if text, kind := incomingMessageContent(msg); text != "" || kind != "unsupported" {
		t.Fatal("invalid response accepted")
	}
}

func TestInteractiveDateList(t *testing.T) {
	choices := []ReplyChoice{}
	for i := 0; i < 6; i++ {
		choices = append(choices, ReplyChoice{ID: fmt.Sprintf("wa:page:%d", i), Title: fmt.Sprintf("Date %d", i)})
	}
	choices[5].Title = "Mostra altri giorni"
	msg, err := buildReplyMessage("Scegli il giorno", choices)
	if err != nil {
		t.Fatal(err)
	}
	buttons := msg.GetInteractiveMessage().GetNativeFlowMessage().GetButtons()
	if len(buttons) != 1 || buttons[0].GetName() != "single_select" {
		t.Fatal("expected selectable list")
	}
	var params struct {
		Sections []struct {
			Rows []ReplyChoice `json:"rows"`
		} `json:"sections"`
	}
	if err = json.Unmarshal([]byte(buttons[0].GetButtonParamsJSON()), &params); err != nil {
		t.Fatal(err)
	}
	if len(params.Sections) != 1 || len(params.Sections[0].Rows) != 6 {
		t.Fatal("missing dates or pagination")
	}
	for i, row := range params.Sections[0].Rows {
		if row.ID != choices[i].ID || row.Title != choices[i].Title {
			t.Fatal("incorrect list choice")
		}
		payload, _ := json.Marshal(map[string]string{"id": row.ID})
		raw, _ := json.Marshal(map[string]any{"interactiveResponseMessage": map[string]any{"nativeFlowResponseMessage": map[string]any{"paramsJSON": string(payload)}}})
		reply := &waE2E.Message{}
		if err = protojson.Unmarshal(raw, reply); err != nil {
			t.Fatal(err)
		}
		id, kind := incomingMessageContent(reply)
		if id != row.ID || kind != "text" {
			t.Fatal("list reply not forwarded")
		}
	}
}
