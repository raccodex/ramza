<?php
require_once('assets/init.php');

$siteUrl = rtrim($wo['config']['site_url'], '/');
$appName = trim((string)($wo['config']['pwa_app_name'] ?? $wo['config']['siteName'] ?? 'Ramza'));
$title = trim((string)($wo['config']['pwa_offline_title'] ?? 'You are offline'));
$message = trim((string)($wo['config']['pwa_offline_text'] ?? 'Ramza saved the app shell. Reconnect to refresh your feed and messages.'));
$themeColor = trim((string)($wo['config']['pwa_theme_color'] ?? '#c94b57'));
$backgroundColor = trim((string)($wo['config']['pwa_background_color'] ?? '#f6f7f9'));

if (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) {
    $themeColor = '#c94b57';
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $backgroundColor)) {
    $backgroundColor = '#f6f7f9';
}

$iconUrl = !empty($wo['config']['pwa_icon_192']) ? Wo_GetMedia($wo['config']['pwa_icon_192']) : $wo['config']['theme_url'] . '/img/icon.png';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?php echo htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        :root {
            --ramza-pwa-theme: <?php echo htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8'); ?>;
            --ramza-pwa-bg: <?php echo htmlspecialchars($backgroundColor, ENT_QUOTES, 'UTF-8'); ?>;
            --ramza-pwa-text: #111827;
            --ramza-pwa-muted: #667085;
            --ramza-pwa-line: rgba(148, 163, 184, 0.34);
        }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: var(--ramza-pwa-bg);
            color: var(--ramza-pwa-text);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }
        .ramza-offline {
            width: min(100%, 460px);
            padding: 28px;
            border: 1px solid var(--ramza-pwa-line);
            border-radius: 28px;
            background: #ffffff;
            box-shadow: 0 22px 70px rgba(15, 23, 42, 0.12);
            text-align: center;
        }
        .ramza-offline img {
            width: 74px;
            height: 74px;
            border-radius: 22px;
            object-fit: cover;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
        }
        .ramza-offline small {
            display: block;
            margin-top: 16px;
            color: var(--ramza-pwa-muted);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .ramza-offline h1 {
            margin: 10px 0 8px;
            font-size: clamp(26px, 6vw, 34px);
            letter-spacing: -0.045em;
            line-height: 1.05;
        }
        .ramza-offline p {
            margin: 0 auto 22px;
            max-width: 340px;
            color: var(--ramza-pwa-muted);
            font-size: 15px;
            line-height: 1.55;
        }
        .ramza-offline button,
        .ramza-offline a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 18px;
            border: 0;
            border-radius: 999px;
            background: var(--ramza-pwa-theme);
            color: #ffffff;
            cursor: pointer;
            font-size: 14px;
            font-weight: 750;
            text-decoration: none;
        }
        .ramza-offline a {
            margin-left: 8px;
            background: #111827;
        }
        @media (max-width: 520px) {
            .ramza-offline { padding: 24px 18px; border-radius: 22px; }
            .ramza-offline button,
            .ramza-offline a { width: 100%; margin: 8px 0 0; }
        }
    </style>
</head>
<body>
    <main class="ramza-offline">
        <img src="<?php echo htmlspecialchars($iconUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?>">
        <small><?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></small>
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
        <button type="button" onclick="window.location.reload()">Try again</button>
        <a href="<?php echo htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8'); ?>">Open home</a>
    </main>
</body>
</html>
