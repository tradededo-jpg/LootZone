-- LootZone schema upgrade. Safe to run repeatedly; preserves existing orders.
CREATE TABLE IF NOT EXISTS orders (
 id BIGSERIAL PRIMARY KEY, order_code VARCHAR(32) NOT NULL UNIQUE,
 tracking_token_hash CHAR(64) NOT NULL, service TEXT NOT NULL,
 customer_name VARCHAR(180) NOT NULL, contact_method VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
 contact_value TEXT NOT NULL, email VARCHAR(254), player_id TEXT, jawaker_id TEXT, note TEXT,
 currency VARCHAR(12), amount NUMERIC(14,2), fee_percent NUMERIC(6,2), fee_amount NUMERIC(14,2), total_due NUMERIC(14,2),
 status VARCHAR(24) NOT NULL DEFAULT 'processing', telegram_notified BOOLEAN NOT NULL DEFAULT FALSE,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS app_users (
 firebase_uid TEXT PRIMARY KEY, email VARCHAR(254), display_name VARCHAR(180),
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS user_uid TEXT;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(64);
ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check;
ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('processing','accepted','in_progress','completed','cancelled'));
CREATE INDEX IF NOT EXISTS orders_created_at_idx ON orders(created_at DESC);
CREATE INDEX IF NOT EXISTS orders_status_idx ON orders(status);
DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='orders_user_uid_fk') THEN ALTER TABLE orders ADD CONSTRAINT orders_user_uid_fk FOREIGN KEY(user_uid) REFERENCES app_users(firebase_uid) ON DELETE SET NULL; END IF; END $$;
CREATE INDEX IF NOT EXISTS orders_user_uid_idx ON orders(user_uid,created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS orders_user_idempotency_unique ON orders(user_uid,idempotency_key) WHERE idempotency_key IS NOT NULL;

CREATE TABLE IF NOT EXISTS order_push_tokens (
 id BIGSERIAL PRIMARY KEY, order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
 fcm_token TEXT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), UNIQUE(order_id,fcm_token)
);
CREATE TABLE IF NOT EXISTS order_status_history (
 id BIGSERIAL PRIMARY KEY, order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
 old_status VARCHAR(24), new_status VARCHAR(24) NOT NULL, changed_by VARCHAR(80) NOT NULL DEFAULT 'system',
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS order_status_history_order_idx ON order_status_history(order_id,created_at DESC);
CREATE TABLE IF NOT EXISTS user_devices (
 id BIGSERIAL PRIMARY KEY, user_uid TEXT NOT NULL REFERENCES app_users(firebase_uid) ON DELETE CASCADE, fcm_token TEXT NOT NULL UNIQUE,
 platform VARCHAR(20) NOT NULL DEFAULT 'android', active BOOLEAN NOT NULL DEFAULT TRUE,
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS user_devices_uid_idx ON user_devices(user_uid);
CREATE TABLE IF NOT EXISTS favorites (
 id BIGSERIAL PRIMARY KEY, user_uid TEXT NOT NULL REFERENCES app_users(firebase_uid) ON DELETE CASCADE, item_id VARCHAR(120) NOT NULL,
 item_name TEXT NOT NULL, item_image TEXT, item_price TEXT,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), UNIQUE(user_uid,item_id)
);
CREATE INDEX IF NOT EXISTS favorites_uid_idx ON favorites(user_uid,created_at DESC);
CREATE TABLE IF NOT EXISTS notifications (
 id BIGSERIAL PRIMARY KEY, user_uid TEXT NOT NULL REFERENCES app_users(firebase_uid) ON DELETE CASCADE,
 order_id BIGINT REFERENCES orders(id) ON DELETE SET NULL, order_code VARCHAR(32),
 title TEXT NOT NULL, body TEXT NOT NULL, status_key VARCHAR(24),
 is_read BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS notifications_order_status_unique ON notifications(order_id,status_key) WHERE order_id IS NOT NULL AND status_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS notifications_uid_idx ON notifications(user_uid,created_at DESC);
