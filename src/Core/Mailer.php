<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Mail-Versand mit drei Treibern:
 *  - log  → schreibt Mails nach /storage/logs/mail-*.log (Entwicklung, §11)
 *  - mail → PHP mail() (Standard auf Shared Hosting)
 *  - smtp → minimaler SMTP-Client (STARTTLS/SSL), keine externen Pakete
 */
final class Mailer
{
    /**
     * Absenderadresse. Ohne Eintrag entsteht sie aus der eigenen Domain,
     * damit Mails nicht mit einer fremden Adresse hinausgehen und am
     * Spamfilter hängen bleiben.
     */
    private static function fromAddress(): string
    {
        $configured = trim((string) Config::get('mail.from', ''));
        if ($configured !== '') {
            return $configured;
        }
        $host = (string) parse_url((string) base_url(), PHP_URL_HOST);
        return 'noreply@' . ($host !== '' ? $host : 'localhost');
    }

    /**
     * Name, mit dem sich der Server beim Mailserver meldet.
     */
    private static function heloHost(): string
    {
        $host = (string) parse_url((string) base_url(), PHP_URL_HOST);
        return $host !== '' ? $host : 'localhost';
    }

    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        // Auf einem Server ist der Versand ueber die Mailfunktion die Vorgabe.
        // 'log' bleibt moeglich, muss aber ausdruecklich gesetzt werden.
        $driver = (string) Config::get('mail.driver', 'mail');

        try {
            $sent = match ($driver) {
                'mail' => self::sendViaMail($to, $subject, $htmlBody),
                'smtp' => self::sendViaSmtp($to, $subject, $htmlBody),
                default => self::sendViaLog($to, $subject, $htmlBody),
            };
        } catch (\Throwable $e) {
            Logger::error('E-Mail-Versand fehlgeschlagen: ' . $e->getMessage(), ['to' => $to, 'subject' => $subject]);
            $sent = false;
        }

        self::record($to, $subject, $htmlBody, $driver, $sent);
        return $sent;
    }

    /**
     * Protokolliert jede Mail in der Datenbank: der Betreiber sieht je
     * Kunde, was wann hinausging und ob der Versand geklappt hat.
     * Ein Fehler hier darf den Versand nie scheitern lassen.
     */
    private static function record(string $to, string $subject, string $htmlBody, string $driver, bool $sent): void
    {
        try {
            Database::insert('sent_emails', [
                'recipient'  => mb_strtolower(trim($to)),
                'subject'    => mb_substr($subject, 0, 255),
                'body'       => mb_substr($htmlBody, 0, 20000),
                'driver'     => $driver,
                'was_sent'   => $sent ? 1 : 0,
                'created_at' => Database::now(),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('E-Mail-Protokoll fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private static function sendViaLog(string $to, string $subject, string $htmlBody): bool
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = sprintf(
            "=== %s ===\nAn: %s\nBetreff: %s\n\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody))
        );
        @file_put_contents($dir . '/mail-' . date('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX);
        Logger::info('E-Mail im Log-Modus gespeichert', ['to' => $to, 'subject' => $subject]);
        return true;
    }

    private static function sendViaMail(string $to, string $subject, string $htmlBody): bool
    {
        $from = self::fromAddress();
        $fromName = (string) Config::get('mail.from_name', 'RapidCar');

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
        ];
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers));
    }

    private static function sendViaSmtp(string $to, string $subject, string $htmlBody): bool
    {
        $host = (string) Config::get('mail.host', '');
        $port = (int) Config::get('mail.port', 587);
        $username = (string) Config::get('mail.username', '');
        $password = (string) Config::get('mail.password', '');
        $encryption = (string) Config::get('mail.encryption', 'tls'); // tls (STARTTLS) | ssl | none
        $from = self::fromAddress();
        $fromName = (string) Config::get('mail.from_name', 'RapidCar');

        if ($host === '') {
            throw new \RuntimeException('SMTP-Host nicht konfiguriert.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 15);
        if ($socket === false) {
            throw new \RuntimeException("SMTP-Verbindung fehlgeschlagen: {$errstr}");
        }
        stream_set_timeout($socket, 15);

        $read = static function () use ($socket): string {
            $response = '';
            while (($line = fgets($socket, 515)) !== false) {
                $response .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $response;
        };
        $expect = static function (string $response, array $codes) use ($socket): void {
            $code = (int) substr($response, 0, 3);
            if (!in_array($code, $codes, true)) {
                fclose($socket);
                throw new \RuntimeException('SMTP-Fehler: ' . trim($response));
            }
        };
        $write = static function (string $command) use ($socket): void {
            fwrite($socket, $command . "\r\n");
        };

        $expect($read(), [220]);
        $write('EHLO ' . self::heloHost());
        $expect($read(), [250]);

        if ($encryption === 'tls') {
            $write('STARTTLS');
            $expect($read(), [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                throw new \RuntimeException('STARTTLS fehlgeschlagen.');
            }
            $write('EHLO ' . self::heloHost());
            $expect($read(), [250]);
        }

        if ($username !== '') {
            $write('AUTH LOGIN');
            $expect($read(), [334]);
            $write(base64_encode($username));
            $expect($read(), [334]);
            $write(base64_encode($password));
            $expect($read(), [235]);
        }

        $write('MAIL FROM:<' . $from . '>');
        $expect($read(), [250]);
        $write('RCPT TO:<' . $to . '>');
        $expect($read(), [250, 251]);
        $write('DATA');
        $expect($read(), [354]);

        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Date: ' . date('r'),
        ];
        // Punkt-Stuffing gemäss RFC 5321
        $body = preg_replace('/^\./m', '..', $htmlBody) ?? $htmlBody;
        $write(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.");
        $expect($read(), [250]);
        $write('QUIT');
        fclose($socket);

        return true;
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
