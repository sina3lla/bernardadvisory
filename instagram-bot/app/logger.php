<?php
declare(strict_types=1);

function app_log_event(array $config, array $event): void
{
    $event = ['timestamp' => gmdate('c')] + $event;
    store_append_log($config['eventLogPath'], $event);
}