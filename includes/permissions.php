<?php
/**
 * Rollen- und Berechtigungsprüfungen (§45, §50).
 * require_role(...) bricht mit 403 ab, wenn die Rolle nicht passt.
 */

declare(strict_types=1);

if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}

use App\Auth\AuthService;

/** Bricht mit 403 ab, wenn der aktuelle Benutzer keine der Rollen besitzt. */
function require_role(string ...$roles): void
{
    $role = AuthService::role();
    if ($role === null || !in_array($role, $roles, true)) {
        http_response_code(403);
        require BASE_PATH . '/errors/403.php';
        exit;
    }
}

/** Nur der Plattform-Betreiber (§44/§45). */
function require_super_admin(): void
{
    require_role(AuthService::ROLE_SUPER_ADMIN);
}

/** Händler-Kontext: Benutzer muss einem Autohaus zugeordnet sein. */
function require_dealership(): int
{
    $dealershipId = AuthService::dealershipId();
    if ($dealershipId === null) {
        http_response_code(403);
        require BASE_PATH . '/errors/403.php';
        exit;
    }
    return $dealershipId;
}

/**
 * KI-Aufrufe kosten Geld und laufen deshalb nur mit Guthaben.
 *
 * Ohne Guthaben wird keine Anfrage an OpenAI gestellt. Das Inserat bleibt
 * unbegrenzt von Hand bearbeitbar, nur die KI schaltet sich ab.
 */
function guard_ai_credits(int $dealershipId): void
{
    if (\App\Service\CreditService::hasCredits($dealershipId)) {
        return;
    }
    json_response(false, null, t('ai.no_credits'), 402);
}

/**
 * Demo-Modus (§64): Schreiboperationen sind deaktiviert.
 * Vor jeder schreibenden Aktion aufrufen.
 */
function guard_demo_mode(): void
{
    $user = AuthService::user();
    if ($user !== null && (int) ($user['is_demo'] ?? 0) === 1) {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_response(false, null, 'Im Demo-Modus sind Änderungen deaktiviert.', 403);
        }
        \App\Core\Session::flash('warning', 'Im Demo-Modus sind Änderungen deaktiviert.');
        redirect('dashboard/');
    }
}
