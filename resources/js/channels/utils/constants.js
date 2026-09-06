/**
 * チャンネルページの定数定義
 */

// 報告タイプ定数
export const REPORT_TYPES = {
    WRONG_SONG: 'wrong_song',
    NOT_SONG: 'not_song',
    NOT_TIMESTAMP: 'not_timestamp',
    PROBLEM: 'problem',
    OTHER: 'other'
};

// モバイル判定用正規表現
export const MOBILE_REGEX = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i;

// YouTubeプレイヤー設定・PiPサイズは共有モジュールからre-export
export { YOUTUBE_PLAYER_CONFIG, PIP_SIZES } from '../../shared/utils/youtube-constants.js';
