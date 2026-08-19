<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Logger;
use RuntimeException;

/**
 * Fahrzeug freistellen (Hintergrund entfernen).
 *
 * Es gibt zwei Wege. Ist ein Freistell-Dienst hinterlegt, geht das Foto
 * dorthin: Spyne und PhotoRoom sind auf Fahrzeug- und Produktfotos
 * spezialisiert und liefern deutlich sauberere Kanten als ein allgemeines
 * Werkzeug. Ohne Dienst übernimmt das lokale rembg, das nichts kostet und
 * die Fotos im Haus behält, dafür langsamer und gröber arbeitet.
 *
 * Ist keiner von beiden verfügbar, gibt es eine klare Fehlermeldung; es wird
 * nichts vorgetäuscht.
 *
 * Einrichtung des lokalen Werkzeugs: `pip install rembg[cli]`, danach den
 * Pfad in der Konfiguration unter background.rembg_path hinterlegen oder
 * rembg im PATH verfügbar machen.
 */
final class CutoutService
{
    /** Ein Foto braucht auf normaler Hardware 30 bis 60 Sekunden. */
    private const TIMEOUT_SECONDS = 180;

    /**
     * Freistell-Dienste, die ohne Codeänderung nutzbar sind.
     *
     * endpoint    Adresse der Schnittstelle
     * file_field  Name des Feldes, unter dem das Foto hochgeladen wird
     * auth_header Kopfzeile für den Schlüssel, %s wird durch ihn ersetzt
     * fields      feste Zusatzfelder des Dienstes
     * response    'binary' (Bilddaten direkt) oder 'base64_json'
     *
     * @var array<string, array<string, mixed>>
     */
    public const PROVIDERS = [
        // Spyne ist auf Autohandel zugeschnitten und laeuft ueber einen
        // eigenen Weg (SpyneService), weil dort Freistellen und Hintergrund
        // in einem Durchlauf passieren.
        'spyne' => [
            'name'     => 'Spyne',
            'external' => true,
        ],
        // Produktfotos allgemein, Monatsabo
        'photoroom' => [
            'name'        => 'PhotoRoom',
            'endpoint'    => 'https://sdk.photoroom.com/v1/segment',
            'file_field'  => 'image_file',
            'auth_header' => 'x-api-key: %s',
            'fields'      => ['format' => 'png'],
            'response'    => 'binary',
        ],
        // Allgemeiner Dienst, Abrechnung je Bild
        'removebg' => [
            'name'        => 'remove.bg',
            'endpoint'    => 'https://api.remove.bg/v1.0/removebg',
            'file_field'  => 'image_file',
            'auth_header' => 'X-Api-Key: %s',
            'fields'      => ['size' => 'auto'],
            'response'    => 'binary',
        ],
    ];

    /**
     * Gewählter Dienst samt seiner Eckdaten. Einzelne Werte lassen sich in
     * der Konfiguration überschreiben, falls der Anbieter etwas umstellt.
     *
     * @return array<string, mixed>|null
     */
    public static function provider(): ?array
    {
        if (!self::hasRemoteService()) {
            return null;
        }

        $key = strtolower(trim((string) Config::get('background.provider', '')));
        if (!isset(self::PROVIDERS[$key])) {
            $key = 'removebg';
        }
        $provider = self::PROVIDERS[$key];
        // Spyne laeuft nicht ueber den allgemeinen Weg: dort gehoert
        // der Hintergrund untrennbar dazu (siehe SpyneService).
        if (($provider['external'] ?? false) === true) {
            return null;
        }
        $provider['key'] = $key;

        // Eigene Adresse oder eigener Kopfzeilenname gehen vor
        $endpoint = trim((string) Config::get('background.api_url', ''));
        if ($endpoint !== '') {
            $provider['endpoint'] = $endpoint;
        }
        $header = trim((string) Config::get('background.api_key_header', ''));
        if ($header !== '') {
            $provider['auth_header'] = $header;
        }
        if ($key === 'removebg') {
            $size = trim((string) Config::get('background.api_size', ''));
            if ($size !== '') {
                $provider['fields']['size'] = $size;
            }
        }

        return $provider;
    }

    /** Pfad zum lokalen Werkzeug oder null, wenn keines gefunden wurde. */
    public static function localToolPath(): ?string
    {
        $configured = trim((string) Config::get('background.rembg_path', ''));
        if ($configured !== '') {
            return is_file($configured) ? $configured : null;
        }
        if (!function_exists('proc_open')) {
            return null;
        }
        // Im PATH suchen, ohne Shell-Interpretation der Eingabe
        $finder = stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'where' : 'which';
        $process = @proc_open(
            [$finder, 'rembg'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return null;
        }
        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $first = strtok($output, "\r\n");
        return $first !== false && $first !== '' && is_file($first) ? $first : null;
    }

    public static function isLocalAvailable(): bool
    {
        return self::localToolPath() !== null;
    }

    /** Ist ein Dienst wie remove.bg hinterlegt? */
    public static function hasRemoteService(): bool
    {
        return trim((string) Config::get('background.api_key', '')) !== '';
    }

    /** Beschreibt, welcher Weg aktiv ist (für Statusanzeigen). */
    public static function activeMethod(): string
    {
        if (\App\Integration\SpyneService::isConfigured()) {
            return 'spyne';
        }
        if (self::hasRemoteService() && self::provider() !== null) {
            return 'service';
        }
        if (self::isLocalAvailable()) {
            return 'local';
        }
        return 'none';
    }

    /** Name des aktiven Dienstes für Anzeigen, sonst leer. */
    public static function providerName(): string
    {
        if (\App\Integration\SpyneService::isConfigured()) {
            return (string) self::PROVIDERS['spyne']['name'];
        }
        $provider = self::provider();
        return $provider === null ? '' : (string) $provider['name'];
    }

    /**
     * Stellt das Fahrzeug frei und liefert die PNG-Binärdaten mit
     * durchsichtigem Hintergrund.
     */
    public static function cutout(string $absolutePath): string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('Das Foto wurde nicht gefunden.');
        }

        // Der Fachdienst hat Vorrang: sauberere Kanten und deutlich schneller
        if (self::hasRemoteService()) {
            return self::cutoutWithService($absolutePath);
        }
        $tool = self::localToolPath();
        if ($tool !== null) {
            return self::cutoutWithLocalTool($tool, $absolutePath);
        }
        throw new RuntimeException(
            'Zum Freistellen wird das lokale Werkzeug rembg benötigt '
            . '(pip install "rembg[cpu]") oder ein Zugang zu einem '
            . 'Freistell-Dienst (background.api_key).'
        );
    }

    /**
     * Freistellen über den hinterlegten Fachdienst.
     *
     * Der Bildinhalt geht dabei an diesen Dienst. Antwortet er mit einem
     * Fehler, wird dessen Meldung durchgereicht statt beschönigt.
     */
    private static function cutoutWithService(string $absolutePath): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Die PHP-Erweiterung cURL wird für den Freistell-Dienst benötigt.');
        }

        $provider = self::provider();
        if ($provider === null) {
            throw new RuntimeException('Es ist kein Freistell-Dienst hinterlegt.');
        }
        $apiKey = trim((string) Config::get('background.api_key', ''));

        $postFields = [
            (string) $provider['file_field'] => new \CURLFile(
                $absolutePath,
                (string) (@getimagesize($absolutePath)['mime'] ?? 'image/jpeg'),
                'foto.jpg'
            ),
        ];
        foreach ((array) $provider['fields'] as $field => $value) {
            $postFields[$field] = (string) $value;
        }

        $ch = curl_init((string) $provider['endpoint']);
        curl_setopt_array($ch, \App\Core\CaBundle::applyTo([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER     => [sprintf((string) $provider['auth_header'], $apiKey)],
        ]));

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error($provider['name'] . ' nicht erreichbar: ' . $curlError);
            throw new RuntimeException($provider['name'] . ' ist nicht erreichbar.');
        }
        if ($status >= 400) {
            Logger::error('Freistell-Dienst abgelehnt', ['dienst' => $provider['key'], 'status' => $status]);
            throw new RuntimeException(
                $provider['name'] . ' hat die Anfrage abgelehnt'
                . self::serviceError((string) $raw, $status)
            );
        }

        $image = self::decodeResponse((string) $raw, (string) $provider['response']);
        if ($image === '') {
            throw new RuntimeException($provider['name'] . ' hat kein Bild geliefert.');
        }
        return $image;
    }

    /** Holt die Bilddaten aus der Antwort des Dienstes. */
    private static function decodeResponse(string $raw, string $format): string
    {
        if ($raw === '') {
            return '';
        }
        if ($format !== 'base64_json') {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        // Verschiedene Dienste benennen das Feld unterschiedlich
        foreach (['result_b64', 'image', 'data', 'base64'] as $field) {
            if (isset($decoded[$field]) && is_string($decoded[$field])) {
                return (string) base64_decode($decoded[$field], true);
            }
        }
        return '';
    }

    /** Fehlertext des Dienstes, falls er einen mitschickt. */
    private static function serviceError(string $raw, int $status): string
    {
        $decoded = json_decode($raw, true);
        $message = '';
        if (is_array($decoded)) {
            $message = (string) ($decoded['errors'][0]['title']
                ?? $decoded['error']['message']
                ?? $decoded['message']
                ?? '');
        }
        return $message !== '' ? ': ' . $message : ' (HTTP ' . $status . ').';
    }

    private static function cutoutWithLocalTool(string $tool, string $absolutePath): string
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Die PHP-Funktion proc_open ist auf diesem Server gesperrt.');
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'cutout_');
        if ($outputPath === false) {
            throw new RuntimeException('Es konnte keine Arbeitsdatei angelegt werden.');
        }
        $outputPath .= '.png';

        try {
            // Argumente als Liste: keine Shell, keine Injektionsfläche
            // Modell: u2net trennt am saubersten. 'u2netp' ist rund viermal
            // schneller, lässt aber sichtbar Reste vom Hintergrund stehen.
            $model = trim((string) Config::get('background.rembg_model', 'u2net'));
            $arguments = [$tool, 'i'];
            if ($model !== '' && preg_match('/^[a-z0-9_-]+$/i', $model) === 1) {
                $arguments[] = '-m';
                $arguments[] = $model;
            }
            $arguments[] = $absolutePath;
            $arguments[] = $outputPath;

            $process = proc_open(
                $arguments,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Das Freistell-Werkzeug konnte nicht gestartet werden.');
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stderr = '';
            $started = time();
            while (true) {
                $status = proc_get_status($process);
                $stderr .= (string) stream_get_contents($pipes[2]);
                if (!$status['running']) {
                    break;
                }
                if (time() - $started > self::TIMEOUT_SECONDS) {
                    proc_terminate($process);
                    throw new RuntimeException('Das Freistellen hat zu lange gedauert und wurde abgebrochen.');
                }
                usleep(200000);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = (int) ($status['exitcode'] ?? -1);
            proc_close($process);

            if ($exitCode !== 0 || !is_file($outputPath) || (int) filesize($outputPath) === 0) {
                Logger::error('rembg fehlgeschlagen', ['exit' => $exitCode, 'stderr' => mb_substr($stderr, 0, 400)]);
                throw new RuntimeException('Das Freistell-Werkzeug hat kein Ergebnis geliefert.');
            }

            $binary = file_get_contents($outputPath);
            if ($binary === false || $binary === '') {
                throw new RuntimeException('Das freigestellte Bild konnte nicht gelesen werden.');
            }
            return $binary;
        } finally {
            @unlink($outputPath);
            @unlink(substr($outputPath, 0, -4)); // tempnam-Datei ohne Endung
        }
    }
}
