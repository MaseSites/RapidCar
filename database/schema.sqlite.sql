-- ===========================================================================
-- VehicleAI — SQLite-Schema (lokale Entwicklung/Demo)
-- Spiegelt schema.mysql.sql; Datentypen an SQLite angepasst.
-- ===========================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS dealerships (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    account_type   TEXT NOT NULL DEFAULT 'dealer', -- dealer | private
    logo_path     TEXT,
    address       TEXT,
    zip           TEXT,
    city          TEXT,
    country       TEXT DEFAULT 'CH',
    phone         TEXT,
    email         TEXT,
    website       TEXT,
    instagram     TEXT,
    opening_hours TEXT,
    currency      TEXT NOT NULL DEFAULT 'CHF',
    language      TEXT NOT NULL DEFAULT 'de',
    credits       INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id           INTEGER REFERENCES dealerships(id) ON DELETE SET NULL,
    first_name              TEXT NOT NULL,
    last_name               TEXT NOT NULL,
    email                   TEXT NOT NULL UNIQUE,
    username                TEXT DEFAULT NULL UNIQUE,
    password_hash           TEXT NOT NULL,
    phone                   TEXT,
    country                 TEXT DEFAULT 'CH',
    role                    TEXT NOT NULL DEFAULT 'dealer_user',
    language                TEXT,
    is_active               INTEGER NOT NULL DEFAULT 1,
    is_demo                 INTEGER NOT NULL DEFAULT 0,
    email_verified_at       TEXT,
    onboarding_completed_at TEXT,
    last_login_at           TEXT,
    created_at              TEXT NOT NULL,
    updated_at              TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_users_dealership ON users(dealership_id);

CREATE TABLE IF NOT EXISTS vehicles (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id      INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    created_by         INTEGER,
    make               TEXT,
    model              TEXT,
    variant            TEXT,
    year               INTEGER,
    first_registration TEXT,
    mileage            INTEGER,
    price              REAL,
    power_hp           INTEGER,
    power_kw           INTEGER,
    displacement_ccm   INTEGER,
    transmission       TEXT,
    drivetrain         TEXT,
    fuel_type          TEXT,
    color              TEXT,
    interior_color     TEXT,
    doors              INTEGER,
    seats              INTEGER,
    vin                TEXT,
    body_type        TEXT DEFAULT NULL,
    condition_state  TEXT DEFAULT NULL,
    cylinders        INTEGER DEFAULT NULL,
    engine_layout    TEXT DEFAULT NULL,
    gears            INTEGER DEFAULT NULL,
    consumption      REAL DEFAULT NULL,
    co2_emission     INTEGER DEFAULT NULL,
    energy_class     TEXT DEFAULT NULL,
    euro_norm        TEXT DEFAULT NULL,
    length_mm        INTEGER DEFAULT NULL,
    width_mm         INTEGER DEFAULT NULL,
    height_mm        INTEGER DEFAULT NULL,
    weight_empty_kg  INTEGER DEFAULT NULL,
    weight_total_kg  INTEGER DEFAULT NULL,
    payload_kg       INTEGER DEFAULT NULL,
    type_certificate TEXT DEFAULT NULL,
    license_category TEXT DEFAULT NULL,
    is_import        INTEGER DEFAULT NULL,
    is_tuned         INTEGER DEFAULT NULL,
    is_race_car      INTEGER DEFAULT NULL,
    is_accessible    INTEGER DEFAULT NULL,
    has_mfk          INTEGER DEFAULT NULL,
    accident_free    INTEGER DEFAULT NULL,
    has_warranty     INTEGER DEFAULT NULL,
    warranty_months  INTEGER DEFAULT NULL,
    warranty_note    TEXT DEFAULT NULL,
    description        TEXT,
    ai_detections    INTEGER NOT NULL DEFAULT 0,
    ai_documents     INTEGER NOT NULL DEFAULT 0,
    status             TEXT NOT NULL DEFAULT 'draft',
    created_at         TEXT NOT NULL,
    updated_at         TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vehicles_dealership ON vehicles(dealership_id);
CREATE INDEX IF NOT EXISTS idx_vehicles_status ON vehicles(status);

CREATE TABLE IF NOT EXISTS vehicle_images (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id       INTEGER NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
    file_path        TEXT NOT NULL,
    spyne_job        TEXT DEFAULT NULL,
    spyne_scene      TEXT DEFAULT NULL,
    thumb_path       TEXT,
    card_path        TEXT,
    original_name    TEXT,
    width            INTEGER,
    height           INTEGER,
    file_size        INTEGER,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    is_main          INTEGER NOT NULL DEFAULT 0,
    ai_quality_score INTEGER,
    ai_analysis      TEXT,
    created_at       TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vimages_vehicle ON vehicle_images(vehicle_id);

CREATE TABLE IF NOT EXISTS vehicle_features (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
    feature    TEXT NOT NULL,
    source     TEXT NOT NULL DEFAULT 'manual',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vfeatures_vehicle ON vehicle_features(vehicle_id);

CREATE TABLE IF NOT EXISTS vehicle_field_status (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id INTEGER NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
    field_name TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'manual',
    confidence INTEGER,
    updated_at TEXT NOT NULL,
    UNIQUE (vehicle_id, field_name)
);

CREATE TABLE IF NOT EXISTS listings (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    vehicle_id    INTEGER NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    title         TEXT,
    description   TEXT,
    status        TEXT NOT NULL DEFAULT 'draft',
    credit_charged INTEGER NOT NULL DEFAULT 0,
    published_at  TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_listings_vehicle ON listings(vehicle_id);
CREATE INDEX IF NOT EXISTS idx_listings_dealership ON listings(dealership_id);

CREATE TABLE IF NOT EXISTS listing_scores (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    listing_id        INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    total_score       INTEGER NOT NULL DEFAULT 0,
    photos_score      INTEGER,
    title_score       INTEGER,
    description_score INTEGER,
    price_score       INTEGER,
    data_score        INTEGER,
    engine            TEXT NOT NULL DEFAULT 'rules',
    details           TEXT,
    created_at        TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_lscores_listing ON listing_scores(listing_id);

CREATE TABLE IF NOT EXISTS listing_recommendations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    listing_id   INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    category     TEXT NOT NULL,
    severity     TEXT NOT NULL DEFAULT 'info',
    message      TEXT NOT NULL,
    action_label TEXT,
    is_resolved  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_lrecs_listing ON listing_recommendations(listing_id);

CREATE TABLE IF NOT EXISTS leads (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id  INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    vehicle_id     INTEGER REFERENCES vehicles(id) ON DELETE SET NULL,
    customer_name  TEXT NOT NULL,
    customer_email TEXT,
    customer_phone TEXT,
    status         TEXT NOT NULL DEFAULT 'new',
    score          INTEGER,
    source         TEXT,
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_leads_dealership ON leads(dealership_id);

CREATE TABLE IF NOT EXISTS messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id     INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
    direction   TEXT NOT NULL DEFAULT 'inbound',
    sender_name TEXT,
    body        TEXT NOT NULL,
    is_ai_draft INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_messages_lead ON messages(lead_id);

CREATE TABLE IF NOT EXISTS social_posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    vehicle_id    INTEGER REFERENCES vehicles(id) ON DELETE SET NULL,
    template_key  TEXT,
    platform      TEXT NOT NULL DEFAULT 'instagram',
    caption       TEXT,
    image_path    TEXT,
    image_ids     TEXT,
    status        TEXT NOT NULL DEFAULT 'draft',
    published_at  TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sposts_dealership ON social_posts(dealership_id);

CREATE TABLE IF NOT EXISTS social_templates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER REFERENCES dealerships(id) ON DELETE CASCADE,
    template_key  TEXT NOT NULL,
    name          TEXT NOT NULL,
    config        TEXT,
    is_system     INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    user_id       INTEGER,
    lead_id       INTEGER REFERENCES leads(id) ON DELETE CASCADE,
    vehicle_id    INTEGER REFERENCES vehicles(id) ON DELETE CASCADE,
    title         TEXT NOT NULL,
    description   TEXT,
    due_at        TEXT,
    status        TEXT NOT NULL DEFAULT 'open',
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tasks_dealership ON tasks(dealership_id);

CREATE TABLE IF NOT EXISTS documents (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    vehicle_id    INTEGER REFERENCES vehicles(id) ON DELETE CASCADE,
    file_path     TEXT NOT NULL,
    original_name TEXT,
    mime_type     TEXT,
    file_size     INTEGER,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS integrations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    provider      TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'disconnected',
    account_name  TEXT,
    connected_at  TEXT,
    last_sync_at  TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    UNIQUE (dealership_id, provider)
);

CREATE TABLE IF NOT EXISTS integration_tokens (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    provider      TEXT NOT NULL,
    access_token  TEXT,
    refresh_token TEXT,
    expires_at    TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    UNIQUE (dealership_id, provider)
);

CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type       TEXT NOT NULL,
    title      TEXT NOT NULL,
    body       TEXT,
    link       TEXT,
    read_at    TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id);

CREATE TABLE IF NOT EXISTS activity_logs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER,
    dealership_id INTEGER,
    action        TEXT NOT NULL,
    description   TEXT NOT NULL,
    entity_type   TEXT,
    entity_id     INTEGER,
    ip_address    TEXT,
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_alogs_created ON activity_logs(created_at);

CREATE TABLE IF NOT EXISTS subscriptions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    plan          TEXT NOT NULL DEFAULT 'free',
    status        TEXT NOT NULL DEFAULT 'active',
    started_at    TEXT,
    ends_at       TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS credit_orders (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    package_key   TEXT NOT NULL,
    credits       INTEGER NOT NULL,
    price         REAL NOT NULL,
    currency      TEXT NOT NULL DEFAULT 'CHF',
    status        TEXT NOT NULL DEFAULT 'pending',
    created_by    INTEGER,
    paid_at       TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_corders_dealership ON credit_orders(dealership_id);

CREATE TABLE IF NOT EXISTS credit_transactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL REFERENCES dealerships(id) ON DELETE CASCADE,
    delta         INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    reason        TEXT NOT NULL,
    description   TEXT,
    listing_id    INTEGER,
    order_id      INTEGER,
    user_id       INTEGER,
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ctrans_dealership ON credit_transactions(dealership_id);

CREATE TABLE IF NOT EXISTS channel_listings (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    dealership_id INTEGER NOT NULL,
    listing_id    INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    provider      TEXT NOT NULL,
    external_id   TEXT,
    status        TEXT NOT NULL DEFAULT 'inactive',
    last_error    TEXT,
    synced_at     TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_channel_listing ON channel_listings(listing_id, provider);

CREATE TABLE IF NOT EXISTS login_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    action      TEXT NOT NULL,
    attempt_key TEXT NOT NULL,
    created_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_lattempts ON login_attempts(action, attempt_key, created_at);

CREATE TABLE IF NOT EXISTS password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_presets_token ON password_resets(token_hash);

CREATE TABLE IF NOT EXISTS email_verifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_everifs_token ON email_verifications(token_hash);

CREATE TABLE IF NOT EXISTS settings (
    setting_key   TEXT PRIMARY KEY,
    setting_value TEXT,
    updated_at    TEXT NOT NULL
);

-- ---------------------------------------------------------------- sent_emails
CREATE TABLE IF NOT EXISTS sent_emails (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient  TEXT NOT NULL,
    subject    TEXT NOT NULL,
    body       TEXT DEFAULT NULL,
    driver     TEXT NOT NULL,
    was_sent   INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sent_emails_recipient ON sent_emails (recipient);
