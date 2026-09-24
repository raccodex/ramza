<?php
declare(strict_types=1);

use Ramza\MobileApi\ApiException;
use Ramza\MobileApi\AuditLogger;
use Ramza\MobileApi\AuthService;
use Ramza\MobileApi\Database;
use Ramza\MobileApi\MobileConfiguration;
use Ramza\MobileApi\RateLimiter;
use Ramza\MobileApi\Request;
use Ramza\MobileApi\RequestContext;
use Ramza\MobileApi\Response;
use Ramza\MobileApi\Router;
use Ramza\MobileApi\Security;
use Ramza\MobileApi\TokenService;

header_remove('Server');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    header('Allow: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Request-ID, X-Ramza-Client');
    http_response_code(204);
    exit;
}

$root = dirname(__DIR__, 2);
foreach ([
    'ApiException.php',
    'RequestContext.php',
    'Response.php',
    'Request.php',
    'Database.php',
    'Security.php',
    'AuditLogger.php',
    'RateLimiter.php',
    'SystemCurrency.php',
    'MobileConfiguration.php',
    'TokenService.php',
    'AuthChallengeService.php',
    'AuthService.php',
    'FeedService.php',
    'InteractionService.php',
    'MessageService.php',
    'StoryService.php',
    'ArticleService.php',
    'MarketplaceService.php',
    'EventService.php',
    'OfferService.php',
    'MovieService.php',
    'JobService.php',
    'CommonThingsService.php',
    'FundingService.php',
    'GameService.php',
    'LiveService.php',
    'AdvertisementService.php',
    'ProService.php',
    'AiService.php',
    'DashboardService.php',
    'Router.php',
] as $sourceFile) {
    require_once __DIR__ . '/src/' . $sourceFile;
}

RequestContext::initialize();

try {
    require_once $root . '/assets/init.php';
    if (function_exists('decryptConfigData')) {
        decryptConfigData();
    }

    $configuredSiteUrl = (string) ($site_url ?? '');
    $configuredHost = (string) parse_url($configuredSiteUrl, PHP_URL_HOST);
    $isLocalHost = in_array(strtolower($configuredHost), ['localhost', '127.0.0.1'], true);
    if (!$isLocalHost && str_starts_with(strtolower($configuredSiteUrl), 'https://')) {
        $requestIsSecure = function_exists('checkHTTPS')
            ? (bool) checkHTTPS()
            : (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
        if (!$requestIsSecure) {
            throw new ApiException(426, 'HTTPS_REQUIRED', 'The mobile API requires HTTPS.');
        }
    }

    $siteOrigin = parse_url($configuredSiteUrl, PHP_URL_SCHEME) . '://'
        . parse_url((string) ($site_url ?? ''), PHP_URL_HOST);
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && hash_equals($siteOrigin, $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    if (!isset($sqlConnect) || !$sqlConnect instanceof mysqli) {
        throw new ApiException(503, 'DATABASE_UNAVAILABLE', 'The mobile API database is unavailable.');
    }
    $pepperSource = (string) ($siteEncryptKey ?? '');
    if (strlen($pepperSource) < 32) {
        throw new ApiException(503, 'MOBILE_SECURITY_NOT_CONFIGURED', 'Mobile API security is not configured.');
    }
    $pepper = hash_hmac('sha256', 'ramza-mobile-api-v1', $pepperSource, true);

    $database = new Database($sqlConnect);
    $security = new Security($pepper);
    $audit = new AuditLogger($database, $security);
    $rateLimiter = new RateLimiter($database, $security);
    $configuration = new MobileConfiguration($database);
    $tokens = new TokenService($database, $security, $audit);
    $challenges = new \Ramza\MobileApi\AuthChallengeService($database, $security);
    $auth = new AuthService($database, $tokens, $rateLimiter, $audit, $challenges);
    $feed = new \Ramza\MobileApi\FeedService($tokens, $rateLimiter, $security);
    $interaction = new \Ramza\MobileApi\InteractionService($tokens, $rateLimiter, $security, $feed);
    $messages = new \Ramza\MobileApi\MessageService($tokens, $rateLimiter, $feed);
    $stories = new \Ramza\MobileApi\StoryService($tokens, $rateLimiter, $feed);
    $articles = new \Ramza\MobileApi\ArticleService($tokens, $rateLimiter);
    $marketplace = new \Ramza\MobileApi\MarketplaceService($tokens, $rateLimiter);
    $events = new \Ramza\MobileApi\EventService($tokens, $rateLimiter);
    $offers = new \Ramza\MobileApi\OfferService($tokens, $rateLimiter);
    $movies = new \Ramza\MobileApi\MovieService($tokens, $rateLimiter);
    $jobs = new \Ramza\MobileApi\JobService($tokens, $rateLimiter);
    $commonThings = new \Ramza\MobileApi\CommonThingsService($tokens, $rateLimiter);
    $funding = new \Ramza\MobileApi\FundingService($tokens, $rateLimiter);
    $games = new \Ramza\MobileApi\GameService($tokens, $rateLimiter);
    $live = new \Ramza\MobileApi\LiveService($tokens, $rateLimiter, $feed);
    $advertising = new \Ramza\MobileApi\AdvertisementService($tokens, $rateLimiter);
    $pro = new \Ramza\MobileApi\ProService($tokens, $rateLimiter, $database);
    $ai = new \Ramza\MobileApi\AiService($tokens, $rateLimiter);
    $dashboard = new \Ramza\MobileApi\DashboardService($tokens, $rateLimiter, $database, $feed);
    $router = new Router(new Request(), $configuration, $tokens, $auth, $feed, $rateLimiter, $interaction, $messages, $stories, $articles, $marketplace, $events, $offers, $movies, $jobs, $commonThings, $funding, $games, $live, $advertising, $pro, $ai, $dashboard);
    $router->dispatch();
} catch (ApiException $error) {
    Response::error($error);
} catch (Throwable $error) {
    error_log(sprintf(
        '[Ramza mobile API] request_id=%s error=%s file=%s line=%d',
        RequestContext::id(),
        $error->getMessage(),
        basename($error->getFile()),
        $error->getLine()
    ));
    Response::unexpected();
}
