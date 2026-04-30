{{-- ガチャ結果シェアポップアップ --}}
<div x-show="showGachaShare"
     x-cloak
     class="fixed inset-0 z-40 flex items-center justify-center pointer-events-none"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-500"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0">
    <div class="gacha-share-popup pointer-events-auto"
         @mouseenter="pauseGachaShareTimer()"
         @mouseleave="resumeGachaShareTimer()"
         @touchstart="pauseGachaShareTimer()"
         @touchend="resumeGachaShareTimer()"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-90 translate-y-4"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-500"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95 translate-y-2">

        {{-- 閉じるボタン --}}
        <button @click="closeGachaShare()"
                class="gacha-share-close"
                aria-label="閉じる">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>

        {{-- 音符アイコン --}}
        <div class="gacha-share-icon">
            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/>
            </svg>
        </div>

        {{-- シェアテキストプレビュー --}}
        <p class="gacha-share-text" x-text="getGachaShareText()"></p>

        {{-- アクションボタン --}}
        <div class="gacha-share-actions">
            <a :href="getGachaShareUrl()"
               target="_blank"
               rel="noopener noreferrer"
               class="gacha-share-twitter-btn"
               @click="closeGachaShare()">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                </svg>
                シェア
            </a>
        </div>
    </div>
</div>
