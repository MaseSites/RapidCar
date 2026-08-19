<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Datenschutzerklärung';
require BASE_PATH . '/includes/layout/public-header.php';
?>
<div class="content-page">
    <h1>Datenschutzerklärung</h1>
    <p><em>Hinweis für den Betreiber: Dieser Text ist eine Vorlage und muss vor dem Produktivbetrieb durch eine rechtlich geprüfte Datenschutzerklärung ersetzt werden (Schweizer DSG bzw. DSGVO, je nach Zielmarkt).</em></p>

    <h2>1. Verantwortliche Stelle</h2>
    <p>Verantwortlich für die Datenbearbeitung im Rahmen dieser Plattform ist der im Impressum genannte Betreiber.</p>

    <h2>2. Bearbeitete Daten</h2>
    <ul>
        <li>Kontodaten (Name, E-Mail, Telefonnummer, Autohaus)</li>
        <li>Fahrzeugdaten und Fahrzeugbilder</li>
        <li>Kundenanfragen (Leads) inklusive Nachrichteninhalten</li>
        <li>Technische Protokolldaten (IP-Adresse, Zeitstempel) zur Betriebssicherheit</li>
    </ul>

    <h2>3. Zweck der Bearbeitung</h2>
    <p>Die Daten werden ausschliesslich zum Betrieb der Plattform bearbeitet: Verwaltung von Fahrzeug-Inseraten, Bewertung der Inseratsqualität, Erstellung von Social-Media-Inhalten und Verwaltung von Kundenanfragen.</p>

    <h2>4. Speicherung und Sicherheit</h2>
    <p>Passwörter werden ausschliesslich als sichere Hashes gespeichert. Zugangs-Tokens zu Drittplattformen werden verschlüsselt abgelegt. Der Zugriff auf Daten ist rollenbasiert beschränkt.</p>

    <h2>5. Rechte der betroffenen Personen</h2>
    <p>Nutzer haben das Recht auf Auskunft, Berichtigung, Löschung und Datenexport. Entsprechende Funktionen stehen in den Kontoeinstellungen zur Verfügung oder können über den Kontakt angefordert werden.</p>

    <h2>6. Aufbewahrung</h2>
    <p>Daten werden gelöscht, sobald sie für den Betrieb nicht mehr erforderlich sind und keine gesetzlichen Aufbewahrungspflichten bestehen.</p>
</div>
<?php require BASE_PATH . '/includes/layout/public-footer.php'; ?>
