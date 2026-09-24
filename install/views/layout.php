<?php
declare(strict_types=1);

$titles = [
    'welcome' => 'Install Ramza',
    'requirements' => 'Server check',
    'mode' => 'Installation type',
    'database' => 'Ramza database',
    'source' => 'WoWonder source',
    'site' => 'Site',
    'admin' => 'Administrator',
    'install' => 'Final review',
    'finish' => 'Ready',
    'locked' => 'Installer locked',
    'recovery' => 'Configuration found',
];
$descriptions = [
    'welcome' => 'A clean, private setup for your Ramza community.',
    'requirements' => 'Required PHP and server features.',
    'mode' => 'Start fresh or move an existing WoWonder community.',
    'database' => 'Use a new empty database only.',
    'source' => 'Connect to the existing database without changing it.',
    'site' => 'Site identity and open-source setup.',
    'admin' => 'Create the first secure admin account.',
    'install' => 'Confirm before import starts.',
    'finish' => 'Ramza is installed and locked.',
    'locked' => 'Setup already completed.',
    'recovery' => 'Existing configuration is protected.',
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
$siteDefaults = isset($site_defaults) && is_array($site_defaults) ? $site_defaults : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= rac_installer_e($titles[$activeView] ?? 'Ramza setup') ?></title>
    <link rel="stylesheet" href="<?= rac_installer_e($assetBase) ?>/css/installer.css?v=<?= rawurlencode(RACSOCIAL_INSTALLER_VERSION) ?>">
    <script src="<?= rac_installer_e($assetBase) ?>/js/installer.js?v=<?= rawurlencode(RACSOCIAL_INSTALLER_VERSION) ?>" defer></script>
</head>
<body>
<div class="shell">
    <aside class="sidebar" aria-label="Installation progress">
        <a class="brand" href="?step=welcome" aria-label="Ramza installer home">
            <span class="brand-mark" aria-hidden="true"><img src="../themes/ramza-light/img/icon.png" alt=""></span>
            <span><strong>Ramza</strong><small>Secure installer</small></span>
        </a>
        <ol class="progress">
            <?php foreach (['welcome' => 'Welcome', 'requirements' => 'Check', 'mode' => 'Type', 'database' => 'Database', 'source' => 'Source', 'site' => 'Site', 'admin' => 'Admin', 'install' => 'Review', 'finish' => 'Finish'] as $key => $label): ?>
                <?php $position = array_search($key, $steps, true); $current = array_search($activeStep, $steps, true); ?>
                <?php if ($position === false): continue; endif; ?>
                <li class="<?= $position < $current ? 'done' : ($key === $activeStep ? 'active' : '') ?>">
                    <span class="step-dot"><?= $position < $current ? '&check;' : (string) ($position + 1) ?></span>
                    <span><?= rac_installer_e($label) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="security-note">
            <span aria-hidden="true">&#10003;</span>
            <p><strong>Private by design</strong>Secrets are never placed in URLs or installer logs.</p>
        </div>
    </aside>

    <main class="content">
        <div class="content-inner">
            <header class="page-header">
                <span class="eyebrow">Ramza <?= rac_installer_e(RACSOCIAL_INSTALLER_VERSION) ?></span>
                <h1><?= rac_installer_e($titles[$activeView] ?? 'Ramza setup') ?></h1>
                <p><?= rac_installer_e($descriptions[$activeView] ?? '') ?></p>
            </header>

            <?php if (isset($error) && is_string($error) && $error !== ''): ?>
                <div class="alert error" role="alert"><strong>Setup paused</strong><span><?= rac_installer_e($error) ?></span></div>
            <?php endif; ?>

            <?php if ($activeView === 'welcome'): ?>
                <section class="card hero-card">
                    <div class="hero-icon" aria-hidden="true">R</div>
                    <h2>Install or migrate safely</h2>
                    <p>Use a new empty Ramza database. Existing WoWonder data can be copied from a separate read-only source.</p>
                    <div class="feature-grid">
                        <div><strong>PHP 8.2+</strong><span>Supported runtime</span></div>
                        <div><strong>Fresh or migrate</strong><span>Choose after the server check</span></div>
                        <div><strong>Private setup</strong><span>Secrets stay server-side</span></div>
                    </div>
                    <form method="post" class="form-stack">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>">
                        <input type="hidden" name="action" value="accept">
                        <label class="check-row"><input type="checkbox" name="agree" value="yes" required><span>I accept the open-source license and installation terms.</span></label>
                        <button class="button primary" type="submit">Start setup <span aria-hidden="true">&rarr;</span></button>
                    </form>
                </section>
            <?php elseif ($activeView === 'requirements'): ?>
                <?php $requiredRows = $requirements['required'] ?? []; $warningRows = $requirements['warnings'] ?? []; ?>
                <section class="card">
                    <div class="section-heading"><div><h2>Required checks</h2><p>Every required item must pass.</p></div><span class="pill <?= ($requirements['required_pass'] ?? false) ? 'pass' : 'fail' ?>"><?= ($requirements['required_pass'] ?? false) ? 'Ready' : 'Action needed' ?></span></div>
                    <div class="check-list">
                        <?php foreach ($requiredRows as $row): ?>
                            <div class="check-item"><span class="status-icon <?= rac_installer_e($row['status']) ?>"><?= $row['status'] === 'pass' ? '&check;' : '!' ?></span><div><strong><?= rac_installer_e($row['name']) ?></strong><span><?= rac_installer_e($row['message']) ?></span></div></div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php if ($warningRows !== []): ?>
                    <details class="card details-card"><summary>Recommendations <span class="pill warning"><?= count($warningRows) ?></span></summary><div class="check-list compact"><?php foreach ($warningRows as $row): ?><div class="check-item"><span class="status-icon warning">i</span><div><strong><?= rac_installer_e($row['name']) ?></strong><span><?= rac_installer_e($row['message']) ?></span></div></div><?php endforeach; ?></div></details>
                <?php endif; ?>
                <form method="post" class="actions"><input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="requirements"><button class="button primary" type="submit" <?= ($requirements['required_pass'] ?? false) ? '' : 'disabled' ?>>Continue <span aria-hidden="true">&rarr;</span></button></form>
            <?php elseif ($activeView === 'mode'): ?>
                <section class="card">
                    <form method="post" class="form-stack">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>">
                        <input type="hidden" name="action" value="mode">
                        <label class="choice-row">
                            <input type="radio" name="install_mode" value="fresh" <?= $old('install_mode', (string) ($mode ?? 'fresh')) === 'fresh' ? 'checked' : '' ?>>
                            <span><strong>Fresh installation</strong><small>Build a new Ramza community in an empty database.</small></span>
                        </label>
                        <label class="choice-row">
                            <input type="radio" name="install_mode" value="migrate" <?= $old('install_mode', (string) ($mode ?? '')) === 'migrate' ? 'checked' : '' ?>>
                            <span><strong>Migrate from WoWonder</strong><small>Copy the existing database into a separate empty Ramza database. The WoWonder source is never modified.</small></span>
                        </label>
                        <div class="actions"><button class="button primary" type="submit">Continue <span aria-hidden="true">&rarr;</span></button></div>
                    </form>
                </section>
            <?php elseif ($activeView === 'database'): ?>
                <section class="card">
                    <div class="callout"><strong>Empty Ramza target required</strong><span>The installer refuses a target database that already contains tables. It never overwrites an existing database.</span></div>
                    <form method="post" class="form-grid" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="database">
                        <label class="field full"><span>Database host</span><input name="db_host" value="<?= rac_installer_e($old('db_host', 'localhost')) ?>" required maxlength="255" spellcheck="false"><small>Use host:port when a custom port is required.</small></label>
                        <label class="field"><span>Database name</span><input name="db_name" value="<?= rac_installer_e($old('db_name')) ?>" required maxlength="128" spellcheck="false"></label>
                        <label class="field"><span>Database username</span><input name="db_user" value="<?= rac_installer_e($old('db_user')) ?>" required maxlength="128" spellcheck="false" autocomplete="username"></label>
                        <label class="field full"><span>Database password</span><span class="password-wrap"><input type="password" name="db_pass" maxlength="1024" autocomplete="new-password"><button type="button" class="reveal" data-reveal aria-label="Show database password">Show</button></span><small>Leave blank only when your database allows it.</small></label>
                        <div class="actions full"><button class="button primary" type="submit">Test connection <span aria-hidden="true">&rarr;</span></button></div>
                    </form>
                </section>
            <?php elseif ($activeView === 'source'): ?>
                <section class="card">
                    <div class="callout"><strong>Source is read-only</strong><span>Ramza reads a consistent snapshot and writes only to the separate empty target database. Back up WoWonder before migration.</span></div>
                    <form method="post" class="form-grid" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>">
                        <input type="hidden" name="action" value="source">
                        <label class="field full"><span>WoWonder database host</span><input name="source_db_host" value="<?= rac_installer_e($old('source_db_host', 'localhost')) ?>" required maxlength="255" spellcheck="false"></label>
                        <label class="field"><span>WoWonder database name</span><input name="source_db_name" value="<?= rac_installer_e($old('source_db_name')) ?>" required maxlength="128" spellcheck="false"></label>
                        <label class="field"><span>WoWonder database username</span><input name="source_db_user" value="<?= rac_installer_e($old('source_db_user')) ?>" required maxlength="128" spellcheck="false" autocomplete="username"></label>
                        <label class="field full"><span>WoWonder database password</span><span class="password-wrap"><input type="password" name="source_db_pass" maxlength="1024" autocomplete="new-password"><button type="button" class="reveal" data-reveal aria-label="Show source database password">Show</button></span></label>
                        <label class="field full"><span>Old WoWonder path <small>optional</small></span><input name="source_root_path" value="<?= rac_installer_e($old('source_root_path')) ?>" maxlength="4096" spellcheck="false"><small>On the same server, enter the old application root or upload directory to copy local media. Leave blank for remote storage or a different server.</small></label>
                        <label class="check-row full"><input type="checkbox" name="migration_consent" value="yes" required><span>I authorize Ramza to read and copy all users, posts, administrator accounts, settings, and other content from this WoWonder database into the new empty Ramza database.</span></label>
                        <div class="actions full"><button class="button primary" type="submit">Validate source <span aria-hidden="true">&rarr;</span></button></div>
                    </form>
                </section>
            <?php elseif ($activeView === 'site'): ?>
                <section class="card">
                    <form method="post" class="form-grid" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="site">
                        <div class="form-section full"><h2>Site</h2><p>Your public site details.</p></div>
                        <label class="field full"><span>Site URL</span><input type="url" name="site_url" value="<?= rac_installer_e($old('site_url', (string) ($siteDefaults['url'] ?? ((rac_installer_is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'))))) ?>" required maxlength="2048" spellcheck="false"><small>Include http:// or https:// and any subfolder.</small></label>
                        <label class="field"><span>Site name</span><input name="site_name" value="<?= rac_installer_e($old('site_name', (string) ($siteDefaults['name'] ?? 'Ramza'))) ?>" required maxlength="100"></label>
                        <label class="field"><span>Site title</span><input name="site_title" value="<?= rac_installer_e($old('site_title', (string) ($siteDefaults['title'] ?? 'Connect. Share. Belong.'))) ?>" required maxlength="150"></label>
                        <label class="field full"><span>Site email</span><input type="email" name="site_email" value="<?= rac_installer_e($old('site_email', (string) ($siteDefaults['email'] ?? ''))) ?>" required maxlength="254" autocomplete="email"></label>
                        <div class="form-section full divided"><h2>Open-source edition</h2><p>No purchase code or license-server connection is required.</p></div>
                        <div class="actions full"><button class="button primary" type="submit">Continue <span aria-hidden="true">&rarr;</span></button></div>
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
                        <div class="callout full"><strong>Keep this account private</strong><span>The password is hashed and never logged.</span></div>
                        <div class="actions full"><button class="button primary" type="submit">Review <span aria-hidden="true">&rarr;</span></button></div>
                    </form>
                </section>
            <?php elseif ($activeView === 'install'): ?>
                <section class="card review-card">
                    <div class="review-row"><span>Installation</span><strong><?= ($mode ?? '') === 'migrate' ? 'WoWonder migration' : 'Fresh Ramza installation' ?></strong></div>
                    <div class="review-row"><span>Community</span><strong><?= rac_installer_e((string) ($site['name'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Site URL</span><strong><?= rac_installer_e((string) ($site['url'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Site email</span><strong><?= rac_installer_e((string) ($site['email'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Database host</span><strong><?= rac_installer_e((string) ($database['host'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Database name</span><strong><?= rac_installer_e((string) ($database['name'] ?? '')) ?></strong></div>
                    <div class="review-row"><span>Database user</span><strong><?= rac_installer_e((string) ($database['user'] ?? '')) ?></strong></div>
                    <?php if (($mode ?? '') === 'migrate'): ?>
                        <div class="review-row"><span>WoWonder source</span><strong><?= rac_installer_e((string) ($source['name'] ?? '')) ?> &middot; <?= (int) ($source['users'] ?? 0) ?> users &middot; <?= (int) ($source['posts'] ?? 0) ?> posts</strong></div>
                        <div class="review-row"><span>Administration</span><strong><?= (int) ($source['administrators'] ?? 0) ?> administrator(s) &middot; <?= (int) ($source['settings'] ?? 0) ?> settings</strong></div>
                        <div class="review-row"><span>Local uploads</span><strong><?= !empty($source['uses_local_storage']) ? (!empty($source['root_path']) ? 'Copy during migration' : 'Manual copy required') : 'Remote storage configured' ?></strong></div>
                    <?php else: ?>
                        <div class="review-row"><span>Administrator</span><strong><?= rac_installer_e((string) ($admin['username'] ?? '')) ?> &middot; <?= rac_installer_e((string) ($admin['email'] ?? '')) ?></strong></div>
                    <?php endif; ?>
                    <div class="review-row"><span>Requirements</span><strong><?= ($requirements['required_pass'] ?? false) ? 'Passed' : 'Failed' ?></strong></div>
                    <div class="review-row"><span>License</span><strong>Open-Source (Community Edition)</strong></div>
                    <div class="callout"><strong><?= ($mode ?? '') === 'migrate' ? 'Database copy starts next' : 'Database import starts next' ?></strong><span>If the operation fails, discard the partial target database and retry with another empty database. The WoWonder source remains unchanged.</span></div>
                    <form method="post" class="actions" data-install-form><input type="hidden" name="csrf" value="<?= rac_installer_e($csrf) ?>"><input type="hidden" name="action" value="install"><div class="install-live" data-install-live role="status" aria-live="polite"></div><button class="button primary" type="submit">Install Ramza</button></form>
                </section>
            <?php elseif ($activeView === 'finish'): ?>
                <section class="card finish-card">
                    <div class="success-mark">&check;</div>
                    <h2>Your community is ready</h2>
                    <p><?= (int) ($summary['tables'] ?? 0) ?> database tables were verified and the installer is now locked.</p>
                    <?php if (($summary['mode'] ?? '') === 'migrate'): ?>
                        <div class="review-row"><span>Copied database rows</span><strong><?= number_format((int) ($summary['rows'] ?? 0)) ?></strong></div>
                        <?php $uploadSummary = isset($summary['upload']) && is_array($summary['upload']) ? $summary['upload'] : []; ?>
                        <?php if (!empty($uploadSummary['required']) && empty($uploadSummary['copied'])): ?>
                            <div class="callout"><strong>Copy local media before opening traffic</strong><span>Copy the contents of the old WoWonder upload directory into the new Ramza upload directory, preserving every subdirectory and filename.</span></div>
                        <?php elseif (!empty($uploadSummary['copied'])): ?>
                            <div class="review-row"><span>Local media copied</span><strong><?= number_format((int) ($uploadSummary['files'] ?? 0)) ?> files</strong></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="callout"><strong>Next step</strong><span>Remove or restrict the install directory, then sign in with the administrator account.</span></div>
                    <a class="button primary" href="../">Open Ramza <span aria-hidden="true">&rarr;</span></a>
                </section>
            <?php elseif ($activeView === 'locked'): ?>
                <section class="card finish-card"><div class="lock-mark">&bull;</div><h2>Setup is safely locked</h2><p>Ramza has already completed installation. This installer will not run again while the installation lock exists.</p><a class="button primary" href="../">Return to Ramza <span aria-hidden="true">&rarr;</span></a></section>
            <?php elseif ($activeView === 'recovery'): ?>
                <section class="card finish-card"><div class="lock-mark">&bull;</div><h2>Existing configuration protected</h2><p>A populated PHP or Node.js configuration already exists. Setup is blocked even without an install lock.</p><div class="callout"><strong>Owner recovery</strong><span>Back up the site, verify its database and current configuration, then restore the missing install lock manually or use a separate clean package and empty database.</span></div><a class="button primary" href="../">Return to Ramza <span aria-hidden="true">&rarr;</span></a></section>
            <?php endif; ?>
        </div>
        <footer>Ramza secure installer &middot; PHP 8.2+</footer>
    </main>
</div>
</body>
</html>
