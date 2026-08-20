<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Database;

/**
 * Abo "RapidCar Plus": schaltet die Werkzeuge frei, die laufende Kosten
 * verursachen oder laufend gepflegt werden muessen.
 *
 * Enthalten:
 *   - Hintergrund per KI ersetzen (Freistellen samt Schatten)
 *   - Kennzeichen durch das eigene Logo ersetzen
 *   - Logo im Hintergrund platzieren
 *   - Veroeffentlichen auf Instagram
 *
 * Ohne Abo bleibt alles andere nutzbar: Fahrzeuge anlegen, Inserate
 * erzeugen, auf die Verkaufsplattformen stellen. Es wird nichts
 * vorgetaeuscht: gesperrte Werkzeuge sagen klar, dass sie zum Abo gehoeren.
 */
final class SubscriptionService
{
    public const PLAN_FREE = 'free';
    public const PLAN_PLUS = 'plus';

    /** Monatspreis in CHF. */
    public const PRICE = 19.90;
    public const CURRENCY = 'CHF';

    /**
     * Werkzeuge, die das Abo freischaltet. Der Schluessel steuert die
     * Sperre, der Text steht auf der Abo-Seite.
     */
    public const FEATURES = [
        'background' => 'Hintergrund per KI ersetzen',
        'plate_logo' => 'Kennzeichen durch das eigene Logo ersetzen',
        'brand_logo' => 'Logo im Hintergrund platzieren',
        'instagram'  => 'Auf Instagram veröffentlichen',
    ];

    /**
     * Was das Abo bringt, wie es auf der Abo-Seite steht.
     * Jeder Punkt: Titel und ein Satz dazu. Hier steht ausschliesslich,
     * was die Anwendung heute wirklich kann (Paragraf 72).
     *
     * @return array<int, array{title: string, text: string}>
     */
    public static function benefits(): array
    {
        return [
            [
                'title' => 'Studio-Hintergründe per KI',
                'text'  => 'Fahrzeug freistellen und in einen professionellen Hintergrund setzen, aus einer kuratierten Auswahl.',
            ],
            [
                'title' => 'Schatten und Glanz',
                'text'  => 'Die KI setzt einen passenden Schatten unter das Fahrzeug und erhält Reflexionen auf Lack und Scheiben.',
            ],
            [
                'title' => 'Kennzeichen abdecken oder branden',
                'text'  => 'Kennzeichen unkenntlich machen oder durch das eigene Logo ersetzen, mit einem Klick für alle Fotos.',
            ],
            [
                'title' => 'Logo im Bild platzieren',
                'text'  => 'Das eigene Logo erscheint auf jedem verarbeiteten Foto, Position frei wählbar.',
            ],
            [
                'title' => 'Alle Fotos auf einmal',
                'text'  => 'Ein Klick setzt denselben Hintergrund auf sämtliche Fotos des Fahrzeugs, damit die Galerie einheitlich wirkt.',
            ],
            [
                'title' => 'Instagram-Beiträge erstellen',
                'text'  => 'Fertige Beiträge aus den Fahrzeugfotos, im gewählten Vorlagen-Design mit Preis und Eckdaten.',
            ],
            [
                'title' => 'Direkt auf Instagram veröffentlichen',
                'text'  => 'Beitrag aus RapidCar heraus auf das verbundene Instagram-Konto stellen, ohne Umweg über das Handy.',
            ],
            [
                'title' => 'Eigene Hintergründe',
                'text'  => 'Wunschhintergründe des Autohauses hinterlegen und wie die mitgelieferten verwenden.',
            ],
        ];
    }

    /**
     * Angekuendigt, aber noch nicht gebaut. Steht als solches auf der
     * Abo-Seite: wer zahlt, soll wissen, was heute geht und was kommt.
     *
     * @return array<int, string>
     */
    public static function planned(): array
    {
        return [
            'Beiträge im Voraus planen und zeitgesteuert veröffentlichen',
            'Automatisch veröffentlichen, sobald ein Inserat fertig ist',
            'Instagram-Werbeanzeigen aus dem Inserat erstellen',
        ];
    }

    /** Ist das Abo aktiv? Ein gekuendigtes laeuft bis zum Enddatum weiter. */
    public static function isActive(int $dealershipId): bool
    {
        $row = self::current($dealershipId);
        if ($row === null || (string) $row['plan'] !== self::PLAN_PLUS) {
            return false;
        }
        if ((string) $row['status'] !== 'active') {
            return false;
        }
        $endsAt = (string) ($row['ends_at'] ?? '');
        // Ohne Enddatum laeuft es weiter; mit Enddatum bis dahin.
        return $endsAt === '' || strtotime($endsAt) >= time();
    }

    /** @return array<string, mixed>|null */
    public static function current(int $dealershipId): ?array
    {
        return Database::fetch(
            'SELECT * FROM subscriptions WHERE dealership_id = :d ORDER BY id DESC LIMIT 1',
            ['d' => $dealershipId]
        );
    }

    /**
     * Schaltet das Abo frei. Wird nach bestaetigter Zahlung aufgerufen,
     * nie vorher: ohne Zahlungseingang gibt es kein Abo.
     */
    public static function activate(int $dealershipId, ?string $endsAt = null, ?int $userId = null): void
    {
        $now = Database::now();
        $existing = self::current($dealershipId);
        $data = [
            'plan'       => self::PLAN_PLUS,
            'status'     => 'active',
            'started_at' => $now,
            'ends_at'    => $endsAt,
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            Database::update('subscriptions', (int) $existing['id'], $data);
        } else {
            Database::insert('subscriptions', $data + [
                'dealership_id' => $dealershipId,
                'created_at'    => $now,
            ]);
        }

        ActivityLogger::log(
            $userId,
            'subscription.activated',
            'RapidCar Plus aktiviert',
            'subscription',
            null,
            $dealershipId
        );
    }

    /**
     * Beendet das Abo. Mit $endsAt laeuft es bis dahin weiter (Kuendigung
     * zum Periodenende), ohne sofort.
     */
    public static function cancel(int $dealershipId, ?string $endsAt = null, ?int $userId = null): void
    {
        $existing = self::current($dealershipId);
        if ($existing === null) {
            return;
        }
        Database::update('subscriptions', (int) $existing['id'], [
            'status'     => $endsAt === null ? 'cancelled' : 'active',
            'ends_at'    => $endsAt,
            'updated_at' => Database::now(),
        ]);
        ActivityLogger::log(
            $userId,
            'subscription.cancelled',
            $endsAt === null ? 'RapidCar Plus beendet' : 'RapidCar Plus gekündigt zum ' . $endsAt,
            'subscription',
            null,
            $dealershipId
        );
    }

    /** Laeuft das Abo aus, ist aber noch gueltig? */
    public static function endsAt(int $dealershipId): ?string
    {
        $row = self::current($dealershipId);
        $endsAt = $row !== null ? (string) ($row['ends_at'] ?? '') : '';
        return $endsAt !== '' ? $endsAt : null;
    }
}
