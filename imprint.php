<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Impressum';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="content-page">
    <h1>Impressum</h1>
    <p><em>Hinweis für den Betreiber: Bitte vor dem Produktivbetrieb mit den echten Angaben ergänzen.</em></p>

    <h2>Betreiber</h2>
    <p>[Firmenname]<br>[Strasse Nr.]<br>[PLZ Ort]<br>Schweiz</p>

    <h2>Kontakt</h2>
    <p>E-Mail: [E-Mail-Adresse]<br>Telefon: [Telefonnummer]</p>

    <h2>Handelsregister</h2>
    <p>[UID / Handelsregister-Nummer]</p>

    <h2>Haftungsausschluss</h2>
    <p>Der Betreiber übernimmt keine Gewähr für die Richtigkeit, Vollständigkeit und Aktualität der bereitgestellten Inhalte. Für Inhalte von Fahrzeug-Inseraten sind die jeweiligen Händler verantwortlich.</p>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
