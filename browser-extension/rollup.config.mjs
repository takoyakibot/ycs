function editionStubs(stubs) {
  return {
    name: 'edition-stubs',
    resolveId(source, importer) {
      if (!importer || !source.startsWith('./')) return null;
      const basename = source.split('/').pop();
      if (stubs[basename]) {
        const importerDir = importer.substring(0, importer.lastIndexOf('/') + 1);
        return importerDir + stubs[basename];
      }
      return null;
    },
  };
}

const generalStubs = {
  'list-scan.js': 'stubs/list-scan.js',
  'chat-search.js': 'stubs/chat-search.js',
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
