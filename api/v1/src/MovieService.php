<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class MovieService
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
        $this->rateLimiter->enforce('mobile_movies', (string) $id, 180, 60);
        global $wo;
        $user = \Wo_UserData($id);
        if (!is_array($user) || empty($user['user_id'])) {
            throw new ApiException(401, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $wo['loggedin'] = true;
        $wo['user'] = $user;
        $wo['user']['id'] = $id;
        return ['id' => $id, 'can_manage' => \Wo_IsAdmin() || \Wo_IsModerator()];
    }

    private function present(array $movie, bool $canManage): array
    {
        return [
            'id' => (int) ($movie['id'] ?? 0),
            'name' => html_entity_decode(strip_tags((string) ($movie['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'genre' => (string) ($movie['genre'] ?? ''),
            'stars' => html_entity_decode(strip_tags((string) ($movie['stars'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'producer' => html_entity_decode(strip_tags((string) ($movie['producer'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'country' => (string) ($movie['country'] ?? ''),
            'release' => (string) ($movie['release'] ?? ''),
            'quality' => strtoupper((string) ($movie['quality'] ?? '')),
            'duration' => (int) ($movie['duration'] ?? 0),
            'description' => html_entity_decode(strip_tags((string) ($movie['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'cover' => $this->media((string) ($movie['cover'] ?? '')),
            'source' => $this->media((string) ($movie['source'] ?? '')),
            'iframe' => (string) ($movie['iframe'] ?? ''),
            'video' => $this->media((string) ($movie['video'] ?? '')),
            'views' => (int) ($movie['views'] ?? 0),
            'rating' => (float) ($movie['rating'] ?? 0),
            'url' => (string) ($movie['url'] ?? ''),
            'can_manage' => $canManage,
        ];
    }

    private function media(string $value): string
    {
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) return $value;
        return function_exists('Wo_GetMedia') ? (string) \Wo_GetMedia($value) : $value;
    }

    public function configuration(string $accessToken, string $clientId): array
    {
        $viewer = $this->viewer($accessToken, $clientId);
        global $wo;
        $configured = $wo['film-genres'] ?? [];
        $genres = [];
        foreach (is_array($configured) ? $configured : [] as $key => $label) {
            $genres[] = ['id' => (string) $key, 'name' => (string) $label];
        }
        return ['can_manage' => $viewer['can_manage'], 'genres' => $genres];
    }

    public function list(string $accessToken, string $clientId, int $afterId, int $limit, string $genre, string $sort): array
    {
        $viewer = $this->viewer($accessToken, $clientId);
        $limit = max(1, min(50, $limit));
        if ($sort === 'most_watched') {
            $rows = \Wo_GetMtwFilms($limit);
            if ($afterId > 0) {
                $rows = array_values(array_filter(is_array($rows) ? $rows : [], static fn($row): bool => (int) ($row['id'] ?? 0) < $afterId));
            }
        } else {
            $rows = \Wo_GetMovies([
                'offset' => max(0, $afterId),
                'limit' => $limit,
                'genre' => trim($genre),
            ]);
        }
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) $items[] = $this->present($row, (bool) $viewer['can_manage']);
        }
        return $items;
    }

    public function get(string $accessToken, string $clientId, int $id, bool $recordView = true): array
    {
        $viewer = $this->viewer($accessToken, $clientId);
        global $db;
        $raw = $db->where('id', $id)->getOne(T_MOVIES);
        if (empty($raw)) throw new ApiException(404, 'MOVIE_NOT_FOUND', 'This movie is unavailable.');
        if ($recordView) {
            $db->where('id', $id)->update(T_MOVIES, ['views' => (int) $raw->views + 1]);
        }
        $rows = \Wo_GetMovies(['id' => $id, 'limit' => 1]);
        if (!is_array($rows) || empty($rows[0])) throw new ApiException(404, 'MOVIE_NOT_FOUND', 'This movie is unavailable.');
        if ($recordView) $rows[0]['views'] = (int) ($rows[0]['views'] ?? 0) + 1;
        return $this->present($rows[0], (bool) $viewer['can_manage']);
    }

    private function values(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        if ($name === '') throw new ApiException(422, 'VALIDATION_FAILED', 'Movie name is required.', 'name');
        if ($description === '') throw new ApiException(422, 'VALIDATION_FAILED', 'Movie summary is required.', 'description');
        return [
            'name' => \Wo_Secure($name),
            'genre' => \Wo_Secure((string) ($payload['genre'] ?? '')),
            'stars' => \Wo_Secure((string) ($payload['stars'] ?? '')),
            'producer' => \Wo_Secure((string) ($payload['producer'] ?? '')),
            'country' => \Wo_Secure((string) ($payload['country'] ?? '')),
            'release' => \Wo_Secure((string) ($payload['release'] ?? date('Y'))),
            'quality' => \Wo_Secure((string) ($payload['quality'] ?? 'HD')),
            'duration' => max(0, (int) ($payload['duration'] ?? 0)),
            'description' => \Wo_Secure($description),
            'source' => \Wo_Secure((string) ($payload['source'] ?? '')),
            'iframe' => \Wo_Secure((string) ($payload['iframe'] ?? '')),
            'rating' => max(0, min(10, (float) ($payload['rating'] ?? 1))),
        ];
    }

    private function uploadImage(array $file): string
    {
        $media = \Wo_ShareFile([
            'file' => $file['tmp_name'],
            'name' => $file['name'] ?? 'movie-cover.jpg',
            'size' => $file['size'] ?? 0,
            'type' => $file['type'] ?? 'image/jpeg',
            'types' => 'jpeg,jpg,png,bmp,webp',
        ]);
        if (!is_array($media) || empty($media['filename'])) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The selected cover is not supported.', 'cover');
        }
        return (string) $media['filename'];
    }

    public function create(string $accessToken, string $clientId, array $payload): array
    {
        $viewer = $this->viewer($accessToken, $clientId);
        if (!$viewer['can_manage']) throw new ApiException(403, 'FORBIDDEN', 'Only an administrator can add movies.');
        $payload += $_POST;
        $values = $this->values($payload);
        if (empty($_FILES['cover']['tmp_name'])) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a movie cover.', 'cover');
        }
        $values['cover'] = $this->uploadImage($_FILES['cover']);
        if ($values['source'] === '' && $values['iframe'] === '') {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Add a video source or YouTube URL.', 'source');
        }
        $id = (int) \Wo_InsertFilm($values);
        if ($id < 1) throw new ApiException(500, 'MOVIE_CREATE_FAILED', 'The movie could not be created.');
        return $this->get($accessToken, $clientId, $id, false);
    }

    public function update(string $accessToken, string $clientId, int $id, array $payload): array
    {
        $viewer = $this->viewer($accessToken, $clientId);
        if (!$viewer['can_manage']) throw new ApiException(403, 'FORBIDDEN', 'Only an administrator can edit movies.');
        $payload += $_POST;
        $values = $this->values($payload);
        if (!empty($_FILES['cover']['tmp_name'])) $values['cover'] = $this->uploadImage($_FILES['cover']);
        if (!\Wo_UpdateFilm($id, $values)) throw new ApiException(500, 'MOVIE_UPDATE_FAILED', 'The movie could not be updated.');
        return $this->get($accessToken, $clientId, $id, false);
    }

    public function delete(string $accessToken, string $clientId, int $id): array
    {
        $viewer = $this->viewer($accessToken, $clientId);
        if (!$viewer['can_manage']) throw new ApiException(403, 'FORBIDDEN', 'Only an administrator can delete movies.');
        if (!\Wo_DeleteFilm($id)) throw new ApiException(500, 'MOVIE_DELETE_FAILED', 'The movie could not be deleted.');
        return ['deleted' => true];
    }

    private function presentComment(array $comment): array
    {
        $user = is_array($comment['user_data'] ?? null) ? $comment['user_data'] : \Wo_UserData((int) ($comment['user_id'] ?? 0));
        $user = is_array($user) ? $user : [];
        return [
            'id' => (int) ($comment['id'] ?? 0),
            'text' => html_entity_decode((string) ($comment['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'time' => (int) ($comment['posted'] ?? $comment['time'] ?? 0),
            'likes' => (int) ($comment['likes'] ?? 0),
            'is_liked' => !empty($comment['is_comment_liked']),
            'is_owner' => !empty($comment['is_owner']),
            'user' => [
                'id' => (int) ($user['user_id'] ?? 0),
                'name' => (string) ($user['name'] ?? $user['username'] ?? ''),
                'avatar' => (string) ($user['avatar'] ?? ''),
            ],
        ];
    }

    public function comments(string $accessToken, string $clientId, int $movieId, int $afterId, int $limit): array
    {
        $this->viewer($accessToken, $clientId);
        $rows = \Wo_GetMovieComments(['movie_id' => $movieId, 'offset' => max(0, $afterId), 'limit' => max(1, min(50, $limit))]);
        return array_map(fn(array $row): array => $this->presentComment($row), is_array($rows) ? $rows : []);
    }

    public function addComment(string $accessToken, string $clientId, int $movieId, array $payload): array
    {
        $viewer = $this->viewer($accessToken, $clientId);
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') throw new ApiException(422, 'VALIDATION_FAILED', 'Write a comment first.', 'text');
        $id = (int) \Wo_RegisterMovieComment([
            'movie_id' => $movieId,
            'user_id' => (int) $viewer['id'],
            'text' => \Wo_Secure($text),
            'posted' => time(),
        ]);
        if ($id < 1) throw new ApiException(500, 'COMMENT_CREATE_FAILED', 'The comment could not be posted.');
        $row = \Wo_GetMovieCommentData($id);
        return $this->presentComment(is_array($row) ? $row : ['id' => $id, 'user_id' => $viewer['id'], 'text' => $text, 'posted' => time()]);
    }

    public function boostTest(string $accessToken, string $clientId, int $movieId): array
    {
        $viewer = $this->viewer($accessToken, $clientId);
        if (!$viewer['can_manage']) throw new ApiException(403, 'FORBIDDEN', 'Only an administrator can create the test promotion.');
        $movie = $this->get($accessToken, $clientId, $movieId, false);
        global $db;
        $existing = $db->where('postLink', (string) $movie['url'])->where('boosted', 1)->getOne(T_POSTS);
        if (!empty($existing->id)) return ['post_id' => (int) $existing->id, 'created' => false];
        $id = (int) $db->insert(T_POSTS, [
            'user_id' => (int) $viewer['id'],
            'postText' => 'Watch ' . (string) $movie['name'],
            'postLink' => (string) $movie['url'],
            'postLinkTitle' => (string) $movie['name'],
            'postLinkImage' => (string) $movie['cover'],
            'postLinkContent' => (string) $movie['description'],
            'postType' => 'link',
            'postPrivacy' => 0,
            'boosted' => 1,
            'time' => time(),
        ]);
        if ($id < 1) throw new ApiException(500, 'BOOST_CREATE_FAILED', 'The test promotion could not be created.');
        $db->where('id', $id)->update(T_POSTS, ['post_id' => $id]);
        return ['post_id' => $id, 'created' => true];
    }
}
