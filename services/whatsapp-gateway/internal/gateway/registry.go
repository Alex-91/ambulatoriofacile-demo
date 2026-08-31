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
