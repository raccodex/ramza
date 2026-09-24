<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/**
 * Native mobile advertising API.
 *
 * This intentionally mirrors Xamarin's CreateAdvertiseActivity and
 * PostType_Ads rather than sending the mobile app through the web ads pages.
 */
final class AdvertisementService
{
    private const PLACEMENTS = [
        'post', 'sidebar', 'jobs', 'forum', 'movies', 'offer', 'funding', 'story',
    ];
    private const BIDDING = ['clicks', 'views'];
    private const MEDIA_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
        'video/mp4', 'video/quicktime', 'video/webm', 'video/mpeg', 'video/x-msvideo',
    ];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    private function viewer(string $accessToken, string $clientId): array
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $id = (int)($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_advertising', (string)$id, 120, 60);
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

    private function publisher(array $advertisement): array
    {
        $pageId = (int)($advertisement['page_id'] ?? 0);
        if ($pageId > 0) {
            $page = \Wo_PageData($pageId);
            if (is_array($page) && !empty($page['page_id'])) {
                return [
                    'id' => (int)$page['page_id'],
                    'type' => 'page',
                    'username' => (string)($page['page_name'] ?? ''),
                    'name' => html_entity_decode((string)($page['page_title'] ?? ''), ENT_QUOTES | ENT_HTML5),
                    'avatar' => (string)($page['avatar'] ?? ''),
                    'verified' => !empty($page['verified']),
                ];
            }
        }
        $user = is_array($advertisement['user_data'] ?? null)
            ? $advertisement['user_data']
            : \Wo_UserData((int)($advertisement['user_id'] ?? 0));
        $user = is_array($user) ? $user : [];
        return [
            'id' => (int)($user['user_id'] ?? 0),
            'type' => 'user',
            'username' => (string)($user['username'] ?? ''),
            'name' => trim((string)($user['name'] ?? (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')))),
            'avatar' => (string)($user['avatar'] ?? ''),
            'verified' => !empty($user['verified']),
        ];
    }

    public function present(array $advertisement, int $viewerId): array
    {
        $media = $this->media((string)($advertisement['ad_media'] ?? ''));
        $path = strtolower((string)(parse_url($media, PHP_URL_PATH) ?? $media));
        $video = preg_match('/\.(mp4|mov|m4v|webm|mpeg|avi)$/', $path) === 1;
        $audience = array_values(array_filter(array_map(
            static fn(string $value): string => trim($value),
            explode(',', (string)($advertisement['audience'] ?? ''))
        )));
        return [
            'id' => (int)($advertisement['id'] ?? 0),
            'user_id' => (int)($advertisement['user_id'] ?? 0),
            'page_id' => (int)($advertisement['page_id'] ?? 0),
            'name' => html_entity_decode((string)($advertisement['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
            'website' => (string)($advertisement['url'] ?? ''),
            'headline' => html_entity_decode((string)($advertisement['headline'] ?? ''), ENT_QUOTES | ENT_HTML5),
            'description' => html_entity_decode(strip_tags((string)($advertisement['description'] ?? '')), ENT_QUOTES | ENT_HTML5),
            'location' => html_entity_decode((string)($advertisement['location'] ?? ''), ENT_QUOTES | ENT_HTML5),
            'audience' => $audience,
            'gender' => (string)($advertisement['gender'] ?? 'all'),
            'bidding' => (string)($advertisement['bidding'] ?? 'clicks'),
            'placement' => (string)($advertisement['appears'] ?? 'post'),
            'posted' => (int)($advertisement['posted'] ?? 0),
            'start' => (string)($advertisement['start'] ?? ''),
            'end' => (string)($advertisement['end'] ?? ''),
            'budget' => (float)($advertisement['budget'] ?? 0),
            'spent' => (float)($advertisement['spent'] ?? 0),
            'clicks' => (int)($advertisement['clicks'] ?? 0),
            'views' => (int)($advertisement['views'] ?? 0),
            'status' => (int)($advertisement['status'] ?? 0),
            'media_url' => $media,
            'media_type' => $video ? 'video' : 'image',
            'is_owner' => (int)($advertisement['user_id'] ?? 0) === $viewerId,
            'publisher' => $this->publisher($advertisement),
        ];
    }

    public function configuration(string $accessToken, string $clientId): array
    {
        [$viewerId, $user] = $this->viewer($accessToken, $clientId);
        global $wo, $db;
        $pages = [];
        $rows = $db->where('user_id', $viewerId)->orderBy('page_id', 'DESC')->get(T_PAGES, 100);
        foreach (is_array($rows) ? $rows : [] as $page) {
            $data = (array)$page;
            $pages[] = [
                'id' => (int)($data['page_id'] ?? 0),
                'username' => (string)($data['page_name'] ?? ''),
                'title' => html_entity_decode((string)($data['page_title'] ?? ''), ENT_QUOTES | ENT_HTML5),
                'avatar' => $this->media((string)($data['avatar'] ?? '')),
                'website' => \Wo_SeoLink('index.php?link1=timeline&u=' . (string)($data['page_name'] ?? '')),
            ];
        }
        $countries = [];
        foreach ((array)($wo['countries_name'] ?? []) as $id => $name) {
            $countries[] = ['id' => (string)$id, 'name' => html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5)];
        }
        $genders = [['id' => 'all', 'name' => 'All']];
        foreach ((array)($wo['genders'] ?? []) as $id => $gender) {
            $name = is_array($gender) ? (string)($gender['name'] ?? $id) : (string)$gender;
            $genders[] = ['id' => (string)$id, 'name' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5)];
        }
        return [
            'enabled' => (int)($wo['config']['user_ads'] ?? 1) === 1,
            'wallet' => (float)($user['wallet'] ?? 0),
            'currency' => SystemCurrency::code(),
            'currency_symbol' => SystemCurrency::symbol(),
            'max_upload_bytes' => (int)($wo['config']['maxUpload'] ?? 0),
            'pages' => $pages,
            'countries' => $countries,
            'genders' => $genders,
            'placements' => self::PLACEMENTS,
            'bidding' => self::BIDDING,
        ];
    }

    public function list(string $accessToken, string $clientId, int $afterId, int $limit): array
    {
        [$viewerId] = $this->viewer($accessToken, $clientId);
        $rows = \Wo_GetMyAds([
            'offset' => max(0, $afterId),
            'limit' => max(1, min(50, $limit)),
        ]);
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $items[] = $this->present($row, $viewerId);
            }
        }
        return [
            'items' => $items,
            'next_after' => empty($items) ? 0 : (int)$items[count($items) - 1]['id'],
        ];
    }

    public function get(string $accessToken, string $clientId, int $id): array
    {
        [$viewerId] = $this->viewer($accessToken, $clientId);
        $advertisement = \Wo_GetUserAdData($id);
        if (!is_array($advertisement) || empty($advertisement['id'])) {
            throw new ApiException(404, 'ADVERTISEMENT_NOT_FOUND', 'This advertisement is unavailable.');
        }
        return $this->present($advertisement, $viewerId);
    }

    private function values(array $payload, int $viewerId): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        $website = trim((string)($payload['website'] ?? $payload['url'] ?? ''));
        $headline = trim((string)($payload['headline'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $location = trim((string)($payload['location'] ?? ''));
        $audienceValue = $payload['audience'] ?? $payload['audience-list'] ?? [];
        $audience = is_array($audienceValue)
            ? $audienceValue
            : explode(',', (string)$audienceValue);
        $audience = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $audience
        ))));
        $gender = trim((string)($payload['gender'] ?? 'all'));
        $bidding = trim((string)($payload['bidding'] ?? ''));
        $placement = trim((string)($payload['placement'] ?? $payload['appears'] ?? ''));
        $start = trim((string)($payload['start'] ?? ''));
        $end = trim((string)($payload['end'] ?? ''));
        $budget = max(0, (float)($payload['budget'] ?? 0));
        $pageId = max(0, (int)($payload['page_id'] ?? 0));

        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Company name must be 3 to 100 characters.', 'name');
        }
        if (filter_var($website, FILTER_VALIDATE_URL) === false || mb_strlen($website) > 3000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid website URL.', 'website');
        }
        if (mb_strlen($headline) < 5 || mb_strlen($headline) > 200) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Headline must be 5 to 200 characters.', 'headline');
        }
        if ($description === '' || mb_strlen($description) > 2000) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Description is required and must be at most 2000 characters.', 'description');
        }
        if ($location === '') {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Location is required.', 'location');
        }
        if (empty($audience)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Select at least one audience country.', 'audience');
        }
        if (!in_array($bidding, self::BIDDING, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Select clicks or views bidding.', 'bidding');
        }
        if (!in_array($placement, self::PLACEMENTS, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Select a valid placement.', 'placement');
        }
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($end) < strtotime($start)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Select a valid start and end date.', 'start');
        }
        if ($budget <= 0) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Budget must be greater than zero.', 'budget');
        }
        if ($pageId > 0 && !\Wo_IsPageOnwer($pageId)) {
            throw new ApiException(403, 'FORBIDDEN', 'Only a page owner can advertise this page.', 'page_id');
        }
        return [
            'name' => \Wo_Secure($name),
            'url' => \Wo_Secure($website),
            'headline' => \Wo_Secure($headline),
            'description' => \Wo_Secure($description),
            'location' => \Wo_Secure($location),
            'audience' => \Wo_Secure(implode(',', $audience)),
            'gender' => \Wo_Secure($gender),
            'bidding' => $bidding,
            'appears' => $placement,
            'page_id' => $pageId,
            'start' => \Wo_Secure($start),
            'end' => \Wo_Secure($end),
            'budget' => $budget,
            'user_id' => $viewerId,
        ];
    }

    private function uploadedMedia(bool $required): ?string
    {
        if (empty($_FILES['media']['tmp_name'])) {
            if ($required) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Choose an advertisement image or video.', 'media');
            }
            return null;
        }
        global $wo;
        $size = (int)($_FILES['media']['size'] ?? 0);
        $maximum = (int)($wo['config']['maxUpload'] ?? 0);
        if ($size < 1 || ($maximum > 0 && $size > $maximum)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The selected media exceeds the upload limit.', 'media');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$_FILES['media']['tmp_name']);
        if (!is_string($mime) || !in_array($mime, self::MEDIA_TYPES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Select a supported image or video.', 'media');
        }
        $shared = \Wo_ShareFile([
            'file' => $_FILES['media']['tmp_name'],
            'name' => $_FILES['media']['name'] ?? ($mime === 'video/mp4' ? 'advertisement.mp4' : 'advertisement.jpg'),
            'size' => $size,
            'type' => $mime,
            'types' => 'jpg,jpeg,png,bmp,gif,webp,mp4,avi,mov,webm,mpeg',
            'compress' => false,
        ]);
        if (!is_array($shared) || empty($shared['filename'])) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The selected media could not be uploaded.', 'media');
        }
        return (string)$shared['filename'];
    }

    public function create(string $accessToken, string $clientId, array $payload): array
    {
        [$viewerId, $user] = $this->viewer($accessToken, $clientId);
        $payload += $_POST;
        if ((float)($user['wallet'] ?? 0) <= 0) {
            throw new ApiException(422, 'ADVERTISEMENT_WALLET_EMPTY', 'Add funds to your wallet before creating an advertisement.');
        }
        $values = $this->values($payload, $viewerId);
        $values['posted'] = time();
        $values['ad_media'] = $this->uploadedMedia(true);
        global $db;
        $id = (int)$db->insert(T_USER_ADS, $values);
        if ($id < 1) {
            throw new ApiException(500, 'ADVERTISEMENT_CREATE_FAILED', 'The advertisement could not be created.');
        }
        return $this->get($accessToken, $clientId, $id);
    }

    public function update(string $accessToken, string $clientId, int $id, array $payload): array
    {
        [$viewerId] = $this->viewer($accessToken, $clientId);
        $payload += $_POST;
        global $db;
        $existing = $db->where('id', $id)->getOne(T_USER_ADS);
        if (empty($existing)) {
            throw new ApiException(404, 'ADVERTISEMENT_NOT_FOUND', 'This advertisement is unavailable.');
        }
        if ((int)$existing->user_id !== $viewerId && !\Wo_IsAdmin() && !\Wo_IsModerator()) {
            throw new ApiException(403, 'FORBIDDEN', 'Only the advertisement owner can edit it.');
        }
        $values = $this->values($payload, $viewerId);
        $media = $this->uploadedMedia(false);
        if ($media !== null) {
            $values['ad_media'] = $media;
        }
        $db->where('id', $id)->update(T_USER_ADS, $values);
        return $this->get($accessToken, $clientId, $id);
    }

    public function delete(string $accessToken, string $clientId, int $id): array
    {
        [$viewerId] = $this->viewer($accessToken, $clientId);
        $advertisement = \Wo_GetUserAdData($id);
        if (!is_array($advertisement) || empty($advertisement['id'])) {
            throw new ApiException(404, 'ADVERTISEMENT_NOT_FOUND', 'This advertisement is unavailable.');
        }
        if ((int)($advertisement['user_id'] ?? 0) !== $viewerId && !\Wo_IsAdmin() && !\Wo_IsModerator()) {
            throw new ApiException(403, 'FORBIDDEN', 'Only the advertisement owner can delete it.');
        }
        \Wo_DeleteUserAd($id);
        return ['deleted' => true];
    }

    public function click(string $accessToken, string $clientId, int $id): array
    {
        $this->viewer($accessToken, $clientId);
        $advertisement = \Wo_GetUserAdData($id);
        if (!is_array($advertisement) || empty($advertisement['id'])) {
            throw new ApiException(404, 'ADVERTISEMENT_NOT_FOUND', 'This advertisement is unavailable.');
        }
        \Wo_RegisterAdConversionClick($id);
        return ['registered' => true, 'website' => (string)($advertisement['url'] ?? '')];
    }

    public function test(string $accessToken, string $clientId): array
    {
        [$viewerId, $user] = $this->viewer($accessToken, $clientId);
        if (!\Wo_IsAdmin() && !\Wo_IsModerator()) {
            throw new ApiException(403, 'FORBIDDEN', 'Only an administrator can create the test advertisement.');
        }
        global $db, $wo;
        $name = 'Ramza Mobile Advertisement Test';
        $existing = $db->where('user_id', $viewerId)->where('name', $name)->getOne(T_USER_ADS);
        if (!empty($existing->id)) {
            return $this->get($accessToken, $clientId, (int)$existing->id);
        }
        $country = (string)($user['country_id'] ?? '0');
        $media = (string)($user['avatar_org'] ?? $user['avatar'] ?? '');
        $id = (int)$db->insert(T_USER_ADS, [
            'name' => $name,
            'url' => (string)($wo['config']['site_url'] ?? ''),
            'headline' => 'Discover Ramza Social',
            'description' => 'Native mobile advertisement used to verify the Xamarin-compatible advertising card, targeting, click tracking, and responsive media layout.',
            'location' => (string)($user['address'] ?? 'Global'),
            'audience' => $country,
            'gender' => 'all',
            'bidding' => 'clicks',
            'appears' => 'post',
            'user_id' => $viewerId,
            'page_id' => 0,
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('+30 days')),
            'budget' => 100,
            'posted' => time(),
            'ad_media' => $media,
            'status' => 1,
        ]);
        if ($id < 1) {
            throw new ApiException(500, 'ADVERTISEMENT_CREATE_FAILED', 'The test advertisement could not be created.');
        }
        return $this->get($accessToken, $clientId, $id);
    }
}
