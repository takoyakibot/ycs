/**
 * タイムスタンプ正規化機能
 */
class TimestampNormalization {
  constructor() {
    this.selectedTimestamp = null;
    this.selectedSong = null;
    this.currentPage = 1;
    this.songsCurrentPage = 1;
    this.searchTimeout = null;

    this.init();
  }

  init() {
    this.bindEvents();
    this.loadTimestamps();
    this.showTab('spotifyTab');
  }

  bindEvents() {
    // 検索
    document.getElementById('timestampSearch').addEventListener('input', (e) => {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => {
        this.loadTimestamps(1, e.target.value);
      }, 500);
    });

    // Spotify検索
    document.getElementById('searchSpotifyBtn').addEventListener('click', () => {
      this.searchSpotify();
    });

    document.getElementById('spotifySearch').addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        this.searchSpotify();
      }
    });

    // 更新ボタン
    document.getElementById('refreshTimestampsBtn').addEventListener('click', () => {
      this.loadTimestamps();
    });

    // 楽曲マスタ一覧表示
    document.getElementById('showSongsBtn').addEventListener('click', () => {
      this.showTab('songsTab');
      this.loadSongs();
    });

    // 手動登録フォーム
    document.getElementById('createSongForm').addEventListener('submit', (e) => {
      e.preventDefault();
      this.createSong();
    });

    // 楽曲マスタ検索
    document.getElementById('songsSearch').addEventListener('input', (e) => {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => {
        this.loadSongs(1, e.target.value);
      }, 500);
    });

    // タブ切り替え
    document.querySelectorAll('.tab-button').forEach(button => {
      button.addEventListener('click', (e) => {
        this.showTab(e.target.id);
      });
    });

    // アクション
    document.getElementById('linkSongBtn').addEventListener('click', () => {
      this.linkTimestamp();
    });

    document.getElementById('markAsNotSongBtn').addEventListener('click', () => {
      this.markAsNotSong();
    });

    document.getElementById('clearSelectionBtn').addEventListener('click', () => {
      this.clearSelection();
    });
  }

  async loadTimestamps(page = 1, search = '') {
    try {
      this.showLoading();
      const url = new URL('/api/manage/timestamps', window.location.origin);
      url.searchParams.append('page', page);
      url.searchParams.append('per_page', 50);
      if (search) {
        url.searchParams.append('search', search);
      }

      const response = await fetch(url);
      const data = await response.json();

      this.displayTimestamps(data.timestamps);
      this.displayPagination(data.pagination, 'timestampPagination', (p) => this.loadTimestamps(p, search));
      this.currentPage = page;
    } catch (error) {
      console.error('タイムスタンプ読み込みエラー:', error);
      this.showError('タイムスタンプの読み込みに失敗しました');
    } finally {
      this.hideLoading();
    }
  }

  displayTimestamps(timestamps) {
    const container = document.getElementById('timestampsList');
    container.innerHTML = '';

    // 表示文字列でソート
    const sortedTimestamps = timestamps.sort((a, b) => {
      const textA = a.song ? `${a.song.title} - ${a.song.artist}` : (a.text || '');
      const textB = b.song ? `${b.song.title} - ${b.song.artist}` : (b.text || '');
      return textA.localeCompare(textB, 'ja');
    });

    sortedTimestamps.forEach(timestamp => {
      const div = document.createElement('div');
      div.className = `p-2 border rounded cursor-pointer hover:bg-gray-50 ${timestamp.song_id ? 'bg-green-50 border-green-200' : timestamp.is_not_song ? 'bg-red-50 border-red-200' : 'bg-gray-50'}`;

      let statusIcon = '';
      if (timestamp.song_id) {
        statusIcon = '<span class="text-green-600 font-bold text-xs mr-1">✓</span>';
      } else if (timestamp.is_not_song) {
        statusIcon = '<span class="text-red-600 font-bold text-xs mr-1">✗</span>';
      } else {
        statusIcon = '<span class="text-yellow-600 font-bold text-xs mr-1">?</span>';
      }

      // 楽曲情報がある場合は楽曲名を、ない場合はタイムスタンプのテキストを表示
      const displayText = timestamp.song
        ? `♪ ${this.escapeHtml(timestamp.song.title)} - ${this.escapeHtml(timestamp.song.artist)}`
        : this.escapeHtml(timestamp.text || '').substring(0, 60) + ((timestamp.text || '').length > 60 ? '...' : '');

      div.innerHTML = `
        <div class="flex items-center text-sm">
          ${statusIcon}
          <span class="flex-1 truncate ${timestamp.song ? 'text-blue-600 font-medium' : 'text-gray-700'}">${displayText}</span>
        </div>
      `;

      div.addEventListener('click', () => {
        this.selectTimestamp(timestamp);
      });

      container.appendChild(div);
    });
  }

  selectTimestamp(timestamp) {
    this.selectedTimestamp = timestamp;
    this.updateSelectedTimestampDisplay();
    this.updateActionButtons();
  }

  updateSelectedTimestampDisplay() {
    const container = document.getElementById('selectedTimestamp');
    if (!this.selectedTimestamp) {
      container.classList.add('hidden');
      return;
    }

    container.classList.remove('hidden');
    const displayText = this.escapeHtml(this.selectedTimestamp.text || '').substring(0, 100) +
      ((this.selectedTimestamp.text || '').length > 100 ? '...' : '');
    container.innerHTML = `
      <div class="font-medium text-sm">選択中: ${displayText}</div>
    `;
  }

  updateActionButtons() {
    const linkBtn = document.getElementById('linkSongBtn');
    const notSongBtn = document.getElementById('markAsNotSongBtn');

    const hasSelection = this.selectedTimestamp && this.selectedSong;
    linkBtn.disabled = !hasSelection;
    notSongBtn.disabled = !this.selectedTimestamp;
  }

  async searchSpotify() {
    const query = document.getElementById('spotifySearch').value.trim();
    if (!query) return;

    try {
      this.showLoading();
      const url = new URL('/api/manage/spotify/search', window.location.origin);
      url.searchParams.append('query', query);

      const response = await fetch(url);
      const data = await response.json();

      this.displaySpotifyResults(data.tracks || []);
    } catch (error) {
      console.error('Spotify検索エラー:', error);
      this.showError('Spotify検索でエラーが発生しました');
    } finally {
      this.hideLoading();
    }
  }

  displaySpotifyResults(tracks) {
    const container = document.getElementById('spotifyTracks');
    container.innerHTML = '';

    if (tracks.length === 0) {
      container.innerHTML = '<div class="text-gray-500 text-center py-4">検索結果がありません</div>';
      return;
    }

    tracks.forEach(track => {
      const div = document.createElement('div');
      div.className = 'p-3 border rounded cursor-pointer hover:bg-gray-50';

      div.innerHTML = `
                <div class="flex items-center">
                    ${track.image_url ? `<img src="${track.image_url}" alt="Album cover" class="w-12 h-12 rounded mr-3">` : ''}
                    <div class="flex-1">
                        <div class="font-medium text-sm">${this.escapeHtml(track.name)}</div>
                        <div class="text-xs text-gray-600">${this.escapeHtml(track.artist)}</div>
                        <div class="text-xs text-gray-500">${this.escapeHtml(track.album)}</div>
                    </div>
                    <div class="ml-2">
                        <button class="px-2 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700">
                            選択
                        </button>
                    </div>
                </div>
                ${track.preview_url ? `<audio controls class="mt-2 w-full" style="height: 30px;"><source src="${track.preview_url}" type="audio/mpeg"></audio>` : ''}
            `;

      div.querySelector('button').addEventListener('click', async (e) => {
        e.stopPropagation();
        await this.createSongFromSpotify(track);
      });

      container.appendChild(div);
    });
  }

  async createSongFromSpotify(track) {
    try {
      this.showLoading();
      const response = await fetch('/api/manage/songs/spotify', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
          spotify_id: track.id
        })
      });

      const data = await response.json();

      if (response.ok) {
        this.selectedSong = data.song;
        this.updateActionButtons();
        this.showSuccess('楽曲マスタを作成しました');
      } else {
        this.showError(data.error || '楽曲マスタの作成に失敗しました');
      }
    } catch (error) {
      console.error('楽曲作成エラー:', error);
      this.showError('楽曲マスタの作成でエラーが発生しました');
    } finally {
      this.hideLoading();
    }
  }

  async createSong() {
    const formData = new FormData(document.getElementById('createSongForm'));

    try {
      this.showLoading();
      const response = await fetch('/api/manage/songs', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: formData
      });

      const data = await response.json();

      if (response.ok) {
        this.selectedSong = data.song;
        this.updateActionButtons();
        this.showSuccess('楽曲マスタを作成しました');
        document.getElementById('createSongForm').reset();
      } else {
        this.showError(data.error || '楽曲マスタの作成に失敗しました');
      }
    } catch (error) {
      console.error('楽曲作成エラー:', error);
      this.showError('楽曲マスタの作成でエラーが発生しました');
    } finally {
      this.hideLoading();
    }
  }

  async loadSongs(page = 1, search = '') {
    try {
      this.showLoading();
      const url = new URL('/api/manage/songs', window.location.origin);
      url.searchParams.append('page', page);
      url.searchParams.append('per_page', 20);
      if (search) {
        url.searchParams.append('search', search);
      }

      const response = await fetch(url);
      const data = await response.json();

      this.displaySongs(data.songs);
      this.displayPagination(data.pagination, 'songsPagination', (p) => this.loadSongs(p, search));
      this.songsCurrentPage = page;
    } catch (error) {
      console.error('楽曲マスタ読み込みエラー:', error);
      this.showError('楽曲マスタの読み込みに失敗しました');
    } finally {
      this.hideLoading();
    }
  }

  displaySongs(songs) {
    const container = document.getElementById('songsResults');
    container.innerHTML = '';

    if (songs.length === 0) {
      container.innerHTML = '<div class="text-gray-500 text-center py-4">楽曲マスタがありません</div>';
      return;
    }

    songs.forEach(song => {
      const div = document.createElement('div');
      div.className = 'p-3 border rounded cursor-pointer hover:bg-gray-50';

      div.innerHTML = `
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <div class="font-medium text-sm">${this.escapeHtml(song.title)}</div>
                        <div class="text-xs text-gray-600">${this.escapeHtml(song.artist)}</div>
                        ${song.spotify_id ? '<div class="text-xs text-green-600">Spotify連携済み</div>' : ''}
                    </div>
                    <button class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                        選択
                    </button>
                </div>
            `;

      div.querySelector('button').addEventListener('click', (e) => {
        e.stopPropagation();
        this.selectedSong = song;
        this.updateActionButtons();
        this.showSuccess('楽曲を選択しました');
      });

      container.appendChild(div);
    });
  }

  async linkTimestamp() {
    if (!this.selectedTimestamp || !this.selectedSong) return;

    try {
      this.showLoading();
      const response = await fetch('/api/manage/timestamps/link', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
          timestamp_id: this.selectedTimestamp.id,
          song_id: this.selectedSong.id,
          is_not_song: false
        })
      });

      const data = await response.json();

      if (response.ok) {
        this.showSuccess('タイムスタンプと楽曲を紐づけました');
        this.loadTimestamps(this.currentPage, document.getElementById('timestampSearch').value);
        this.clearSelection();
      } else {
        this.showError(data.error || '紐づけに失敗しました');
      }
    } catch (error) {
      console.error('紐づけエラー:', error);
      this.showError('紐づけでエラーが発生しました');
    } finally {
      this.hideLoading();
    }
  }

  async markAsNotSong() {
    if (!this.selectedTimestamp) return;

    try {
      this.showLoading();
      const response = await fetch('/api/manage/timestamps/link', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
          timestamp_id: this.selectedTimestamp.id,
          is_not_song: true
        })
      });

      const data = await response.json();

      if (response.ok) {
        this.showSuccess('楽曲ではないとマークしました');
        this.loadTimestamps(this.currentPage, document.getElementById('timestampSearch').value);
        this.clearSelection();
      } else {
        this.showError(data.error || 'マーク処理に失敗しました');
      }
    } catch (error) {
      console.error('マーク処理エラー:', error);
      this.showError('マーク処理でエラーが発生しました');
    } finally {
      this.hideLoading();
    }
  }

  clearSelection() {
    this.selectedTimestamp = null;
    this.selectedSong = null;
    this.updateSelectedTimestampDisplay();
    this.updateActionButtons();
  }

  showTab(tabId) {
    // タブボタンの状態更新
    document.querySelectorAll('.tab-button').forEach(button => {
      button.classList.remove('border-green-500', 'text-green-600', 'border-purple-500', 'text-purple-600', 'border-blue-500', 'text-blue-600');
      button.classList.add('border-transparent', 'text-gray-500');
    });

    // コンテンツの表示/非表示
    document.querySelectorAll('.tab-content').forEach(content => {
      content.classList.add('hidden');
    });

    const activeButton = document.getElementById(tabId);
    let contentId, colorClass;

    switch (tabId) {
      case 'spotifyTab':
        contentId = 'spotifyResults';
        colorClass = ['border-green-500', 'text-green-600'];
        break;
      case 'manualTab':
        contentId = 'manualForm';
        colorClass = ['border-blue-500', 'text-blue-600'];
        break;
      case 'songsTab':
        contentId = 'songsList';
        colorClass = ['border-purple-500', 'text-purple-600'];
        this.loadSongs();
        break;
    }

    if (activeButton && contentId) {
      activeButton.classList.remove('border-transparent', 'text-gray-500');
      activeButton.classList.add(...colorClass);
      document.getElementById(contentId).classList.remove('hidden');
    }
  }

  displayPagination(pagination, containerId, loadFunction) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '';

    if (pagination.last_page <= 1) return;

    const nav = document.createElement('nav');
    nav.className = 'flex items-center justify-between';

    const info = document.createElement('div');
    info.className = 'text-sm text-gray-700';
    info.textContent = `${pagination.total}件中 ${((pagination.current_page - 1) * pagination.per_page) + 1}-${Math.min(pagination.current_page * pagination.per_page, pagination.total)}件`;

    const buttons = document.createElement('div');
    buttons.className = 'flex space-x-2';

    // 前のページ
    if (pagination.current_page > 1) {
      const prevBtn = this.createPaginationButton('前', () => loadFunction(pagination.current_page - 1));
      buttons.appendChild(prevBtn);
    }

    // ページ番号
    const start = Math.max(1, pagination.current_page - 2);
    const end = Math.min(pagination.last_page, pagination.current_page + 2);

    for (let i = start; i <= end; i++) {
      const pageBtn = this.createPaginationButton(i.toString(), () => loadFunction(i), i === pagination.current_page);
      buttons.appendChild(pageBtn);
    }

    // 次のページ
    if (pagination.current_page < pagination.last_page) {
      const nextBtn = this.createPaginationButton('次', () => loadFunction(pagination.current_page + 1));
      buttons.appendChild(nextBtn);
    }

    nav.appendChild(info);
    nav.appendChild(buttons);
    container.appendChild(nav);
  }

  createPaginationButton(text, onClick, isActive = false) {
    const button = document.createElement('button');
    button.textContent = text;
    button.className = `px-3 py-2 text-sm rounded ${isActive ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'}`;
    button.addEventListener('click', onClick);
    if (isActive) {
      button.disabled = true;
    }
    return button;
  }

  showLoading() {
    document.getElementById('loadingModal').classList.remove('hidden');
  }

  hideLoading() {
    document.getElementById('loadingModal').classList.add('hidden');
  }

  showSuccess(message) {
    this.showNotification(message, 'success');
  }

  showError(message) {
    this.showNotification(message, 'error');
  }

  showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 p-4 rounded-md z-50 ${type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' :
      type === 'error' ? 'bg-red-100 border border-red-400 text-red-700' :
        'bg-blue-100 border border-blue-400 text-blue-700'
      }`;
    notification.textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
      notification.remove();
    }, 5000);
  }

  escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return text ? text.replace(/[&<>"']/g, (m) => map[m]) : '';
  }
}

// 初期化
document.addEventListener('DOMContentLoaded', () => {
  new TimestampNormalization();
});