import { basename } from 'path';
import { readFileSync, writeFileSync } from 'fs';

const ALLOWED_STUB_RETURNS = new Set(['false', 'true', 'null', 'undefined']);

function editionStubs(stubs) {
  return {
    name: 'edition-stubs',
    resolveId(source, importer) {
      if (!importer || !source.startsWith('./')) return null;
      const bn = source.split('/').pop();
      if (stubs[bn]) {
        const importerDir = importer.substring(0, importer.lastIndexOf('/') + 1);
        return importerDir + stubs[bn];
      }
      return null;
    },
    transform(code, id) {
      if (!id.includes('/stubs/')) return null;

      const fileName = basename(id);
      const returnRegex = /return\s+(.+?)\s*;/g;
      let match;
      while ((match = returnRegex.exec(code)) !== null) {
        const value = match[1].trim();
        if (ALLOWED_STUB_RETURNS.has(value)) continue;
        if (value.startsWith('Promise.reject(')) continue;
        const line = code.substring(0, match.index).split('\n').length;
        this.error(
          `stubs/${fileName}:${line}: return ${value}; — ` +
          `スタブでデータを返すと呼び出し元が静かに壊れます。` +
          `共有ロジックはスタブ対象外のモジュールに移動するか、Promise.reject()でエラーにしてください。`
        );
      }
      return null;
    },
  };
}

// popup.js は管理者版ではそのまま使い、一般版ではこの定数を true に固定してビルドする。
// 定数が畳み込まれ、一般版で到達しない分岐（tabCapture / offscreen / Claude API / 管理者向け設定欄など）が
// tree-shaking で成果物から消える（ストア審査で権限・通信先・使っていない機能として問われないようにするため）
// src/content/ 側は edition.js を edition-general.js に差し替えて同じことをする（generalStubs 参照）
const EDITION_DETECTION = "const IS_GENERAL_EDITION = chrome.runtime.getManifest().x_edition === 'general';";

function fixGeneralEdition() {
  return {
    name: 'fix-general-edition',
    transform(code, id) {
      if (!code.includes(EDITION_DETECTION)) {
        this.error(
          `${basename(id)} に「${EDITION_DETECTION}」が見つかりません。` +
          `一般版の分岐を畳み込めないため、rollup.config.mjs の EDITION_DETECTION を合わせてください。`
        );
      }
      return { code: code.replace(EDITION_DETECTION, 'const IS_GENERAL_EDITION = true;'), map: null };
    },
  };
}

const VOID_ELEMENTS = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);

/**
 * data-admin-only が付いた要素（子孫ごと）と HTML コメントを除く。
 * 同名タグの入れ子を数えて対応する閉じタグまでを消す（popup.html 程度の素直な HTML を前提とする）
 */
function stripAdminOnlyElements(html) {
  let out = html.replace(/<!--[\s\S]*?-->\s*/g, '');
  const openRe = /<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*\sdata-admin-only\b[^>]*>/;
  let match;
  while ((match = openRe.exec(out)) !== null) {
    const tag = match[1].toLowerCase();
    if (VOID_ELEMENTS.has(tag)) {
      throw new Error(`popup.html: 空要素 <${tag}> に data-admin-only は付けられません（囲む要素に付けてください）`);
    }
    const tagRe = new RegExp(`<(/?)${tag}\\b[^>]*>`, 'gi');
    tagRe.lastIndex = match.index + match[0].length;
    let depth = 1;
    let end = -1;
    let m;
    while ((m = tagRe.exec(out)) !== null) {
      depth += m[1] ? -1 : 1;
      if (depth === 0) {
        end = m.index + m[0].length;
        break;
      }
    }
    if (end < 0) {
      throw new Error(`popup.html: data-admin-only の <${tag}> に対応する閉じタグが見つかりません`);
    }
    // 要素の前のインデントと後ろの改行もまとめて消す
    const lineStart = out.lastIndexOf('\n', match.index) + 1;
    const start = /^[ \t]*$/.test(out.slice(lineStart, match.index)) ? lineStart : match.index;
    const after = out.slice(end).match(/^[ \t]*\n/);
    out = out.slice(0, start) + out.slice(end + (after ? after[0].length : 0));
  }
  return out;
}

// 一般版の popup.html（popup-general.html）を生成する。
// 管理者向けの要素は実行時に隠すのではなく、成果物に含めない。
// 見出しは一般版 manifest の name に置き換える。
// あわせて、一般版 popup.js が getElementById で参照する要素が残っているかを検証する
function generalPopupHtml() {
  return {
    name: 'general-popup-html',
    generateBundle(_options, bundle) {
      const source = readFileSync('popup.html', 'utf8');
      const { name } = JSON.parse(readFileSync('manifest.general.json', 'utf8'));
      let html = stripAdminOnlyElements(source);
      html = html
        .replace(/<title>[^<]*<\/title>/, `<title>${name}</title>`)
        .replace(/(<h1 id="popup-title">)[^<]*(<\/h1>)/, `$1${name}$2`);
      if (!html.includes(`<h1 id="popup-title">${name}</h1>`)) {
        this.error('popup.html の <h1 id="popup-title"> が見つかりません。一般版の見出しを置き換えられません');
      }

      const code = Object.values(bundle).map(chunk => chunk.code || '').join('\n');
      for (const [, id] of code.matchAll(/getElementById\('([^']+)'\)/g)) {
        if (!html.includes(`id="${id}"`)) {
          this.error(`一般版 popup.js が参照する #${id} が一般版 popup.html にありません（data-admin-only の付け方を確認してください）`);
        }
      }
      writeFileSync('popup-general.html', html);
    },
  };
}

// 一般版の tree-shaking 設定。
// rollup は既定で try ブロック内の tree-shaking を控える（例外を投げさせる機能検出のため）。
// そのままだと try 内の `if (!IS_GENERAL_EDITION) sendChatReplayDataToServer(...)` が `if (!true) ;` として残り、
// 呼び出し先の関数や管理者向けパネルの変数も参照ありとみなされて成果物に残る。
// 拡張のコードは例外による機能検出をしていないため、一般版では無効にして到達しないコードを確実に消す。
// （管理者版の content.js は従来どおり既定の設定でビルドする）
const GENERAL_TREESHAKE = { tryCatchDeoptimization: false };

const generalStubs = {
  'list-scan.js': 'stubs/list-scan.js',
  'highlight.js': 'stubs/highlight.js',
  'subtitle-panel.js': 'stubs/subtitle-panel.js',
  'song-candidates.js': 'song-candidates-general.js',
  // 管理者向け分岐の定数（src/content/edition.js 参照）
  'edition.js': 'edition-general.js',
};

export default [
  {
    input: 'src/content/index.js',
    output: {
      file: 'content.js',
      format: 'iife',
    },
  },
  {
    input: 'src/content/index-general.js',
    output: {
      file: 'content-general.js',
      format: 'iife',
    },
    treeshake: GENERAL_TREESHAKE,
    plugins: [editionStubs(generalStubs)],
  },
  {
    input: 'popup.js',
    output: {
      file: 'popup-general.js',
      format: 'iife',
    },
    treeshake: GENERAL_TREESHAKE,
    plugins: [fixGeneralEdition(), generalPopupHtml()],
  },
];
