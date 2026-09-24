<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/** Read-only mobile article access backed by the existing Wo_Blog tables. */
final class ArticleService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    public function list(
        string $token,
        string $clientId,
        int $category = 0,
        bool $mine = false,
        int $limit = 20,
        int $offset = 0
    ): array {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_articles_read');
        global $sqlConnect;
        $limit = max(1, min(50, $limit));
        $offset = max(0, min(10000, $offset));
        $where = ["b.active='1'", "CAST(b.posted AS UNSIGNED)>0"];
        $types = '';
        $values = [];
        if ($category > 0) {
            $where[] = 'b.category=?';
            $types .= 'i';
            $values[] = $category;
        }
        if ($mine) {
            $where[] = 'b.user=?';
            $types .= 'i';
            $values[] = $viewerId;
        }
        $sql = 'SELECT b.id,b.user,b.title,b.description,b.thumbnail,b.category,b.posted,b.view,'
            . 'u.username,u.first_name,u.last_name,u.avatar '
            . 'FROM Wo_Blog b LEFT JOIN Wo_Users u ON u.user_id=b.user '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY b.id DESC LIMIT ? OFFSET ?';
        $types .= 'ii';
        $values[] = $limit;
        $values[] = $offset;
        $stmt = $sqlConnect->prepare($sql);
        if ($stmt === false) return [];
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(fn(array $row): array => $this->card($row), $rows);
    }

    public function detail(string $token, string $clientId, int $id): array
    {
        $this->authenticate($token, $clientId, 'mobile_article_read');
        global $sqlConnect;
        $stmt = $sqlConnect->prepare(
            'SELECT b.id,b.user,b.title,b.content,b.description,b.thumbnail,b.category,b.posted,b.view,'
            . 'u.username,u.first_name,u.last_name,u.avatar '
            . 'FROM Wo_Blog b LEFT JOIN Wo_Users u ON u.user_id=b.user '
            . 'WHERE b.id=? AND b.active=\'1\' LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            throw new ApiException(404, 'ARTICLE_NOT_FOUND', 'The article was not found.');
        }
        $update = $sqlConnect->prepare('UPDATE Wo_Blog SET view=view+1 WHERE id=?');
        if ($update) {
            $update->bind_param('i', $id);
            $update->execute();
            $update->close();
        }
        return $this->card($row, true);
    }

    public function comments(string $token, string $clientId, int $id, int $limit = 25): array
    {
        $this->authenticate($token, $clientId, 'mobile_article_comments');
        global $sqlConnect;
        $limit = max(1, min(50, $limit));
        $stmt = $sqlConnect->prepare(
            'SELECT c.id,c.user_id,c.text,c.likes,c.posted,u.username,u.first_name,u.last_name,u.avatar '
            . 'FROM Wo_BlogComments c LEFT JOIN Wo_Users u ON u.user_id=c.user_id '
            . 'WHERE c.blog_id=? ORDER BY c.id ASC LIMIT ?'
        );
        $stmt->bind_param('ii', $id, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(fn(array $row): array => $this->comment($row), $rows);
    }

    public function createComment(
        string $token,
        string $clientId,
        int $id,
        string $text
    ): array {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_article_comment_create');
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 2000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid comment.', 'text');
        }
        global $sqlConnect;
        $exists = $sqlConnect->prepare('SELECT id FROM Wo_Blog WHERE id=? AND active=\'1\' LIMIT 1');
        $exists->bind_param('i', $id);
        $exists->execute();
        $found = $exists->get_result()->fetch_assoc();
        $exists->close();
        if (!is_array($found)) {
            throw new ApiException(404, 'ARTICLE_NOT_FOUND', 'The article was not found.');
        }
        $posted = time();
        $stmt = $sqlConnect->prepare(
            'INSERT INTO Wo_BlogComments (blog_id,user_id,text,likes,posted) VALUES (?,?,?,0,?)'
        );
        $stmt->bind_param('iisi', $id, $viewerId, $text, $posted);
        $stmt->execute();
        $commentId = (int)$stmt->insert_id;
        $stmt->close();
        $stmt = $sqlConnect->prepare(
            'SELECT c.id,c.user_id,c.text,c.likes,c.posted,u.username,u.first_name,u.last_name,u.avatar '
            . 'FROM Wo_BlogComments c LEFT JOIN Wo_Users u ON u.user_id=c.user_id WHERE c.id=? LIMIT 1'
        );
        $stmt->bind_param('i', $commentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $this->comment(is_array($row) ? $row : []);
    }

    /**
     * Exchanges the authenticated mobile session for a short-lived WoWonder
     * browser session. The session id is returned in the JSON body so Flutter
     * can install it as an Http cookie; it is never placed in a URL.
     */
    public function webSession(string $token, string $clientId): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_article_web_session');
        global $sqlConnect, $site_url;
        $now = time();
        $cutoff = $now - 3600;
        $cleanup = $sqlConnect->prepare(
            "DELETE FROM Wo_AppsSessions WHERE platform='mobile_web' AND time<?"
        );
        if ($cleanup) {
            $cleanup->bind_param('i', $cutoff);
            $cleanup->execute();
            $cleanup->close();
        }
        $sessionId = bin2hex(random_bytes(48));
        $details = json_encode([
            'source' => 'ramza_mobile',
            'request_id' => RequestContext::id(),
        ], JSON_UNESCAPED_SLASHES);
        $stmt = $sqlConnect->prepare(
            'INSERT INTO Wo_AppsSessions (user_id,session_id,platform,platform_details,time) '
            . "VALUES (?,?,'mobile_web',?,?)"
        );
        if ($stmt === false) {
            throw new ApiException(503, 'WEB_SESSION_UNAVAILABLE', 'Automatic web sign-in is unavailable.');
        }
        $stmt->bind_param('issi', $viewerId, $sessionId, $details, $now);
        $stmt->execute();
        $stmt->close();
        return [
            'cookie_name' => 'user_id',
            'cookie_value' => $sessionId,
            'expires_at' => $now + 3600,
            'url' => rtrim((string)$site_url, '/') . '/create-blog',
        ];
    }

    private function card(array $row, bool $full = false): array
    {
        $title = html_entity_decode((string)($row['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $result = [
            'id' => (int)($row['id'] ?? 0),
            'title' => $title,
            'description' => html_entity_decode((string)($row['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'category' => (string)($row['category'] ?? ''),
            'thumbnail' => $this->media((string)($row['thumbnail'] ?? '')),
            'posted' => (int)($row['posted'] ?? 0),
            'views' => (int)($row['view'] ?? 0),
            'author' => [
                'id' => (int)($row['user'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
                'avatar' => $this->media((string)($row['avatar'] ?? '')),
            ],
        ];
        if ($full) $result['content'] = (string)($row['content'] ?? '');
        return $result;
    }

    private function comment(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'text' => (string)($row['text'] ?? ''),
            'likes' => (int)($row['likes'] ?? 0),
            'posted' => (int)($row['posted'] ?? 0),
            'author' => [
                'id' => (int)($row['user_id'] ?? 0),
                'username' => (string)($row['username'] ?? ''),
                'name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
                'avatar' => $this->media((string)($row['avatar'] ?? '')),
            ],
        ];
    }

    private function authenticate(string $token, string $clientId, string $bucket): int
    {
        $this->rateLimiter->enforce($bucket, RequestContext::clientIp(), 120, 60);
        $session = $this->tokens->authenticate($token, $clientId);
        return (int)$session['user_id'];
    }

    private function media(string $value): string
    {
        global $site_url;
        if ($value === '') return '';
        if (preg_match('#^https?://#i', $value)) return $value;
        return rtrim((string)$site_url, '/') . '/' . ltrim($value, '/');
    }
}
