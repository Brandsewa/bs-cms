<?php

declare(strict_types=1);

namespace BsCms\Plugins;

use BsCms\Blocks\BlockRegistry;
use BsCms\Database\Connection;
use BsCms\Security\Ulid;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class ContactFormPlugin implements PluginInterface
{
    public const HANDLE = 'contact-form';

    public function __construct(
        private readonly Connection $connection,
        private readonly ?BlockRegistry $blockRegistry = null
    ) {
    }

    public function getHandle(): string
    {
        return self::HANDLE;
    }

    public function getName(): string
    {
        return 'Contact Form & Inquiries';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Create responsive contact forms, embed them into any page or section via blocks or snippets, and view incoming submissions from the admin backend.';
    }

    public function getAuthor(): string
    {
        return 'BS CMS Official';
    }

    public function getCategory(): string
    {
        return 'Forms & Feedback';
    }

    public function getIcon(): string
    {
        return 'mail';
    }

    public function getDefaultConfig(): array
    {
        return [
            'default_notify_email' => '',
            'enable_honeypot' => true,
            'rate_limit_per_minute' => 10,
        ];
    }

    public function boot(): void
    {
        $registry = $this->blockRegistry ?? BlockRegistry::getInstance();
        if ($registry !== null && !$registry->has('contact_form')) {
            $registry->register([
                'name' => 'contact_form',
                'label' => 'Contact Form',
                'description' => 'Embed a dynamic contact form with inquiry management',
                'category' => 'interactive',
                'icon' => 'mail',
                'fields' => [
                    [
                        'name' => 'form_handle',
                        'label' => 'Form Handle',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Unique handle of the published form to embed (e.g. contact-us)',
                    ],
                    [
                        'name' => 'heading',
                        'label' => 'Section Heading',
                        'type' => 'string',
                        'required' => false,
                    ],
                    [
                        'name' => 'subheading',
                        'label' => 'Subheading or Instructions',
                        'type' => 'string',
                        'required' => false,
                    ],
                    [
                        'name' => 'card_style',
                        'label' => 'Wrap in Card Container',
                        'type' => 'boolean',
                        'required' => false,
                        'default' => true,
                    ],
                ],
            ]);
        }
    }

    /**
     * List all form definitions with submission metrics.
     *
     * @return list<array<string, mixed>>
     */
    public function getForms(): array
    {
        $formsTable = $this->connection->prefixTable('form_definitions');
        $subsTable = $this->connection->prefixTable('form_submissions');
        $pdo = $this->connection->getPdo();

        $sql = "SELECT f.*,
                COALESCE((SELECT COUNT(*) FROM `{$subsTable}` s WHERE s.form_id = f.id), 0) AS submissions_count,
                COALESCE((SELECT COUNT(*) FROM `{$subsTable}` s WHERE s.form_id = f.id AND s.status = 'unread'), 0) AS unread_count
                FROM `{$formsTable}` f
                ORDER BY f.created_at DESC";

        $stmt = $pdo->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrateForm($row);
        }

        return $result;
    }

    /**
     * Get single form definition by ID or handle.
     *
     * @return array<string, mixed>|null
     */
    public function getForm(string $idOrHandle): ?array
    {
        $formsTable = $this->connection->prefixTable('form_definitions');
        $subsTable = $this->connection->prefixTable('form_submissions');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT f.*,
                COALESCE((SELECT COUNT(*) FROM `{$subsTable}` s WHERE s.form_id = f.id), 0) AS submissions_count,
                COALESCE((SELECT COUNT(*) FROM `{$subsTable}` s WHERE s.form_id = f.id AND s.status = 'unread'), 0) AS unread_count
                FROM `{$formsTable}` f
                WHERE f.id = ? OR f.handle = ?
                LIMIT 1");
        $stmt->execute([$idOrHandle, $idOrHandle]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateForm($row);
    }

    /**
     * Create a new form definition.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createForm(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException("Form 'title' is required.");
        }

        $rawHandle = (string) ($data['handle'] ?? '');
        $handle = $rawHandle !== '' ? $this->slugify($rawHandle) : $this->slugify($title);
        if ($handle === '') {
            $handle = 'form-' . substr(strtolower(Ulid::generate()), 0, 8);
        }

        $existing = $this->getForm($handle);
        if ($existing !== null) {
            throw new InvalidArgumentException("Form handle '{$handle}' is already taken.");
        }

        $id = Ulid::generate();
        $status = in_array($data['status'] ?? 'draft', ['draft', 'published', 'archived'], true)
            ? (string) $data['status']
            : 'draft';
        $description = isset($data['description']) ? trim((string) $data['description']) : null;

        $fields = $this->normalizeFields($data['fields'] ?? []);
        $settings = $this->normalizeSettings($data['settings'] ?? []);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $formsTable = $this->connection->prefixTable('form_definitions');

        $stmt = $this->connection->getPdo()->prepare("INSERT INTO `{$formsTable}`
            (`id`, `handle`, `title`, `description`, `status`, `fields`, `settings`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $id,
            $handle,
            $title,
            $description,
            $status,
            json_encode($fields, JSON_THROW_ON_ERROR),
            json_encode($settings, JSON_THROW_ON_ERROR),
            $now,
            $now,
        ]);

        return (array) $this->getForm($id);
    }

    /**
     * Update an existing form definition.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateForm(string $id, array $data): array
    {
        $existing = $this->getForm($id);
        if ($existing === null) {
            throw new RuntimeException("Form '{$id}' not found.");
        }

        $title = isset($data['title']) ? trim((string) $data['title']) : (string) $existing['title'];
        if ($title === '') {
            throw new InvalidArgumentException("Form 'title' cannot be empty.");
        }

        $handle = (string) $existing['handle'];
        if (isset($data['handle']) && trim((string) $data['handle']) !== '') {
            $candidateHandle = $this->slugify((string) $data['handle']);
            if ($candidateHandle !== $handle) {
                $other = $this->getForm($candidateHandle);
                if ($other !== null && $other['id'] !== $id) {
                    throw new InvalidArgumentException("Form handle '{$candidateHandle}' is already taken.");
                }
                $handle = $candidateHandle;
            }
        }

        $description = array_key_exists('description', $data)
            ? ($data['description'] !== null ? trim((string) $data['description']) : null)
            : $existing['description'];

        $status = isset($data['status']) && in_array($data['status'], ['draft', 'published', 'archived'], true)
            ? (string) $data['status']
            : (string) $existing['status'];

        $fields = isset($data['fields']) ? $this->normalizeFields($data['fields']) : $existing['fields'];
        $settings = isset($data['settings']) ? $this->normalizeSettings($data['settings']) : $existing['settings'];

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $formsTable = $this->connection->prefixTable('form_definitions');

        $stmt = $this->connection->getPdo()->prepare("UPDATE `{$formsTable}`
            SET `handle` = ?, `title` = ?, `description` = ?, `status` = ?, `fields` = ?, `settings` = ?, `updated_at` = ?
            WHERE `id` = ?");

        $stmt->execute([
            $handle,
            $title,
            $description,
            $status,
            json_encode($fields, JSON_THROW_ON_ERROR),
            json_encode($settings, JSON_THROW_ON_ERROR),
            $now,
            $id,
        ]);

        return (array) $this->getForm($id);
    }

    /**
     * Delete form and its associated submissions.
     */
    public function deleteForm(string $id): void
    {
        $existing = $this->getForm($id);
        if ($existing === null) {
            return;
        }

        $formsTable = $this->connection->prefixTable('form_definitions');
        $subsTable = $this->connection->prefixTable('form_submissions');
        $pdo = $this->connection->getPdo();

        $stmtSubs = $pdo->prepare("DELETE FROM `{$subsTable}` WHERE `form_id` = ?");
        $stmtSubs->execute([$id]);

        $stmtForm = $pdo->prepare("DELETE FROM `{$formsTable}` WHERE `id` = ?");
        $stmtForm->execute([$id]);
    }

    /**
     * Retrieve submissions, optionally filtered by formId or status.
     *
     * @return list<array<string, mixed>>
     */
    public function getSubmissions(?string $formId = null, ?string $status = null, int $limit = 100): array
    {
        $subsTable = $this->connection->prefixTable('form_submissions');
        $formsTable = $this->connection->prefixTable('form_definitions');
        $pdo = $this->connection->getPdo();

        $where = [];
        $params = [];

        if ($formId !== null && trim($formId) !== '') {
            $where[] = '(s.form_id = ? OR s.form_handle = ?)';
            $params[] = $formId;
            $params[] = $formId;
        }

        if ($status !== null && trim($status) !== '' && $status !== 'all') {
            $where[] = 's.status = ?';
            $params[] = $status;
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
        $limitVal = max(1, min($limit, 500));

        $sql = "SELECT s.*, f.title AS form_title
                FROM `{$subsTable}` s
                LEFT JOIN `{$formsTable}` f ON f.id = s.form_id
                {$whereClause}
                ORDER BY s.created_at DESC
                LIMIT {$limitVal}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->hydrateSubmission($row);
        }

        return $result;
    }

    /**
     * Get a single submission by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getSubmission(string $id): ?array
    {
        $subsTable = $this->connection->prefixTable('form_submissions');
        $formsTable = $this->connection->prefixTable('form_definitions');
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("SELECT s.*, f.title AS form_title
            FROM `{$subsTable}` s
            LEFT JOIN `{$formsTable}` f ON f.id = s.form_id
            WHERE s.id = ?
            LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateSubmission($row);
    }

    /**
     * Update submission status ('unread', 'read', 'spam', 'archived').
     *
     * @return array<string, mixed>
     */
    public function updateSubmissionStatus(string $id, string $status): array
    {
        if (!in_array($status, ['unread', 'read', 'spam', 'archived'], true)) {
            throw new InvalidArgumentException("Invalid submission status '{$status}'.");
        }

        $subsTable = $this->connection->prefixTable('form_submissions');
        $stmt = $this->connection->getPdo()->prepare("UPDATE `{$subsTable}` SET `status` = ? WHERE `id` = ?");
        $stmt->execute([$status, $id]);

        $sub = $this->getSubmission($id);
        if ($sub === null) {
            throw new RuntimeException("Submission '{$id}' not found.");
        }

        return $sub;
    }

    /**
     * Delete a single submission.
     */
    public function deleteSubmission(string $id): void
    {
        $subsTable = $this->connection->prefixTable('form_submissions');
        $stmt = $this->connection->getPdo()->prepare("DELETE FROM `{$subsTable}` WHERE `id` = ?");
        $stmt->execute([$id]);
    }

    /**
     * Public submission handler with honeypot and validation.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function submitForm(string $handle, array $payload, ?string $ip = null, ?string $userAgent = null): array
    {
        $form = $this->getForm($handle);
        if ($form === null || ($form['status'] ?? '') !== 'published') {
            throw new RuntimeException("Form '{$handle}' is not available or not published.");
        }

        $settings = (array) ($form['settings'] ?? []);
        $honeypotEnabled = (bool) ($settings['honeypot_enabled'] ?? true);

        // Anti-spam honeypot detection: if field `_hp_check` or `website_url_hp` is non-empty, drop quietly
        if ($honeypotEnabled) {
            $hp = trim((string) ($payload['_hp_check'] ?? $payload['website_url_hp'] ?? ''));
            if ($hp !== '') {
                // Return success to confuse bots without persisting
                return [
                    'success' => true,
                    'message' => (string) ($settings['success_message'] ?? 'Thank you! Your message has been sent.'),
                    'submission_id' => 'hp_' . Ulid::generate(),
                ];
            }
        }

        // Validate payload against fields schema
        $fields = (array) ($form['fields'] ?? []);
        $sanitizedData = [];
        $validationErrors = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fieldName = (string) ($field['name'] ?? '');
            if ($fieldName === '') {
                continue;
            }

            $label = (string) ($field['label'] ?? $fieldName);
            $required = (bool) ($field['required'] ?? false);
            $type = (string) ($field['type'] ?? 'text');

            $val = $payload[$fieldName] ?? null;

            if ($required && ($val === null || (is_string($val) && trim($val) === ''))) {
                $validationErrors[$fieldName][] = "{$label} is required.";
                continue;
            }

            if ($val !== null && $type === 'email' && is_string($val) && trim($val) !== '') {
                if (!filter_var(trim($val), FILTER_VALIDATE_EMAIL)) {
                    $validationErrors[$fieldName][] = "{$label} must be a valid email address.";
                    continue;
                }
            }

            if (is_string($val)) {
                $sanitizedData[$fieldName] = trim(strip_tags($val));
            } elseif (is_array($val) || is_bool($val) || is_numeric($val)) {
                $sanitizedData[$fieldName] = $val;
            }
        }

        if (count($validationErrors) > 0) {
            return [
                'success' => false,
                'errors' => $validationErrors,
            ];
        }

        $submissionId = Ulid::generate();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $subsTable = $this->connection->prefixTable('form_submissions');

        $stmt = $this->connection->getPdo()->prepare("INSERT INTO `{$subsTable}`
            (`id`, `form_id`, `form_handle`, `data`, `status`, `ip_address`, `user_agent`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $submissionId,
            $form['id'],
            $form['handle'],
            json_encode($sanitizedData, JSON_THROW_ON_ERROR),
            'unread',
            $ip !== null ? substr($ip, 0, 45) : null,
            $userAgent !== null ? substr($userAgent, 0, 512) : null,
            $now,
        ]);

        return [
            'success' => true,
            'message' => (string) ($settings['success_message'] ?? 'Thank you! Your message has been sent.'),
            'submission_id' => $submissionId,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateForm(array $row): array
    {
        $fields = [];
        if (isset($row['fields']) && is_string($row['fields'])) {
            try {
                $fields = json_decode($row['fields'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $fields = [];
            }
        } elseif (isset($row['fields']) && is_array($row['fields'])) {
            $fields = $row['fields'];
        }

        $settings = [];
        if (isset($row['settings']) && is_string($row['settings'])) {
            try {
                $settings = json_decode($row['settings'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $settings = [];
            }
        } elseif (isset($row['settings']) && is_array($row['settings'])) {
            $settings = $row['settings'];
        }

        return [
            'id' => (string) $row['id'],
            'handle' => (string) $row['handle'],
            'title' => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'status' => (string) ($row['status'] ?? 'draft'),
            'fields' => $fields,
            'settings' => $settings,
            'submissions_count' => (int) ($row['submissions_count'] ?? 0),
            'unread_count' => (int) ($row['unread_count'] ?? 0),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateSubmission(array $row): array
    {
        $data = [];
        if (isset($row['data']) && is_string($row['data'])) {
            try {
                $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $data = [];
            }
        } elseif (isset($row['data']) && is_array($row['data'])) {
            $data = $row['data'];
        }

        return [
            'id' => (string) $row['id'],
            'form_id' => (string) $row['form_id'],
            'form_handle' => (string) $row['form_handle'],
            'form_title' => (string) ($row['form_title'] ?? $row['form_handle']),
            'data' => $data,
            'status' => (string) ($row['status'] ?? 'unread'),
            'ip_address' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            'user_agent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param mixed $rawFields
     * @return list<array<string, mixed>>
     */
    private function normalizeFields(mixed $rawFields): array
    {
        if (!is_array($rawFields) || count($rawFields) === 0) {
            // Default 4 standard contact form fields if none provided
            return [
                ['name' => 'name', 'label' => 'Full Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Jane Doe'],
                ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true, 'placeholder' => 'jane@example.com'],
                ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => false, 'placeholder' => 'Inquiry'],
                ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'placeholder' => 'How can we help?'],
            ];
        }

        $normalized = [];
        foreach ($rawFields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $name = $this->slugify((string) ($f['name'] ?? $f['label'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                'name' => $name,
                'label' => trim((string) ($f['label'] ?? $name)),
                'type' => in_array($f['type'] ?? 'text', ['text', 'email', 'textarea', 'phone', 'select', 'checkbox'], true)
                    ? (string) $f['type']
                    : 'text',
                'required' => (bool) ($f['required'] ?? false),
                'placeholder' => isset($f['placeholder']) ? (string) $f['placeholder'] : '',
                'options' => is_array($f['options'] ?? null) ? array_values(array_map('strval', $f['options'])) : [],
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $rawSettings
     * @return array<string, mixed>
     */
    private function normalizeSettings(mixed $rawSettings): array
    {
        $settings = is_array($rawSettings) ? $rawSettings : [];

        return [
            'submit_label' => trim((string) ($settings['submit_label'] ?? 'Send Message')),
            'success_message' => trim((string) ($settings['success_message'] ?? 'Thank you for reaching out! We will be in touch shortly.')),
            'notify_emails' => trim((string) ($settings['notify_emails'] ?? '')),
            'honeypot_enabled' => (bool) ($settings['honeypot_enabled'] ?? true),
        ];
    }

    private function slugify(string $text): string
    {
        $text = (string) preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text) ?: $text;
        $text = (string) preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = (string) preg_replace('~-+~', '-', $text);
        return strtolower($text);
    }
}
