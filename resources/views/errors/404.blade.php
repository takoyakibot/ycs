<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found</title>
</head>
<body>
    <h1>ページが見つかりません</h1>
    <p><a href="{{ route('top') }}">トップページに戻る</a></p>
    <p>5秒後に自動でトップページに移動します</p>
</body>
</html>
<script>
    // 5秒後にトップページにリダイレクト
    setTimeout(() => {
        location.href = "{{ route('top') }}";
    }, 5000);
</script>
