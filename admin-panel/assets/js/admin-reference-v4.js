/**
 * RACSocial Admin Reference V4 Javascript
 * Exactly matches reference Micco V4.0 behavior & architecture:
 * - Unified vertical sidebar with mint-glow hover & active animations
 * - Real-time sidebar menu search filter (#search-sidebar-menu)
 * - Interactive global search modal (#staticBackdrop) with Ctrl+K shortcut
 * - Instant zero-latency client search + deep settings keyword matching
 * - Keyboard navigation (Arrow keys + Enter + Esc)
 * - Recent search history persistence in localStorage
 * - Seamless AJAX navigation state synchronization
 */
(function () {
    'use strict';

    var body = document.body;
    var navigation = document.querySelector('.navigation');
    var menuBody = navigation && navigation.querySelector('.navigation-menu-body');
    var menu = menuBody && menuBody.querySelector(':scope > ul');

    if (!body || !navigation || !menuBody || !menu || !body.classList.contains('rac-reference-v4')) {
        return;
    }

    // -------------------------------------------------------------
    // Comprehensive RACSocial Admin Panel Route Directory
    // -------------------------------------------------------------
    var ADMIN_DIRECTORY = [
        { name: "Dashboard", category: "Overview", path: "dashboard", uri: "admin-cp/dashboard", icon: "dashboard", keywords: "home analytics stats summary overview" },
        { name: "Website Mode", category: "Settings", path: "website_mode", uri: "admin-cp/website_mode", icon: "public", keywords: "network mode social ask linkedin instagram network format" },
        { name: "General Configuration", category: "Settings", path: "general-settings", uri: "admin-cp/general-settings", icon: "settings", keywords: "site title email seo language timezone currency maintenance setup" },
        { name: "Website Information", category: "Settings", path: "site-settings", uri: "admin-cp/site-settings", icon: "info", keywords: "description contact keywords footer header branding" },
        { name: "File Upload Configuration", category: "Settings", path: "amazon-settings", uri: "admin-cp/amazon-settings", icon: "cloud_upload", keywords: "amazon s3 digitalocean wasabi storage backblaze upload limit media size" },
        { name: "E-mail & SMS Setup", category: "Settings", path: "email-settings", uri: "admin-cp/email-settings", icon: "email", keywords: "smtp mailgun sendgrid twilio messagebird sms server mail config" },
        { name: "Chat & Video/Audio", category: "Settings", path: "video-settings", uri: "admin-cp/video-settings", icon: "videocam", keywords: "agora twilio calling video audio chat message live stream call" },
        { name: "Social Login Settings", category: "Settings", path: "social-login", uri: "admin-cp/social-login", icon: "login", keywords: "facebook google twitter linkedin vkontakte oauth app key secret" },
        { name: "NodeJS Settings", category: "Settings", path: "node", uri: "admin-cp/node", icon: "memory", keywords: "nodejs socket.io port real-time websockets server" },
        { name: "CronJob Settings", category: "Settings", path: "cronjob_settings", uri: "admin-cp/cronjob_settings", icon: "schedule", keywords: "cron scheduled tasks background automated jobs" },
        { name: "AI Settings", category: "Settings", path: "ai-settings", uri: "admin-cp/ai-settings", icon: "auto_awesome", keywords: "openai chatgpt dalle artificial intelligence api key image text" },
        { name: "Posts Settings", category: "Settings", path: "post-settings", uri: "admin-cp/post-settings", icon: "article", keywords: "posts comments feed colored reactions feeling stream length" },
        { name: "Manage Colored Posts", category: "Settings", path: "manage-colored-posts", uri: "admin-cp/manage-colored-posts", icon: "palette", keywords: "colored backgrounds gradients posts" },
        { name: "Post Reactions", category: "Settings", path: "manage-reactions", uri: "admin-cp/manage-reactions", icon: "thumb_up", keywords: "like love haha wow sad angry emoji reactions" },
        { name: "Setup Live Streaming", category: "Settings", path: "live", uri: "admin-cp/live", icon: "live_tv", keywords: "live streaming broadcast agora stream rtmp" },
        { name: "Manage Features", category: "Features", path: "site-features", uri: "admin-cp/site-features", icon: "featured_play_list", keywords: "enable disable features modules blog market event forum games" },
        { name: "Manage Users", category: "Users", path: "manage-users", uri: "admin-cp/manage-users", icon: "people", keywords: "members accounts edit user ban active verify delete profiles" },
        { name: "Online Users", category: "Users", path: "online-users", uri: "admin-cp/online-users", icon: "person_pin", keywords: "active sessions who is online users count real-time" },
        { name: "Manage Stories", category: "Users", path: "manage-stories", uri: "admin-cp/manage-stories", icon: "history_toggle_off", keywords: "stories status 24h temporary media moments" },
        { name: "Manage Verification Requests", category: "Users", path: "manage-verification-reqeusts", uri: "admin-cp/manage-verification-reqeusts", icon: "verified", keywords: "blue badge verified identity passport id approval verify" },
        { name: "Affiliates Settings", category: "Users", path: "affiliates-settings", uri: "admin-cp/affiliates-settings", icon: "group_add", keywords: "referral percentage commission invite earn affiliate program" },
        { name: "Payment Requests", category: "Users", path: "payment-reqeuests", uri: "admin-cp/payment-reqeuests", icon: "payments", keywords: "withdrawals cashout payout bank paypal affiliate payout" },
        { name: "Manage Genders", category: "Users", path: "manage-genders", uri: "admin-cp/manage-genders", icon: "wc", keywords: "gender male female custom gender list options" },
        { name: "Payment & Currency Settings", category: "Payments & Ads", path: "payment-settings", uri: "admin-cp/payment-settings", icon: "credit_card", keywords: "stripe paypal 2checkout bank razorpay paystack wallet checkout" },
        { name: "Ads Settings", category: "Payments & Ads", path: "ads-settings", uri: "admin-cp/ads-settings", icon: "monetization_on", keywords: "advertisement cost per click impressions cpc cpm budget" },
        { name: "Manage Currencies", category: "Payments & Ads", path: "manage-currencies", uri: "admin-cp/manage-currencies", icon: "attach_money", keywords: "usd eur gbp exchange rates default currency symbol" },
        { name: "Manage Site Ads", category: "Payments & Ads", path: "manage-site-ads", uri: "admin-cp/manage-site-ads", icon: "campaign", keywords: "banner ads google adsense header sidebar footer ad codes placements" },
        { name: "Manage User Ads", category: "Payments & Ads", path: "manage-user-ads", uri: "admin-cp/manage-user-ads", icon: "loyalty", keywords: "promoted posts user campaigns approve pause advertisements" },
        { name: "Manage Bank Receipts", category: "Payments & Ads", path: "bank-receipts", uri: "admin-cp/bank-receipts", icon: "receipt_long", keywords: "offline payments transfer proof screenshot receipt verify approve" },
        { name: "Pro System Settings", category: "Pro System", path: "pro-settings", uri: "admin-cp/pro-settings", icon: "star", keywords: "vip membership upgrade star pro packages prices features" },
        { name: "Manage Pro Payments", category: "Pro System", path: "pro-payments", uri: "admin-cp/pro-payments", icon: "receipt", keywords: "pro subscriptions invoices transaction history subscribers" },
        { name: "Manage Pro Members", category: "Pro System", path: "pro-memebers", uri: "admin-cp/pro-memebers", icon: "stars", keywords: "active pro members expiration packages duration" },
        { name: "Manage Refund Requests", category: "Pro System", path: "pro-refund", uri: "admin-cp/pro-refund", icon: "assignment_return", keywords: "refunds cancel membership money back returns" },
        { name: "Themes", category: "Design", path: "manage-themes", uri: "admin-cp/manage-themes", icon: "palette", keywords: "templates sunshine default layout skin switch appearance" },
        { name: "Change Site Design", category: "Design", path: "manage-site-design", uri: "admin-cp/manage-site-design", icon: "brush", keywords: "colors primary background header styling css design" },
        { name: "Custom JS / CSS", category: "Design", path: "custom-code", uri: "admin-cp/custom-code", icon: "code", keywords: "javascript css head code footer analytics tracking code styles" },
        { name: "Manage Emails", category: "Tools", path: "manage_emails", uri: "admin-cp/manage_emails", icon: "mail_outline", keywords: "email templates welcome activation reset password notification" },
        { name: "Users Invitation", category: "Tools", path: "manage-invitation", uri: "admin-cp/manage-invitation", icon: "person_add", keywords: "invite links invite only registration send invites" },
        { name: "Send E-mail", category: "Tools", path: "send_email", uri: "admin-cp/send_email", icon: "send", keywords: "newsletter mass email broadcast message newsletter" },
        { name: "Announcements", category: "Tools", path: "manage-announcements", uri: "admin-cp/manage-announcements", icon: "campaign", keywords: "alert top banner notification broadcast notice" },
        { name: "Auto Delete Data", category: "Tools", path: "auto-delete", uri: "admin-cp/auto-delete", icon: "delete_sweep", keywords: "clean prune purge inactive old posts database trash" },
        { name: "Auto Friend", category: "Tools", path: "auto-friend", uri: "admin-cp/auto-friend", icon: "person_add_alt", keywords: "automatic follow default friends follow on signup bot" },
        { name: "Auto Page Like", category: "Tools", path: "auto-like", uri: "admin-cp/auto-like", icon: "thumb_up", keywords: "auto like page follow default page fan" },
        { name: "Auto Group Join", category: "Tools", path: "auto-join", uri: "admin-cp/auto-join", icon: "group_work", keywords: "default groups auto join group on register community" },
        { name: "Fake User Generator", category: "Tools", path: "fake-users", uri: "admin-cp/fake-users", icon: "smart_toy", keywords: "generate bot dummy mock users test profiles fake generator" },
        { name: "Mass Notifications", category: "Tools", path: "mass-notifications", uri: "admin-cp/mass-notifications", icon: "notifications_active", keywords: "push notification to all users alert message system alert" },
        { name: "BlackList", category: "Tools", path: "ban-users", uri: "admin-cp/ban-users", icon: "block", keywords: "ban ip ban email reserved usernames domain blacklist block list" },
        { name: "Generate SiteMap", category: "Tools", path: "generate-sitemap", uri: "admin-cp/generate-sitemap", icon: "map", keywords: "sitemap.xml seo google bot crawler search index xml" },
        { name: "Invitation Codes", category: "Tools", path: "manage-invitation-keys", uri: "admin-cp/manage-invitation-keys", icon: "vpn_key", keywords: "invite keys generate codes vouchers registration code" },
        { name: "Backup SQL & Files", category: "Tools", path: "backups", uri: "admin-cp/backups", icon: "backup", keywords: "database backup sql dump export download database storage" },
        { name: "Manage Custom Pages", category: "Pages", path: "manage-custom-pages", uri: "admin-cp/manage-custom-pages", icon: "description", keywords: "custom pages about privacy contact terms content cms" },
        { name: "Manage Terms Pages", category: "Pages", path: "manage_terms_pages", uri: "admin-cp/manage_terms_pages", icon: "gavel", keywords: "terms of service privacy policy about us dmca legal" },
        { name: "Manage Reports", category: "Reports", path: "manage-reports", uri: "admin-cp/manage-reports", icon: "warning", keywords: "flagged content post reports comment reports reviews abuse" },
        { name: "Manage Users Reports", category: "Reports", path: "user_reports", uri: "admin-cp/user_reports", icon: "report_problem", keywords: "user reports spam profile violation abuse report complaints" },
        { name: "Manage API Server Key", category: "API Settings", path: "manage-api-access-keys", uri: "admin-cp/manage-api-access-keys", icon: "vpn_key", keywords: "server key rest api mobile app client secret token auth" },
        { name: "Push Notifications Settings", category: "API Settings", path: "push-notifications-system", uri: "admin-cp/push-notifications-system", icon: "notifications", keywords: "firebase onesignal fcm push credentials notifications" },
        { name: "Verify Applications", category: "API Settings", path: "verfiy-applications", uri: "admin-cp/verfiy-applications", icon: "verified_user", keywords: "developer apps permissions api clients oauth apps" },
        { name: "3rd Party Scripts", category: "API Settings", path: "manage-third-psites", uri: "admin-cp/manage-third-psites", icon: "integration_instructions", keywords: "external scripts embed integrations tracking scripts" },
        { name: "Updates & Bug Fixes", category: "System", path: "manage-updates", uri: "admin-cp/manage-updates", icon: "system_update", keywords: "check update version upgrade patch download install release" },
        { name: "System Status", category: "System", path: "system_status", uri: "admin-cp/system_status", icon: "info", keywords: "server requirements php version extensions memory limit curl config" },
        { name: "Changelogs", category: "System", path: "changelog", uri: "admin-cp/changelog", icon: "update", keywords: "release history what is new changes log version release notes" }
    ];

    // Quick Access items shown when search input is empty
    var QUICK_ACCESS = [
        { name: "Dashboard", category: "Overview", path: "dashboard", icon: "dashboard" },
        { name: "General Configuration", category: "Settings", path: "general-settings", icon: "settings" },
        { name: "Website Mode", category: "Settings", path: "website_mode", icon: "public" },
        { name: "Manage Users", category: "Users", path: "manage-users", icon: "people" },
        { name: "Posts Settings", category: "Settings", path: "post-settings", icon: "article" },
        { name: "Backup SQL & Files", category: "Tools", path: "backups", icon: "backup" },
        { name: "BlackList", category: "Tools", path: "ban-users", icon: "block" },
        { name: "Updates & Bug Fixes", category: "System", path: "manage-updates", icon: "system_update" }
    ];

    // Ensure all menu items are visible initially
    Array.prototype.forEach.call(menu.querySelectorAll('li'), function (li) {
        li.classList.remove('rac-v4-workspace-visible');
        li.style.removeProperty('display');
    });

    // -------------------------------------------------------------
    // 1. Live Sidebar Menu Search Filter (Matches Reference V4 _sidebar.blade.php)
    // -------------------------------------------------------------
    var sidebarSearchInput = document.getElementById('search-sidebar-menu');
    if (sidebarSearchInput) {
        sidebarSearchInput.addEventListener('input', function () {
            var val = (this.value || '').trim().replace(/\s+/g, ' ').toLowerCase();
            var topItems = menu.querySelectorAll(':scope > li');

            if (!val) {
                Array.prototype.forEach.call(topItems, function (li) {
                    li.style.removeProperty('display');
                    if (li.classList.contains('was-opened-by-search')) {
                        li.classList.remove('open', 'was-opened-by-search');
                        var sub = li.querySelector('ul.ml-menu, ul');
                        if (sub) sub.style.removeProperty('display');
                    }
                    var subItems = li.querySelectorAll('ul.ml-menu li, ul li');
                    Array.prototype.forEach.call(subItems, function (subLi) {
                        subLi.style.removeProperty('display');
                    });
                });
                return;
            }

            Array.prototype.forEach.call(topItems, function (li) {
                if (li.classList.contains('nav-section-title') || li.classList.contains('nav-item')) {
                    return;
                }

                var liText = (li.textContent || '').replace(/\s+/g, ' ').toLowerCase();
                var hasTopMatch = liText.indexOf(val) !== -1;

                var subItems = li.querySelectorAll('ul.ml-menu li, ul li');
                var hasSubMatch = false;

                if (subItems.length > 0) {
                    Array.prototype.forEach.call(subItems, function (subLi) {
                        var subText = (subLi.textContent || '').replace(/\s+/g, ' ').toLowerCase();
                        if (subText.indexOf(val) !== -1) {
                            subLi.style.display = 'block';
                            hasSubMatch = true;
                        } else {
                            subLi.style.display = 'none';
                        }
                    });
                }

                if (hasTopMatch || hasSubMatch) {
                    li.style.display = 'block';
                    var submenu = li.querySelector('ul.ml-menu, ul');
                    if (submenu && !li.classList.contains('open')) {
                        li.classList.add('open', 'was-opened-by-search');
                        submenu.style.display = 'block';
                    }
                } else {
                    li.style.display = 'none';
                }
            });

            // Toggle section headers if following items are visible
            Array.prototype.forEach.call(topItems, function (li) {
                if (li.classList.contains('nav-section-title')) {
                    var next = li.nextElementSibling;
                    var hasVisibleSibling = false;
                    while (next && !next.classList.contains('nav-section-title')) {
                        if (next.style.display !== 'none') {
                            hasVisibleSibling = true;
                            break;
                        }
                        next = next.nextElementSibling;
                    }
                    li.style.display = hasVisibleSibling ? 'block' : 'none';
                }
            });
        });

        // Clear sidebar search on Escape key
        sidebarSearchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                this.value = '';
                this.dispatchEvent(new Event('input'));
                this.blur();
            }
        });

        // Clicking search icon on sidebar search form triggers global search modal
        var sidebarSearchBtn = sidebarSearchInput.parentElement && sidebarSearchInput.parentElement.querySelector('button.btn');
        if (sidebarSearchBtn) {
            sidebarSearchBtn.style.cursor = 'pointer';
            sidebarSearchBtn.style.pointerEvents = 'auto';
            sidebarSearchBtn.title = 'Open Search (Ctrl+K)';
            sidebarSearchBtn.addEventListener('click', function (e) {
                e.preventDefault();
                openSearchModal();
            });
        }
    }

    // -------------------------------------------------------------
    // 2. Global Search Modal (#staticBackdrop) Logic (Reference V4 app.blade.php)
    // -------------------------------------------------------------
    var searchModal = document.getElementById('staticBackdrop');
    var searchInput = document.getElementById('searchInput');
    var searchResults = document.getElementById('searchResults');
    var activeResultIndex = -1;
    var deepSearchTimer = null;

    function getSiteBaseUrl() {
        var host = window.location.origin;
        var pathname = window.location.pathname;
        var idx = pathname.indexOf('/admin-cp');
        if (idx !== -1) {
            return host + pathname.substring(0, idx);
        }
        return host;
    }

    function buildAdminUrl(path) {
        var base = getSiteBaseUrl();
        if (!path || path === 'dashboard') {
            return base + '/admin-cp';
        }
        return base + '/admin-cp/' + path;
    }

    function openSearchModal() {
        if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery('#staticBackdrop').modal('show');
        } else if (searchModal) {
            searchModal.classList.add('show');
            searchModal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    }

    function closeSearchModal() {
        if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery('#staticBackdrop').modal('hide');
        } else if (searchModal) {
            searchModal.classList.remove('show');
            searchModal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }
    }

    // Keyboard shortcut: Ctrl + K (or Cmd + K) opens modal
    document.addEventListener('keydown', function (event) {
        var isK = (event.key === 'k' || event.key === 'K');
        if ((event.ctrlKey || event.metaKey) && isK) {
            event.preventDefault();
            var opener = document.getElementById('modalOpener');
            if (opener) {
                opener.click();
            } else {
                openSearchModal();
            }
        }
    });

    // Recent Searches in localStorage
    var RECENT_KEY = 'rac_admin_recent_searches_v4';

    function getRecentSearches() {
        try {
            var raw = localStorage.getItem(RECENT_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function saveRecentSearch(item) {
        try {
            var list = getRecentSearches();
            list = list.filter(function (x) { return x.path !== item.path; });
            list.unshift({
                name: item.name,
                category: item.category || 'Page',
                path: item.path,
                uri: item.uri || ('admin-cp/' + item.path),
                icon: item.icon || 'arrow_forward',
                time: Date.now()
            });
            if (list.length > 6) {
                list = list.slice(0, 6);
            }
            localStorage.setItem(RECENT_KEY, JSON.stringify(list));
        } catch (e) {}
    }

    function clearRecentSearches() {
        try {
            localStorage.removeItem(RECENT_KEY);
        } catch (e) {}
        renderDefaultSearchState();
    }

    // Escape regex characters for keyword highlighting
    function escapeRegExp(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function highlightKeyword(text, keyword) {
        if (!keyword || !text) return text;
        var safe = escapeRegExp(keyword.trim());
        var regex = new RegExp('(' + safe + ')', 'gi');
        return text.replace(regex, '<mark class="p-0">$1</mark>');
    }

    // Render Initial State (Recent Searches + Quick Access)
    function renderDefaultSearchState() {
        activeResultIndex = -1;
        var recents = getRecentSearches();
        var html = '';

        if (recents.length > 0) {
            html += '<div class="d-flex align-items-center justify-content-between mb-2">';
            html += '<div class="fs-13 fw-600 text-muted text-uppercase tracking-wider">Recent Searches</div>';
            html += '<a href="javascript:void(0);" id="racClearRecents" class="fs-11 text-muted text-decoration-none">Clear all</a>';
            html += '</div>';
            html += '<div class="search-list d-flex flex-column mb-3" style="max-height: 180px;">';

            recents.forEach(function (r, idx) {
                var fullUrl = buildAdminUrl(r.path);
                html += '<a href="' + fullUrl + '" class="search-list-item d-flex align-items-center justify-content-between" data-route-path="' + r.path + '" data-route-name="' + r.name + '" data-ajax="?path=' + r.path + '">';
                html += '<div class="d-flex align-items-center gap-2">';
                html += '<span class="badge badge-soft-teal fs-10">' + (r.category || 'Page') + '</span>';
                html += '<span class="fs-13 fw-500 text-dark">' + r.name + '</span>';
                html += '</div>';
                html += '<span class="text-muted fs-11">' + (r.uri || ('admin-cp/' + r.path)) + '</span>';
                html += '</a>';
            });

            html += '</div>';
        }

        html += '<div class="fs-13 fw-600 text-muted text-uppercase tracking-wider mb-2">Quick Navigation</div>';
        html += '<div class="search-quick-grid">';

        QUICK_ACCESS.forEach(function (q) {
            var fullUrl = buildAdminUrl(q.path);
            html += '<a href="' + fullUrl + '" class="search-quick-card" data-route-path="' + q.path + '" data-route-name="' + q.name + '" data-ajax="?path=' + q.path + '">';
            html += '<i class="material-icons">' + q.icon + '</i>';
            html += '<span>' + q.name + '</span>';
            html += '</a>';
        });

        html += '</div>';
        searchResults.innerHTML = html;

        var clearBtn = document.getElementById('racClearRecents');
        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                clearRecentSearches();
            });
        }
    }

    // Render Search Results matching user query
    function renderSearchResults(keyword, deepMatches) {
        activeResultIndex = -1;
        var cleanKey = (keyword || '').trim().toLowerCase();
        if (!cleanKey) {
            renderDefaultSearchState();
            return;
        }

        var matchedRoutes = ADMIN_DIRECTORY.filter(function (item) {
            var text = (item.name + ' ' + item.category + ' ' + item.path + ' ' + (item.keywords || '')).toLowerCase();
            return text.indexOf(cleanKey) !== -1;
        });

        var html = '';

        if (matchedRoutes.length === 0 && (!deepMatches || deepMatches.length === 0)) {
            html += '<div class="fs-14 fw-600 text-muted mb-2">Search Result</div>';
            html += '<div class="search-list h-300 d-flex flex-column gap-2 justify-content-center align-items-center fs-14 text-muted text-center py-5">';
            html += '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="text-muted mb-1"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>';
            html += '<div class="fw-600 text-dark fs-15">No results found</div>';
            html += '<div class="text-muted fs-12">No admin pages match "' + (keyword || '') + '". Try another keyword.</div>';
            html += '</div>';
            searchResults.innerHTML = html;
            return;
        }

        var totalCount = matchedRoutes.length + (deepMatches ? deepMatches.length : 0);
        html += '<div class="d-flex align-items-center justify-content-between mb-2">';
        html += '<div class="fs-13 fw-600 text-muted text-uppercase tracking-wider">Search Results (' + totalCount + ')</div>';
        html += '<small class="text-muted fs-11">Use &uarr; &darr; to navigate, Enter to select</small>';
        html += '</div>';

        html += '<div class="search-list d-flex flex-column">';

        matchedRoutes.forEach(function (route, index) {
            var fullUrl = buildAdminUrl(route.path);
            var highName = highlightKeyword(route.name, cleanKey);
            var highUri = highlightKeyword(route.uri, cleanKey);

            html += '<a href="' + fullUrl + '" class="search-list-item d-flex flex-column" data-index="' + index + '" data-route-path="' + route.path + '" data-route-name="' + route.name + '" data-ajax="?path=' + route.path + '">';
            html += '<div class="d-flex align-items-center justify-content-between mb-1">';
            html += '<div class="d-flex align-items-center gap-2">';
            html += '<span class="badge badge-soft-teal">' + route.category + '</span>';
            html += '<h5 class="mb-0 fs-13 fw-600 text-dark">' + highName + '</h5>';
            html += '</div>';
            html += '<span class="search-item-arrow text-muted">&rarr;</span>';
            html += '</div>';
            html += '<p class="text-muted fs-11 mb-0">' + highUri + '</p>';
            html += '</a>';
        });

        // Append deep setting matches from backend if present
        if (deepMatches && deepMatches.length > 0) {
            html += '<div class="fs-11 fw-700 text-muted text-uppercase tracking-wider px-2 pt-3 pb-1">Setting Field Matches</div>';
            deepMatches.forEach(function (dm, dIndex) {
                var actualIndex = matchedRoutes.length + dIndex;
                var highTitle = highlightKeyword(dm.title, cleanKey);
                var highPage = highlightKeyword(dm.pageTitle, cleanKey);

                html += '<a href="' + dm.href + '" class="search-list-item d-flex flex-column" data-index="' + actualIndex + '" data-route-path="' + dm.link + '" data-route-name="' + dm.title + '">';
                html += '<div class="d-flex align-items-center justify-content-between mb-1">';
                html += '<div class="d-flex align-items-center gap-2">';
                html += '<span class="badge badge-soft-accent">' + highPage + '</span>';
                html += '<h5 class="mb-0 fs-13 fw-600 text-dark">' + highTitle + '</h5>';
                html += '</div>';
                html += '<span class="search-item-arrow text-muted">&rarr;</span>';
                html += '</div>';
                html += '<p class="text-muted fs-11 mb-0">admin-cp/' + dm.link + '</p>';
                html += '</a>';
            });
        }

        html += '</div>';
        searchResults.innerHTML = html;
    }

    // Keyboard navigation in search results list (ArrowDown / ArrowUp / Enter)
    function handleSearchListKeyNavigation(e) {
        var items = searchResults.querySelectorAll('.search-list-item');
        if (!items || items.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeResultIndex++;
            if (activeResultIndex >= items.length) activeResultIndex = 0;
            updateActiveSearchItem(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeResultIndex--;
            if (activeResultIndex < 0) activeResultIndex = items.length - 1;
            updateActiveSearchItem(items);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeResultIndex >= 0 && items[activeResultIndex]) {
                items[activeResultIndex].click();
            } else if (items.length > 0) {
                items[0].click();
            }
        }
    }

    function updateActiveSearchItem(items) {
        Array.prototype.forEach.call(items, function (it, idx) {
            if (idx === activeResultIndex) {
                it.classList.add('active-search-item');
                it.scrollIntoView({ block: 'nearest' });
            } else {
                it.classList.remove('active-search-item');
            }
        });
    }

    // Modal Lifecycle Listeners
    if (searchModal && searchInput && searchResults) {
        // Shown event (Bootstrap event)
        if (window.jQuery) {
            window.jQuery('#staticBackdrop').on('shown.bs.modal', function () {
                searchInput.value = '';
                searchInput.focus();
                renderDefaultSearchState();
            });
        }

        // Live input typing on search input
        searchInput.addEventListener('input', function () {
            var keyword = this.value;
            renderSearchResults(keyword, null);

            // Debounced deep backend lookup for settings within pages
            if (deepSearchTimer) clearTimeout(deepSearchTimer);
            if (keyword.trim().length >= 2 && typeof window.Wo_Ajax_Requests_File === 'function') {
                deepSearchTimer = setTimeout(function () {
                    var cleanVal = searchInput.value.trim();
                    if (cleanVal.length < 2) return;

                    var ajaxUrl = window.Wo_Ajax_Requests_File() + '?f=admin_setting&s=search_in_pages';
                    var req = new XMLHttpRequest();
                    req.open('POST', ajaxUrl, true);
                    req.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    req.onreadystatechange = function () {
                        if (req.readyState === 4 && req.status === 200) {
                            try {
                                var res = JSON.parse(req.responseText);
                                if (res && res.html) {
                                    // Parse returned HTML items to extract link & title
                                    var tempDiv = document.createElement('div');
                                    tempDiv.innerHTML = res.html;
                                    var deepLinks = tempDiv.querySelectorAll('a.rac-admin-search-result');
                                    var parsedDeep = [];
                                    Array.prototype.forEach.call(deepLinks, function (dl) {
                                        var href = dl.getAttribute('href') || '';
                                        var pageSpan = dl.querySelector('.rac-admin-search-page');
                                        var titleSpan = dl.querySelector('.rac-admin-search-title');
                                        var linkMatch = href.match(/admin-cp\/([^?#]+)/);
                                        parsedDeep.push({
                                            href: href,
                                            link: linkMatch ? linkMatch[1] : '',
                                            pageTitle: pageSpan ? pageSpan.textContent.trim() : 'Setting',
                                            title: titleSpan ? titleSpan.textContent.trim() : dl.textContent.trim()
                                        });
                                    });
                                    if (parsedDeep.length > 0 && searchInput.value.trim() === cleanVal) {
                                        renderSearchResults(cleanVal, parsedDeep);
                                    }
                                }
                            } catch (ignore) {}
                        }
                    };
                    req.send('keyword=' + encodeURIComponent(cleanVal));
                }, 220);
            }
        });

        // Keydown on search input (Escape dismiss, Arrow keys navigation)
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeSearchModal();
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter') {
                handleSearchListKeyNavigation(e);
            }
        });

        // Click on search result item: save to recents, trigger smooth navigation, close modal
        searchResults.addEventListener('click', function (e) {
            var item = e.target.closest('.search-list-item, .search-quick-card');
            if (!item || !searchResults.contains(item)) return;

            var routePath = item.getAttribute('data-route-path') || '';
            var routeName = item.getAttribute('data-route-name') || '';

            if (routePath) {
                saveRecentSearch({
                    path: routePath,
                    name: routeName,
                    category: item.querySelector('.badge') ? item.querySelector('.badge').textContent.trim() : 'Page'
                });
            }

            closeSearchModal();

            // Trigger AJAX navigation if item has data-ajax and listener exists
            var dataAjax = item.getAttribute('data-ajax');
            if (dataAjax && window.jQuery) {
                var existingLink = menu.querySelector('a[data-ajax="' + dataAjax + '"]');
                if (existingLink) {
                    e.preventDefault();
                    existingLink.click();
                    return;
                }
            }
        });
    }

    // -------------------------------------------------------------
    // 3. Submenu Accordion & Smooth Click Handling
    // -------------------------------------------------------------
    menuBody.addEventListener('click', function (event) {
        var link = event.target.closest('li > a');
        if (!link || !menuBody.contains(link)) {
            return;
        }
        var item = link.parentElement;
        var submenu = link.nextElementSibling;

        if (submenu && (submenu.classList.contains('ml-menu') || submenu.tagName === 'UL')) {
            var isAlreadyOpen = item.classList.contains('open');
            var href = link.getAttribute('href') || '';

            if (href === '#' || href.indexOf('javascript') !== -1 || !link.getAttribute('data-ajax')) {
                event.preventDefault();
                item.classList.toggle('open', !isAlreadyOpen);
                if (submenu.style) {
                    submenu.style.display = !isAlreadyOpen ? 'block' : 'none';
                }
            }
        }
    });

    // -------------------------------------------------------------
    // 4. Auto-scroll to active item on load (Matches reference V4 sidebar.js)
    // -------------------------------------------------------------
    window.addEventListener('load', function () {
        var activeItem = menu.querySelector('li.open, li > a.active');
        if (activeItem && menuBody) {
            var offset = activeItem.offsetTop - 120;
            if (offset > 0) {
                menuBody.scrollTop = offset;
            }
        }
    });

    // -------------------------------------------------------------
    // 5. Mobile Drawer Support
    // -------------------------------------------------------------
    document.addEventListener('click', function (event) {
        var toggler = event.target.closest('[data-action="navigation-toggler"], .navigation-toggler');
        if (toggler) {
            event.preventDefault();
            body.classList.toggle('rac-mobile-nav-open');
            navigation.classList.toggle('open');
            return;
        }

        var closeBtn = event.target.closest('.navigation .ti-close, .navigation .navigation-header a');
        if (closeBtn) {
            event.preventDefault();
            body.classList.remove('rac-mobile-nav-open');
            navigation.classList.remove('open');
            return;
        }

        if (body.classList.contains('rac-mobile-nav-open')) {
            if (!event.target.closest('.navigation, [data-action="navigation-toggler"], #staticBackdrop')) {
                body.classList.remove('rac-mobile-nav-open');
                navigation.classList.remove('open');
            }
        }
    });

    // -------------------------------------------------------------
    // 6. Table Dropdown Overflow Fix (Bootstrap / Popper)
    // -------------------------------------------------------------
    document.addEventListener('show.bs.dropdown', function (event) {
        var dropdown = event.target && event.target.closest ? event.target.closest('tbody .dropdown') : null;
        if (!dropdown) {
            return;
        }
        var row = dropdown.closest('tr');
        var bodyRows = row && row.parentElement ? Array.prototype.slice.call(row.parentElement.children) : [];
        dropdown.classList.toggle('dropup', bodyRows.indexOf(row) >= Math.max(0, bodyRows.length - 2));
    });

    // -------------------------------------------------------------
    // 7. AJAX Navigation state synchronization
    // -------------------------------------------------------------
    if (window.jQuery) {
        window.jQuery(document).on('ajaxSuccess.racReferenceVisuals', function (event, xhr, settings) {
            var requestUrl;
            try { requestUrl = new URL(settings.url, window.location.href); } catch (ignore) { return; }
            if (!/\/admin_load\.php$/.test(requestUrl.pathname)) {
                return;
            }
            var path = requestUrl.searchParams.get('path') || 'dashboard';
            var page = path.split('/')[0];
            if (!/^[a-zA-Z0-9_-]+$/.test(page)) {
                return;
            }
            body.setAttribute('data-admin-page', page);

            // Highlight target link in sidebar
            Array.prototype.forEach.call(menu.querySelectorAll('a'), function (link) {
                link.classList.remove('active');
            });
            var target = menu.querySelector('a[data-ajax="?path=' + path + '"]') || menu.querySelector('a[data-ajax="?path=' + page + '"]');
            if (target) {
                target.classList.add('active');
                var parentLi = target.closest('li.open') || target.closest('ul.ml-menu');
                if (parentLi) {
                    var mainParent = target.closest('.navigation-menu-body > ul > li');
                    if (mainParent) mainParent.classList.add('open');
                }
            }

            // Ensure metric SVGs retain constrained sizes after AJAX dashboard load
            var metricSvgs = document.querySelectorAll('.rac-dash-metric__icon svg, .rac-dash-summary svg');
            Array.prototype.forEach.call(metricSvgs, function (svg) {
                svg.setAttribute('width', '18');
                svg.setAttribute('height', '18');
            });
        });
    }

    body.classList.add('rac-reference-v4-ready');
})();
