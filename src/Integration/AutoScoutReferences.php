<?php

declare(strict_types=1);

namespace App\Integration;

use App\Core\Logger;
use RuntimeException;

/**
 * Referenzdaten von AutoScout24 (Marken, Modelle, Aufzählungswerte).
 *
 * Die internen Identifikatoren stammen ausschliesslich aus der API. Es werden
 * keine IDs geraten: Lässt sich ein Wert nicht auflösen, wird das Feld
 * weggelassen und dem Benutzer als offener Punkt gemeldet.
 *
 * Antworten werden in /storage/cache zwischengespeichert (24 Stunden), damit
 * nicht bei jedem Inserat erneut abgefragt wird.
 */
final class AutoScoutReferences
{
    private const CACHE_TTL = 86400;

    /**
     * Alle Marken mit ihren Modellen.
     *
     * @return array<string, mixed>
     */
    public static function makes(int $dealershipId): array
    {
        return self::cached('makes', function () use ($dealershipId): array {
            $response = AutoScoutClient::request($dealershipId, 'GET', '/makes');
            return is_array($response['data']) ? $response['data'] : [];
        });
    }

    /**
     * Referenzwerte, optional gefiltert nach Typ und Kultur.
     *
     * @return array<string, mixed>
     */
    public static function references(int $dealershipId, ?string $referenceType = null, string $culture = 'de-DE'): array
    {
        $key = 'references-' . ($referenceType ?? 'all') . '-' . $culture;
        return self::cached($key, function () use ($dealershipId, $referenceType, $culture): array {
            $query = ['culture' => $culture];
            if ($referenceType !== null) {
                $query['referenceType'] = $referenceType;
            }
            $response = AutoScoutClient::request($dealershipId, 'GET', '/references?' . http_build_query($query));
            return is_array($response['data']) ? $response['data'] : [];
        });
    }

    /**
     * Sucht die AutoScout24-Marken-ID zu einem Markennamen.
     * Gibt null zurück, wenn kein eindeutiger Treffer existiert.
     */
    public static function findMakeId(int $dealershipId, string $makeName): ?int
    {
        $makeName = self::normalize($makeName);
        if ($makeName === '') {
            return null;
        }

        try {
            $makes = self::flattenMakes(self::makes($dealershipId));
        } catch (\Throwable $e) {
            return null;   // Markenliste nicht erreichbar
        }

        foreach ($makes as $make) {
            if (self::normalize((string) ($make['name'] ?? '')) === $makeName) {
                return isset($make['id']) ? (int) $make['id'] : null;
            }
        }
        return null;
    }

    /** Sucht die Modell-ID innerhalb einer Marke. */
    public static function findModelId(int $dealershipId, string $makeName, string $modelName): ?int
    {
        $makeNameNorm = self::normalize($makeName);
        $modelNameNorm = self::normalize($modelName);
        if ($makeNameNorm === '' || $modelNameNorm === '') {
            return null;
        }

        try {
            $makes = self::flattenMakes(self::makes($dealershipId));
        } catch (\Throwable $e) {
            return null;   // Markenliste nicht erreichbar
        }

        foreach ($makes as $make) {
            if (self::normalize((string) ($make['name'] ?? '')) !== $makeNameNorm) {
                continue;
            }
            $models = $make['models'] ?? $make['model'] ?? [];
            if (!is_array($models)) {
                return null;
            }
            // Exakter Treffer hat Vorrang
            foreach ($models as $model) {
                if (is_array($model) && self::normalize((string) ($model['name'] ?? '')) === $modelNameNorm) {
                    return isset($model['id']) ? (int) $model['id'] : null;
                }
            }
            // Sonst eindeutiger Präfix-Treffer (z.B. "M4" in "M4 Competition")
            $matches = [];
            foreach ($models as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $candidate = self::normalize((string) ($model['name'] ?? ''));
                if ($candidate !== '' && (str_starts_with($modelNameNorm, $candidate) || str_starts_with($candidate, $modelNameNorm))) {
                    $matches[] = $model;
                }
            }
            if (count($matches) === 1 && isset($matches[0]['id'])) {
                return (int) $matches[0]['id'];
            }
            return null;
        }
        return null;
    }

    /**
     * Sucht einen Referenzwert (z.B. Kraftstoffart) anhand seines Namens.
     *
     * @param array<int, string> $candidates Mögliche Bezeichnungen
     */
    public static function findReferenceId(int $dealershipId, string $referenceType, array $candidates, string $culture = 'de-DE'): int|string|null
    {
        $normalizedCandidates = array_filter(array_map([self::class, 'normalize'], $candidates));
        if ($normalizedCandidates === []) {
            return null;
        }

        // Ist die Referenzliste nicht erreichbar (keine Verbindung, Ausfall),
        // bleibt der Wert offen statt die ganze Uebertragung abzubrechen.
        try {
            $entries = self::flattenReferences(self::references($dealershipId, $referenceType, $culture));
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($entries as $entry) {
            $name = self::normalize((string) ($entry['name'] ?? $entry['value'] ?? ''));
            if ($name === '') {
                continue;
            }
            foreach ($normalizedCandidates as $candidate) {
                if ($name === $candidate) {
                    $id = $entry['id'] ?? $entry['key'] ?? null;
                    if (is_int($id) || is_string($id)) {
                        return $id;
                    }
                }
            }
        }
        return null;
    }

    /** Leert den Referenz-Cache (z.B. nach einem Verbindungswechsel). */
    public static function clearCache(): void
    {
        foreach (glob(BASE_PATH . '/storage/cache/as24-*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Bringt die Marken-Antwort in eine flache Liste, unabhängig davon, ob die
     * API sie direkt oder unter einem Schlüssel wie "makes" liefert.
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private static function flattenMakes(array $data): array
    {
        if (isset($data['makes']) && is_array($data['makes'])) {
            $data = $data['makes'];
        }
        $result = [];
        foreach ($data as $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private static function flattenReferences(array $data): array
    {
        $result = [];
        $walk = static function (array $items) use (&$walk, &$result): void {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['name']) || isset($item['value'])) {
                    $result[] = $item;
                }
                foreach ($item as $value) {
                    if (is_array($value)) {
                        $walk($value);
                    }
                }
            }
        };
        $walk($data);
        return $result;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return (string) preg_replace('/[^a-z0-9]/u', '', $value);
    }

    /**
     * @param callable(): array<string, mixed> $loader
     * @return array<string, mixed>
     */
    private static function cached(string $key, callable $loader): array
    {
        $dir = BASE_PATH . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/as24-' . preg_replace('/[^a-z0-9\-]/i', '_', $key) . '.json';

        if (is_file($file) && (time() - (int) filemtime($file)) < self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = $loader();
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $data;
    }
}
