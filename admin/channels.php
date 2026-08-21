<?php
/**
 * Kanal-Zugangsdaten (§53/§55).
 *
 * Der Betreiber hinterlegt hier die OAuth-Zugangsdaten der Plattformen, damit
 * die Händler den Kanal anschliessend selbst verbinden können. Ohne diese
 * Werte bleibt der Kanal ehrlich als "Nicht konfiguriert" markiert.
 *
 * Die Werte liegen verschlüsselt in der Datenbank. Das Geheimnis wird nie
 * zurück in das Formular geschrieben (§50).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once BASE_PATH . '/includes/admin-auth.php';
require_once BASE_PATH . '/includes/permissions.php';
require_once BASE_PATH . '/includes/csrf.php';

use App\Core\Session;
use App\Integration\ChannelCredentials;
use App\Integration\ChannelRegistry;
use App\Service\ActivityLogger;

require_super_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $channel = (string) ($_POST['channel'] ?? '');
    if (!ChannelRegistry::exists($channel)) {
        Session::flash('danger', 'Unbekannter Kanal.');
        redirect('admin/channels.php');
    }

    $values = [];
    foreach (ChannelCredentials::FIELDS as $field) {
        $values[$field] = (string) ($_POST[$field] ?? '');
    }
    ChannelCredentials::save($channel, $values);

    ActivityLogger::log((int) $currentUser['id'], 'admin.channel_credentials', 'Zugangsdaten gespeichert: ' . $channel);
    Session::flash('success', 'Zugangsdaten gespeichert: ' . (ChannelRegistry::get($channel)['name'] ?? $channel));
    redirect('admin/channels.php#' . $channel);
}

$channels = ChannelRegistry::all();
$configuredCount = count(array_filter(
    array_keys($channels),
    static fn(string $key): bool => ChannelRegistry::isConfigured($key)
));

$pageTitle = 'Kanäle';
$activeNav = 'channels';
require BASE_PATH . '/includes/layout/admin-header.php';
?>

<div class="page-head">
    <div>
        <h1>Kanal-Zugangsdaten</h1>
        <div class="sub">
            Hier hinterlegte Werte schalten den Kanal für alle Autohäuser frei.
            <?= $configuredCount ?> von <?= count($channels) ?> Kanälen sind einsatzbereit.
        </div>
    </div>
</div>

<div class="alert alert-info mb-3">
    <?= icon('shield', 16) ?>
    <div class="text-sm">
        Die Werte stammen aus der jeweiligen Entwicklerkonsole der Plattform. Sie werden
        verschlüsselt gespeichert und nie im Browser angezeigt. Steht ein Wert bereits in
        <code>config/config.php</code>, hat die Datei Vorrang.
    </div>
</div>

<div class="channel-toolbar">
    <div class="feature-search" style="flex:1;padding:0;border:0;background:none">
        <?= icon('search', 15) ?>
        <input type="text" class="form-control" id="adminChannelSearch" autocomplete="off"
               placeholder="Kanal suchen">
    </div>
</div>

<?php foreach ($channels as $key => $channel): ?>
    <?php
    $stored = ChannelCredentials::stored($key);
    $ready = ChannelRegistry::isConfigured($key);
    $selfService = in_array($key, ChannelRegistry::SELF_SERVICE, true);
    ?>
    <div class="card mb-2 admin-channel" id="<?= e($key) ?>"
         data-search="<?= e(mb_strtolower($channel['name'] . ' ' . $channel['region'])) ?>">
        <div class="card-header">
            <h2 style="display:flex;align-items:center;gap:10px">
                <span class="logo-box" style="width:32px;height:32px"><?= icon($channel['icon'], 16) ?></span>
                <?= e($channel['name']) ?>
                <span class="text-xs text-muted fw-600"><?= e($channel['region']) ?></span>
            </h2>
            <?php if ($selfService): ?>
                <span class="badge badge-success">Händler verbindet selbst</span>
            <?php elseif ($ready): ?>
                <span class="badge badge-success"><?= icon('check', 12) ?> Einsatzbereit</span>
            <?php else: ?>
                <span class="badge badge-neutral">Nicht konfiguriert</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($selfService): ?>
                <p class="text-secondary text-sm" style="margin:0">
                    <?= e($channel['note']) ?>
                    Für diesen Kanal sind keine plattformweiten Zugangsdaten nötig.
                </p>
            <?php else: ?>
                <details<?= $ready ? '' : ' open' ?>>
                    <summary class="text-sm text-secondary" style="cursor:pointer;margin-bottom:12px">
                        <?= e($channel['note']) ?>
                    </summary>
                    <?php if ($key === 'instagram'): ?>
                        <div class="alert alert-info mb-2" style="align-items:flex-start">
                            <?= icon('info', 16) ?>
                            <div class="text-sm">
                                <strong>So kommst du an die beiden Werte:</strong>
                                <ol style="margin:6px 0 0 18px;line-height:1.8">
                                    <li>Auf developers.facebook.com eine App vom Typ <em>Business</em> anlegen</li>
                                    <li>Produkt <em>Instagram</em> hinzufügen, dort <em>API mit Instagram-Login einrichten</em></li>
                                    <li>Client-ID und Geheimnis sind die <strong>Instagram-App-ID</strong> und das
                                        <strong>Instagram-App-Geheimnis</strong>, nicht die der Facebook-App</li>
                                    <li>Diese Adresse als gültige Weiterleitung eintragen:<br>
                                        <code><?= e(\App\Integration\ChannelCredentials::value('instagram', 'redirect_uri')) ?></code></li>
                                </ol>
                                <p style="margin:8px 0 0">
                                    Zum Veröffentlichen für fremde Konten verlangt Meta eine Prüfung der App
                                    (Rechte <code>instagram_business_basic</code> und
                                    <code>instagram_business_content_publish</code>). Ohne Prüfung funktioniert
                                    es nur für Konten, die in der App als Tester eingetragen sind.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <form method="post">
                        <?= App\Core\Csrf::field() ?>
                        <input type="hidden" name="channel" value="<?= e($key) ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Client-ID</label>
                                <input class="form-control" type="text" name="client_id"
                                       value="<?= e($stored['client_id'] ?? '') ?>" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label class="form-label">
                                    Client-Secret
                                    <?php if (ChannelCredentials::hasSecret($key)): ?>
                                        <span class="field-status detected">hinterlegt</span>
                                    <?php endif; ?>
                                </label>
                                <input class="form-control" type="password" name="client_secret"
                                       placeholder="<?= ChannelCredentials::hasSecret($key) ? 'Leer lassen, um es zu behalten' : '' ?>"
                                       autocomplete="new-password">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Redirect-URI</label>
                                <input class="form-control" type="text" name="redirect_uri"
                                       value="<?= e($stored['redirect_uri'] ?? base_url('api/channels/callback.php?channel=' . $key)) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Berechtigungen (Scopes)</label>
                                <input class="form-control" type="text" name="scopes"
                                       value="<?= e($stored['scopes'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Autorisierungs-URL</label>
                                <input class="form-control" type="text" name="auth_url"
                                       value="<?= e($stored['auth_url'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Token-URL</label>
                                <input class="form-control" type="text" name="token_url"
                                       value="<?= e($stored['token_url'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">API-Basis-URL</label>
                            <input class="form-control" type="text" name="api_url"
                                   value="<?= e($stored['api_url'] ?? '') ?>">
                        </div>
                        <button class="btn btn-primary" type="submit"><?= t('common.save') ?></button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="text-sm text-muted" id="adminChannelNoMatch" style="display:none">Kein Kanal gefunden.</div>

<?php
$pageScripts = <<<HTML
<script>
(function () {
    var search = document.getElementById('adminChannelSearch');
    var cards = document.querySelectorAll('.admin-channel');
    var noMatch = document.getElementById('adminChannelNoMatch');
    search.addEventListener('input', function () {
        var needle = search.value.trim().toLowerCase();
        var hits = 0;
        Array.prototype.forEach.call(cards, function (card) {
            var hit = needle === '' || (card.dataset.search || '').indexOf(needle) !== -1;
            card.style.display = hit ? '' : 'none';
            if (hit) { hits++; }
        });
        noMatch.style.display = hits === 0 ? '' : 'none';
    });
})();
</script>
HTML;
require BASE_PATH . '/includes/layout/dash-footer.php';
?>
