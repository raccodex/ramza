<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class OfferService
{
    private const TYPES = [
        'discount_percent',
        'discount_amount',
        'buy_get_discount',
        'spend_get_off',
        'free_shipping',
    ];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    private function viewer(string $accessToken, string $clientId): int
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $id = (int) ($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_offers', (string) $id, 120, 60);
        global $wo;
        $user = \Wo_UserData($id);
        if (!is_array($user) || empty($user['user_id'])) {
            throw new ApiException(401, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $wo['loggedin'] = true;
        $wo['user'] = $user;
        $wo['user']['id'] = $id;
        return $id;
    }

    private function present(array $offer, int $viewerId): array
    {
        global $db;
        $raw = $db->where('id', (int) ($offer['id'] ?? 0))->getOne(T_OFFER);
        $page = is_array($offer['page'] ?? null)
            ? $offer['page']
            : \Wo_PageData((int) ($offer['page_id'] ?? 0));
        $page = is_array($page) ? $page : [];
        return [
            'id' => (int) ($offer['id'] ?? 0),
            'page_id' => (int) ($offer['page_id'] ?? 0),
            'user_id' => (int) ($offer['user_id'] ?? 0),
            'post_id' => (int) ($offer['post_id'] ?? 0),
            'discount_type' => (string) ($offer['discount_type'] ?? 'free_shipping'),
            'discount_percent' => (float) ($offer['discount_percent'] ?? 0),
            'discount_amount' => (float) ($offer['discount_amount'] ?? 0),
            'buy' => (float) ($offer['buy'] ?? 0),
            'get' => (float) ($offer['get_price'] ?? 0),
            'spend' => (float) ($offer['spend'] ?? 0),
            'amount_off' => (float) ($offer['amount_off'] ?? 0),
            'discounted_items' => html_entity_decode(strip_tags((string) ($offer['discounted_items'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => html_entity_decode(strip_tags((string) ($offer['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'expire_date' => (string) ($offer['expire_date'] ?? ''),
            'expire_time' => (string) ($offer['expire_time'] ?? ''),
            'image' => $this->media((string) ($raw->image ?? $offer['image'] ?? '')),
            // Wo_GetOfferById converts currency to "$" for display. The
            // mobile editor needs the stored ISO code for its dropdown.
            'currency' => SystemCurrency::normalize((string) ($raw->currency ?? '')),
            'offer_text' => (string) ($offer['offer_text'] ?? 'Free Shipping'),
            'is_owner' => (int) ($offer['user_id'] ?? 0) === $viewerId,
            'page' => [
                'id' => (int) ($page['page_id'] ?? 0),
                'username' => (string) ($page['page_name'] ?? ''),
                'title' => html_entity_decode((string) ($page['page_title'] ?? ''), ENT_QUOTES | ENT_HTML5),
                'avatar' => (string) ($page['avatar'] ?? ''),
            ],
        ];
    }

    private function media(string $value): string
    {
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        return function_exists('Wo_GetMedia') ? (string) \Wo_GetMedia($value) : $value;
    }

    public function list(string $accessToken, string $clientId, int $afterId, int $limit): array
    {
        $viewerId = $this->viewer($accessToken, $clientId);
        $rows = \Wo_GetAllOffers([
            'after_id' => max(0, $afterId),
            'limit' => max(1, min(50, $limit)),
        ]);
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
        $viewerId = $this->viewer($accessToken, $clientId);
        $offer = \Wo_GetOfferById($id);
        if (!is_array($offer) || empty($offer['id'])) {
            throw new ApiException(404, 'OFFER_NOT_FOUND', 'This offer is unavailable.');
        }
        return $this->present($offer, $viewerId);
    }

    private function values(array $payload): array
    {
        $type = (string) ($payload['discount_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Select a valid discount type.', 'discount_type');
        }
        $description = trim((string) ($payload['description'] ?? ''));
        $items = trim((string) ($payload['discounted_items'] ?? ''));
        if (mb_strlen($description) < 32) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Description must be at least 32 characters.', 'description');
        }
        if ($items === '' || mb_strlen($items) > 100) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Discounted items are required and must be 100 characters or less.', 'discounted_items');
        }
        if (trim((string) ($payload['expire_date'] ?? '')) === '' || trim((string) ($payload['expire_time'] ?? '')) === '') {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Expiry date and time are required.', 'expire_date');
        }
        $values = [
            'discount_type' => $type,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'buy' => 0,
            'get_price' => 0,
            'spend' => 0,
            'amount_off' => 0,
            'description' => \Wo_Secure($description),
            'discounted_items' => \Wo_Secure($items),
            'expire_date' => \Wo_Secure((string) $payload['expire_date']),
            'expire_time' => \Wo_Secure((string) $payload['expire_time']),
            'currency' => \Wo_Secure(SystemCurrency::normalize((string) ($payload['currency'] ?? ''))),
        ];
        $positive = static fn(array $source, string $key): float => max(0, (float) ($source[$key] ?? 0));
        if ($type === 'discount_percent') {
            $values['discount_percent'] = $positive($payload, 'discount_percent');
            if ($values['discount_percent'] < 1 || $values['discount_percent'] > 99) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Discount percent must be between 1 and 99.', 'discount_percent');
            }
        } elseif ($type === 'discount_amount') {
            $values['discount_amount'] = $positive($payload, 'discount_amount');
            if ($values['discount_amount'] < 1) throw new ApiException(422, 'VALIDATION_FAILED', 'Discount amount is required.', 'discount_amount');
        } elseif ($type === 'buy_get_discount') {
            $values['discount_percent'] = $positive($payload, 'discount_percent');
            $values['buy'] = $positive($payload, 'buy');
            $values['get_price'] = $positive($payload, 'get');
            if ($values['discount_percent'] < 1 || $values['discount_percent'] > 99 || $values['buy'] < 1 || $values['get_price'] < 1) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Buy, get and discount percent are required.', 'discount_percent');
            }
        } elseif ($type === 'spend_get_off') {
            $values['spend'] = $positive($payload, 'spend');
            $values['amount_off'] = $positive($payload, 'amount_off');
            if ($values['spend'] < 1 || $values['amount_off'] < 1) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Spend and amount off are required.', 'spend');
            }
        }
        return $values;
    }

    public function create(string $accessToken, string $clientId, array $payload): array
    {
        $viewerId = $this->viewer($accessToken, $clientId);
        $payload += $_POST;
        $pageId = (int) ($payload['page_id'] ?? 0);
        if ($pageId < 1 || !\Wo_IsPageOnwer($pageId)) {
            throw new ApiException(403, 'FORBIDDEN', 'Only a page owner can create an offer.');
        }
        if (empty($_FILES['thumbnail']['tmp_name'])) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose an offer image.', 'thumbnail');
        }
        $media = \Wo_ShareFile([
            'file' => $_FILES['thumbnail']['tmp_name'],
            'name' => $_FILES['thumbnail']['name'] ?? 'offer.jpg',
            'size' => $_FILES['thumbnail']['size'] ?? 0,
            'type' => $_FILES['thumbnail']['type'] ?? 'image/jpeg',
            'types' => 'jpeg,jpg,png,bmp',
        ]);
        if (!is_array($media) || empty($media['filename'])) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'The selected image is not supported.', 'thumbnail');
        }
        global $db;
        $values = $this->values($payload) + [
            'page_id' => $pageId,
            'user_id' => $viewerId,
            'image' => $media['filename'],
            'time' => time(),
        ];
        $offerId = (int) $db->insert(T_OFFER, $values);
        if ($offerId < 1) throw new ApiException(500, 'OFFER_CREATE_FAILED', 'The offer could not be created.');
        $summary = mb_substr((string) $values['description'], 0, 175, 'UTF-8');
        $postId = (int) $db->insert(T_POSTS, [
            'page_id' => $pageId,
            'postText' => $summary . (mb_strlen((string) $values['description']) > 175 ? '...' : ''),
            'offer_id' => $offerId,
            'postType' => 'offer',
            'postPrivacy' => 0,
            'time' => time(),
        ]);
        if ($postId > 0) $db->where('id', $postId)->update(T_POSTS, ['post_id' => $postId]);
        return $this->get($accessToken, $clientId, $offerId);
    }

    public function delete(string $accessToken, string $clientId, int $id): array
    {
        $viewerId = $this->viewer($accessToken, $clientId);
        global $db;
        $offer = $db->where('id', $id)->getOne(T_OFFER);
        if (empty($offer)) throw new ApiException(404, 'OFFER_NOT_FOUND', 'This offer is unavailable.');
        if ((int) $offer->user_id !== $viewerId && !\Wo_IsAdmin() && !\Wo_IsModerator()) {
            throw new ApiException(403, 'FORBIDDEN', 'Only the offer owner can delete this offer.');
        }
        $post = $db->where('offer_id', $id)->getOne(T_POSTS);
        if (!empty($post->id)) \Wo_DeletePost((int) $post->id);
        $db->where('id', $id)->delete(T_OFFER);
        return ['deleted' => true];
    }

    public function update(string $accessToken, string $clientId, int $id, array $payload): array
    {
        $viewerId = $this->viewer($accessToken, $clientId);
        $payload += $_POST;
        global $db;
        $offer = $db->where('id', $id)->getOne(T_OFFER);
        if (empty($offer)) {
            throw new ApiException(404, 'OFFER_NOT_FOUND', 'This offer is unavailable.');
        }
        if ((int) $offer->user_id !== $viewerId && !\Wo_IsAdmin() && !\Wo_IsModerator()) {
            throw new ApiException(403, 'FORBIDDEN', 'Only the offer owner can edit this offer.');
        }
        $values = $this->values($payload);
        // Xamarin EditOffers only changes discount type/values and
        // description. Preserve the creation-only fields even when an older
        // client still sends a display currency symbol such as "$".
        $values['discounted_items'] = (string) $offer->discounted_items;
        $values['expire_date'] = (string) $offer->expire_date;
        $values['expire_time'] = (string) $offer->expire_time;
        $values['currency'] = (string) $offer->currency;
        if (!empty($_FILES['thumbnail']['tmp_name'])) {
            $media = \Wo_ShareFile([
                'file' => $_FILES['thumbnail']['tmp_name'],
                'name' => $_FILES['thumbnail']['name'] ?? 'offer.jpg',
                'size' => $_FILES['thumbnail']['size'] ?? 0,
                'type' => $_FILES['thumbnail']['type'] ?? 'image/jpeg',
                'types' => 'jpeg,jpg,png,bmp',
            ]);
            if (!is_array($media) || empty($media['filename'])) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'The selected image is not supported.', 'thumbnail');
            }
            $values['image'] = $media['filename'];
        }
        $db->where('id', $id)->update(T_OFFER, $values);
        $summary = mb_substr((string) $values['description'], 0, 175, 'UTF-8');
        $db->where('offer_id', $id)->update(T_POSTS, [
            'postText' => $summary . (mb_strlen((string) $values['description']) > 175 ? '...' : ''),
            'postType' => 'offer',
        ]);
        return $this->get($accessToken, $clientId, $id);
    }
}
