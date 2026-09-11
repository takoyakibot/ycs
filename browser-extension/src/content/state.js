const state = {
  // Video element & time sync
  videoElement: null,
  timeUpdateInterval: null,
  videoDuration: 0,
  videoListenerTarget: null,

  // Volume graph
  volumeGraphContainer: null,
  volumeCanvas: null,
  volumeCtx: null,
  volumeData: [],
  spectralData: [],
  isGraphVisible: false,
  zoomIndex: 0,
  lastSaveTime: 0,
  isRelativeVolumeMode: false,
  graphBaseHeightPx: 60,
  graphHeightStepPx: 20,

  // Audio analysis
  audioContext: null,
  analyserNode: null,
  gainNode: null,
  mediaElementSource: null,
  isScanning: false,
  backgroundScanVideoId: null,
  scanInterval: null,
  originalPlaybackRate: 1,
  audioInitialized: false,

  // Auto-scan (playlist)
  isAutoScanMode: false,
  autoScanStopRequested: false,

  // List scan
  isListScanMode: false,
  listScanProceeding: false,

  // Detected timestamps
  detectedTimestamps: [],

  // Timestamp editor
  tsMarkers: [],
  selectedMarkerId: null,
  nextMarkerId: 1,
  tsHistoryUndo: [],
  tsHistoryRedo: [],
  lastHistoryTag: null,
  lastHistoryTime: 0,
  tsZeroPad: false,
  tsEditorMode: 'marker',

  // Embedded UI
  embeddedUIVisible: true,
  embeddedTriggerButton: null,

  // Subtitle
  currentSubtitles: [],
  currentCaptionTracks: [],
  pageBridgeReady: null,

  // API
  ycsApiToken: null,
  ycsServerUrl: null,
};

export default state;
