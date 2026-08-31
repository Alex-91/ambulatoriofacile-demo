package gateway

import (
	"context"
	"database/sql"
	"errors"
	"time"

	_ "github.com/mattn/go-sqlite3"
)

type AccountRecord struct {
	TenantID    int64
	AccountID   string
	DeviceJID   string
	DisplayName string
	CreatedAt   time.Time
	UpdatedAt   time.Time
}

type IncomingMessage struct {
	TenantID    int64     `json:"-"`
	AccountID   string    `json:"-"`
	MessageID   string    `json:"message_id"`
	ChatJID     string    `json:"-"`
	SenderJID   string    `json:"-"`
	From        string    `json:"from"`
	To          string    `json:"to,omitempty"`
	Peer        string    `json:"peer"`
	SenderName  string    `json:"sender_name,omitempty"`
	Text        string    `json:"text"`
	MessageType string    `json:"message_type"`
	Direction   string    `json:"direction"`
	IsGroup     bool      `json:"is_group"`
	ReceivedAt  time.Time `json:"received_at"`
	CreatedAt   time.Time `json:"stored_at"`
}

type Registry struct {
	db *sql.DB
}

func OpenRegistry(ctx context.Context, dsn string) (*Registry, error) {
	db, err := sql.Open("sqlite3", dsn)
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1)

	registry := &Registry{db: db}
	if err := registry.migrate(ctx); err != nil {
		_ = db.Close()
		return nil, err
	}
	return registry, nil
}

func (r *Registry) migrate(ctx context.Context) error {
	_, err := r.db.ExecContext(ctx, `
CREATE TABLE IF NOT EXISTS gateway_accounts (
    tenant_id INTEGER NOT NULL CHECK (tenant_id > 0),
    account_id TEXT NOT NULL,
    device_jid TEXT NULL,
    display_name TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (tenant_id, account_id),
    UNIQUE (device_jid)
);
CREATE INDEX IF NOT EXISTS idx_gateway_accounts_tenant
    ON gateway_accounts (tenant_id, account_id);

CREATE TABLE IF NOT EXISTS gateway_incoming_messages (
    tenant_id INTEGER NOT NULL CHECK (tenant_id > 0),
    account_id TEXT NOT NULL,
    message_id TEXT NOT NULL,
    chat_jid TEXT NOT NULL,
    sender_jid TEXT NOT NULL,
    sender_address TEXT NOT NULL,
    sender_name TEXT NOT NULL DEFAULT '',
    text_content TEXT NOT NULL DEFAULT '',
    message_type TEXT NOT NULL,
    is_group INTEGER NOT NULL DEFAULT 0 CHECK (is_group IN (0, 1)),
    received_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (tenant_id, account_id, chat_jid, message_id)
);
CREATE INDEX IF NOT EXISTS idx_gateway_incoming_messages_account_time
    ON gateway_incoming_messages (tenant_id, account_id, received_at DESC);

CREATE TABLE IF NOT EXISTS gateway_messages (
    tenant_id INTEGER NOT NULL CHECK (tenant_id > 0),
    account_id TEXT NOT NULL,
    message_id TEXT NOT NULL,
    chat_jid TEXT NOT NULL,
    sender_jid TEXT NOT NULL,
    sender_address TEXT NOT NULL DEFAULT '',
    recipient_address TEXT NOT NULL DEFAULT '',
    peer_address TEXT NOT NULL,
    sender_name TEXT NOT NULL DEFAULT '',
    text_content TEXT NOT NULL DEFAULT '',
    message_type TEXT NOT NULL,
    direction TEXT NOT NULL CHECK (direction IN ('incoming', 'outgoing')),
    is_group INTEGER NOT NULL DEFAULT 0 CHECK (is_group IN (0, 1)),
    occurred_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (tenant_id, account_id, chat_jid, message_id)
);
CREATE INDEX IF NOT EXISTS idx_gateway_messages_account_time
    ON gateway_messages (tenant_id, account_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_gateway_messages_account_peer_time
    ON gateway_messages (tenant_id, account_id, peer_address, occurred_at DESC);

INSERT OR IGNORE INTO gateway_messages (
    tenant_id, account_id, message_id, chat_jid, sender_jid, sender_address,
    recipient_address, peer_address, sender_name, text_content, message_type,
    direction, is_group, occurred_at, created_at
)
SELECT tenant_id, account_id, message_id, chat_jid, sender_jid, sender_address,
       '', sender_address, sender_name, text_content, message_type,
       'incoming', is_group, received_at, created_at
FROM gateway_incoming_messages;
`)
	return err
}

func (r *Registry) Ping(ctx context.Context) error {
	return r.db.PingContext(ctx)
}

func (r *Registry) Close() error {
	return r.db.Close()
}

func (r *Registry) List(ctx context.Context) ([]AccountRecord, error) {
	rows, err := r.db.QueryContext(ctx, `
SELECT tenant_id, account_id, COALESCE(device_jid, ''), display_name, created_at, updated_at
FROM gateway_accounts
ORDER BY tenant_id, account_id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var records []AccountRecord
	for rows.Next() {
		record, err := scanAccount(rows)
		if err != nil {
			return nil, err
		}
		records = append(records, record)
	}
	return records, rows.Err()
}

func (r *Registry) Get(ctx context.Context, tenantID int64, accountID string) (AccountRecord, error) {
	row := r.db.QueryRowContext(ctx, `
SELECT tenant_id, account_id, COALESCE(device_jid, ''), display_name, created_at, updated_at
FROM gateway_accounts
WHERE tenant_id = ? AND account_id = ?`, tenantID, accountID)
	record, err := scanAccount(row)
	if errors.Is(err, sql.ErrNoRows) {
		return AccountRecord{}, ErrAccountNotFound
	}
	return record, err
}

func (r *Registry) Upsert(ctx context.Context, tenantID int64, accountID, displayName string) error {
	now := time.Now().UTC().Format(time.RFC3339Nano)
	_, err := r.db.ExecContext(ctx, `
INSERT INTO gateway_accounts (tenant_id, account_id, display_name, created_at, updated_at)
VALUES (?, ?, ?, ?, ?)
ON CONFLICT (tenant_id, account_id) DO UPDATE SET
    display_name = CASE WHEN excluded.display_name = '' THEN gateway_accounts.display_name ELSE excluded.display_name END,
    updated_at = excluded.updated_at`, tenantID, accountID, displayName, now, now)
	return err
}

func (r *Registry) BindDevice(ctx context.Context, tenantID int64, accountID, deviceJID string) error {
	result, err := r.db.ExecContext(ctx, `
UPDATE gateway_accounts
SET device_jid = ?, updated_at = ?
WHERE tenant_id = ? AND account_id = ?`, deviceJID, time.Now().UTC().Format(time.RFC3339Nano), tenantID, accountID)
	if err != nil {
		return err
	}
	affected, err := result.RowsAffected()
	if err == nil && affected == 0 {
		return ErrAccountNotFound
	}
	return err
}

func (r *Registry) Delete(ctx context.Context, tenantID int64, accountID string) error {
	_, err := r.db.ExecContext(ctx, `DELETE FROM gateway_accounts WHERE tenant_id = ? AND account_id = ?`, tenantID, accountID)
	return err
}

func (r *Registry) StoreIncoming(ctx context.Context, message IncomingMessage) (bool, error) {
	now := time.Now().UTC()
	message.Direction = "incoming"
	if message.Peer == "" {
		message.Peer = message.From
	}
	if message.ReceivedAt.IsZero() {
		message.ReceivedAt = now
	}
	if message.CreatedAt.IsZero() {
		message.CreatedAt = now
	}
	return r.storeMessage(ctx, message)
}

func (r *Registry) StoreOutgoing(ctx context.Context, message IncomingMessage) (bool, error) {
	now := time.Now().UTC()
	message.Direction = "outgoing"
	if message.Peer == "" {
		message.Peer = message.To
	}
	if message.ReceivedAt.IsZero() {
		message.ReceivedAt = now
	}
	if message.CreatedAt.IsZero() {
		message.CreatedAt = now
	}
	return r.storeMessage(ctx, message)
}

func (r *Registry) storeMessage(ctx context.Context, message IncomingMessage) (bool, error) {
	result, err := r.db.ExecContext(ctx, `
INSERT INTO gateway_messages (
    tenant_id, account_id, message_id, chat_jid, sender_jid, sender_address,
    recipient_address, peer_address, sender_name, text_content, message_type,
    direction, is_group, occurred_at, created_at
)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON CONFLICT (tenant_id, account_id, chat_jid, message_id) DO NOTHING`,
		message.TenantID,
		message.AccountID,
		message.MessageID,
		message.ChatJID,
		message.SenderJID,
		message.From,
		message.To,
		message.Peer,
		message.SenderName,
		message.Text,
		message.MessageType,
		message.Direction,
		message.IsGroup,
		message.ReceivedAt.UTC().Format(time.RFC3339Nano),
		message.CreatedAt.UTC().Format(time.RFC3339Nano),
	)
	if err != nil {
		return false, err
	}
	affected, err := result.RowsAffected()
	return affected > 0, err
}

func (r *Registry) ListIncoming(ctx context.Context, tenantID int64, accountID string, limit int) ([]IncomingMessage, error) {
	return r.listMessages(ctx, tenantID, accountID, limit, "incoming")
}

func (r *Registry) ListMessages(ctx context.Context, tenantID int64, accountID string, limit int) ([]IncomingMessage, error) {
	return r.listMessages(ctx, tenantID, accountID, limit, "")
}

func (r *Registry) listMessages(ctx context.Context, tenantID int64, accountID string, limit int, direction string) ([]IncomingMessage, error) {
	if limit <= 0 {
		limit = 50
	}
	if limit > 100 {
		limit = 100
	}
	rows, err := r.db.QueryContext(ctx, `
SELECT tenant_id, account_id, message_id, chat_jid, sender_jid, sender_address,
       recipient_address, peer_address, sender_name, text_content, message_type,
       direction, is_group, occurred_at, created_at
FROM gateway_messages
WHERE tenant_id = ? AND account_id = ?
  AND (? = '' OR direction = ?)
ORDER BY occurred_at DESC, created_at DESC
LIMIT ?`, tenantID, accountID, direction, direction, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	messages := make([]IncomingMessage, 0)
	for rows.Next() {
		message, err := scanIncomingMessage(rows)
		if err != nil {
			return nil, err
		}
		messages = append(messages, message)
	}
	return messages, rows.Err()
}

type scanner interface {
	Scan(dest ...any) error
}

func scanAccount(row scanner) (AccountRecord, error) {
	var record AccountRecord
	var createdAt string
	var updatedAt string
	err := row.Scan(&record.TenantID, &record.AccountID, &record.DeviceJID, &record.DisplayName, &createdAt, &updatedAt)
	if err != nil {
		return AccountRecord{}, err
	}
	record.CreatedAt, _ = time.Parse(time.RFC3339Nano, createdAt)
	record.UpdatedAt, _ = time.Parse(time.RFC3339Nano, updatedAt)
	return record, nil
}

func scanIncomingMessage(row scanner) (IncomingMessage, error) {
	var message IncomingMessage
	var isGroup int
	var receivedAt string
	var createdAt string
	err := row.Scan(
		&message.TenantID,
		&message.AccountID,
		&message.MessageID,
		&message.ChatJID,
		&message.SenderJID,
		&message.From,
		&message.To,
		&message.Peer,
		&message.SenderName,
		&message.Text,
		&message.MessageType,
		&message.Direction,
		&isGroup,
		&receivedAt,
		&createdAt,
	)
	if err != nil {
		return IncomingMessage{}, err
	}
	message.IsGroup = isGroup != 0
	message.ReceivedAt, _ = time.Parse(time.RFC3339Nano, receivedAt)
	message.CreatedAt, _ = time.Parse(time.RFC3339Nano, createdAt)
	return message, nil
}
