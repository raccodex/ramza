<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class CommonThingsService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    private function viewer(string $accessToken, string $clientId): array
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $id = (int) ($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_common_things', (string) $id, 120, 60);
        global $wo;
        $user = \Wo_UserData($id);
        if (!is_array($user) || empty($user['user_id'])) {
            throw new ApiException(401, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $wo['loggedin'] = true;
        $wo['user'] = $user;
        $wo['user']['id'] = $id;
        return $user;
    }

    private function hasComparableProfileData(array $user): bool
    {
        return !empty($user['relationship_id'])
            || !empty($user['school'])
            || !empty($user['working'])
            || (!empty($user['birthday']) && $user['birthday'] !== '0000-00-00')
            || !empty($user['country_id'])
            || !empty($user['city']);
    }

    private function media(string $value): string
    {
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        return function_exists('Wo_GetMedia') ? (string) \Wo_GetMedia($value) : $value;
    }

    public function list(
        string $accessToken,
        string $clientId,
        int $afterId,
        int $limit
    ): array {
        $viewer = $this->viewer($accessToken, $clientId);
        if (!$this->hasComparableProfileData($viewer)) {
            return [];
        }

        $rows = \Wo_GetCommonUsers([
            'limit' => max(1, min(50, $limit)),
            'after' => max(0, $afterId),
        ]);
        $result = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !is_array($row['user_data'] ?? null)) {
                continue;
            }
            $user = $row['user_data'];
            $id = (int) ($user['user_id'] ?? $row['user_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $name = trim((string) ($user['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            }
            if ($name === '') {
                $name = (string) ($user['username'] ?? '');
            }
            $result[] = [
                'id' => $id,
                'user_id' => $id,
                'username' => (string) ($user['username'] ?? ''),
                'name' => html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'avatar' => $this->media((string) ($user['avatar'] ?? '')),
                'common_things' => max(0, (int) ($row['common_things'] ?? 0)),
            ];
        }
        return $result;
    }
}
