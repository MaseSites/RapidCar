<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\CaBundle;
use App\Core\Config;
use App\Core\Logger;
use RuntimeException;

/**
 * Spyne: Fahrzeugfotos freistellen und in einen Studio-Hintergrund setzen.
 *
 * Spyne arbeitet in zwei Schritten und ist auf Autohandel zugeschnitten:
 *  1. Die Fotos werden zur Verarbeitung angemeldet und bekommen eine SKU.
 *  2. Sobald der Dienst fertig ist, liegt das Ergebnis unter einer URL bereit.
 *
 * Wichtig: Spyne holt die Fotos selbst ab. Sie müssen deshalb öffentlich
 * erreichbar sein. Auf einem lokalen Rechner ist das nicht der Fall, dort
 * meldet der Dienst ehrlich einen Fehler statt still etwas anderes zu tun.
 *
 * Ohne Zugangsdaten ist der Dienst schlicht aus.
 */
final class SpyneService
{
    public const PROVIDER = 'spyne';

    /**
     * Von Spyne dokumentierte Beispiel-Kennung. Nur als Hinweis im Admin
     * gedacht: ob sie funktioniert, haengt am eigenen Spyne-Konto.
     */
    public const DEFAULT_BACKGROUND = '923';

    // Neue Spyne-API (Bearer-Schluessel aus dem Entwicklerhub der Konsole).
    // Die alte replace-bg-Schnittstelle nahm nur die alten auth_key-Schluessel
    // an und lehnte die neuen mit HTTP 400 ab.
    private const SUBMIT_URL = 'https://api.spyne.ai/api/pv1/merchandise/process';
    private const RESULT_URL = 'https://api.spyne.ai/api/pv1/merchandise';

    /** Verarbeitung läuft im Hintergrund; so lange wird darauf gewartet. */
    private const MAX_WAIT_SECONDS = 150;
    private const POLL_INTERVAL_SECONDS = 3;
    private const TIMEOUT_SECONDS = 60;

    public static function isConfigured(): bool
    {
        return self::provider() === self::PROVIDER
            && trim((string) Config::get('background.api_key', '')) !== '';
    }

    /** Welcher Freistell-Anbieter ist eingestellt? */
    public static function provider(): string
    {
        return strtolower(trim((string) Config::get('background.provider', '')));
    }

    /**
     * Hintergründe des Kontos: bei Spyne sind das Kennungen wie "923".
     * Welche zur Verfügung stehen, legt Spyne im Konto fest.
     *
     * @return array<string, string> background_id => Anzeigename
     */
    /**
     * Szenen mit allen Angaben: Name und (falls vorhanden) Vorschaubild.
     * Gespeichert wird je Szene entweder nur der Name (alt) oder ein
     * Objekt {label, preview}; beide Formen werden gelesen.
     *
     * @return array<string, array{label: string, preview: string}>
     */
    public static function scenes(): array
    {
        $scenes = [];

        $normalize = static function ($value, string $id): array {
            if (is_array($value)) {
                $label = trim((string) ($value['label'] ?? ''));
                return [
                    'label'       => $label !== '' ? $label : $id,
                    'preview'     => trim((string) ($value['preview'] ?? '')),
                    'theme'       => trim((string) ($value['theme'] ?? '')),
                    'unavailable' => (bool) ($value['unavailable'] ?? false),
                ];
            }
            $label = trim((string) $value);
            return ['label' => $label !== '' ? $label : $id, 'preview' => '', 'theme' => '', 'unavailable' => false];
        };

        $stored = \App\Service\SettingsService::get('spyne_scenes');
        if ($stored !== null) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id => $value) {
                    $id = trim((string) $id);
                    if ($id !== '') {
                        $scenes[$id] = $normalize($value, $id);
                    }
                }
            }
        }

        $configured = Config::get('background.scenes', []);
        if (is_array($configured)) {
            foreach ($configured as $id => $value) {
                $id = trim((string) $id);
                if ($id !== '' && !isset($scenes[$id])) {
                    $scenes[$id] = $normalize($value, $id);
                }
            }
        }

        // Bewusst kein Rueckfall auf eine erfundene Kennung: Spyne kennt nur
        // Hintergruende, die dem eigenen Konto zugeordnet sind. Eine geratene
        // Nummer wird abgelehnt und sieht fuer den Nutzer wie ein Fehler aus.
        // Ohne Eintraege bleibt die Auswahl leer und sagt das ehrlich.
        return $scenes;
    }

    public static function backgrounds(): array
    {
        $backgrounds = [];
        foreach (self::scenes() as $id => $scene) {
            if ($scene['unavailable']) {
                continue;   // von Spyne abgelehnt, siehe markUnavailable()
            }
            $backgrounds[(string) $id] = $scene['label'];
        }
        // Achtung fuer Abnehmer: PHP fuehrt rein numerische Schluessel
        // ('923') zwingend als Zahl. Wer den Schluessel an eine mit string
        // typisierte Stelle weitergibt, muss ihn mit (string) wandeln.
        return $backgrounds;
    }

    public static function isBackground(string $key): bool
    {
        return isset(self::backgrounds()[$key]);
    }

    /**
     * Schickt ein Foto durch die Verarbeitung und liefert die Bilddaten
     * des Ergebnisses.
     *
     * @param string $imageUrl      Öffentlich erreichbare Adresse des Fotos
     * @param string $backgroundId  Hintergrund des Kontos
     * @param string $skuName       Bezeichnung, unter der Spyne das Fahrzeug führt
     */
    public static function compose(string $imageUrl, string $backgroundId, string $skuName): string
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Für Spyne ist kein Zugang hinterlegt.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für Spyne benötigt.');
        }
        if (!preg_match('#^https?://#i', $imageUrl)) {
            throw new RuntimeException('Spyne holt die Fotos selbst ab. Dafür muss die Anwendung öffentlich erreichbar sein.');
        }

        $jobId = self::submit($imageUrl, $backgroundId, $skuName);
        return self::waitForResult($jobId);
    }

    /**
     * Stösst die Verarbeitung an und liefert sofort die Auftragsnummer.
     * Shared Hosting kappt lange Anfragen nach wenigen Sekunden; deshalb
     * wartet der Server nie selbst, sondern die Oberfläche fragt über
     * checkJob() in Abständen nach.
     */
    /**
     * @param array{plate?: string, banner_url?: string} $options
     *        plate: '0' (nichts), '1' (weisse Flaeche) oder eine Logo-Adresse,
     *               die Spyne auf das Kennzeichen setzt.
     *        banner_url: Bildadresse, die Spyne als Banner auf das Foto legt.
     */
    public static function submitJob(string $imageUrl, string $backgroundId, string $skuName, array $options = []): string
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Für Spyne ist kein Zugang hinterlegt.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für Spyne benötigt.');
        }
        if (!preg_match('#^https?://#i', $imageUrl)) {
            throw new RuntimeException('Spyne holt die Fotos selbst ab. Dafür muss die Anwendung öffentlich erreichbar sein.');
        }
        if (trim($backgroundId) === '') {
            throw new RuntimeException(
                'Es ist kein Hintergrund gewählt. Im Admin unter Einstellungen die '
                . 'Hintergrund-Kennungen aus der Spyne-Konsole eintragen.'
            );
        }
        return self::submit($imageUrl, $backgroundId, $skuName, $options);
    }

    /**
     * Fragt EINMAL nach dem Ergebnis eines Auftrags.
     * Fertig: die Bilddaten. Noch in Arbeit: null. Abgelehnt: Ausnahme.
     */
    public static function checkJob(string $jobId): ?string
    {
        $url = self::RESULT_URL . '?' . http_build_query(['dealerVinId' => $jobId]);
        $response = self::request($url, null);

        $image = $response['mediaData']['image'] ?? ($response['data']['mediaData']['image'] ?? []);
        $status = strtoupper((string) ($image['aiStatus'] ?? ''));
        if ($status === 'FAILED') {
            throw new RuntimeException('Spyne konnte das Foto nicht verarbeiten.');
        }

        foreach ((array) ($image['imageData'] ?? []) as $entry) {
            $output = (string) ($entry['outputImage'] ?? '');
            if ($output !== '') {
                return self::download($output);
            }
        }
        return null;
    }

    /**
     * Merkt sich, dass Spyne diese Kennung nicht kennt. Solche Szenen
     * erscheinen nicht mehr in der Auswahl; im Admin bleiben sie sichtbar
     * und lassen sich dort loeschen oder erneut freigeben.
     */
    private static function markUnavailable(string $sceneId): void
    {
        try {
            $stored = json_decode((string) \App\Service\SettingsService::get('spyne_scenes'), true);
            if (!is_array($stored) || !isset($stored[$sceneId])) {
                return;
            }
            $entry = $stored[$sceneId];
            $entry = is_array($entry) ? $entry : ['label' => (string) $entry, 'preview' => '', 'theme' => ''];
            $entry['unavailable'] = true;
            $stored[$sceneId] = $entry;
            \App\Service\SettingsService::set('spyne_scenes', (string) json_encode($stored));
            Logger::warning('Spyne-Hintergrund nicht freigeschaltet, aus der Auswahl entfernt: ' . $sceneId);
        } catch (\Throwable $e) {
            Logger::warning('Hintergrund konnte nicht markiert werden: ' . $e->getMessage());
        }
    }

    /** Meldet das Foto zur Verarbeitung an und gibt die Auftragsnummer zurück. */
    private static function submit(string $imageUrl, string $backgroundId, string $skuName, array $options = []): string
    {
        // Jede Einreichung bekommt eine eigene Kennung: dieselbe wuerde bei
        // Spyne denselben Fahrzeugeintrag fortschreiben, und der Abruf faende
        // alte Ergebnisse statt des neuen.
        $reference = preg_replace('/[^A-Za-z0-9-]/', '', $skuName) . '-' . bin2hex(random_bytes(4));

        $payload = [
            'vin'   => $reference,
            'media' => [
                // Nur Bilder: 360-Spin und Videos nehmen die
                // Verkaufsplattformen nicht an.
                'image'        => true,
                'spin'         => false,
                'featureVideo' => false,
            ],
            'mediaInput' => [
                'imageData' => [
                    ['url' => $imageUrl],
                ],
            ],
            'processingDetails' => [
                'backgroundId'    => $backgroundId,
                // '0' nichts, '1' weisse Flaeche, oder die Adresse eines
                // Logos, das Spyne auf das Kennzeichen setzt.
                'numberPlateLogo' => (string) ($options['plate']
                    ?? ((bool) Config::get('background.blur_license_plate', false) ? '1' : '0')),
                'image'           => [
                    'backgroundType' => 'legacy',
                ],
            ],
        ];

        // Banner (z.B. das Haendlerlogo) direkt auf das Foto legen
        $bannerUrl = trim((string) ($options['banner_url'] ?? ''));
        if ($bannerUrl !== '' && preg_match('#^https?://#i', $bannerUrl)) {
            $payload['mediaInput']['imageData'][0]['clientMetaData'] = [
                'banner_urls' => [$bannerUrl],
                'banner_copy' => '0',
            ];
        }

        $response = self::request(self::SUBMIT_URL, $payload);

        // Spyne kann mit Erfolgs-Status antworten und die Anfrage trotzdem
        // ablehnen (validationSummary). Ohne diese Pruefung wuerde ewig auf
        // ein Ergebnis gewartet, das nie kommt.
        $summary = $response['data']['validationSummary'] ?? [];
        if (!empty($summary['isRequestRejected'])) {
            $reason = (string) ($summary['displayError']['message'] ?? 'kein Grund genannt');
            if (stripos($reason, 'BackgroundId') !== false) {
                // Nicht jede Kennung aus dem Spyne-Katalog ist dem eigenen
                // Konto zugeordnet. Statt den Nutzer wieder hineinlaufen zu
                // lassen, verschwindet der Hintergrund aus der Auswahl.
                $rejectedId = (string) $payload['processingDetails']['backgroundId'];
                self::markUnavailable($rejectedId);
                throw new RuntimeException(
                    'Der Hintergrund ' . $rejectedId . ' ist in deinem Spyne-Konto nicht '
                    . 'freigeschaltet und wurde aus der Auswahl entfernt. Bitte einen anderen waehlen.'
                );
            }
            throw new RuntimeException('Spyne hat die Anfrage abgelehnt: ' . $reason);
        }

        $jobId = (string) ($response['data']['dealerVinID'] ?? ($response['data']['dealerVinId'] ?? ''));
        if ($jobId === '') {
            throw new RuntimeException('Spyne hat die Verarbeitung nicht angenommen.');
        }
        return $jobId;
    }

    /**
     * Wartet, bis das Ergebnis bereitliegt, und gibt dessen Adresse zurück.
     * Spyne verarbeitet im Hintergrund, deshalb wird in Abständen nachgefragt.
     */
    private static function waitForResult(string $jobId): string
    {
        $deadline = time() + self::MAX_WAIT_SECONDS;
        while (time() < $deadline) {
            $binary = self::checkJob($jobId);
            if ($binary !== null) {
                return $binary;
            }
            sleep(self::POLL_INTERVAL_SECONDS);
        }
        throw new RuntimeException('Spyne war nicht rechtzeitig fertig. Bitte später erneut versuchen.');
    }

    /** Holt das fertige Bild. */
    private static function download(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, CaBundle::applyTo([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $status >= 400 || (string) $raw === '') {
            throw new RuntimeException('Das Ergebnis von Spyne konnte nicht geladen werden.');
        }
        return (string) $raw;
    }

    /**
     * Ruft die Schnittstelle auf. Mit $payload als POST, ohne als GET.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private static function request(string $url, ?array $payload): array
    {
        $bearer = 'Authorization: Bearer ' . trim((string) Config::get('background.api_key', ''));
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', $bearer],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json', $bearer];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, CaBundle::applyTo($options));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('Spyne nicht erreichbar: ' . $curlError);
            throw new RuntimeException('Spyne ist nicht erreichbar.');
        }
        if ($status === 401) {
            throw new RuntimeException('Spyne hat den Schlüssel abgelehnt.');
        }
        if ($status >= 400) {
            // Den Grund mitgeben: Spyne nennt fehlende oder falsche Felder
            // in der Antwort, ohne ihn liesse sich nichts beheben.
            $decodedError = json_decode((string) $raw, true);
            $reason = '';
            if (is_array($decodedError)) {
                $reason = (string) ($decodedError['message'] ?? ($decodedError['error'] ?? ''));
            }
            Logger::error('Spyne hat abgelehnt', ['status' => $status, 'antwort' => mb_substr((string) $raw, 0, 500)]);
            throw new RuntimeException(
                'Spyne hat die Anfrage abgelehnt (HTTP ' . $status . ($reason !== '' ? ': ' . $reason : '') . ').'
            );
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
