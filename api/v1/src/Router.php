<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class Router
{
    public function __construct(
        private readonly Request $request,
        private readonly MobileConfiguration $configuration,
        private readonly TokenService $tokens,
        private readonly AuthService $auth,
        private readonly FeedService $feed,
        private readonly RateLimiter $rateLimiter,
        private readonly InteractionService $interaction,
        private readonly MessageService $messages,
        private readonly StoryService $stories,
        private readonly ArticleService $articles,
        private readonly MarketplaceService $marketplace,
        private readonly EventService $events,
        private readonly OfferService $offers,
        private readonly MovieService $movies,
        private readonly JobService $jobs,
        private readonly CommonThingsService $commonThings,
        private readonly FundingService $funding,
        private readonly GameService $games,
        private readonly LiveService $live,
        private readonly AdvertisementService $advertising,
        private readonly ProService $pro,
        private readonly AiService $ai,
        private readonly DashboardService $dashboard
    ) {
    }

    public function dispatch(): never
    {
        $method = $this->request->method();
        $path = $this->request->path();

        if ($method === 'GET' && $path === '/dashboard/overview') {
            $clientId = $this->gatedClient();
            Response::success($this->dashboard->overview(
                $this->request->bearerToken(), $clientId,
                $this->request->queryString('range', 16, '28d')
            ), 'Professional dashboard loaded.');
        }
        if ($method === 'GET' && $path === '/dashboard/analytics') {
            $clientId = $this->gatedClient();
            Response::success($this->dashboard->analytics(
                $this->request->bearerToken(), $clientId,
                $this->request->queryString('range', 16, '28d')
            ), 'Analytics loaded.');
        }
        if ($method === 'GET' && $path === '/dashboard/content') {
            $clientId = $this->gatedClient();
            $result = $this->dashboard->content(
                $this->request->bearerToken(), $clientId,
                $this->request->queryString('range', 16, 'lifetime'),
                $this->request->queryString('type', 24, 'all'),
                $this->request->queryString('metric', 24, 'views'),
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 30)
            );
            Response::success($result['items'], 'Content loaded.', [
                'next_after' => $result['next_after'], 'filters' => $result['filters'],
            ]);
        }
        if ($method === 'GET' && $path === '/dashboard/community/comments') {
            $clientId = $this->gatedClient();
            $result = $this->dashboard->comments(
                $this->request->bearerToken(), $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 30)
            );
            Response::success($result['items'], 'Comment queue loaded.', ['next_after' => $result['next_after']]);
        }
        if ($method === 'GET' && $path === '/dashboard/status') {
            $clientId = $this->gatedClient();
            Response::success($this->dashboard->status(
                $this->request->bearerToken(), $clientId
            ), 'Professional status loaded.');
        }

        if ($method === 'GET' && $path === '/ai/config') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->ai->configuration($this->request->bearerToken(), $clientId),
                'AI configuration loaded.'
            );
        }
        if ($method === 'POST' && $path === '/ai/text') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->ai->generateText($this->request->bearerToken(), $clientId, $this->request->json()),
                'Text generated.'
            );
        }
        if ($method === 'POST' && $path === '/ai/chat') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->ai->generateText($this->request->bearerToken(), $clientId, $this->request->json(), true),
                'Reply generated.'
            );
        }
        if ($method === 'POST' && $path === '/ai/image') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->ai->generateImage($this->request->bearerToken(), $clientId, $this->request->json()),
                'Image generated.'
            );
        }

        if ($method === 'GET' && $path === '/pro') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->pro->configuration($this->request->bearerToken(), $clientId),
                'Pro plans loaded.'
            );
        }
        if ($method === 'POST' && $path === '/pro/purchase') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->pro->purchase($this->request->bearerToken(), $clientId, $this->request->json()),
                'Your account was upgraded.'
            );
        }

        if ($method === 'GET' && $path === '/advertising/config') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->advertising->configuration($this->request->bearerToken(), $clientId),
                'Advertising configuration loaded.'
            );
        }
        if ($method === 'GET' && $path === '/advertising') {
            $clientId = $this->gatedClient();
            $result = $this->advertising->list(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 50)
            );
            Response::success($result['items'], 'Advertisements loaded.', ['next_after' => $result['next_after']]);
        }
        if ($method === 'POST' && $path === '/advertising') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->advertising->create($this->request->bearerToken(), $clientId, $this->request->json()),
                'Advertisement created.',
                [],
                201
            );
        }
        if ($method === 'POST' && $path === '/advertising/test') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->advertising->test($this->request->bearerToken(), $clientId),
                'Test advertisement is ready.',
                [],
                201
            );
        }
        if ($method === 'GET' && preg_match('#^/advertising/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->advertising->get($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Advertisement loaded.'
            );
        }
        if ($method === 'POST' && preg_match('#^/advertising/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->advertising->update(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id'],
                    $this->request->json()
                ),
                'Advertisement updated.'
            );
        }
        if ($method === 'DELETE' && preg_match('#^/advertising/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->advertising->delete($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Advertisement deleted.'
            );
        }
        if ($method === 'POST' && preg_match('#^/advertising/(?<id>[0-9]+)/click$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->advertising->click($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Advertisement click registered.'
            );
        }

        if ($method === 'GET' && $path === '/live/config') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->configuration($this->request->bearerToken(), $clientId),
                'Live-video configuration loaded.'
            );
        }

        if ($method === 'GET' && $path === '/calls/config') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->callConfiguration($this->request->bearerToken(), $clientId),
                'Calling configuration loaded.'
            );
        }
        if ($method === 'GET' && $path === '/calls/incoming') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->incomingCall($this->request->bearerToken(), $clientId),
                'Incoming call checked.'
            );
        }
        if ($method === 'POST' && $path === '/calls') {
            $clientId = $this->gatedClient();
            $payload = $this->request->json();
            Response::success(
                $this->live->createCall(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)($payload['user_id'] ?? 0),
                    (string)($payload['type'] ?? 'audio')
                ),
                'Call started.',
                [],
                201
            );
        }
        if ($method === 'GET' && preg_match('#^/calls/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->call($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Call loaded.'
            );
        }
        if ($method === 'POST' && preg_match('#^/calls/(?<id>[0-9]+)/accept$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->answerCall($this->request->bearerToken(), $clientId, (int)$matches['id'], true),
                'Call accepted.'
            );
        }
        if ($method === 'POST' && preg_match('#^/calls/(?<id>[0-9]+)/decline$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->answerCall($this->request->bearerToken(), $clientId, (int)$matches['id'], false),
                'Call declined.'
            );
        }
        if ($method === 'DELETE' && preg_match('#^/calls/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->endCall($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Call ended.'
            );
        }

        if ($method === 'GET' && $path === '/live') {
            $clientId = $this->gatedClient();
            $result = $this->live->list(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 30)
            );
            Response::success(
                $result['items'],
                'Live videos loaded.',
                [
                    'next_after' => $result['next_after'],
                    'configuration' => $result['configuration'],
                ]
            );
        }
        if ($method === 'POST' && $path === '/live') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->create($this->request->bearerToken(), $clientId, $this->request->json()),
                'Live video started.',
                [],
                201
            );
        }
        if ($method === 'POST' && preg_match('#^/live/(?<id>[0-9]+)/join$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->join($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Live video joined.'
            );
        }
        if ($method === 'POST' && preg_match('#^/live/(?<id>[0-9]+)/heartbeat$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $payload = $this->request->json();
            Response::success(
                $this->live->heartbeat(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id'],
                    (string)($payload['page'] ?? 'story') === 'live'
                ),
                'Live status updated.'
            );
        }
        if ($method === 'DELETE' && preg_match('#^/live/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->live->end($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Live video ended.'
            );
        }

        if ($method === 'GET' && $path === '/games') {
            $clientId = $this->gatedClient();
            Response::success($this->games->list(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryString('scope', 20, 'all'),
                $this->request->queryString('query', 120, ''),
                $this->request->queryInt('after', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 50)
            ), 'Games loaded.');
        }
        if ($method === 'GET' && preg_match('#^/games/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->games->get($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Game loaded.'
            );
        }
        if ($method === 'POST' && preg_match('#^/games/(?<id>[0-9]+)/play$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->games->play($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Game opened.'
            );
        }

        if ($method === 'GET' && $path === '/funding') {
            $clientId = $this->gatedClient();
            Response::success($this->funding->list(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryString('scope', 10, 'all') === 'mine',
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 50)
            ), 'Funding loaded.');
        }
        if ($method === 'POST' && $path === '/funding') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->funding->create(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->json()
                ),
                'Funding created.',
                [],
                201
            );
        }
        if ($method === 'GET' && preg_match('#^/funding/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->funding->get($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Funding loaded.'
            );
        }
        if ($method === 'POST' && preg_match('#^/funding/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->funding->update(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id'],
                    $this->request->json()
                ),
                'Funding updated.'
            );
        }
        if ($method === 'DELETE' && preg_match('#^/funding/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->funding->delete($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Funding deleted.'
            );
        }
        if ($method === 'POST' && preg_match('#^/funding/(?<id>[0-9]+)/donate$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $payload = $this->request->json();
            Response::success(
                $this->funding->donate(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id'],
                    (float)($payload['amount'] ?? 0)
                ),
                'Donated.'
            );
        }

        if ($method === 'GET' && $path === '/memories') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->feed->memories($this->request->bearerToken(), $clientId),
                'Memories loaded.'
            );
        }

        if ($method === 'GET' && $path === '/common-things') {
            $clientId = $this->gatedClient();
            Response::success($this->commonThings->list(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 50)
            ), 'Common things loaded.');
        }

        if ($method === 'GET' && $path === '/jobs/config') {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->configuration($this->request->bearerToken(), $clientId), 'Job configuration loaded.');
        }
        if ($method === 'GET' && $path === '/jobs') {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->list(
                $this->request->bearerToken(), $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 50),
                $this->request->queryString('keyword', 120, ''),
                $this->request->queryString('type', 30, ''),
                $this->request->queryInt('category', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('distance', 0, 0, 500)
            ), 'Jobs loaded.');
        }
        if ($method === 'POST' && $path === '/jobs') {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->create($this->request->bearerToken(), $clientId, $this->request->json()), 'Job created.', [], 201);
        }
        if ($method === 'GET' && preg_match('#^/jobs/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->get($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Job loaded.');
        }
        if ($method === 'POST' && preg_match('#^/jobs/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->update($this->request->bearerToken(), $clientId, (int)$matches['id'], $this->request->json()), 'Job updated.');
        }
        if ($method === 'DELETE' && preg_match('#^/jobs/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->delete($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Job deleted.');
        }
        if ($method === 'POST' && preg_match('#^/jobs/(?<id>[0-9]+)/apply$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->apply($this->request->bearerToken(), $clientId, (int)$matches['id'], $this->request->json()), 'Applied successfully.', [], 201);
        }
        if ($method === 'GET' && preg_match('#^/jobs/(?<id>[0-9]+)/applications$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->applications($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Applications loaded.');
        }
        if ($method === 'POST' && preg_match('#^/jobs/(?<id>[0-9]+)/boost-test$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->jobs->boostTest($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Job test promotion is ready.');
        }

        if ($method === 'GET' && $path === '/health') {
            $this->rateLimiter->enforce('mobile_health', RequestContext::clientIp(), 120, 60);
            Response::success([
                'service' => 'ramza-mobile-api',
                'version' => 'v1',
                'time' => time(),
            ]);
        }

        if ($method === 'GET' && $path === '/config/mobile') {
            $client = $this->configuration->client($this->request->publicClientId());
            $this->rateLimiter->enforce('mobile_config', RequestContext::clientIp(), 30, 60);
            Response::success($this->configuration->publicPayload($client));
        }

        if ($method === 'POST' && $path === '/auth/login') {
            $client = $this->configuration->client($this->request->publicClientId());
            $this->configuration->assertMobileAvailable();
            $result = $this->auth->login($this->request->json(), $client);
            Response::success(
                $result,
                !empty($result['authentication_required']) ? 'Verification is required.' : 'Signed in successfully.',
                [],
                !empty($result['authentication_required']) ? 202 : 200
            );
        }

        if ($method === 'POST' && $path === '/auth/register') {
            $client = $this->configuration->client($this->request->publicClientId());
            $this->configuration->assertMobileAvailable();
            Response::success($this->auth->register($this->request->json(), $client), 'Account created.', [], 201);
        }

        if ($method === 'POST' && $path === '/auth/activate') {
            $client = $this->configuration->client($this->request->publicClientId());
            $this->configuration->assertMobileAvailable();
            Response::success($this->auth->activate($this->request->json(), $client), 'Account activated.');
        }

        if ($method === 'POST' && $path === '/auth/challenge/verify') {
            $client = $this->configuration->client($this->request->publicClientId());
            $this->configuration->assertMobileAvailable();
            Response::success($this->auth->verifyLoginChallenge($this->request->json(), $client), 'Signed in successfully.');
        }

        if ($method === 'POST' && $path === '/auth/password/forgot') {
            $client = $this->configuration->client($this->request->publicClientId());
            $this->configuration->assertMobileAvailable();
            Response::success(
                $this->auth->requestPasswordReset($this->request->json(), $client),
                'If the account exists, a verification code has been sent.'
            );
        }

        if ($method === 'POST' && $path === '/auth/password/reset') {
            $client = $this->configuration->client($this->request->publicClientId());
            $this->configuration->assertMobileAvailable();
            $this->auth->resetPassword($this->request->json(), $client);
            Response::success(null, 'Password updated. Sign in with your new password.');
        }

        if ($method === 'GET' && $path === '/auth/social/providers') {
            $this->configuration->client($this->request->publicClientId());
            $this->configuration->assertMobileAvailable();
            Response::success($this->auth->socialProviders());
        }

        if ($method === 'POST' && $path === '/auth/social/login') {
            $client = $this->configuration->client($this->request->publicClientId());
            $this->configuration->assertMobileAvailable();
            Response::success($this->auth->socialLogin($this->request->json(), $client), 'Signed in successfully.');
        }

        if ($method === 'POST' && $path === '/auth/refresh') {
            $clientId = $this->resolvedClientId();
            $this->configuration->assertMobileAvailable();
            $body = $this->request->json();
            $refresh = $this->request->requiredString($body, 'refresh_token', 200);
            $installation = $this->request->requiredString($body, 'installation_id', 128);
            Response::success(
                $this->tokens->rotate($refresh, $clientId, $installation),
                'Session refreshed.'
            );
        }

        if ($method === 'POST' && $path === '/auth/logout') {
            $clientId = $this->resolvedClientId();
            $session = $this->tokens->authenticate($this->request->bearerToken(), $clientId);
            $this->tokens->revokeSession((string) $session['session_id'], (int) $session['user_id']);
            Response::success(null, 'Signed out successfully.');
        }

        if ($method === 'GET' && $path === '/auth/sessions') {
            $clientId = $this->resolvedClientId();
            $session = $this->tokens->authenticate($this->request->bearerToken(), $clientId);
            Response::success($this->tokens->sessions((int) $session['user_id']));
        }

        if ($method === 'GET' && $path === '/account') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->account($this->request->bearerToken(), $clientId), 'Account loaded.');
        }

        if ($method === 'POST' && $path === '/account') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->updateAccount(
                $this->request->bearerToken(), $clientId, $this->request->json()
            ), 'Your details were updated.');
        }

        if ($method === 'GET' && $path === '/account/privacy') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->accountPrivacy(
                $this->request->bearerToken(), $clientId
            ), 'Privacy settings loaded.');
        }

        if ($method === 'POST' && $path === '/account/privacy') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->updateAccountPrivacy(
                $this->request->bearerToken(), $clientId, $this->request->json()
            ), 'Privacy settings updated.');
        }

        if ($method === 'GET' && $path === '/account/notification-settings') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->accountNotificationSettings(
                $this->request->bearerToken(), $clientId
            ), 'Notification settings loaded.');
        }

        if ($method === 'POST' && $path === '/account/notification-settings') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->updateAccountNotificationSettings(
                $this->request->bearerToken(), $clientId, $this->request->json()
            ), 'Notification settings updated.');
        }

        if ($method === 'GET' && $path === '/account/invitation-links') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->invitationLinks(
                $this->request->bearerToken(), $clientId
            ), 'Invitation links loaded.');
        }

        if ($method === 'POST' && $path === '/account/invitation-links') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->createInvitationLink(
                    $this->request->bearerToken(),
                    $clientId
                ),
                'Invitation link generated.',
                [],
                201
            );
        }

        if ($method === 'GET' && $path === '/account/information') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->informationExportConfiguration(
                $this->request->bearerToken(), $clientId
            ), 'Information export options loaded.');
        }

        if ($method === 'POST' && $path === '/account/information/export') {
            $clientId = $this->gatedClient();
            $body = $this->request->json();
            Response::success($this->interaction->exportInformation(
                $this->request->bearerToken(),
                $clientId,
                $this->request->requiredString($body, 'type', 32)
            ), 'Your file is ready.');
        }

        if ($method === 'GET' && $path === '/account/addresses') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->addresses(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 15, 1, 50)
            );
            Response::success($result['items'], 'Addresses loaded.', ['next_after' => $result['next_after']]);
        }

        if ($method === 'POST' && $path === '/account/addresses') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->createAddress(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->json()
                ),
                'Address created.',
                [],
                201
            );
        }

        if (preg_match('#^/account/addresses/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            if ($method === 'POST') {
                Response::success($this->interaction->updateAddress(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id'],
                    $this->request->json()
                ), 'Address updated.');
            }
            if ($method === 'DELETE') {
                Response::success($this->interaction->deleteAddress(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id']
                ), 'Address deleted.');
            }
        }

        if ($method === 'GET' && $path === '/account/earnings') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->earnings(
                $this->request->bearerToken(), $clientId
            ), 'Earnings loaded.');
        }

        if ($method === 'POST' && $path === '/account/earnings/withdraw') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->requestWithdrawal(
                $this->request->bearerToken(),
                $clientId,
                $this->request->json()
            ), 'Withdrawal requested.');
        }

        if ($method === 'POST' && $path === '/account/wallet/send') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->sendWalletMoney(
                $this->request->bearerToken(),
                $clientId,
                $this->request->json()
            ), 'Money successfully sent.');
        }

        if ($method === 'POST' && $path === '/account/password') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->changePassword(
                $this->request->bearerToken(), $clientId, $this->request->json()
            ), 'Your details were updated.');
        }

        if ($method === 'POST' && $path === '/account/two-factor') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->twoFactor(
                $this->request->bearerToken(), $clientId, $this->request->json()
            ), 'Two-factor settings updated.');
        }

        if ($method === 'GET' && $path === '/account/blocked-users') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->blockedUsers(
                $this->request->bearerToken(), $clientId
            ), 'Blocked users loaded.');
        }

        if ($method === 'DELETE' && preg_match('#^/account/blocked-users/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->unblockUser(
                $this->request->bearerToken(), $clientId, (int)$matches['id']
            ), 'Unblocked successfully.');
        }

        if ($method === 'POST' && $path === '/account/delete') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->deleteAccount(
                $this->request->bearerToken(), $clientId, $this->request->json()
            ), 'Your account was successfully deleted.');
        }

        if ($method === 'POST' && $path === '/account/verification') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->requestVerification(
                $this->request->bearerToken(),
                $clientId,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['message'] ?? ''),
                isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : null,
                isset($_FILES['passport']) && is_array($_FILES['passport']) ? $_FILES['passport'] : null
            ), 'Verification request sent.', [], 201);
        }

        if ($method === 'GET' && $path === '/feed') {
            $clientId = $this->resolvedClientId();
            $this->configuration->assertMobileAvailable();
            $result = $this->feed->feed(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryString('cursor') ?: null,
                $this->request->queryInt('limit', 10, 1, 20),
                $this->request->queryString('filter', 20, 'all'),
                $this->request->queryString('sort', 20, 'recent')
            );
            Response::success(
                $result['items'],
                'Feed loaded.',
                ['next_cursor' => $result['next_cursor']]
            );
        }

        if ($method === 'POST' && $path === '/algorithm/events') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->feed->recordAlgorithmEvents(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->json()
                ),
                'Algorithm behavior recorded.'
            );
        }

        if ($method === 'GET' && $path === '/algorithm/configuration') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->feed->algorithmConfiguration(
                    $this->request->bearerToken(),
                    $clientId
                ),
                'Algorithm configuration loaded.'
            );
        }

        if ($method === 'GET' && $path === '/reels') {
            $clientId = $this->resolvedClientId();
            $this->configuration->assertMobileAvailable();
            $result = $this->feed->reels(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryString('cursor') ?: null,
                $this->request->queryInt('limit', 10, 1, 20)
            );
            Response::success($result['items'], 'Reels loaded.', ['next_cursor' => $result['next_cursor']]);
        }

        if ($method === 'GET' && $path === '/stories') {
            $clientId = $this->gatedClient();
            Response::success($this->stories->groups(
                $this->request->bearerToken(), $clientId,
                $this->request->queryInt('user_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 30, 1, 50)
            ), 'Stories loaded.');
        }

        if ($method === 'POST' && $path === '/stories') {
            $clientId = $this->gatedClient();
            Response::success($this->stories->create(
                $this->request->bearerToken(), $clientId,
                (string) ($_POST['file_type'] ?? ''), (string) ($_POST['caption'] ?? ''),
                isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null,
                isset($_FILES['cover']) && is_array($_FILES['cover']) ? $_FILES['cover'] : null
            ), 'Story published.', [], 201);
        }

        if ($method === 'POST' && preg_match('#^/stories/(?<id>[0-9]{1,19})/seen$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->stories->seen($this->request->bearerToken(), $clientId, (int)$matches['id']));
        }

        if ($method === 'POST' && preg_match('#^/stories/(?<id>[0-9]{1,19})/react$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->stories->react(
                $this->request->bearerToken(), $clientId, (int)$matches['id'],
                (int)($this->request->json()['reaction'] ?? 0)
            ), 'Story reaction saved.');
        }

        if ($method === 'POST' && preg_match('#^/stories/(?<id>[0-9]{1,19})/reply$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->stories->reply(
                $this->request->bearerToken(), $clientId, (int)$matches['id'],
                (string)($this->request->json()['text'] ?? '')
            ), 'Reply sent.', [], 201);
        }

        if ($method === 'GET' && preg_match('#^/stories/(?<id>[0-9]{1,19})/views$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->stories->views(
                $this->request->bearerToken(), $clientId, (int)$matches['id'],
                $this->request->queryInt('limit', 30, 1, 50)
            ), 'Story views loaded.');
        }

        if ($method === 'DELETE' && preg_match('#^/stories/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->stories->delete(
                $this->request->bearerToken(), $clientId, (int)$matches['id']
            ), 'Story deleted.');
        }

        if ($method === 'DELETE' && preg_match('#^/auth/sessions/(?<id>[0-9a-f-]{36})$#', $path, $matches) === 1) {
            $clientId = $this->resolvedClientId();
            $session = $this->tokens->authenticate($this->request->bearerToken(), $clientId);
            $this->tokens->revokeSession($matches['id'], (int) $session['user_id'], 'user_revoked');
            Response::success(null, 'Session revoked.');
        }

        if ($method === 'POST' && preg_match('#^/posts/(?<id>[0-9]{1,19})/react$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $type = $this->request->requiredString($this->request->json(), 'type', 1);
            Response::success(
                $this->interaction->react($this->request->bearerToken(), $clientId, (int) $matches['id'], $type),
                'Reaction saved.'
            );
        }

        if ($method === 'DELETE' && preg_match('#^/posts/(?<id>[0-9]{1,19})/react$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->removeReaction($this->request->bearerToken(), $clientId, (int) $matches['id']),
                'Reaction removed.'
            );
        }

        if ($method === 'GET' && preg_match('#^/posts/(?<id>[0-9]{1,19})/reactions$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $result = $this->interaction->reactionUsers(
                $this->request->bearerToken(),
                $clientId,
                (int)$matches['id'],
                $this->request->queryString('type') ?: '1',
                $this->request->queryInt('offset', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 30, 1, 50)
            );
            Response::success($result['items'], 'Reactions loaded.', ['next_offset' => $result['next_offset']]);
        }

        if ($method === 'POST' && preg_match('#^/posts/(?<id>[0-9]{1,19})/action$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $action = $this->request->requiredString($this->request->json(), 'action', 32);
            Response::success($this->interaction->postAction(
                $this->request->bearerToken(), $clientId, (int)$matches['id'], $action
            ), 'Post action completed.');
        }

        if ($method === 'POST' && preg_match('#^/posts/(?<id>[0-9]{1,19})/share$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $body = $this->request->json();
            Response::success(
                $this->interaction->sharePost(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id'],
                    (string)($body['destination'] ?? 'timeline'),
                    (int)($body['destination_id'] ?? 0),
                    (string)($body['text'] ?? '')
                ),
                'Post shared.',
                [],
                201
            );
        }

        if ($method === 'POST' && preg_match('#^/posts/(?<id>[0-9]{1,19})/edit$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $text = $this->request->requiredString($this->request->json(), 'text', 10000);
            Response::success(
                $this->interaction->editPost($this->request->bearerToken(), $clientId, (int)$matches['id'], $text),
                'Post updated.'
            );
        }

        if ($method === 'POST' && preg_match('#^/posts/(?<id>[0-9]{1,19})/vote$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $optionId = (int) ($this->request->json()['option_id'] ?? 0);
            Response::success(
                $this->interaction->vote($this->request->bearerToken(), $clientId, (int) $matches['id'], $optionId),
                'Vote saved.'
            );
        }

        if ($method === 'GET' && preg_match('#^/posts/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->postDetail($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Post loaded.'
            );
        }

        if ($method === 'GET' && preg_match('#^/posts/(?<id>[0-9]{1,19})/comments$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $result = $this->interaction->comments(
                $this->request->bearerToken(),
                $clientId,
                (int) $matches['id'],
                $this->request->queryString('cursor') ?: null,
                $this->request->queryInt('limit', 15, 1, 30)
            );
            Response::success($result['items'], 'Comments loaded.', ['next_cursor' => $result['next_cursor']]);
        }

        if ($method === 'POST' && preg_match('#^/posts/(?<id>[0-9]{1,19})/comments$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $text = $this->request->requiredString($this->request->json(), 'text', 10_000);
            Response::success(
                $this->interaction->addComment($this->request->bearerToken(), $clientId, (int) $matches['id'], $text),
                'Comment posted.',
                [],
                201
            );
        }

        if ($method === 'POST' && preg_match('#^/comments/(?<id>[0-9]{1,19})/like$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->toggleCommentLike($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Comment reaction updated.'
            );
        }

        if ($method === 'POST' && preg_match('#^/comments/(?<id>[0-9]{1,19})/edit$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $text = $this->request->requiredString($this->request->json(), 'text', 10_000);
            Response::success(
                $this->interaction->editComment($this->request->bearerToken(), $clientId, (int)$matches['id'], $text),
                'Comment updated.'
            );
        }

        if ($method === 'DELETE' && preg_match('#^/comments/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->deleteComment($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Comment deleted.'
            );
        }

        if ($method === 'GET' && preg_match('#^/comments/(?<id>[0-9]{1,19})/replies$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $result = $this->interaction->commentReplies(
                $this->request->bearerToken(),
                $clientId,
                (int)$matches['id'],
                $this->request->queryInt('offset', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 30)
            );
            Response::success($result['items'], 'Replies loaded.', ['next_offset' => $result['next_offset']]);
        }

        if ($method === 'POST' && preg_match('#^/comments/(?<id>[0-9]{1,19})/replies$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $text = $this->request->requiredString($this->request->json(), 'text', 10_000);
            Response::success(
                $this->interaction->addCommentReply($this->request->bearerToken(), $clientId, (int)$matches['id'], $text),
                'Reply posted.',
                [],
                201
            );
        }

        if ($method === 'POST' && preg_match('#^/comment-replies/(?<id>[0-9]{1,19})/like$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->toggleReplyLike($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Reply reaction updated.'
            );
        }

        if ($method === 'DELETE' && preg_match('#^/comment-replies/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->deleteReply($this->request->bearerToken(), $clientId, (int)$matches['id']),
                'Reply deleted.'
            );
        }

        if ($method === 'GET' && $path === '/account/activity') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->activities(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('offset', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 30)
            );
            Response::success($result['items'], 'Activity loaded.', ['next_offset' => $result['next_offset']]);
        }

        if ($method === 'GET' && $path === '/gifts') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->gifts($this->request->bearerToken(), $clientId), 'Gifts loaded.');
        }

        if ($method === 'POST' && $path === '/gifts/send') {
            $clientId = $this->gatedClient();
            $body = $this->request->json();
            Response::success(
                $this->interaction->sendGift(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)($body['gift_id'] ?? 0),
                    (int)($body['recipient_id'] ?? 0)
                ),
                'Gift sent.'
            );
        }

        if ($method === 'GET' && preg_match('#^/users/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->profile($this->request->bearerToken(), $clientId, (int) $matches['id']),
                'Profile loaded.'
            );
        }

        if ($method === 'GET' && $path === '/pokes') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->pokes($this->request->bearerToken(), $clientId),
                'Pokes loaded.'
            );
        }

        if ($method === 'GET' && $path === '/photos') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->myPhotos(
                $this->request->bearerToken(), $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 30)
            );
            Response::success($result['items'], 'Photos loaded.', ['next_after' => $result['next_after']]);
        }

        if ($method === 'GET' && $path === '/videos') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->myVideos(
                $this->request->bearerToken(), $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 30)
            );
            Response::success($result['items'], 'Videos loaded.', ['next_after' => $result['next_after']]);
        }

        if ($method === 'GET' && $path === '/saved-posts') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->savedPosts(
                $this->request->bearerToken(), $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 8, 1, 30)
            );
            Response::success($result['items'], 'Saved posts loaded.', ['next_after' => $result['next_after']]);
        }

        if ($method === 'GET' && $path === '/pages') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->pagesOverview($this->request->bearerToken(), $clientId), 'Pages loaded.');
        }

        if ($method === 'GET' && $path === '/pages/search') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->searchPages($this->request->bearerToken(), $clientId, $this->request->queryString('q', 100), $this->request->queryInt('limit', 30, 1, 30)), 'Pages loaded.');
        }

        if ($method === 'POST' && $path === '/pages') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->createPage($this->request->bearerToken(), $clientId, $this->request->json()), 'Page created.', [], 201);
        }

        if ($method === 'GET' && preg_match('#^/pages/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->page($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Page loaded.');
        }

        if ($method === 'POST' && preg_match('#^/pages/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->updatePage($this->request->bearerToken(), $clientId, (int)$matches['id'], $this->request->json()), 'Page updated.');
        }

        if ($method === 'DELETE' && preg_match('#^/pages/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->deletePage($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Page deleted.');
        }

        if ($method === 'GET' && preg_match('#^/pages/(?<id>[0-9]{1,19})/posts$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $result = $this->interaction->pagePosts($this->request->bearerToken(), $clientId, (int)$matches['id'], $this->request->queryInt('after_id',0,0,PHP_INT_MAX), $this->request->queryInt('limit',10,1,20));
            Response::success($result['items'], 'Page posts loaded.', ['next_after'=>$result['next_after']]);
        }

        if ($method === 'POST' && preg_match('#^/pages/(?<id>[0-9]{1,19})/media$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->updatePageMedia($this->request->bearerToken(),$clientId,(int)$matches['id'],(string)($_POST['type'] ?? ''),isset($_FILES['image'])&&is_array($_FILES['image'])?$_FILES['image']:null),'Page image updated.');
        }

        if ($method === 'GET' && preg_match('#^/pages/(?<id>[0-9]{1,19})/admins$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->pageAdmins($this->request->bearerToken(),$clientId,(int)$matches['id']),'Page administrators loaded.');
        }

        if ($method === 'POST' && preg_match('#^/pages/(?<id>[0-9]{1,19})/admins/(?<user>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->togglePageAdmin($this->request->bearerToken(),$clientId,(int)$matches['id'],(int)$matches['user']),'Page administrator updated.');
        }

        if ($method === 'GET' && preg_match('#^/pages/(?<id>[0-9]{1,19})/reviews$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->pageReviews($this->request->bearerToken(),$clientId,(int)$matches['id']),'Page reviews loaded.');
        }

        if ($method === 'POST' && preg_match('#^/pages/(?<id>[0-9]{1,19})/reviews$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $body = $this->request->json();
            Response::success($this->interaction->ratePage($this->request->bearerToken(),$clientId,(int)$matches['id'],(int)($body['rating']??0),(string)($body['review']??'')),'Review submitted.');
        }

        if ($method === 'GET' && $path === '/groups') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->groupsOverview($this->request->bearerToken(), $clientId), 'Groups loaded.');
        }

        if ($method === 'GET' && $path === '/groups/search') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->searchGroups($this->request->bearerToken(), $clientId, $this->request->queryString('q', 100), $this->request->queryInt('limit', 30, 1, 30)), 'Groups loaded.');
        }

        if ($method === 'POST' && $path === '/groups') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->createGroup($this->request->bearerToken(), $clientId, $this->request->json()), 'Group created.', [], 201);
        }

        if ($method === 'GET' && preg_match('#^/groups/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->group($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Group loaded.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->updateGroup($this->request->bearerToken(), $clientId, (int)$matches['id'], $this->request->json()), 'Group updated.');
        }

        if ($method === 'DELETE' && preg_match('#^/groups/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->deleteGroup($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Group deleted.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})/delete$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->deleteGroupConfirmed($this->request->bearerToken(), $clientId, (int)$matches['id'], $this->request->json()), 'Group deleted.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})/join$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->toggleGroupJoin($this->request->bearerToken(), $clientId, (int)$matches['id']), 'Membership updated.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})/report$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->reportGroup($this->request->bearerToken(), $clientId, (int)$matches['id'], (string)($this->request->json()['text'] ?? '')), 'Report updated.');
        }

        if ($method === 'GET' && preg_match('#^/groups/(?<id>[0-9]{1,19})/posts$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $result = $this->interaction->groupPosts($this->request->bearerToken(), $clientId, (int)$matches['id'], $this->request->queryInt('after_id',0,0,PHP_INT_MAX), $this->request->queryInt('limit',10,1,20));
            Response::success($result['items'], 'Group posts loaded.', ['next_after'=>$result['next_after']]);
        }

        if ($method === 'GET' && preg_match('#^/groups/(?<id>[0-9]{1,19})/members$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->groupMembers($this->request->bearerToken(), $clientId, (int)$matches['id'], $this->request->queryInt('offset',0,0,PHP_INT_MAX), $this->request->queryInt('limit',20,1,50)), 'Members loaded.');
        }

        if ($method === 'GET' && preg_match('#^/groups/(?<id>[0-9]{1,19})/invite-candidates$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->groupInviteCandidates($this->request->bearerToken(),$clientId,(int)$matches['id'],$this->request->queryInt('offset',0,0,PHP_INT_MAX),$this->request->queryInt('limit',10,1,50)),'People loaded.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})/invite$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->inviteGroupMember($this->request->bearerToken(),$clientId,(int)$matches['id'],(int)($this->request->json()['user_id'] ?? 0)),'Invitation sent.');
        }

        if ($method === 'DELETE' && preg_match('#^/groups/(?<id>[0-9]{1,19})/members/(?<user>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->removeGroupMember($this->request->bearerToken(),$clientId,(int)$matches['id'],(int)$matches['user']),'Member removed.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})/members/(?<user>[0-9]{1,19})/admin$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->toggleGroupAdmin($this->request->bearerToken(), $clientId, (int)$matches['id'], (int)$matches['user']), 'Administrator updated.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})/members/(?<user>[0-9]{1,19})/block$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->blockGroupMember($this->request->bearerToken(), $clientId, (int)$matches['id'], (int)$matches['user']), 'Member blocked.');
        }

        if ($method === 'GET' && preg_match('#^/groups/(?<id>[0-9]{1,19})/requests$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->groupRequests($this->request->bearerToken(),$clientId,(int)$matches['id'],$this->request->queryInt('offset',0,0,PHP_INT_MAX),$this->request->queryInt('limit',10,1,50)),'Requests loaded.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})/requests/(?<user>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->decideGroupRequest($this->request->bearerToken(),$clientId,(int)$matches['id'],(int)$matches['user'],(string)($this->request->json()['action'] ?? '')),'Request updated.');
        }

        if ($method === 'POST' && preg_match('#^/groups/(?<id>[0-9]{1,19})/media$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->updateGroupMedia($this->request->bearerToken(),$clientId,(int)$matches['id'],(string)($_POST['type'] ?? ''),isset($_FILES['image'])&&is_array($_FILES['image'])?$_FILES['image']:null),'Group image updated.');
        }

        if ($method === 'GET' && $path === '/albums') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->albums(
                $this->request->bearerToken(), $clientId,
                $this->request->queryInt('user_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 15, 1, 30)
            );
            Response::success($result['items'], 'Albums loaded.', ['next_after' => $result['next_after']]);
        }

        if ($method === 'GET' && preg_match('#^/albums/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->album(
                $this->request->bearerToken(), $clientId, (int) $matches['id']
            ), 'Album loaded.');
        }

        if ($method === 'POST' && $path === '/albums') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->createAlbum(
                $this->request->bearerToken(), $clientId,
                (string) ($_POST['album_name'] ?? ''),
                isset($_FILES['photos']) && is_array($_FILES['photos']) ? $_FILES['photos'] : null
            ), 'Album created.', [], 201);
        }

        if ($method === 'POST' && preg_match('#^/albums/(?<id>[0-9]{1,19})/photos$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->addAlbumPhotos(
                $this->request->bearerToken(), $clientId, (int) $matches['id'],
                isset($_FILES['photos']) && is_array($_FILES['photos']) ? $_FILES['photos'] : null
            ), 'Photos added.');
        }

        if ($method === 'POST' && preg_match('#^/pokes/(?<id>[0-9]{1,19})/back$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->pokeBack(
                    $this->request->bearerToken(), $clientId, (int) $matches['id']
                ),
                'Poke sent.'
            );
        }

        if ($method === 'GET' && preg_match('#^/users/(?<id>[0-9]{1,19})/posts$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $result = $this->interaction->userPosts(
                $this->request->bearerToken(),
                $clientId,
                (int) $matches['id'],
                $this->request->queryString('cursor') ?: null,
                $this->request->queryInt('limit', 10, 1, 20)
            );
            Response::success($result['items'], 'Posts loaded.', ['next_cursor' => $result['next_cursor']]);
        }

        if ($method === 'POST' && preg_match('#^/users/(?<id>[0-9]{1,19})/follow$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->follow($this->request->bearerToken(), $clientId, (int) $matches['id']),
                'Following.'
            );
        }

        if ($method === 'DELETE' && preg_match('#^/users/(?<id>[0-9]{1,19})/follow$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->unfollow($this->request->bearerToken(), $clientId, (int) $matches['id']),
                'Unfollowed.'
            );
        }

        if ($method === 'POST' && preg_match('#^/users/(?<id>[0-9]{1,19})/action$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $body = $this->request->json();
            Response::success(
                $this->interaction->profileAction(
                    $this->request->bearerToken(),
                    $clientId,
                    (int) $matches['id'],
                    (string) ($body['action'] ?? ''),
                    (string) ($body['text'] ?? '')
                ),
                'Profile action completed.'
            );
        }

        if ($method === 'GET' && $path === '/post-colors') {
            $this->gatedClient();
            Response::success($this->feed->postColors(), 'Post colors.');
        }

        if ($method === 'GET' && $path === '/composer/config') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->composerConfiguration(
                    $this->request->bearerToken(),
                    $clientId
                ),
                'Composer configuration loaded.'
            );
        }

        if ($method === 'POST' && $path === '/posts/media') {
            // multipart/form-data: text + privacy + feeling + photos[]/postVideo.
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->createMediaPost(
                    $this->request->bearerToken(),
                    $clientId,
                    (string) ($_POST['text'] ?? ''),
                    (string) ($_POST['privacy'] ?? '0'),
                    (string) ($_POST['feeling_type'] ?? ''),
                    (string) ($_POST['feeling'] ?? ''),
                    (string) ($_POST['location'] ?? ''),
                    array_values(array_filter(array_map('intval', explode(',', (string)($_POST['tagged_user_ids'] ?? ''))))),
                    (int) ($_POST['group_id'] ?? 0),
                    (int) ($_POST['page_id'] ?? 0),
                    (string) ($_POST['is_reel'] ?? '0') === '1',
                    (string) ($_POST['activity_type'] ?? ''),
                    (string) ($_POST['activity'] ?? '')
                ),
                'Post published.',
                [],
                201
            );
        }

        if ($method === 'POST' && $path === '/posts') {
            $clientId = $this->gatedClient();
            $json = $this->request->json();
            $text = (string) ($json['text'] ?? '');
            $privacy = (string) ($json['privacy'] ?? '0');
            $colorId = (int) ($json['color_id'] ?? 0);
            Response::success(
                $this->interaction->createPost(
                    $this->request->bearerToken(),
                    $clientId,
                    $text,
                    $privacy,
                    $colorId,
                    (string) ($json['feeling_type'] ?? ''),
                    (string) ($json['feeling'] ?? ''),
                    (string) ($json['location'] ?? ''),
                    is_array($json['poll_options'] ?? null) ? $json['poll_options'] : [],
                    is_array($json['tagged_user_ids'] ?? null) ? $json['tagged_user_ids'] : [],
                    (int) ($json['group_id'] ?? 0),
                    (int) ($json['page_id'] ?? 0),
                    false,
                    (string) ($json['activity_type'] ?? ''),
                    (string) ($json['activity'] ?? '')
                ),
                'Post published.',
                [],
                201
            );
        }

        if ($method === 'GET' && $path === '/users/suggested') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->suggestedUsers(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryInt('limit', 12, 1, 30)
                ),
                'Suggestions loaded.'
            );
        }

        if ($method === 'GET' && $path === '/users/nearby') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->nearbyUsers(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryInt('limit', 10, 1, 35),
                    $this->request->queryInt('offset', 0, 0, 100000),
                    [
                        'gender' => $this->request->queryString('gender'),
                        'status' => $this->request->queryString('status'),
                        'distance' => $this->request->queryString('distance'),
                        'relship' => $this->request->queryString('relship'),
                        'keyword' => $this->request->queryString('keyword'),
                    ]
                ),
                'Nearby users loaded.'
            );
        }

        if ($method === 'POST' && preg_match('#^/pages/(?<id>[0-9]+)/like$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->togglePageLike(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id']
                ),
                'Page reaction updated.'
            );
        }

        if ($method === 'GET' && $path === '/pages/suggested') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->suggestedPages(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryInt('limit', 12, 1, 12)
                ),
                'Page suggestions loaded.'
            );
        }

        if ($method === 'GET' && $path === '/events') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->events->list(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryString('type', 20, 'all'),
                    $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                    $this->request->queryInt('limit', 20, 1, 50)
                ),
                'Events loaded.'
            );
        }

        if ($method === 'GET' && $path === '/movies/config') {
            $clientId = $this->gatedClient();
            Response::success($this->movies->configuration($this->request->bearerToken(), $clientId), 'Movie configuration loaded.');
        }

        if ($method === 'GET' && $path === '/movies') {
            $clientId = $this->gatedClient();
            Response::success($this->movies->list(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 50),
                $this->request->queryString('genre', 80, ''),
                $this->request->queryString('sort', 30, 'latest')
            ), 'Movies loaded.');
        }

        if ($method === 'GET' && preg_match('#^/movies/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->movies->get($this->request->bearerToken(), $clientId, (int) $matches['id']), 'Movie loaded.');
        }

        if ($method === 'POST' && $path === '/movies') {
            $clientId = $this->gatedClient();
            Response::success($this->movies->create($this->request->bearerToken(), $clientId, $this->request->json()), 'Movie created.', [], 201);
        }

        if ($method === 'POST' && preg_match('#^/movies/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->movies->update($this->request->bearerToken(), $clientId, (int) $matches['id'], $this->request->json()), 'Movie updated.');
        }

        if ($method === 'DELETE' && preg_match('#^/movies/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->movies->delete($this->request->bearerToken(), $clientId, (int) $matches['id']), 'Movie deleted.');
        }

        if ($method === 'GET' && preg_match('#^/movies/(?<id>[0-9]+)/comments$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->movies->comments(
                $this->request->bearerToken(), $clientId, (int) $matches['id'],
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 25, 1, 50)
            ), 'Movie comments loaded.');
        }

        if ($method === 'POST' && preg_match('#^/movies/(?<id>[0-9]+)/comments$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->movies->addComment($this->request->bearerToken(), $clientId, (int) $matches['id'], $this->request->json()), 'Comment posted.', [], 201);
        }

        if ($method === 'POST' && preg_match('#^/movies/(?<id>[0-9]+)/boost-test$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->movies->boostTest($this->request->bearerToken(), $clientId, (int) $matches['id']), 'Movie test promotion is ready.');
        }

        if ($method === 'GET' && $path === '/offers') {
            $clientId = $this->gatedClient();
            Response::success($this->offers->list(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 50)
            ), 'Offers loaded.');
        }

        if ($method === 'GET' && preg_match('#^/offers/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->offers->get($this->request->bearerToken(), $clientId, (int) $matches['id']), 'Offer loaded.');
        }

        if ($method === 'POST' && $path === '/offers') {
            $clientId = $this->gatedClient();
            Response::success($this->offers->create(
                $this->request->bearerToken(),
                $clientId,
                $this->request->json()
            ), 'Offer created.', [], 201);
        }

        if ($method === 'POST' && preg_match('#^/offers/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->offers->update(
                $this->request->bearerToken(),
                $clientId,
                (int) $matches['id'],
                $this->request->json()
            ), 'Offer updated.');
        }

        if ($method === 'DELETE' && preg_match('#^/offers/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->offers->delete($this->request->bearerToken(), $clientId, (int) $matches['id']), 'Offer deleted.');
        }

        if ($method === 'GET' && preg_match('#^/events/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->events->get($this->request->bearerToken(), $clientId, (int) $matches['id']),
                'Event loaded.'
            );
        }

        if ($method === 'POST' && $path === '/events') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->events->create(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->json()
                ),
                'Event created.',
                [],
                201
            );
        }

        if ($method === 'POST' && preg_match('#^/events/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->events->update(
                    $this->request->bearerToken(),
                    $clientId,
                    (int) $matches['id'],
                    $this->request->json()
                ),
                'Event updated.'
            );
        }

        if ($method === 'DELETE' && preg_match('#^/events/(?<id>[0-9]+)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->events->delete($this->request->bearerToken(), $clientId, (int) $matches['id']),
                'Event deleted.'
            );
        }

        if ($method === 'POST' && preg_match('#^/events/(?<id>[0-9]+)/(going|interested)$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->events->rsvp(
                    $this->request->bearerToken(),
                    $clientId,
                    (int) $matches['id'],
                    (string) $matches[2]
                ),
                'Event RSVP updated.'
            );
        }

        if ($method === 'GET' && $path === '/events/suggested') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->suggestedEvents(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryInt('limit', 12, 1, 12)
                ),
                'Event suggestions loaded.'
            );
        }

        if ($method === 'POST' && preg_match('#^/events/(?<id>[0-9]+)/interested$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->toggleEventInterested(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id']
                ),
                'Event interest updated.'
            );
        }

        if ($method === 'GET' && $path === '/search/hashtags') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->searchHashtags(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryString('q', 100),
                    $this->request->queryInt('limit', 15, 1, 20)
                ),
                'Hashtag results.'
            );
        }

        if ($method === 'GET' && preg_match('#^/hashtags/(?<tag>[^/]+)/posts$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $result = $this->interaction->hashtagPosts(
                $this->request->bearerToken(),
                $clientId,
                rawurldecode((string)$matches['tag']),
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 20)
            );
            Response::success($result['items'], 'Hashtag posts loaded.', ['next_after' => $result['next_after']]);
        }

        if ($method === 'GET' && $path === '/search/communities') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->searchCommunities(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryString('q', 100),
                    $this->request->queryInt('limit', 30, 1, 30)
                ),
                'Community results.'
            );
        }

        if ($method === 'GET' && $path === '/trending/hashtags') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->trendingHashtags(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryInt('limit', 20, 1, 30),
                    [
                        'gender' => $this->request->queryString('gender', 10, 'all'),
                        'country' => $this->request->queryInt('country', 0, 0, 999),
                        'status' => $this->request->queryString('status', 1),
                        'verified' => $this->request->queryString('verified', 1),
                        'image' => $this->request->queryString('image', 1),
                        'filterbyage' => $this->request->queryString('filterbyage', 1),
                        'age_from' => $this->request->queryInt('age_from', 18, 0, 120),
                        'age_to' => $this->request->queryInt('age_to', 80, 0, 120),
                    ]
                ),
                'Trending hashtags.'
            );
        }

        if ($method === 'GET' && $path === '/search/posts') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->searchPosts(
                $this->request->bearerToken(), $clientId,
                $this->request->queryString('q', 100),
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 20),
                $this->request->queryInt('group_id', 0, 0, PHP_INT_MAX)
            );
            Response::success($result['items'], 'Post search results.', ['next_after' => $result['next_after']]);
        }

        if ($method === 'GET' && $path === '/trending/pro-users') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->trendingProUsers(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryInt('limit', 20, 1, 30)
                ),
                'Pro members.'
            );
        }

        if ($method === 'GET' && $path === '/trending/modules') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->trendingModules(
                $this->request->bearerToken(), $clientId
            ), 'Trending modules.');
        }

        if ($method === 'GET' && $path === '/articles') {
            $clientId = $this->gatedClient();
            Response::success($this->articles->list(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('category', 0, 0, PHP_INT_MAX),
                in_array(
                    strtolower($this->request->queryString('mine', 5, 'false')),
                    ['1', 'true', 'yes'],
                    true
                ),
                $this->request->queryInt('limit', 20, 1, 50),
                $this->request->queryInt('offset', 0, 0, 10000)
            ), 'Articles loaded.');
        }

        if ($method === 'GET' && preg_match('#^/articles/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->articles->detail(
                $this->request->bearerToken(),
                $clientId,
                (int)$matches['id']
            ), 'Article loaded.');
        }

        if ($method === 'GET' && preg_match('#^/articles/(?<id>[0-9]{1,19})/comments$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->articles->comments(
                $this->request->bearerToken(),
                $clientId,
                (int)$matches['id'],
                $this->request->queryInt('limit', 25, 1, 50)
            ), 'Comments loaded.');
        }

        if ($method === 'POST' && preg_match('#^/articles/(?<id>[0-9]{1,19})/comments$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $body = $this->request->json();
            Response::success($this->articles->createComment(
                $this->request->bearerToken(),
                $clientId,
                (int)$matches['id'],
                $this->request->requiredString($body, 'text', 2000)
            ), 'Comment added.', [], 201);
        }

        if ($method === 'POST' && $path === '/articles/web-session') {
            $clientId = $this->gatedClient();
            Response::success($this->articles->webSession(
                $this->request->bearerToken(),
                $clientId
            ), 'Web session created.');
        }

        if ($method === 'GET' && $path === '/marketplace/config') {
            $clientId = $this->gatedClient();
            Response::success($this->marketplace->configuration(
                $this->request->bearerToken(), $clientId
            ), 'Marketplace configuration loaded.');
        }

        if ($method === 'GET' && $path === '/marketplace/products') {
            $clientId = $this->gatedClient();
            Response::success($this->marketplace->products(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryString('scope', 20, 'market'),
                $this->request->queryString('q', 100),
                $this->request->queryInt('category', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 40),
                $this->request->queryInt('distance', 0, 0, 1000)
            ), 'Products loaded.');
        }

        if ($method === 'POST' && $path === '/marketplace/products') {
            $clientId = $this->gatedClient();
            Response::success($this->marketplace->create(
                $this->request->bearerToken(),
                $clientId,
                $_POST,
                isset($_FILES['images']) && is_array($_FILES['images']) ? $_FILES['images'] : null
            ), 'Product created.', [], 201);
        }

        if ($method === 'GET' && preg_match('#^/marketplace/products/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->marketplace->detail(
                $this->request->bearerToken(), $clientId, (int)$matches['id']
            ), 'Product loaded.');
        }

        if ($method === 'POST' && preg_match('#^/marketplace/products/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->marketplace->update(
                $this->request->bearerToken(),
                $clientId,
                (int)$matches['id'],
                $_POST,
                isset($_FILES['images']) && is_array($_FILES['images']) ? $_FILES['images'] : null
            ), 'Product updated.');
        }

        if ($method === 'POST' && preg_match('#^/marketplace/products/(?<id>[0-9]{1,19})/cart$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->marketplace->toggleCart(
                $this->request->bearerToken(), $clientId, (int)$matches['id']
            ), 'Cart updated.');
        }

        if ($method === 'GET' && $path === '/marketplace/cart') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->marketplace->cart($this->request->bearerToken(), $clientId),
                'Cart loaded.'
            );
        }

        if ($method === 'POST' && preg_match('#^/marketplace/cart/(?<id>[0-9]{1,19})/quantity$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->marketplace->updateCartQuantity(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)$matches['id'],
                    (int)($this->request->json()['quantity'] ?? 1)
                ),
                'Cart updated.'
            );
        }

        if ($method === 'POST' && $path === '/marketplace/checkout') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->marketplace->checkout(
                    $this->request->bearerToken(),
                    $clientId,
                    (int)($this->request->json()['address_id'] ?? 0)
                ),
                'Order placed.',
                [],
                201
            );
        }

        if ($method === 'DELETE' && preg_match('#^/marketplace/products/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->marketplace->delete(
                $this->request->bearerToken(), $clientId, (int)$matches['id']
            ), 'Product deleted.');
        }

        if ($method === 'GET' && $path === '/boosted/posts') {
            $clientId = $this->gatedClient();
            Response::success($this->feed->boostedPosts(
                $this->request->bearerToken(), $clientId,
                $this->request->queryInt('limit', 20, 1, 40)
            ), 'Boosted posts loaded.');
        }

        if ($method === 'GET' && $path === '/boosted/pages') {
            $clientId = $this->gatedClient();
            $session = $this->tokens->authenticate($this->request->bearerToken(), $clientId);
            $viewerId = (int)$session['user_id'];
            $this->rateLimiter->enforce('mobile_boosted_pages', (string)$viewerId, 120, 60);
            global $sqlConnect, $site_url;
            $stmt = $sqlConnect->prepare(
                "SELECT p.page_id,p.page_name,p.page_title,p.avatar,p.cover,"
                . "(SELECT COUNT(*) FROM Wo_Pages_Likes pl "
                . "WHERE pl.page_id=p.page_id AND pl.active='1') AS likes "
                . "FROM Wo_Pages p WHERE p.user_id=? AND p.boosted='1' AND p.active='1' "
                . "ORDER BY p.page_id DESC LIMIT 40"
            );
            if ($stmt === false) {
                Response::success([], 'Boosted pages loaded.');
            }
            $stmt->bind_param('i', $viewerId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $media = static function (string $value) use ($site_url): string {
                if ($value === '' || preg_match('#^https?://#i', $value)) return $value;
                return rtrim((string)$site_url, '/') . '/' . ltrim($value, '/');
            };
            Response::success(array_map(static fn(array $row): array => [
                'id' => (int)$row['page_id'],
                'name' => (string)($row['page_title'] ?: $row['page_name']),
                'avatar' => $media((string)$row['avatar']),
                'cover' => $media((string)$row['cover']),
                'likes' => (int)$row['likes'],
            ], $rows), 'Boosted pages loaded.');
        }

        if ($method === 'GET' && $path === '/search/users') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->searchUsers(
                    $this->request->bearerToken(),
                    $clientId,
                    $this->request->queryString('q', 100),
                    $this->request->queryInt('limit', 20, 1, 30)
                ),
                'Search results.'
            );
        }

        if ($method === 'POST' && $path === '/profile') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->updateProfile($this->request->bearerToken(), $clientId, $this->request->json()),
                'Profile updated.'
            );
        }

        if ($method === 'POST' && $path === '/profile/media') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->updateProfileMedia(
                    $this->request->bearerToken(),
                    $clientId,
                    (string) ($_POST['type'] ?? ''),
                    isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : null
                ),
                'Profile image updated.'
            );
        }

        if ($method === 'DELETE' && $path === '/profile/avatar') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->resetProfileAvatar($this->request->bearerToken(), $clientId),
                'Avatar reset.'
            );
        }

        if ($method === 'GET' && $path === '/notifications') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->notifications(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryString('cursor') ?: null,
                $this->request->queryInt('limit', 20, 1, 30)
            );
            Response::success($result['items'], 'Notifications loaded.', ['next_cursor' => $result['next_cursor']]);
        }

        if ($method === 'POST' && preg_match('#^/notifications/(?<id>[0-9]{1,19})/seen$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->markNotificationSeen(
                $this->request->bearerToken(),
                $clientId,
                (int)$matches['id']
            ), 'Notification marked as seen.');
        }

        if ($method === 'POST' && preg_match('#^/notifications/(?<id>[0-9]{1,19})/action$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $body = $this->request->json();
            Response::success($this->interaction->updateNotification(
                $this->request->bearerToken(),
                $clientId,
                (int)$matches['id'],
                $this->request->requiredString($body, 'action', 32)
            ), 'Notification preference updated.');
        }

        if ($method === 'GET' && $path === '/notifications/birthdays') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->friendsBirthdays(
                $this->request->bearerToken(),
                $clientId
            ), 'Friends birthdays loaded.');
        }

        if ($method === 'POST' && $path === '/devices/push') {
            $clientId = $this->gatedClient();
            Response::success($this->interaction->registerPushDevice(
                $this->request->bearerToken(),
                $clientId,
                $this->request->json()
            ), 'Push device registered.');
        }

        if ($method === 'GET' && $path === '/friend-requests') {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->friendRequests($this->request->bearerToken(), $clientId),
                'Requests loaded.'
            );
        }

        if ($method === 'GET' && $path === '/following') {
            $clientId = $this->gatedClient();
            $result = $this->interaction->following(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 10, 1, 30),
                $this->request->queryInt('user_id', 0, 0, PHP_INT_MAX),
                $this->request->queryString('type', 20)
            );
            Response::success(
                $result['items'],
                'Connections loaded.',
                [
                    'next_after' => $result['next_after'],
                    'type' => $result['type'],
                    'user_id' => $result['user_id'],
                ]
            );
        }

        if ($method === 'GET' && $path === '/conversations') {
            $clientId = $this->gatedClient();
            $result = $this->messages->conversations(
                $this->request->bearerToken(),
                $clientId,
                $this->request->queryInt('before', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 20, 1, 40),
                $this->request->queryString('q', 100)
            );
            Response::success($result['items'], 'Conversations loaded.', ['next_before' => $result['next_before']]);
        }

        if ($method === 'GET' && preg_match('#^/conversations/(?<id>[0-9]{1,19})/messages$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $result = $this->messages->messages(
                $this->request->bearerToken(), $clientId, (int) $matches['id'],
                $this->request->queryInt('before_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('after_id', 0, 0, PHP_INT_MAX),
                $this->request->queryInt('limit', 30, 1, 50)
            );
            Response::success($result, 'Messages loaded.', ['next_before' => $result['next_before']]);
        }

        if ($method === 'POST' && preg_match('#^/conversations/(?<id>[0-9]{1,19})/messages$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            $body = str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/')
                ? $_POST : $this->request->json();
            Response::success($this->messages->send(
                $this->request->bearerToken(), $clientId, (int) $matches['id'],
                (string) ($body['text'] ?? ''), (int) ($body['reply_id'] ?? 0)
            ), 'Message sent.', [], 201);
        }

        if ($method === 'POST' && preg_match('#^/conversations/(?<id>[0-9]{1,19})/typing$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->messages->setTyping(
                $this->request->bearerToken(), $clientId, (int) $matches['id'],
                (string) ($this->request->json()['status'] ?? 'idle')
            ));
        }

        if ($method === 'POST' && preg_match('#^/messages/(?<id>[0-9]{1,19})/react$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->messages->react(
                $this->request->bearerToken(), $clientId, (int) $matches['id'],
                (int) ($this->request->json()['reaction'] ?? 0)
            ), 'Message reaction saved.');
        }

        if ($method === 'DELETE' && preg_match('#^/messages/(?<id>[0-9]{1,19})$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success($this->messages->delete(
                $this->request->bearerToken(), $clientId, (int) $matches['id']
            ), 'Message deleted.');
        }

        if ($method === 'POST' && preg_match('#^/users/(?<id>[0-9]{1,19})/accept-request$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->acceptRequest($this->request->bearerToken(), $clientId, (int) $matches['id']),
                'Request accepted.'
            );
        }

        if ($method === 'POST' && preg_match('#^/users/(?<id>[0-9]{1,19})/decline-request$#', $path, $matches) === 1) {
            $clientId = $this->gatedClient();
            Response::success(
                $this->interaction->declineRequest($this->request->bearerToken(), $clientId, (int) $matches['id']),
                'Request declined.'
            );
        }

        throw new ApiException(404, 'ROUTE_NOT_FOUND', 'The requested API route does not exist.');
    }

    /** Returns the enabled server-configured client id for token binding. */
    private function resolvedClientId(): string
    {
        $client = $this->configuration->client($this->request->publicClientId());
        return (string) $client['public_client_id'];
    }

    /** Asserts mobile availability and returns the resolved client id. */
    private function gatedClient(): string
    {
        $clientId = $this->resolvedClientId();
        $this->configuration->assertMobileAvailable();
        return $clientId;
    }
}
