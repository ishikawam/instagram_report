<?php

/**
 * config
 */

date_default_timezone_set('Asia/Tokyo');

$config = [
    'timeout' => 20,
    'thread' => 5,
    'wait' => 5,

    // 予約
    'retry' => 3,
    'debug' => false,
    'proxy' => null,
    'logDir' => '',
    'outDir' => '',
];
