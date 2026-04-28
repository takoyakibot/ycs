<x-app-layout>
    <x-slot name="alpine_script">
        <script>
            window.channel = @json($channel ?? []);
            // initで取得するのでこちらはコメントアウト
            // window.archives = @json($archives ?? []);
        </script>
        @vite('resources/js/channels/archive-list.js')
        @vite('resources/js/channels/play-history.js')
    </x-slot>

    <div class="px-2 sm:px-6 py-2 sm:py-6 transition-all duration-300"
         :style="showDistributionPanel && activeTab === 'timestamps' ? 'padding-bottom: 10rem;' : ''"
         x-data="archiveListComponent">

        {{-- チャンネルヘッダー --}}
        @include('channels.partials.header')

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-5xl gap-2">
            {{-- タブUI（モバイル専用） --}}
            @include('channels.partials.tabs')

            {{-- 統一検索ボックス --}}
            @include('channels.partials.search-box')

            {{-- アーカイブタブ --}}
            @include('channels.partials.archives-tab')

            {{-- タイムスタンプタブ --}}
            @include('channels.partials.timestamps-tab')
        </div>

        {{-- ガチャシェアポップアップ --}}
        @include('channels.partials.gacha-share-popup')

        {{-- 報告モーダル --}}
        @include('channels.partials.report-modal')

        {{-- 配信リンクパネル --}}
        @include('channels.partials.distribution-panel')

        {{-- 動画プレイヤー --}}
        @include('channels.partials.video-player')
    </div>
</x-app-layout>
