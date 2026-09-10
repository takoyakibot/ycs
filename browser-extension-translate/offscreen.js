let captureStream = null;
let audioContext = null;
let mediaRecorder = null;
let analyser = null;
let chunkTimer = null;
let audioChunks = [];
let settings = {
  lang: 'ko',
  mode: 'full',
  partialN: 3,
  openaiKey: '',
  deeplKey: ''
};

const CHUNK_INTERVAL_MS = 3000;
const SILENCE_THRESHOLD = 0.01;

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
  const source = audioContext.createMediaStreamSource(captureStream);

  analyser = audioContext.createAnalyser();
  analyser.fftSize = 2048;
  source.connect(analyser);

  mediaRecorder = new MediaRecorder(captureStream, { mimeType: 'audio/webm;codecs=opus' });
  audioChunks = [];

  mediaRecorder.ondataavailable = (event) => {
    if (event.data.size > 0) {
      audioChunks.push(event.data);
    }
  };

  mediaRecorder.start();

  chunkTimer = setInterval(() => processChunk(), CHUNK_INTERVAL_MS);

  return { success: true };
}

function stopRecognition() {
  if (chunkTimer) {
    clearInterval(chunkTimer);
    chunkTimer = null;
  }
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
    mediaRecorder = null;
  }
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
  if (!mediaRecorder || mediaRecorder.state === 'inactive') return;

  if (isSilent()) {
    audioChunks = [];
    return;
  }

  // 現在のレコーダーを停止して新しいのを開始し、チャンクを回収
  const chunks = await collectChunks();
  if (!chunks || chunks.length === 0) return;

  const audioBlob = new Blob(chunks, { type: 'audio/webm;codecs=opus' });
  if (audioBlob.size < 1000) return;

  try {
    const text = await transcribeWithWhisper(audioBlob);
    if (!text || text.trim() === '') return;

    const translated = await translateText(text.trim());

    chrome.runtime.sendMessage({
      type: 'TRANSLATION_RESULT',
      original: text.trim(),
      translated: translated
    });
  } catch (error) {
    console.error('処理エラー:', error);
  }
}

async function collectChunks() {
  return new Promise((resolve) => {
    if (!mediaRecorder || mediaRecorder.state === 'inactive') {
      resolve(null);
      return;
    }

    const collected = [...audioChunks];
    audioChunks = [];

    // 一旦停止して溜まったデータを回収、すぐ再開
    mediaRecorder.stop();

    mediaRecorder.onstop = () => {
      const allChunks = [...collected, ...audioChunks];
      audioChunks = [];

      if (captureStream && captureStream.active) {
        mediaRecorder = new MediaRecorder(captureStream, { mimeType: 'audio/webm;codecs=opus' });
        mediaRecorder.ondataavailable = (event) => {
          if (event.data.size > 0) {
            audioChunks.push(event.data);
          }
        };
        mediaRecorder.start();
      }

      resolve(allChunks);
    };
  });
}

async function transcribeWithWhisper(audioBlob) {
  if (!settings.openaiKey) return null;

  const lang = settings.lang === 'en' ? 'en' : 'ko';

  const formData = new FormData();
  formData.append('file', audioBlob, 'audio.webm');
  formData.append('model', 'whisper-1');
  formData.append('language', lang);

  const response = await fetch('https://api.openai.com/v1/audio/transcriptions', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${settings.openaiKey}`
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

async function translateFull(text) {
  if (!settings.deeplKey) return '(DeepL APIキー未設定)';

  const sourceLang = settings.lang === 'en' ? 'EN' : 'KO';

  const response = await fetch('https://api-free.deepl.com/v2/translate', {
    method: 'POST',
    headers: {
      'Authorization': `DeepL-Auth-Key ${settings.deeplKey}`,
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
  if (!settings.deeplKey) return '(DeepL APIキー未設定)';

  const words = text.split(/\s+/).filter(w => w.length > 0);
  if (words.length === 0) return text;

  const indicesToTranslate = [];
  for (let i = 0; i < words.length; i += n) {
    indicesToTranslate.push(i);
  }

  if (indicesToTranslate.length === 0) return text;

  const wordsToTranslate = indicesToTranslate.map(i => words[i]);
  const sourceLang = settings.lang === 'en' ? 'EN' : 'KO';

  const response = await fetch('https://api-free.deepl.com/v2/translate', {
    method: 'POST',
    headers: {
      'Authorization': `DeepL-Auth-Key ${settings.deeplKey}`,
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
