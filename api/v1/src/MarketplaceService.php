<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class MarketplaceService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    public function configuration(string $token, string $clientId): array
    {
        $this->authenticate($token, $clientId, 'mobile_marketplace_config');
        global $wo;
        $categories = [];
        foreach ((array)($wo['products_categories'] ?? []) as $id => $name) {
            if ((int)$id > 0) {
                $categories[] = ['id' => (int)$id, 'name' => (string)$name];
            }
        }
        $currencies = SystemCurrency::options();
        return ['categories' => $categories, 'currencies' => $currencies];
    }

    public function products(
        string $token,
        string $clientId,
        string $scope,
        string $keyword,
        int $category,
        int $afterId,
        int $limit,
        int $distance
    ): array {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_list');
        $limit = max(1, min(40, $limit));
        $scope = strtolower(trim($scope));
        if ($scope === 'purchased') {
            return $this->purchased($viewerId, $afterId, $limit);
        }
        $filter = ['limit' => $limit];
        if ($afterId > 0) $filter['after_id'] = $afterId;
        if ($category > 0) $filter['c_id'] = $category;
        if (trim($keyword) !== '') $filter['keyword'] = mb_substr(trim($keyword), 0, 100);
        if ($scope === 'mine') $filter['user_id'] = $viewerId;
        if ($distance > 0) $filter['length'] = min(1000, $distance);
        $items = function_exists('Wo_GetProducts') ? \Wo_GetProducts($filter) : [];
        return array_values(array_map(fn(array $item): array => $this->product($item, $viewerId), (array)$items));
    }

    public function detail(string $token, string $clientId, int $id): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_detail');
        $item = function_exists('Wo_GetProduct') ? \Wo_GetProduct($id) : [];
        if (!is_array($item) || empty($item['id']) || (int)($item['active'] ?? 0) !== 1) {
            throw new ApiException(404, 'PRODUCT_NOT_FOUND', 'The product was not found.');
        }
        return $this->product($item, $viewerId);
    }

    public function create(
        string $token,
        string $clientId,
        array $fields,
        ?array $uploads
    ): array {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_create');
        $data = $this->validate($fields);
        $files = $this->files($uploads);
        if ($files === []) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Add at least one product image.', 'images');
        }
        global $sqlConnect;
        $stmt = $sqlConnect->prepare(
            'INSERT INTO Wo_Products '
            . '(user_id,name,description,category,price,location,status,type,currency,units,time,active) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,1)'
        );
        if ($stmt === false) throw new ApiException(503, 'PRODUCT_CREATE_FAILED', 'The product could not be created.');
        $now = time();
        $status = 0;
        $stmt->bind_param(
            'issidsiisii',
            $viewerId,
            $data['name'],
            $data['description'],
            $data['category'],
            $data['price'],
            $data['location'],
            $status,
            $data['type'],
            $data['currency'],
            $data['units'],
            $now
        );
        $stmt->execute();
        $productId = (int)$stmt->insert_id;
        $stmt->close();
        if ($productId < 1) throw new ApiException(503, 'PRODUCT_CREATE_FAILED', 'The product could not be created.');

        $postId = (int)\Wo_RegisterPost([
            'user_id' => $viewerId,
            'product_id' => $productId,
            'postPrivacy' => 0,
            'time' => $now,
        ]);
        try {
            foreach ($files as $file) {
                $shared = \Wo_ShareFile([
                    'file' => $file['tmp_name'],
                    'name' => $file['name'],
                    'size' => $file['size'],
                    'type' => $file['type'],
                    'types' => 'jpg,png,jpeg,gif,webp',
                ]);
                if (!is_array($shared) || empty($shared['filename'])) {
                    throw new ApiException(422, 'IMAGE_UPLOAD_FAILED', 'One of the product images could not be uploaded.', 'images');
                }
                \Wo_RegisterProductMedia($productId, (string)$shared['filename']);
            }
        } catch (\Throwable $error) {
            $sqlConnect->query('DELETE FROM Wo_Products_Media WHERE product_id=' . $productId);
            $sqlConnect->query('DELETE FROM Wo_Posts WHERE product_id=' . $productId);
            $sqlConnect->query('DELETE FROM Wo_Products WHERE id=' . $productId);
            if ($error instanceof ApiException) throw $error;
            throw new ApiException(503, 'IMAGE_UPLOAD_FAILED', 'The product images could not be uploaded.');
        }
        $item = \Wo_GetProduct($productId);
        $item['post_id'] = $postId ?: ($item['post_id'] ?? 0);
        return $this->product($item, $viewerId);
    }

    public function update(
        string $token,
        string $clientId,
        int $id,
        array $fields,
        ?array $uploads
    ): array {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_update');
        $this->assertOwner($id, $viewerId);
        $data = $this->validate($fields);
        global $sqlConnect;
        $stmt = $sqlConnect->prepare(
            'UPDATE Wo_Products SET name=?,description=?,category=?,price=?,location=?,type=?,currency=?,units=? '
            . 'WHERE id=? AND user_id=? LIMIT 1'
        );
        $stmt->bind_param(
            'ssidsisiii',
            $data['name'],
            $data['description'],
            $data['category'],
            $data['price'],
            $data['location'],
            $data['type'],
            $data['currency'],
            $data['units'],
            $id,
            $viewerId
        );
        $stmt->execute();
        $stmt->close();
        foreach ($this->files($uploads) as $file) {
            $shared = \Wo_ShareFile([
                'file' => $file['tmp_name'], 'name' => $file['name'],
                'size' => $file['size'], 'type' => $file['type'],
                'types' => 'jpg,png,jpeg,gif,webp',
            ]);
            if (is_array($shared) && !empty($shared['filename'])) {
                \Wo_RegisterProductMedia($id, (string)$shared['filename']);
            }
        }
        return $this->detail($token, $clientId, $id);
    }

    public function toggleCart(string $token, string $clientId, int $id): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_cart');
        $this->assertExists($id);
        global $sqlConnect;
        $check = $sqlConnect->prepare('SELECT id FROM Wo_UserCard WHERE user_id=? AND product_id=? LIMIT 1');
        $check->bind_param('ii', $viewerId, $id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();
        if (is_array($row)) {
            $stmt = $sqlConnect->prepare('DELETE FROM Wo_UserCard WHERE user_id=? AND product_id=?');
            $added = false;
        } else {
            $stmt = $sqlConnect->prepare('INSERT INTO Wo_UserCard (user_id,product_id,units) VALUES (?,?,1)');
            $added = true;
        }
        $stmt->bind_param('ii', $viewerId, $id);
        $stmt->execute();
        $stmt->close();
        return ['added_to_cart' => $added];
    }

    public function cart(string $token, string $clientId): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_cart_read');
        global $sqlConnect, $wo;
        $stmt = $sqlConnect->prepare('SELECT product_id,units FROM Wo_UserCard WHERE user_id=? ORDER BY id ASC');
        $stmt->bind_param('i', $viewerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $items = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $product = \Wo_GetProduct((int)$row['product_id']);
            if (!is_array($product) || empty($product['id']) || (int)($product['active'] ?? 0) !== 1) continue;
            $quantity = max(1, (int)$row['units']);
            $unitPrice = $this->convertedPrice($product);
            $lineTotal = $unitPrice * $quantity;
            $total += $lineTotal;
            $items[] = [
                'product' => $this->product($product, $viewerId),
                'quantity' => $quantity,
                'line_total' => round($lineTotal, 2),
            ];
        }
        return [
            'items' => $items,
            'total' => round($total, 2),
            'currency' => SystemCurrency::code(),
            'currency_symbol' => SystemCurrency::symbol(),
            'wallet' => (float)($wo['user']['wallet'] ?? 0),
        ];
    }

    public function updateCartQuantity(
        string $token,
        string $clientId,
        int $productId,
        int $quantity
    ): array {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_cart_update');
        $product = function_exists('Wo_GetProduct') ? \Wo_GetProduct($productId) : [];
        if (!is_array($product) || empty($product['id'])) {
            throw new ApiException(404, 'PRODUCT_NOT_FOUND', 'The product was not found.');
        }
        $quantity = max(1, $quantity);
        if ($quantity > (int)($product['units'] ?? 0)) {
            throw new ApiException(422, 'QUANTITY_UNAVAILABLE', 'The requested quantity is not available.', 'quantity');
        }
        global $sqlConnect;
        $stmt = $sqlConnect->prepare('UPDATE Wo_UserCard SET units=? WHERE user_id=? AND product_id=? LIMIT 1');
        $stmt->bind_param('iii', $quantity, $viewerId, $productId);
        $stmt->execute();
        $stmt->close();
        return $this->cart($token, $clientId);
    }

    public function checkout(string $token, string $clientId, int $addressId): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_checkout');
        if ($addressId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a shipping address.', 'address_id');
        }
        global $sqlConnect, $wo;
        $address = $sqlConnect->prepare('SELECT id FROM Wo_UserAddress WHERE id=? AND user_id=? LIMIT 1');
        $address->bind_param('ii', $addressId, $viewerId);
        $address->execute();
        $validAddress = $address->get_result()->fetch_assoc();
        $address->close();
        if (!is_array($validAddress)) {
            throw new ApiException(404, 'ADDRESS_NOT_FOUND', 'The shipping address was not found.');
        }
        $cart = $this->cart($token, $clientId);
        if (empty($cart['items'])) {
            throw new ApiException(422, 'EMPTY_CART', 'Your cart is empty.');
        }
        if ((float)$cart['wallet'] < (float)$cart['total']) {
            throw new ApiException(422, 'INSUFFICIENT_WALLET', 'Please top up your wallet before checkout.');
        }

        $bySeller = [];
        foreach ($cart['items'] as $line) {
            $product = $line['product'];
            $sellerId = (int)($product['seller']['id'] ?? 0);
            if ($sellerId < 1 || $sellerId === $viewerId) {
                throw new ApiException(422, 'CHECKOUT_FAILED', 'You cannot purchase this product.');
            }
            $bySeller[$sellerId][] = $line;
        }

        $sqlConnect->begin_transaction();
        $orderHashes = [];
        try {
            $wallet = $sqlConnect->prepare('UPDATE Wo_Users SET wallet=wallet-? WHERE user_id=? AND wallet>=? LIMIT 1');
            $grandTotal = (float)$cart['total'];
            $wallet->bind_param('did', $grandTotal, $viewerId, $grandTotal);
            $wallet->execute();
            if ($wallet->affected_rows !== 1) {
                $wallet->close();
                throw new ApiException(422, 'INSUFFICIENT_WALLET', 'Please top up your wallet before checkout.');
            }
            $wallet->close();

            foreach ($bySeller as $sellerId => $lines) {
                $hash = bin2hex(random_bytes(12));
                $orderHashes[] = $hash;
                $sellerTotal = 0.0;
                $sellerCommission = 0.0;
                foreach ($lines as $line) {
                    $product = $line['product'];
                    $productId = (int)$product['id'];
                    $quantity = (int)$line['quantity'];
                    $price = (float)$line['line_total'];
                    $commission = round(((float)($wo['config']['store_commission'] ?? 0) * $price) / 100, 2);
                    $final = $price - $commission;
                    $stock = $sqlConnect->prepare('UPDATE Wo_Products SET units=units-? WHERE id=? AND units>=? LIMIT 1');
                    $stock->bind_param('iii', $quantity, $productId, $quantity);
                    $stock->execute();
                    if ($stock->affected_rows !== 1) {
                        $stock->close();
                        throw new ApiException(422, 'QUANTITY_UNAVAILABLE', 'One of the products is no longer available.');
                    }
                    $stock->close();
                    $order = $sqlConnect->prepare(
                        'INSERT INTO Wo_UserOrders '
                        . '(user_id,product_owner_id,product_id,price,commission,final_price,hash_id,units,status,address_id,time) '
                        . "VALUES (?,?,?,?,?,?,?,?,'placed',?,?)"
                    );
                    $now = time();
                    $order->bind_param(
                        'iiidddsiii',
                        $viewerId, $sellerId, $productId, $price, $commission,
                        $final, $hash, $quantity, $addressId, $now
                    );
                    $order->execute();
                    $order->close();
                    $sellerTotal += $price;
                    $sellerCommission += $commission;
                }
                $finalTotal = $sellerTotal - $sellerCommission;
                $purchase = $sqlConnect->prepare(
                    'INSERT INTO Wo_Purchases '
                    . '(user_id,order_hash_id,price,data,commission,final_price,time) VALUES (?,?,?,?,?,?,?)'
                );
                $data = json_encode(['name' => (string)($lines[0]['product']['name'] ?? '')], JSON_UNESCAPED_UNICODE);
                $now = time();
                $purchase->bind_param(
                    'isdsddi',
                    $viewerId, $hash, $sellerTotal, $data, $sellerCommission, $finalTotal, $now
                );
                $purchase->execute();
                $purchase->close();
            }
            $clear = $sqlConnect->prepare('DELETE FROM Wo_UserCard WHERE user_id=?');
            $clear->bind_param('i', $viewerId);
            $clear->execute();
            $clear->close();
            $sqlConnect->commit();
        } catch (\Throwable $error) {
            $sqlConnect->rollback();
            if ($error instanceof ApiException) throw $error;
            throw new ApiException(503, 'CHECKOUT_FAILED', 'The order could not be completed.');
        }
        return [
            'placed' => true,
            'order_hashes' => $orderHashes,
            'total' => (float)$cart['total'],
            'currency' => (string)$cart['currency'],
        ];
    }

    public function delete(string $token, string $clientId, int $id): array
    {
        $viewerId = $this->authenticate($token, $clientId, 'mobile_marketplace_delete');
        $this->assertOwner($id, $viewerId);
        global $sqlConnect;
        $stmt = $sqlConnect->prepare("UPDATE Wo_Products SET active='0' WHERE id=? AND user_id=? LIMIT 1");
        $stmt->bind_param('ii', $id, $viewerId);
        $stmt->execute();
        $stmt->close();
        return ['deleted' => true];
    }

    private function purchased(int $viewerId, int $afterId, int $limit): array
    {
        global $sqlConnect;
        $where = $afterId > 0 ? ' AND o.id<?' : '';
        $stmt = $sqlConnect->prepare(
            'SELECT DISTINCT p.id FROM Wo_UserOrders o INNER JOIN Wo_Products p ON p.id=o.product_id '
            . "WHERE o.user_id=? AND p.active='1'{$where} ORDER BY o.id DESC LIMIT ?"
        );
        if ($afterId > 0) $stmt->bind_param('iii', $viewerId, $afterId, $limit);
        else $stmt->bind_param('ii', $viewerId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $result = [];
        foreach ($rows as $row) {
            $item = \Wo_GetProduct((int)$row['id']);
            if (is_array($item) && !empty($item['id'])) $result[] = $this->product($item, $viewerId);
        }
        return $result;
    }

    private function validate(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $location = trim((string)($input['location'] ?? ''));
        $currency = SystemCurrency::normalize((string)($input['currency'] ?? ''));
        $category = (int)($input['category'] ?? 0);
        $price = (float)($input['price'] ?? 0);
        $type = (int)($input['type'] ?? 0) === 1 ? 1 : 0;
        $units = max(1, (int)($input['units'] ?? 1));
        if ($name === '' || mb_strlen($name) > 100) throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a product name.', 'name');
        if ($description === '') throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a product description.', 'description');
        if ($category < 1) throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a category.', 'category');
        if ($price <= 0) throw new ApiException(422, 'VALIDATION_FAILED', 'Enter a valid price.', 'price');
        if ($currency === '' || strlen($currency) > 40) throw new ApiException(422, 'VALIDATION_FAILED', 'Choose a currency.', 'currency');
        return compact('name', 'description', 'location', 'currency', 'category', 'price', 'type', 'units');
    }

    private function files(?array $uploads): array
    {
        if (!is_array($uploads) || empty($uploads['name'])) return [];
        $names = is_array($uploads['name']) ? $uploads['name'] : [$uploads['name']];
        $tmp = is_array($uploads['tmp_name'] ?? null) ? $uploads['tmp_name'] : [$uploads['tmp_name'] ?? ''];
        $sizes = is_array($uploads['size'] ?? null) ? $uploads['size'] : [$uploads['size'] ?? 0];
        $types = is_array($uploads['type'] ?? null) ? $uploads['type'] : [$uploads['type'] ?? ''];
        $errors = is_array($uploads['error'] ?? null) ? $uploads['error'] : [$uploads['error'] ?? UPLOAD_ERR_OK];
        $result = [];
        foreach ($names as $i => $name) {
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $extension = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'Only JPG, PNG, GIF, or WebP images are allowed.', 'images');
            }
            if ((int)($sizes[$i] ?? 0) > 15 * 1024 * 1024) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'A product image is too large.', 'images');
            }
            $result[] = [
                'name' => basename((string)$name), 'tmp_name' => (string)($tmp[$i] ?? ''),
                'size' => (int)($sizes[$i] ?? 0), 'type' => (string)($types[$i] ?? ''),
            ];
        }
        return $result;
    }

    private function product(array $item, int $viewerId): array
    {
        global $wo;
        $seller = (array)($item['user_data'] ?? $item['seller'] ?? []);
        $categoryId = (int)($item['category'] ?? 0);
        $images = [];
        foreach ((array)($item['images'] ?? []) as $image) {
            $url = is_array($image) ? (string)($image['image'] ?? '') : (string)$image;
            if ($url !== '') $images[] = $this->media($url);
        }
        $first = trim((string)($seller['first_name'] ?? ''));
        $last = trim((string)($seller['last_name'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') $name = (string)($seller['name'] ?? $seller['username'] ?? '');
        return [
            'id' => (int)($item['id'] ?? 0),
            'post_id' => (int)($item['post_id'] ?? 0),
            'name' => html_entity_decode((string)($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => strip_tags(html_entity_decode((string)($item['edit_description'] ?? $item['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'price' => (float)($item['price'] ?? 0),
            'price_format' => (string)($item['price_format'] ?? ''),
            'currency' => $currency = SystemCurrency::normalize((string)($item['currency'] ?? '')),
            'currency_symbol' => SystemCurrency::symbol($currency),
            'category' => $categoryId,
            'category_name' => (string)(($wo['products_categories'] ?? [])[$categoryId] ?? ''),
            'type' => (int)($item['type'] ?? 0),
            'status' => (int)($item['status'] ?? 0),
            'location' => (string)($item['location'] ?? ''),
            'units' => (int)($item['units'] ?? 0),
            'images' => $images,
            'url' => (string)($item['url'] ?? ''),
            'reviews_count' => (int)($item['reviews_count'] ?? 0),
            'rating' => (int)($item['rating'] ?? 0),
            'added_to_cart' => !empty($item['added_to_cart']),
            'is_owner' => (int)($item['user_id'] ?? 0) === $viewerId,
            'seller' => [
                'id' => (int)($item['user_id'] ?? $seller['user_id'] ?? 0),
                'username' => (string)($seller['username'] ?? ''),
                'name' => $name,
                'avatar' => $this->media((string)($seller['avatar'] ?? '')),
            ],
        ];
    }

    private function convertedPrice(array $product): float
    {
        global $wo;
        $price = (float)($product['price'] ?? 0);
        $currency = (string)($product['currency'] ?? '');
        $currencyText = (string)(($wo['currencies'][$currency]['text'] ?? $currency));
        $siteCurrency = SystemCurrency::code();
        $exchange = (float)($wo['config']['exchange'][$currencyText] ?? 0);
        return $currencyText !== $siteCurrency && $exchange > 0 ? $price / $exchange : $price;
    }

    private function authenticate(string $token, string $clientId, string $bucket): int
    {
        $this->rateLimiter->enforce($bucket, RequestContext::clientIp(), 120, 60);
        $session = $this->tokens->authenticate($token, $clientId);
        $viewerId = (int)$session['user_id'];
        global $wo;
        $wo['loggedin'] = true;
        $wo['user'] = \Wo_UserData($viewerId);
        return $viewerId;
    }

    private function assertExists(int $id): void
    {
        global $sqlConnect;
        $stmt = $sqlConnect->prepare("SELECT id FROM Wo_Products WHERE id=? AND active='1' LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) throw new ApiException(404, 'PRODUCT_NOT_FOUND', 'The product was not found.');
    }

    private function assertOwner(int $id, int $viewerId): void
    {
        global $sqlConnect;
        $stmt = $sqlConnect->prepare("SELECT id FROM Wo_Products WHERE id=? AND user_id=? AND active='1' LIMIT 1");
        $stmt->bind_param('ii', $id, $viewerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) throw new ApiException(403, 'PRODUCT_OWNER_REQUIRED', 'Only the product owner can do that.');
    }

    private function media(string $value): string
    {
        global $site_url;
        if ($value === '') return '';
        if (preg_match('#^https?://#i', $value)) return $value;
        return rtrim((string)$site_url, '/') . '/' . ltrim($value, '/');
    }
}
