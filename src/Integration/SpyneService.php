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

    /** Von Spyne dokumentierte Standard-Hintergrund-Kennung. */
    public const DEFAULT_BACKGROUND = '923';

    private const SUBMIT_URL = 'https://api.spyne.ai/api/pv1/image/replace-bg';
    private const RESULT_URL = 'https://api.spyne.ai/api/pv1/sku/get-ready-images/v2';

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
    public static function backgrounds(): array
    {
        // Drei Quellen, in dieser Reihenfolge zusammengefuehrt:
        //   1. Vom Betreiber im Admin gepflegte Szenen (Einstellungen)
        //   2. Szenen aus der Konfigurationsdatei
        //   3. Rueckfall: die von Spyne dokumentierte Standard-Kennung 923,
        //      damit der Hintergrundwechsel sofort funktioniert. Eine Liste
        //      der Konto-Hintergruende bietet Spyne per API nicht an; die
        //      Kennungen kommen aus dem Spyne-Konto (Darkroom).
        $backgrounds = [];

        $stored = \App\Service\SettingsService::get('spyne_scenes');
        if ($stored !== null) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id => $label) {
                    $id = trim((string) $id);
                    if ($id !== '') {
                        $backgrounds[$id] = trim((string) $label) !== '' ? (string) $label : $id;
                    }
                }
            }
        }

        $configured = Config::get('background.scenes', []);
        if (is_array($configured)) {
            foreach ($configured as $id => $label) {
                $id = trim((string) $id);
                if ($id !== '' && !isset($backgrounds[$id])) {
                    $backgrounds[$id] = trim((string) $label) !== '' ? (string) $label : $id;
                }
            }
        }

        if ($backgrounds === []) {
            $backgrounds[self::DEFAULT_BACKGROUND] = 'Studio (Standard)';
        }
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

        $skuId = self::submit($imageUrl, $backgroundId, $skuName);
        $outputUrl = self::waitForResult($skuId);

        return self::download($outputUrl);
    }

    /**
     * Stösst die Verarbeitung an und liefert sofort die Auftragsnummer.
     * Shared Hosting kappt lange Anfragen nach wenigen Sekunden; deshalb
     * wartet der Server nie selbst, sondern die Oberfläche fragt über
     * checkJob() in Abständen nach.
     */
    public static function submitJob(string $imageUrl, string $backgroundId, string $skuName): string
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
        return self::submit($imageUrl, $backgroundId, $skuName);
    }

    /**
     * Fragt EINMAL nach dem Ergebnis eines Auftrags.
     * Fertig: die Bilddaten. Noch in Arbeit: null. Abgelehnt: Ausnahme.
     */
    public static function checkJob(string $skuId): ?string
    {
        $authKey = trim((string) Config::get('background.api_key', ''));
        $url = self::RESULT_URL . '?' . http_build_query(['auth_key' => $authKey, 'sku_id' => $skuId]);
        $response = self::request($url, null);

        foreach ((array) ($response['image_data'] ?? []) as $entry) {
            $output = (string) ($entry['output_image'] ?? '');
            if ($output !== '') {
                return self::download($output);
            }
            $reject = (string) ($entry['reject_reason'] ?? '');
            if ($reject !== '') {
                throw new RuntimeException('Spyne hat das Foto abgelehnt: ' . $reject);
            }
        }
        return null;
    }

    /** Meldet das Foto zur Verarbeitung an und gibt die SKU zurück. */
    private static function submit(string $imageUrl, string $backgroundId, string $skuName): string
    {
        $payload = [
            'auth_key'        => trim((string) Config::get('background.api_key', '')),
            'sku_name'        => $skuName,
            'category_id'     => 'Automobile',
            'background_type' => 'legacy',
            'background'      => $backgroundId !== '' ? $backgroundId : '923',
            'image_data'      => [[
                'category'   => 'Exterior',
                'image_urls' => [$imageUrl],
            ]],
        ];

        // Kennzeichen unkenntlich machen: 1 setzt eine weisse Fläche darüber
        if ((bool) Config::get('background.blur_license_plate', false)) {
            $payload['number_plate_logo'] = '1';
        }

        $response = self::request(self::SUBMIT_URL, $payload);
        $skuId = (string) ($response['data']['sku_id'] ?? '');
        if ($skuId === '') {
            throw new RuntimeException('Spyne hat die Verarbeitung nicht angenommen.');
        }
        return $skuId;
    }

    /**
     * Wartet, bis das Ergebnis bereitliegt, und gibt dessen Adresse zurück.
     * Spyne verarbeitet im Hintergrund, deshalb wird in Abständen nachgefragt.
     */
    private static function waitForResult(string $skuId): string
    {
        $deadline = time() + self::MAX_WAIT_SECONDS;
        $authKey = trim((string) Config::get('background.api_key', ''));

        while (time() < $deadline) {
            $url = self::RESULT_URL . '?' . http_build_query(['auth_key' => $authKey, 'sku_id' => $skuId]);
            $response = self::request($url, null);

            foreach ((array) ($response['image_data'] ?? []) as $entry) {
                $output = (string) ($entry['output_image'] ?? '');
                if ($output !== '') {
                    return $output;
                }
                $reject = (string) ($entry['reject_reason'] ?? '');
                if ($reject !== '') {
                    throw new RuntimeException('Spyne hat das Foto abgelehnt: ' . $reject);
                }
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
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
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
            Logger::error('Spyne hat abgelehnt', ['status' => $status]);
            throw new RuntimeException('Spyne hat die Anfrage abgelehnt (HTTP ' . $status . ').');
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
