{{-- PIP風動画プレイヤー --}}
<div x-show="showVideoPlayer"
     x-cloak
     x-ref="videoPlayer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 scale-95"
     x-transition:enter-end="opacity-100 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 scale-100"
     x-transition:leave-end="opacity-0 scale-95"
     class="fixed z-50 shadow-2xl rounded-lg overflow-hidden transition-all duration-200"
     :class="[
         playerPosition.x === null ? (showDistributionPanel ? 'bottom-28 right-4' : 'bottom-4 right-4') : ''
     ]"
     :style="{
         ...getPlayerStyle(),
         width: getPipWidth() + 'px',
         maxWidth: 'calc(100vw - 2rem)'
     }">
    {{-- プレイヤーヘッダー（ドラッグ可能） --}}
    <div class="bg-gray-800 text-white px-2 py-1 flex items-center justify-between cursor-move select-none"
         @mousedown="startDrag($event)"
         @touchstart="startDrag($event)">
        <span class="text-xs truncate flex-1"
              x-text="selectedSong ? `${selectedSong.title}${selectedSong.artist ? ' / ' + selectedSong.artist : ''}` : '動画プレビュー'"></span>
        <div class="flex items-center gap-1">
            {{-- 最小化/最大化ボタン --}}
            <button @click="togglePlayerMinimize()"
                    @mousedown.stop
                    @touchstart.stop
                    class="text-gray-400 hover:text-white p-1"
                    :aria-label="playerMinimized ? 'プレイヤーを展開' : 'プレイヤーを最小化'">
                <svg x-show="!playerMinimized" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
                <svg x-show="playerMinimized" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                </svg>
            </button>
            {{-- 閉じるボタン --}}
            <button @click="closeVideoPlayer()"
                    @mousedown.stop
                    @touchstart.stop
                    class="text-gray-400 hover:text-white p-1"
                    aria-label="プレイヤーを閉じる">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
    {{-- YouTube Player（最小化時は非表示） --}}
    <div x-show="!playerMinimized"
         x-transition:enter="transition ease-out duration-200"
         x-transition:leave="transition ease-in duration-150"
         class="bg-black" style="aspect-ratio: 16/9;">
        <div id="youtube-player"></div>
    </div>
</div>

{{-- 戻すボタン（パネル非表示時、タイムスタンプタブのみ） --}}
<button x-show="panelDismissed && !showDistributionPanel && activeTab === 'timestamps'"
        x-cloak
        @click="openPanel()"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="fixed bottom-24 right-6 p-2 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg z-40 transition-colors"
        title="配信リンクを表示"
        aria-label="配信リンクを表示">
    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/>
    </svg>
</button>
