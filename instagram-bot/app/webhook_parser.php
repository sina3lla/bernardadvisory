<?php
declare(strict_types=1);

function to_comment_event(array $entry, array $value): ?array
{
    if (!isset($value['id']) || !is_string($value['id']) || !isset($value['text']) || !is_string($value['text'])) {
        return null;
    }

    return [
        'igUserIdFromWebhook' => is_string($entry['id'] ?? null) ? $entry['id'] : null,
        'webhookTime' => $entry['time'] ?? null,
        'commentId' => $value['id'],
        'commentText' => $value['text'],
        'mediaId' => is_string($value['media']['id'] ?? null) ? $value['media']['id'] : null,
        'mediaProductType' => is_string($value['media']['media_product_type'] ?? null) ? $value['media']['media_product_type'] : null,
        'parentCommentId' => is_string($value['parent_id'] ?? null) ? $value['parent_id'] : null,
        'commenterId' => is_string($value['from']['id'] ?? null) ? $value['from']['id'] : null,
        'commenterUsername' => is_string($value['from']['username'] ?? null) ? $value['from']['username'] : null,
    ];
}

function extract_comment_events(array $payload): array
{
    $events = [];
    $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (($entry['field'] ?? null) === 'comments' && is_array($entry['value'] ?? null)) {
            $event = to_comment_event($entry, $entry['value']);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
        foreach ($changes as $change) {
            if (!is_array($change) || ($change['field'] ?? null) !== 'comments' || !is_array($change['value'] ?? null)) {
                continue;
            }

            $event = to_comment_event($entry, $change['value']);
            if ($event !== null) {
                $events[] = $event;
            }
        }
    }

    return $events;
}

function extract_message_events(array $payload): array
{
    $events = [];
    $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $messagingItems = is_array($entry['messaging'] ?? null) ? $entry['messaging'] : [];
        foreach ($messagingItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['message']['is_echo'] ?? false) || ($item['message']['is_deleted'] ?? false) || ($item['message']['is_unsupported'] ?? false)) {
                continue;
            }

            $senderId = $item['sender']['id'] ?? null;
            $messageId = $item['message']['mid'] ?? $item['postback']['mid'] ?? null;
            $messageText = $item['message']['text'] ?? $item['postback']['payload'] ?? $item['postback']['title'] ?? null;

            if (!is_string($senderId) || !is_string($messageId) || !is_string($messageText)) {
                continue;
            }

            $events[] = [
                'igUserIdFromWebhook' => is_string($entry['id'] ?? null) ? $entry['id'] : null,
                'senderId' => $senderId,
                'recipientId' => is_string($item['recipient']['id'] ?? null) ? $item['recipient']['id'] : null,
                'messageId' => $messageId,
                'messageText' => $messageText,
                'timestamp' => $item['timestamp'] ?? null,
            ];
        }
    }

    return $events;
}

