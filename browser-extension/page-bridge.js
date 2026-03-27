/**
 * ページコンテキストで実行されるブリッジスクリプト
 * content scriptからはYouTubeページのJS変数にアクセスできないため、
 * このスクリプトをページに注入して字幕データを取得する。
 * web_accessible_resourcesとして登録し、<script src>で読み込むことでCSPを回避する。
 */
(function () {
  /**
   * 最新のプレイヤーレスポンスを取得する。
   * ytInitialPlayerResponseはSPA遷移で古くなるため、
   * movie_player.getPlayerResponse()を優先的に使用する。
   */
  function getPlayerResponse() {
    const player = document.querySelector('#movie_player');
    const fresh = player?.getPlayerResponse?.();
    if (fresh?.captions) return fresh;
    return window.ytInitialPlayerResponse;
  }

  /**
   * InnerTube player APIを呼び出して最新のプレイヤーデータを取得する。
   * baseUrlの署名期限切れを回避するため、字幕取得時に毎回呼び出す。
   */
  function callInnerTubePlayer(videoId) {
    const cfg = window.ytcfg;
    const clientVersion = cfg?.get?.('INNERTUBE_CLIENT_VERSION') || '2.20260325.00.00';
    const apiKey = cfg?.get?.('INNERTUBE_API_KEY') || '';
    const url = 'https://www.youtube.com/youtubei/v1/player'
      + (apiKey ? '?key=' + apiKey + '&prettyPrint=false' : '?prettyPrint=false');

    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        context: {
          client: {
            clientName: 'WEB',
            clientVersion: clientVersion,
            hl: document.documentElement.lang || 'ja',
          },
        },
        videoId: videoId,
      }),
    }).then(res => {
      if (!res.ok) throw new Error('InnerTube player API HTTP ' + res.status);
      return res.json();
    });
  }

  /**
   * timedtext URLから字幕セグメントを取得する（JSON3→XMLフォールバック）
   */
  function fetchTimedTextSegments(baseUrl) {
    function tryJson3() {
      const url = new URL(baseUrl);
      url.searchParams.set('fmt', 'json3');
      return fetch(url.toString())
        .then(res => {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.text();
        })
        .then(text => {
          if (!text || text.trim().length === 0) throw new Error('empty');
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
        });
    }

    function tryXml() {
      return fetch(baseUrl)
        .then(res => {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.text();
        })
        .then(text => {
          if (!text || text.trim().length === 0) throw new Error('empty');
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
        });
    }

    return tryJson3().catch(() => tryXml());
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

    // timedtext APIで字幕を取得
    // InnerTube player APIを呼んで最新のbaseUrlを取得してからfetchする。
    if (event.data?.type === 'YCS_FETCH_TIMEDTEXT') {
      const videoId = event.data.videoId;
      const lang = event.data.lang || 'ja';

      if (!videoId) {
        window.postMessage({ type: 'YCS_TIMEDTEXT_RESPONSE', error: 'videoIdが指定されていません' }, '*');
        return;
      }

      callInnerTubePlayer(videoId)
        .then(data => {
          const tracks = data?.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
          // 指定言語のトラックを検索（完全一致→前方一致）
          let track = tracks.find(t => t.languageCode === lang);
          if (!track) track = tracks.find(t => t.languageCode?.startsWith(lang));
          if (!track && tracks.length > 0) track = tracks[0];

          if (!track?.baseUrl) {
            throw new Error('この動画には利用可能な字幕がありません');
          }

          return fetchTimedTextSegments(track.baseUrl);
        })
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
