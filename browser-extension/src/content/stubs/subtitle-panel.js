export function toggleSubtitlePanel() {}
export function isSubtitlePanelVisible() { return false; }
export function hideSubtitlePanel() {}
export async function getCaptionTracksFromPage() { return []; }
export function fetchTimedText() { return Promise.reject(new Error('subtitle-panel is not available in general edition')); }
export function ensurePageBridge() { return Promise.resolve(); }
