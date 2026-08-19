<?php
/**
 * Konfiguration für den Serverbetrieb.
 *
 * Der Installer erzeugt daraus /config/config.php und trägt Datenbank,
 * Adresse und Schlüssel selbst ein. Alle Vorgaben hier sind auf einen
 * echten Server ausgelegt, nicht auf einen Entwicklungsrechner.
 *
 * Diese Datei niemals mit echten Zugangsdaten ins Versionssystem einchecken.
 */

return [
    'app' => [
        // Leer lassen ist der sichere Weg: die Anwendung nimmt dann die
        // Adresse, unter der die Anfrage ankam. Ein fester Wert wird nur
        // gebraucht, damit Links in E-Mails und die Rücksprungadressen der
        // Kanäle stimmen; die entstehen ohne Anfrage.
        'url'   => '',
        'key'   => '',        // 32 Byte, base64. Der Installer erzeugt ihn.
        'debug' => false,     // Auf einem Server immer false: sonst stehen
                              // interne Angaben auf der Fehlerseite.
        'name'  => 'RapidCar',

        // Zeitzone des Betriebs. Ein Server steht meist auf UTC, dann waeren
        // alle Zeitangaben im Dashboard verschoben.
        'timezone' => 'Europe/Zurich',

        // Saubere Adressen ohne .php (Standard). Nur abschalten, wenn der
        // Server kein mod_rewrite hat und Links deshalb ins Leere laufen.
        'pretty_urls' => true,

        // Leitet http auf https um. Nur einschalten, wenn ein gueltiges
        // Zertifikat vorhanden ist, sonst entsteht eine Weiterleitungsschleife.
        // Auf Plesk erledigt das ueblicherweise schon die Domain-Einstellung.
        'force_https' => false,
    ],

    // Für den Betrieb ist MySQL oder MariaDB vorgesehen. SQLite ist nur für
    // einen Entwicklungsrechner gedacht: es verträgt keine gleichzeitigen
    // Schreibzugriffe, wie sie auf einem Server normal sind.
    'db' => [
        'driver'      => 'mysql',            // mysql | sqlite
        'host'        => 'localhost',
        'port'        => '3306',
        'name'        => '',
        'user'        => '',
        'password'    => '',
        'sqlite_path' => __DIR__ . '/../storage/database.sqlite', // nur bei driver=sqlite
    ],

    // 'mail' nutzt die Mailfunktion des Servers und genügt auf Plesk meist.
    // 'smtp' ist zuverlässiger, wenn die Domain SPF und DKIM gesetzt hat.
    // 'log' schreibt nur ins Protokoll und verschickt nichts; damit bleibt
    // die Bestätigung der Adresse abgeschaltet, sonst käme niemand hinein.
    'mail' => [
        'driver'     => 'mail',              // mail | smtp | log
        'host'       => '',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls',               // tls | ssl | none
        'from'       => '',                  // leer = noreply@<Domain>
        'from_name'  => 'RapidCar',
        'contact'    => '',                  // Empfaenger des Kontaktformulars,
                                             // leer = erstes Betreiberkonto
    ],

    'features' => [
        'email_verification' => true,        // Bestätigungs-E-Mail bei Registrierung (§11)
    ],

    // Registrierung und Anmeldung über Google (OAuth 2.0).
    // Zugangsdaten aus der Google Cloud Console (OAuth-Client, Typ Webanwendung).
    // Autorisierte Weiterleitungs-URI: https://deine-domain.ch/google-callback.php
    // Ohne diese Werte erscheint der Google-Knopf nicht.
    'google' => [
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => '',  // leer = <app.url>/google-callback.php
    ],

    // KI-Anbindung (OpenAI). Ohne api_key bleibt die Anwendung im Demo-Modus,
    // egal was hier als Modus steht: Es wird nichts vorgetäuscht.
    // KI-Anbieter. Standard ist OpenAI; alternativ laeuft alles ueber Google
    // Gemini, dessen Schnittstelle OpenAI-kompatibel ist und eine kostenlose
    // Stufe hat (Schluessel unter aistudio.google.com). Dafuer setzen:
    //   'api_url'      => 'https://generativelanguage.googleapis.com/v1beta/openai',
    //   'api_key'      => 'AIza...',
    //   'model'        => 'gemini-2.5-flash',
    //   'vision_model' => 'gemini-2.5-flash',
    'ai' => [
        'mode'    => 'mock',        // mock | live, umschaltbar auch im Admin-Bereich
        'api_key' => '',            // sk-... aus dem OpenAI-Konto
        'model'   => 'gpt-4o-mini', // Textmodell für Titel, Beschreibung und Antworten.
                                    // Günstig und dafür völlig ausreichend.
        'vision_model' => '',       // Modell der Fahrzeugerkennung.
                                    // Leer = gpt-5.5. Es liest kleine Typschilder
                                    // wie "STO" zuverlässig und findet die
                                    // Ausstattung auf den Fotos.
        'api_url' => '',            // leer = https://api.openai.com/v1

        // Bilddetailgrad allgemein: 'low' schickt eine verkleinerte Fassung
        // und kostet je Bild etwa ein Zehntel von 'high'.
        'image_detail' => 'low',

        // Bilddetailgrad der Fahrzeugerkennung. Hier lohnt sich 'high':
        // Typschilder sind klein, und verkleinert rät das Modell nur noch.
        // Die Erkennung läuft einmal je Fahrzeug, also fällt das kaum ins Gewicht.
        'detection_detail' => 'high',

        // Qualität beim Freistellen (nur wenn kein lokales rembg vorhanden ist):
        // low | medium | high. Jede Stufe kostet deutlich mehr.
        'image_quality' => 'medium',
    ],

    // AutoScout24 Listing-Creation-API (HTTP Basic Auth, kein OAuth).
    //
    // Zwei Betriebsarten, beide von der API unterstützt:
    //
    //   A) Plattform-Zugang (empfohlen für SaaS): Der Betreiber erhält von
    //      AutoScout24 EINEN Zugang, der stellvertretend für mehrere Kunden
    //      arbeiten darf (GET /customers liefert alle berechtigten Kunden).
    //      Autohäuser wählen dann nur ihre Kundennummer und geben kein
    //      Passwort ein. Dafür hier platform_username/platform_password setzen.
    //
    //   B) Eigener Zugang je Autohaus: Bleiben die Werte leer, hinterlegt
    //      jedes Autohaus im Dashboard seine eigenen Zugangsdaten.
    //
    // Der Zugang muss in beiden Fällen bei AutoScout24 beantragt werden.
    'autoscout' => [
        'platform_username' => '',
        'platform_password' => '',
        'api_url'           => '',  // leer = https://listing-creation.api.autoscout24.com
    ],

    // Instagram über die Meta-Graph-API (§39).
    // In der Meta-Entwicklerkonsole eine App anlegen (Typ Business), die
    // Facebook-Seite mit dem Instagram-Business-Konto verknüpfen und hier
    // client_id, client_secret und redirect_uri eintragen. Die übrigen Werte
    // sind die Meta-Standardadressen und können bleiben.
    // Weiterleitungs-URI in der Meta-Konsole: <app.url>/api/channels/callback.php?channel=instagram
    'instagram' => [
        'client_id'     => '',
        'client_secret' => '',
        'redirect_uri'  => '',
        'auth_url'      => 'https://www.facebook.com/v21.0/dialog/oauth',
        'token_url'     => 'https://graph.facebook.com/v21.0/oauth/access_token',
        'api_url'       => 'https://graph.facebook.com/v21.0',
        'scopes'        => 'instagram_basic,instagram_content_publish,pages_show_list,business_management',
    ],

    'uploads' => [
        'max_file_size_mb' => 12,
        'max_images_per_vehicle' => 20,
    ],

    // Hintergrund entfernen (Fotos freistellen).
    //
    // Ist unter api_key ein Schlüssel hinterlegt, übernimmt der gewählte
    // Fachdienst. Er hat Vorrang: sauberere Kanten, wenige Sekunden statt
    // einer Minute. Ohne Schlüssel läuft das lokale Werkzeug rembg
    // (pip install "rembg[cpu]"): kostenlos, Fotos bleiben im Haus, dafür
    // langsamer und gröber. Die KI von OpenAI ist nie beteiligt.
    //
    // provider:
    //   'spyne' Auf Fahrzeugfotos spezialisiert, Abo je Händler.
    //               Zugang über spyne.com, dort Demo anfragen. Nach dem
    //               Vertrag kommen Schlüssel und die genaue Kopfzeile vom
    //               Anbieter; weicht sie ab, unter api_key_header eintragen.
    //   'photoroom' Produktfotos allgemein, Monatsabo, Selbstbedienung
    //               über photoroom.com/api.
    //   'removebg'  Allgemeiner Dienst, Abrechnung je Bild.
    'background' => [
        'rembg_path'  => '',       // leer = rembg wird im PATH gesucht
        'rembg_model' => 'u2net',  // u2net trennt am saubersten.
                                   // 'u2netp' ist viermal schneller, lässt aber
                                   // sichtbare Reste vom Hintergrund stehen.

        'provider'    => 'removebg',   // spyne | photoroom | removebg
        'api_key'     => '',           // Schlüssel des Dienstes
        'api_url'     => '',           // leer = Standardadresse des Dienstes
        'api_key_header' => '',        // leer = Standard des Dienstes,
                                       // z.B. 'Authorization: Bearer %s'

        // ---------------------------------------------------- nur Spyne
        // complete stellt ganz frei, normal behält den Boden,
        // blur macht den Hintergrund unscharf.
        'cut_type'    => 'complete',

        // Die Studio-Hintergruende des Kontos. Die Kennungen sind Zahlen und
        // kommen aus dem Spyne-Konto, die Namen dahinter stehen so in der
        // Auswahl im Inserat. Mit Spyne ersetzen sie die mitgelieferten Bilder.
        'scenes' => [
            // 'showroom_white' => 'Studio hell',
            // 'showroom_grey'  => 'Studio dunkel',
            // 'outdoor_city'   => 'Stadt',
        ],

        'guideline'    => '',       // Verarbeitungsregel des Kontos, leer = Standard
        'resolution'   => '',       // z.B. '1600x1000', leer = Kontostandard
        'retouching_accuracy' => '', // normal | precise
        'blur_license_plate'  => false,  // Kennzeichen unkenntlich machen
        // Nur remove.bg: 'preview' spart Guthaben beim Ausprobieren.
        'api_size'    => 'auto',
    ],

    // Ausgehende HTTPS-Verbindungen (AutoScout24, weitere Kanäle).
    // Normalerweise leer lassen: Die Zertifikatsliste wird automatisch gefunden.
    // Nur setzen, wenn der Server eine an ungewöhnlicher Stelle hat.
    'http' => [
        'ca_bundle' => '',
    ],

    // Zahlungsanbieter für den Kauf von Inserat-Guthaben.
    // Solange hier nichts hinterlegt ist, wird keine Zahlung vorgetäuscht:
    // Bestellungen bleiben offen und werden vom Betreiber im Admin freigegeben.
    'payment' => [
        'provider' => '',        // 'stripe' fuer die eingebaute Anbindung
        'api_key'  => '',        // sk_live_... oder sk_test_...
        'webhook_secret' => '',  // whsec_... des Webhook-Endpunkts
        // Rechnung je Kauf ueber Stripe Invoicing (PDF am Beleg).
        'invoices' => true,
        // Mehrwertsteuer ueber Stripe Tax. Erst im Stripe-Dashboard
        // einrichten (Steuerregistrierung), sonst lehnt Stripe ab.
        'automatic_tax' => false,
        // Zahlarten fest vorgeben, z.B. ['card', 'twint']: dann zeigt die
        // Kasse genau diese (Apple Pay und Google Pay laufen ueber card).
        // Leer = die Kasse zeigt alles, was im Stripe-Konto aktiv ist.
        // Jede Zahlart muss im Stripe-Dashboard freigeschaltet sein.
        'methods' => [],
    ],

    // Weitere Verkaufs- und Social-Kanäle (ChannelRegistry).
    // Schlüssel entsprechen den Kanal-Keys: mobile_de, car4you, autolina,
    // tutti, ricardo, kleinanzeigen, facebook_marketplace, tiktok, facebook, youtube
    'channels' => [
        'tiktok' => [
            'client_id' => '', 'client_secret' => '', 'redirect_uri' => '',
            'auth_url' => '', 'token_url' => '', 'api_url' => '', 'scopes' => '',
        ],
        'mobile_de' => [
            'client_id' => '', 'client_secret' => '', 'redirect_uri' => '',
            'auth_url' => '', 'token_url' => '', 'api_url' => '', 'scopes' => '',
        ],
        // Weitere Kanäle nach dem gleichen Muster ergänzen.
    ],
];
