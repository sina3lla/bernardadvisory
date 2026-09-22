<?php
declare(strict_types=1);

function app_project_path(string $path = ''): string
{
    $base = dirname(__DIR__);
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function app_load_dotenv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $trimmed, 2);
        $name = trim($name);
        $value = trim($value);

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

function env_string(string $name, ?string $fallback = null): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        if ($fallback !== null) {
            return $fallback;
        }
        throw new RuntimeException('Missing required environment variable: ' . $name);
    }

    return $value;
}

function env_bool(string $name, bool $fallback): bool
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $fallback;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function is_list_array(array $value): bool
{
    if ($value === []) {
        return true;
    }

    return array_keys($value) === range(0, count($value) - 1);
}

function parse_json_array(?string $raw, string $sourceName): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || !is_list_array($parsed)) {
        throw new RuntimeException($sourceName . ' must be a JSON array');
    }

    return $parsed;
}

function parse_keyword_responses(mixed $raw, string $sourceName): array
{
    if (!is_array($raw) || is_list_array($raw)) {
        throw new RuntimeException($sourceName . ' must be a JSON object');
    }

    $responses = [];
    foreach ($raw as $keyword => $response) {
        if (!is_string($response) || trim($response) === '') {
            throw new RuntimeException($sourceName . ': keyword "' . $keyword . '" must map to a non-empty response');
        }

        $normalized = strtoupper(trim((string) $keyword));
        if ($normalized === '') {
            throw new RuntimeException('Keyword names cannot be empty');
        }

        $responses[$normalized] = $response;
    }

    if ($responses === []) {
        throw new RuntimeException($sourceName . ' must contain at least one keyword');
    }

    return $responses;
}

function load_legacy_single_account(): ?array
{
    if (getenv('META_ACCESS_TOKEN') === false || getenv('META_IG_USER_ID') === false || getenv('KEYWORD_RESPONSES_JSON') === false) {
        return null;
    }

    $keywordResponses = json_decode(env_string('KEYWORD_RESPONSES_JSON'), true);

    return [
        'id' => 'default',
        'displayName' => 'Default Instagram account',
        'metaIgUserId' => env_string('META_IG_USER_ID'),
        'metaAccessToken' => env_string('META_ACCESS_TOKEN'),
        'keywordResponses' => parse_keyword_responses($keywordResponses, 'KEYWORD_RESPONSES_JSON'),
        'publicRepliesEnabled' => env_bool('PUBLIC_REPLIES_ENABLED', false),
        'publicReplyRules' => parse_json_array(getenv('PUBLIC_REPLY_RULES_JSON') ?: null, 'PUBLIC_REPLY_RULES_JSON'),
        'moderationEnabled' => env_bool('MODERATION_ENABLED', true),
        'moderationRules' => parse_json_array(getenv('MODERATION_RULES_JSON') ?: null, 'MODERATION_RULES_JSON'),
        'dmAutoResponderEnabled' => env_bool('DM_AUTORESPONDER_ENABLED', false),
        'dmAutoResponseRules' => parse_json_array(getenv('DM_AUTORESPONSES_JSON') ?: null, 'DM_AUTORESPONSES_JSON'),
    ];
}

function load_accounts(): array
{
    $raw = getenv('INSTAGRAM_ACCOUNTS_JSON');
    if ($raw === false || trim($raw) === '') {
        $legacy = load_legacy_single_account();
        if ($legacy !== null) {
            return [$legacy];
        }

        throw new RuntimeException('Missing required environment variable: INSTAGRAM_ACCOUNTS_JSON');
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || !is_list_array($parsed)) {
        throw new RuntimeException('INSTAGRAM_ACCOUNTS_JSON must be a JSON array');
    }

    $accounts = [];
    foreach ($parsed as $index => $account) {
        $source = 'INSTAGRAM_ACCOUNTS_JSON[' . $index . ']';
        if (!is_array($account)) {
            throw new RuntimeException($source . ' must be a JSON object');
        }

        foreach (['id', 'displayName', 'metaIgUserId', 'metaAccessToken'] as $required) {
            if (!isset($account[$required]) || !is_string($account[$required]) || trim($account[$required]) === '') {
                throw new RuntimeException($source . ' must include id, displayName, metaIgUserId, and metaAccessToken');
            }
        }

        $accounts[] = [
            'id' => $account['id'],
            'displayName' => $account['displayName'],
            'metaIgUserId' => $account['metaIgUserId'],
            'metaAccessToken' => $account['metaAccessToken'],
            'keywordResponses' => parse_keyword_responses($account['keywordResponses'] ?? null, $source . '.keywordResponses'),
            'publicRepliesEnabled' => (bool) ($account['publicRepliesEnabled'] ?? false),
            'publicReplyRules' => is_array($account['publicReplyRules'] ?? null) ? $account['publicReplyRules'] : [],
            'moderationEnabled' => (bool) ($account['moderationEnabled'] ?? true),
            'moderationRules' => is_array($account['moderationRules'] ?? null) ? $account['moderationRules'] : [],
            'dmAutoResponderEnabled' => (bool) ($account['dmAutoResponderEnabled'] ?? false),
            'dmAutoResponseRules' => is_array($account['dmAutoResponseRules'] ?? null) ? $account['dmAutoResponseRules'] : [],
        ];
    }

    return $accounts;
}

function load_config(): array
{
    app_load_dotenv(app_project_path('.env'));

    $accounts = load_accounts();
    $accountsByIgUserId = [];

    foreach ($accounts as $account) {
        $igUserId = $account['metaIgUserId'];
        if (isset($accountsByIgUserId[$igUserId])) {
            throw new RuntimeException('Duplicate metaIgUserId in account config: ' . $igUserId);
        }
        $accountsByIgUserId[$igUserId] = $account;
    }

    return [
        'metaAppSecret' => env_string('META_APP_SECRET'),
        'metaVerifyToken' => env_string('META_VERIFY_TOKEN'),
        'metaApiVersion' => env_string('META_API_VERSION', 'v25.0'),
        'basePath' => env_string('APP_BASE_PATH', '/instagram-bot'),
        'verifyMetaSignatures' => env_bool('VERIFY_META_SIGNATURES', true),
        'accounts' => $accounts,
        'accountsByIgUserId' => $accountsByIgUserId,
        'dedupeStorePath' => app_project_path(env_string('DEDUPE_STORE_PATH', 'data/processed-comments.json')),
        'eventLogPath' => app_project_path(env_string('EVENT_LOG_PATH', 'data/events.jsonl')),
    ];
}
