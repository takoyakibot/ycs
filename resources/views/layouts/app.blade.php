<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet" />

        {{ $alpine_script ?? ""}}
        <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            @include('layouts.navigation')

            {{-- フラッシュメッセージ --}}
            @if(session('error'))
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                        <span class="block sm:inline">{{ session('error') }}</span>
                    </div>
                </div>
            @endif
            @if(session('success'))
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                        <span class="block sm:inline">{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-2 sm:py-4 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
        <div id="firstDisplayPopup" class="fixed bottom-0 left-0 right-0 bg-gray-800 text-white p-4 text-center">
            <p>このサイトを利用することにより、<a target="_blank" class="text-gray-400 underline" href="{{ route('markdown.show') }}">[利用規約とプライバシーポリシー]</a>に同意したものとみなします。<br/>
            利用規約とプライバシーポリシーの内容をよくご確認の上、引き続きサイトを利用してください。</p>
            <button id="acceptCookies" onclick="acceptCookies()" class="bg-blue-500 px-4 py-1 my-1 rounded text-white">おｋ</button>
        </div>
    </body>
    <script>
        function escapeHTML(str) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        function toggleButtonDisabled(target, flg) {
            target.disabled = flg;
            target.classList.toggle('opacity-50', flg);
            target.classList.toggle('cursor-not-allowed', flg);
        }

        function encodeURIComponentLocal(str) {
            return encodeURIComponent(str);
        }

        function getArchiveUrl(videoId, tsNum = 0) {
            return 'https://youtu.be/' + encodeURIComponentLocal(videoId || '') + (tsNum !== 0 ? '?t=' + tsNum + 's' : '');
        }

        function isCoverSong(title) {
            if (!title) return false;
            const lowerTitle = title.toLowerCase();
            return lowerTitle.includes('歌ってみた') || lowerTitle.includes('cover') || lowerTitle.includes('カバー');
        }

        function getTwitterShareUrl(tsText, text, archiveTitle, videoId, tsNum = 0) {
            const youtubeUrl = getArchiveUrl(videoId, tsNum);
            let tweetText = '';
            // カバー曲（0:00）の場合はアーカイブ名のみ
            const isCover = tsNum === 0 && isCoverSong(archiveTitle);
            if (isCover) {
                tweetText += '📺 ' + archiveTitle + '\n';
            } else {
                if (tsText && text) {
                    tweetText += tsText + ' ' + text + '\n';
                } else if (text) {
                    tweetText += text + '\n';
                }
                if (archiveTitle) {
                    tweetText += '📺 ' + archiveTitle + '\n';
                }
            }
            return 'https://twitter.com/intent/tweet?text=' + encodeURIComponentLocal(tweetText) + '&url=' + encodeURIComponentLocal(youtubeUrl);
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                // Success - will be handled by Alpine
            }).catch(function(err) {
                console.error('Failed to copy: ', err);
            });
        }

        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
        }

        if (getCookie('cookie_consent')) {
            document.getElementById("firstDisplayPopup").classList.add("hidden");
        }

        function acceptCookies() {
            document.getElementById("firstDisplayPopup").classList.add("hidden");
            // 保存期間を4週間くらいにしておく
            document.cookie = "cookie_consent=true; max-age=" + (7 * 4 * 60 * 60 * 24) + "; path=/; Secure; SameSite=Strict";
        }
    </script>
</html>
