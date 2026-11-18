CREATE TABLE IF NOT EXISTS orders (
    id BIGSERIAL PRIMARY KEY,
    order_code VARCHAR(32) NOT NULL UNIQUE,
    tracking_token_hash CHAR(64) NOT NULL,
    service TEXT NOT NULL,
    customer_name VARCHAR(180) NOT NULL,
    contact_method VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
    contact_value TEXT NOT NULL,
    email VARCHAR(254),
    player_id TEXT,
    jawaker_id TEXT,
    note TEXT,
    currency VARCHAR(12),
    amount NUMERIC(14,2),
    fee_percent NUMERIC(6,2),
    fee_amount NUMERIC(14,2),
    total_due NUMERIC(14,2),
    status VARCHAR(24) NOT NULL DEFAULT 'processing'
      CHECK (status IN ('processing','in_progress','completed','cancelled')),
    telegram_notified BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS orders_created_at_idx ON orders (created_at DESC);
CREATE INDEX IF NOT EXISTS orders_status_idx ON orders (status);
