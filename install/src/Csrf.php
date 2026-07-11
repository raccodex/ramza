<?php
declare(strict_types=1);

final class RACInstallerCsrf
{
    public function __construct(private readonly RACInstallerSession $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('csrf_token');
        if (!is_string($token) || strlen($token) < 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }
        return $token;
    }

    public function validate(?string $token): void
    {
        $expected = $this->token();
        if (!is_string($token) || !hash_equals($expected, $token)) {
            throw new RACInstallerUserException('Your installer session expired or the form token was invalid. Please reload and try again.');
        }
    }
}
