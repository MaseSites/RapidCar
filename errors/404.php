<?php
http_response_code(404);
$errorCode = '404';
$errorTitle = 'Seite nicht gefunden';
$errorText = 'Die angeforderte Seite existiert nicht oder wurde verschoben.';
require __DIR__ . '/error-layout.php';
