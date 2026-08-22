# RapidCar: AI Vehicle Marketing Hub

Webbasierte SaaS-Plattform für Autohäuser: **Fotos hochladen → Fahrzeug erfassen → Inserat erstellen → bewerten → verbessern → Social Media → veröffentlichen.**

- **Technik:** PHP 8+ (OOP, PDO), MySQL/MariaDB (oder SQLite für lokale Entwicklung), Vanilla JS, kein Build-System, kein Composer.
- **KI:** OpenAI-Anbindung mit Bildanalyse (`src/AI/OpenAiProvider.php`). Ohne hinterlegten Schlüssel bleibt die Anwendung im **Demo-Modus** mit regelbasierten, klar gekennzeichneten Ergebnissen. Umschalten unter Admin → Einstellungen → KI-Modus.
- **Integrationen:** Zwölf Kanäle sind vorbereitet (`src/Integration/ChannelRegistry.php`): AutoScout24, mobile.de, Ricardo, Autolina, tutti.ch, Ricardo, Kleinanzeigen, Facebook Marketplace sowie Instagram, TikTok, Facebook und YouTube. Ohne Partner-Zugangsdaten zeigt die UI ehrlich „Nicht konfiguriert": nichts wird vorgetäuscht.
- **Guthaben:** Abgerechnet wird pro veröffentlichtem Inserat. Anlegen, Bearbeiten und Vorschau sind kostenlos, erst das Veröffentlichen verbraucht ein Guthaben (einmalig pro Inserat). Pakete: 1/CHF 10, 5/CHF 40, 10/CHF 70, 50/CHF 300, 100/CHF 500. Neue Autohäuser erhalten ein Gratis-Inserat.
- **Sprachen:** Deutsch, Englisch, Französisch, Italienisch. Umschaltbar in der Kopfzeile, in den Einstellungen (Autohaus-Standard) und im Profil (persönlich). Fehlende Übersetzungen fallen automatisch auf Deutsch zurück.

---

## Installation auf dem Webserver (z.B. Hosttime)

1. **Hochladen:** Den kompletten Projektordner per FTP in das Web-Root laden (`httpdocs/` bzw. `public_html/`).
2. **Datenbank anlegen:** Im Hosting-Panel (Plesk/cPanel) eine MySQL-Datenbank + Benutzer erstellen.
3. **Installer aufrufen:** `https://deine-domain.ch/install/` im Browser öffnen.
   - Schritt 1 prüft PHP-Version, Extensions (`pdo_mysql`, `gd`, `openssl`, `mbstring`, `fileinfo`, `curl`) und Schreibrechte.
   - Schritt 2 bis 5: Datenbank verbinden → Tabellen anlegen → Super-Admin-Konto erstellen → optional Demo-Daten (5 Fahrzeuge mit generierten Platzhalterbildern).
4. **Installer entfernen:** Nach Abschluss sperrt sich `/install` selbst: das Verzeichnis trotzdem **löschen**.
5. **Anmelden:** Login mit dem Admin-Konto; der Admin-Bereich liegt unter `/admin`.

### Lokale Entwicklung (ohne MySQL)

```bash
php -S localhost:8080 -t .
```

Dann `http://localhost:8080/install/` öffnen und **SQLite** wählen.

### Tests

```bash
php tests/run.php
```

---

## Konfiguration

Wird vom Installer als `config/config.php` erzeugt (Vorlage: `config/config.sample.php`). Optional überschreibt eine `.env` im Root einzelne Werte. **Keine Schlüssel im Frontend**: alles serverseitig.

| Bereich | Schlüssel | Zweck |
|---|---|---|
| App | `app.url`, `app.key`, `app.debug` | Basis-URL, Verschlüsselungsschlüssel (Tokens), Debug |
| DB | `db.driver` (`mysql`/`sqlite`), Host/Name/User/Passwort | Datenbank |
| Mail | `mail.driver` (`log`/`mail`/`smtp`) + SMTP-Daten | E-Mail-Versand; `log` schreibt nach `storage/logs/mail-*.log` |
| Features | `features.email_verification` | Bestätigungs-E-Mail bei Registrierung (aktiviert) |
| Google | `google.client_id`, `google.client_secret`, `google.redirect_uri` | Registrierung und Anmeldung über Google. Weiterleitungs-URI in der Google-Konsole: `<app.url>/google-callback.php`. Ohne Werte erscheint der Knopf nicht |
| KI | `ai.mode`, `ai.api_key`, `ai.model`, `ai.api_url` | OpenAI: `mock`/`live`, Schlüssel, Modell mit Bildverständnis (Standard `gpt-4o-mini`, günstig), `ai.image_detail` (`low`/`high`) steuert die Bildkosten |
| AutoScout24 | `autoscout.api_url` (optional) | Standard ist `https://listing-creation.api.autoscout24.com`. Zugangsdaten gibt jeder Händler selbst im Dashboard ein |
| Instagram | `instagram.*` | Optional. Bequemer im Admin unter Kanäle eintragen (verschlüsselt in der Datenbank, kein Dateizugriff nötig) |
| Weitere Kanäle | `channels.<key>.*` (z.B. `channels.tiktok.client_id`) | Zugangsdaten je Plattform |
| Zahlung | `payment.provider` (`stripe`), `payment.api_key`, `payment.webhook_secret` | Mit Stripe geht die Kasse direkt zur Stripe-Zahlseite; Gutschrift erst nach Webhook-Bestätigung (`/api/payments/stripe-webhook.php`). Ohne Angabe bleiben Bestellungen offen und werden im Admin freigegeben |

## AutoScout24

Die Anbindung nutzt die **Listing-Creation-API** mit **HTTP Basic Auth**. Der Zugang muss bei AutoScout24 beantragt werden, er ist nie automatisch aktiv.

### Zwei Betriebsarten (beide unterstützt)

**A) Plattform-Zugang (empfohlen für SaaS).** Der Betreiber erhält von AutoScout24 einen Zugang, der stellvertretend für mehrere Kunden arbeiten darf. Konfiguration: `autoscout.platform_username` und `autoscout.platform_password`. Ein Autohaus wählt dann nur seine Kundennummer und gibt **kein Passwort** ein.

**B) Eigener Zugang je Autohaus.** Bleiben die Plattformwerte leer, hinterlegt jedes Autohaus im Dashboard seine eigenen Zugangsdaten:

1. Benutzername und Passwort des AutoScout24-Händlerkontos eingeben
2. RapidCar prüft sie direkt über `GET /customers` und zeigt die verfügbaren Kundennummern
3. Kundennummer wählen, fertig

### Trennung der Autohäuser

Eine Kundennummer gehört genau einem Autohaus. Im Plattform-Modus werden nur noch nicht vergebene Kundennummern zur Auswahl angeboten, und der Versuch, eine fremde direkt abzusenden, wird abgewiesen. Alle API-Aufrufe laufen über `/customers/{customerId}/...` mit der Kundennummer des jeweiligen Autohauses, sodass jedes Autohaus ausschliesslich seine eigenen Inserate sieht.

**Anmeldung über Google, Facebook oder Apple:** Die Schnittstelle arbeitet ausschliesslich mit Benutzername und Passwort. Ein Social-Login lässt sich nicht verwenden, weil dabei kein Passwort existiert, das die Schnittstelle prüfen könnte. In diesem Fall müssen bei AutoScout24 Zugangsdaten für die Schnittstelle angefordert werden (oft "API-Zugang" oder "technischer Benutzer"). Die Anwendung weist im Verbindungsdialog darauf hin.

Die Zugangsdaten werden AES-256-GCM-verschlüsselt in `integration_tokens` abgelegt und nie im Klartext angezeigt.

**Zertifikatsprüfung:** Ausgehende HTTPS-Verbindungen prüfen die Zertifikatskette immer. Ist in der `php.ini` weder `curl.cainfo` noch `openssl.cafile` gesetzt (typisch bei frischen Windows-Installationen), sucht die Anwendung selbst eine vorhandene Zertifikatsliste an den üblichen Systempfaden. Findet sie keine, erklärt die Fehlermeldung, was zu tun ist: entweder `curl.cainfo` in der `php.ini` setzen oder eine `cacert.pem` unter `storage/cacert.pem` ablegen. Die Prüfung wird nie abgeschaltet, weil über diese Verbindung Zugangsdaten übertragen werden.

**Abgleich:** Der Knopf *Aktualisieren* in der Fahrzeugliste holt den Bestand aller verbundenen Kanäle (`GET /customers/{id}/listings`) und ordnet ihn über die Referenz `VAI-<Fahrzeug-ID>` den lokalen Fahrzeugen zu. Ist der Stand älter als 15 Minuten, zieht die Seite ihn im Hintergrund nach. Jedes Fahrzeug erscheint genau einmal, mit einem Marker je Plattform; Inserate ohne lokales Gegenstück stehen separat unter "Nur auf einer Plattform vorhanden". Fahrzeuge lassen sich anschliessend einzeln übertragen (`POST /customers/{id}/images` für Bilder, dann `POST/PUT .../listings`). **Neue Inserate werden immer inaktiv angelegt**; das Aktivieren ist ein bewusster zweiter Schritt. Marken-, Modell- und Aufzählungs-IDs werden über `/makes` und `/references` aufgelöst und zwischengespeichert; was sich nicht eindeutig zuordnen lässt, wird nicht geraten, sondern dem Händler als offener Punkt gemeldet.

## Ein Inserat, nicht zwei Objekte

In der Oberfläche heisst das Objekt durchgehend **Inserat**. Fahrzeugdaten sind ein Abschnitt darin, kein eigener Eintrag. Werden 20 Fotos gleichzeitig hochgeladen, entsteht genau **ein** Inserat: Der Browser schickt eine Kennung des Hochladevorgangs mit, und der Server legt pro Kennung nur einen Entwurf an. Ohne diese Klammer hätte jede der 20 gleichzeitigen Anfragen ihren eigenen Entwurf erzeugt.

## Kaufvertrag auslesen

Auf der Inseratseite gibt es ein eigenes Feld für **Kaufvertrag, Fahrzeugausweis oder Serviceheft**. Das Dokument wird an OpenAI geschickt, die schriftlich festgehaltenen Angaben (Vorhalter, Erstzulassung, Kilometerstand, Fahrgestellnummer, Leistung) werden in die leeren Felder übernommen, und die Datei wird **sofort danach gelöscht**. Sie wird nie ins Inserat übernommen und nirgends veröffentlicht, weil Kaufverträge personenbezogene Daten enthalten. Auch bei einem Fehler wird die Datei entfernt.

Ausgelesen wird nur, was im Dokument steht. Für Dokumente gilt derselbe strikte Schema-Zwang wie für Fotos: Was fehlt, bleibt leer.

## Hintergrund tauschen

Zwei getrennte Schritte, damit die Kosten klein bleiben:

1. **Freistellen** (ein KI-Aufruf je Foto): Das Fahrzeug wird vom Hintergrund getrennt, das Ergebnis wird als PNG mit Transparenz gespeichert.
2. **Hintergrund wählen** (kein KI-Aufruf): Der gespeicherte Zuschnitt wird lokal per GD auf den gewünschten Hintergrund gesetzt. Beliebig oft, ohne weitere Kosten.

Zur Auswahl stehen sechs Vorlagen (Studio hell, Studio dunkel, Ausstellung, Asphalt, Weiss, Akzentblau) sowie eigene Hintergründe, die das Autohaus selbst hochlädt. Das Originalfoto bleibt erhalten und lässt sich jederzeit wiederherstellen. Ein Zuschnitt kann Details verändern, deshalb weist die Oberfläche darauf hin, das Ergebnis zu prüfen.

## Ausstattung auswählen statt tippen

Die Ausstattung wird aus einem Katalog mit rund 90 Merkmalen in fünf Gruppen gewählt (`src/Service/FeatureCatalog.php`), mit Suchfeld. Das verhindert Schreibvarianten desselben Merkmals und spart Tippen. Was fehlt, lässt sich weiterhin frei ergänzen. Die Suche läuft im Browser und schickt keine Anfrage an den Server.

## Kanäle nach Region

Ein Schweizer Autohaus bekommt mobile.de und Kleinanzeigen gar nicht erst angeboten, ein deutsches kein Autolina oder tutti.ch. Massgeblich ist das Land des Autohauses; soziale Netzwerke gelten überall. Über einen Knopf lassen sich die übrigen Regionen einblenden, und ein bereits verbundener Kanal bleibt immer sichtbar. Dazu gibt es eine Suche über alle Plattformen.

**Zugangsdaten je Plattform** hinterlegt der Betreiber unter `/admin/channels.php`: Client-ID, Geheimnis, Redirect-URI, Auth- und Token-URL. Die Werte liegen AES-256-GCM-verschlüsselt in der Datenbank, das Geheimnis wird nie zurück ins Formular geschrieben. Steht ein Wert in `config/config.php`, hat die Datei Vorrang. Erst wenn die Zugangsdaten vorhanden sind, wird der Kanal für die Händler freigeschaltet; vorher steht dort ehrlich „Nicht konfiguriert".

## Fotos: ein Hauptbild, viele Nebenbilder

Pro Fahrzeug sind **20 Fotos** möglich (`uploads.max_images_per_vehicle`). Beim Anlegen lassen sich alle auf einmal ziehen; ein Zähler zeigt den Stand, und was über der Grenze liegt, wird gar nicht erst gesendet statt still verworfen.

Das erste Foto wird automatisch zum **Hauptbild**. Über den Stern auf einer Kachel wird jedes andere Foto zum Hauptbild, alle übrigen sind Nebenbilder. Es gibt immer genau eines: Wird das Hauptbild gelöscht, rückt das nächste Foto nach.

Alle Fotos gehören zu **einem** Fahrzeug und damit zu **einem** Inserat. In der Fahrzeugliste, im Editor, in der Vorschau und bei der Übertragung an die Plattformen steht das Hauptbild immer vorn.

## Instagram: einmal einrichten, alle Händler verbinden sich selbst

Instagram lässt Beiträge nur über eine Meta-App zu. Diese App gehört dem
**Betreiber der Plattform**, nicht dem einzelnen Autohaus. Der Ablauf ist
deshalb zweistufig:

**Einmalig durch den Betreiber** (kein FTP, kein Dateizugriff):

1. Auf [developers.facebook.com](https://developers.facebook.com) eine App vom Typ „Business" anlegen.
2. Im Admin-Bereich unter **Kanäle → Instagram** die **Client-ID** und das **Client-Secret** eintragen und speichern. Die Werte liegen verschlüsselt in der Datenbank.
3. Die dort angezeigte **Redirect-URI** (`<domain>/api/channels/callback.php?channel=instagram`) in der Meta-Konsole als gültige OAuth-Weiterleitung hinterlegen. Sie wird automatisch aus der eigenen Domain abgeleitet, muss also nur kopiert werden.

**Durch jedes Autohaus, mit zwei Klicks:**

1. Im Dashboard auf **Kanäle** gehen und bei Instagram auf **Verbinden** klicken.
2. Bei Meta mit dem eigenen Konto anmelden und die Freigabe erteilen.

Danach gehört der Zugang dem jeweiligen Autohaus: Der Token wird
AES-256-GCM-verschlüsselt gespeichert, jeder Beitrag geht auf das eigene
Instagram-Business-Konto. Kein Händler sieht oder ändert Server-Einstellungen.

**Veröffentlichen:** Im Post-Generator erscheint bei verbundenem Konto der
Knopf „Veröffentlichen". Dahinter läuft die Meta-Graph-API: verknüpftes
Instagram-Business-Konto ermitteln, Medien-Container mit Bild und Text anlegen,
veröffentlichen. Das Bild muss dafür öffentlich erreichbar sein, was auf dem
Webserver der Fall ist; von `localhost` lehnt Meta die Bild-URL ab und die
Meldung sagt das ehrlich.

## Hintergrund entfernen: kostenlos statt kostenpflichtig

Das Freistellen kann auf zwei Wegen laufen. Die Anwendung nimmt automatisch den
ersten verfügbaren:

| Weg | Kosten | Tempo | Voraussetzung |
|---|---|---|---|
| **rembg (lokal)** | kostenlos | rund 30 bis 60 Sekunden je Foto | Python auf dem Server |
| OpenAI-Bildbearbeitung | zahlungspflichtig je Foto | ähnlich | hinterlegter API-Schlüssel |

**Lokal einrichten** (empfohlen, einmalig):

```bash
pip install "rembg[cpu]"
```

Danach findet die Anwendung `rembg` selbst im PATH. Alternativ den vollen Pfad
unter `background.rembg_path` eintragen. Ab dann kostet Freistellen nichts mehr
und die Fotos verlassen den eigenen Server nicht.

Das Modell lässt sich über `background.rembg_model` wechseln: `u2net` (Standard)
trennt am saubersten, `u2netp` ist rund viermal schneller, lässt aber sichtbare
Reste vom Hintergrund stehen.

**Wichtig für Shared Hosting:** Läuft dort kein Python (bei Hosttime meist der
Fall), bleibt nur der OpenAI-Weg oder der Verzicht auf das Freistellen. Der
Zuschnitt wird in jedem Fall gespeichert, sodass jeder weitere
Hintergrundwechsel ohne neuen Aufruf auskommt.

## Guthaben und Abrechnung

Kostenlos: Fahrzeug anlegen, Fotos hochladen, Inserat schreiben, Score prüfen, Vorschau ansehen.
Kostenpflichtig: nur das Veröffentlichen eines Inserats (1 Guthaben, einmalig pro Inserat).

Solange kein Zahlungsanbieter konfiguriert ist, erfasst ein Kauf eine Bestellung im Status „Offen"; der Betreiber gibt sie unter `/admin/orders.php` frei. Für Tests gibt es zusätzlich einen klar benannten Testkauf ohne Zahlung, der protokolliert wird.

## Anmeldung

Angemeldet wird mit **E-Mail oder Benutzername** (Feld „E-Mail oder Benutzername" auf `/login.php`). Der Benutzername ist optional: Konten ohne einen melden sich unverändert mit ihrer E-Mail an. Er ist eindeutig und wird ohne Rücksicht auf Gross- und Kleinschreibung verglichen. Gesetzt wird er direkt in der Spalte `users.username`.

## Rollen

- `super_admin`: Plattform-Betreiber, einziger Zugang zu `/admin`
- `dealer_admin`: Autohaus-Administrator (bei Registrierung)
- `dealer_user`: Mitarbeiter

## Verzeichnisse

```
/            Öffentliche Seiten (Landing, Auth) + .htaccess
/dashboard   Händler-Bereich  ·  /admin  Betreiber-Bereich  ·  /api  JSON-Endpunkte
/src         Klassen (App\)   ·  /includes  Bootstrap, Guards, Layouts
/config      Konfiguration    ·  /database  Schemas + Demo-Seeds
/lang        Sprachdateien (de) · /assets  CSS/JS/Icons
/uploads     Bilder (PHP-Ausführung deaktiviert) · /storage  Logs, SQLite, Lock
/install     Installations-Assistent (nach Installation löschen)
/tests       Testskript ohne Abhängigkeiten
```

## Sicherheit (Kurzfassung)

Passwörter nur als `password_hash()`-Hashes · ausschliesslich Prepared Statements · CSRF-Token auf jedem Formular und API-Aufruf · gehärtete Sessions (HttpOnly, SameSite, Regeneration, Idle-Timeout) · Login-Drosselung pro E-Mail und IP · Upload-Validierung (MIME + `getimagesize`) mit GD-Neukodierung und Zufallsnamen · Integration-Tokens AES-256-GCM-verschlüsselt · `src/`, `config/`, `storage/`, `database/`, `includes/`, `lang/` per `.htaccess` gesperrt · neutrale Fehlerseiten (404/403/500) ohne PHP-Details.

## KI: Bildanalyse mit OpenAI

### Einrichten

1. In `config/config.php` den Schlüssel hinterlegen (nur serverseitig, nie im Browser):

```php
'ai' => [
    'mode'    => 'live',
    'api_key' => 'sk-...',
    'model'   => 'gpt-4o',   // Modell mit Bildverständnis
    'api_url' => '',         // leer = https://api.openai.com/v1
],
```

2. Danach unter Admin → Einstellungen den KI-Modus auf **Live** stellen. Ohne hinterlegten Schlüssel ist die Auswahl gesperrt und die Anwendung bleibt im Demo-Modus (§72: nichts wird vorgetäuscht).

### Was passiert beim Fahrzeug anlegen

Nach dem Foto-Upload gehen bis zu sechs Bilder an das Modell. Die Antwort ist über ein striktes JSON-Schema (Structured Outputs) festgelegt: Für jedes der 17 Felder liefert das Modell **Wert**, **Sicherheit in Prozent** und **Alternativen**.

- **Sicher erkannt** (Sicherheit ab 80 %, keine Alternativen): Der Wert wird direkt eingetragen und grün als „Erkannt" markiert.
- **Nicht eindeutig** (Alternativen vorhanden oder Sicherheit unter 80 %): Unter dem Feld erscheint eine Auswahlliste mit allen plausiblen Antworten plus „Eigene Eingabe". Der Händler entscheidet.
- **Nicht erkennbar**: Das Feld bleibt leer. Es wird nichts geraten. Kilometerstand, Preis und Erstzulassung sind auf Fotos praktisch nie ablesbar und bleiben daher fast immer offen.

Getriebe, Antrieb und Treibstoff sind im Schema auf die gültigen Codes der Anwendung begrenzt, sodass keine unbrauchbaren Werte zurückkommen können. Titel und Beschreibung des Inserats erzeugt derselbe Anbieter; liefert er keinen Titel, greift der regelbasierte Titel aus den Fahrzeugdaten.

### Fehlerfälle

Abgelehnter Schlüssel (HTTP 401) und erschöpftes Kontingent (HTTP 429) werden in Klartext gemeldet, nicht als technischer Fehlercode. Schlägt der Aufruf fehl, bleibt das Formular unverändert: Es werden keine Ersatzdaten eingesetzt.
