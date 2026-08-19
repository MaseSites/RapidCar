# RapidCar auf einem Plesk-Server einrichten

Diese Anleitung führt von einer leeren Domain bis zur laufenden Anwendung mit
echter MySQL-Datenbank. Rechne mit 20 bis 30 Minuten.

## Voraussetzungen

| Was | Mindestens | Wo in Plesk |
|---|---|---|
| PHP | 8.1 | Websites & Domains → PHP-Einstellungen |
| PHP-Erweiterungen | pdo_mysql, gd, curl, mbstring, fileinfo, openssl | PHP-Einstellungen → Erweiterungen |
| Datenbank | MySQL 5.7 oder MariaDB 10.3 | Datenbanken |
| Zertifikat | Let's Encrypt | SSL/TLS-Zertifikate |

`zip` und `unzip` sind praktisch, aber nicht nötig; der Dateimanager von Plesk
kann Archive entpacken.

## 1. Datenbank anlegen

Plesk → **Datenbanken** → *Datenbank hinzufügen*

- Name: `rapidcar`
- Zeichensatz: **utf8mb4**
- Benutzer anlegen, Passwort von Plesk erzeugen lassen und notieren
- Zugriff: nur von localhost

## 2. Dateien hochladen

Alles aus dem Projekt **ausser** diesen Ordnern:

- `storage/` und `uploads/` (werden auf dem Server neu angelegt)
- `testdaten/` (nur für die örtliche Entwicklung)
- `.git/`
- `config/config.php` (die örtliche Fassung mit deinen Schlüsseln)

Ziel ist das Dokumentenstammverzeichnis der Domain, üblicherweise
`httpdocs`. Danach zwei Ordner anlegen:

```
httpdocs/storage
httpdocs/uploads
```

Beide brauchen Schreibrechte für den Webserver-Benutzer. In Plesk unter
Dateimanager → Rechte ändern → `755` genügt, wenn Eigentümer der
Abonnement-Benutzer ist.

## 3. Installer aufrufen

Im Browser: `https://deine-domain.tld/install/`

Der Installer prüft zuerst die Voraussetzungen und zeigt rot an, was fehlt.
Danach die Datenbankangaben eintragen:

| Feld | Wert |
|---|---|
| Treiber | MySQL |
| Host | `localhost` |
| Port | `3306` |
| Datenbank | `rapidcar` |
| Benutzer | der in Schritt 1 angelegte |
| Passwort | das notierte |

Der Installer legt alle 30 Tabellen an, schreibt `config/config.php` und
erstellt das Betreiberkonto.

**Wichtig:** Nach der Installation den Ordner `install/` löschen. Der
Installer weist am Ende selbst darauf hin.

## 4. Konfiguration ergänzen

Der Installer schreibt bereits Adresse, Schlüssel, Datenbank, Zeitzone und den
Mailversand. Von Hand kommt nur dazu, was der Installer nicht wissen kann: die
Zugänge zu den Diensten.

`config/config.php` im Dateimanager öffnen und ergänzen:

```php
'ai' => [
    'mode'         => 'live',
    'api_key'      => 'sk-...',  // OpenAI
    'model'        => 'gpt-4o-mini',
    'vision_model' => 'gpt-5.5',
],
```

Diese Werte gehören dem Betreiber. Ein Autohaus muss nie eine Serverdatei
anfassen: es meldet sich an und verbindet seine Kanäle im Dashboard.

### Was der Installer setzt

| Schlüssel | Wert | Bedeutung |
|---|---|---|
| `app.url` | die Adresse der Anfrage | Links in E-Mails und Rücksprungadressen |
| `app.debug` | `false` | keine internen Angaben auf der Fehlerseite |
| `app.timezone` | Zeitzone des Servers | ein Server steht sonst auf UTC |
| `app.force_https` | `false` | Weiterleitung erledigt Plesk |
| `db.driver` | `mysql` | SQLite ist nur für den Entwicklungsrechner |
| `mail.driver` | `mail`, sonst `log` | je nachdem, ob `mail()` erlaubt ist |
| `mail.contact` | dein Betreiberkonto | Empfänger des Kontaktformulars |
| `features.email_verification` | wie `mail.driver` | ohne Versand aus, sonst an |

### Mailversand

`mail` nutzt die Mailfunktion des Servers und genügt auf Plesk meistens.
Landen die Nachrichten im Spam, ist SMTP der zuverlässigere Weg:

```php
'mail' => [
    'driver'     => 'smtp',
    'host'       => 'mail.deine-domain.tld',
    'port'       => 587,
    'username'   => 'noreply@deine-domain.tld',
    'password'   => '...',
    'encryption' => 'tls',
    'from'       => 'noreply@deine-domain.tld',
],
```

Bleibt der Treiber auf `log`, wird nichts verschickt. Die Anwendung merkt das
und schaltet die Bestätigung von E-Mail-Adressen ab, damit sich niemand
aussperrt. Sie täuscht keinen Versand vor.

### https erzwingen

Normalerweise übernimmt das Plesk unter Hosting-Einstellungen mit
*Permanente SEO-sichere 301-Umleitung von HTTP zu HTTPS*. Geht das nicht,
erledigt es die Anwendung:

```php
'app' => ['force_https' => true],
```

Nur einschalten, wenn ein gültiges Zertifikat vorhanden ist. Sonst entsteht
eine Weiterleitungsschleife.

### Wenn Links auf localhost zeigen

Steht in `config/config.php` unter `app.url` noch eine Adresse mit
`localhost`, merkt die Anwendung das und baut die Links stattdessen aus der
Adresse, unter der die Anfrage ankam. Die Seite bleibt also bedienbar.

Trage trotzdem die richtige Adresse ein, denn E-Mails und die Rücksprung-
adressen der Kanäle entstehen ohne Anfrage und brauchen den festen Wert:

```php
'url' => 'https://deine-domain.tld',
```

## 5. Freistellen der Fotos

Auf dem Server steht rembg nicht zur Verfügung. Für das Freistellen brauchst
du deshalb einen Dienst:

```php
'background' => [
    'provider' => 'spyne',
    'api_key'  => 'DEIN_SCHLUESSEL',
    'scenes'   => ['923' => 'Studio hell'],
],
```

Spyne holt die Fotos selbst von deinem Server ab. Das funktioniert erst,
sobald die Domain öffentlich erreichbar ist, also genau ab jetzt.

## 6. Prüfen

Die Anwendung prüft sich selbst. Im Browser aufrufen:

```
https://deine-domain.tld/systemcheck.php?key=<app.key>
```

Den Schlüssel findest du in `config/config.php` unter `app.key`. Die Seite
zeigt PHP-Version, Erweiterungen, Schreibrechte, Datenbankverbindung, alle
Tabellen und Spalten sowie die letzten Fehler aus dem Protokoll. Antwortet
eine Seite mit Fehler 500, steht der Grund dort.

Nach der Einrichtung `systemcheck.php` löschen.

### Weitere Prüfungen von Hand

- `https://deine-domain.tld/` zeigt die Startseite
- Anmelden mit dem Betreiberkonto aus dem Installer
- Ein Fahrzeug anlegen, Fotos hochladen, Inserat erzeugen
- `storage/logs/` auf Einträge prüfen, falls etwas hakt

## Sicherheit

Die mitgelieferten `.htaccess`-Dateien sperren `config/`, `src/`,
`includes/`, `lang/` und `database/` gegen direkten Zugriff. Prüfe, ob dein
Server sie beachtet:

`https://deine-domain.tld/config/config.php` muss **403** liefern, nicht den
Inhalt. Falls Apache mit `AllowOverride None` läuft, greifen sie nicht; dann
in Plesk unter Apache-Einstellungen `AllowOverride All` setzen oder die
Regeln in die Nginx-Direktiven übernehmen:

```
location ~ ^/(config|src|includes|lang|database|storage)/ { deny all; }
```

## Sicherung

Zwei Dinge sind unersetzlich:

- die Datenbank (Plesk → Datenbanken → Export)
- der Ordner `uploads/` mit allen Fahrzeugfotos

`storage/logs/` darf weg, `config/config.php` gehört gesichert, aber niemals
in ein öffentliches Repository.
