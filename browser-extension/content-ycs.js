/**
 * 歌枠タイムスタンプ検出 - ycs管理画面連携用Content Script
 *
 * ycs管理画面でスキャン済みアーカイブを表示するための機能を提供
 */

// 旧形式互換: 解像度はデータの長さから取得

/**
 * スキャン済みvideoIdリストを取得してページに通知
 */
async function notifyScannedVideoIds() {
  try {
    const allData = await chrome.storage.local.get(null);
    const scannedIds = [];

    for (const key in allData) {
      if (key.startsWith('volumeData_')) {
        const videoId = key.replace('volumeData_', '');
        const data = allData[key];

        if (!data || !data.data || !Array.isArray(data.data)) {
          continue;
        }

        const filledCount = data.data.filter(v => v > 0).length;
        const progress = Math.round((filledCount / data.data.length) * 100);

        scannedIds.push({
          videoId,
          progress: progress >= 95 ? 100 : progress,
          status: progress >= 95 ? 'completed' : (progress > 0 ? 'partial' : 'not_scanned'),
          scannedAt: data.savedAt || null
        });
      }
    }

    console.log(`[YCS Extension] スキャン済みvideoId: ${scannedIds.length}件`);

    // カスタムイベントで通知
    window.dispatchEvent(new CustomEvent('ycs-scanned-videos', {
      detail: { scannedIds }
    }));

    return scannedIds;
  } catch (error) {
    console.error('[YCS Extension] スキャン情報取得エラー:', error);
    return [];
  }
}

/**
 * ページに視覚的なマークを追加
 */
function addScanBadges(scannedIds) {
  if (!scannedIds || scannedIds.length === 0) return;

  // スキャン済みvideoIdをマップに変換
  const scannedMap = new Map();
  scannedIds.forEach(item => {
    scannedMap.set(item.videoId, item);
  });

  // アーカイブ行を探してマークを追加
  const archiveRows = document.querySelectorAll('[data-video-id]');
  let markedCount = 0;

  archiveRows.forEach(row => {
    const videoId = row.dataset.videoId;
    if (!videoId) return;

    const scanInfo = scannedMap.get(videoId);
    if (!scanInfo) return;

    // 既にマークがあればスキップ
    if (row.querySelector('.ycs-scan-badge')) return;

    const badge = createScanBadge(scanInfo);
    const titleEl = row.querySelector('.archive-title') || row.querySelector('td:first-child') || row;
    titleEl.appendChild(badge);
    markedCount++;
  });

  console.log(`[YCS Extension] ${markedCount}件のアーカイブにスキャンマークを追加`);
}

/**
 * スキャンバッジを作成
 */
function createScanBadge(scanInfo) {
  const badge = document.createElement('span');
  badge.className = 'ycs-scan-badge';

  // スタイルを追加
  if (!document.getElementById('ycs-badge-styles')) {
    const style = document.createElement('style');
    style.id = 'ycs-badge-styles';
    style.textContent = `
      .ycs-scan-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-left: 8px;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 500;
      }
      .ycs-scan-badge.completed {
        background: #e8f5e9;
        color: #2e7d32;
      }
      .ycs-scan-badge.partial {
        background: #fff3e0;
        color: #f57c00;
      }
      .ycs-scan-badge .ycs-badge-icon {
        font-size: 12px;
      }
    `;
    document.head.appendChild(style);
  }

  if (scanInfo.status === 'completed') {
    badge.classList.add('completed');
    badge.innerHTML = '<span class="ycs-badge-icon">✓</span> スキャン済み';
    badge.title = `スキャン完了 (100%)`;
  } else {
    badge.classList.add('partial');
    badge.innerHTML = `<span class="ycs-badge-icon">◐</span> ${scanInfo.progress}%`;
    badge.title = `スキャン途中 (${scanInfo.progress}%)`;
  }

  return badge;
}

/**
 * ページ変更を監視してバッジを更新（SPAナビゲーション対応）
 */
function observePageChanges(scannedIds) {
  const observer = new MutationObserver(() => {
    addScanBadges(scannedIds);
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true
  });

  // 1分後に監視を停止（リソース節約）
  setTimeout(() => observer.disconnect(), 60000);
}

/**
 * 初期化
 */
async function init() {
  console.log('[YCS Extension] ycs管理画面連携を初期化');

  // スキャン情報を取得してページに通知
  const scannedIds = await notifyScannedVideoIds();

  // バッジを追加
  addScanBadges(scannedIds);

  // ページ変更を監視
  observePageChanges(scannedIds);

  // ストレージ変更を監視してリアルタイム更新
  chrome.storage.onChanged.addListener((changes, areaName) => {
    if (areaName !== 'local') return;

    // volumeData_*が変更されたら再通知
    const hasVolumeDataChange = Object.keys(changes).some(key => key.startsWith('volumeData_'));
    if (hasVolumeDataChange) {
      notifyScannedVideoIds().then(addScanBadges);
    }
  });
}

// 少し待ってから初期化（ページの読み込み完了を待つ）
setTimeout(init, 1000);
