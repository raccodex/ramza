<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

final class JobService
{
    private const TYPES = ['full_time', 'part_time', 'internship', 'volunteer', 'contract'];
    private const PERIODS = ['per_hour', 'per_day', 'per_week', 'per_month', 'per_year'];
    private const QUESTION_TYPES = ['free_text_question', 'yes_no_question', 'multiple_choice_question'];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    private function viewer(string $token, string $clientId): int
    {
        $session = $this->tokens->authenticate($token, $clientId);
        $id = (int)($session['user_id'] ?? 0);
        $this->rateLimiter->enforce('mobile_jobs', (string)$id, 120, 60);
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
        if ($value === '' || preg_match('#^https?://#i', $value) === 1) return $value;
        return function_exists('Wo_GetMedia') ? (string)\Wo_GetMedia($value) : $value;
    }

    private function clean(string $value): string
    {
        return html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function answers(mixed $value): array
    {
        if (is_array($value)) return array_values(array_map('strval', $value));
        $decoded = json_decode((string)$value, true);
        if (is_array($decoded)) return array_values(array_map('strval', $decoded));
        return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
    }

    private function present(array $job, int $viewerId): array
    {
        global $db, $wo;
        $id = (int)($job['id'] ?? 0);
        $page = is_array($job['page'] ?? null) ? $job['page'] : \Wo_PageData((int)($job['page_id'] ?? 0));
        $page = is_array($page) ? $page : [];
        $applied = $db->where('job_id', $id)->where('user_id', $viewerId)->getValue(T_JOB_APPLY, 'COUNT(*)') > 0;
        $applyCount = (int)$db->where('job_id', $id)->getValue(T_JOB_APPLY, 'COUNT(*)');
        $post = $db->where('job_id', $id)->getOne(T_POSTS, ['id', 'boosted']);
        $isOwner = (int)($page['user_id'] ?? $job['user_id'] ?? 0) === $viewerId;
        $categoryId = (int)($job['category'] ?? 0);
        $categoryName = (string)(($wo['job_categories'] ?? [])[$categoryId] ?? '');
        if ($categoryName === '') $categoryName = 'Unknown';
        $questions = [];
        foreach (['one', 'two', 'three'] as $key) {
            $text = $this->clean((string)($job["question_{$key}"] ?? ''));
            if ($text !== '') {
                $questions[] = [
                    'key' => $key,
                    'question' => $text,
                    'type' => (string)($job["question_{$key}_type"] ?? 'free_text_question'),
                    'answers' => $this->answers($job["question_{$key}_answers"] ?? ''),
                ];
            }
        }
        return [
            'id' => $id,
            'user_id' => (int)($job['user_id'] ?? 0),
            'page_id' => (int)($job['page_id'] ?? 0),
            'post_id' => (int)($post->id ?? $job['post_id'] ?? 0),
            'title' => $this->clean((string)($job['title'] ?? '')),
            'description' => $this->clean((string)($job['description'] ?? '')),
            'location' => $this->clean((string)($job['location'] ?? '')),
            'lat' => (string)($job['lat'] ?? ''),
            'lng' => (string)($job['lng'] ?? ''),
            'minimum' => (string)($job['minimum'] ?? '0'),
            'maximum' => (string)($job['maximum'] ?? '0'),
            'salary_date' => (string)($job['salary_date'] ?? ''),
            'job_type' => (string)($job['job_type'] ?? ''),
            'category' => $categoryId,
            'category_name' => $this->clean($categoryName),
            'currency' => SystemCurrency::normalize((string)($job['currency'] ?? '')),
            'image' => $this->media((string)($job['image'] ?? '')),
            'time' => (int)($job['time'] ?? 0),
            'questions' => $questions,
            'is_owner' => $isOwner,
            'has_applied' => $applied,
            'apply_count' => $applyCount,
            'is_boosted' => (int)($post->boosted ?? 0) === 1,
            'button_text' => $isOwner ? "Show applies ({$applyCount})" : ($applied ? 'Already applied' : 'Apply now'),
            'page' => [
                'id' => (int)($page['page_id'] ?? 0),
                'username' => (string)($page['page_name'] ?? ''),
                'title' => $this->clean((string)($page['page_title'] ?? '')),
                'avatar' => $this->media((string)($page['avatar'] ?? '')),
            ],
        ];
    }

    public function configuration(string $token, string $clientId): array
    {
        $viewerId = $this->viewer($token, $clientId);
        global $db, $wo;
        $pages = $db->where('user_id', $viewerId)->orderBy('page_id', 'DESC')->get(T_PAGES);
        $managed = [];
        foreach ($pages as $page) {
            $managed[] = [
                'id' => (int)$page->page_id,
                'username' => (string)$page->page_name,
                'title' => $this->clean((string)$page->page_title),
                'avatar' => $this->media((string)$page->avatar),
                'cover' => $this->media((string)$page->cover),
            ];
        }
        $categories = [];
        foreach ((array)($wo['job_categories'] ?? []) as $id => $label) {
            $categories[(string)$id] = $this->clean((string)$label);
        }
        if ($categories === []) {
            foreach ($db->get(T_JOB_CATEGORY) as $row) $categories[(string)$row->id] = 'Category ' . $row->id;
        }
        return [
            'types' => self::TYPES,
            'salary_periods' => self::PERIODS,
            'question_types' => self::QUESTION_TYPES,
            'categories' => $categories,
            'managed_pages' => $managed,
            'currencies' => array_column(SystemCurrency::options(), 'code'),
        ];
    }

    public function list(string $token, string $clientId, int $afterId, int $limit, string $keyword, string $type, int $category, int $distance): array
    {
        $viewerId = $this->viewer($token, $clientId);
        $query = ['after_id' => max(0, $afterId), 'limit' => max(1, min(50, $limit))];
        if ($keyword !== '') $query['keyword'] = $keyword;
        if (in_array($type, self::TYPES, true)) $query['type'] = $type;
        if ($category > 0) $query['c_id'] = $category;
        if ($distance > 0) $query['length'] = $distance;
        $items = [];
        foreach ((array)\Wo_GetAllJobs($query) as $row) if (is_array($row)) $items[] = $this->present($row, $viewerId);
        return $items;
    }

    public function get(string $token, string $clientId, int $id): array
    {
        $viewerId = $this->viewer($token, $clientId);
        $job = \Wo_GetJobById($id);
        if (!is_array($job) || empty($job['id'])) throw new ApiException(404, 'JOB_NOT_FOUND', 'This job is unavailable.');
        return $this->present($job, $viewerId);
    }

    private function values(array $payload): array
    {
        $title = trim((string)($payload['job_title'] ?? $payload['title'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $location = trim((string)($payload['location'] ?? ''));
        $type = (string)($payload['job_type'] ?? '');
        $period = (string)($payload['salary_date'] ?? '');
        $category = (int)($payload['category'] ?? 0);
        $minimum = (float)($payload['minimum'] ?? 0);
        $maximum = (float)($payload['maximum'] ?? 0);
        if ($title === '' || $description === '' || $location === '' || $category < 1 || !in_array($type, self::TYPES, true) || !in_array($period, self::PERIODS, true) || $minimum <= 0 || $maximum < $minimum) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Complete the title, location, salary, type, category and description.', 'job_title');
        }
        $values = [
            'title' => \Wo_Secure($title), 'description' => \Wo_Secure($description),
            'location' => \Wo_Secure($location), 'lat' => \Wo_Secure((string)($payload['lat'] ?? '')),
            'lng' => \Wo_Secure((string)($payload['lng'] ?? '')), 'minimum' => (string)$minimum,
            'maximum' => (string)$maximum, 'salary_date' => $period, 'job_type' => $type,
            'category' => (string)$category, 'currency' => \Wo_Secure(SystemCurrency::normalize((string)($payload['currency'] ?? ''))),
        ];
        foreach (['one', 'two', 'three'] as $key) {
            $question = trim((string)($payload["question_{$key}"] ?? ''));
            $questionType = (string)($payload["question_{$key}_type"] ?? 'free_text_question');
            if ($question !== '' && in_array($questionType, self::QUESTION_TYPES, true)) {
                $values["question_{$key}"] = \Wo_Secure($question);
                $values["question_{$key}_type"] = $questionType;
                $values["question_{$key}_answers"] = json_encode((object)$this->answers($payload["question_{$key}_answers"] ?? ''));
            }
        }
        return $values;
    }

    public function create(string $token, string $clientId, array $payload): array
    {
        $viewerId = $this->viewer($token, $clientId);
        $payload += $_POST;
        $pageId = (int)($payload['page_id'] ?? 0);
        global $db;
        $page = $db->where('page_id', $pageId)->where('user_id', $viewerId)->getOne(T_PAGES);
        if (!$page) throw new ApiException(403, 'FORBIDDEN', 'Select a Page you own.');
        $values = $this->values($payload);
        if (!empty($_FILES['thumbnail']['tmp_name'])) {
            $media = \Wo_ShareFile([
                'file' => $_FILES['thumbnail']['tmp_name'], 'name' => $_FILES['thumbnail']['name'] ?? 'job.jpg',
                'size' => $_FILES['thumbnail']['size'] ?? 0, 'type' => $_FILES['thumbnail']['type'] ?? 'image/jpeg',
                'types' => 'jpeg,jpg,png,bmp',
            ]);
            if (!is_array($media) || empty($media['filename'])) throw new ApiException(422, 'VALIDATION_FAILED', 'The selected image is not supported.', 'thumbnail');
            $values['image'] = $media['filename'];
            $values['image_type'] = 'upload';
        } else {
            $values['image'] = (string)$page->cover;
            $values['image_type'] = 'cover';
        }
        $values += ['page_id' => $pageId, 'user_id' => $viewerId, 'status' => 1, 'time' => time()];
        $jobId = (int)$db->insert(T_JOB, $values);
        if ($jobId < 1) throw new ApiException(500, 'JOB_CREATE_FAILED', 'The job could not be created.');
        $postId = (int)$db->insert(T_POSTS, ['page_id' => $pageId, 'postText' => $values['title'], 'job_id' => $jobId, 'postType' => 'job', 'postPrivacy' => 0, 'time' => time()]);
        if ($postId > 0) $db->where('id', $postId)->update(T_POSTS, ['post_id' => $postId]);
        return $this->get($token, $clientId, $jobId);
    }

    public function update(string $token, string $clientId, int $id, array $payload): array
    {
        $viewerId = $this->viewer($token, $clientId);
        global $db;
        $job = $db->where('id', $id)->getOne(T_JOB);
        if (!$job) throw new ApiException(404, 'JOB_NOT_FOUND', 'This job is unavailable.');
        if ((int)$job->user_id !== $viewerId) throw new ApiException(403, 'FORBIDDEN', 'Only the job owner can edit it.');
        $payload += $_POST;
        $values = $this->values($payload);
        if (!empty($_FILES['thumbnail']['tmp_name'])) {
            $media = \Wo_ShareFile(['file' => $_FILES['thumbnail']['tmp_name'], 'name' => $_FILES['thumbnail']['name'] ?? 'job.jpg', 'size' => $_FILES['thumbnail']['size'] ?? 0, 'type' => $_FILES['thumbnail']['type'] ?? 'image/jpeg', 'types' => 'jpeg,jpg,png,bmp']);
            if (is_array($media) && !empty($media['filename'])) { $values['image'] = $media['filename']; $values['image_type'] = 'upload'; }
        }
        $db->where('id', $id)->update(T_JOB, $values);
        $db->where('job_id', $id)->update(T_POSTS, ['postText' => $values['title']]);
        return $this->get($token, $clientId, $id);
    }

    public function apply(string $token, string $clientId, int $id, array $payload): array
    {
        $viewerId = $this->viewer($token, $clientId);
        global $db;
        $job = $db->where('id', $id)->getOne(T_JOB);
        if (!$job) throw new ApiException(404, 'JOB_NOT_FOUND', 'This job is unavailable.');
        if ((int)$job->user_id === $viewerId) throw new ApiException(403, 'FORBIDDEN', 'A Page owner cannot apply to their own job.');
        if ($db->where('job_id', $id)->where('user_id', $viewerId)->getValue(T_JOB_APPLY, 'COUNT(*)') > 0) throw new ApiException(409, 'ALREADY_APPLIED', 'You already applied for this job.');
        foreach (['user_name', 'phone_number', 'location', 'email'] as $field) if (trim((string)($payload[$field] ?? '')) === '') throw new ApiException(422, 'VALIDATION_FAILED', 'Complete all contact fields.', $field);
        $data = ['job_id' => $id, 'page_id' => (int)$job->page_id, 'user_id' => $viewerId, 'time' => time()];
        foreach (['user_name','phone_number','location','email','question_one_answer','question_two_answer','question_three_answer','position','where_did_you_work','experience_description','experience_start_date','experience_end_date'] as $field) $data[$field] = \Wo_Secure((string)($payload[$field] ?? ''));
        $applyId = (int)$db->insert(T_JOB_APPLY, $data);
        if ($applyId < 1) throw new ApiException(500, 'JOB_APPLY_FAILED', 'Your application could not be submitted.');
        \Wo_RegisterNotification(['recipient_id' => (int)$job->user_id, 'type' => 'apply_job', 'url' => 'index.php?link1=timeline&type=job_apply&id=' . $id]);
        return ['applied' => true, 'application_id' => $applyId];
    }

    public function applications(string $token, string $clientId, int $id): array
    {
        $viewerId = $this->viewer($token, $clientId);
        global $db;
        $job = $db->where('id', $id)->getOne(T_JOB);
        if (!$job || (int)$job->user_id !== $viewerId) throw new ApiException(403, 'FORBIDDEN', 'Only the Page owner can view applications.');
        $rows = $db->where('job_id', $id)->orderBy('id', 'DESC')->get(T_JOB_APPLY);
        return array_map(static fn($row) => [
            'id' => (int)$row->id, 'user_id' => (int)$row->user_id, 'user_name' => (string)$row->user_name,
            'phone_number' => (string)$row->phone_number, 'location' => (string)$row->location, 'email' => (string)$row->email,
            'position' => (string)$row->position, 'where_did_you_work' => (string)$row->where_did_you_work,
            'experience_description' => (string)$row->experience_description, 'experience_start_date' => (string)$row->experience_start_date,
            'experience_end_date' => (string)$row->experience_end_date, 'time' => (int)$row->time,
            'answers' => [(string)$row->question_one_answer, (string)$row->question_two_answer, (string)$row->question_three_answer],
        ], $rows);
    }

    public function delete(string $token, string $clientId, int $id): array
    {
        $viewerId = $this->viewer($token, $clientId);
        global $db;
        $job = $db->where('id', $id)->getOne(T_JOB);
        if (!$job) throw new ApiException(404, 'JOB_NOT_FOUND', 'This job is unavailable.');
        if ((int)$job->user_id !== $viewerId && !\Wo_IsAdmin() && !\Wo_IsModerator()) throw new ApiException(403, 'FORBIDDEN', 'Only the job owner can delete it.');
        $db->where('job_id', $id)->delete(T_JOB_APPLY);
        $db->where('job_id', $id)->delete(T_POSTS);
        $db->where('id', $id)->delete(T_JOB);
        return ['deleted' => true];
    }

    public function boostTest(string $token, string $clientId, int $id): array
    {
        $viewerId = $this->viewer($token, $clientId);
        global $db;
        $job = $db->where('id', $id)->getOne(T_JOB);
        if (!$job || (int)$job->user_id !== $viewerId) throw new ApiException(403, 'FORBIDDEN', 'Only the job owner can promote it.');
        $db->where('job_id', $id)->update(T_POSTS, ['boosted' => 1]);
        return ['boosted' => true];
    }
}
