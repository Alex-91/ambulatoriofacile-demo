package httpapi

import (
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/Alex-91/ambulatoriofacile-demo/services/whatsapp-gateway/internal/auth"
	"github.com/Alex-91/ambulatoriofacile-demo/services/whatsapp-gateway/internal/gateway"
)

type Server struct {
	manager *gateway.Manager
	logger  *slog.Logger
}

func New(manager *gateway.Manager, authenticator *auth.Authenticator, logger *slog.Logger) http.Handler {
	server := &Server{manager: manager, logger: logger}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", server.health)
	mux.HandleFunc("GET /readyz", server.ready)

	mux.Handle("GET /v1/accounts/{accountID}", authenticator.Middleware(http.HandlerFunc(server.accountStatus)))
	mux.Handle("POST /v1/accounts/{accountID}/pair", authenticator.Middleware(http.HandlerFunc(server.startPairing)))
	mux.Handle("GET /v1/accounts/{accountID}/qr", authenticator.Middleware(http.HandlerFunc(server.qrCode)))
	mux.Handle("POST /v1/accounts/{accountID}/connect", authenticator.Middleware(http.HandlerFunc(server.connect)))
	mux.Handle("DELETE /v1/accounts/{accountID}/session", authenticator.Middleware(http.HandlerFunc(server.logout)))
	mux.Handle("GET /v1/accounts/{accountID}/messages", authenticator.Middleware(http.HandlerFunc(server.incomingMessages)))
	mux.Handle("POST /v1/accounts/{accountID}/messages/text", authenticator.Middleware(http.HandlerFunc(server.sendText)))

	return server.securityHeaders(server.recoverPanic(server.logRequests(mux)))
}

func (s *Server) health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":      true,
		"service": "ambulatoriofacile-whatsapp-gateway",
		"status":  "healthy",
	})
}

func (s *Server) ready(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := contextWithTimeout(r, 2*time.Second)
	defer cancel()
	if err := s.manager.Ready(ctx); err != nil {
		writeError(w, http.StatusServiceUnavailable, "gateway_not_ready", "Archivio sessioni non disponibile.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "status": "ready"})
}

func (s *Server) accountStatus(w http.ResponseWriter, r *http.Request) {
	status, err := s.manager.Status(r.Context(), auth.TenantID(r.Context()), r.PathValue("accountID"))
	if err != nil {
		s.writeManagerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "account": status})
}

func (s *Server) startPairing(w http.ResponseWriter, r *http.Request) {
	var request struct {
		DisplayName string `json:"display_name"`
	}
	if err := decodeJSON(r, &request); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", err.Error())
		return
	}
	status, err := s.manager.StartPairing(r.Context(), auth.TenantID(r.Context()), r.PathValue("accountID"), request.DisplayName)
	if err != nil && !errors.Is(err, gateway.ErrAlreadyPaired) {
		s.writeManagerError(w, err)
		return
	}
	if errors.Is(err, gateway.ErrAlreadyPaired) {
		writeJSON(w, http.StatusConflict, map[string]any{"ok": false, "error": "already_paired", "account": status})
		return
	}
	writeJSON(w, http.StatusAccepted, map[string]any{"ok": true, "account": status})
}

func (s *Server) qrCode(w http.ResponseWriter, r *http.Request) {
	status, err := s.manager.QRCode(r.Context(), auth.TenantID(r.Context()), r.PathValue("accountID"))
	if err != nil {
		if errors.Is(err, gateway.ErrQRCodePending) {
			writeJSON(w, http.StatusAccepted, map[string]any{"ok": true, "account": status, "qr_pending": true})
			return
		}
		s.writeManagerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "account": status})
}

func (s *Server) connect(w http.ResponseWriter, r *http.Request) {
	status, err := s.manager.Connect(r.Context(), auth.TenantID(r.Context()), r.PathValue("accountID"))
	if err != nil {
		s.writeManagerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "account": status})
}

func (s *Server) logout(w http.ResponseWriter, r *http.Request) {
	if err := s.manager.Logout(r.Context(), auth.TenantID(r.Context()), r.PathValue("accountID")); err != nil {
		s.writeManagerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "status": "logged_out"})
}

func (s *Server) sendText(w http.ResponseWriter, r *http.Request) {
	var request struct {
		To   string `json:"to"`
		Text string `json:"text"`
	}
	if err := decodeJSON(r, &request); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", err.Error())
		return
	}

	ctx, cancel := contextWithTimeout(r, 90*time.Second)
	defer cancel()
	result, err := s.manager.SendText(ctx, auth.TenantID(r.Context()), r.PathValue("accountID"), request.To, request.Text)
	if err != nil {
		s.writeManagerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":         true,
		"provider":   "ambulatoriofacile-whatsapp-gateway",
		"request_id": auth.RequestID(r.Context()),
		"message":    result,
	})
}

func (s *Server) incomingMessages(w http.ResponseWriter, r *http.Request) {
	limit := 50
	if rawLimit := strings.TrimSpace(r.URL.Query().Get("limit")); rawLimit != "" {
		parsedLimit, err := strconv.Atoi(rawLimit)
		if err != nil || parsedLimit < 1 || parsedLimit > 100 {
			writeError(w, http.StatusBadRequest, "invalid_limit", "limit deve essere compreso tra 1 e 100.")
			return
		}
		limit = parsedLimit
	}
	direction := strings.ToLower(strings.TrimSpace(r.URL.Query().Get("direction")))
	var messages []gateway.IncomingMessage
	var err error
	switch direction {
	case "", "incoming":
		direction = "incoming"
		messages, err = s.manager.IncomingMessages(
			r.Context(),
			auth.TenantID(r.Context()),
			r.PathValue("accountID"),
			limit,
		)
	case "all":
		messages, err = s.manager.Messages(
			r.Context(),
			auth.TenantID(r.Context()),
			r.PathValue("accountID"),
			limit,
		)
	default:
		writeError(w, http.StatusBadRequest, "invalid_direction", "direction deve essere incoming oppure all.")
		return
	}
	if err != nil {
		s.writeManagerError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":       true,
		"count":    len(messages),
		"direction": direction,
		"messages": messages,
	})
}

func (s *Server) writeManagerError(w http.ResponseWriter, err error) {
	switch {
	case errors.Is(err, gateway.ErrAccountNotFound):
		writeError(w, http.StatusNotFound, "account_not_found", err.Error())
	case errors.Is(err, gateway.ErrNotPaired):
		writeError(w, http.StatusConflict, "account_not_paired", err.Error())
	case errors.Is(err, gateway.ErrInvalidPhone):
		writeError(w, http.StatusUnprocessableEntity, "invalid_phone", err.Error())
	default:
		s.logger.Error("gateway request failed", "error", err)
		writeError(w, http.StatusInternalServerError, "gateway_error", "Operazione WhatsApp non riuscita.")
	}
}

func (s *Server) securityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("Content-Security-Policy", "default-src 'none'; frame-ancestors 'none'")
		w.Header().Set("Referrer-Policy", "no-referrer")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		next.ServeHTTP(w, r)
	})
}

func (s *Server) logRequests(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		started := time.Now()
		next.ServeHTTP(w, r)
		s.logger.Info("http request", "method", r.Method, "path", r.URL.Path, "duration_ms", time.Since(started).Milliseconds())
	})
}

func (s *Server) recoverPanic(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if recovered := recover(); recovered != nil {
				s.logger.Error("panic recovered", "panic", recovered, "method", r.Method, "path", r.URL.Path)
				writeError(w, http.StatusInternalServerError, "internal_error", "Errore interno del gateway.")
			}
		}()
		next.ServeHTTP(w, r)
	})
}

func decodeJSON(r *http.Request, target any) error {
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		return err
	}
	var extra any
	if err := decoder.Decode(&extra); !errors.Is(err, io.EOF) {
		if err == nil {
			return errors.New("multiple JSON values are not allowed")
		}
		return err
	}
	return nil
}

func writeJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}

func writeError(w http.ResponseWriter, status int, code, message string) {
	writeJSON(w, status, map[string]any{
		"ok":      false,
		"error":   code,
		"message": strings.TrimSpace(message),
	})
}
