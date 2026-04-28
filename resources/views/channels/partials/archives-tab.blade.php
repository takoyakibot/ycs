{{-- アーカイブタブ --}}
<div x-show="activeTab === 'archives'">
    <x-pagination
        :total="0"
        :current-page="1"
        :last-page="1"
    ></x-pagination>
    <div id="archives" x-data="{ isFiltered : false }"
         @filter-changed.window="isFiltered = $event.detail"
         class="flex flex-col items-center w-[100%] gap-4">
        {{-- アーカイブリスト --}}
        <template x-for="archive in (archives.data || [])" :key="archive.id">
            <div class="channel-archive-card w-[100%] max-w-5xl">
                {{-- ヘッダー: サムネイル + タイトル --}}
                <div class="channel-archive-header" :class="isFiltered ? 'channel-archive-header-compact' : ''">
                    <a :href="getArchiveUrl(archive.video_id || '')" target="_blank" rel="noopener noreferrer"
                       class="channel-archive-thumb-wrap" :class="isFiltered ? 'channel-archive-thumb-compact' : ''">
                        <img :src="escapeHTML(archive.thumbnail || '')" alt="サムネイル" loading="lazy"
                            class="channel-archive-thumb"/>
                        <div class="channel-archive-thumb-overlay">
                            <svg class="w-8 h-8 text-white drop-shadow-lg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </div>
                    </a>
                    <div class="channel-archive-info" :class="isFiltered ? 'flex-1' : ''">
                        <h4 class="channel-archive-title"
                            x-data="{ expanded: false }"
                            :class="expanded ? '' : 'truncate'"
                            :title="archive.title || ''"
                            @click="expanded = !expanded"
                            role="button"
                            tabindex="0"
                            :aria-expanded="expanded"
                            aria-label="タイトルを展開/折りたたみ"
                            @keydown.enter="expanded = !expanded"
                            @keydown.space.prevent="expanded = !expanded"
                            x-text="archive.title || ''">
                        </h4>
                        <p class="channel-archive-date"
                            :title="'元の値: ' + (archive.published_at || '')"
                            x-text="'公開日: ' + formatPublishedDate(archive.published_at)"></p>
                    </div>
                </div>
                {{-- タイムスタンプリスト --}}
                <div class="channel-archive-ts-list" x-show="archive.ts_items_display && archive.ts_items_display.length > 0">
                    <template x-for="tsItem in archive.ts_items_display" :key="tsItem.id">
                        <div class="channel-archive-ts-row">
                            <a :href="getArchiveUrl(tsItem.video_id, tsItem.ts_num)"
                                target="_blank" rel="noopener noreferrer"
                                class="channel-archive-ts-time"
                                x-text="tsItem.ts_text || '0:00:00'">
                            </a>
                            <div class="channel-archive-ts-text"
                                 @click="tsItem.song ? selectSong(tsItem.song, tsItem) : (tsItem.text ? selectText(tsItem.text, tsItem) : null)"
                                 :title="tsItem.song ? `配信サービスで聴く: ${tsItem.song.title} / ${tsItem.song.artist}` : (tsItem.text ? `配信サービスで検索: ${tsItem.text}` : '')">
                                <span x-text="tsItem.text || ''"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
    <x-pagination
        :total="0"
        :current-page="1"
        :last-page="1"
    ></x-pagination>
</div>
