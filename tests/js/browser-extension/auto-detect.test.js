/**
 * auto-detect.js のテスト
 *
 * auto-detect.js は Chrome 拡張のモジュールに依存しているため、
 * テスト対象の純関数だけをインポートできるよう依存モジュールをモックする。
 */
import { vi, describe, it, expect, beforeEach } from 'vitest';

// chrome API のグローバルモック
globalThis.chrome = {
  storage: { local: { get: vi.fn().mockResolvedValue({}) } },
  runtime: { getManifest: vi.fn().mockReturnValue({}) },
};

// auto-detect.js の依存モジュールをモック
vi.mock('../../../browser-extension/src/content/state.js', () => ({
  default: {},
}));
vi.mock('../../../browser-extension/src/content/edition.js', () => ({
  IS_GENERAL_EDITION: false,
}));
vi.mock('../../../browser-extension/src/content/chat-search.js', () => ({
  initChatDB: vi.fn(),
  loadChatDataForVideo: vi.fn(),
  getChatContinuation: vi.fn(),
  fetchAllChatReplays: vi.fn(),
  saveChatsToDB: vi.fn(),
}));
vi.mock('../../../browser-extension/src/content/timestamp-editor.js', () => ({
  pushMarkerHistory: vi.fn(),
  updateTimestampList: vi.fn(),
}));
vi.mock('../../../browser-extension/src/content/volume-graph.js', () => ({
  drawVolumeGraph: vi.fn(),
}));
vi.mock('../../../browser-extension/src/content/timestamp-io.js', () => ({
  saveMarkersToStorage: vi.fn(),
}));
vi.mock('../../../browser-extension/src/content/api.js', () => ({
  sendChatReplayDataToServer: vi.fn(),
}));

const {
  isClapMessage,
  isEmojiOnlyMessage,
  detectClapBursts,
  buildChatBuckets,
} = await import('../../../browser-extension/src/content/auto-detect.js');

describe('isClapMessage', () => {
  it('基本パターン（CLAP_PATTERN）にマッチする', () => {
    expect(isClapMessage('888')).toBe(true);
    expect(isClapMessage('８８８')).toBe(true);
    expect(isClapMessage('8888888')).toBe(true);
    expect(isClapMessage('拍手')).toBe(true);
    expect(isClapMessage('ぱちぱち')).toBe(true);
    expect(isClapMessage('パチパチ')).toBe(true);
    expect(isClapMessage('clap')).toBe(true);
    expect(isClapMessage('CLAP')).toBe(true);
  });

  it('基本パターンにマッチしない通常メッセージ', () => {
    expect(isClapMessage('こんにちは')).toBe(false);
    expect(isClapMessage('88')).toBe(false);
    expect(isClapMessage('かわいい')).toBe(false);
  });

  it('追加パターンにマッチする', () => {
    // :_おめでとう: と :_よかった: は基本パターンにマッチしない独自パターン
    const extra = [':_おめでとう:', ':_よかった:'];
    expect(isClapMessage(':_おめでとう:', extra)).toBe(true);
    expect(isClapMessage(':_よかった:', extra)).toBe(true);
    expect(isClapMessage('素敵な歌声:_おめでとう:', extra)).toBe(true);
  });

  it('追加パターンにマッチしない', () => {
    const extra = [':_おめでとう:'];
    expect(isClapMessage('こんにちは', extra)).toBe(false);
    expect(isClapMessage(':_かわいい:', extra)).toBe(false);
  });

  it('追加パターンが空配列の場合は基本パターンのみで判定する', () => {
    expect(isClapMessage('888', [])).toBe(true);
    expect(isClapMessage('こんにちは', [])).toBe(false);
  });
});

describe('isEmojiOnlyMessage', () => {
  it('非ASCIIショートコードのみのメッセージを絵文字のみと判定する', () => {
    // EMOJI_SHORTCODE_RE はひらがな・カタカナを含むショートコードに対応
    expect(isEmojiOnlyMessage(':_ぱちぱち:')).toBe(true);
    expect(isEmojiOnlyMessage(':_カワイイ:')).toBe(true);
  });

  it('漢字を含むショートコードは誤判定防止のためマッチしない', () => {
    expect(isEmojiOnlyMessage(':_拍手:')).toBe(false);
    expect(isEmojiOnlyMessage(':今日は:')).toBe(false);
  });

  it('ASCIIショートコードのみのメッセージを絵文字のみと判定する', () => {
    expect(isEmojiOnlyMessage(':clap:')).toBe(true);
    expect(isEmojiOnlyMessage(':heart:')).toBe(true);
  });

  it('テキスト混在のメッセージは絵文字のみではない', () => {
    expect(isEmojiOnlyMessage('素敵:_ぱちぱち:')).toBe(false);
    expect(isEmojiOnlyMessage('hello :clap:')).toBe(false);
  });

  it('空文字列やnullはfalse', () => {
    expect(isEmojiOnlyMessage('')).toBe(false);
    expect(isEmojiOnlyMessage(null)).toBe(false);
    expect(isEmojiOnlyMessage(undefined)).toBe(false);
  });
});

describe('detectClapBursts', () => {
  // チャットデータのヘルパー: timestampはミリ秒
  function makeChat(timeSec, message) {
    return { timestamp: (timeSec + 5) * 1000, message }; // CHAT_DELAY_SEC=5 を加算
  }

  it('基本パターンで拍手バーストを検出する', () => {
    const chats = [
      makeChat(100, '888'),
      makeChat(101, '888'),
      makeChat(102, '888'),
      makeChat(103, '888'),
    ];
    const bursts = detectClapBursts(chats, 600);
    expect(bursts.length).toBe(1);
    expect(bursts[0]).toBeCloseTo(100, 0);
  });

  it('追加パターンで拍手バーストを検出する', () => {
    // :_おめでとう: は基本パターンにマッチしない独自絵文字
    const chats = [
      makeChat(200, ':_おめでとう:'),
      makeChat(201, ':_おめでとう:'),
      makeChat(202, ':_おめでとう:'),
    ];
    // 追加パターンなしでは検出されない
    const burstsWithout = detectClapBursts(chats, 600);
    expect(burstsWithout.length).toBe(0);

    // 追加パターンありで検出される
    const burstsWith = detectClapBursts(chats, 600, [':_おめでとう:']);
    expect(burstsWith.length).toBe(1);
    expect(burstsWith[0]).toBeCloseTo(200, 0);
  });

  it('基本パターンと追加パターンを混在で検出する', () => {
    const chats = [
      makeChat(300, '888'),
      makeChat(301, ':_おめでとう:'), // 追加パターンでのみマッチ
      makeChat(302, '拍手'),
    ];
    const bursts = detectClapBursts(chats, 600, [':_おめでとう:']);
    expect(bursts.length).toBe(1);
  });

  it('空のチャット配列は空の結果を返す', () => {
    const bursts = detectClapBursts([], 600);
    expect(bursts).toEqual([]);
  });
});

describe('buildChatBuckets', () => {
  function makeChat(timeSec, message) {
    return { timestamp: (timeSec + 5) * 1000, message };
  }

  it('追加パターンで拍手カウントが増える', () => {
    // :_よかった: は基本パターンにマッチしない独自絵文字
    const chats = [
      makeChat(5, ':_よかった:'),
      makeChat(6, ':_よかった:'),
      makeChat(7, '888'),
    ];

    // 追加パターンなし: :_よかった: は拍手としてカウントされない
    const bucketsWithout = buildChatBuckets(chats, 60, 10);
    const bucket0Without = bucketsWithout[0];
    expect(bucket0Without.clap).toBe(1); // 888 のみ

    // 追加パターンあり: :_よかった: も拍手としてカウントされる
    const bucketsWith = buildChatBuckets(chats, 60, 10, [':_よかった:']);
    const bucket0With = bucketsWith[0];
    expect(bucket0With.clap).toBe(3); // 888 + :_よかった: x 2
  });

  it('拍手にマッチしたメッセージは emojiOnly にカウントされない', () => {
    const chats = [
      makeChat(5, ':_よかった:'), // 追加パターンで拍手
    ];

    const buckets = buildChatBuckets(chats, 60, 10, [':_よかった:']);
    expect(buckets[0].clap).toBe(1);
    expect(buckets[0].emojiOnly).toBe(0);
  });
});
