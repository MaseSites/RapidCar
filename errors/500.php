<?php
http_response_code(500);
$errorCode = '500';
$errorTitle = 'Technischer Fehler';
$errorText = 'Es ist ein unerwarteter Fehler aufgetreten. Das Team wurde informiert. Bitte versuche es später erneut.';
require __DIR__ . '/error-layout.php';
