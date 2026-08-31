package gateway

import "testing"

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
