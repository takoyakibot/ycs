let captureStream = null;
let recognition = null;
let settings = {
  lang: 'ko',
  mode: 'full',   // 'full' | 'partial'
  partialN: 3,
  deeplKey: ''
};

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
      if (recognition) {
        recognition.lang = settings.lang === 'en' ? 'en-US' : 'ko';
      }
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

  // AudioContextに接続して音声をアクティブに保つ
  const audioContext = new AudioContext();
  const source = audioContext.createMediaStreamSource(captureStream);
  source.connect(audioContext.createAnalyser());

  recognition = new webkitSpeechRecognition();
  recognition.lang = settings.lang === 'en' ? 'en-US' : 'ko';
  recognition.continuous = true;
  recognition.interimResults = true;

  recognition.onresult = async (event) => {
    for (let i = event.resultIndex; i < event.results.length; i++) {
      if (event.results[i].isFinal) {
        const text = event.results[i][0].transcript.trim();
        if (!text) continue;
        await handleFinalText(text);
      }
    }
  };

  recognition.onerror = (event) => {
    console.error('SpeechRecognition error:', event.error);
    if (event.error === 'no-speech' || event.error === 'aborted') {
      // 自動再開
      try { recognition.start(); } catch (e) {}
    }
  };

  recognition.onend = () => {
    // continuous modeでも環境によって停止する場合がある — 自動再開
    if (captureStream) {
      try { recognition.start(); } catch (e) {}
    }
  };

  recognition.start();
  return { success: true };
}

function stopRecognition() {
  if (recognition) {
    recognition.onend = null; // 自動再開を防ぐ
    recognition.abort();
    recognition = null;
  }
  if (captureStream) {
    captureStream.getTracks().forEach(track => track.stop());
    captureStream = null;
  }
}

async function handleFinalText(text) {
  let translated;

  if (settings.mode === 'partial') {
    translated = await translatePartial(text, settings.partialN);
  } else {
    translated = await translateFull(text);
  }

  chrome.runtime.sendMessage({
    type: 'TRANSLATION_RESULT',
    original: text,
    translated: translated
  });
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

  // N単語ごとに1つの単語を翻訳対象として選ぶ
  const indicesToTranslate = [];
  for (let i = 0; i < words.length; i += n) {
    indicesToTranslate.push(i);
  }

  if (indicesToTranslate.length === 0) return text;

  // 翻訳対象の単語をまとめてDeepL APIに送る
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

  // 翻訳結果を原文に差し込む
  const result = [...words];
  indicesToTranslate.forEach((wordIndex, transIndex) => {
    if (translations[transIndex]?.text) {
      result[wordIndex] = `[${translations[transIndex].text}]`;
    }
  });

  return result.join(' ');
}
