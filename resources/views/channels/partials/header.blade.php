{{-- チャンネルミニヒーロー --}}
<div class="channel-detail-hero -mx-2 sm:-mx-6 -mt-2 sm:-mt-6 mb-4">
    <div class="channel-detail-hero-bg"></div>
    <div class="relative z-10 max-w-5xl mx-auto px-4 py-6 sm:py-8">
        <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')"
           target="_blank"
           rel="noopener noreferrer"
           class="flex items-center gap-4 group">
            <img :src="escapeHTML(channel.thumbnail || '')"
                 alt="アイコン"
                 class="w-16 h-16 sm:w-20 sm:h-20 rounded-full ring-3 ring-white/20 shadow-lg
                        group-hover:ring-amber-400/40 transition-all duration-300" />
            <div>
                <h1 class="channel-detail-hero-name text-xl sm:text-2xl font-bold"
                    x-text="channel.title || '未設定'"></h1>
                <span class="channel-detail-hero-handle text-sm opacity-60"
                      x-text="'@' + (channel.handle || '')"></span>
            </div>
        </a>
    </div>
</div>
