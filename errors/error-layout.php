<?php
/**
 * Gemeinsames Layout der Fehlerseiten. Erwartet: $errorCode, $errorTitle, $errorText.
 * Bewusst ohne Abhängigkeiten zu bootstrap — muss auch bei Teilausfällen funktionieren.
 */
$errorCode = $errorCode ?? '500';
$errorTitle = $errorTitle ?? 'Technischer Fehler';
$errorText = $errorText ?? 'Bitte versuche es später erneut.';
$homeHref = '/';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($errorCode) ?> | RapidCar</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
        background: #f6f7f9; color: #1a1d23;
        min-height: 100vh; display: flex; align-items: center; justify-content: center;
        padding: 24px;
    }
    .error-card {
        background: #fff; border-radius: 20px; padding: 56px 48px;
        box-shadow: 0 8px 40px rgba(16, 24, 40, .08);
        text-align: center; max-width: 480px; width: 100%;
    }
    .error-code {
        font-size: 64px; font-weight: 800; letter-spacing: -2px;
        color: #1d4fd7;
    }
    h1 { font-size: 22px; margin: 12px 0 8px; }
    p { color: #5b6472; line-height: 1.6; margin-bottom: 28px; }
    a.btn {
        display: inline-block; background: #1a1d23; color: #fff;
        padding: 12px 28px; border-radius: 10px; text-decoration: none;
        font-weight: 600; font-size: 15px;
    }
    a.btn:hover { background: #2d323c; }
</style>
</head>
<body>
    <div class="error-card">
        <div class="error-code"><?= htmlspecialchars($errorCode) ?></div>
        <h1><?= htmlspecialchars($errorTitle) ?></h1>
        <p><?= htmlspecialchars($errorText) ?></p>
        <a class="btn" href="<?= htmlspecialchars($homeHref) ?>">Zur Startseite</a>
    </div>
</body>
</html>
