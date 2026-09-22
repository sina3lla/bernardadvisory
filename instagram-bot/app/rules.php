<?php
declare(strict_types=1);

function includes_any(string $text, ?array $keywords): bool
{
    if (!is_array($keywords) || $keywords === []) {
        return false;
    }

    $normalized = strtolower($text);
    foreach ($keywords as $keyword) {
        if (is_string($keyword) && $keyword !== '' && str_contains($normalized, strtolower($keyword))) {
            return true;
        }
    }

    return false;
}

function includes_all(string $text, ?array $keywords): bool
{
    if (!is_array($keywords) || $keywords === []) {
        return true;
    }

    $normalized = strtolower($text);
    foreach ($keywords as $keyword) {
        if (!is_string($keyword) || $keyword === '' || !str_contains($normalized, strtolower($keyword))) {
            return false;
        }
    }

    return true;
}

function stable_ratio(string $seed): float
{
    $hash = 2166136261;
    $length = strlen($seed);
    for ($i = 0; $i < $length; $i++) {
        $hash ^= ord($seed[$i]);
        $hash = ($hash * 16777619) & 0xffffffff;
    }

    return $hash / 4294967295;
}

function choose_by_seed(array $values, string $seed): string
{
    $index = (int) floor(stable_ratio($seed) * count($values));
    return $values[min($index, count($values) - 1)];
}

function date_key(): string
{
    return gmdate('Y-m-d');
}

function find_keyword_match(string $commentText, array $responses): ?array
{
    $keywords = array_keys($responses);
    usort($keywords, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($keywords as $keyword) {
        $pattern = '~(^|[^\p{L}\p{N}_])' . preg_quote($keyword, '~') . '($|[^\p{L}\p{N}_])~iu';
        if (preg_match($pattern, $commentText) === 1) {
            return ['keyword' => strtoupper($keyword), 'responseText' => $responses[$keyword]];
        }
    }

    return null;
}

function find_moderation_rule(array $comment, array $rules): ?array
{
    foreach ($rules as $rule) {
        if (!is_array($rule) || !($rule['enabled'] ?? false) || ($rule['action'] ?? 'none') === 'none') {
            continue;
        }

        if (includes_any($comment['commentText'], $rule['keywords'] ?? [])) {
            return $rule;
        }
    }

    return null;
}

function score_comment(string $text, array $rule): int
{
    $score = 0;
    $positive = ['love', 'great', 'amazing', 'helpful', 'needed', 'thanks', 'thank you', 'interesting', 'smart', 'true', 'agree', 'inspiring', 'valuable'];
    $negative = ['scam', 'fake', 'trash', 'hate', 'stupid', 'idiot', 'worst', 'unfollow', 'reported'];

    if (str_contains($text, '?')) {
        $score += 2;
    }
    if (includes_any($text, $positive)) {
        $score += 2;
    }
    if (includes_any($text, $rule['philosophyKeywords'] ?? [])) {
        $score += 2;
    }
    if (strlen($text) >= 40) {
        $score += 1;
    }
    if (includes_any($text, $negative)) {
        $score -= 4;
    }
    if (includes_any($text, $rule['excludeKeywords'] ?? [])) {
        $score -= 10;
    }

    return $score;
}

function find_public_reply_rule(array $comment, array $rules): ?array
{
    foreach ($rules as $rule) {
        if (!is_array($rule) || !($rule['enabled'] ?? false) || empty($rule['templates']) || !is_array($rule['templates'])) {
            continue;
        }

        $text = $comment['commentText'];
        if (isset($rule['minLength']) && strlen($text) < (int) $rule['minLength']) {
            continue;
        }
        if (isset($rule['maxLength']) && strlen($text) > (int) $rule['maxLength']) {
            continue;
        }
        if (($rule['requireQuestion'] ?? false) && !str_contains($text, '?')) {
            continue;
        }
        if (includes_any($text, $rule['excludeKeywords'] ?? [])) {
            continue;
        }
        if (isset($rule['anyKeywords']) && !includes_any($text, $rule['anyKeywords'])) {
            continue;
        }
        if (!includes_all($text, $rule['requiredAnyKeywords'] ?? [])) {
            continue;
        }
        if (!empty($rule['philosophyKeywords']) && !includes_any($text, $rule['philosophyKeywords'])) {
            continue;
        }

        $score = score_comment($text, $rule);
        if (($rule['requirePositiveSignal'] ?? false) && $score < 2) {
            continue;
        }
        if (isset($rule['minScore']) && $score < (int) $rule['minScore']) {
            continue;
        }
        if (isset($rule['probability']) && stable_ratio($comment['commentId'] . ':' . ($rule['id'] ?? 'rule')) > (float) $rule['probability']) {
            continue;
        }

        return $rule;
    }

    return null;
}

function find_dm_auto_response_rule(array $message, array $rules): ?array
{
    foreach ($rules as $rule) {
        if (!is_array($rule) || !($rule['enabled'] ?? false) || empty($rule['templates']) || !is_array($rule['templates'])) {
            continue;
        }

        $text = $message['messageText'];
        if (includes_any($text, $rule['excludeKeywords'] ?? [])) {
            continue;
        }
        if (isset($rule['anyKeywords']) && !includes_any($text, $rule['anyKeywords'])) {
            continue;
        }
        if (!includes_all($text, $rule['requiredAnyKeywords'] ?? [])) {
            continue;
        }

        return $rule;
    }

    return null;
}

function choose_public_reply_template(array $rule, string $commentId): string
{
    return choose_by_seed($rule['templates'], $commentId . ':' . ($rule['id'] ?? 'rule'));
}

function choose_unused_dm_template(array $rule, string $senderId, array $usedTemplates): ?string
{
    $available = array_values(array_filter($rule['templates'], static fn (string $template): bool => !in_array($template, $usedTemplates, true)));
    if ($available === []) {
        return null;
    }

    return choose_by_seed($available, $senderId . ':' . ($rule['id'] ?? 'rule') . ':' . count($usedTemplates));
}

