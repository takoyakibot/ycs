let captureStream = null;
let audioContext = null;
let mediaRecorder = null;
let analyser = null;
let processTimer = null;
let audioChunks = [];
let isProcessing = false;
let isStopping = false;
let captureStartTime = null;
let settings = {
  lang: 'ko',
  mode: 'full',
  partialN: 3,
  chunkInterval: 10,
  context: '',
  openaiKey: '',
  deeplKey: ''
};
const SILENCE_THRESHOLD = 0.01;
let chunkSeq = 0;
let nextSendSeq = 0;
const pendingResults = new Map();

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  switch (message.type) {
    case 'START_RECOGNITION':
      if (message.settings) Object.assign(settings, message.settings);
      startRecognition(message.streamId)
        .then(result => sendResponse(result))
        .catch(error => sendResponse({ success: false, error: error.message }));
      return true;

    case 'STOP_RECOGNITION':
      stopRecognition();
      sendResponse({ success: true });
      return true;

    case 'UPDATE_SETTINGS_TO_OFFSCREEN':
      if (message.settings) Object.assign(settings, message.settings);
      sendResponse({ success: true });
      return true;
  }
});

async function startRecognition(streamId) {
  captureStream = await navigator.mediaDevices.getUserMedia({
    audio: {
      mandatory: {
        chromeMediaSource: 'tab',
        chromeMediaSourceId: streamId
      }
    }
  });

  if (!captureStream) {
    return { success: false, error: 'ストリームを取得できませんでした' };
  }

  audioContext = new AudioContext();
  if (audioContext.state === 'suspended') {
    await audioContext.resume();
  }
  const source = audioContext.createMediaStreamSource(captureStream);

  analyser = audioContext.createAnalyser();
  analyser.fftSize = 2048;
  source.connect(analyser);

  isStopping = false;
  audioChunks = [];
  captureStartTime = Date.now();

  createRecorder();
  scheduleProcessing();

  return { success: true };
}

function createRecorder() {
  if (!captureStream || !captureStream.active || isStopping) return;
  audioChunks = [];
  mediaRecorder = new MediaRecorder(captureStream, { mimeType: 'audio/webm;codecs=opus' });
  mediaRecorder.ondataavailable = (event) => {
    if (event.data.size > 0) {
      audioChunks.push(event.data);
    }
  };
  mediaRecorder.start();
}

function stopRecognition() {
  isStopping = true;

  if (processTimer) {
    clearTimeout(processTimer);
    processTimer = null;
  }
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
  }
  mediaRecorder = null;
  audioChunks = [];
  if (captureStream) {
    captureStream.getTracks().forEach(track => track.stop());
    captureStream = null;
  }
  if (audioContext) {
    audioContext.close();
    audioContext = null;
  }
  analyser = null;
  isProcessing = false;
  chunkSeq = 0;
  nextSendSeq = 0;
  pendingResults.clear();
}

function scheduleProcessing() {
  const intervalMs = (settings.chunkInterval || 10) * 1000;
  processTimer = setTimeout(async () => {
    if (isStopping) return;
    await processChunk();
    if (!isStopping) {
      scheduleProcessing();
    }
  }, intervalMs);
}

function isSilent() {
  if (!analyser) return true;
  const dataArray = new Float32Array(analyser.fftSize);
  analyser.getFloatTimeDomainData(dataArray);
  let sum = 0;
  for (let i = 0; i < dataArray.length; i++) {
    sum += dataArray[i] * dataArray[i];
  }
  const rms = Math.sqrt(sum / dataArray.length);
  return rms < SILENCE_THRESHOLD;
}

async function processChunk() {
  if (isProcessing || isStopping || !mediaRecorder) return;
  isProcessing = true;

  try {
    const silent = isSilent();

    // stop()でondataavailableが発火し、完全なWebMファイルが得られる
    const recorder = mediaRecorder;
    mediaRecorder = null;

    await new Promise(resolve => {
      recorder.onstop = resolve;
      recorder.stop();
    });

    if (isStopping) return;

    if (silent) {
      createRecorder();
      return;
    }

    const chunks = audioChunks.splice(0);
    createRecorder();

    if (chunks.length === 0) return;

    const audioBlob = new Blob(chunks, { type: 'audio/webm;codecs=opus' });
    if (audioBlob.size < 1000) return;

    const seq = chunkSeq++;
    let videoTime = null;
    try {
      const vtResponse = await chrome.runtime.sendMessage({ type: 'GET_VIDEO_TIME' });
      videoTime = vtResponse?.currentTime;
    } catch {}
    const elapsed = videoTime != null
      ? Math.round(videoTime)
      : (captureStartTime ? Math.round((Date.now() - captureStartTime) / 1000) : null);

    isProcessing = false;
    processChunkAsync(seq, audioBlob, elapsed);
  } catch (error) {
    console.error('処理エラー:', error);
    if (!mediaRecorder && !isStopping) {
      createRecorder();
    }
    isProcessing = false;
  }
}

async function processChunkAsync(seq, audioBlob, elapsed) {
  try {
    const text = await transcribeWithWhisper(audioBlob);
    if (isStopping) { skipSeq(seq); return; }
    if (!text || text.trim() === '') { skipSeq(seq); return; }

    const translated = await translateText(text.trim());
    if (isStopping) { skipSeq(seq); return; }

    pendingResults.set(seq, { original: text.trim(), translated, elapsed });
    flushPendingResults();
  } catch (error) {
    console.error('非同期処理エラー:', error);
    skipSeq(seq);
  }
}

function skipSeq(seq) {
  pendingResults.set(seq, null);
  flushPendingResults();
}

function flushPendingResults() {
  while (pendingResults.has(nextSendSeq)) {
    const result = pendingResults.get(nextSendSeq);
    pendingResults.delete(nextSendSeq);
    nextSendSeq++;
    if (result) {
      chrome.runtime.sendMessage({
        type: 'TRANSLATION_RESULT',
        original: result.original,
        translated: result.translated,
        elapsed: result.elapsed
      }).catch(() => {});
    }
  }
}

async function getApiKeys() {
  const data = await chrome.storage.local.get(['translateSettings']);
  const saved = data.translateSettings || {};
  return { openaiKey: saved.openaiKey || '', deeplKey: saved.deeplKey || '' };
}

async function transcribeWithWhisper(audioBlob) {
  const { openaiKey } = await getApiKeys();
  if (!openaiKey) return null;

  const lang = settings.lang === 'en' ? 'en' : 'ko';

  const formData = new FormData();
  formData.append('file', audioBlob, 'audio.webm');
  formData.append('model', 'whisper-1');
  formData.append('language', lang);
  if (settings.context) {
    formData.append('prompt', settings.context);
  }

  const response = await fetch('https://api.openai.com/v1/audio/transcriptions', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${openaiKey}`
    },
    body: formData
  });

  if (!response.ok) {
    const errorText = await response.text();
    console.error('Whisper API error:', response.status, errorText);
    return null;
  }

  const data = await response.json();
  return data.text || null;
}

async function translateText(text) {
  if (settings.mode === 'partial') {
    return await translatePartial(text, settings.partialN);
  }
  return await translateFull(text);
}

async function fetchWithRetry(url, options, maxRetries = 2) {
  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    const response = await fetch(url, options);
    if (response.status === 429 && attempt < maxRetries) {
      const retryAfter = parseInt(response.headers.get('Retry-After') || '0', 10);
      await new Promise(r => setTimeout(r, Math.max(retryAfter, 1) * 1000));
      continue;
    }
    return response;
  }
}

async function translateFull(text) {
  const { deeplKey } = await getApiKeys();
  if (!deeplKey) return '(DeepL APIキー未設定)';

  const sourceLang = settings.lang === 'en' ? 'EN' : 'KO';

  const response = await fetchWithRetry('https://api-free.deepl.com/v2/translate', {
    method: 'POST',
    headers: {
      'Authorization': `DeepL-Auth-Key ${deeplKey}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      text: [text],
      source_lang: sourceLang,
      target_lang: 'JA'
    })
  });

  if (!response.ok) {
    console.error('DeepL API error:', response.status);
    return `(翻訳エラー: ${response.status})`;
  }

  const data = await response.json();
  return data.translations?.[0]?.text || '(翻訳結果なし)';
}

async function translatePartial(text, n) {
  const { deeplKey } = await getApiKeys();
  if (!deeplKey) return '(DeepL APIキー未設定)';

  const words = text.split(/\s+/).filter(w => w.length > 0);
  if (words.length === 0) return text;

  const indicesToTranslate = [];
  for (let i = 0; i < words.length; i += n) {
    indicesToTranslate.push(i);
  }

  if (indicesToTranslate.length === 0) return text;

  const wordsToTranslate = indicesToTranslate.map(i => words[i]);
  const sourceLang = settings.lang === 'en' ? 'EN' : 'KO';

  const response = await fetchWithRetry('https://api-free.deepl.com/v2/translate', {
    method: 'POST',
    headers: {
      'Authorization': `DeepL-Auth-Key ${deeplKey}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      text: wordsToTranslate,
      source_lang: sourceLang,
      target_lang: 'JA'
    })
  });

  if (!response.ok) {
    console.error('DeepL API error:', response.status);
    return text;
  }

  const data = await response.json();
  const translations = data.translations || [];

  const result = [...words];
  indicesToTranslate.forEach((wordIndex, transIndex) => {
    if (translations[transIndex]?.text) {
      result[wordIndex] = `[${translations[transIndex].text}]`;
    }
  });

  return result.join(' ');
}
