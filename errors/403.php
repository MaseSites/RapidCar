<?php
http_response_code(403);
$errorCode = '403';
$errorTitle = 'Keine Berechtigung';
$errorText = 'Du hast keine Berechtigung, auf diese Seite zuzugreifen.';
require __DIR__ . '/error-layout.php';
