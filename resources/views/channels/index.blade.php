<x-app-layout>
    <x-slot name="alpine_script">
        <script>
            window.channels = @json($channels['data'] ?? []);
        </script>
    </x-slot>

    {{-- Hero Section --}}
    <div class="channel-hero relative overflow-hidden">
        <div class="channel-hero-bg"></div>
        <div class="relative z-10 max-w-5xl mx-auto px-4 py-10 sm:py-14">
            <div class="flex flex-col items-center text-center">
                <h1 class="channel-hero-title text-3xl sm:text-4xl font-bold tracking-tight">
                    <span class="channel-hero-note" aria-hidden="true">&#9835;</span>
                    {{ config('app.name', '歌枠履歴er:D') }}
                </h1>
                <p class="channel-hero-subtitle mt-3 text-base sm:text-lg max-w-lg">
                    お気に入りの歌枠アーカイブを探そう
                </p>
            </div>
        </div>
    </div>

    {{-- Channel Grid --}}
    <div class="max-w-5xl mx-auto px-4 sm:px-6 -mt-8 relative z-20 pb-12">
        <div x-data='{ channels: window.channels }'>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                <template x-for="(channel, idx) in channels" :key="channel.handle">
                    <a :href="'/channels/'+channel.handle"
                       class="channel-card group"
                       :style="'animation-delay: ' + (idx * 80) + 'ms'">
                        <div class="channel-card-inner">
                            {{-- Thumbnail --}}
                            <div class="channel-card-avatar">
                                <img :src="escapeHTML(channel.thumbnail || '')"
                                     alt=""
                                     class="w-16 h-16 sm:w-18 sm:h-18 rounded-full object-cover ring-2 ring-white/80 shadow-md
                                            group-hover:ring-amber-400/60 transition-all duration-300" />
                            </div>
                            {{-- Info --}}
                            <div class="flex flex-col min-w-0 flex-1 pt-1">
                                <span class="channel-card-name truncate"
                                      x-text="channel.title || '未設定'"></span>
                                <span class="channel-card-meta"
                                      x-show="channel.last_refresh_at"
                                      x-text="'最終更新 ' + (channel.last_refresh_at ? new Date(channel.last_refresh_at).toLocaleDateString('ja-JP') : '')"></span>
                            </div>
                            {{-- Arrow --}}
                            <div class="channel-card-arrow" aria-hidden="true">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                        </div>
                    </a>
                </template>
            </div>

            {{-- Empty State --}}
            <template x-if="channels.length === 0">
                <div class="text-center py-16 text-gray-400">
                    <p class="text-lg">チャンネルが登録されていません</p>
                </div>
            </template>
        </div>
    </div>

</x-app-layout>
