/**
 * RACSocial — X/Twitter Mode v2.0
 * Frontend runtime: data-attribute binding, character counter, compose tweaks
 */
(function () {
  'use strict';
  var $ = window.jQuery;

  document.documentElement.setAttribute('data-mode', 'twitter');

  $(function () {
    // Character counter (280 char tweet style)
    var MAX_CHARS = 280;
    $('.post-textarea textarea, #postTextarea').each(function () {
      var $ta = $(this);
      var $counter = $('<span class="twitter-char-counter">280</span>').insertAfter($ta);
      $counter.css({ 'float': 'right', 'font-size': '12px', 'color': '#71767b', 'margin-top': '4px' });
      $ta.attr('maxlength', MAX_CHARS);
      $ta.on('input', function () {
        var remaining = MAX_CHARS - $ta.val().length;
        $counter.text(remaining);
        $counter.css('color', remaining < 20 ? '#f4212e' : '#71767b');
      });
    });

    // Strip text from reaction buttons, leave only icons
    $('.post-footer .wo-reaction-likes .reaction-btn, .post-footer .social-btn').each(function () {
      var $btn = $(this);
      var html = $btn.html();
      var svgMatch = html.match(/<svg[\s\S]*?<\/svg>/i);
      if (svgMatch) {
        $btn.html(svgMatch[0]).addClass('twitter-icon-action');
      }
    });

    // Mark post footers
    $('.post-footer').addClass('twitter-post-footer');

    // Hide non-Twitter sidebar items (pokes, games, movies)
    $('.sidebar-list li').each(function () {
      var text = $(this).text().toLowerCase();
      if (text.indexOf('poke') > -1 || text.indexOf('game') > -1 || text.indexOf('movie') > -1) {
        $(this).hide();
      }
    });

    // Dynamic link styling
    var style = document.createElement('style');
    style.textContent =
      '.twitter-post-footer .post-text a { color: #1d9bf0; text-decoration: none; }' +
      '.twitter-post-footer .post-text a:hover { text-decoration: underline; }' +
      '.twitter-icon-action { background: none !important; border: none !important; padding: 8px !important; border-radius: 50% !important; }' +
      '.twitter-icon-action:hover { background: rgba(29, 155, 240, 0.1) !important; }';
    document.head.appendChild(style);

    $('body').addClass('twitter-dark-mode');

    // Sticky nav
    var $nav = $('#navbar, .navbar');
    if ($nav.length) {
      $nav.css({ 'position': 'sticky', 'top': '0', 'z-index': '1000' });
    }
  });
})();
