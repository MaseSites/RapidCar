<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Über uns';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="content-page">
    <h1>Über uns</h1>
    <p>RapidCar wurde mit einem Ziel entwickelt: Autohäusern die tägliche Arbeit rund um Fahrzeug-Inserate radikal zu vereinfachen.</p>
    <p>Ein Fahrzeug wird bei uns nur einmal erfasst. Danach übernimmt die Plattform so viel wie möglich: von der Aufbereitung der Bilder über die Qualitätsbewertung des Inserats bis zum fertigen Social-Media-Post und der Verwaltung eingehender Kundenanfragen.</p>

    <h2>Unser Prinzip: Kontrolle bleibt beim Händler</h2>
    <p>Jede Automatisierung bei RapidCar kennt drei Zustände: automatisch, Vorschlag oder manuell. Die Software entscheidet nie eigenmächtig über Preise, Rabatte oder Zusagen. Das bleibt immer die Entscheidung des Händlers.</p>

    <h2>Transparenz</h2>
    <p>Wir täuschen keine Funktionen vor. Wenn ein KI-Modul im Demo-Modus läuft oder eine Integration noch nicht verbunden ist, zeigt die Plattform das klar an.</p>

    <h2>Kontakt</h2>
    <p>Fragen? <a href="<?= base_url('contact.php') ?>">Schreib uns</a>, wir freuen uns auf den Austausch.</p>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
