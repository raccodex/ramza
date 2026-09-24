<?php
declare(strict_types=1);

final class RACInstallerApp
{
    private RACInstallerPaths $paths;
    private RACInstallerLogger $logger;
    private RACInstallerSession $session;
    private RACInstallerCsrf $csrf;
    private RACInstallerRequirements $requirements;
    private RACInstallerInstallLock $lock;
    private array $steps = ['welcome', 'requirements', 'mode', 'database', 'source', 'site', 'admin', 'install', 'finish'];

    public function __construct()
    {
        $this->paths = new RACInstallerPaths();
        $this->logger = new RACInstallerLogger($this->paths);
        $this->session = new RACInstallerSession();
        $this->session->start();
        $this->csrf = new RACInstallerCsrf($this->session);
        $this->requirements = new RACInstallerRequirements($this->paths);
        $this->lock = new RACInstallerInstallLock($this->paths);
    }

    public function handle(): void
    {
        if ($this->lock->exists()) {
            $this->render('locked', ['step' => 'finish']);
            return;
        }
        if (!RACInstallerConfigWriter::canWriteFreshConfig($this->paths->configFile)
            || !RACInstallerNodeConfigWriter::canWriteFreshNodeConfig($this->paths->nodeConfigFile)) {
            if ($this->restoreMissingLockForValidInstall()) {
                $this->render('locked', ['step' => 'finish']);
                return;
            }
            $this->render('recovery', ['step' => 'welcome']);
            return;
        }

        $requested = isset($_GET['step']) && is_string($_GET['step']) ? $_GET['step'] : 'welcome';
        $step = in_array($requested, $this->steps, true) ? $requested : 'welcome';
        if (!$this->isAccessible($step)) {
            $step = (string) $this->session->get('max_step', 'welcome');
        }

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->csrf->validate(rac_installer_post_string('csrf', 128));
                $action = rac_installer_post_string('action', 64) ?? '';
                $this->handlePost($action);
                return;
            }
            $this->render($step, $this->viewData($step));
        } catch (RACInstallerUserException $error) {
            $this->render($step, array_merge($this->viewData($step), ['error' => $error->getMessage()]));
        } catch (Throwable $error) {
            $reference = $this->logger->error('installer-request', $error, ['step' => $step]);
            $this->render($step, array_merge($this->viewData($step), [
                'error' => 'The installer encountered an internal error. No secret values were logged. Reference: ' . $reference,
            ]));
        }
    }

    private function handlePost(string $action): void
    {
        if ($action === 'accept') {
            if (rac_installer_post_string('agree', 8) !== 'yes') {
                throw new RACInstallerUserException('Accept the license and installation terms to continue.');
            }
            $this->advance('requirements');
            rac_installer_redirect('requirements');
        }
        if ($action === 'requirements') {
            $result = $this->requirements->evaluate();
            if (!$result['required_pass']) {
                throw new RACInstallerUserException('Resolve every required item before continuing.');
            }
            $this->advance('mode');
            rac_installer_redirect('mode');
        }
        if ($action === 'mode') {
            $mode = rac_installer_post_string('install_mode', 16) ?? '';
            if (!in_array($mode, ['fresh', 'migrate'], true)) {
                throw new RACInstallerUserException('Choose a fresh installation or a WoWonder migration.');
            }
            $this->session->set('install_mode', $mode);
            $this->session->forget('database');
            $this->session->forget('source_database');
            $this->session->forget('site');
            $this->session->forget('admin');
            $this->session->set('max_step', 'database');
            $this->session->regenerate();
            rac_installer_redirect('database');
        }
        if ($action === 'database') {
            $mode = (string) $this->session->get('install_mode', '');
            if (!in_array($mode, ['fresh', 'migrate'], true)) {
                throw new RACInstallerUserException('Choose an installation type first.');
            }
            $validator = new RACInstallerDatabaseValidator($this->logger);
            $database = $validator->validate([
                'host' => rac_installer_post_string('db_host', 255) ?? '',
                'name' => rac_installer_post_string('db_name', 128) ?? '',
                'user' => rac_installer_post_string('db_user', 128) ?? '',
                'pass' => rac_installer_post_string('db_pass', 1024) ?? '',
            ]);
            $this->session->set('database', $database);
            $this->session->regenerate();
            $next = $mode === 'migrate' ? 'source' : 'site';
            $this->advance($next);
            rac_installer_redirect($next);
        }
        if ($action === 'source') {
            if ((string) $this->session->get('install_mode', '') !== 'migrate') {
                throw new RACInstallerUserException('WoWonder source settings are available only in migration mode.');
            }
            if (!isset($_POST['migration_consent']) || !is_string($_POST['migration_consent']) || $_POST['migration_consent'] !== 'yes') {
                throw new RACInstallerUserException('Confirm that you authorize Ramza to read and copy the selected WoWonder community.');
            }
            $database = $this->session->get('database');
            if (!is_array($database)) {
                throw new RACInstallerUserException('Validate the new empty Ramza database first.');
            }
            $source = (new RACInstallerWoWonderMigrator($this->logger, $this->paths))->validateSource([
                'host' => rac_installer_post_string('source_db_host', 255) ?? '',
                'name' => rac_installer_post_string('source_db_name', 128) ?? '',
                'user' => rac_installer_post_string('source_db_user', 128) ?? '',
                'pass' => rac_installer_post_string('source_db_pass', 1024) ?? '',
                'root_path' => rac_installer_post_string('source_root_path', 4096) ?? '',
            ], $database);
            $this->session->set('source_database', $source);
            $this->session->regenerate();
            $this->advance('site');
            rac_installer_redirect('site');
        }
        if ($action === 'site') {
            $mode = (string) $this->session->get('install_mode', '');
            if (!in_array($mode, ['fresh', 'migrate'], true)) {
                throw new RACInstallerUserException('Choose an installation type first.');
            }
            if ($mode === 'migrate' && !is_array($this->session->get('source_database'))) {
                throw new RACInstallerUserException('Validate the WoWonder source before continuing.');
            }
            $postInstall = new RACInstallerPostInstall($this->logger);
            $site = $postInstall->validateSite([
                'url' => rac_installer_post_string('site_url', 2048) ?? '',
                'name' => rac_installer_post_string('site_name', 100) ?? '',
                'title' => rac_installer_post_string('site_title', 150) ?? '',
                'email' => rac_installer_post_string('site_email', 254) ?? '',
            ]);
            $this->session->set('site', $site);
            $this->session->regenerate();
            $next = $mode === 'migrate' ? 'install' : 'admin';
            $this->advance($next);
            rac_installer_redirect($next);
        }
        if ($action === 'admin') {
            if ((string) $this->session->get('install_mode', '') !== 'fresh') {
                throw new RACInstallerUserException('Migration keeps the existing WoWonder administrators.');
            }
            $password = rac_installer_post_string('admin_password', 128) ?? '';
            $confirmation = rac_installer_post_string('admin_password_confirmation', 128) ?? '';
            if (!hash_equals($password, $confirmation)) {
                throw new RACInstallerUserException('Administrator password confirmation does not match.');
            }
            $admin = (new RACInstallerPostInstall($this->logger))->validateAdmin([
                'username' => rac_installer_post_string('admin_username', 32) ?? '',
                'email' => rac_installer_post_string('admin_email', 254) ?? '',
                'password' => $password,
            ]);
            $this->session->set('admin', $admin);
            $this->session->regenerate();
            $this->advance('install');
            rac_installer_redirect('install');
        }
        if ($action === 'install') {
            $this->runInstallation();
            rac_installer_redirect('finish');
        }
        throw new RACInstallerUserException('Unknown installer action. Reload the page and try again.');
    }

    private function runInstallation(): void
    {
        $mode = (string) $this->session->get('install_mode', '');
        $database = $this->session->get('database');
        $site = $this->session->get('site');
        $admin = $this->session->get('admin');
        $sourceDatabase = $this->session->get('source_database');
        $modeStateValid = $mode === 'fresh'
            ? is_array($admin)
            : ($mode === 'migrate' && is_array($sourceDatabase));
        if (!is_array($database) || !is_array($site) || !$modeStateValid) {
            throw new RACInstallerUserException('The validated installer state expired. Start again before making database changes.');
        }
        if ($this->session->get('install_running') === true) {
            throw new RACInstallerUserException('An installation request is already in progress.');
        }
        $this->session->set('install_running', true);

        $configWriter = new RACInstallerConfigWriter($this->paths);
        $nodeWriter = new RACInstallerNodeConfigWriter($this->paths);
        $connection = null;
        try {
            $validator = new RACInstallerDatabaseValidator($this->logger);
            $database = $validator->validate($database);
            $configWriter->prepare($database, $site, '', []);
            $nodeWriter->prepare($database, $site, '');
            $connection = $validator->connect($database);
            $postInstall = new RACInstallerPostInstall($this->logger);
            if ($mode === 'migrate') {
                $migration = (new RACInstallerWoWonderMigrator($this->logger, $this->paths))->migrate($connection, $sourceDatabase);
                $adminUserId = $postInstall->configureMigration($connection, $site);
                $import = [
                    'tables' => (int) $migration['tables'],
                    'schema_fingerprint' => $this->schemaFingerprint($connection),
                ];
                $summary = array_merge(['mode' => 'migrate'], $migration);
            } else {
                $sqlImporter = new RACInstallerSqlImporter($this->logger);
                $baseImport = $sqlImporter->import($connection, $this->paths->sqlDump);
                $mobileImport = $sqlImporter->import($connection, $this->paths->mobileApiMigration);
                $import = $mobileImport;
                $import['statements'] = (int) $baseImport['statements'] + (int) $mobileImport['statements'];
                $adminUserId = $postInstall->configure($connection, $site, $admin);
                $summary = ['mode' => 'fresh', 'tables' => (int) $import['tables']];
            }
            (new RACInstallerHtaccessWriter($this->paths))->installIfMissing();
            $nodeWriter->commit();
            $configWriter->commit();
            $this->lock->create($site['url'], $import['schema_fingerprint']);
            $configWriter->finalize();
            $nodeWriter->finalize();
            $this->logger->info('installation', [
                'result' => 'complete',
                'mode' => $mode,
                'tables' => $import['tables'],
                'admin_user_id' => $adminUserId,
            ]);
            $this->session->set('install_summary', $summary);
            $this->session->clearSensitive();
            $this->advance('finish');
        } catch (Throwable $error) {
            $configWriter->cleanup();
            $nodeWriter->cleanup();
            $configWriter->rollbackPublished();
            $nodeWriter->rollbackPublished();
            $configWriter->finalize();
            $nodeWriter->finalize();
            throw $error;
        } finally {
            if ($connection instanceof mysqli) {
                $connection->close();
            }
            $this->session->forget('install_running');
        }
    }

    private function schemaFingerprint(mysqli $database): string
    {
        $tables = [];
        $result = $database->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
        );
        while ($row = $result->fetch_assoc()) {
            $tables[] = (string) $row['table_name'];
        }
        $result->free();
        return hash('sha256', implode("\n", $tables));
    }

    /**
     * Restore a deleted install lock only when the existing PHP configuration
     * opens a real Ramza database containing the required core tables.
     */
    private function restoreMissingLockForValidInstall(): bool
    {
        if (!is_file($this->paths->configFile) || !is_readable($this->paths->configFile)) {
            return false;
        }

        try {
            $configuration = (static function (string $path): array {
                $sql_db_host = $sql_db_user = $sql_db_pass = $sql_db_name = $site_url = '';
                $table_prefix = 'Wo_';
                require $path;
                return compact(
                    'sql_db_host',
                    'sql_db_user',
                    'sql_db_pass',
                    'sql_db_name',
                    'site_url',
                    'table_prefix'
                );
            })($this->paths->configFile);

            foreach (['sql_db_host', 'sql_db_user', 'sql_db_name', 'site_url'] as $requiredKey) {
                if (!is_string($configuration[$requiredKey]) || trim($configuration[$requiredKey]) === '') {
                    return false;
                }
            }
            if (!is_string($configuration['sql_db_pass'])) {
                return false;
            }

            $database = new mysqli(
                trim($configuration['sql_db_host']),
                trim($configuration['sql_db_user']),
                $configuration['sql_db_pass'],
                trim($configuration['sql_db_name'])
            );
            if ($database->connect_errno !== 0) {
                return false;
            }
            try {
                $database->set_charset('utf8mb4');
                $prefix = is_string($configuration['table_prefix']) && preg_match('/^[A-Za-z0-9_]+$/', $configuration['table_prefix']) === 1
                    ? $configuration['table_prefix']
                    : 'Wo_';
                $requiredTables = [$prefix . 'Config', $prefix . 'Users'];
                $statement = $database->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables ' .
                    'WHERE table_schema = DATABASE() AND table_type = ? AND table_name IN (?, ?)'
                );
                if (!$statement) {
                    return false;
                }
                $tableType = 'BASE TABLE';
                $statement->bind_param('sss', $tableType, $requiredTables[0], $requiredTables[1]);
                $statement->execute();
                $statement->bind_result($requiredCount);
                $statement->fetch();
                $statement->close();
                if ((int) $requiredCount !== count($requiredTables)) {
                    return false;
                }

                $fingerprint = $this->schemaFingerprint($database);
                if ($fingerprint === hash('sha256', '')) {
                    return false;
                }
                $this->lock->create(trim($configuration['site_url']), $fingerprint);
                $this->logger->info('install-lock-restored', ['reason' => 'validated-existing-install']);
                return true;
            } finally {
                $database->close();
            }
        } catch (Throwable $error) {
            $this->logger->error('install-lock-recovery', $error);
            return false;
        }
    }

    private function advance(string $step): void
    {
        if ($this->stepIndex($step) > $this->stepIndex((string) $this->session->get('max_step', 'welcome'))) {
            $this->session->set('max_step', $step);
        }
    }

    private function isAccessible(string $step): bool
    {
        return $this->stepIndex($step) <= $this->stepIndex((string) $this->session->get('max_step', 'welcome'));
    }

    private function stepIndex(string $step): int
    {
        $index = array_search($step, $this->steps, true);
        return $index === false ? 0 : $index;
    }

    private function viewData(string $step): array
    {
        $mode = (string) $this->session->get('install_mode', '');
        $source = $this->session->get('source_database', []);
        $data = [
            'step' => $step,
            'mode' => $mode,
            'source' => is_array($source) ? $source : [],
        ];
        if ($step === 'requirements') {
            $data['requirements'] = $this->requirements->evaluate();
        }
        if ($step === 'install') {
            $data['site'] = $this->session->get('site', []);
            $data['database'] = $this->session->get('database', []);
            $data['admin'] = $this->session->get('admin', []);
            $data['requirements'] = $this->requirements->evaluate();
        }
        if ($step === 'site' && is_array($source) && $mode === 'migrate') {
            $data['site_defaults'] = [
                'url' => (string) ($source['site_url'] ?? ''),
                'name' => (string) ($source['site_name'] ?? 'Ramza'),
                'title' => (string) ($source['site_title'] ?? ''),
                'email' => (string) ($source['site_email'] ?? ''),
            ];
        }
        if ($step === 'admin') {
            $site = $this->session->get('site', []);
            $data['site_email'] = is_array($site) ? (string) ($site['email'] ?? '') : '';
        }
        if ($step === 'finish') {
            $data['summary'] = $this->session->get('install_summary', []);
        }
        return $data;
    }

    private function render(string $view, array $data): void
    {
        $data['view'] = $view;
        $data['csrf'] = $this->csrf->token();
        $data['steps'] = $this->steps;
        extract($data, EXTR_SKIP);
        require $this->paths->installDir . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'layout.php';
    }
}
