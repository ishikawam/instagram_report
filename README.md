instagramのユーザーの概要を一括取得
==================================

## 使い方

1. `config/users.sample`を参考に、instagramアカウントを改行区切りで並べた`config/users`を作ります。
    * 不正なURLや不正なアカウントがあると除外して取得します。
1. `php get.php`を実行します。
1. `out/report.csv`にフォロー数、フォロワー数、記事数、直近12件の記事のコメント数、いいね数、サムネイル画像URLがCSVで保存されます。
1. `out/html/`内に各ユーザー毎のファイルが保存され、ユーザーページから取れるその他の情報がjsonで保存されます。

## report.csvについて

各項目は

* user: アカウント
* status: エラーの場合等のステータス
* follows: フォロー数
* followers: フォロワー数
* articles: 投稿記事数
* (0〜11)_date: 投稿日時
* (0〜11)_comments: コメント数
* (0〜11)_likes: いいね数
* (0〜11)_thumbnail: サムネイル画像URL

数値は0が最新で、12件分取得できます。

エラーがあった場合は2列目(status)にエラーメッセージが入っています。

正常

* '' (空)
* No user error
* Private user
  * 非公開設定のユーザー

異常

* Skip
* No content error
* Parse error
* Json error

以上
