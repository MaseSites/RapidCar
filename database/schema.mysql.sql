-- ===========================================================================
-- VehicleAI — MySQL/MariaDB-Schema (Produktion, §56)
-- Zeichensatz utf8mb4, InnoDB, Fremdschlüssel mit ON DELETE CASCADE
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- dealerships
CREATE TABLE IF NOT EXISTS dealerships (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(190) NOT NULL,
    logo_path       VARCHAR(255) DEFAULT NULL,
    address         VARCHAR(255) DEFAULT NULL,
    zip             VARCHAR(20)  DEFAULT NULL,
    city            VARCHAR(120) DEFAULT NULL,
    country         VARCHAR(2)   DEFAULT 'CH',
    phone           VARCHAR(50)  DEFAULT NULL,
    email           VARCHAR(190) DEFAULT NULL,
    website         VARCHAR(255) DEFAULT NULL,
    instagram       VARCHAR(190) DEFAULT NULL,
    opening_hours   TEXT         DEFAULT NULL,
    currency        VARCHAR(3)   NOT NULL DEFAULT 'CHF',
    language        VARCHAR(2)   NOT NULL DEFAULT 'de',
    credits         INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS users (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id            INT UNSIGNED DEFAULT NULL,
    first_name               VARCHAR(100) NOT NULL,
    last_name                VARCHAR(100) NOT NULL,
    email                    VARCHAR(190) NOT NULL,
    username                 VARCHAR(60) DEFAULT NULL,
    password_hash            VARCHAR(255) NOT NULL,
    phone                    VARCHAR(50)  DEFAULT NULL,
    country                  VARCHAR(2)   DEFAULT 'CH',
    role                     VARCHAR(20)  NOT NULL DEFAULT 'dealer_user',
    language                 VARCHAR(2)   DEFAULT NULL,
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
    is_demo                  TINYINT(1)   NOT NULL DEFAULT 0,
    email_verified_at        DATETIME     DEFAULT NULL,
    onboarding_completed_at  DATETIME     DEFAULT NULL,
    last_login_at            DATETIME     DEFAULT NULL,
    created_at               DATETIME     NOT NULL,
    updated_at               DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_dealership (dealership_id),
    CONSTRAINT fk_users_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- vehicles
CREATE TABLE IF NOT EXISTS vehicles (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id      INT UNSIGNED NOT NULL,
    created_by         INT UNSIGNED DEFAULT NULL,
    make               VARCHAR(100) DEFAULT NULL,
    model              VARCHAR(100) DEFAULT NULL,
    variant            VARCHAR(150) DEFAULT NULL,
    year               SMALLINT UNSIGNED DEFAULT NULL,
    first_registration VARCHAR(7)   DEFAULT NULL,        -- MM.JJJJ
    mileage            INT UNSIGNED DEFAULT NULL,
    price              DECIMAL(12,2) DEFAULT NULL,
    power_hp           SMALLINT UNSIGNED DEFAULT NULL,
    power_kw           SMALLINT UNSIGNED DEFAULT NULL,
    displacement_ccm   MEDIUMINT UNSIGNED DEFAULT NULL,
    transmission       VARCHAR(30)  DEFAULT NULL,        -- manual | automatic | semi_automatic
    drivetrain         VARCHAR(30)  DEFAULT NULL,        -- fwd | rwd | awd
    fuel_type          VARCHAR(30)  DEFAULT NULL,        -- petrol | diesel | electric | hybrid | …
    color              VARCHAR(80)  DEFAULT NULL,
    interior_color     VARCHAR(80)  DEFAULT NULL,
    doors              TINYINT UNSIGNED DEFAULT NULL,
    seats              TINYINT UNSIGNED DEFAULT NULL,
    vin                VARCHAR(30)  DEFAULT NULL,
    description        TEXT         DEFAULT NULL,
    status             VARCHAR(20)  NOT NULL DEFAULT 'draft',  -- §24
    created_at         DATETIME     NOT NULL,
    updated_at         DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_vehicles_dealership (dealership_id),
    KEY idx_vehicles_status (status),
    KEY idx_vehicles_make_model (make, model),
    CONSTRAINT fk_vehicles_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- vehicle_images
CREATE TABLE IF NOT EXISTS vehicle_images (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id            INT UNSIGNED NOT NULL,
    file_path             VARCHAR(255) NOT NULL,        -- relativ zu /uploads
    thumb_path            VARCHAR(255) DEFAULT NULL,
    card_path             VARCHAR(255) DEFAULT NULL,
    original_name         VARCHAR(255) DEFAULT NULL,
    width                 SMALLINT UNSIGNED DEFAULT NULL,
    height                SMALLINT UNSIGNED DEFAULT NULL,
    file_size             INT UNSIGNED DEFAULT NULL,
    sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_main               TINYINT(1) NOT NULL DEFAULT 0,
    ai_quality_score      TINYINT UNSIGNED DEFAULT NULL, -- §73: von AIImageService befüllt
    ai_analysis           TEXT DEFAULT NULL,             -- JSON-Ergebnis der Bildanalyse
    created_at            DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_vimages_vehicle (vehicle_id),
    CONSTRAINT fk_vimages_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- vehicle_features
CREATE TABLE IF NOT EXISTS vehicle_features (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id  INT UNSIGNED NOT NULL,
    feature     VARCHAR(150) NOT NULL,
    source      VARCHAR(10)  NOT NULL DEFAULT 'manual',  -- manual | ai (§30)
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_vfeatures_vehicle (vehicle_id),
    CONSTRAINT fk_vfeatures_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- vehicle_field_status
-- KI-Status pro Feld (§30): detected | uncertain | manual
CREATE TABLE IF NOT EXISTS vehicle_field_status (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id  INT UNSIGNED NOT NULL,
    field_name  VARCHAR(50)  NOT NULL,
    status      VARCHAR(15)  NOT NULL DEFAULT 'manual',
    confidence  TINYINT UNSIGNED DEFAULT NULL,           -- 0–100
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vfs (vehicle_id, field_name),
    CONSTRAINT fk_vfs_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- listings
CREATE TABLE IF NOT EXISTS listings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id    INT UNSIGNED NOT NULL,
    dealership_id INT UNSIGNED NOT NULL,
    title         VARCHAR(255) DEFAULT NULL,
    description   TEXT         DEFAULT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'draft',  -- draft | ready | published
    credit_charged TINYINT(1)  NOT NULL DEFAULT 0,        -- Guthaben einmalig belastet
    published_at  DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_listings_vehicle (vehicle_id),
    KEY idx_listings_dealership (dealership_id),
    CONSTRAINT fk_listings_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id) ON DELETE CASCADE,
    CONSTRAINT fk_listings_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- listing_scores
CREATE TABLE IF NOT EXISTS listing_scores (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id     INT UNSIGNED NOT NULL,
    total_score    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    photos_score   TINYINT UNSIGNED DEFAULT NULL,
    title_score    TINYINT UNSIGNED DEFAULT NULL,
    description_score TINYINT UNSIGNED DEFAULT NULL,
    price_score    TINYINT UNSIGNED DEFAULT NULL,        -- NULL = unzureichende Vergleichsdaten
    data_score     TINYINT UNSIGNED DEFAULT NULL,
    engine         VARCHAR(10) NOT NULL DEFAULT 'rules', -- rules | ai (§54/§72)
    details        TEXT DEFAULT NULL,                    -- JSON mit Begründungen
    created_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lscores_listing (listing_id),
    CONSTRAINT fk_lscores_listing FOREIGN KEY (listing_id)
        REFERENCES listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------- listing_recommendations
CREATE TABLE IF NOT EXISTS listing_recommendations (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id   INT UNSIGNED NOT NULL,
    category     VARCHAR(30)  NOT NULL,                  -- price | photos | description | title | data
    severity     VARCHAR(10)  NOT NULL DEFAULT 'info',   -- critical | warning | info
    message      TEXT         NOT NULL,
    action_label VARCHAR(100) DEFAULT NULL,
    is_resolved  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lrecs_listing (listing_id),
    CONSTRAINT fk_lrecs_listing FOREIGN KEY (listing_id)
        REFERENCES listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------- leads
CREATE TABLE IF NOT EXISTS leads (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id  INT UNSIGNED NOT NULL,
    vehicle_id     INT UNSIGNED DEFAULT NULL,
    customer_name  VARCHAR(150) NOT NULL,
    customer_email VARCHAR(190) DEFAULT NULL,
    customer_phone VARCHAR(50)  DEFAULT NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'new',  -- new | active | test_drive | won | lost
    score          TINYINT UNSIGNED DEFAULT NULL,
    source         VARCHAR(50)  DEFAULT NULL,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_leads_dealership (dealership_id),
    KEY idx_leads_vehicle (vehicle_id),
    CONSTRAINT fk_leads_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE,
    CONSTRAINT fk_leads_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- messages
CREATE TABLE IF NOT EXISTS messages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id     INT UNSIGNED NOT NULL,
    direction   VARCHAR(10)  NOT NULL DEFAULT 'inbound', -- inbound | outbound
    sender_name VARCHAR(150) DEFAULT NULL,
    body        TEXT NOT NULL,
    is_ai_draft TINYINT(1) NOT NULL DEFAULT 0,           -- §42/§43: KI-Entwurf, muss bestätigt werden
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_messages_lead (lead_id),
    CONSTRAINT fk_messages_lead FOREIGN KEY (lead_id)
        REFERENCES leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- social_posts
CREATE TABLE IF NOT EXISTS social_posts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    vehicle_id    INT UNSIGNED DEFAULT NULL,
    template_key  VARCHAR(50)  DEFAULT NULL,
    platform      VARCHAR(30)  NOT NULL DEFAULT 'instagram',
    caption       TEXT         DEFAULT NULL,
    image_path    VARCHAR(255) DEFAULT NULL,             -- generiertes Bild in /uploads
    image_ids     TEXT         DEFAULT NULL,             -- JSON: verwendete vehicle_images-IDs
    status        VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft | saved | published
    published_at  DATETIME     DEFAULT NULL,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sposts_dealership (dealership_id),
    CONSTRAINT fk_sposts_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE,
    CONSTRAINT fk_sposts_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- social_templates
CREATE TABLE IF NOT EXISTS social_templates (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED DEFAULT NULL,             -- NULL = Systemvorlage (§38)
    template_key  VARCHAR(50)  NOT NULL,
    name          VARCHAR(100) NOT NULL,
    config        TEXT         DEFAULT NULL,             -- JSON: Farben, Layout, Schrift
    is_system     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_stemplates_dealership (dealership_id),
    CONSTRAINT fk_stemplates_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------- tasks
CREATE TABLE IF NOT EXISTS tasks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED DEFAULT NULL,
    lead_id       INT UNSIGNED DEFAULT NULL,
    vehicle_id    INT UNSIGNED DEFAULT NULL,
    title         VARCHAR(255) NOT NULL,
    description   TEXT DEFAULT NULL,
    due_at        DATETIME DEFAULT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'open',   -- open | done
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tasks_dealership (dealership_id),
    CONSTRAINT fk_tasks_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_lead FOREIGN KEY (lead_id)
        REFERENCES leads (id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ documents
CREATE TABLE IF NOT EXISTS documents (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    vehicle_id    INT UNSIGNED DEFAULT NULL,
    file_path     VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    mime_type     VARCHAR(100) DEFAULT NULL,
    file_size     INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_documents_dealership (dealership_id),
    CONSTRAINT fk_documents_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- integrations
CREATE TABLE IF NOT EXISTS integrations (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    provider      VARCHAR(50)  NOT NULL,                 -- autoscout24 | instagram | …
    status        VARCHAR(20)  NOT NULL DEFAULT 'disconnected', -- disconnected | connected | error
    account_name  VARCHAR(190) DEFAULT NULL,
    connected_at  DATETIME     DEFAULT NULL,
    last_sync_at  DATETIME     DEFAULT NULL,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_integrations (dealership_id, provider),
    CONSTRAINT fk_integrations_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- integration_tokens
-- §58: Tokens verschlüsselt (AES-256-GCM), niemals im Klartext
CREATE TABLE IF NOT EXISTS integration_tokens (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    provider      VARCHAR(50)  NOT NULL,
    access_token  TEXT         DEFAULT NULL,             -- verschlüsselt
    refresh_token TEXT         DEFAULT NULL,             -- verschlüsselt
    expires_at    DATETIME     DEFAULT NULL,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_itokens (dealership_id, provider),
    CONSTRAINT fk_itokens_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- notifications
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(50)  NOT NULL,
    title      VARCHAR(255) NOT NULL,
    body       TEXT         DEFAULT NULL,
    link       VARCHAR(255) DEFAULT NULL,
    read_at    DATETIME     DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_notifications_user (user_id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- activity_logs
CREATE TABLE IF NOT EXISTS activity_logs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED DEFAULT NULL,
    dealership_id INT UNSIGNED DEFAULT NULL,
    action        VARCHAR(50)  NOT NULL,
    description   VARCHAR(500) NOT NULL,
    entity_type   VARCHAR(30)  DEFAULT NULL,
    entity_id     INT UNSIGNED DEFAULT NULL,
    ip_address    VARCHAR(45)  DEFAULT NULL,
    created_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_alogs_user (user_id),
    KEY idx_alogs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- subscriptions
CREATE TABLE IF NOT EXISTS subscriptions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    plan          VARCHAR(30)  NOT NULL DEFAULT 'free',
    status        VARCHAR(20)  NOT NULL DEFAULT 'active',
    started_at    DATETIME     DEFAULT NULL,
    ends_at       DATETIME     DEFAULT NULL,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_subscriptions_dealership (dealership_id),
    CONSTRAINT fk_subscriptions_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- credit_orders
CREATE TABLE IF NOT EXISTS credit_orders (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    package_key   VARCHAR(30)  NOT NULL,
    credits       INT UNSIGNED NOT NULL,
    price         DECIMAL(10,2) NOT NULL,
    currency      VARCHAR(3)   NOT NULL DEFAULT 'CHF',
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',  -- pending | paid | cancelled
    created_by    INT UNSIGNED DEFAULT NULL,
    paid_at       DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_corders_dealership (dealership_id),
    CONSTRAINT fk_corders_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- credit_transactions
CREATE TABLE IF NOT EXISTS credit_transactions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    delta         INT          NOT NULL,
    balance_after INT UNSIGNED NOT NULL,
    reason        VARCHAR(40)  NOT NULL,
    description   VARCHAR(255) DEFAULT NULL,
    listing_id    INT UNSIGNED DEFAULT NULL,
    order_id      INT UNSIGNED DEFAULT NULL,
    user_id       INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ctrans_dealership (dealership_id),
    CONSTRAINT fk_ctrans_dealership FOREIGN KEY (dealership_id)
        REFERENCES dealerships (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- channel_listings
-- Verknüpfung lokaler Inserate mit externen Kanal-Inseraten (z.B. AutoScout24)
CREATE TABLE IF NOT EXISTS channel_listings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dealership_id INT UNSIGNED NOT NULL,
    listing_id    INT UNSIGNED NOT NULL,
    provider      VARCHAR(50)  NOT NULL,
    external_id   VARCHAR(190) DEFAULT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'inactive',
    last_error    TEXT         DEFAULT NULL,
    synced_at     DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_channel_listing (listing_id, provider),
    KEY idx_chlistings_dealership (dealership_id),
    CONSTRAINT fk_chlistings_listing FOREIGN KEY (listing_id)
        REFERENCES listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- login_attempts
CREATE TABLE IF NOT EXISTS login_attempts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    action      VARCHAR(30)  NOT NULL,                   -- login | password_reset | register
    attempt_key VARCHAR(190) NOT NULL,                   -- E-Mail oder IP
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lattempts (action, attempt_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ password_resets
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_presets_token (token_hash),
    CONSTRAINT fk_presets_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- email_verifications
CREATE TABLE IF NOT EXISTS email_verifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_everifs_token (token_hash),
    CONSTRAINT fk_everifs_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- settings
-- Globale Plattform-Einstellungen (u.a. KI-Modus, §54)
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
