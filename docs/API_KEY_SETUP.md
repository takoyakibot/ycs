# YouTube Data API キー登録手順

このアプリケーションでは、各ユーザーが独自のYouTube Data API キーを登録する必要があります。

## なぜAPIキーが必要なのか

- YouTube Data API v3には1日あたり10,000 unitsのquota制限があります
- 各ユーザーが独自のAPIキーを持つことで、quotaを分離できます
- これにより、多くのユーザーがアプリを利用してもquotaが枯渇しません

## 登録手順

### 1. Google Cloud Consoleにアクセス

https://console.cloud.google.com/ にアクセスしてください。

### 2. 新しいプロジェクトを作成

1. 画面上部のプロジェクト選択ドロップダウンをクリック
2. 「新しいプロジェクト」をクリック
3. プロジェクト名を入力（例：「ycs-api」）
4. 「作成」をクリック

### 3. YouTube Data API v3を有効化

1. 作成したプロジェクトを選択
2. 左メニューから「APIとサービス」→「ライブラリ」を選択
3. 検索ボックスに「YouTube Data API v3」と入力
4. 「YouTube Data API v3」をクリック
5. 「有効にする」ボタンをクリック

### 4. APIキーを作成

1. 左メニューから「APIとサービス」→「認証情報」を選択
2. 画面上部の「認証情報を作成」ボタンをクリック
3. 「APIキー」を選択
4. APIキーが作成されます（`AIzaSy...`で始まる文字列）

### 5. APIキーの制限を設定（推奨）

セキュリティのため、APIキーに制限を設定することを推奨します：

1. 作成されたAPIキーの右側にある鉛筆アイコン（編集）をクリック
2. 「アプリケーションの制限」セクション：
   - 「なし」を選択（サーバーサイドで使用するため）
3. 「API の制限」セクション：
   - 「キーを制限」を選択
   - 「YouTube Data API v3」のみにチェック
4. 「保存」をクリック

### 6. アプリケーションにAPIキーを登録

1. このアプリケーションにログイン
2. プロフィール画面を開く
3. 「YouTube API Key」欄に作成したAPIキーを貼り付け
4. 「Save」ボタンをクリック

## Quota について

- デフォルトのquota: 10,000 units/日
- 一般的な使用例：
  - チャンネル情報取得：1 unit
  - 動画リスト取得（50件）：1 unit
  - コメント取得（100件）：1 unit

### Quotaが不足する場合

通常の使用では10,000 units/日で十分ですが、もし不足する場合は：

1. Google Cloud Consoleで「IAMと管理」→「割り当て」を選択
2. 「YouTube Data API v3」のquotaを検索
3. 増量をリクエスト（無料で50,000 units程度まで増量可能な場合が多い）

## トラブルシューティング

### 「API key not valid」エラーが出る場合

1. APIキーをコピペする際に余分な空白が入っていないか確認
2. YouTube Data API v3が有効化されているか確認
3. APIキー作成直後は反映に数分かかる場合があります

### 「Quota exceeded」エラーが出る場合

1日のquota（10,000 units）を使い切った場合です。翌日（太平洋時間0時）にリセットされます。

## 参考リンク

- [Google Cloud Console](https://console.cloud.google.com/)
- [YouTube Data API v3 Documentation](https://developers.google.com/youtube/v3)
- [API Quotas and Limits](https://developers.google.com/youtube/v3/getting-started#quota)
