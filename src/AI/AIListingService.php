<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Database;

/**
 * Inserat-Generator (§31): Titel und Beschreibung.
 *
 * Mock-Modus: regelbasierte Text-Erzeugung aus ECHTEN Fahrzeugdaten —
 * deterministisch und nützlich, gekennzeichnet als Demo-Modus (§72).
 * Live-Modus: derselbe Aufruf geht an den konfigurierten Provider.
 */
final class AIListingService
{
    /**
     * @return array{title: string, description: string, mode: string}
     */
    public static function generate(int $vehicleId): array
    {
        $vehicle = Database::fetch('SELECT * FROM vehicles WHERE id = :id', ['id' => $vehicleId]);
        if ($vehicle === null) {
            throw new AIException('Fahrzeug nicht gefunden.');
        }
        $features = array_map(
            static fn(array $row): string => (string) $row['feature'],
            Database::fetchAll('SELECT feature FROM vehicle_features WHERE vehicle_id = :vid ORDER BY id', ['vid' => $vehicleId])
        );

        $dealershipId = (int) $vehicle['dealership_id'];
        // Erlaubte Zusaetze fuer den Titel: aus echten Daten, nie erfunden
        $highlights = \App\Service\ListingStyle::titleHighlights($vehicle, $features);

        if (!AIService::isMock()) {
            $result = AIService::provider()->complete('listing_generation', [
                'vehicle'  => $vehicle,
                'features' => $features,
                // Ton und Beispieltext des Autohauses aus den Einstellungen
                'style'    => \App\Service\ListingStyle::instruction($dealershipId),
                // Titelstil des Autohauses, samt der erlaubten Zusaetze
                'title_style' => \App\Service\ListingStyle::titleInstruction($dealershipId, $highlights),
            ]);
            // Sicherheitsnetz: Gedankenstriche gehören nicht in unsere Texte,
            // auch wenn das Modell sie doch einmal verwendet.
            $clean = static function (string $text): string {
                $text = str_replace([' – ', ' — ', '–', '—'], [', ', ', ', '-', '-'], $text);
                return trim($text);
            };

            $title = $clean((string) ($result['title'] ?? ''));
            // Zu lange Titel schneiden die Portale ab: dann lieber der
            // regelbasierte Titel, der die Laenge einhaelt.
            if ($title === '' || mb_strlen($title) > \App\Service\ListingStyle::TITLE_MAX_LENGTH) {
                $title = self::buildTitle($vehicle, $highlights, $dealershipId);
            }

            // Der Text bleibt als Vorlage erhalten: geänderte Fahrzeugdaten
            // fliessen dadurch später ohne neuen KI-Aufruf ein.
            $descriptionTemplate = $clean((string) $result['text']);

            return [
                'title'                => \App\Service\ListingTemplate::render($title, $vehicle),
                'description'          => \App\Service\ListingTemplate::render($descriptionTemplate, $vehicle),
                'title_template'       => $title,
                'description_template' => $descriptionTemplate,
                'mode'                 => 'live',
            ];
        }

        return [
            'title'                => self::buildTitle($vehicle, $highlights, $dealershipId),
            'description'          => self::buildDescription($vehicle, $features),
            'title_template'       => null,
            'description_template' => null,
            'mode'                 => 'mock',
        ];
    }

    /**
     * Regelbasierter Titel im Stil des Autohauses: Fahrzeugname plus die
     * staerksten Angaben, getrennt durch einen senkrechten Strich.
     *
     * @param array<string, mixed> $vehicle
     * @param array<int, string>   $highlights
     */
    private static function buildTitle(array $vehicle, array $highlights, int $dealershipId): string
    {
        return \App\Service\ListingStyle::composeTitle(
            $vehicle,
            $highlights,
            \App\Service\ListingStyle::titleExtras($dealershipId)
        );
    }

    /**
     * @param array<string, mixed> $vehicle
     * @param array<int, string> $features
     */
    private static function buildDescription(array $vehicle, array $features): string
    {
        $name = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '') . ' ' . ($vehicle['variant'] ?? ''));
        $lines = [];

        $intro = $name !== '' ? $name : 'Dieses Fahrzeug';
        $facts = [];
        if (!empty($vehicle['year'])) {
            $facts[] = 'Baujahr ' . (int) $vehicle['year'];
        }
        if (!empty($vehicle['first_registration'])) {
            $facts[] = 'Erstzulassung ' . $vehicle['first_registration'];
        }
        if (!empty($vehicle['mileage'])) {
            $facts[] = number_format((float) $vehicle['mileage'], 0, '.', "'") . ' km';
        }
        $lines[] = $intro . ($facts !== [] ? ': ' . implode(', ', $facts) . '.' : '.');
        $lines[] = '';

        $tech = [];
        if (!empty($vehicle['power_hp'])) {
            $tech[] = (int) $vehicle['power_hp'] . ' PS' . (!empty($vehicle['power_kw']) ? ' (' . (int) $vehicle['power_kw'] . ' kW)' : '');
        }
        $fuelLabels = [
            'petrol' => 'Benzin', 'diesel' => 'Diesel', 'electric' => 'Elektro',
            'hybrid' => 'Hybrid', 'plug_in_hybrid' => 'Plug-in-Hybrid', 'gas' => 'Gas',
        ];
        if (!empty($vehicle['fuel_type'])) {
            $tech[] = $fuelLabels[(string) $vehicle['fuel_type']] ?? (string) $vehicle['fuel_type'];
        }
        $transLabels = ['manual' => 'Handschaltung', 'automatic' => 'Automatik', 'semi_automatic' => 'Halbautomatik'];
        if (!empty($vehicle['transmission'])) {
            $tech[] = $transLabels[(string) $vehicle['transmission']] ?? (string) $vehicle['transmission'];
        }
        $driveLabels = ['fwd' => 'Frontantrieb', 'rwd' => 'Heckantrieb', 'awd' => 'Allradantrieb'];
        if (!empty($vehicle['drivetrain'])) {
            $tech[] = $driveLabels[(string) $vehicle['drivetrain']] ?? (string) $vehicle['drivetrain'];
        }
        if ($tech !== []) {
            $lines[] = 'Technik: ' . implode(' · ', $tech) . '.';
            $lines[] = '';
        }

        if ($features !== []) {
            $lines[] = 'Ausstattung (Auszug):';
            foreach (array_slice($features, 0, 10) as $feature) {
                $lines[] = '- ' . $feature;
            }
            $lines[] = '';
        }

        if (!empty($vehicle['color'])) {
            $lines[] = 'Aussenfarbe: ' . $vehicle['color'] . '.';
            $lines[] = '';
        }

        $lines[] = 'Besichtigung und Probefahrt nach Vereinbarung. Wir freuen uns auf Ihre Anfrage.';

        return implode("\n", $lines);
    }
}
