<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class GameService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    private function viewer(string $accessToken, string $clientId): int
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $id = (int)($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_games', (string)$id, 180, 60);

        global $wo;
        $user = \Wo_UserData($id);
        if (!is_array($user) || empty($user['user_id'])) {
            throw new ApiException(401, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $wo['loggedin'] = true;
        $wo['user'] = $user;
        $wo['user']['id'] = $id;

        if ((int)($wo['config']['games'] ?? 0) !== 1
            || (isset($wo['config']['can_use_games']) && (int)$wo['config']['can_use_games'] !== 1)) {
            throw new ApiException(403, 'FEATURE_DISABLED', 'Games are not available.');
        }
        return $id;
    }

    private function media(string $value): string
    {
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        return function_exists('Wo_GetMedia') ? (string)\Wo_GetMedia($value) : $value;
    }

    private function present(array $row): array
    {
        $id = (int)($row['id'] ?? 0);
        $lastPlay = (int)($row['last_play'] ?? 0);
        return [
            'id' => $id,
            'name' => html_entity_decode(strip_tags((string)($row['name'] ?? $row['game_name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'avatar' => $this->media((string)($row['game_avatar'] ?? '')),
            'game_link' => trim((string)($row['game_link'] ?? '')),
            'url' => (string)($row['url'] ?? ''),
            'active' => (int)($row['active'] ?? 0) === 1,
            'players' => (int)($row['players'] ?? 0),
            'last_play' => $lastPlay,
            'offset_id' => (int)($row['offset_id'] ?? 0),
            'is_played' => $lastPlay > 0,
        ];
    }

    public function list(
        string $accessToken,
        string $clientId,
        string $scope,
        string $query,
        int $after,
        int $limit
    ): array {
        $this->viewer($accessToken, $clientId);
        $limit = max(1, min(50, $limit));
        $after = max(0, $after);

        if ($scope === 'mine') {
            $rows = \Wo_GetMyGames($limit, $after);
        } elseif ($scope === 'popular') {
            $rows = \Wo_GetPopularGames($limit, $after);
        } elseif ($scope === 'search') {
            if ($query === '') {
                return [];
            }
            $rows = \Wo_GetSearchAdv(\Wo_Secure($query), 'games', $after, $limit);
        } else {
            $rows = \Wo_GetAllGames($limit, $after);
        }

        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id > 0 && empty($row['game_link'])) {
                $full = \Wo_GameData($id);
                if (is_array($full)) {
                    $row = array_merge($full, $row);
                }
            }
            if ((int)($row['id'] ?? 0) > 0) {
                $items[] = $this->present($row);
            }
        }
        return $items;
    }

    public function get(string $accessToken, string $clientId, int $id): array
    {
        $this->viewer($accessToken, $clientId);
        $row = \Wo_GameData($id);
        if (!is_array($row) || empty($row['id'])) {
            throw new ApiException(404, 'GAME_NOT_FOUND', 'This game is unavailable.');
        }
        $row['players'] = \Wo_CountGamePlayers($id);
        return $this->present($row);
    }

    public function play(string $accessToken, string $clientId, int $id): array
    {
        $this->viewer($accessToken, $clientId);
        $row = \Wo_GameData($id);
        if (!is_array($row) || empty($row['id']) || (int)($row['active'] ?? 0) !== 1) {
            throw new ApiException(404, 'GAME_NOT_FOUND', 'This game is unavailable.');
        }
        \Wo_AddPlayGame($id);
        return $this->get($accessToken, $clientId, $id);
    }
}
