{{-- アーカイブタブ --}}
<div x-show="activeTab === 'archives'">
    <x-pagination
        :total="0"
        :current-page="1"
        :last-page="1"
    ></x-pagination>
    <div id="archives" x-data="{ isFiltered : false }"
         @filter-changed.window="isFiltered = $event.detail"
         class="flex flex-col items-center w-[100%]">
        {{-- アーカイブリスト --}}
        <template x-for="archive in (archives.data || [])" :key="archive.id">
            <div class="archive flex flex-col sm:flex-row w-[100%] max-w-5xl border rounded-lg shadow-lg p-4 gap-4 mb-2 bg-white">
                <div class="flex flex-col flex-shrink-0" :class="isFiltered ? 'sm:w-1/2' : 'sm:w-1/3'">
                    <div class="flex gap-2" :class="isFiltered ? 'flex-row' : 'flex-col'">
                        <a :href="getArchiveUrl(archive.video_id || '')" target="_blank" rel="noopener noreferrer" :class="isFiltered ? 'w-1/4' : 'h-auto'" >
                            <img :src="escapeHTML(archive.thumbnail || '')" alt="サムネイル" loading="lazy"
                                class="rounded-md object-cover flex flex-shrink-0"/>
                        </a>
                        <div :class="isFiltered ? 'w-3/4' : ''">
                            <h4 class="font-semibold text-gray-800 cursor-pointer hover:text-blue-600 transition-colors transition-all duration-200 ease-in-out"
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
                            <p class="text-sm text-gray-600"
                                :title="'元の値: ' + (archive.published_at || '')"
                                x-text="'公開日: ' + formatPublishedDate(archive.published_at)"></p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col flex-grow gap-2" :class="isFiltered ? 'sm:w-1/2' : 'sm:w-2/3'">
                    <div class="timestamps flex flex-col gap-2 sm:gap-0">
                        <template x-for="tsItem in archive.ts_items_display" :key="tsItem.id">
                            <div class="timestamp text-sm text-gray-700">
                                <div class="flex flex-col sm:flex-row sm:items-center gap-1">
                                    <div class="flex items-baseline gap-2">
                                        <a :href="getArchiveUrl(tsItem.video_id, tsItem.ts_num)"
                                            target="_blank" rel="noopener noreferrer" class="text-blue-500 tabular-nums hover:underline flex-shrink-0"
                                            x-text="tsItem.ts_text || '0:00:00'">
                                        </a>
                                        <div class="flex-1 cursor-pointer hover:text-blue-600 dark:hover:text-blue-400 transition-colors"
                                             @click="tsItem.song ? selectSong(tsItem.song, tsItem) : (tsItem.text ? selectText(tsItem.text, tsItem) : null)"
                                             :title="tsItem.song ? `配信サービスで聴く: ${tsItem.song.title} / ${tsItem.song.artist}` : (tsItem.text ? `配信サービスで検索: ${tsItem.text}` : '')">
                                            <span x-text="tsItem.text || ''"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
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
