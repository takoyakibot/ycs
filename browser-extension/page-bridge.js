/**
 * ページコンテキストで実行されるブリッジスクリプト
 * content scriptからはYouTubeページのJS変数にアクセスできないため、
 * このスクリプトをページに注入して字幕データを取得する。
 * web_accessible_resourcesとして登録し、<script src>で読み込むことでCSPを回避する。
 */
(function () {
  // timedtextレスポンスのキャッシュ（fetch/XHRインターセプトで収集）
  const timedTextCache = {};

  function cacheTimedText(url, text) {
    if (!text || text.trim().length === 0) return;
    try {
      const urlObj = new URL(url, location.origin);
      const lang = urlObj.searchParams.get('lang') || 'unknown';
      const videoId = urlObj.searchParams.get('v') || 'unknown';
      timedTextCache[videoId + ':' + lang] = { text, url, timestamp: Date.now() };
    } catch (e) { /* ignore */ }
  }

  /**
   * fetchをインターセプトしてtimedtextレスポンスをキャッシュする。
   */
  const originalFetch = window.fetch;
  window.fetch = async function (...args) {
    const response = await originalFetch.apply(this, args);
    try {
      const url = typeof args[0] === 'string' ? args[0] : args[0]?.url || '';
      if (url.includes('timedtext')) {
        const clone = response.clone();
        const text = await clone.text();
        cacheTimedText(url, text);
      }
    } catch (e) { /* ignore */ }
    return response;
  };

  /**
   * XMLHttpRequestもインターセプト（プレイヤーがXHRを使う場合）
   */
  const originalXHROpen = XMLHttpRequest.prototype.open;
  const originalXHRSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url, ...rest) {
    this._ycsUrl = url;
    return originalXHROpen.call(this, method, url, ...rest);
  };
  XMLHttpRequest.prototype.send = function (...args) {
    if (this._ycsUrl && String(this._ycsUrl).includes('timedtext')) {
      this.addEventListener('load', function () {
        try { cacheTimedText(this._ycsUrl, this.responseText); } catch (e) { /* ignore */ }
      });
    }
    return originalXHRSend.apply(this, args);
  };

  /**
   * 最新のプレイヤーレスポンスを取得する。
   */
  function getPlayerResponse() {
    const player = document.querySelector('#movie_player');
    const fresh = player?.getPlayerResponse?.();
    if (fresh?.captions) return fresh;
    return window.ytInitialPlayerResponse;
  }

  /**
   * timedtextのJSON3レスポンスをセグメントに変換
   */
  function parseJson3(text) {
    const data = JSON.parse(text);
    const segments = [];
    for (const ev of (data.events || [])) {
      if (!ev.segs) continue;
      const t = ev.segs.map(s => s.utf8 || '').join('');
      if (!t.trim()) continue;
      segments.push({
        start: (ev.tStartMs || 0) / 1000,
        duration: (ev.dDurationMs || 0) / 1000,
        text: t,
      });
    }
    return segments;
  }

  /**
   * timedtextのXMLレスポンスをセグメントに変換
   */
  function parseXml(text) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(text, 'text/xml');
    const textEls = doc.querySelectorAll('text');
    const segments = [];
    for (const el of textEls) {
      const content = el.textContent || '';
      if (!content.trim()) continue;
      segments.push({
        start: parseFloat(el.getAttribute('start') || '0'),
        duration: parseFloat(el.getAttribute('dur') || '0'),
        text: content,
      });
    }
    return segments;
  }

  /**
   * キャッシュまたはCCボタン経由でtimedtextを取得
   */
  function getTimedTextViaPlayer(videoId, lang) {
    return new Promise((resolve, reject) => {
      // 1. キャッシュを確認（言語完全一致→前方一致→任意の言語）
      function findCache() {
        const exact = timedTextCache[videoId + ':' + lang];
        if (exact && (Date.now() - exact.timestamp) < 300000) return exact;
        for (const key of Object.keys(timedTextCache)) {
          if (key.startsWith(videoId + ':') && (Date.now() - timedTextCache[key].timestamp) < 300000) {
            return timedTextCache[key];
          }
        }
        return null;
      }

      const cached = findCache();
      if (cached) {
        try {
          const segments = cached.text.trim().startsWith('{')
            ? parseJson3(cached.text)
            : parseXml(cached.text);
          resolve(segments);
          return;
        } catch (e) { /* パース失敗は無視 */ }
      }

      // 2. CCボタンをクリックして字幕を有効にし、インターセプトを待つ
      const ccButton = document.querySelector('.ytp-subtitles-button');
      if (!ccButton) {
        reject(new Error('字幕ボタンが見つかりません'));
        return;
      }

      const wasOn = ccButton.getAttribute('aria-pressed') === 'true';

      let resolved = false;
      const checkInterval = setInterval(() => {
        const entry = findCache();
        if (entry && (Date.now() - entry.timestamp) < 5000) {
          clearInterval(checkInterval);
          clearTimeout(timeoutId);
          if (resolved) return;
          resolved = true;

          // 元の状態に戻す
          if (!wasOn) {
            try { ccButton.click(); } catch (e) { /* ignore */ }
          }

          try {
            const segments = entry.text.trim().startsWith('{')
              ? parseJson3(entry.text)
              : parseXml(entry.text);
            resolve(segments);
          } catch (e) {
            reject(new Error('字幕データのパースに失敗: ' + e.message));
          }
        }
      }, 100);

      const timeoutId = setTimeout(() => {
        clearInterval(checkInterval);
        if (resolved) return;
        resolved = true;

        if (!wasOn) {
          try { ccButton.click(); } catch (e) { /* ignore */ }
        }

        // タイムアウト時にキャッシュにあれば使う（古くてもOK）
        const lastResort = findCache();
        if (lastResort) {
          try {
            const segments = lastResort.text.trim().startsWith('{')
              ? parseJson3(lastResort.text)
              : parseXml(lastResort.text);
            resolve(segments);
            return;
          } catch (e) { /* ignore */ }
        }

        reject(new Error('プレイヤー経由の字幕取得がタイムアウトしました（CCボタンクリック後にtimedtextリクエストを検出できませんでした）'));
      }, 8000);

      // CCが既にONならOFF→ONでリロードを強制
      if (wasOn) {
        ccButton.click(); // OFF
        setTimeout(() => { ccButton.click(); }, 300); // ON
      } else {
        ccButton.click(); // ON
      }
    });
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window) return;

    // 字幕トラック一覧の取得
    if (event.data?.type === 'YCS_GET_CAPTION_TRACKS') {
      const playerResponse = getPlayerResponse();
      const captionTracks = playerResponse?.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];

      window.postMessage({
        type: 'YCS_CAPTION_TRACKS_RESPONSE',
        tracks: captionTracks.map(track => ({
          languageCode: track.languageCode || '',
          name: track.name?.simpleText || '',
          kind: track.kind || '',
          baseUrl: track.baseUrl || '',
          isTranslatable: track.isTranslatable || false,
        })),
        playabilityStatus: playerResponse?.playabilityStatus?.status || '',
      }, '*');
    }

    // プレイヤー経由で字幕を取得
    if (event.data?.type === 'YCS_FETCH_TIMEDTEXT') {
      const videoId = event.data.videoId;
      const lang = event.data.lang || 'ja';

      if (!videoId) {
        window.postMessage({ type: 'YCS_TIMEDTEXT_RESPONSE', error: 'videoIdが指定されていません' }, '*');
        return;
      }

      getTimedTextViaPlayer(videoId, lang)
        .then(segments => {
          window.postMessage({ type: 'YCS_TIMEDTEXT_RESPONSE', segments }, '*');
        })
        .catch(err => {
          window.postMessage({ type: 'YCS_TIMEDTEXT_RESPONSE', error: err.message }, '*');
        });
    }

    // get_transcript APIで字幕テキストを取得（レガシーフォールバック）
    if (event.data?.type === 'YCS_GET_TRANSCRIPT') {
      try {
        const panels = window.ytInitialData?.engagementPanels || [];
        let transcriptParams = null;

        for (const panel of panels) {
          const panelId = panel?.engagementPanelSectionListRenderer?.panelIdentifier;
          if (panelId === 'engagement-panel-searchable-transcript') {
            const content = panel?.engagementPanelSectionListRenderer?.content;
            const continuation = content?.continuationItemRenderer?.continuationEndpoint
              ?.getTranscriptEndpoint?.params;
            if (continuation) {
              transcriptParams = continuation;
            }
            break;
          }
        }

        if (!transcriptParams) {
          window.postMessage({ type: 'YCS_TRANSCRIPT_RESPONSE', error: 'この動画にはトランスクリプトがありません' }, '*');
          return;
        }

        const cfg = window.ytcfg;
        const clientVersion = cfg?.get?.('INNERTUBE_CLIENT_VERSION') || '2.20260325.00.00';
        const apiKey = cfg?.get?.('INNERTUBE_API_KEY') || '';
        const url = 'https://www.youtube.com/youtubei/v1/get_transcript'
          + (apiKey ? '?key=' + apiKey + '&prettyPrint=false' : '?prettyPrint=false');

        fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            context: {
              client: {
                clientName: 'WEB',
                clientVersion: clientVersion,
                hl: document.documentElement.lang || 'ja',
              },
            },
            params: transcriptParams,
          }),
        })
          .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
          })
          .then(data => {
            const segments = [];
            const actions = data?.actions || [];
            for (const action of actions) {
              const panelContent = action?.updateEngagementPanelAction?.content
                ?.transcriptRenderer?.content?.transcriptSearchPanelRenderer;
              const segmentList = panelContent?.body?.transcriptSegmentListRenderer?.initialSegments
                || [];

              for (const item of segmentList) {
                const seg = item?.transcriptSegmentRenderer;
                if (!seg) continue;
                const startMs = parseInt(seg.startMs || '0', 10);
                const endMs = parseInt(seg.endMs || '0', 10);
                const text = seg.snippet?.runs?.map(r => r.text).join('') || '';
                segments.push({
                  start: startMs / 1000,
                  duration: (endMs - startMs) / 1000,
                  text: text,
                });
              }
            }

            window.postMessage({ type: 'YCS_TRANSCRIPT_RESPONSE', segments }, '*');
          })
          .catch(err => {
            window.postMessage({ type: 'YCS_TRANSCRIPT_RESPONSE', error: err.message }, '*');
          });
      } catch (err) {
        window.postMessage({ type: 'YCS_TRANSCRIPT_RESPONSE', error: err.message }, '*');
      }
    }
  });
})();
