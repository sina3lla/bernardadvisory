<?php
declare(strict_types=1);

function account_log_fields(array $account): array
{
    return [
        'accountId' => $account['id'],
        'accountName' => $account['displayName'],
        'igUserId' => $account['metaIgUserId'],
    ];
}

function stringify_meta_body(mixed $body): string
{
    $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : 'Unserializable Meta response body';
}

function resolve_account_for_comment(array $config, array $event): ?array
{
    $igUserId = $event['igUserIdFromWebhook'] ?? null;
    return is_string($igUserId) && isset($config['accountsByIgUserId'][$igUserId]) ? $config['accountsByIgUserId'][$igUserId] : null;
}

function resolve_account_for_message(array $config, array $event): ?array
{
    $igUserId = $event['igUserIdFromWebhook'] ?? null;
    if (is_string($igUserId) && isset($config['accountsByIgUserId'][$igUserId])) {
        return $config['accountsByIgUserId'][$igUserId];
    }

    $recipientId = $event['recipientId'] ?? null;
    return is_string($recipientId) && isset($config['accountsByIgUserId'][$recipientId]) ? $config['accountsByIgUserId'][$recipientId] : null;
}

function process_moderation(array $config, array $account, array $event): bool
{
    if (!($account['moderationEnabled'] ?? false)) {
        return false;
    }

    $rule = find_moderation_rule($event, $account['moderationRules'] ?? []);
    if ($rule === null) {
        return false;
    }

    $action = ($rule['action'] ?? 'hide') === 'delete' ? 'delete' : 'hide';
    $ruleId = (string) ($rule['id'] ?? 'moderation');
    $actionKey = $account['id'] . ':moderation:' . $action . ':' . $event['commentId'];

    if (!store_claim_action($config['dedupeStorePath'], $actionKey, ['mediaId' => $event['mediaId'] ?? null, 'keyword' => $ruleId])) {
        app_log_event($config, account_log_fields($account) + [
            'action' => $action === 'delete' ? 'moderation_delete' : 'moderation_hide',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'duplicate',
            'reason' => 'Moderation action already processed or currently processing',
        ]);
        return true;
    }

    try {
        $result = $action === 'delete'
            ? meta_delete_comment($config, $account, $event['commentId'])
            : meta_hide_comment($config, $account, $event['commentId']);

        if ($result['ok']) {
            store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'sent');
            app_log_event($config, account_log_fields($account) + [
                'action' => $action === 'delete' ? 'moderation_delete' : 'moderation_hide',
                'mediaId' => $event['mediaId'] ?? null,
                'commentId' => $event['commentId'],
                'ruleId' => $ruleId,
                'succeeded' => true,
                'status' => $action === 'delete' ? 'deleted' : 'hidden',
                'reason' => $rule['reason'] ?? null,
                'metaStatus' => $result['status'],
                'metaResponse' => $result['body'],
            ]);
        } else {
            store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'failed', stringify_meta_body($result['body']));
            app_log_event($config, account_log_fields($account) + [
                'action' => $action === 'delete' ? 'moderation_delete' : 'moderation_hide',
                'mediaId' => $event['mediaId'] ?? null,
                'commentId' => $event['commentId'],
                'ruleId' => $ruleId,
                'succeeded' => false,
                'status' => 'failed',
                'reason' => $rule['reason'] ?? 'Meta moderation API returned an error',
                'metaStatus' => $result['status'],
                'metaResponse' => $result['body'],
            ]);
        }
    } catch (Throwable $error) {
        store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'failed', $error->getMessage());
        app_log_event($config, account_log_fields($account) + [
            'action' => $action === 'delete' ? 'moderation_delete' : 'moderation_hide',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'failed',
            'reason' => $error->getMessage(),
        ]);
    }

    return true;
}

function process_private_reply(array $config, array $account, array $event): void
{
    $match = find_keyword_match($event['commentText'], $account['keywordResponses']);
    if ($match === null) {
        return;
    }

    $commentKey = $account['id'] . ':' . $event['commentId'];
    if (!store_claim_comment($config['dedupeStorePath'], $commentKey, $event['mediaId'] ?? null, $match['keyword'])) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'private_reply',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'detectedKeyword' => $match['keyword'],
            'dmSucceeded' => false,
            'status' => 'duplicate',
            'reason' => 'Comment already processed or currently processing',
        ]);
        return;
    }

    try {
        $result = meta_send_private_reply($config, $account, $event['commentId'], $match['responseText']);
        if ($result['ok']) {
            store_mark($config['dedupeStorePath'], 'comments', $commentKey, 'sent');
            app_log_event($config, account_log_fields($account) + [
                'action' => 'private_reply',
                'mediaId' => $event['mediaId'] ?? null,
                'commentId' => $event['commentId'],
                'detectedKeyword' => $match['keyword'],
                'dmSucceeded' => true,
                'status' => 'sent',
                'metaStatus' => $result['status'],
                'metaResponse' => $result['body'],
            ]);
        } else {
            store_mark($config['dedupeStorePath'], 'comments', $commentKey, 'failed', stringify_meta_body($result['body']));
            app_log_event($config, account_log_fields($account) + [
                'action' => 'private_reply',
                'mediaId' => $event['mediaId'] ?? null,
                'commentId' => $event['commentId'],
                'detectedKeyword' => $match['keyword'],
                'dmSucceeded' => false,
                'status' => 'failed',
                'reason' => 'Meta private reply API returned an error',
                'metaStatus' => $result['status'],
                'metaResponse' => $result['body'],
            ]);
        }
    } catch (Throwable $error) {
        store_mark($config['dedupeStorePath'], 'comments', $commentKey, 'failed', $error->getMessage());
        app_log_event($config, account_log_fields($account) + [
            'action' => 'private_reply',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'detectedKeyword' => $match['keyword'],
            'dmSucceeded' => false,
            'status' => 'failed',
            'reason' => $error->getMessage(),
        ]);
    }
}

function process_public_reply(array $config, array $account, array $event): void
{
    if (!($account['publicRepliesEnabled'] ?? false)) {
        return;
    }

    $rule = find_public_reply_rule($event, $account['publicReplyRules'] ?? []);
    if ($rule === null) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'public_reply',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'succeeded' => false,
            'status' => 'ignored',
            'reason' => 'No public reply rule matched',
        ]);
        return;
    }

    $ruleId = (string) ($rule['id'] ?? 'public_reply');
    $today = date_key();
    $globalCounterKey = $account['id'] . ':public_reply:' . $ruleId . ':' . $today . ':global';
    $mediaCounterKey = $account['id'] . ':public_reply:' . $ruleId . ':' . $today . ':media:' . ($event['mediaId'] ?? 'unknown');

    if (isset($rule['maxRepliesGlobalPerDay']) && store_get_counter($config['dedupeStorePath'], $globalCounterKey) >= (int) $rule['maxRepliesGlobalPerDay']) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'public_reply',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'ignored',
            'reason' => 'Public reply global daily cap reached',
        ]);
        return;
    }

    if (isset($rule['maxRepliesPerMediaPerDay']) && store_get_counter($config['dedupeStorePath'], $mediaCounterKey) >= (int) $rule['maxRepliesPerMediaPerDay']) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'public_reply',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'ignored',
            'reason' => 'Public reply media daily cap reached',
        ]);
        return;
    }

    $actionKey = $account['id'] . ':public_reply:' . $event['commentId'];
    if (!store_claim_action($config['dedupeStorePath'], $actionKey, ['mediaId' => $event['mediaId'] ?? null, 'keyword' => $ruleId])) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'public_reply',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'duplicate',
            'reason' => 'Public reply already processed or currently processing',
        ]);
        return;
    }

    try {
        $result = meta_reply_to_comment($config, $account, $event['commentId'], choose_public_reply_template($rule, $event['commentId']));
        if ($result['ok']) {
            store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'sent');
            store_increment_counter($config['dedupeStorePath'], $globalCounterKey);
            store_increment_counter($config['dedupeStorePath'], $mediaCounterKey);
            app_log_event($config, account_log_fields($account) + [
                'action' => 'public_reply',
                'mediaId' => $event['mediaId'] ?? null,
                'commentId' => $event['commentId'],
                'ruleId' => $ruleId,
                'succeeded' => true,
                'status' => 'sent',
                'metaStatus' => $result['status'],
                'metaResponse' => $result['body'],
            ]);
        } else {
            store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'failed', stringify_meta_body($result['body']));
            app_log_event($config, account_log_fields($account) + [
                'action' => 'public_reply',
                'mediaId' => $event['mediaId'] ?? null,
                'commentId' => $event['commentId'],
                'ruleId' => $ruleId,
                'succeeded' => false,
                'status' => 'failed',
                'reason' => 'Meta comment reply API returned an error',
                'metaStatus' => $result['status'],
                'metaResponse' => $result['body'],
            ]);
        }
    } catch (Throwable $error) {
        store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'failed', $error->getMessage());
        app_log_event($config, account_log_fields($account) + [
            'action' => 'public_reply',
            'mediaId' => $event['mediaId'] ?? null,
            'commentId' => $event['commentId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'failed',
            'reason' => $error->getMessage(),
        ]);
    }
}

function process_dm_auto_response(array $config, array $account, array $event): void
{
    if (!($account['dmAutoResponderEnabled'] ?? false)) {
        return;
    }

    $rule = find_dm_auto_response_rule($event, $account['dmAutoResponseRules'] ?? []);
    if ($rule === null) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'dm_autoreply',
            'messageId' => $event['messageId'],
            'senderId' => $event['senderId'],
            'succeeded' => false,
            'status' => 'ignored',
            'reason' => 'No DM autoresponse rule matched',
        ]);
        return;
    }

    $ruleId = (string) ($rule['id'] ?? 'dm_autoreply');
    $senderStoreKey = $account['id'] . ':' . $event['senderId'];
    $senderCounterKey = $account['id'] . ':dm_autoreply:' . $ruleId . ':' . date_key() . ':sender:' . $event['senderId'];

    if (isset($rule['maxRepliesPerSenderPerDay']) && store_get_counter($config['dedupeStorePath'], $senderCounterKey) >= (int) $rule['maxRepliesPerSenderPerDay']) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'dm_autoreply',
            'messageId' => $event['messageId'],
            'senderId' => $event['senderId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'ignored',
            'reason' => 'DM sender daily cap reached',
        ]);
        return;
    }

    $lastSentAt = store_get_sender_rule_last_sent_at($config['dedupeStorePath'], $senderStoreKey, $ruleId);
    if ($lastSentAt !== null && isset($rule['cooldownSeconds']) && time() - strtotime($lastSentAt) < (int) $rule['cooldownSeconds']) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'dm_autoreply',
            'messageId' => $event['messageId'],
            'senderId' => $event['senderId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'ignored',
            'reason' => 'DM sender cooldown active',
        ]);
        return;
    }

    $actionKey = $account['id'] . ':dm_autoreply:' . $event['messageId'];
    if (!store_claim_action($config['dedupeStorePath'], $actionKey, ['keyword' => $ruleId])) {
        app_log_event($config, account_log_fields($account) + [
            'action' => 'dm_autoreply',
            'messageId' => $event['messageId'],
            'senderId' => $event['senderId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'duplicate',
            'reason' => 'DM message already processed or currently processing',
        ]);
        return;
    }

    $usedTemplates = store_get_sender_template_history($config['dedupeStorePath'], $senderStoreKey, $ruleId);
    $message = choose_unused_dm_template($rule, $event['senderId'], $usedTemplates);
    if ($message === null) {
        store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'sent');
        app_log_event($config, account_log_fields($account) + [
            'action' => 'dm_autoreply',
            'messageId' => $event['messageId'],
            'senderId' => $event['senderId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'ignored',
            'reason' => 'All configured templates for this sender/rule have already been used',
        ]);
        return;
    }

    try {
        $result = meta_send_message($config, $account, $event['senderId'], $message);
        if ($result['ok']) {
            store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'sent');
            store_increment_counter($config['dedupeStorePath'], $senderCounterKey);
            store_remember_sender_template($config['dedupeStorePath'], $senderStoreKey, $ruleId, $message);
            store_remember_sender_rule_sent($config['dedupeStorePath'], $senderStoreKey, $ruleId);
            app_log_event($config, account_log_fields($account) + [
                'action' => 'dm_autoreply',
                'messageId' => $event['messageId'],
                'senderId' => $event['senderId'],
                'ruleId' => $ruleId,
                'succeeded' => true,
                'status' => 'sent',
                'metaStatus' => $result['status'],
                'metaResponse' => $result['body'],
            ]);
        } else {
            store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'failed', stringify_meta_body($result['body']));
            app_log_event($config, account_log_fields($account) + [
                'action' => 'dm_autoreply',
                'messageId' => $event['messageId'],
                'senderId' => $event['senderId'],
                'ruleId' => $ruleId,
                'succeeded' => false,
                'status' => 'failed',
                'reason' => 'Meta DM API returned an error',
                'metaStatus' => $result['status'],
                'metaResponse' => $result['body'],
            ]);
        }
    } catch (Throwable $error) {
        store_mark($config['dedupeStorePath'], 'actions', $actionKey, 'failed', $error->getMessage());
        app_log_event($config, account_log_fields($account) + [
            'action' => 'dm_autoreply',
            'messageId' => $event['messageId'],
            'senderId' => $event['senderId'],
            'ruleId' => $ruleId,
            'succeeded' => false,
            'status' => 'failed',
            'reason' => $error->getMessage(),
        ]);
    }
}
