/**
 * RACSocial — Ask.fm Mode v2.5
 * Frontend runtime: Q&A transformations, anonymous ask button, answer boxes
 */
(function () {
  'use strict';
  var $ = window.jQuery;

  document.documentElement.setAttribute('data-mode', 'askfm');

  $(function () {
    // Convert post text to "question" format
    $('.post .post-text').each(function () {
      $(this).addClass('askfm-question-text');
    });

    // Add "Ask me anything" button on profile pages
    var $profile = $('.wo_user_profile');
    if ($profile.length) {
      var $askBtn = $('<button class="ask-anon-btn"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="#fff" d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg> Ask me anything</button>');
      $askBtn.css({
        'display': 'inline-flex',
        'align-items': 'center',
        'gap': '8px',
        'background': 'linear-gradient(135deg, #e84393, #6c5ce7)',
        'color': '#fff',
        'border': 'none',
        'padding': '12px 28px',
        'border-radius': '999px',
        'font-size': '15px',
        'font-weight': '600',
        'cursor': 'pointer',
        'margin': '16px 0',
        'transition': 'transform 150ms, box-shadow 150ms',
        'box-shadow': '0 4px 15px rgba(232, 67, 147, 0.3)'
      });
      $askBtn.on('mouseenter', function () {
        $(this).css({ 'transform': 'translateY(-2px)', 'box-shadow': '0 6px 20px rgba(232, 67, 147, 0.4)' });
      }).on('mouseleave', function () {
        $(this).css({ 'transform': 'translateY(0)', 'box-shadow': '0 4px 15px rgba(232, 67, 147, 0.3)' });
      });
      $askBtn.on('click', function () {
        var $textarea = $('.post-textarea textarea').first();
        if ($textarea.length) {
          $('html, body').animate({ scrollTop: $textarea.offset().top - 100 }, 400);
          $textarea.focus().attr('placeholder', 'Ask me anything...').css({
            'border': '2px solid #e84393',
            'border-radius': '24px',
            'padding': '16px 20px',
            'transition': 'border-color 200ms'
          });
        }
      });
      $('.wo_user_profile .user-cover-buttons, .wo_user_profile .profile-buttons').first().prepend($askBtn);
    }

    // Transform empty comment sections
    $('.post-footer .comments-list').each(function () {
      var $cl = $(this);
      if ($cl.children().length === 0) {
        $cl.html('<div class="askfm-no-answer"><p style="color:#71767b;font-style:italic;margin:8px 0;">No answer yet — be the first to respond.</p></div>');
      }
    });

    // Hide non-AskFM sidebar items
    $('.sidebar-list li').each(function () {
      var text = $(this).text().toLowerCase();
      if (text.indexOf('poke') > -1 || text.indexOf('game') > -1 || text.indexOf('movie') > -1 || text.indexOf('fund') > -1) {
        $(this).hide();
      }
    });

    // Style the post compose area
    $('.post-textarea textarea').attr('placeholder', 'Ask me anything...');
    $('body').addClass('askfm-mode-active');
  });
})();
