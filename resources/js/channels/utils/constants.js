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

// YouTubeプレイヤー設定
export const YOUTUBE_PLAYER_CONFIG = {
    height: '180',
    width: '320',
    playerVars: {
        autoplay: 0,
        controls: 1,
        rel: 0,
        modestbranding: 1
    },
    // 推奨画質（small=240p, tiny=144p相当）
    // YouTubeのポリシーにより強制は不可だが、推奨として設定
    suggestedQuality: 'small'
};

// ワイプ（PiP）サイズ設定
export const PIP_SIZES = {
    small: {
        width: 240,
        height: 135,
        minimizedWidth: 160,
        label: '小'
    },
    medium: {
        width: 320,
        height: 180,
        minimizedWidth: 200,
        label: '中'
    },
    large: {
        width: 480,
        height: 270,
        minimizedWidth: 240,
        label: '大'
    }
};
