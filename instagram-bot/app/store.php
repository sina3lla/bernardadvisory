<?php
declare(strict_types=1);

function ensure_parent_dir(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create directory: ' . $dir);
    }
}

function store_with_lock(string $path, callable $operation): mixed
{
    ensure_parent_dir($path);

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not open store file: ' . $path);
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock store file: ' . $path);
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = [];
        }

        foreach (['comments', 'actions', 'counters', 'senderTemplates', 'senderRuleLastSentAt'] as $bucket) {
            if (!isset($data[$bucket]) || !is_array($data[$bucket])) {
                $data[$bucket] = [];
            }
        }

        $result = $operation($data);

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        fflush($handle);

        flock($handle, LOCK_UN);
        return $result;
    } finally {
        fclose($handle);
    }
}

function store_claim_comment(string $path, string $commentKey, ?string $mediaId, string $keyword): bool
{
    return store_with_lock($path, function (array &$data) use ($commentKey, $mediaId, $keyword): bool {
        $existing = $data['comments'][$commentKey] ?? null;
        if (is_array($existing) && in_array($existing['status'] ?? '', ['sent', 'processing'], true)) {
            return false;
        }

        $data['comments'][$commentKey] = [
            'status' => 'processing',
            'mediaId' => $mediaId,
            'keyword' => $keyword,
            'updatedAt' => gmdate('c'),
        ];

        return true;
    });
}

function store_claim_action(string $path, string $actionKey, array $metadata = []): bool
{
    return store_with_lock($path, function (array &$data) use ($actionKey, $metadata): bool {
        $existing = $data['actions'][$actionKey] ?? null;
        if (is_array($existing) && in_array($existing['status'] ?? '', ['sent', 'processing'], true)) {
            return false;
        }

        $data['actions'][$actionKey] = [
            'status' => 'processing',
            'mediaId' => $metadata['mediaId'] ?? null,
            'keyword' => $metadata['keyword'] ?? null,
            'updatedAt' => gmdate('c'),
        ];

        return true;
    });
}

function store_mark(string $path, string $bucket, string $key, string $status, ?string $error = null): void
{
    store_with_lock($path, function (array &$data) use ($bucket, $key, $status, $error): null {
        if (!isset($data[$bucket]) || !is_array($data[$bucket])) {
            $data[$bucket] = [];
        }

        $existing = is_array($data[$bucket][$key] ?? null) ? $data[$bucket][$key] : [];
        $data[$bucket][$key] = $existing + ['status' => $status];
        $data[$bucket][$key]['status'] = $status;
        $data[$bucket][$key]['updatedAt'] = gmdate('c');
        if ($error !== null) {
            $data[$bucket][$key]['error'] = $error;
        }

        return null;
    });
}

function store_get_counter(string $path, string $counterKey): int
{
    return store_with_lock($path, static fn (array &$data): int => (int) ($data['counters'][$counterKey] ?? 0));
}

function store_increment_counter(string $path, string $counterKey): void
{
    store_with_lock($path, function (array &$data) use ($counterKey): null {
        $data['counters'][$counterKey] = (int) ($data['counters'][$counterKey] ?? 0) + 1;
        return null;
    });
}

function store_get_sender_template_history(string $path, string $senderKey, string $ruleId): array
{
    return store_with_lock($path, static function (array &$data) use ($senderKey, $ruleId): array {
        $history = $data['senderTemplates'][$senderKey . ':' . $ruleId] ?? [];
        return is_array($history) ? $history : [];
    });
}

function store_remember_sender_template(string $path, string $senderKey, string $ruleId, string $template): void
{
    store_with_lock($path, function (array &$data) use ($senderKey, $ruleId, $template): null {
        $key = $senderKey . ':' . $ruleId;
        $history = is_array($data['senderTemplates'][$key] ?? null) ? $data['senderTemplates'][$key] : [];
        $history[] = $template;
        $data['senderTemplates'][$key] = $history;
        return null;
    });
}

function store_get_sender_rule_last_sent_at(string $path, string $senderKey, string $ruleId): ?string
{
    return store_with_lock($path, static function (array &$data) use ($senderKey, $ruleId): ?string {
        $value = $data['senderRuleLastSentAt'][$senderKey . ':' . $ruleId] ?? null;
        return is_string($value) ? $value : null;
    });
}

function store_remember_sender_rule_sent(string $path, string $senderKey, string $ruleId): void
{
    store_with_lock($path, function (array &$data) use ($senderKey, $ruleId): null {
        $data['senderRuleLastSentAt'][$senderKey . ':' . $ruleId] = gmdate('c');
        return null;
    });
}

function store_append_log(string $path, array $event): void
{
    ensure_parent_dir($path);
    file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
