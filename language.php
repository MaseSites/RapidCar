<?php
/**
 * Sprachumschaltung für die aktuelle Sitzung.
 * Aufruf: /language.php?set=fr&return=/dashboard/
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use App\Core\Lang;

$requested = (string) ($_GET['set'] ?? '');
Lang::switchTo($requested);

// Nur interne Rücksprünge erlauben (kein offener Redirect)
$return = (string) ($_GET['return'] ?? '');
$path = parse_url($return, PHP_URL_PATH);
$query = parse_url($return, PHP_URL_QUERY);

if (!is_string($path) || $path === '' || str_starts_with($return, 'http') || str_starts_with($return, '//')) {
    redirect('index.php');
}

header('Location: ' . $path . ($query !== null ? '?' . $query : ''));
exit;
