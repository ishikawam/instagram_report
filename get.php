<?php

/**
 * instagramのユーザーの概要を一括取得
 * get.php
 *
 */

// ユーザー一覧を取得
$users = getAccounts();

// アカウント名が有効かどうかのチェック
$data = validateAccounts($users);

// アクセスするURLを絞る
$urls = array_unique(array_column($data, 'url'));

// Instagram取得
$result = getContents($urls);
echo "\n";

// 200, 404以外をリトライ
$urls = [];
foreach ($result as $val) {
    if (! in_array($val['status'], [200, 404])) {
        $urls[] = $val['url'];
    }
}
if ($urls) {
    echo "Retry.\n";
    $resultRetry = getContents($urls);
    $result = array_merge($result, $resultRetry);
    echo "\n";
}

// parse
$data = parse($data, $result);
echo "\n";

// パースエラーはリトライ
$urls = [];
foreach ($data as &$val) {
    if ($val['error'] == 'Parse error') {
        $urls[] = $val['url'];
    }
}
if ($urls) {
    echo "Retry for Parse error.\n";
    $resultRetry = getContents($urls);
    $result = array_merge($result, $resultRetry);
    $data = parse($data, $result);
    echo "\n";
}

// レポート配信
putReport($data);

echo("\nFinished.\n\n");


///////////////////////////////////////////////////////////////////////////


/**
 * ユーザー一覧を取得
 * @return array
 */
function getAccounts()
{
    $usersFile = __DIR__ . '/config/users';
    if (! file_exists($usersFile)) {
        echo("\nplease create config/users file.\n");
        return [];
    }

    $users = file($usersFile);

    foreach ($users as &$user) {
        // 整形
        $user = trim($user);
        $old = $user;
        $user = preg_replace('/\?.*$/', '', $user);
        $user = trim($user, '/');
        $user = preg_replace('/^.*\//', '', $user);
    }

    return $users;
}

/**
 * アカウント名が有効かどうかのチェック。有効ならurlをセット。
 * @param string[] $users
 * @return array
 */
function validateAccounts($users)
{
    foreach ($users as &$user) {
        $user = [
            'user' => $user,
            'error' => preg_match('/^[a-zA-Z0-9_.]+$/', $user) ? null : 'Skip',
        ];
        $user['url'] = $user['error'] ? null : sprintf('https://www.instagram.com/%s/', $user['user']);
    }

    return $users;
}

/**
 * Instagram取得
 * @param string[] $urls
 * @return array [][content, status, url]
 */
function getContents($urls)
{
    $configFile = __DIR__ . '/config/config.php';
    require($configFile); // $config

    $mh = curl_multi_init();
    $result = [];

    $count = 0;
    $all = count($urls);
    while ($urlArray = array_splice($urls, 0, $config['thread'])) {
        echo(sprintf(" %s/%s\n", $count * $config['thread'], $all));
        $count ++;

        foreach ($urlArray as $url) {
            if (! $url) {
                continue;
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                    CURLOPT_URL             => $url,
                    CURLOPT_RETURNTRANSFER  => true,
                    CURLOPT_TIMEOUT         => $config['timeout'],
                    CURLOPT_CONNECTTIMEOUT  => $config['timeout'],
                    CURLOPT_FOLLOWLOCATION  => true, // リダイレクトを追いかける
                    CURLOPT_MAXREDIRS       => 10, // リダイレクト回数上限
                    CURLOPT_AUTOREFERER     => true, // リダイレクトの際にヘッダのRefererを自動的に追加させる
                ]);
            curl_multi_add_handle($mh, $ch);
        }

        do {
            $stat = curl_multi_exec($mh, $running);
        } while ($stat === CURLM_CALL_MULTI_PERFORM);
        if (! $running || $stat !== CURLM_OK) {
            throw new RuntimeException('おかしなURLが混ざっているかも');
        }

        do {
            switch (curl_multi_select($mh, $config['timeout'])) {
                // イベントが発生するまでブロック

                case -1:
                    sleep(1); // ちょっと待ってからretry
                    echo "[sleep]\n";
                    do {
                        $stat = curl_multi_exec($mh, $running);
                    } while ($stat === CURLM_CALL_MULTI_PERFORM);
                    continue 2;

                case 0: // タイムアウト
                    echo "[timeout]\n";
                    continue 2; // retry

                default:
                    do {
                        $stat = curl_multi_exec($mh, $running); // ステータスを更新
                    } while ($stat === CURLM_CALL_MULTI_PERFORM);

                    do {
                        if ($raised = curl_multi_info_read($mh, $remains)) {
                            // 変化のあったcurlハンドラを取得する
                            $info = curl_getinfo($raised['handle']);
                            if ($info['http_code'] != 200) {
                                echo "$info[http_code] : $info[url]\n";
                            }

                            $result[$info['url']] = [
                                'content' => curl_multi_getcontent($raised['handle']), // エラー時はfalse
                                'status' => $info['http_code'], // 正常時は200
                                'url' => $info['url'],
                            ];
                            curl_multi_remove_handle($mh, $raised['handle']);
                            curl_close($raised['handle']);
                        }
                    } while ($remains);
            }
        } while ($running);

        sleep($config['wait']);
    }

    curl_multi_close($mh);

    return $result;
}

/**
 * parse
 * @param array $data
 * @param array $result
 * @return array $data
 */
function parse($data, $result)
{
    foreach ($data as &$val) {
        $val['error'] = '';
        if (! isset($result[$val['url']])) {
            // skipのはず
            continue;
        }
        if (! $result[$val['url']]['content']) {
            $val['error'] = 'No content error';
            echo(sprintf("ERROR: No content error: '%s'\n", $user));
            continue;
        }

        $content = $result[$val['url']]['content'];
        $user = $val['user'];

        $out = null;
        if (! preg_match('|<script type="text/javascript">window\._sharedData = (.*?);</script>|', $content, $out)) {
            $val['error'] = 'Parse error';
            echo(sprintf("ERROR: Parse error '%s'\n", $user));
            file_put_contents(__DIR__ . '/out/error/ERROR_PARSE_' . $user, $content);
            continue;
        }

        $json = json_decode($out[1]);
        if ($json === null) {
            $val['error'] = 'Json error';
            echo(sprintf("ERROR: Json error '%s'\n", $user));
            file_put_contents(__DIR__ . '/out/error/ERROR_JSON_' . $user, $out[1]);
        } elseif (! isset($json->entry_data->ProfilePage[0]->user)) {
            // ユーザーが存在しない
            $val['error'] = 'No user error';
            echo(sprintf("ERROR: No user error '%s'\n", $user));
        } elseif ($json->entry_data->ProfilePage[0]->user->is_private) {
            // 鍵付きユーザー
            $val['error'] = 'Private user';
            echo(sprintf("ERROR: Private user '%s'\n", $user));
        }

        $val['data'] = $json;

        file_put_contents(__DIR__ . '/out/html/' . $user, $out[1]);
    }
    return $data;
}

/**
 * レポート配信
 * @param array $data [][data, user, error]
 */
function putReport($data)
{
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
            'status' => $row['error'],
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
}
