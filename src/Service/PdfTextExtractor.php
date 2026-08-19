<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use RuntimeException;

/**
 * Text aus einem PDF holen, ohne KI und ohne Kosten.
 *
 * Zwei Wege, in dieser Reihenfolge:
 *  1. pdftotext (aus Poppler). Schnell und zuverlässig, falls vorhanden.
 *  2. Reines PHP: Die Textströme im PDF werden entpackt und die Textbefehle
 *     ausgelesen. Das genügt für die üblichen Kaufverträge und läuft auch auf
 *     Shared Hosting ohne Zusatzsoftware.
 *
 * Bei eingescannten PDFs (reines Bild ohne Textebene) findet keiner der beiden
 * Wege Text. Dann meldet der Aufrufer das ehrlich und bietet den Bildweg an.
 */
final class PdfTextExtractor
{
    private const TIMEOUT_SECONDS = 30;

    /** Pfad zu pdftotext oder null. */
    public static function toolPath(): ?string
    {
        $configured = trim((string) Config::get('documents.pdftotext_path', ''));
        if ($configured !== '') {
            return is_file($configured) ? $configured : null;
        }
        if (!function_exists('proc_open')) {
            return null;
        }
        $finder = stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'where' : 'which';
        $process = @proc_open([$finder, 'pdftotext'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

    /**
     * Liest den Text eines PDFs.
     *
     * @return string Leerer Text bedeutet: keine Textebene gefunden.
     */
    public static function extract(string $absolutePath): string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('Das Dokument wurde nicht gefunden.');
        }

        $tool = self::toolPath();
        if ($tool !== null) {
            $text = self::extractWithTool($tool, $absolutePath);
            if (trim($text) !== '') {
                return $text;
            }
        }

        return self::extractWithPhp($absolutePath);
    }

    private static function extractWithTool(string $tool, string $absolutePath): string
    {
        $target = tempnam(sys_get_temp_dir(), 'pdftxt_');
        if ($target === false) {
            return '';
        }
        try {
            // -layout erhält die Spaltenstruktur, das hilft bei Formularen
            $process = @proc_open(
                [$tool, '-layout', '-enc', 'UTF-8', $absolutePath, $target],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                return '';
            }
            $started = time();
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (time() - $started > self::TIMEOUT_SECONDS) {
                    proc_terminate($process);
                    break;
                }
                usleep(100000);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $text = is_file($target) ? (string) file_get_contents($target) : '';
            return self::normalize($text);
        } finally {
            @unlink($target);
        }
    }

    /**
     * Textextraktion in reinem PHP: Die Inhaltsströme werden entpackt und die
     * Textbefehle (Tj, TJ) ausgelesen.
     */
    private static function extractWithPhp(string $absolutePath): string
    {
        $raw = (string) file_get_contents($absolutePath);
        if ($raw === '' || !str_starts_with($raw, '%PDF')) {
            return '';
        }

        $text = '';
        // Alle Ströme einsammeln; komprimierte werden entpackt
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches) === false) {
            return '';
        }
        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded === false) {
                $decoded = $stream; // unkomprimiert
            }
            $text .= self::extractFromContentStream((string) $decoded) . "\n";
        }

        return self::normalize($text);
    }

    /** Holt die sichtbaren Zeichenketten aus einem Inhaltsstrom. */
    private static function extractFromContentStream(string $content): string
    {
        $out = '';

        // Einzeltexte: (Text) Tj
        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $content, $single) !== false) {
            foreach ($single[1] as $piece) {
                $out .= self::unescape($piece) . ' ';
            }
        }

        // Textblöcke: [(A) -20 (B)] TJ
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $blocks) !== false) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $block, $parts) !== false) {
                    foreach ($parts[1] as $piece) {
                        $out .= self::unescape($piece);
                    }
                    $out .= ' ';
                }
            }
        }

        return $out;
    }

    private static function unescape(string $value): string
    {
        return str_replace(
            ['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'],
            ['(', ')', '\\', "\n", "\r", "\t"],
            $value
        );
    }

    private static function normalize(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
