package config

import (
	"errors"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	ListenAddr       string
	DatabaseDSN      string
	APIKeyID         string
	APISecret        string
	AllowedClockSkew time.Duration
	ShutdownTimeout  time.Duration
	LogLevel         string
	WebhookURL       string
	WebhookTimeout   time.Duration
}

func Load() (Config, error) {
	cfg := Config{
		ListenAddr:       env("WHATSAPP_GATEWAY_LISTEN_ADDR", ":8080"),
		DatabaseDSN:      env("WHATSAPP_GATEWAY_DATABASE_DSN", "file:data/whatsapp-gateway.db?_foreign_keys=on&_busy_timeout=5000&_journal_mode=WAL"),
		APIKeyID:         env("WHATSAPP_GATEWAY_API_KEY_ID", "ambulatoriofacile-app"),
		APISecret:        strings.TrimSpace(os.Getenv("WHATSAPP_GATEWAY_API_SECRET")),
		AllowedClockSkew: secondsEnv("WHATSAPP_GATEWAY_ALLOWED_CLOCK_SKEW_SECONDS", 300),
		ShutdownTimeout:  secondsEnv("WHATSAPP_GATEWAY_SHUTDOWN_TIMEOUT_SECONDS", 20),
		LogLevel:         strings.ToUpper(env("WHATSAPP_GATEWAY_LOG_LEVEL", "INFO")),
		WebhookURL:       strings.TrimSpace(os.Getenv("WHATSAPP_GATEWAY_WEBHOOK_URL")),
		WebhookTimeout:   secondsEnv("WHATSAPP_GATEWAY_WEBHOOK_TIMEOUT_SECONDS", 10),
	}

	if len(cfg.APISecret) < 32 {
		return Config{}, errors.New("WHATSAPP_GATEWAY_API_SECRET must contain at least 32 characters")
	}
	if cfg.APIKeyID == "" {
		return Config{}, errors.New("WHATSAPP_GATEWAY_API_KEY_ID must not be empty")
	}
	if cfg.AllowedClockSkew < 30*time.Second || cfg.AllowedClockSkew > 15*time.Minute {
		return Config{}, errors.New("WHATSAPP_GATEWAY_ALLOWED_CLOCK_SKEW_SECONDS must be between 30 and 900")
	}
	if cfg.WebhookTimeout < time.Second || cfg.WebhookTimeout > time.Minute {
		return Config{}, errors.New("WHATSAPP_GATEWAY_WEBHOOK_TIMEOUT_SECONDS must be between 1 and 60")
	}
	if cfg.WebhookURL != "" {
		parsed, err := url.Parse(cfg.WebhookURL)
		if err != nil || parsed.Scheme != "https" || parsed.Host == "" || parsed.User != nil || parsed.Fragment != "" {
			return Config{}, errors.New("WHATSAPP_GATEWAY_WEBHOOK_URL must be an absolute HTTPS URL without credentials or fragment")
		}
	}

	return cfg, nil
}

func env(key, fallback string) string {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}
	return value
}

func secondsEnv(key string, fallback int) time.Duration {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return time.Duration(fallback) * time.Second
	}
	seconds, err := strconv.Atoi(value)
	if err != nil || seconds <= 0 {
		return time.Duration(fallback) * time.Second
	}
	return time.Duration(seconds) * time.Second
}
