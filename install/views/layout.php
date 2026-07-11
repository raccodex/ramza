<?php
declare(strict_types=1);

$titles = [
    'welcome' => 'Welcome to RACSocial',
    'requirements' => 'Server readiness',
    'database' => 'Connect a fresh database',
    'site' => 'Create your community',
    'admin' => 'Create the first administrator',
    'install' => 'Review and install',
    'finish' => 'Installation complete',
    'locked' => 'RACSocial is installed',
    'recovery' => 'Existing configuration protected',
];
$descriptions = [
    'welcome' => 'A guided, secure setup for your RACSocial community.',
    'requirements' => 'We checked the services RACSocial needs before anything is changed.',
    'database' => 'Use an empty database created specifically for this installation.',
    'site' => 'Set the public identity and verify this installation license.',
    'admin' => 'Create the secure account that will manage RACSocial.',
    'install' => 'Confirm the destination before the database is imported.',
    'finish' => 'Your community is ready for its first sign-in.',
    'locked' => 'The installation lock is active, so setup cannot run again.',
    'recovery' => 'Setup found active configuration and will not overwrite it.',
];
$activeView = isset($view) && is_string($view) ? $view : 'welcome';
$activeStep = isset($step) && is_string($step) ? $step : 'welcome';
$scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')));
$assetBase = rtrim($scriptDirectory === '/' ? '' : $scriptDirectory, '/') . '/assets';
$old = static function (string $key, string $fallback = ''): string {
    if (isset($_POST[$key]) && is_string($_POST[$key])) {
        return $_POST[$key];
    }
    return $fallback;
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= rac_installer_e($titles[$activeView] ?? 'RACSocial setup') ?></title>
    <link rel="stylesheet" href="<?= rac_installer_e($assetBase) ?>/css/installer.css?v=<?= rawurlencode(RACSOCIAL_INSTALLER_VERSION) ?>">
    <script src="<?= rac_installer_e($assetBase) ?>/js/installer.js?v=<?= rawurlencode(RACSOCIAL_INSTALLER_VERSION) ?>" defer></script>
</head>
<body>
<div class="shell">
    <aside class="sidebar" aria-label="Installation progress">
        <a class="brand" href="?step=welcome" aria-label="RACSocial installer home">
            <span class="brand-mark" aria-hidden="true">R</span>
            <span><strong>RACSocial</strong><small>Community setup</small></span>
        </a>
        <ol class="progress">
            <?php foreach (['welcome' => 'Welcome', 'requirements' => 'Readiness', 'database' => 'Database', 'site' => 'Community', 'admin' => 'Administrator', 'install' => 'Review', 'finish' => 'Finish'] as $key => $label): ?>
                <?php $position = array_search($key, $steps, true); $current = array_search($activeStep, $steps, true); ?>
                <li class="<?= $position < $current ? 'done' : ($key === $activeStep ? 'active' : '') ?>">
                    <span class="step-dot"><?= $position < $current ? '✓' : (string) ($position + 1) ?></span>
                    <span><?= rac_installer_e($label) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="security-note">
            <span aria-hidden="true">◆</span>
            <p><strong>Private by design</strong>Credentials stay on the server and never appear in the URL.</p>
        </div>
    </aside>

    <main class="content">
        <div class="content-inner">
            <header class="page-header">
                <span class="eyebrow">RACSocial <?= rac_installer_e(RACSOCIAL_INSTALLER_VERSION) ?></span>
                <h1><?= rac_installer_e($titles[$activeView] ?? 'RACSocial setup') ?></h1>
                <p><?= rac_installer_e($descriptions[$activeView] ?? '') ?></p>
            </header>

            <?php if (isset($error) && is_string($error) && $error !== ''): ?>
                <div class="alert error" role="alert"><strong>Setup paused</strong><span><?= rac_installer_e($error) ?></span></div>
            <?php endif; ?>

            <?php if ($activeView === 'welcome'): ?>
                <section class="card hero-card">
                    <div class="hero-icon" aria-hidden="true">✦</div>
                    <h2>Build a home for your community</h2>
                    <p>This setup checks your server, verifies an empty database, creates a secure administrator account, and locks itself when complete.</p>
                    <div class="feature-grid">
                        <div><strong>PHP 8.2+</strong><span>Modern supported runtime</span></div>
                        <div><strong>Safe database check</strong><span>No existing tables are dropped</span></div>
                        <div><strong>Secure credentials</strong><span>Passwords are hashed, never logged</span></div>
                    </div>
                    <form method="post" class="form-stack">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>">
                        <input type="hidden" name="action" value="accept">
                        <label class="check-row"><input type="checkbox" name="agree" value="yes" required><span>I accept the applicable license and confirm that I am authorized to install this copy of RACSocial.</span></label>
                        <button class="button primary" type="submit">Start setup <span aria-hidden="true">→</span></button>
                    </form>
                </section>
            <?php elseif ($activeView === 'requirements'): ?>
                <?php $requiredRows = $requirements['required'] ?? []; $warningRows = $requirements['warnings'] ?? []; ?>
                <section class="card">
                    <div class="section-heading"><div><h2>Required checks</h2><p>Every required item must be ready.</p></div><span class="pill <?= ($requirements['required_pass'] ?? false) ? 'pass' : 'fail' ?>"><?= ($requirements['required_pass'] ?? false) ? 'Ready' : 'Action needed' ?></span></div>
                    <div class="check-list">
                        <?php foreach ($requiredRows as $row): ?>
                            <div class="check-item"><span class="status-icon <?= rac_installer_e($row['status']) ?>"><?= $row['status'] === 'pass' ? '✓' : '!' ?></span><div><strong><?= rac_installer_e($row['name']) ?></strong><span><?= rac_installer_e($row['message']) ?></span></div></div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php if ($warningRows !== []): ?>
                    <details class="card details-card"><summary>Recommendations <span class="pill warning"><?= count($warningRows) ?></span></summary><div class="check-list compact"><?php foreach ($warningRows as $row): ?><div class="check-item"><span class="status-icon warning">i</span><div><strong><?= rac_installer_e($row['name']) ?></strong><span><?= rac_installer_e($row['message']) ?></span></div></div><?php endforeach; ?></div></details>
                <?php endif; ?>
                <form method="post" class="actions"><input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="requirements"><button class="button primary" type="submit" <?= ($requirements['required_pass'] ?? false) ? '' : 'disabled' ?>>Continue to database <span aria-hidden="true">→</span></button></form>
            <?php elseif ($activeView === 'database'): ?>
                <section class="card">
                    <div class="callout"><strong>Fresh database required</strong><span>The installer refuses any database that already contains tables. It never drops existing tables.</span></div>
                    <form method="post" class="form-grid" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="database">
                        <label class="field full"><span>Database host</span><input name="db_host" value="<?= rac_installer_e($old('db_host', 'localhost')) ?>" required maxlength="255" spellcheck="false"><small>Use host:port when a custom port is required.</small></label>
                        <label class="field"><span>Database name</span><input name="db_name" value="<?= rac_installer_e($old('db_name')) ?>" required maxlength="128" spellcheck="false"></label>
                        <label class="field"><span>Database username</span><input name="db_user" value="<?= rac_installer_e($old('db_user')) ?>" required maxlength="128" spellcheck="false" autocomplete="username"></label>
                        <label class="field full"><span>Database password</span><span class="password-wrap"><input type="password" name="db_pass" maxlength="1024" autocomplete="new-password"><button type="button" class="reveal" data-reveal aria-label="Show database password">Show</button></span><small>An empty password is allowed only when your local database is configured that way.</small></label>
                        <div class="actions full"><button class="button primary" type="submit">Test safe connection <span aria-hidden="true">→</span></button></div>
                    </form>
                </section>
            <?php elseif ($activeView === 'site'): ?>
                <section class="card">
                    <form method="post" class="form-grid" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="site">
                        <div class="form-section full"><h2>Community details</h2><p>These appear across your new site.</p></div>
                        <label class="field full"><span>Site URL</span><input type="url" name="site_url" value="<?= rac_installer_e($old('site_url', (rac_installer_is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'))) ?>" required maxlength="2048" spellcheck="false"><small>Include http:// or https:// and any subfolder.</small></label>
                        <label class="field"><span>Site name</span><input name="site_name" value="<?= rac_installer_e($old('site_name', 'RACSocial')) ?>" required maxlength="100"></label>
                        <label class="field"><span>Site title</span><input name="site_title" value="<?= rac_installer_e($old('site_title', 'Connect. Share. Belong.')) ?>" required maxlength="150"></label>
                        <label class="field full"><span>Site email</span><input type="email" name="site_email" value="<?= rac_installer_e($old('site_email')) ?>" required maxlength="254" autocomplete="email"></label>
                        <div class="form-section full divided"><h2>License verification</h2><p>Your code is sent only in the secure request body and is never written to the browser URL or installer log.</p></div>
                        <label class="field full"><span>Purchase code</span><input type="password" name="purchase_code" required maxlength="512" autocomplete="off" spellcheck="false"></label>
                        <div class="actions full"><button class="button primary" type="submit">Verify and continue <span aria-hidden="true">→</span></button></div>
                    </form>
                </section>
            <?php elseif ($activeView === 'admin'): ?>
                <section class="card">
                    <form method="post" class="form-grid" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="admin">
                        <label class="field"><span>Admin username</span><input name="admin_username" value="<?= rac_installer_e($old('admin_username')) ?>" required minlength="5" maxlength="32" pattern="[A-Za-z0-9_]+" autocomplete="username"></label>
                        <label class="field"><span>Admin email</span><input type="email" name="admin_email" value="<?= rac_installer_e($old('admin_email', (string) ($site_email ?? ''))) ?>" required maxlength="254" autocomplete="email"></label>
                        <label class="field"><span>Admin password</span><span class="password-wrap"><input type="password" name="admin_password" required minlength="12" maxlength="128" autocomplete="new-password" data-password-strength><button type="button" class="reveal" data-reveal aria-label="Show administrator password">Show</button></span><span class="strength" data-strength role="status" aria-live="polite">Use 12+ characters with letters and numbers.</span></label>
                        <label class="field"><span>Confirm password</span><span class="password-wrap"><input type="password" name="admin_password_confirmation" required minlength="12" maxlength="128" autocomplete="new-password"><button type="button" class="reveal" data-reveal aria-label="Show password confirmation">Show</button></span></label>
                        <div class="callout full"><strong>Keep this account private</strong><span>The password is hashed with PHP's current secure default and is never placed in installer logs, URLs, or JavaScript.</span></div>
                        <div class="actions full"><button class="button primary" type="submit">Review installation <span aria-hidden="true">→</span></button></div>
                    </form>
                </section>
            <?php elseif ($activeView === 'install'): ?>
                <section class="card review-card">
                    <div class="review-row"><span>Community</span><strong><?= rac_installer_e((string) ($site['name'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Site URL</span><strong><?= rac_installer_e((string) ($site['url'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Site email</span><strong><?= rac_installer_e((string) ($site['email'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Database host</span><strong><?= rac_installer_e((string) ($database['host'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Database name</span><strong><?= rac_installer_e((string) ($database['name'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Database user</span><strong><?= rac_installer_e((string) ($database['user'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Administrator</span><strong><?= rac_installer_e((string) ($admin['username'] ?? '')) ?> · <?= rac_installer_e((string) ($admin['email'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Requirements</span><strong><?= ($requirements['required_pass'] ?? false) ? 'All required checks passed' : 'Required check failed' ?></strong></div>
                    <div class="review-row"><span>License</span><strong>Verified for this session</strong></div>
                    <div class="callout"><strong>Installation changes this fresh database</strong><span>If import fails, discard the partial database and retry with a new empty database. Existing databases are never accepted.</span></div>
                    <form method="post" class="actions" data-install-form><input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="install"><div class="install-live" data-install-live role="status" aria-live="polite"></div><button class="button primary" type="submit">Confirm and install RACSocial</button></form>
                </section>
            <?php elseif ($activeView === 'finish'): ?>
                <section class="card finish-card"><div class="success-mark">✓</div><h2>Your community is ready</h2><p><?= (int) ($summary['tables'] ?? 0) ?> database tables were verified and the installer is now locked.</p><div class="callout"><strong>Recommended next step</strong><span>Remove or restrict the install directory at the web-server level, then sign in with the administrator account you created.</span></div><a class="button primary" href="../">Open RACSocial <span aria-hidden="true">→</span></a></section>
            <?php elseif ($activeView === 'locked'): ?>
                <section class="card finish-card"><div class="lock-mark">◆</div><h2>Setup is safely locked</h2><p>RACSocial has already completed installation. This installer will not run again while the installation lock exists.</p><a class="button primary" href="../">Return to RACSocial <span aria-hidden="true">→</span></a></section>
            <?php elseif ($activeView === 'recovery'): ?>
                <section class="card finish-card"><div class="lock-mark">◆</div><h2>Existing configuration protected</h2><p>A populated PHP or Node.js configuration already exists. Setup is blocked even without an install lock, and no query-string override is available.</p><div class="callout"><strong>Owner recovery</strong><span>Back up the site, verify its database and current configuration, then restore the missing install lock manually or use a separate clean package and empty database.</span></div><a class="button primary" href="../">Return to RACSocial <span aria-hidden="true">→</span></a></section>
            <?php endif; ?>
        </div>
        <footer>RACSocial secure installer · PHP 8.2+</footer>
    </main>
</div>
</body>
</html>
