/**
 * ページコンテキストで実行されるブリッジスクリプト
 * content scriptからはYouTubeページのJS変数にアクセスできないため、
 * このスクリプトをページに注入して字幕データを取得する。
 * web_accessible_resourcesとして登録し、<script src>で読み込むことでCSPを回避する。
 */
(function () {
  window.addEventListener('message', function (event) {
    if (event.source !== window) return;

    // 字幕トラック一覧の取得
    if (event.data?.type === 'YCS_GET_CAPTION_TRACKS') {
      const playerResponse = window.ytInitialPlayerResponse;
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

    // timedtext APIで字幕を取得（JSON→XMLフォールバック）
    if (event.data?.type === 'YCS_FETCH_TIMEDTEXT') {
      const baseUrl = event.data.baseUrl;
      if (!baseUrl) {
        window.postMessage({ type: 'YCS_TIMEDTEXT_RESPONSE', error: 'baseUrlが指定されていません' }, '*');
        return;
      }

      // JSON3形式を試行し、失敗時はXML形式にフォールバック
      function tryFetchJson3() {
        const url = new URL(baseUrl);
        url.searchParams.set('fmt', 'json3');
        return fetch(url.toString())
          .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
          })
          .then(text => {
            if (!text || text.trim().length === 0) throw new Error('empty response');
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

      function tryFetchXml() {
        return fetch(baseUrl)
          .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
          })
          .then(text => {
            if (!text || text.trim().length === 0) throw new Error('empty response');
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
                text: content.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&#39;/g, "'").replace(/&quot;/g, '"'),
              });
            }
            return segments;
          });
      }

      tryFetchJson3()
        .catch(() => tryFetchXml())
        .then(segments => {
          window.postMessage({ type: 'YCS_TIMEDTEXT_RESPONSE', segments }, '*');
        })
        .catch(err => {
          window.postMessage({ type: 'YCS_TIMEDTEXT_RESPONSE', error: 'timedtext API: ' + err.message }, '*');
        });
    }

    // get_transcript APIで字幕テキストを取得
    // YouTubeページのytInitialDataからトランスクリプト用パラメータを取得し、
    // get_transcript APIに渡す。手動でprotobufを構築するのではなく、
    // YouTubeが用意したパラメータをそのまま使用する。
    if (event.data?.type === 'YCS_GET_TRANSCRIPT') {
      try {
        // ytInitialDataのengagementPanelsからトランスクリプトパネルのパラメータを取得
        const panels = window.ytInitialData?.engagementPanels || [];
        let transcriptParams = null;

        for (const panel of panels) {
          const panelId = panel?.engagementPanelSectionListRenderer?.panelIdentifier;
          if (panelId === 'engagement-panel-searchable-transcript') {
            // トランスクリプトパネルの継続トークンまたはパラメータを取得
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
        const clientVersion = cfg?.get?.('INNERTUBE_CLIENT_VERSION') || '2.20260316.00.00';
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
            // レスポンス構造を探索してセグメントを抽出
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
