<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;

/**
 * AutoScout24 hat die Zugangsdaten abgelehnt (HTTP 401 oder 403).
 *
 * Eigene Klasse, damit die Oberfläche diesen Fall von Netzwerk- und
 * Serverfehlern unterscheiden und gezielt weiterhelfen kann.
 */
final class AutoScoutAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 401
    ) {
        parent::__construct($message);
    }
}
