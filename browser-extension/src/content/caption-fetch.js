import { getVideoId } from './utils.js';

const INNERTUBE_API_KEY = 'AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8';

function parseJson3(data) {
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

export function pickPreferredCaptionTrack(tracks) {
  const ja = tracks.filter(t => (t.languageCode || '').startsWith('ja'));
  return ja.find(t => t.kind !== 'asr') || ja[0] || tracks[0];
}

export async function getCaptionTracksViaInnerTube(videoId) {
  const response = await fetch(`https://www.youtube.com/youtubei/v1/player?key=${INNERTUBE_API_KEY}&prettyPrint=false`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({
      context: {
        client: {
          clientName: 'WEB',
          clientVersion: '2.20250911.01.00',
          hl: document.documentElement.lang || 'ja',
        },
      },
      videoId: videoId,
    }),
  });
  if (!response.ok) throw new Error(`InnerTube API error: ${response.status}`);
  const data = await response.json();
  const captionTracks = data.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
  return captionTracks.map(track => ({
    languageCode: track.languageCode || '',
    name: track.name?.simpleText || '',
    kind: track.kind || '',
    baseUrl: track.baseUrl || '',
  }));
}

export async function fetchTimedTextDirect(baseUrl) {
  const url = new URL(baseUrl);
  url.searchParams.set('fmt', 'json3');
  const response = await fetch(url.toString());
  if (!response.ok) throw new Error(`timedtext fetch error: ${response.status}`);
  const text = await response.text();
  if (text.trim().startsWith('{')) {
    return parseJson3(JSON.parse(text));
  }
  return parseXml(text);
}

export function extractSubtitleWindow(segments, sec, windowSec = 60) {
  const halfWindow = windowSec / 2;
  const start = sec - halfWindow;
  const end = sec + halfWindow;
  return segments
    .filter(s => s.start >= start && s.start < end)
    .map(s => s.text)
    .join(' ');
}
