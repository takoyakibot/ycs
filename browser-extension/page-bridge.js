/**
 * ページコンテキストで実行されるブリッジスクリプト
 * content scriptからはYouTubeページのJS変数にアクセスできないため、
 * このスクリプトをページに注入して字幕データを取得する。
 * web_accessible_resourcesとして登録し、<script src>で読み込むことでCSPを回避する。
 */
(function () {
  // timedtextレスポンスのキャッシュ（fetchインターセプトで収集）
  const timedTextCache = {};

  /**
   * fetchをインターセプトしてtimedtextレスポンスをキャッシュする。
   * YouTubeプレイヤーが字幕を読み込む際のリクエストを横取りする。
   */
  const originalFetch = window.fetch;
  window.fetch = async function (...args) {
    const response = await originalFetch.apply(this, args);
    try {
      const url = typeof args[0] === 'string' ? args[0] : args[0]?.url || '';
      if (url.includes('/api/timedtext') || url.includes('timedtext')) {
        const clone = response.clone();
        const text = await clone.text();
        if (text && text.trim().length > 0) {
          // URLからlangパラメータを抽出してキーにする
          const urlObj = new URL(url, location.origin);
          const lang = urlObj.searchParams.get('lang') || 'unknown';
          const videoId = urlObj.searchParams.get('v') || 'unknown';
          timedTextCache[videoId + ':' + lang] = { text, url, timestamp: Date.now() };
        }
      }
    } catch (e) {
      // インターセプトのエラーは無視（元のfetchに影響させない）
    }
    return response;
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
   * キャッシュまたはプレイヤー字幕有効化でtimedtextを取得
   */
  function getTimedTextViaPlayer(videoId, lang) {
    return new Promise((resolve, reject) => {
      // 1. キャッシュを確認
      const cacheKey = videoId + ':' + lang;
      const cached = timedTextCache[cacheKey];
      if (cached && (Date.now() - cached.timestamp) < 300000) {
        try {
          const segments = cached.text.trim().startsWith('{')
            ? parseJson3(cached.text)
            : parseXml(cached.text);
          resolve(segments);
          return;
        } catch (e) {
          // パース失敗は無視してプレイヤー経由で取得
        }
      }

      // 2. プレイヤーの字幕を有効にしてインターセプトを待つ
      const player = document.querySelector('#movie_player');
      if (!player) {
        reject(new Error('movie_playerが見つかりません'));
        return;
      }

      // 現在の字幕状態を保存
      const wasSubtitleOn = player.getOption?.('captions', 'track') != null;

      // 字幕トラックリストを取得
      const tracklist = player.getOption?.('captions', 'tracklist') || [];
      let targetTrack = tracklist.find(t => t.languageCode === lang);
      if (!targetTrack) targetTrack = tracklist.find(t => t.languageCode?.startsWith(lang));
      if (!targetTrack && tracklist.length > 0) targetTrack = tracklist[0];

      if (!targetTrack) {
        reject(new Error('字幕トラックがプレイヤーで利用できません'));
        return;
      }

      // インターセプト待ちのリスナーを設定
      let resolved = false;
      const checkInterval = setInterval(() => {
        const key = videoId + ':' + (targetTrack.languageCode || lang);
        const entry = timedTextCache[key];
        if (entry && (Date.now() - entry.timestamp) < 5000) {
          clearInterval(checkInterval);
          clearTimeout(timeoutId);
          if (resolved) return;
          resolved = true;

          // 元の字幕状態に戻す
          if (!wasSubtitleOn) {
            try { player.setOption?.('captions', 'track', {}); } catch (e) { /* ignore */ }
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

        // 元の字幕状態に戻す
        if (!wasSubtitleOn) {
          try { player.setOption?.('captions', 'track', {}); } catch (e) { /* ignore */ }
        }

        reject(new Error('プレイヤー経由の字幕取得がタイムアウトしました'));
      }, 8000);

      // 字幕を有効にする（これによりプレイヤーがtimedtextをfetchする）
      try {
        player.setOption?.('captions', 'track', targetTrack);
      } catch (e) {
        clearInterval(checkInterval);
        clearTimeout(timeoutId);
        reject(new Error('字幕の有効化に失敗: ' + e.message));
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
