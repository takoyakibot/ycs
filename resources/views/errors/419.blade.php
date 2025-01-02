<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>419 Page Expired</title>
</head>
<body>
    <h1>セッションが期限切れです</h1>
    <p><a href="{{ route('login') }}">ログインページへ</a></p>
    <p>5秒後に自動でログイン画面に移動します</p>
</body>
</html>
<script type="text/javascript">
    // 5秒後に自動的にログイン画面にリダイレクト
    setTimeout(() => {
        location.href = "{{ e(route('login')) }}";
    }, 5000);
</script>
