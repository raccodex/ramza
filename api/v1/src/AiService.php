<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

use Throwable;

/**
 * Authenticated mobile bridge for the script's existing AI providers.
 *
 * Provider credentials never leave the server. The same admin feature
 * switches, audience rules and credit accounting used by the web app apply.
 */
final class AiService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    private function viewer(string $accessToken, string $clientId): array
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $userId = (int)($session['user_id'] ?? 0);
        global $wo;
        $user = \Wo_UserData($userId);
        if (!is_array($user) || empty($user['user_id'])) {
            throw new ApiException(401, 'ACCOUNT_UNAVAILABLE', 'This account is unavailable.');
        }
        $wo['loggedin'] = true;
        $wo['user'] = $user;
        $wo['user']['id'] = $userId;
        return [$userId, $user];
    }

    private function allowed(array $user, string $rule): bool
    {
        global $wo;
        $audience = strtolower((string)($wo['config'][$rule] ?? 'all'));
        return match ($audience) {
            'admin' => !empty($user['admin']),
            'verified' => !empty($user['verified']) || !empty($user['admin']),
            'pro' => !empty($user['is_pro']) || !empty($user['admin']),
            default => true,
        };
    }

    private function textEnabled(array $user): bool
    {
        global $wo;
        return (string)($wo['config']['ai_post_system'] ?? '0') === '1'
            && $this->allowed($user, 'ai_post_use');
    }

    private function imageEnabled(array $user): bool
    {
        global $wo;
        return (string)($wo['config']['ai_image_system'] ?? '0') === '1'
            && $this->allowed($user, 'ai_image_use');
    }

    public function configuration(string $accessToken, string $clientId): array
    {
        [, $user] = $this->viewer($accessToken, $clientId);
        global $wo;
        $textProvider = (string)($wo['config']['post_ai'] ?? 'openai');
        $imageProvider = (string)($wo['config']['site_assistant_image_provider'] ?? 'site_default');
        if ($imageProvider === 'site_default') {
            $imageProvider = (string)($wo['config']['images_ai'] ?? 'openai');
        }
        return [
            'text_enabled' => $this->textEnabled($user),
            'chat_enabled' => $this->textEnabled($user),
            'image_enabled' => $this->imageEnabled($user),
            'text_provider' => $textProvider,
            'text_model' => $textProvider === 'cloudflare'
                ? (string)($wo['config']['cloudflare_ai_text_model'] ?? '@cf/meta/llama-3.1-8b-instruct')
                : ($textProvider === 'gemini'
                    ? (string)($wo['config']['gemini_model'] ?? '')
                    : ($textProvider === 'custom'
                        ? (string)($wo['config']['custom_ai_model'] ?? '')
                        : (string)($wo['config']['openai_text_model'] ?? ''))),
            'image_provider' => $imageProvider,
            'credits' => (float)($user['credits'] ?? 0),
        ];
    }

    public function generateText(
        string $accessToken,
        string $clientId,
        array $input,
        bool $chat = false
    ): array {
        [$userId, $user] = $this->viewer($accessToken, $clientId);
        if (!$this->textEnabled($user)) {
            throw new ApiException(403, 'AI_TEXT_DISABLED', 'AI text generation is not available for this account.');
        }
        $this->rateLimiter->enforce($chat ? 'mobile_ai_chat' : 'mobile_ai_text', (string)$userId, 20, 60);
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '' || mb_strlen($prompt) > 4000) {
            throw new ApiException(422, 'AI_PROMPT_INVALID', 'Enter a prompt up to 4000 characters.', 'prompt');
        }
        $maxTokens = max(32, min(1200, (int)($input['max_tokens'] ?? 500)));
        if ($chat) {
            $history = is_array($input['history'] ?? null) ? array_slice($input['history'], -10) : [];
            $lines = [];
            foreach ($history as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $role = (string)($message['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
                $content = trim(strip_tags((string)($message['content'] ?? '')));
                if ($content !== '') {
                    $lines[] = $role . ': ' . mb_substr($content, 0, 1000);
                }
            }
            $prompt = "Continue this helpful social-app conversation. Return only the assistant reply.\n"
                . implode("\n", $lines)
                . "\nUser: " . $prompt;
        }
        try {
            $result = \getOpenAiText($prompt, $maxTokens);
        } catch (Throwable $error) {
            throw new ApiException(502, 'AI_PROVIDER_ERROR', $error->getMessage());
        }
        return [
            'text' => trim((string)($result['output'] ?? '')),
            'credits' => (float)($result['credits'] ?? 0),
        ];
    }

    public function generateImage(string $accessToken, string $clientId, array $input): array
    {
        [$userId, $user] = $this->viewer($accessToken, $clientId);
        if (!$this->imageEnabled($user)) {
            throw new ApiException(403, 'AI_IMAGE_DISABLED', 'AI image generation is not available for this account.');
        }
        $this->rateLimiter->enforce('mobile_ai_image', (string)$userId, 5, 60);
        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '' || mb_strlen($prompt) > 1800) {
            throw new ApiException(422, 'AI_PROMPT_INVALID', 'Enter an image prompt up to 1800 characters.', 'prompt');
        }
        global $wo, $db;
        if (($wo['config']['images_credit_system'] ?? 0) == 1
            && function_exists('shouldTopUpImageCredits')
            && \shouldTopUpImageCredits($user['credits'] ?? 0, 1)) {
            throw new ApiException(402, 'AI_CREDITS_REQUIRED', 'You do not have enough image credits.');
        }
        try {
            $stored = \Wo_SiteAssistantGeneratedImage($prompt);
        } catch (Throwable $error) {
            throw new ApiException(502, 'AI_PROVIDER_ERROR', $error->getMessage());
        }
        $provider = (string)($wo['config']['site_assistant_image_provider'] ?? 'site_default');
        if ($provider === 'site_default') {
            $provider = (string)($wo['config']['images_ai'] ?? 'openai') === 'openai'
                ? 'openai'
                : (!empty($wo['config']['gemini_api_key']) ? 'gemini' : 'openai');
        }
        if ($provider !== 'openai'
            && ($wo['config']['images_credit_system'] ?? 0) == 1
            && (float)($wo['config']['generated_image_price'] ?? 0) > 0) {
            $db->where('user_id', $userId)->update(T_USERS, [
                'credits' => $db->dec((float)$wo['config']['generated_image_price']),
            ]);
        }
        return [
            'url' => \Wo_GetMedia($stored),
            'path' => $stored,
            'credits' => (float)$db->where('user_id', $userId)->getValue(T_USERS, 'credits'),
        ];
    }
}
