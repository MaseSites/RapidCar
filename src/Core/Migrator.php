<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Leichtgewichtige Migrationen für bestehende Installationen.
 *
 * Neue Installationen erhalten das vollständige Schema aus /database.
 * Bestehende Installationen werden hier nachgezogen, ohne dass der
 * Betreiber SQL ausführen muss. Jede Migration ist idempotent.
 */
final class Migrator
{
    /** Aktuelle Schema-Version. Bei neuen Migrationen erhöhen. */
    private const CURRENT_VERSION = 17;

    private const VERSION_KEY = 'schema_version';

    /** Version 8: Referenz des Zahlungsanbieters an der Bestellung (Stripe-Session). */
    private static function migrateToVersion8(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        self::addColumn('credit_orders', 'provider_ref', ($isSqlite ? 'TEXT' : 'VARCHAR(120)') . ' DEFAULT NULL');
    }

    /**
     * Version 9: Die Bestätigungspflicht kam nachträglich. Konten, die vorher
     * angelegt wurden, haben nie eine Mail erhalten und gelten als bestätigt,
     * sonst wären alle Bestandskunden ausgesperrt.
     */
    private static function migrateToVersion9(): void
    {
        Database::run('UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL');
    }

    /** Version 10: Favoriten fuer Hintergruende (je Autohaus). */
    private static function migrateToVersion10(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        $intType = $isSqlite ? 'INTEGER' : 'INT';
        self::createTable('background_favorites', "
            id            {$intType} " . ($isSqlite ? 'PRIMARY KEY AUTOINCREMENT' : 'UNSIGNED NOT NULL AUTO_INCREMENT') . ",
            dealership_id {$intType} NOT NULL,
            bg_key        " . ($isSqlite ? 'TEXT' : 'VARCHAR(80)') . " NOT NULL,
            created_at    " . ($isSqlite ? 'TEXT' : 'DATETIME') . " NOT NULL
            " . ($isSqlite ? '' : ', PRIMARY KEY (id), UNIQUE KEY uq_bgfav (dealership_id, bg_key)'));
        if ($isSqlite) {
            Database::run('CREATE UNIQUE INDEX IF NOT EXISTS uq_bgfav ON background_favorites (dealership_id, bg_key)');
        }
    }

    /**
     * Version 11: Kennzahlen je Social-Post (Aufrufe, Gefaellt mir, Kommentare,
     * Gespeichert). Bleiben leer, bis eine echte Plattform-Anbindung sie liefert.
     */
    private static function migrateToVersion11(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        $intType = $isSqlite ? 'INTEGER' : 'INT';
        foreach (['views', 'likes', 'comments', 'saves'] as $column) {
            self::addColumn('social_posts', 'stat_' . $column, "{$intType} DEFAULT NULL");
        }
    }

    /**
     * Version 12: Schreibstil der Inseratstexte je Autohaus, samt eigenem
     * Beispieltext als Vorbild.
     */
    private static function migrateToVersion12(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        self::addColumn('dealerships', 'listing_tone', ($isSqlite ? 'TEXT' : 'VARCHAR(30)') . " DEFAULT NULL");
        self::addColumn('dealerships', 'listing_sample', 'TEXT DEFAULT NULL');
    }

    /**
     * Version 13: Titelstil der Inserate je Autohaus, samt eigenem
     * Beispieltitel als Vorbild.
     */
    private static function migrateToVersion13(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        self::addColumn('dealerships', 'listing_title_style', ($isSqlite ? 'TEXT' : 'VARCHAR(30)') . " DEFAULT NULL");
        self::addColumn('dealerships', 'listing_title_sample', ($isSqlite ? 'TEXT' : 'VARCHAR(160)') . ' DEFAULT NULL');
    }

    /**
     * Version 14: Titel und Beschreibung zusaetzlich als Vorlage mit
     * Platzhaltern, damit geaenderte Fahrzeugdaten im Text mitlaufen.
     */
    private static function migrateToVersion14(): void
    {
        self::addColumn('listings', 'title_template', 'TEXT DEFAULT NULL');
        self::addColumn('listings', 'description_template', 'TEXT DEFAULT NULL');
    }

    /** Führt ausstehende Migrationen aus. Wird beim Bootstrap aufgerufen. */
    public static function run(): void
    {
        try {
            self::healEmptyDatabase();
            $installed = self::installedVersion();
            if ($installed >= self::CURRENT_VERSION) {
                return;
            }

            if ($installed < 2) {
                self::migrateToVersion2();
            }
            if ($installed < 3) {
                self::migrateToVersion3();
            }
            if ($installed < 4) {
                self::migrateToVersion4();
            }
            if ($installed < 5) {
                self::migrateToVersion5();
            }
            if ($installed < 6) {
                self::migrateToVersion6();
            }
            if ($installed < 7) {
                self::migrateToVersion7();
            }
            if ($installed < 8) {
                self::migrateToVersion8();
            }
            if ($installed < 9) {
                self::migrateToVersion9();
            }
            if ($installed < 10) {
                self::migrateToVersion10();
            }
            if ($installed < 11) {
                self::migrateToVersion11();
            }
            if ($installed < 12) {
                self::migrateToVersion12();
            }
            if ($installed < 13) {
                self::migrateToVersion13();
            }
            if ($installed < 14) {
                self::migrateToVersion14();
            }
            if ($installed < 15) {
                self::migrateToVersion15();
            }
            if ($installed < 16) {
                self::migrateToVersion16();
            }
            if ($installed < 17) {
                self::migrateToVersion17();
            }

            self::setVersion(self::CURRENT_VERSION);
        } catch (\Throwable $e) {
            Logger::error('Migration fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Version 2: Guthaben-System (Inserat-Credits), Sprachwahl pro Benutzer,
     * Kanal-Verknüpfung für Inserate.
     */
    private static function migrateToVersion2(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        $intType = $isSqlite ? 'INTEGER' : 'INT';
        $textType = $isSqlite ? 'TEXT' : 'VARCHAR(255)';
        $dateType = $isSqlite ? 'TEXT' : 'DATETIME';
        $decimalType = $isSqlite ? 'REAL' : 'DECIMAL(10,2)';

        self::addColumn('dealerships', 'credits', "{$intType} NOT NULL DEFAULT 0");
        self::addColumn('users', 'language', ($isSqlite ? 'TEXT' : 'VARCHAR(2)') . " DEFAULT NULL");
        self::addColumn('listings', 'credit_charged', "{$intType} NOT NULL DEFAULT 0");

        self::createTable('credit_orders', "
            id            {$intType} " . ($isSqlite ? 'PRIMARY KEY AUTOINCREMENT' : 'UNSIGNED NOT NULL AUTO_INCREMENT') . ",
            dealership_id {$intType} NOT NULL,
            package_key   " . ($isSqlite ? 'TEXT' : 'VARCHAR(30)') . " NOT NULL,
            credits       {$intType} NOT NULL,
            price         {$decimalType} NOT NULL,
            currency      " . ($isSqlite ? 'TEXT' : 'VARCHAR(3)') . " NOT NULL DEFAULT 'CHF',
            status        " . ($isSqlite ? 'TEXT' : 'VARCHAR(20)') . " NOT NULL DEFAULT 'pending',
            created_by    {$intType} DEFAULT NULL,
            paid_at       {$dateType} DEFAULT NULL,
            created_at    {$dateType} NOT NULL,
            updated_at    {$dateType} NOT NULL
            " . ($isSqlite ? '' : ', PRIMARY KEY (id), KEY idx_corders_dealership (dealership_id)'));

        self::createTable('credit_transactions', "
            id            {$intType} " . ($isSqlite ? 'PRIMARY KEY AUTOINCREMENT' : 'UNSIGNED NOT NULL AUTO_INCREMENT') . ",
            dealership_id {$intType} NOT NULL,
            delta         {$intType} NOT NULL,
            balance_after {$intType} NOT NULL,
            reason        " . ($isSqlite ? 'TEXT' : 'VARCHAR(40)') . " NOT NULL,
            description   {$textType} DEFAULT NULL,
            listing_id    {$intType} DEFAULT NULL,
            order_id      {$intType} DEFAULT NULL,
            user_id       {$intType} DEFAULT NULL,
            created_at    {$dateType} NOT NULL
            " . ($isSqlite ? '' : ', PRIMARY KEY (id), KEY idx_ctrans_dealership (dealership_id)'));

        // Bestehende Autohäuser erhalten das Gratis-Startguthaben
        Database::run('UPDATE dealerships SET credits = 1 WHERE credits = 0');
    }

    /**
     * Version 3: Verknüpfung lokaler Inserate mit externen Kanal-Inseraten
     * (z.B. AutoScout24-Inserats-IDs).
     */
    private static function migrateToVersion3(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        $intType = $isSqlite ? 'INTEGER' : 'INT';
        $textType = $isSqlite ? 'TEXT' : 'VARCHAR(190)';
        $dateType = $isSqlite ? 'TEXT' : 'DATETIME';

        self::createTable('channel_listings', "
            id            {$intType} " . ($isSqlite ? 'PRIMARY KEY AUTOINCREMENT' : 'UNSIGNED NOT NULL AUTO_INCREMENT') . ",
            dealership_id {$intType} NOT NULL,
            listing_id    {$intType} NOT NULL,
            provider      " . ($isSqlite ? 'TEXT' : 'VARCHAR(50)') . " NOT NULL,
            external_id   {$textType} DEFAULT NULL,
            status        " . ($isSqlite ? 'TEXT' : 'VARCHAR(20)') . " NOT NULL DEFAULT 'inactive',
            last_error    TEXT DEFAULT NULL,
            synced_at     {$dateType} DEFAULT NULL,
            created_at    {$dateType} NOT NULL,
            updated_at    {$dateType} NOT NULL
            " . ($isSqlite ? '' : ', PRIMARY KEY (id), UNIQUE KEY uq_channel_listing (listing_id, provider)'));

        if ($isSqlite) {
            Database::run('CREATE UNIQUE INDEX IF NOT EXISTS uq_channel_listing ON channel_listings (listing_id, provider)');
        }
    }

    /**
     * Version 4: Momentaufnahme der Inserate, die auf den verbundenen Kanälen
     * tatsächlich vorhanden sind. Dient der Abgleichsansicht in der
     * Fahrzeugliste (auch für Inserate ohne lokales Gegenstück).
     */
    private static function migrateToVersion4(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        $intType = $isSqlite ? 'INTEGER' : 'INT';
        $textType = $isSqlite ? 'TEXT' : 'VARCHAR(190)';
        $dateType = $isSqlite ? 'TEXT' : 'DATETIME';
        $decimalType = $isSqlite ? 'REAL' : 'DECIMAL(12,2)';

        self::createTable('channel_remote_listings', "
            id             {$intType} " . ($isSqlite ? 'PRIMARY KEY AUTOINCREMENT' : 'UNSIGNED NOT NULL AUTO_INCREMENT') . ",
            dealership_id  {$intType} NOT NULL,
            provider       " . ($isSqlite ? 'TEXT' : 'VARCHAR(50)') . " NOT NULL,
            external_id    {$textType} NOT NULL,
            reference_id   {$textType} DEFAULT NULL,
            title          {$textType} DEFAULT NULL,
            price          {$decimalType} DEFAULT NULL,
            currency       " . ($isSqlite ? 'TEXT' : 'VARCHAR(3)') . " DEFAULT NULL,
            status         " . ($isSqlite ? 'TEXT' : 'VARCHAR(20)') . " DEFAULT NULL,
            url            " . ($isSqlite ? 'TEXT' : 'VARCHAR(500)') . " DEFAULT NULL,
            vehicle_id     {$intType} DEFAULT NULL,
            fetched_at     {$dateType} NOT NULL
            " . ($isSqlite ? '' : ', PRIMARY KEY (id), UNIQUE KEY uq_remote_listing (dealership_id, provider, external_id)'));

        if ($isSqlite) {
            Database::run(
                'CREATE UNIQUE INDEX IF NOT EXISTS uq_remote_listing
                 ON channel_remote_listings (dealership_id, provider, external_id)'
            );
        }

        // Zeitpunkt der letzten Kanal-Synchronisierung je Autohaus
        self::addColumn('dealerships', 'channels_synced_at', ($isSqlite ? 'TEXT' : 'DATETIME') . ' DEFAULT NULL');
    }

    /**
     * Version 5: Alternativen je Feld aus der Bilderkennung.
     * Ist ein Wert nicht eindeutig, bietet die Oberfläche daraus eine
     * Auswahlliste an, statt einen Wert zu erzwingen.
     */
    private static function migrateToVersion5(): void
    {
        self::addColumn('vehicle_field_status', 'alternatives', 'TEXT DEFAULT NULL');
    }

    /**
     * Version 6: Benutzername als zweite Anmeldemöglichkeit neben der E-Mail.
     * Bleibt leer, solange keiner vergeben wurde: Bestehende Konten melden
     * sich unverändert mit ihrer E-Mail an.
     */
    private static function migrateToVersion6(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        self::addColumn('users', 'username', ($isSqlite ? 'TEXT' : 'VARCHAR(60)') . ' DEFAULT NULL');

        // Eindeutigkeit über einen Index, damit kein Benutzername doppelt vergeben wird.
        // Ist er schon vorhanden, meldet die Datenbank einen Fehler: der wird
        // bewusst verworfen, damit die Migration wiederholbar bleibt.
        $sql = $isSqlite
            ? 'CREATE UNIQUE INDEX IF NOT EXISTS uq_users_username ON users (username)'
            : 'ALTER TABLE users ADD UNIQUE KEY uq_users_username (username)';
        try {
            Database::run($sql);
        } catch (\Throwable $e) {
            Logger::info('Index uq_users_username bereits vorhanden oder nicht anlegbar: ' . $e->getMessage());
        }
    }

    /**
     * Version 7: Anzahl Vorhalter (aus dem Kaufvertrag auslesbar) sowie
     * freigestellte Fotos mit austauschbarem Hintergrund.
     */
    private static function migrateToVersion7(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        $intType = $isSqlite ? 'INTEGER' : 'INT';
        $textType = $isSqlite ? 'TEXT' : 'VARCHAR(255)';
        $dateType = $isSqlite ? 'TEXT' : 'DATETIME';

        self::addColumn('vehicles', 'previous_owners', "{$intType} DEFAULT NULL");

        // Freistellung: einmal je Foto von der KI berechnet, danach ohne KI wiederverwendbar
        self::addColumn('vehicle_images', 'cutout_path', "{$textType} DEFAULT NULL");
        self::addColumn('vehicle_images', 'background_key', ($isSqlite ? 'TEXT' : 'VARCHAR(80)') . ' DEFAULT NULL');
        self::addColumn('vehicle_images', 'composed_path', "{$textType} DEFAULT NULL");

        // Eigene Hintergründe je Autohaus
        self::createTable('backgrounds', "
            id            {$intType} " . ($isSqlite ? 'PRIMARY KEY AUTOINCREMENT' : 'UNSIGNED NOT NULL AUTO_INCREMENT') . ",
            dealership_id {$intType} NOT NULL,
            name          " . ($isSqlite ? 'TEXT' : 'VARCHAR(120)') . " NOT NULL,
            file_path     {$textType} NOT NULL,
            created_at    {$dateType} NOT NULL
            " . ($isSqlite ? '' : ', PRIMARY KEY (id), KEY idx_backgrounds_dealership (dealership_id)'));
    }

    // -----------------------------------------------------------------------

    /**
     * Version 15: Die Plattform steht auch Privatpersonen offen. Jeder
     * Mandant traegt seine Art: dealer (Autohaus) oder private.
     */
    private static function migrateToVersion15(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        self::addColumn('dealerships', 'account_type', ($isSqlite ? 'TEXT' : 'VARCHAR(10)') . " NOT NULL DEFAULT 'dealer'");
    }

    /**
     * Version 16: Protokoll aller automatisch versendeten E-Mails, damit der
     * Betreiber je Kunde nachsehen kann, was wann hinausging und ob der
     * Versand geklappt hat.
     */
    private static function migrateToVersion16(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        $intType = $isSqlite ? 'INTEGER' : 'INT';
        self::createTable('sent_emails', "
            id         {$intType} " . ($isSqlite ? 'PRIMARY KEY AUTOINCREMENT' : 'UNSIGNED NOT NULL AUTO_INCREMENT') . ",
            recipient  " . ($isSqlite ? 'TEXT' : 'VARCHAR(190)') . " NOT NULL,
            subject    " . ($isSqlite ? 'TEXT' : 'VARCHAR(255)') . " NOT NULL,
            body       TEXT DEFAULT NULL,
            driver     " . ($isSqlite ? 'TEXT' : 'VARCHAR(10)') . " NOT NULL,
            was_sent   " . ($isSqlite ? 'INTEGER' : 'TINYINT(1)') . " NOT NULL DEFAULT 0,
            created_at " . ($isSqlite ? 'TEXT' : 'DATETIME') . " NOT NULL
            " . ($isSqlite ? '' : ', PRIMARY KEY (id), KEY idx_sent_emails_recipient (recipient)'));
        if ($isSqlite) {
            Database::run('CREATE INDEX IF NOT EXISTS idx_sent_emails_recipient ON sent_emails (recipient)');
        }
    }

    /**
     * Version 17: laufender Spyne-Auftrag je Foto. Die Verarbeitung dauert
     * mehrere Minuten; ohne Notiz waere das Ergebnis verloren, sobald der
     * Nutzer die Seite verlaesst.
     */
    private static function migrateToVersion17(): void
    {
        $isSqlite = Database::driver() === 'sqlite';
        self::addColumn('vehicle_images', 'spyne_job', ($isSqlite ? 'TEXT' : 'VARCHAR(120)') . ' DEFAULT NULL');
        self::addColumn('vehicle_images', 'spyne_scene', ($isSqlite ? 'TEXT' : 'VARCHAR(80)') . ' DEFAULT NULL');
    }

    /**
     * Ist die Anwendung installiert, die Datenbank aber leer, wird das
     * Schema neu angelegt. Das passiert, wenn beim Umzug auf einen Server
     * die Konfiguration mitkommt, die Datenbank aber nicht: SQLite legt
     * dann eine leere Datei an, und bei MySQL zeigt der Zugang auf eine
     * leere Datenbank. Ohne Heilung endet jede echte Abfrage im Fehler 500.
     */
    private static function healEmptyDatabase(): void
    {
        if (self::tableExists('users') && self::tableExists('settings')) {
            return;
        }

        $isSqlite = Database::driver() === 'sqlite';
        $file = BASE_PATH . '/database/schema.' . ($isSqlite ? 'sqlite' : 'mysql') . '.sql';
        if (!is_file($file)) {
            Logger::error('Datenbank ist leer und die Schema-Datei fehlt: ' . basename($file));
            return;
        }

        $applied = 0;
        foreach (self::splitSql((string) file_get_contents($file)) as $statement) {
            try {
                Database::run($statement);
                $applied++;
            } catch (\Throwable $e) {
                Logger::error('Schema-Heilung, Anweisung fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // Als Fehler protokolliert, damit es im Systemcheck sichtbar ist.
        Logger::error(
            'Die Datenbank war leer. Das Schema wurde neu angelegt (' . $applied . ' Anweisungen).'
        );

        self::seedBaseline();
    }

    /**
     * Grunddaten fuer eine frisch aufgebaute Datenbank: Vorlagen und
     * Demo-Daten aus /database/seeds.php sowie das Betreiberkonto aus der
     * Konfiguration (operator-Block). So entsteht aus dem blossen Hochladen
     * der Dateien eine vollstaendig eingerichtete Anwendung.
     */
    private static function seedBaseline(): void
    {
        try {
            if ((int) Database::scalar('SELECT COUNT(*) FROM users') > 0) {
                return;
            }

            $seedsFile = BASE_PATH . '/database/seeds.php';
            if (is_file($seedsFile)) {
                require_once $seedsFile;
                if (function_exists('rapidcar_run_seeds')) {
                    rapidcar_run_seeds();
                }
            }

            // Betreiberkonto aus der Konfiguration. Nur der Hash steht dort,
            // nie das Passwort selbst.
            $operator = Config::get('operator', []);
            $email = is_array($operator) ? trim((string) ($operator['email'] ?? '')) : '';
            $hash = is_array($operator) ? (string) ($operator['password_hash'] ?? '') : '';
            if ($email === '' || $hash === '') {
                Logger::error('Grunddaten angelegt, aber kein operator-Block in der Konfiguration: Betreiberkonto fehlt.');
                return;
            }
            $exists = Database::scalar('SELECT COUNT(*) FROM users WHERE email = :e', ['e' => $email]);
            if ((int) $exists > 0) {
                return;
            }

            $now = Database::now();
            $dealershipId = Database::insert('dealerships', [
                'name'         => (string) ($operator['tenant_name'] ?? 'RapidCar'),
                'account_type' => 'dealer',
                'country'      => 'CH',
                'currency'     => 'CHF',
                'language'     => 'de',
                'credits'      => (int) ($operator['credits'] ?? 100),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            Database::insert('users', [
                'dealership_id'           => $dealershipId,
                'first_name'              => (string) ($operator['first_name'] ?? 'Betreiber'),
                'last_name'               => (string) ($operator['last_name'] ?? 'RapidCar'),
                'email'                   => mb_strtolower($email),
                'password_hash'           => $hash,
                'role'                    => 'super_admin',
                'is_active'               => 1,
                'email_verified_at'       => $now,
                'onboarding_completed_at' => $now,
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);
            Logger::info('Betreiberkonto aus der Konfiguration angelegt: ' . $email);
        } catch (\Throwable $e) {
            Logger::error('Grunddaten konnten nicht angelegt werden: ' . $e->getMessage());
        }
    }

    private static function tableExists(string $table): bool
    {
        try {
            if (Database::driver() === 'sqlite') {
                $found = Database::scalar(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t",
                    ['t' => $table]
                );
                return $found !== false && $found !== null;
            }
            // information_schema statt SHOW: SHOW vertraegt sich nicht auf
            // jedem Server mit echten Prepared Statements.
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t',
                ['t' => $table]
            ) > 0;
        } catch (\Throwable) {
            // Verbindung kaputt: nicht heilen, der Fehler gehoert dem Aufrufer.
            return true;
        }
    }

    /**
     * Zerlegt eine Schema-Datei in einzelne Anweisungen.
     * Gleiches Vorgehen wie im Installer: Kommentarzeilen weg,
     * dann am Semikolon am Zeilenende trennen.
     *
     * @return array<int, string>
     */
    private static function splitSql(string $sql): array
    {
        $clean = [];
        foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }
        $statements = preg_split('/;\s*(?:\r?\n|$)/', implode("\n", $clean)) ?: [];
        return array_values(array_filter(array_map('trim', $statements)));
    }

    private static function installedVersion(): int
    {
        try {
            $value = Database::scalar(
                'SELECT setting_value FROM settings WHERE setting_key = :k',
                ['k' => self::VERSION_KEY]
            );
            return $value === false || $value === null ? 1 : (int) $value;
        } catch (\Throwable) {
            return self::CURRENT_VERSION; // Tabelle fehlt (z.B. während Installation)
        }
    }

    private static function setVersion(int $version): void
    {
        $existing = Database::scalar(
            'SELECT COUNT(*) FROM settings WHERE setting_key = :k',
            ['k' => self::VERSION_KEY]
        );
        if ((int) $existing > 0) {
            Database::run(
                'UPDATE settings SET setting_value = :v, updated_at = :t WHERE setting_key = :k',
                ['v' => (string) $version, 't' => Database::now(), 'k' => self::VERSION_KEY]
            );
        } else {
            Database::run(
                'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (:k, :v, :t)',
                ['k' => self::VERSION_KEY, 'v' => (string) $version, 't' => Database::now()]
            );
        }
    }

    private static function addColumn(string $table, string $column, string $definition): void
    {
        if (self::columnExists($table, $column)) {
            return;
        }
        Database::run("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function createTable(string $table, string $columns): void
    {
        Database::run("CREATE TABLE IF NOT EXISTS {$table} ({$columns})");
    }

    private static function columnExists(string $table, string $column): bool
    {
        try {
            if (Database::driver() === 'sqlite') {
                foreach (Database::fetchAll("PRAGMA table_info({$table})") as $row) {
                    if (($row['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
                ['t' => $table, 'c' => $column]
            ) > 0;
        } catch (\Throwable) {
            return true; // im Zweifel nicht anfassen
        }
    }
}
