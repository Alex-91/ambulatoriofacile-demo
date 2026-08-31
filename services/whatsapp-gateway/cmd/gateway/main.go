package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/Alex-91/ambulatoriofacile-demo/services/whatsapp-gateway/internal/auth"
	"github.com/Alex-91/ambulatoriofacile-demo/services/whatsapp-gateway/internal/config"
	"github.com/Alex-91/ambulatoriofacile-demo/services/whatsapp-gateway/internal/gateway"
	"github.com/Alex-91/ambulatoriofacile-demo/services/whatsapp-gateway/internal/httpapi"
)

func main() {
	if len(os.Args) > 1 && os.Args[1] == "healthcheck" {
		if err := runHealthcheck(); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		return
	}

	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))
	cfg, err := config.Load()
	if err != nil {
		logger.Error("invalid configuration", "error", err)
		os.Exit(1)
	}

	ctx := context.Background()
	manager, err := gateway.NewManager(ctx, cfg.DatabaseDSN, cfg.LogLevel, logger)
	if err != nil {
		logger.Error("gateway initialization failed", "error", err)
		os.Exit(1)
	}

	handler := httpapi.New(manager, auth.New(cfg.APIKeyID, cfg.APISecret, cfg.AllowedClockSkew), logger)
	server := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           handler,
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      100 * time.Second,
		IdleTimeout:       120 * time.Second,
		MaxHeaderBytes:    32 << 10,
	}

	serverErrors := make(chan error, 1)
	go func() {
		logger.Info("AmbulatorioFacile WhatsApp Gateway started", "listen_addr", cfg.ListenAddr)
		serverErrors <- server.ListenAndServe()
	}()

	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)
	select {
	case received := <-signals:
		logger.Info("shutdown signal received", "signal", received.String())
	case err := <-serverErrors:
		if !errors.Is(err, http.ErrServerClosed) {
			logger.Error("http server failed", "error", err)
		}
	}

	shutdownCtx, cancel := context.WithTimeout(context.Background(), cfg.ShutdownTimeout)
	defer cancel()
	if err := server.Shutdown(shutdownCtx); err != nil {
		logger.Error("http shutdown failed", "error", err)
	}
	if err := manager.Close(); err != nil {
		logger.Error("gateway shutdown failed", "error", err)
	}
}

func runHealthcheck() error {
	address := os.Getenv("WHATSAPP_GATEWAY_HEALTHCHECK_URL")
	if address == "" {
		address = "http://127.0.0.1:8080/healthz"
	}
	client := &http.Client{Timeout: 3 * time.Second}
	response, err := client.Get(address)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return fmt.Errorf("healthcheck returned HTTP %d", response.StatusCode)
	}
	return nil
}
