<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Die Datenbank ist nicht erreichbar (Zugangsdaten, Server, Treiber).
 *
 * Eigener Typ, damit die Fehlerbehandlung diesen Fall von einem
 * Programmierfehler unterscheiden kann: Besucher sehen eine ehrliche
 * Wartungsseite (503) statt "Technischer Fehler", und der Betreiber
 * findet die Ursache im Protokoll und im Systemcheck.
 */
final class DatabaseUnavailableException extends \RuntimeException
{
}
