{{-- 配信リンクパネル（タイムスタンプタブのみ表示） --}}
<div x-show="showDistributionPanel && selectedSong && activeTab === 'timestamps'"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="transform translate-y-full"
     x-transition:enter-end="transform translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="transform translate-y-0"
     x-transition:leave-end="transform translate-y-full"
     class="fixed bottom-0 left-0 right-0 bg-white dark:bg-gray-800 border-t-2 border-gray-300 dark:border-gray-600 shadow-lg z-50 px-4 py-3">
    <div class="max-w-7xl mx-auto">
        {{-- ヘッダー: 動画コントロール / 楽曲タイトル / 閉じる --}}
        <div class="flex items-center justify-between mb-2 gap-2">
            {{-- 動画コントロール --}}
            <div class="flex items-center gap-2 flex-shrink-0">
                {{-- 自動再生チェック（モバイルでは非表示） --}}
                <label x-show="!isMobile" class="hidden sm:inline-flex items-center gap-1 cursor-pointer select-none">
                    <input type="checkbox"
                           x-model="autoPlay"
                           @change="saveAutoPlay()"
                           class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                    <span class="text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">自動再生</span>
                </label>
                {{-- 再生/停止ボタン --}}
                <button @click="togglePlayPause()"
                        :disabled="!selectedTimestamp?.video_id"
                        :class="!selectedTimestamp?.video_id ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200 dark:hover:bg-gray-600'"
                        class="inline-flex items-center gap-1 px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs transition-colors"
                        :title="isPlaying ? '停止' : '再生'">
                    {{-- 再生アイコン --}}
                    <svg x-show="!isPlaying" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                    {{-- 停止アイコン --}}
                    <svg x-show="isPlaying" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                    </svg>
                    <span class="hidden sm:inline" x-text="isPlaying ? '停止' : '再生'"></span>
                </button>
            </div>
            {{-- 楽曲タイトル --}}
            <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate flex-1"
                 x-text="selectedSong ? `${selectedSong.title}${selectedSong.artist ? ' / ' + selectedSong.artist : ''}` : ''"></div>
            <button @click="closePanel()"
                    class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 p-1 flex-shrink-0"
                    aria-label="閉じる">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        {{-- コンテンツ: 配信サービス / 報告 --}}
        <div class="flex flex-wrap gap-3 items-start">
            {{-- 配信サービスボタン群 --}}
            <div class="flex flex-wrap gap-2 flex-1">
                {{-- Spotify --}}
                <a :href="getSpotifyUrl(selectedSong)"
                   @click="logDistributionLinkClick('spotify')"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-md transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                    </svg>
                    <span class="hidden sm:inline">Spotify</span>
                </a>
                {{-- Apple Music --}}
                <a :href="getAppleMusicUrl(selectedSong)"
                   @click="logDistributionLinkClick('apple_music')"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-pink-600 hover:bg-pink-700 text-white text-sm rounded-md transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M23.997 6.124c0-.738-.065-1.47-.24-2.19-.317-1.31-1.062-2.31-2.18-3.043C21.003.517 20.373.285 19.7.164c-.517-.093-1.038-.135-1.564-.15-.04-.003-.083-.01-.124-.013H5.986c-.152.01-.303.017-.455.026C4.786.07 4.043.15 3.34.428 2.004.958 1.04 1.88.475 3.208c-.192.448-.292.925-.363 1.408-.056.392-.088.785-.1 1.18 0 .032-.007.062-.01.093v12.223c.01.14.017.283.027.424.05.815.154 1.624.497 2.373.65 1.42 1.738 2.353 3.234 2.801.42.127.856.187 1.293.228.555.053 1.11.06 1.667.06h11.03c.525 0 1.048-.034 1.57-.1.823-.106 1.597-.35 2.296-.81a5.08 5.08 0 0 0 1.88-2.207c.186-.42.293-.87.37-1.324.113-.675.138-1.358.137-2.04-.002-3.8 0-7.595-.003-11.393zm-6.423 3.99v5.712c0 .417-.058.827-.244 1.206-.29.59-.76 1.035-1.388 1.29-.47.19-.96.27-1.46.27-.93 0-1.72-.407-2.22-1.24-.34-.565-.435-1.187-.39-1.822.09-1.232.85-2.011 2.07-2.067.582-.027 1.164-.017 1.745-.017.153 0 .306-.01.46-.016v-3.46c0-.08-.018-.097-.096-.086-.86.12-1.72.24-2.58.36-.86.12-1.72.24-2.58.36-.085.01-.106.04-.105.124.002 2.644 0 5.29 0 7.934 0 .4-.06.796-.24 1.167-.283.585-.756 1.026-1.38 1.278-.474.192-.965.273-1.47.273-.93 0-1.717-.408-2.216-1.242-.34-.566-.435-1.188-.39-1.823.09-1.232.85-2.01 2.07-2.066.582-.027 1.164-.018 1.745-.018.153 0 .306-.01.46-.016V7.21c0-.08.018-.097.096-.086 1.72.24 3.44.48 5.16.72.86.12 1.72.24 2.58.36.085.01.106-.04.105-.124-.002-.645 0-1.29 0-1.935z"/>
                    </svg>
                    <span class="hidden sm:inline">Apple</span>
                </a>
                {{-- YouTube Music --}}
                <a :href="getYouTubeMusicUrl(selectedSong)"
                   @click="logDistributionLinkClick('youtube_music')"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-red-500 hover:bg-red-600 text-white text-sm rounded-md transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L15.816 12l-6.132 3.54z"/>
                    </svg>
                    <span class="hidden sm:inline">YT Music</span>
                </a>
                {{-- Amazon Music --}}
                <a :href="getAmazonMusicUrl(selectedSong)"
                   @click="logDistributionLinkClick('amazon_music')"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-md transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M13.55 17.526L10.2 15.3v-2.4l5.7 3.4v2.8l-2.35-1.574zM10.2 8.7v2.4l3.35 2.226 2.35-1.574v-2.8l-5.7 3.4V8.7zm8.4 7.674l-6.05 4.026v2.4l8.45-5.626v-2.8l-2.4 1.6zm-2.4-10.8L10.2 2.3v2.4l5.7 3.8 2.3-1.926V3.774L16.2 5.574z"/>
                    </svg>
                    <span class="hidden sm:inline">Amazon</span>
                </a>
                {{-- LINE MUSIC --}}
                <a :href="getLineMusicUrl(selectedSong)"
                   @click="logDistributionLinkClick('line_music')"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="inline-flex items-center gap-1.5 px-3 py-2 bg-green-500 hover:bg-green-600 text-white text-sm rounded-md transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
                    </svg>
                    <span class="hidden sm:inline">LINE</span>
                </a>
            </div>
            {{-- 報告ボタン（タイムスタンプ選択時のみ） --}}
            <template x-if="selectedTimestamp">
                <div class="flex-shrink-0">
                    <template x-if="selectedTimestamp.has_pending_report">
                        <div class="inline-flex items-center gap-1 px-2 py-2 bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs rounded-md">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span class="hidden sm:inline">報告済み</span>
                        </div>
                    </template>
                    <template x-if="!selectedTimestamp.has_pending_report">
                        <button @click="openReportModal(selectedTimestamp)"
                                class="inline-flex items-center gap-1 px-2 py-2 bg-gray-400 hover:bg-gray-500 text-white text-xs rounded-md transition-colors"
                                title="このタイムスタンプを報告">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <span class="hidden sm:inline">報告</span>
                        </button>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
