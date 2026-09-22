<?php
declare(strict_types=1);

function meta_parse_response_body(string $body): mixed
{
    if ($body === '') {
        return null;
    }

    $decoded = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
}

function meta_retry_after_ms(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    if (is_numeric($value)) {
        return max(0, (int) ((float) $value * 1000));
    }

    $time = strtotime($value);
    return $time === false ? null : max(0, ($time - time()) * 1000);
}

function meta_is_retryable(int $status, mixed $body): bool
{
    if (in_array($status, [429, 500, 502, 503, 504], true)) {
        return true;
    }

    $code = is_array($body) ? ($body['error']['code'] ?? null) : null;
    return is_int($code) && in_array($code, [1, 2, 4, 17, 32, 613], true);
}

function meta_request(string $method, string $url, string $accessToken, ?array $jsonBody = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required');
    }

    $maxAttempts = 4;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false) {
            if ($attempt < $maxAttempts) {
                usleep((500 * (2 ** ($attempt - 1))) * 1000);
                continue;
            }
            throw new RuntimeException($curlError !== '' ? $curlError : 'Meta API request failed');
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);
        $body = meta_parse_response_body($rawBody);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'body' => $body, 'attempts' => $attempt];
        }

        if ($attempt < $maxAttempts && meta_is_retryable($status, $body)) {
            $retryAfter = null;
            foreach (explode("\n", $rawHeaders) as $headerLine) {
                if (stripos($headerLine, 'retry-after:') === 0) {
                    $retryAfter = trim(substr($headerLine, strlen('retry-after:')));
                    break;
                }
            }

            $backoffMs = meta_retry_after_ms($retryAfter) ?? (500 * (2 ** ($attempt - 1)));
            usleep($backoffMs * 1000);
            continue;
        }

        return ['ok' => false, 'status' => $status, 'body' => $body, 'attempts' => $attempt];
    }

    throw new RuntimeException('Unreachable Meta API retry state');
}

function meta_graph_url(array $config, string $path): string
{
    return 'https://graph.instagram.com/' . rawurlencode($config['metaApiVersion']) . '/' . ltrim($path, '/');
}

function meta_send_private_reply(array $config, array $account, string $commentId, string $messageText): array
{
    return meta_request('POST', meta_graph_url($config, $account['metaIgUserId'] . '/messages'), $account['metaAccessToken'], [
        'recipient' => ['comment_id' => $commentId],
        'message' => ['text' => $messageText],
    ]);
}

function meta_reply_to_comment(array $config, array $account, string $commentId, string $messageText): array
{
    return meta_request('POST', meta_graph_url($config, $commentId . '/replies'), $account['metaAccessToken'], [
        'message' => $messageText,
    ]);
}

function meta_send_message(array $config, array $account, string $recipientId, string $messageText): array
{
    return meta_request('POST', meta_graph_url($config, $account['metaIgUserId'] . '/messages'), $account['metaAccessToken'], [
        'recipient' => ['id' => $recipientId],
        'messaging_type' => 'RESPONSE',
        'message' => ['text' => $messageText],
    ]);
}

function meta_hide_comment(array $config, array $account, string $commentId): array
{
    return meta_request('POST', meta_graph_url($config, $commentId) . '?hide=true', $account['metaAccessToken'], []);
}

function meta_delete_comment(array $config, array $account, string $commentId): array
{
    return meta_request('DELETE', meta_graph_url($config, $commentId), $account['metaAccessToken']);
}

