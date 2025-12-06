{{-- チャンネルヘッダー --}}
<div class="p-2">
    {{-- チャンネル情報（デスクトップ・モバイル共通） --}}
    <div class="flex justify-center">
        <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 hover:opacity-80 text-gray-500">
            <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-16 h-16 sm:w-20 sm:h-20 rounded-full">
            <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
        </a>
    </div>
</div>
