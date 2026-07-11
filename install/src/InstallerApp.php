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
    private array $steps = ['welcome', 'requirements', 'database', 'site', 'admin', 'install', 'finish'];

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
            $this->advance('database');
            rac_installer_redirect('database');
        }
        if ($action === 'database') {
            $validator = new RACInstallerDatabaseValidator($this->logger);
            $database = $validator->validate([
                'host' => rac_installer_post_string('db_host', 255) ?? '',
                'name' => rac_installer_post_string('db_name', 128) ?? '',
                'user' => rac_installer_post_string('db_user', 128) ?? '',
                'pass' => rac_installer_post_string('db_pass', 1024) ?? '',
            ]);
            $this->session->set('database', $database);
            $this->session->regenerate();
            $this->advance('site');
            rac_installer_redirect('site');
        }
        if ($action === 'site') {
            $postInstall = new RACInstallerPostInstall($this->logger);
            $site = $postInstall->validateSite([
                'url' => rac_installer_post_string('site_url', 2048) ?? '',
                'name' => rac_installer_post_string('site_name', 100) ?? '',
                'title' => rac_installer_post_string('site_title', 150) ?? '',
                'email' => rac_installer_post_string('site_email', 254) ?? '',
            ]);
            $purchaseCode = rac_installer_post_string('purchase_code', 512) ?? '';
            $endpoint = defined('RACSOCIAL_LICENSE_ENDPOINT') ? (string) constant('RACSOCIAL_LICENSE_ENDPOINT') : '';
            $license = new RACInstallerLicenseVerifier($this->logger, new RACInstallerCurlHttpClient(), $endpoint);
            $result = $license->verify($purchaseCode, $site['url']);
            if (!$result['ok']) {
                throw new RACInstallerUserException($result['message']);
            }
            $this->session->set('site', $site);
            $this->session->set('purchase_code', $purchaseCode);
            $this->session->set('license_verified_at', time());
            $this->session->regenerate();
            $this->advance('admin');
            rac_installer_redirect('admin');
        }
        if ($action === 'admin') {
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
        $database = $this->session->get('database');
        $site = $this->session->get('site');
        $admin = $this->session->get('admin');
        $purchaseCode = $this->session->get('purchase_code');
        $verifiedAt = (int) $this->session->get('license_verified_at', 0);
        if (!is_array($database) || !is_array($site) || !is_array($admin) || !is_string($purchaseCode) || $verifiedAt < time() - 1800) {
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
            $configWriter->prepare($database, $site, $purchaseCode);
            $nodeWriter->prepare($database, $site, $purchaseCode);
            $connection = $validator->connect($database);
            $import = (new RACInstallerSqlImporter($this->logger))->import($connection, $this->paths->sqlDump);
            $adminUserId = (new RACInstallerPostInstall($this->logger))->configure($connection, $site, $admin);
            (new RACInstallerHtaccessWriter($this->paths))->installIfMissing();
            $nodeWriter->commit();
            $configWriter->commit();
            $this->lock->create($site['url'], $import['schema_fingerprint']);
            $configWriter->finalize();
            $nodeWriter->finalize();
            $this->logger->info('installation', ['result' => 'complete', 'tables' => $import['tables'], 'admin_user_id' => $adminUserId]);
            $this->session->set('install_summary', ['tables' => $import['tables']]);
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
        $data = ['step' => $step];
        if ($step === 'requirements') {
            $data['requirements'] = $this->requirements->evaluate();
        }
        if ($step === 'install') {
            $data['site'] = $this->session->get('site', []);
            $data['database'] = $this->session->get('database', []);
            $data['admin'] = $this->session->get('admin', []);
            $data['requirements'] = $this->requirements->evaluate();
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
