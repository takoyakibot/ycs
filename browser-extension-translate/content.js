chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message.type === 'GET_VIDEO_TIME') {
    const video = document.querySelector('video');
    sendResponse({ currentTime: video ? video.currentTime : null });
  }
});
