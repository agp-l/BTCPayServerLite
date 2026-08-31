<?php

declare(strict_types=1);

$errorStatus = isset($errorStatus) && is_int($errorStatus) ? $errorStatus : 500;
$errorTitle = isset($errorTitle) && is_string($errorTitle) ? $errorTitle : 'Chyba aplikace';
$errorMessage = isset($errorMessage) && is_string($errorMessage)
    ? $errorMessage
    : 'Požadavek nyní nelze dokončit.';
$homeUrl = isset($homeUrl) && is_string($homeUrl) ? $homeUrl : '/';
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars((string) $errorStatus . ' – ' . $errorTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root { color-scheme: light; font-family: Inter, system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #fafcfa; color: #17201a; }
        main { width: min(560px, calc(100% - 40px)); padding: 44px; border: 1px solid #e5eae7; border-radius: 20px; background: #fff; box-shadow: 0 18px 55px rgba(20,45,28,.08); }
        .status { color: #20b948; font-size: 13px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 10px 0 12px; font-size: clamp(30px, 7vw, 48px); letter-spacing: -.04em; }
        p { margin: 0 0 28px; color: #66736a; line-height: 1.65; }
        a { display: inline-block; padding: 12px 18px; border-radius: 10px; background: #2fd35a; color: #fff; font-weight: 750; text-decoration: none; }
        a:hover { background: #20b948; }
    </style>
</head>
<body>
<main>
    <div class="status">HTTP <?php echo $errorStatus; ?></div>
    <h1><?php echo htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>">Zpět na hlavní stránku</a>
</main>
</body>
</html>
