<?php

/**
 * instagramのユーザーの概要を一括取得
 */

$usersFile = __DIR__ . '/config/users';
if (! file_exists($usersFile)) {
    echo "\nplease create config/users file.\n";
    return;
}

$users = file($usersFile);

$count = 0;
$data = [];
foreach ($users as $key => $user) {
    $count ++;
    echo(sprintf(" %s/%s	", $count, count($users)));

    // 整形
    $user = trim($user);
    $old = $user;
    $user = preg_replace('/\?.*$/', '', $user);
    $user = trim($user, '/');
    $user = preg_replace('/^.*\//', '', $user);

    $data[$key] = [
        'user' => $user,
        'data' => null,
        'error' => null,
    ];

    $out = null;
    if (! preg_match('/^[a-zA-Z0-9_.]+$/', $user, $out)) { // instagramのアカウントの使用可能文字
        $data[$key]['error'] = 'Skip';
        echo("Skip\n");
        continue;
    }

    // Instagram取得
    $stream_context = stream_context_create(['http' => [
                'timeout' => 20,
                'ignore_errors' => true,
            ]]);
    for ($i = 0; $i < 3; $i++) {
        // タイムアウトの可能性あり。3回までリトライする
        $http_response_header = null;
        $content = @file_get_contents('https://instagram.com/' . $user . '/', false, $stream_context);
        if (count($http_response_header) == 0) {
            echo("Retry $i (timeout) ");
        } elseif (empty($content)) {
            echo("Retry $i (no content) ");
        } else {
            // 成功
            break;
        }
    }
    if (empty($content)) {
        $data[$key]['error'] = 'No content error';
        echo(sprintf("ERROR: No content error: '%s'\n", $user));
        continue;
    }

    $out = null;
    if (! preg_match('|<script type="text/javascript">window\._sharedData = (.*?);</script>|', $content, $out)) {
        $data[$key]['error'] = 'Parse error';
        echo(sprintf("ERROR: Parse error '%s'\n", $user));
        file_put_contents(__DIR__ . '/out/html/ERROR_PARSE_' . $user, $content);
        continue;
    }

    $json = json_decode($out[1]);
    if ($json === null) {
        $data[$key]['error'] = 'Json error';
        echo(sprintf("ERROR: Json error '%s' ", $user));
        file_put_contents(__DIR__ . '/out/html/ERROR_JSON_' . $user, $out[1]);
    } elseif (! isset($json->entry_data->ProfilePage[0]->user)) {
        // ユーザーが存在しない
        $data[$key]['error'] = 'No user error';
        echo(sprintf("ERROR: No user error '%s' ", $user));
    } elseif ($json->entry_data->ProfilePage[0]->user->is_private) {
        // 鍵付きユーザー
        $data[$key]['error'] = 'Private user error';
        echo(sprintf("ERROR: Private user error '%s' ", $user));
    }

    $data[$key]['data'] = $json;

    file_put_contents(__DIR__ . '/out/html/' . $user, $out[1]);

    echo("\n");
}

// レポート配信
$fp = fopen(__DIR__ . '/out/report.csv', 'w');
$isFirst = true;
foreach ($data as $row) {
    if (! isset($row['data']->entry_data->ProfilePage[0]->user)) {
        fputcsv($fp, [$row['user'], $row['error']]);
        if ($isFirst) {
            // 1行目からエラーだったら強制終了
            echo("\nERROR!\n\n");
            exit(1);
        }
        continue;
    }
    $userData = $row['data']->entry_data->ProfilePage[0]->user;

    $csv = [
        'user' => $row['user'],
        'follows' => $userData->follows->count,
        'followers' => $userData->followed_by->count,
        'articles' => $userData->media->count,
    ];

    foreach ($userData->media->nodes as $key => $article) {
        $csv[$key . '_date'] = date('Y-m-d H:i:s', $article->date);
        $csv[$key . '_comments'] = $article->comments->count;
        $csv[$key . '_likes'] = $article->likes->count;
        $csv[$key . '_thumbnail'] = $article->thumbnail_src;
    }
    if ($isFirst) {
        fputcsv($fp, array_keys($csv));
    }
    fputcsv($fp, $csv);
    $isFirst = false;
}
fclose($fp);

echo("\nFinished.\n\n");
