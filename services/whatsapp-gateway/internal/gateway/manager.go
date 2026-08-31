package gateway

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"regexp"
	"strings"
	"sync"
	"time"

	"go.mau.fi/whatsmeow"
	waE2E "go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/store/sqlstore"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	waLog "go.mau.fi/whatsmeow/util/log"
	"google.golang.org/protobuf/proto"
)

var (
	ErrAccountNotFound = errors.New("whatsapp account not found")
	ErrAlreadyPaired   = errors.New("whatsapp account is already paired")
	ErrNotPaired       = errors.New("whatsapp account is not paired")
	ErrQRCodePending   = errors.New("qr code is not available yet")
	ErrInvalidPhone    = errors.New("invalid international phone number")
)

var accountIDPattern = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,62}$`)

type AccountStatus struct {
	TenantID    int64      `json:"tenant_id"`
	AccountID   string     `json:"account_id"`
	DisplayName string     `json:"display_name,omitempty"`
	State       string     `json:"state"`
	DeviceJID   string     `json:"device_jid,omitempty"`
	Connected   bool       `json:"connected"`
	LoggedIn    bool       `json:"logged_in"`
	QRCode      string     `json:"qr_code,omitempty"`
	QRExpiresAt *time.Time `json:"qr_expires_at,omitempty"`
	LastError   string     `json:"last_error,omitempty"`
	UpdatedAt   time.Time  `json:"updated_at"`
}

type SendResult struct {
	MessageID string    `json:"message_id"`
	To        string    `json:"to"`
	SentAt    time.Time `json:"sent_at"`
}

type session struct {
	mu          sync.RWMutex
	tenantID    int64
	accountID   string
	displayName string
	client      *whatsmeow.Client
	state       string
	qrCode      string
	qrExpiresAt time.Time
	lastError   string
	updatedAt   time.Time
	pairCancel  context.CancelFunc
}

type Manager struct {
	container *sqlstore.Container
	registry  *Registry
	logger    *slog.Logger
	waLogger  waLog.Logger
	mu        sync.RWMutex
	sessions  map[string]*session
}

func NewManager(ctx context.Context, dsn, logLevel string, logger *slog.Logger) (*Manager, error) {
	registry, err := OpenRegistry(ctx, dsn)
	if err != nil {
		return nil, fmt.Errorf("open account registry: %w", err)
	}

	waLogger := waLog.Stdout("WhatsAppGateway", logLevel, true)
	container, err := sqlstore.New(ctx, "sqlite3", dsn, waLogger.Sub("Database"))
	if err != nil {
		_ = registry.Close()
		return nil, fmt.Errorf("open whatsmeow store: %w", err)
	}

	manager := &Manager{
		container: container,
		registry:  registry,
		logger:    logger,
		waLogger:  waLogger,
		sessions:  make(map[string]*session),
	}
	if err := manager.restore(ctx); err != nil {
		_ = manager.Close()
		return nil, err
	}
	return manager, nil
}

func ValidateAccountID(accountID string) error {
	if !accountIDPattern.MatchString(accountID) {
		return fmt.Errorf("account_id must match %s", accountIDPattern.String())
	}
	return nil
}

func (m *Manager) Ready(ctx context.Context) error {
	return m.registry.Ping(ctx)
}

func (m *Manager) Close() error {
	m.mu.Lock()
	currentSessions := make([]*session, 0, len(m.sessions))
	for _, current := range m.sessions {
		currentSessions = append(currentSessions, current)
	}
	m.sessions = make(map[string]*session)
	m.mu.Unlock()
	for _, current := range currentSessions {
		current.stop()
	}

	containerErr := m.container.Close()
	registryErr := m.registry.Close()
	return errors.Join(containerErr, registryErr)
}

func (m *Manager) Status(ctx context.Context, tenantID int64, accountID string) (AccountStatus, error) {
	current, err := m.getOrLoad(ctx, tenantID, accountID)
	if err != nil {
		return AccountStatus{}, err
	}
	return current.snapshot(), nil
}

func (m *Manager) StartPairing(ctx context.Context, tenantID int64, accountID, displayName string) (AccountStatus, error) {
	if tenantID <= 0 {
		return AccountStatus{}, fmt.Errorf("tenant_id must be positive")
	}
	if err := ValidateAccountID(accountID); err != nil {
		return AccountStatus{}, err
	}

	key := sessionKey(tenantID, accountID)
	m.mu.Lock()
	if existing := m.sessions[key]; existing != nil {
		status := existing.snapshot()
		if status.LoggedIn {
			m.mu.Unlock()
			return status, ErrAlreadyPaired
		}
		if status.State == "pairing" && status.QRExpiresAt != nil && status.QRExpiresAt.After(time.Now().UTC()) {
			m.mu.Unlock()
			return status, nil
		}
		existing.stop()
		delete(m.sessions, key)
	}

	device := m.container.NewDevice()
	client := whatsmeow.NewClient(device, m.waLogger.Sub(fmt.Sprintf("Tenant%d-%s", tenantID, accountID)))
	pairCtx, cancel := context.WithTimeout(context.Background(), 3*time.Minute)
	current := &session{
		tenantID:    tenantID,
		accountID:   accountID,
		displayName: strings.TrimSpace(displayName),
		client:      client,
		state:       "pairing",
		updatedAt:   time.Now().UTC(),
		pairCancel:  cancel,
	}
	client.AddEventHandler(func(evt any) { m.handleEvent(current, evt) })
	m.sessions[key] = current
	m.mu.Unlock()

	if err := m.registry.Upsert(ctx, tenantID, accountID, displayName); err != nil {
		m.removeSession(key)
		return AccountStatus{}, err
	}

	qrChannel, err := client.GetQRChannel(pairCtx)
	if err != nil {
		m.removeSession(key)
		return AccountStatus{}, err
	}
	go m.consumeQR(current, qrChannel)

	if err := client.ConnectContext(pairCtx); err != nil {
		current.setError(err)
		return current.snapshot(), err
	}
	return current.snapshot(), nil
}

func (m *Manager) Connect(ctx context.Context, tenantID int64, accountID string) (AccountStatus, error) {
	current, err := m.getOrLoad(ctx, tenantID, accountID)
	if err != nil {
		return AccountStatus{}, err
	}
	if current.client.Store.ID == nil {
		return current.snapshot(), ErrNotPaired
	}
	if !current.client.IsConnected() {
		current.setState("connecting")
		if err := current.client.ConnectContext(ctx); err != nil {
			current.setError(err)
			return current.snapshot(), err
		}
	}
	return current.snapshot(), nil
}

func (m *Manager) QRCode(ctx context.Context, tenantID int64, accountID string) (AccountStatus, error) {
	status, err := m.Status(ctx, tenantID, accountID)
	if err != nil {
		return AccountStatus{}, err
	}
	if status.QRCode == "" || status.QRExpiresAt == nil || status.QRExpiresAt.Before(time.Now().UTC()) {
		return status, ErrQRCodePending
	}
	return status, nil
}

func (m *Manager) Logout(ctx context.Context, tenantID int64, accountID string) error {
	key := sessionKey(tenantID, accountID)
	current, err := m.getOrLoad(ctx, tenantID, accountID)
	if err != nil {
		return err
	}
	if current.client.Store.ID != nil {
		if err := current.client.Logout(ctx); err != nil {
			return err
		}
	} else {
		current.client.Disconnect()
	}
	if err := m.registry.Delete(ctx, tenantID, accountID); err != nil {
		return err
	}
	m.removeSession(key)
	return nil
}

func (m *Manager) SendText(ctx context.Context, tenantID int64, accountID, phone, text string) (SendResult, error) {
	text = strings.TrimSpace(text)
	if text == "" || len([]rune(text)) > 4096 {
		return SendResult{}, fmt.Errorf("text must contain between 1 and 4096 characters")
	}
	normalizedPhone, err := NormalizePhone(phone)
	if err != nil {
		return SendResult{}, err
	}

	current, err := m.getOrLoad(ctx, tenantID, accountID)
	if err != nil {
		return SendResult{}, err
	}
	if current.client.Store.ID == nil {
		return SendResult{}, ErrNotPaired
	}
	if !current.client.IsConnected() {
		if err := current.client.ConnectContext(ctx); err != nil {
			current.setError(err)
			return SendResult{}, err
		}
	}

	to := types.NewJID(normalizedPhone, types.DefaultUserServer)
	response, err := current.client.SendMessage(ctx, to, &waE2E.Message{Conversation: proto.String(text)})
	if err != nil {
		current.setError(err)
		return SendResult{}, err
	}
	return SendResult{
		MessageID: response.ID,
		To:        "+" + normalizedPhone,
		SentAt:    response.Timestamp.UTC(),
	}, nil
}

func NormalizePhone(phone string) (string, error) {
	phone = strings.TrimSpace(phone)
	phone = strings.NewReplacer(" ", "", "-", "", "(", "", ")", "").Replace(phone)
	if !strings.HasPrefix(phone, "+") && !strings.HasPrefix(phone, "00") {
		return "", ErrInvalidPhone
	}
	if strings.HasPrefix(phone, "00") {
		phone = phone[2:]
	} else {
		phone = strings.TrimPrefix(phone, "+")
	}
	if len(phone) < 8 || len(phone) > 15 {
		return "", ErrInvalidPhone
	}
	for _, char := range phone {
		if char < '0' || char > '9' {
			return "", ErrInvalidPhone
		}
	}
	if phone[0] == '0' {
		return "", ErrInvalidPhone
	}
	return phone, nil
}

func (m *Manager) restore(ctx context.Context) error {
	records, err := m.registry.List(ctx)
	if err != nil {
		return err
	}
	for _, record := range records {
		if record.DeviceJID == "" {
			continue
		}
		jid, err := types.ParseJID(record.DeviceJID)
		if err != nil {
			m.logger.Error("invalid stored WhatsApp device JID", "tenant_id", record.TenantID, "account_id", record.AccountID, "error", err)
			continue
		}
		device, err := m.container.GetDevice(ctx, jid)
		if err != nil || device == nil {
			m.logger.Error("unable to restore WhatsApp device", "tenant_id", record.TenantID, "account_id", record.AccountID, "error", err)
			continue
		}
		client := whatsmeow.NewClient(device, m.waLogger.Sub(fmt.Sprintf("Tenant%d-%s", record.TenantID, record.AccountID)))
		current := &session{
			tenantID:    record.TenantID,
			accountID:   record.AccountID,
			displayName: record.DisplayName,
			client:      client,
			state:       "disconnected",
			updatedAt:   time.Now().UTC(),
		}
		client.AddEventHandler(func(evt any) { m.handleEvent(current, evt) })
		m.sessions[sessionKey(record.TenantID, record.AccountID)] = current
		go func(account *session) {
			connectCtx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
			defer cancel()
			account.setState("connecting")
			if err := account.client.ConnectContext(connectCtx); err != nil {
				account.setError(err)
				m.logger.Warn("WhatsApp reconnect failed", "tenant_id", account.tenantID, "account_id", account.accountID, "error", err)
			}
		}(current)
	}
	return nil
}

func (m *Manager) getOrLoad(ctx context.Context, tenantID int64, accountID string) (*session, error) {
	if err := ValidateAccountID(accountID); err != nil {
		return nil, err
	}
	key := sessionKey(tenantID, accountID)
	m.mu.RLock()
	current := m.sessions[key]
	m.mu.RUnlock()
	if current != nil {
		return current, nil
	}

	record, err := m.registry.Get(ctx, tenantID, accountID)
	if err != nil {
		return nil, err
	}
	if record.DeviceJID == "" {
		return nil, ErrNotPaired
	}
	jid, err := types.ParseJID(record.DeviceJID)
	if err != nil {
		return nil, err
	}
	device, err := m.container.GetDevice(ctx, jid)
	if err != nil {
		return nil, err
	}
	if device == nil {
		return nil, ErrAccountNotFound
	}
	client := whatsmeow.NewClient(device, m.waLogger.Sub(fmt.Sprintf("Tenant%d-%s", tenantID, accountID)))
	current = &session{tenantID: tenantID, accountID: accountID, displayName: record.DisplayName, client: client, state: "disconnected", updatedAt: time.Now().UTC()}
	client.AddEventHandler(func(evt any) { m.handleEvent(current, evt) })
	m.mu.Lock()
	if existing := m.sessions[key]; existing != nil {
		m.mu.Unlock()
		return existing, nil
	}
	m.sessions[key] = current
	m.mu.Unlock()
	return current, nil
}

func (m *Manager) handleEvent(current *session, evt any) {
	switch value := evt.(type) {
	case *events.PairSuccess:
		current.setState("paired")
		if err := m.registry.BindDevice(context.Background(), current.tenantID, current.accountID, value.ID.String()); err != nil {
			current.setError(err)
			m.logger.Error("failed to bind paired WhatsApp device", "tenant_id", current.tenantID, "account_id", current.accountID, "error", err)
		}
	case *events.Connected:
		current.setState("connected")
	case *events.Disconnected:
		current.setState("disconnected")
	case *events.LoggedOut:
		current.setState("logged_out")
	case *events.PairError:
		current.setError(value.Error)
	case *events.ClientOutdated:
		current.setError(errors.New("whatsmeow client is outdated"))
	}
}

func (m *Manager) consumeQR(current *session, channel <-chan whatsmeow.QRChannelItem) {
	for item := range channel {
		if item.Event == whatsmeow.QRChannelEventCode {
			current.setQR(item.Code, time.Now().UTC().Add(item.Timeout))
			continue
		}
		if item.Event == whatsmeow.QRChannelEventError && item.Error != nil {
			current.setError(item.Error)
			continue
		}
		current.setState(item.Event)
	}
}

func (m *Manager) removeSession(key string) {
	m.mu.Lock()
	current := m.sessions[key]
	delete(m.sessions, key)
	m.mu.Unlock()
	if current != nil {
		current.stop()
	}
}

func sessionKey(tenantID int64, accountID string) string {
	return fmt.Sprintf("%d:%s", tenantID, accountID)
}

func (s *session) snapshot() AccountStatus {
	s.mu.RLock()
	defer s.mu.RUnlock()
	status := AccountStatus{
		TenantID:    s.tenantID,
		AccountID:   s.accountID,
		DisplayName: s.displayName,
		State:       s.state,
		Connected:   s.client.IsConnected(),
		LoggedIn:    s.client.IsLoggedIn(),
		QRCode:      s.qrCode,
		LastError:   s.lastError,
		UpdatedAt:   s.updatedAt,
	}
	if s.client.Store.ID != nil {
		status.DeviceJID = s.client.Store.ID.String()
	}
	if !s.qrExpiresAt.IsZero() {
		expires := s.qrExpiresAt
		status.QRExpiresAt = &expires
	}
	return status
}

func (s *session) setState(state string) {
	s.mu.Lock()
	s.state = state
	if state == "connected" || state == "paired" || state == "success" {
		s.lastError = ""
	}
	if state != "pairing" && state != whatsmeow.QRChannelEventCode {
		s.qrCode = ""
		s.qrExpiresAt = time.Time{}
	}
	s.updatedAt = time.Now().UTC()
	s.mu.Unlock()
}

func (s *session) setQR(code string, expires time.Time) {
	s.mu.Lock()
	s.state = "pairing"
	s.qrCode = code
	s.qrExpiresAt = expires
	s.updatedAt = time.Now().UTC()
	s.mu.Unlock()
}

func (s *session) setError(err error) {
	if err == nil {
		return
	}
	s.mu.Lock()
	s.state = "error"
	s.lastError = err.Error()
	s.updatedAt = time.Now().UTC()
	s.mu.Unlock()
}

func (s *session) stop() {
	s.mu.Lock()
	cancel := s.pairCancel
	s.pairCancel = nil
	client := s.client
	s.mu.Unlock()
	if cancel != nil {
		cancel()
	}
	client.Disconnect()
}
