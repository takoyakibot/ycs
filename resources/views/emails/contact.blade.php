<x-mail::message>
# お問い合わせを受信しました

以下の内容でお問い合わせがありました。

**お名前:** {{ $name }}

**メールアドレス:** {{ $email }}

**お問い合わせ種別:** @switch($category)
    @case('general')一般的なお問い合わせ@break
    @case('bug')不具合報告@break
    @case('feature')機能リクエスト@break
    @case('other')その他@break
    @default{{ $category }}
@endswitch

---

## お問い合わせ内容

{{ $message }}

---

<x-mail::button :url="config('app.url')">
サイトを開く
</x-mail::button>

このメールは {{ config('app.name') }} のお問い合わせフォームから自動送信されました。
</x-mail::message>
