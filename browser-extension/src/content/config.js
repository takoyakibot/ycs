export const SAMPLING_INTERVAL_SEC = 2;
export const LEGACY_GRAPH_RESOLUTION = 500;

export const MARKER_SNAP_THRESHOLD_SEC = 3;
export const MARKER_SNAP_THRESHOLD_PX = 8;

export const ZOOM_LEVELS = [1, 1.5, 2, 3, 4, 5, 6, 7, 8];
export const DEFAULT_GRAPH_BASE_HEIGHT_PX = 60;
export const DEFAULT_GRAPH_HEIGHT_STEP_PX = 20;
export const GRAPH_BASE_HEIGHT_RANGE = { min: 40, max: 400 };
export const GRAPH_HEIGHT_STEP_RANGE = { min: 0, max: 100 };
export const SAVE_INTERVAL = 3000;

export const LIST_SCAN_AUTO_CLICK_DELAY = 3000;

export const TS_HISTORY_LIMIT = 50;
export const TS_HISTORY_COALESCE_MS = 1500;

export const AUTO_DETECT_SKIP_NEAR_MARKER_SEC = 60;

export const SONG_DETECT_CONFIG = {
  REF_PERCENTILE: 0.99,
  LOCAL_REF_WINDOW_SEC: 300,
  LOCAL_REF_PERCENTILE: 0.90,
  ACTIVE_LEVEL_RATIO: 0.45,
  ABS_ACTIVE_FLOOR_RATIO: 0.35,
  ACTIVITY_WINDOW_SEC: 30,
  ENTER_ACTIVITY: 0.4,
  EXIT_ACTIVITY: 0.2,
  EXIT_TOLERANCE_SEC: 20,
  MIN_SEGMENT_SEC: 60,
  START_ADJUST_MAX_SEC: 20,
};

export const CHAT_SIGNAL_CONFIG = {
  CHAT_DELAY_SEC: 5,
  CLUSTER_GAP_SEC: 20,
  MIN_CLAPS_PER_BURST: 3,
  MIN_BURSTS_TO_TRUST: 3,
  MERGE_MAX_GAP_SEC: 60,
  SPLIT_MIN_HEAD_SEC: 45,
  SPLIT_MIN_TAIL_SEC: 30,
  SPLIT_START_OFFSET_SEC: 5,
  DEDUPE_SEC: 30,
};

export const CLAP_PATTERN = /8{3,}|８{3,}|👏|拍手|ぱちぱち|パチパチ|clap/i;

export const CHAT_DB_NAME = 'YCSChatDB';
export const CHAT_DB_VERSION = 1;
export const CHAT_STORE_NAME = 'chats';
export const CHAT_MAX_AGE_DAYS = 30;

export const HIGHLIGHT_DB_NAME = 'YCSHighlightDB';
export const HIGHLIGHT_DB_VERSION = 1;
export const HIGHLIGHT_STORE_NAME = 'highlights';
export const HIGHLIGHT_MAX_AGE_DAYS = 90;

export const DEFAULT_YCS_SERVER_URL = 'https://ycs.alpacasandbag.jp';
