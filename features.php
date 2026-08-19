<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Funktionen';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<section class="section">
    <h1 class="section-title">Funktionen</h1>
    <p class="section-sub">RapidCar begleitet dein Autohaus vom ersten Foto bis zur Kundenanfrage.</p>

    <div class="grid-2">
        <div class="card card-pad">
            <h3 class="mb-1">KI-Fahrzeugerkennung</h3>
            <p class="text-secondary">Fotos hochladen, RapidCar bereitet Marke, Modell und Fahrzeugdaten für dein Inserat vor. Jeder Wert bleibt überschreibbar: Du behältst die Kontrolle.</p>
        </div>
        <div class="card card-pad">
            <h3 class="mb-1">KI-Bildoptimierung</h3>
            <p class="text-secondary">Bilder verbessern, Hauptbild wählen und professionelle Darstellungen für Inserate und Social Media vorbereiten.</p>
        </div>
        <div class="card card-pad">
            <h3 class="mb-1">Inserat-Generator</h3>
            <p class="text-secondary">Titel und Beschreibung werden aus den Fahrzeugdaten vorbereitet, inklusive Ausstattungs-Highlights.</p>
        </div>
        <div class="card card-pad">
            <h3 class="mb-1">Inserat-Score</h3>
            <p class="text-secondary">Fotos, Titel, Beschreibung, Preis und Datenqualität werden bewertet, bevor du veröffentlichst, mit konkreten Verbesserungsvorschlägen.</p>
        </div>
        <div class="card card-pad">
            <h3 class="mb-1">Preis-Analyse</h3>
            <p class="text-secondary">Dein Preis wird gegen vergleichbare Fahrzeuge geprüft. Sind zu wenige Vergleichsdaten vorhanden, sagen wir dir das ehrlich.</p>
        </div>
        <div class="card card-pad">
            <h3 class="mb-1">Social-Media-Generator</h3>
            <p class="text-secondary">Fünf professionelle Vorlagen (Luxury, Minimal, Sport, Modern, Classic) machen aus jedem Fahrzeug einen fertigen Instagram-Post mit deinem Logo.</p>
        </div>
        <div class="card card-pad">
            <h3 class="mb-1">Lead-Management</h3>
            <p class="text-secondary">Alle Kundenanfragen zentral, mit Nachrichtenverlauf, Status und KI-Antwortassistent. Preise, Rabatte und Zusagen bleiben immer Händler-Entscheidung.</p>
        </div>
        <div class="card card-pad">
            <h3 class="mb-1">Plattform-Anbindung</h3>
            <p class="text-secondary">Die AutoScout24-Integrationsschicht ist vorbereitet. Sobald deine Händler-Zugangsdaten hinterlegt sind, verwaltest du Inserate direkt aus RapidCar.</p>
        </div>
    </div>

    <div class="text-center mt-4">
        <a class="btn btn-accent btn-lg" href="<?= base_url('register.php') ?>">Jetzt kostenlos starten</a>
    </div>
</section>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
