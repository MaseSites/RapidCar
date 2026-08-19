<?php
http_response_code(503);
header('Retry-After: 120');
$errorCode = '503';
$errorTitle = 'Datenbank nicht erreichbar';
$errorText = 'Die Anwendung erreicht ihre Datenbank gerade nicht. Das ist ein Zustand des Servers, kein Fehler deiner Eingabe. Bitte versuche es in ein paar Minuten erneut.';
require __DIR__ . '/error-layout.php';
