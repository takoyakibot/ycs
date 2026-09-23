import { basename } from 'path';

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

const generalStubs = {
  'list-scan.js': 'stubs/list-scan.js',
  'highlight.js': 'stubs/highlight.js',
  'subtitle-panel.js': 'stubs/subtitle-panel.js',
  'song-candidates.js': 'song-candidates-general.js',
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
    plugins: [editionStubs(generalStubs)],
  },
];
