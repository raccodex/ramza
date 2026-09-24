<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class FundingService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    private function viewer(string $accessToken, string $clientId): array
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $id = (int)($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_funding', (string)$id, 120, 60);
        global $wo;
        $user = \Wo_UserData($id);
        if (!is_array($user) || empty($user['user_id'])) {
            throw new ApiException(401, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $wo['loggedin'] = true;
        $wo['user'] = $user;
        $wo['user']['id'] = $id;
        return [$id, $user];
    }

    private function media(string $value): string
    {
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        return function_exists('Wo_GetMedia') ? (string)\Wo_GetMedia($value) : $value;
    }

    private function name(array $user): string
    {
        $name = trim((string)($user['name'] ?? ''));
        if ($name !== '') {
            return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        return $name !== '' ? $name : (string)($user['username'] ?? '');
    }

    private function user(array $user, int $viewerId): array
    {
        $id = (int)($user['user_id'] ?? 0);
        $relation = 'none';
        if ($id === $viewerId) {
            $relation = 'self';
        } elseif ($id > 0 && function_exists('Wo_IsFollowing') && \Wo_IsFollowing($id, $viewerId)) {
            $relation = 'following';
        } elseif ($id > 0 && function_exists('Wo_IsFollowRequested') && \Wo_IsFollowRequested($id, $viewerId)) {
            $relation = 'requested';
        }
        return [
            'id' => $id,
            'username' => (string)($user['username'] ?? ''),
            'name' => $this->name($user),
            'avatar' => $this->media((string)($user['avatar'] ?? '')),
            'verified' => (int)($user['verified'] ?? 0) === 1,
            'relation' => $relation,
            'is_own' => $id === $viewerId,
            'is_online' => (int)($user['lastseen'] ?? 0) > time() - 60,
        ];
    }

    private function presentDonation(array $row, int $viewerId): array
    {
        $user = is_array($row['user_data'] ?? null)
            ? $row['user_data']
            : \Wo_UserData((int)($row['user_id'] ?? 0));
        return [
            'id' => (int)($row['id'] ?? 0),
            'amount' => (float)($row['amount'] ?? 0),
            'time' => (int)($row['time'] ?? 0),
            'user' => $this->user(is_array($user) ? $user : [], $viewerId),
        ];
    }

    private function present(array $row, int $viewerId, bool $withDonations = false): array
    {
        $id = (int)($row['id'] ?? 0);
        $user = is_array($row['user_data'] ?? null)
            ? $row['user_data']
            : \Wo_UserData((int)($row['user_id'] ?? 0));
        $donations = [];
        if ($withDonations) {
            $rawDonations = \GetRecentRaise($id, 20);
            foreach (is_array($rawDonations) ? $rawDonations : [] as $donation) {
                if (is_array($donation)) {
                    $donations[] = $this->presentDonation($donation, $viewerId);
                }
            }
        }
        global $db;
        $postId = (int)($db->where('fund_id', $id)->getValue(T_POSTS, 'id') ?? 0);
        return [
            'id' => $id,
            'hashed_id' => (string)($row['hashed_id'] ?? ''),
            'user_id' => (int)($row['user_id'] ?? 0),
            'post_id' => $postId,
            'title' => html_entity_decode(strip_tags((string)($row['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => html_entity_decode(strip_tags((string)($row['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'amount' => (float)($row['amount'] ?? 0),
            'raised' => (float)($row['raised'] ?? 0),
            'progress' => max(0.0, min(100.0, (float)($row['bar'] ?? 0))),
            'time' => (int)($row['time'] ?? 0),
            'image' => $this->media((string)($row['image'] ?? '')),
            'is_owner' => (int)($row['user_id'] ?? 0) === $viewerId,
            'is_donated' => (int)($row['is_donate'] ?? 0) > 0,
            'user' => $this->user(is_array($user) ? $user : [], $viewerId),
            'recent_donations' => $donations,
        ];
    }

    public function canCreate(array $user): bool
    {
        global $wo;
        $request = strtolower((string)($wo['config']['funding_request'] ?? 'all'));
        return $request === 'all'
            || ($request === 'verified' && (int)($user['verified'] ?? 0) === 1);
    }

    public function list(
        string $accessToken,
        string $clientId,
        bool $mine,
        int $afterId,
        int $limit
    ): array {
        [$viewerId] = $this->viewer($accessToken, $clientId);
        $limit = max(1, min(50, $limit));
        $rows = $mine
            ? \GetFundingByUserId($viewerId, $limit, max(0, $afterId))
            : \GetFunding($limit, max(0, $afterId));
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $items[] = $this->present($row, $viewerId);
            }
        }
        return $items;
    }

    public function get(string $accessToken, string $clientId, int $id): array
    {
        [$viewerId] = $this->viewer($accessToken, $clientId);
        $row = \GetFundingById($id);
        if (!is_array($row) || empty($row['id'])) {
            throw new ApiException(404, 'FUNDING_NOT_FOUND', 'This funding request is unavailable.');
        }
        return $this->present($row, $viewerId, true);
    }

    private function values(array $payload): array
    {
        $title = trim((string)($payload['title'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $amount = (float)($payload['amount'] ?? 0);
        if ($title === '') {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Please enter a title.', 'title');
        }
        if ($amount <= 0) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Please enter an amount.', 'amount');
        }
        if ($description === '') {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Please enter a description.', 'description');
        }
        return [
            'title' => \Wo_Secure($title),
            'description' => \Wo_Secure($description),
            'amount' => $amount,
        ];
    }

    public function create(string $accessToken, string $clientId, array $payload): array
    {
        [$viewerId, $user] = $this->viewer($accessToken, $clientId);
        if (!$this->canCreate($user)) {
            throw new ApiException(403, 'FUNDING_CREATE_FORBIDDEN', 'You are not allowed to create funding requests.');
        }
        $payload += $_POST;
        $values = $this->values($payload);
        if (empty($_FILES['image']['tmp_name'])) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Please select an image.', 'image');
        }
        $media = \Wo_ShareFile([
            'file' => $_FILES['image']['tmp_name'],
            'name' => $_FILES['image']['name'] ?? 'funding.jpg',
            'size' => $_FILES['image']['size'] ?? 0,
            'type' => $_FILES['image']['type'] ?? 'image/jpeg',
            'types' => 'jpeg,jpg,png,bmp',
        ]);
        if (!is_array($media) || empty($media['filename'])) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The selected image is not supported.', 'image');
        }
        global $db;
        $id = (int)$db->insert(T_FUNDING, $values + [
            'user_id' => $viewerId,
            'image' => $media['filename'],
            'time' => time(),
            'hashed_id' => \Wo_GenerateKey(15, 15),
        ]);
        if ($id < 1) {
            throw new ApiException(500, 'FUNDING_CREATE_FAILED', 'The funding request could not be created.');
        }
        $postId = (int)\Wo_RegisterPost([
            'user_id' => $viewerId,
            'fund_id' => $id,
            'time' => time(),
            'multi_image_post' => 0,
            'postPrivacy' => 0,
        ]);
        if ($postId < 1) {
            $db->where('id', $id)->delete(T_FUNDING);
            throw new ApiException(500, 'FUNDING_CREATE_FAILED', 'The funding post could not be created.');
        }
        return $this->get($accessToken, $clientId, $id);
    }

    public function update(
        string $accessToken,
        string $clientId,
        int $id,
        array $payload
    ): array {
        [$viewerId] = $this->viewer($accessToken, $clientId);
        global $db;
        $fund = $db->where('id', $id)->getOne(T_FUNDING);
        if (empty($fund)) {
            throw new ApiException(404, 'FUNDING_NOT_FOUND', 'This funding request is unavailable.');
        }
        if ((int)$fund->user_id !== $viewerId && !\Wo_IsAdmin() && !\Wo_IsModerator()) {
            throw new ApiException(403, 'FORBIDDEN', 'Only the funding owner can edit this request.');
        }
        $db->where('id', $id)->update(T_FUNDING, $this->values($payload + $_POST));
        return $this->get($accessToken, $clientId, $id);
    }

    public function delete(string $accessToken, string $clientId, int $id): array
    {
        [$viewerId] = $this->viewer($accessToken, $clientId);
        global $db;
        $fund = $db->where('id', $id)->getOne(T_FUNDING);
        if (empty($fund)) {
            throw new ApiException(404, 'FUNDING_NOT_FOUND', 'This funding request is unavailable.');
        }
        if ((int)$fund->user_id !== $viewerId && !\Wo_IsAdmin() && !\Wo_IsModerator()) {
            throw new ApiException(403, 'FORBIDDEN', 'Only the funding owner can delete this request.');
        }
        $posts = $db->where('fund_id', $id)->get(T_POSTS);
        foreach (is_array($posts) ? $posts : [] as $post) {
            if (function_exists('Wo_DeletePost')) {
                \Wo_DeletePost((int)$post->id);
            }
        }
        $raises = $db->where('funding_id', $id)->get(T_FUNDING_RAISE);
        foreach (is_array($raises) ? $raises : [] as $raise) {
            $raisePosts = $db->where('fund_raise_id', (int)$raise->id)->get(T_POSTS);
            foreach (is_array($raisePosts) ? $raisePosts : [] as $post) {
                if (function_exists('Wo_DeletePost')) {
                    \Wo_DeletePost((int)$post->id);
                }
            }
        }
        $db->where('funding_id', $id)->delete(T_FUNDING_RAISE);
        $db->where('id', $id)->delete(T_FUNDING);
        if (!empty($fund->image)) {
            @\Wo_DeleteFromToS3((string)$fund->image);
            if (is_file((string)$fund->image)) {
                @unlink((string)$fund->image);
            }
        }
        return ['deleted' => true];
    }

    public function donate(
        string $accessToken,
        string $clientId,
        int $id,
        float $amount
    ): array {
        [$viewerId, $viewer] = $this->viewer($accessToken, $clientId);
        if ($amount <= 0) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid donation amount.', 'amount');
        }
        global $db, $sqlConnect, $wo;
        $fund = $db->where('id', $id)->getOne(T_FUNDING);
        if (empty($fund)) {
            throw new ApiException(404, 'FUNDING_NOT_FOUND', 'This funding request is unavailable.');
        }
        if ((float)($viewer['wallet'] ?? 0) < $amount) {
            throw new ApiException(422, 'INSUFFICIENT_WALLET', 'Your wallet balance is too low.', 'amount');
        }

        $commission = max(0.0, (float)($wo['config']['donate_percentage'] ?? 0));
        $credited = $amount - (($commission * $amount) / 100);
        mysqli_begin_transaction($sqlConnect);
        try {
            $wallet = (float)$viewer['wallet'] - $amount;
            $db->where('user_id', $viewerId)->update(T_USERS, ['wallet' => $wallet]);
            $owner = \Wo_UserData((int)$fund->user_id);
            $db->where('user_id', (int)$fund->user_id)->update(T_USERS, [
                'balance' => (float)($owner['balance'] ?? 0) + $credited,
            ]);
            $raiseId = (int)$db->insert(T_FUNDING_RAISE, [
                'user_id' => $viewerId,
                'funding_id' => $id,
                'amount' => $credited,
                'time' => time(),
            ]);
            if ($raiseId < 1) {
                throw new \RuntimeException('Donation insert failed.');
            }
            \Wo_RegisterPost([
                'user_id' => $viewerId,
                'fund_raise_id' => $raiseId,
                'time' => time(),
                'multi_image_post' => 0,
            ]);
            mysqli_query(
                $sqlConnect,
                "INSERT INTO " . T_PAYMENT_TRANSACTIONS
                . " (`userid`,`kind`,`amount`,`notes`) VALUES ("
                . $viewerId . ",'DONATE'," . (float)$amount . ",'"
                . \Wo_Secure(mb_substr((string)$fund->title, 0, 100, 'UTF-8'))
                . "')"
            );
            \Wo_RegisterNotification([
                'recipient_id' => (int)$fund->user_id,
                'type' => 'fund_donate',
                'url' => 'index.php?link1=show_fund&id=' . (string)$fund->hashed_id,
            ]);
            mysqli_commit($sqlConnect);
            cache($viewerId, 'users', 'delete');
            cache((int)$fund->user_id, 'users', 'delete');
        } catch (\Throwable $error) {
            mysqli_rollback($sqlConnect);
            throw new ApiException(500, 'FUNDING_PAYMENT_FAILED', 'The donation could not be completed.');
        }
        return $this->get($accessToken, $clientId, $id);
    }
}
