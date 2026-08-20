<?php
/**
 * Angaben zum Verkaeufer: Name, Adresse, Telefon.
 *
 * Bei der Einrichtung darf man diese Angaben ueberspringen. Fuers
 * Veroeffentlichen eines Inserats muessen sie da sein, denn die
 * Verkaufsplattformen verlangen eine Halteradresse und eine Nummer.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Database;
use App\Core\Session;
use App\Core\Validator;

$dealershipId = require_dealership();
$dealership = Database::fetch('SELECT * FROM dealerships WHERE id = :id', ['id' => $dealershipId]) ?? [];
$isPrivate = ($dealership['account_type'] ?? 'dealer') === 'private';

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    guard_demo_mode();

    $v = new Validator($_POST);
    $v->required('first_name', 'Vorname')->maxLength('first_name', 'Vorname', 100)
      ->required('last_name', 'Nachname')->maxLength('last_name', 'Nachname', 100)
      ->maxLength('address', 'Strasse und Hausnummer', 255)
      ->maxLength('zip', 'Postleitzahl', 20)
      ->maxLength('city', 'Ort', 120)
      ->maxLength('country', 'Land', 5)
      ->maxLength('phone', 'Telefon', 50);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        Database::update('users', (int) $currentUser['id'], [
            'first_name' => $v->value('first_name'),
            'last_name'  => $v->value('last_name'),
            'updated_at' => Database::now(),
        ]);
        Database::update('dealerships', $dealershipId, [
            'address'    => $v->value('address') !== '' ? $v->value('address') : null,
            'zip'        => $v->value('zip') !== '' ? $v->value('zip') : null,
            'city'       => $v->value('city') !== '' ? $v->value('city') : null,
            'country'    => $v->value('country') !== '' ? strtoupper($v->value('country')) : 'CH',
            'phone'      => $v->value('phone') !== '' ? $v->value('phone') : null,
            'updated_at' => Database::now(),
        ]);
        Session::flash('success', 'Die Angaben sind gespeichert.');
        // Kam die Person von einem blockierten Veroeffentlichen, geht es
        // direkt dorthin zurueck.
        $backTo = (string) ($_POST['back_to'] ?? '');
        if (preg_match('/^\d+$/', $backTo) === 1) {
            redirect('dashboard/vehicle.php?id=' . $backTo);
        }
        redirect('dashboard/details.php');
    }

    $dealership = Database::fetch('SELECT * FROM dealerships WHERE id = :id', ['id' => $dealershipId]) ?? [];
}

$user = Database::fetch('SELECT first_name, last_name FROM users WHERE id = :id', ['id' => (int) $currentUser['id']]) ?? [];
$backTo = (string) ($_GET['vehicle'] ?? '');

$pageTitle = 'Angaben';
$activeNav = 'details';
require BASE_PATH . '/includes/layout/dash-header.php';
?>

<div class="page-head">
    <div>
        <h1>Angaben</h1>
        <div class="sub">
            <?= $isPrivate
                ? 'Deine Adresse und Telefonnummer. Sie erscheinen im Inserat als Halteradresse.'
                : 'Adresse und Telefonnummer des Betriebs. Sie erscheinen im Inserat.' ?>
        </div>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger mb-3"><?= icon('alert-triangle', 16) ?> <?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="details-form">
    <?= App\Core\Csrf::field() ?>
    <?php if ($backTo !== ''): ?>
        <input type="hidden" name="back_to" value="<?= e($backTo) ?>">
    <?php endif; ?>

    <div class="details-grid">
        <div class="card">
            <div class="card-header"><h2>Person</h2></div>
            <div class="card-body">
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Vorname <span class="req-star">*</span></label>
                        <input class="form-control" type="text" name="first_name" required
                               value="<?= e((string) ($user['first_name'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nachname <span class="req-star">*</span></label>
                        <input class="form-control" type="text" name="last_name" required
                               value="<?= e((string) ($user['last_name'] ?? '')) ?>">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label">Telefon <span class="req-star">*</span></label>
                    <input class="form-control" type="tel" name="phone" placeholder="+41 79 123 45 67"
                           value="<?= e((string) ($dealership['phone'] ?? '')) ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Adresse</h2></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Strasse und Hausnummer</label>
                    <input class="form-control" type="text" name="address" placeholder="Musterstrasse 12"
                           value="<?= e((string) ($dealership['address'] ?? '')) ?>">
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Postleitzahl <span class="req-star">*</span></label>
                        <input class="form-control" type="text" name="zip"
                               value="<?= e((string) ($dealership['zip'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ort <span class="req-star">*</span></label>
                        <input class="form-control" type="text" name="city"
                               value="<?= e((string) ($dealership['city'] ?? '')) ?>">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label">Land</label>
                    <select class="form-control" name="country">
                        <?php foreach (['CH' => 'Schweiz', 'DE' => 'Deutschland', 'AT' => 'Österreich', 'LI' => 'Liechtenstein', 'FR' => 'Frankreich', 'IT' => 'Italien'] as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($dealership['country'] ?? 'CH') === $code ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="details-actions">
        <span class="text-sm text-muted">
            <span class="req-star">*</span> Für die Veröffentlichung von Inseraten erforderlich.
        </span>
        <button class="btn btn-primary" type="submit"><?= icon('check', 15) ?> Speichern</button>
    </div>
</form>

<?php require BASE_PATH . '/includes/layout/dash-footer.php'; ?>
