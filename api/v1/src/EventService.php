<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class EventService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    private function viewer(string $accessToken, string $clientId): int
    {
        $session = $this->tokens->authenticate($accessToken, $clientId);
        $id = (int) ($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_events', (string) $id, 120, 60);
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

    private function media(string $value): string
    {
        global $site_url;
        if ($value === '' || preg_match('#^https?://#i', $value)) return $value;
        return rtrim((string) $site_url, '/') . '/' . ltrim($value, '/');
    }

    private function present(array $event, int $viewerId): array
    {
        $id = (int) ($event['id'] ?? 0);
        $owner = (int) ($event['poster_id'] ?? 0) === $viewerId;
        return [
            'id' => $id,
            'name' => html_entity_decode(strip_tags((string) ($event['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'location' => html_entity_decode(strip_tags((string) ($event['location'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => html_entity_decode(strip_tags((string) ($event['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'start_date' => (string) ($event['start_date'] ?? ''),
            'start_time' => (string) ($event['start_time'] ?? ''),
            'end_date' => (string) ($event['end_date'] ?? ''),
            'end_time' => (string) ($event['end_time'] ?? ''),
            'cover' => $this->media((string) ($event['cover'] ?? '')),
            'poster_id' => (int) ($event['poster_id'] ?? 0),
            'is_owner' => $owner,
            'is_going' => function_exists('Wo_EventGoingExists') && \Wo_EventGoingExists($id),
            'is_interested' => function_exists('Wo_EventInterestedExists') && \Wo_EventInterestedExists($id),
            'going_count' => function_exists('Wo_TotalGoingUsers') ? (int) \Wo_TotalGoingUsers($id) : 0,
            'interested_count' => function_exists('Wo_TotalInterestedUsers') ? (int) \Wo_TotalInterestedUsers($id) : 0,
        ];
    }

    public function list(string $accessToken, string $clientId, string $type, int $offset, int $limit): array
    {
        $viewerId = $this->viewer($accessToken, $clientId);
        $offset = max(0, $offset);
        $limit = max(1, min(50, $limit));
        $rows = match ($type) {
            'going' => \Wo_GetGoingEvents($offset, $limit),
            'invited' => \Wo_GetInvitedEvents($offset, $limit),
            'interested' => \Wo_GetInterestedEvents($offset, $limit),
            'past' => \Wo_GetPastEvents($offset, $limit),
            'my' => \Wo_GetMyEvents($offset, $limit),
            default => \Wo_GetEvents(['offset' => $offset, 'limit' => $limit]),
        };
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) $items[] = $this->present($row, $viewerId);
        }
        return $items;
    }

    public function get(string $accessToken, string $clientId, int $id): array
    {
        $viewerId = $this->viewer($accessToken, $clientId);
        $event = \Wo_EventData($id);
        if (!is_array($event) || empty($event['id'])) {
            throw new ApiException(404, 'EVENT_NOT_FOUND', 'This event is unavailable.');
        }
        return $this->present($event, $viewerId);
    }

    public function create(string $accessToken, string $clientId, array $payload): array
    {
        $viewerId = $this->viewer($accessToken, $clientId);
        $payload += $_POST;
        $required = ['name', 'location', 'description', 'start_date', 'start_time', 'end_date', 'end_time'];
        foreach ($required as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                throw new ApiException(422, 'VALIDATION_FAILED', "Event {$field} is required.", $field);
            }
        }
        if (mb_strlen(trim((string) $payload['name'])) < 5) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Event name must be at least 5 characters.', 'name');
        }
        if (mb_strlen(trim((string) $payload['description'])) < 10) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Event description must be at least 10 characters.', 'description');
        }
        $id = \Wo_InsertEvent([
            'name' => \Wo_Secure((string) $payload['name']),
            'location' => \Wo_Secure((string) $payload['location']),
            'description' => \Wo_Secure((string) $payload['description']),
            'start_date' => \Wo_Secure((string) $payload['start_date']),
            'start_time' => \Wo_Secure((string) $payload['start_time']),
            'end_date' => \Wo_Secure((string) $payload['end_date']),
            'end_time' => \Wo_Secure((string) $payload['end_time']),
            'poster_id' => $viewerId,
        ]);
        if (!$id) throw new ApiException(500, 'EVENT_CREATE_FAILED', 'The event could not be created.');
        if (!empty($_FILES['event_cover']['tmp_name'])) {
            \Wo_UploadImage(
                $_FILES['event_cover']['tmp_name'],
                $_FILES['event_cover']['name'] ?? 'event-cover.jpg',
                'cover',
                $_FILES['event_cover']['type'] ?? 'image/jpeg',
                $id,
                'event'
            );
        }
        return $this->get($accessToken, $clientId, (int) $id);
    }

    public function update(string $accessToken, string $clientId, int $id, array $payload): array
    {
        $viewerId = $this->viewer($accessToken, $clientId);
        if (!\Is_EventOwner($id, false, false)) {
            throw new ApiException(403, 'FORBIDDEN', 'Only the event owner can edit this event.');
        }
        $payload += $_POST;
        $fields = ['name', 'location', 'description', 'start_date', 'start_time', 'end_date', 'end_time'];
        foreach ($fields as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                throw new ApiException(422, 'VALIDATION_FAILED', "Event {$field} is required.", $field);
            }
        }
        \Wo_UpdateEvent($id, array_intersect_key($payload, array_flip($fields)));
        if (!empty($_FILES['event_cover']['tmp_name'])) {
            \Wo_UploadImage($_FILES['event_cover']['tmp_name'], $_FILES['event_cover']['name'] ?? 'event-cover.jpg', 'cover', $_FILES['event_cover']['type'] ?? 'image/jpeg', $id, 'event');
        }
        return $this->get($accessToken, $clientId, $id);
    }

    public function delete(string $accessToken, string $clientId, int $id): array
    {
        $this->viewer($accessToken, $clientId);
        if (!\Is_EventOwner($id, false, false)) throw new ApiException(403, 'FORBIDDEN', 'Only the event owner can delete this event.');
        if (!\Wo_DeleteEvent($id)) throw new ApiException(500, 'EVENT_DELETE_FAILED', 'The event could not be deleted.');
        return ['deleted' => true];
    }

    public function rsvp(string $accessToken, string $clientId, int $id, string $kind): array
    {
        $this->viewer($accessToken, $clientId);
        if (!is_array(\Wo_EventData($id))) throw new ApiException(404, 'EVENT_NOT_FOUND', 'This event is unavailable.');
        $going = \Wo_EventGoingExists($id);
        $interested = \Wo_EventInterestedExists($id);
        if ($kind === 'going') {
            if ($going) \Wo_UnsetEventGoingUsers($id); else \Wo_AddEventGoingUsers($id);
        } elseif ($kind === 'interested') {
            if ($interested) \Wo_UnsetEventInterestedUsers($id); else \Wo_AddEventInterestedUsers($id);
        } else {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Unsupported RSVP type.', 'type');
        }
        return $this->get($accessToken, $clientId, $id);
    }
}
