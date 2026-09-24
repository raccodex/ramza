<?php
declare(strict_types=1);

final class RACInstallerPostInstall
{
    public function __construct(private readonly RACInstallerLogger $logger)
    {
    }

    public function validateSite(array $input): array
    {
        foreach (['url', 'name', 'title', 'email'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '') {
                throw new RACInstallerUserException('Site URL, name, title, and email are required.');
            }
        }
        $url = rtrim(trim($input['url']), '/');
        if (preg_match('/[\x00-\x1F\x7F<>"\'`]/u', $url)) {
            throw new RACInstallerUserException('The site URL contains unsafe characters.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            throw new RACInstallerUserException('Enter a complete site URL beginning with http:// or https://.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RACInstallerUserException('The site URL cannot contain credentials, a query string, or a fragment.');
        }
        if (!filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL)) {
            throw new RACInstallerUserException('Enter a valid site email address.');
        }
        if (mb_strlen(trim($input['name'])) > 100 || mb_strlen(trim($input['title'])) > 150) {
            throw new RACInstallerUserException('The site name or title is too long.');
        }
        if (preg_match('/[\x00-\x1F\x7F<>]/u', $input['name'] . $input['title'])) {
            throw new RACInstallerUserException('The site name or title contains unsafe markup or control characters.');
        }
        return [
            'url' => $url,
            'name' => trim($input['name']),
            'title' => trim($input['title']),
            'email' => strtolower(trim($input['email'])),
        ];
    }

    public function validateAdmin(array $input): array
    {
        foreach (['username', 'email', 'password'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || $input[$key] === '') {
                throw new RACInstallerUserException('Administrator username, email, and password are required.');
            }
        }
        $username = trim($input['username']);
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
            throw new RACInstallerUserException('Administrator username must be 5–32 letters, numbers, or underscores.');
        }
        $email = strtolower(trim($input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RACInstallerUserException('Enter a valid administrator email address.');
        }
        if (strlen($input['password']) < 12 || strlen($input['password']) > 128) {
            throw new RACInstallerUserException('Administrator password must be 12–128 characters.');
        }
        if (!preg_match('/[A-Za-z]/', $input['password']) || !preg_match('/\d/', $input['password'])) {
            throw new RACInstallerUserException('Administrator password must include at least one letter and one number.');
        }
        return ['username' => $username, 'email' => $email, 'password' => $input['password']];
    }

    public function configure(mysqli $database, array $site, array $admin): int
    {
        $database->begin_transaction();
        try {
            $this->setConfig($database, 'siteName', $site['name']);
            $this->setConfig($database, 'siteTitle', $site['title']);
            $this->setConfig($database, 'siteEmail', $site['email']);
            $this->setConfig($database, 'theme', 'ramza-light');
            $this->setConfig($database, 'maintenance_mode', '0');
            $this->setConfig($database, 'developer_mode', '0');

            $passwordHash = password_hash($admin['password'], PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RACInstallerSystemException('Unable to hash the administrator password securely.');
            }
            $registered = date('m/Y');
            $joined = time();
            $username = $admin['username'];
            $email = $admin['email'];
            $statement = $database->prepare("INSERT INTO Wo_Users (username, email, password, status, active, admin, registered, joined, start_up, start_up_info, startup_follow, startup_image) VALUES (?, ?, ?, '1', '1', '1', ?, ?, '1', '1', '1', '1')");
            $statement->bind_param('ssssi', $username, $email, $passwordHash, $registered, $joined);
            $statement->execute();
            $userId = (int) $database->insert_id;
            $statement->close();

            $fields = $database->prepare('INSERT INTO Wo_UserFields (user_id) VALUES (?)');
            $fields->bind_param('i', $userId);
            $fields->execute();
            $fields->close();
            $database->commit();

            $check = $database->prepare('SELECT password, admin, active FROM Wo_Users WHERE user_id = ?');
            $check->bind_param('i', $userId);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            $check->close();
            if (!is_array($row) || (string) $row['admin'] !== '1' || (string) $row['active'] !== '1' || !password_verify($admin['password'], (string) $row['password'])) {
                throw new RACInstallerSystemException('Administrator verification failed after creation.');
            }
            $this->logger->info('post-install', ['result' => 'complete', 'admin_user_id' => $userId]);
            return $userId;
        } catch (Throwable $error) {
            $database->rollback();
            $this->logger->error('post-install', $error);
            throw $error;
        }
    }

    public function configureMigration(mysqli $database, array $site): int
    {
        $database->begin_transaction();
        try {
            $this->setConfig($database, 'site_url', $site['url']);
            $this->setConfig($database, 'siteName', $site['name']);
            $this->setConfig($database, 'siteTitle', $site['title']);
            $this->setConfig($database, 'siteEmail', $site['email']);
            $this->setConfig($database, 'theme', 'ramza-light');
            $this->setConfig($database, 'version', '1.0');
            $result = $database->query("SELECT `user_id` FROM `Wo_Users` WHERE `admin` = '1' AND `active` = '1' ORDER BY `user_id` ASC LIMIT 1");
            $row = $result->fetch_assoc();
            $result->free();
            if (!is_array($row) || empty($row['user_id'])) {
                throw new RACInstallerUserException('The WoWonder source has no active administrator. Create or activate an administrator in WoWonder before migrating.');
            }
            $database->commit();
            $adminId = (int) $row['user_id'];
            $this->logger->info('post-migration', ['result' => 'complete', 'admin_user_id' => $adminId]);
            return $adminId;
        } catch (Throwable $error) {
            $database->rollback();
            $this->logger->error('post-migration', $error);
            throw $error;
        }
    }

    private function setConfig(mysqli $database, string $name, string $value): void
    {
        $statement = $database->prepare('UPDATE Wo_Config SET value = ? WHERE name = ?');
        $statement->bind_param('ss', $value, $name);
        $statement->execute();
        if ($statement->affected_rows < 0) {
            throw new RACInstallerSystemException('Unable to update a required site setting.');
        }
        $statement->close();
    }
}
