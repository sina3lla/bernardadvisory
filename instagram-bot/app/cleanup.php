<?php
declare(strict_types=1);

// Save as app/cleanup.php. Run daily using Hetzner's PHP CLI cron job.
// Never expose cleanup as a public webhook or web page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

function retention_time(mixed $value): ?int
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : $timestamp;
}

function retention_prune_state(array $data, int $cutoff): array
{
    foreach (['comments', 'actions', 'counters', 'senderTemplates', 'senderRuleLastSentAt', 'retentionUndatedHistorySince'] as $bucket) {
        if (isset($data[$bucket]) && !is_array($data[$bucket])) {
            throw new RuntimeException('Unexpected processing-store bucket format.');
        }
    }
    foreach (['comments', 'actions'] as $bucket) {
        foreach ($data[$bucket] ?? [] as $key => $record) {
            $time = is_array($record) ? retention_time($record['updatedAt'] ?? null) : null;
            if ($time === null) {
                throw new RuntimeException('A processing record has no valid timestamp; cleanup stopped.');
            }
            if ($time < $cutoff) {
                unset($data[$bucket][$key]);
            }
        }
    }

    // Counters use UTC calendar dates inside their keys.
    $cutoffDay = gmdate('Y-m-d', $cutoff);
    foreach ($data['counters'] ?? [] as $key => $value) {
        if (!preg_match('/:(\d{4}-\d{2}-\d{2}):/', (string) $key, $match)) {
            throw new RuntimeException('A counter has no recognizable date; cleanup stopped.');
        }
        if ($match[1] < $cutoffDay) {
            unset($data['counters'][$key]);
        }
    }

    // Existing template history has no per-message dates. Its retention
    // therefore runs from the most recent recorded send for that sender/rule.
    foreach ($data['senderRuleLastSentAt'] ?? [] as $key => $value) {
        $time = retention_time($value);
        if ($time === null) {
            throw new RuntimeException('Reply history has no valid timestamp; cleanup stopped.');
        }
        if ($time < $cutoff) {
            unset($data['senderRuleLastSentAt'][$key], $data['senderTemplates'][$key]);
        }
    }
    // A send interrupted between the two store writes can leave undated history.
    // Give that history a one-time expiry, without resetting it on future runs.
    foreach ($data['senderTemplates'] ?? [] as $key => $history) {
        if (isset($data['senderRuleLastSentAt'][$key])) {
            unset($data['retentionUndatedHistorySince'][$key]);
            continue;
        }
        $since = $data['retentionUndatedHistorySince'][$key] ?? null;
        if ($since === null) {
            $data['retentionUndatedHistorySince'][$key] = gmdate('c');
        } elseif ((retention_time($since) ?? PHP_INT_MAX) < $cutoff) {
            unset($data['senderTemplates'][$key], $data['retentionUndatedHistorySince'][$key]);
        }
    }
    foreach ($data['retentionUndatedHistorySince'] ?? [] as $key => $since) {
        if (!isset($data['senderTemplates'][$key])) {
            unset($data['retentionUndatedHistorySince'][$key]);
        }
    }
    return $data;
}

function retention_rewrite(string $path, callable $transform): void
{
    if (!is_file($path)) {
        return;
    }
    $handle = fopen($path, 'r+');
    if ($handle === false) {
        throw new RuntimeException('Could not open a retention file.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock a retention file.');
        }
        $raw = stream_get_contents($handle);
        if ($raw === false) {
            throw new RuntimeException('Could not read a retention file.');
        }
        // Complete parsing and validation before changing the original file.
        $replacement = $transform($raw);
        if ($replacement === $raw) {
            return;
        }
        rewind($handle);
        $length = strlen($replacement);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($replacement, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write a retention file.');
            }
            $offset += $written;
        }
        if (!ftruncate($handle, $length) || !fflush($handle)) {
            throw new RuntimeException('Could not finish a retention file update.');
        }
    } finally {
        // Keep the same inode so the bot's existing file locks cooperate.
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

try {
    date_default_timezone_set('UTC');
    app_load_dotenv(app_project_path('.env'));
    $cutoff = time() - 30 * 86400;
    $statePath = app_project_path(env_string('DEDUPE_STORE_PATH', 'data/processed-comments.json'));
    $logPath = app_project_path(env_string('EVENT_LOG_PATH', 'data/events.jsonl'));

    retention_rewrite($statePath, static function (string $raw) use ($cutoff): string {
        if (trim($raw) === '') {
            return $raw;
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Unexpected processing-store format.');
        }
        $data = retention_prune_state($data, $cutoff);
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    });

    retention_rewrite($logPath, static function (string $raw) use ($cutoff): string {
        $kept = '';
        foreach (explode("\n", $raw) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $time = is_array($event) ? retention_time($event['timestamp'] ?? null) : null;
            if ($time === null) {
                throw new RuntimeException('A log entry has no valid timestamp; cleanup stopped.');
            }
            if ($time >= $cutoff) {
                $kept .= $line . "\n";
            }
        }
        return $kept;
    });
    echo "Retention cleanup completed successfully.\n";
} catch (Throwable $error) {
    // Do not print file contents, identifiers, tokens, or API response bodies.
    fwrite(STDERR, "Retention cleanup failed; check file permissions and JSON/timestamps.\n");
    exit(1);
}
