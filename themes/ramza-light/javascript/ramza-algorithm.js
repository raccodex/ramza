(function (window, document, $) {
    'use strict';

    var config = window.RamzaAlgorithmTracking || {};
    if (!config.enabled || !$ || !window.IntersectionObserver) {
        return;
    }

    var queue = [];
    var states = {};
    var observed = new WeakSet();
    var flushTimer = null;
    var failedFlushes = 0;
    var videoStates = new WeakMap();

    function postId(element) {
        var post = element && element.closest ? element.closest('.post[data-post-id]') : null;
        var id = post ? parseInt(post.getAttribute('data-post-id'), 10) : 0;
        return id > 0 ? id : 0;
    }

    function eventId(type, id) {
        return type + '-' + id + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    function deviceType() {
        var width = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
        return width < 768 ? 'mobile' : (width < 1100 ? 'tablet' : 'desktop');
    }

    function eventMeta(element) {
        var post = element && element.closest ? element.closest('.post[data-post-id]') : null;
        var position = 0;
        if (post) {
            position = Array.prototype.indexOf.call(document.querySelectorAll('.post[data-post-id]'), post) + 1;
        }
        return {
            position: Math.max(0, position),
            source: post ? (post.getAttribute('data-algorithm-source') || '') : '',
            reason: post ? (post.getAttribute('data-algorithm-reason') || '') : '',
            device: deviceType()
        };
    }

    function add(type, id, duration, metadata) {
        if (!id || queue.length >= 100) {
            return;
        }
        metadata = metadata || {};
        var payload = {
            type: type,
            post_id: id,
            duration_ms: Math.max(0, Math.min(1800000, Math.round(duration || 0))),
            surface: config.surface || 'home',
            event_id: eventId(type, id)
        };
        Object.keys(metadata).forEach(function (key) {
            payload[key] = metadata[key];
        });
        queue.push(payload);
        scheduleFlush();
    }

    function scheduleFlush() {
        if (flushTimer) {
            return;
        }
        flushTimer = window.setTimeout(function () { flush(false); }, 3500);
    }

    function flush(useBeacon) {
        window.clearTimeout(flushTimer);
        flushTimer = null;
        if (!queue.length) {
            return;
        }
        var batch = queue.splice(0, Math.max(1, Math.min(40, config.batchLimit || 40)));
        if (useBeacon && navigator.sendBeacon && window.FormData) {
            var form = new FormData();
            form.append('hash_id', config.hash);
            form.append('session_key', config.sessionKey);
            form.append('events', JSON.stringify(batch));
            if (navigator.sendBeacon(config.endpoint, form)) {
                return;
            }
        }
        $.ajax({
            url: config.endpoint,
            type: 'POST',
            dataType: 'json',
            data: {
                hash_id: config.hash,
                session_key: config.sessionKey,
                events: JSON.stringify(batch)
            }
        }).done(function () {
            failedFlushes = 0;
        }).fail(function () {
            failedFlushes++;
            if (failedFlushes <= 2) {
                queue = batch.concat(queue).slice(0, 100);
            }
        }).always(function () {
            if (queue.length) {
                scheduleFlush();
            }
        });
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            var id = postId(entry.target);
            if (!id) {
                return;
            }
            var state = states[id] || (states[id] = {visibleAt: 0, impression: false, timer: null});
            if (entry.isIntersecting && entry.intersectionRatio >= 0.58) {
                if (!state.visibleAt) {
                    state.visibleAt = Date.now();
                }
                if (!state.impression && !state.timer) {
                    state.timer = window.setTimeout(function () {
                        if (state.visibleAt && !state.impression) {
                            state.impression = true;
                            add('impression', id, Date.now() - state.visibleAt, eventMeta(entry.target));
                        }
                        state.timer = null;
                    }, 850);
                }
            } else if (state.visibleAt) {
                var duration = Date.now() - state.visibleAt;
                state.visibleAt = 0;
                if (state.timer) {
                    window.clearTimeout(state.timer);
                    state.timer = null;
                }
                if (duration >= 1800) {
                    add('dwell', id, duration, eventMeta(entry.target));
                } else if (duration >= 250 && config.trackQuickSkips) {
                    add('quick_skip', id, duration, eventMeta(entry.target));
                }
            }
        });
    }, {threshold: [0, 0.58, 0.85]});

    function observePosts(root) {
        var posts = (root || document).querySelectorAll ? (root || document).querySelectorAll('.post[data-post-id]') : [];
        Array.prototype.forEach.call(posts, function (post) {
            if (!observed.has(post)) {
                observed.add(post);
                observer.observe(post);
            }
        });
    }

    observePosts(document);
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
                if (node.nodeType === 1) {
                    if (node.matches && node.matches('.post[data-post-id]')) {
                        observePosts(node.parentNode || node);
                    } else {
                        observePosts(node);
                    }
                }
            });
        });
    }).observe(document.body, {childList: true, subtree: true});

    document.addEventListener('click', function (event) {
        var id = postId(event.target);
        if (!id) {
            return;
        }
        var action = event.target.closest('a,button,[role="button"]');
        if (!action) {
            add('click', id, 0, eventMeta(event.target));
            return;
        }
        var signature = ((action.className || '') + ' ' + (action.getAttribute('onclick') || '') + ' ' + (action.id || '') + ' ' + (action.getAttribute('href') || '') + ' ' + (action.textContent || '')).toLowerCase();
        var meta = eventMeta(event.target);
        if (signature.indexOf('not interested') !== -1 || signature.indexOf('not-interested') !== -1) {
            add('not_interested', id, 0, meta);
        } else if (signature.indexOf('savepost') !== -1 || signature.indexOf('save-post') !== -1) {
            add('save', id, 0, meta);
        } else if (signature.indexOf('hidepost') !== -1 || signature.indexOf('hide-post') !== -1) {
            add('hide', id, 0, meta);
        } else if (signature.indexOf('report') !== -1) {
            add('report', id, 0, meta);
        } else if (signature.indexOf('block-user') !== -1 || signature.indexOf('blockuser') !== -1) {
            add('block', id, 0, meta);
        } else if (signature.indexOf('mute-user') !== -1 || signature.indexOf('muteuser') !== -1) {
            add('mute', id, 0, meta);
        } else if (signature.indexOf('unfollow') !== -1) {
            add('unfollow', id, 0, meta);
        } else if (signature.indexOf('follow') !== -1) {
            add('follow_after_view', id, 0, meta);
        } else if (signature.indexOf('repost') !== -1) {
            add('repost', id, 0, meta);
        } else if (signature.indexOf('send') !== -1 && signature.indexOf('comment') === -1) {
            add('send_post', id, 0, meta);
        } else if (signature.indexOf('share') !== -1) {
            add('share', id, 0, meta);
        } else if (signature.indexOf('reply') !== -1) {
            add('reply', id, 0, meta);
        } else if (signature.indexOf('comment') !== -1) {
            add('open_comments', id, 0, meta);
        } else if (signature.indexOf('read-more') !== -1 || signature.indexOf('view-more') !== -1 || signature.indexOf('show-more') !== -1) {
            add('expand_text', id, 0, meta);
        } else if (signature.indexOf('hashtag') !== -1 || signature.indexOf('/hashtag/') !== -1) {
            add('hashtag_click', id, 0, meta);
        } else if (signature.indexOf('/post/') !== -1 || signature.indexOf('open-post') !== -1) {
            add('open_post', id, 0, meta);
        } else if (signature.indexOf('timeline') !== -1 || signature.indexOf('user-avatar') !== -1 || signature.indexOf('publisher-name') !== -1) {
            add('profile_visit', id, 0, meta);
        } else if (signature.indexOf('like') !== -1 || signature.indexOf('reaction') !== -1) {
            add('reaction', id, 0, meta);
        } else {
            add('click', id, 0, meta);
        }
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var id = postId(form);
        if (!id) {
            return;
        }
        var signature = ((form.className || '') + ' ' + (form.id || '') + ' ' + (form.getAttribute('action') || '')).toLowerCase();
        if (signature.indexOf('reply') !== -1) {
            add('reply', id, 0, eventMeta(form));
        } else if (signature.indexOf('comment') !== -1) {
            add('comment', id, 0, eventMeta(form));
        }
    }, true);

    function videoState(video) {
        var state = videoStates.get(video);
        if (!state) {
            state = {thresholds: {}, completed: false, sound: false, plays: 0};
            videoStates.set(video, state);
        }
        return state;
    }

    document.addEventListener('play', function (event) {
        if (!event.target || event.target.tagName !== 'VIDEO') {
            return;
        }
        var state = videoState(event.target);
        state.plays++;
        if ((state.completed || state.plays > 1) && event.target.currentTime < 2) {
            add('rewatch', postId(event.target), 0, eventMeta(event.target));
            state.completed = false;
        }
    }, true);

    document.addEventListener('timeupdate', function (event) {
        var video = event.target;
        if (!video || video.tagName !== 'VIDEO' || !isFinite(video.duration) || video.duration <= 0) {
            return;
        }
        var state = videoState(video);
        var percentage = Math.max(0, Math.min(100, (video.currentTime / video.duration) * 100));
        [25, 50, 75].forEach(function (threshold) {
            if (percentage >= threshold && !state.thresholds[threshold]) {
                state.thresholds[threshold] = true;
                var meta = eventMeta(video);
                meta.watch_percentage = percentage;
                add('watch_' + threshold, postId(video), Math.round(video.currentTime * 1000), meta);
            }
        });
    }, true);

    document.addEventListener('volumechange', function (event) {
        var video = event.target;
        if (!video || video.tagName !== 'VIDEO') {
            return;
        }
        var state = videoState(video);
        if (!video.muted && video.volume > 0 && !state.sound) {
            state.sound = true;
            add('sound_on', postId(video), Math.round(video.currentTime * 1000), eventMeta(video));
        }
    }, true);

    document.addEventListener('pause', function (event) {
        var video = event.target;
        if (!video || video.tagName !== 'VIDEO' || video.ended) {
            return;
        }
        if (video.currentTime >= 1 && video.currentTime <= 5) {
            var meta = eventMeta(video);
            meta.watch_percentage = video.duration > 0 ? Math.min(100, (video.currentTime / video.duration) * 100) : 0;
            add('short_watch', postId(video), Math.round(video.currentTime * 1000), meta);
        }
    }, true);

    document.addEventListener('ended', function (event) {
        if (event.target && event.target.tagName === 'VIDEO') {
            var state = videoState(event.target);
            state.completed = true;
            var meta = eventMeta(event.target);
            meta.watch_percentage = 100;
            add('watch_complete', postId(event.target), Math.round((event.target.duration || 0) * 1000), meta);
        }
    }, true);

    window.setInterval(function () { flush(false); }, 10000);
    window.addEventListener('pagehide', function () { flush(true); });
}(window, document, window.jQuery));
