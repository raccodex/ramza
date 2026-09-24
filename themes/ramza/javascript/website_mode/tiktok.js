/**
 * RACSocial — TikTok Mode v3.0
 * Frontend runtime: vertical snap feed, overlay actions, music ticker
 */
(function () {
  'use strict';
  var $ = window.jQuery;

  document.documentElement.setAttribute('data-mode', 'tiktok');

  $(function () {
    // ── Scroll-snap feed container ────────────────────────────────────
    var $timeline = $('.timeline-container, .middlecol, #main-content');
    if ($timeline.length) {
      $timeline.addClass('tiktok-feed-container');
    }

    // ── Transform posts into vertical cards ────────────────────────────
    $('.post').each(function (index) {
      var $post = $(this);
      $post.addClass('tiktok-video-card');

      // Move action buttons to overlay
      var $footer = $post.find('.post-footer');
      if ($footer.length) {
        var $overlayActions = $('<div class="tiktok-overlay-actions"></div>');
        // Clone like button
        var $likeBtn = $footer.find('.wo-reaction-likes .reaction-btn, .social-btn-like').first().clone();
        if ($likeBtn.length) {
          $likeBtn.addClass('tiktok-action-btn tiktok-action-like');
          $overlayActions.append($likeBtn);
        }
        // Clone comment button
        var $commentBtn = $footer.find('.social-btn-comment, a[href*="comment"]').first().clone();
        if ($commentBtn.length) {
          $commentBtn.addClass('tiktok-action-btn tiktok-action-comment');
          $overlayActions.append($commentBtn);
        }
        // Clone share button
        var $shareBtn = $footer.find('.social-btn-share, a[href*="share"]').first().clone();
        if ($shareBtn.length) {
          $shareBtn.addClass('tiktok-action-btn tiktok-action-share');
          $overlayActions.append($shareBtn);
        }

        // Music disc indicator
        var $musicDisc = $('<div class="tiktok-music-disc"><div class="tiktok-disc-inner"></div></div>');
        $overlayActions.append($musicDisc);
        $post.append($overlayActions);
      }

      // Wrap post media for full-bleed
      $post.find('.post-media img, .post-media video, .post-file img, .post-file video').each(function () {
        $(this).addClass('tiktok-media-fullbleed');
      });
    });

    // ── Like animation ────────────────────────────────────────────────
    $(document).on('click', '.tiktok-action-like', function () {
      $(this).addClass('tiktok-like-animate');
      var self = this;
      setTimeout(function () {
        $(self).removeClass('tiktok-like-animate');
      }, 600);
    });

    // ── Hide non-TikTok sidebar items ─────────────────────────────────
    $('.sidebar-list li').each(function () {
      var text = $(this).text().toLowerCase();
      if (text.indexOf('poke') > -1 || text.indexOf('game') > -1 || text.indexOf('movie') > -1 ||
          text.indexOf('blog') > -1 || text.indexOf('forum') > -1 || text.indexOf('event') > -1 ||
          text.indexOf('fund') > -1) {
        $(this).hide();
      }
    });

    // ── Music ticker animation on posts ────────────────────────────────
    var style = document.createElement('style');
    style.textContent =
      '@keyframes tiktok-disc-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }' +
      '@keyframes tiktok-like-burst { 0% { transform: scale(1); } 30% { transform: scale(1.35); } 60% { transform: scale(0.95); } 100% { transform: scale(1); } }' +
      '.tiktok-music-disc { animation: tiktok-disc-spin 3s linear infinite; }' +
      '.tiktok-like-animate { animation: tiktok-like-burst 0.5s ease-out; }' +
      '.tiktok-media-fullbleed { width: 100% !important; border-radius: 0 !important; }' +
      '.tiktok-overlay-actions { position: absolute; right: 12px; bottom: 80px; display: flex; flex-direction: column; align-items: center; gap: 18px; z-index: 10; }' +
      '.tiktok-action-btn { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; border-radius: 50%; color: #fff; background: rgba(0,0,0,0.35); backdrop-filter: blur(4px); border: none; cursor: pointer; transition: transform 150ms; }' +
      '.tiktok-action-btn:hover { transform: scale(1.1); }' +
      '.tiktok-action-btn svg { width: 26px; height: 26px; fill: #fff; }' +
      '.tiktok-music-disc { width: 36px; height: 36px; border-radius: 50%; background: #333; border: 2px solid #333; overflow: hidden; margin-top: 8px; }' +
      '.tiktok-disc-inner { width: 100%; height: 100%; border-radius: 50%; background: linear-gradient(135deg, #fe2c55, #25f4ee); }';
    document.head.appendChild(style);

    $('body').addClass('tiktok-mode-active');
  });
})();
