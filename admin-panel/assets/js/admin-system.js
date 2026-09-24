(function () {
    'use strict';

    var body = document.body;
    if (!body || !body.classList.contains('rac-admin-shell')) {
        return;
    }

    var iconPaths = {
        dashboard: 'M3 13h8V3H3v10zm10 8h8V3h-8v18zM3 21h8v-6H3v6zm10 0h8v-6h-8v6z',
        tune: 'M4 7h9.2a2.8 2.8 0 0 0 5.6 0H20V5h-1.2a2.8 2.8 0 0 0-5.6 0H4v2zm0 6h1.2a2.8 2.8 0 0 0 5.6 0H20v-2h-9.2a2.8 2.8 0 0 0-5.6 0H4v2zm0 6h9.2a2.8 2.8 0 0 0 5.6 0H20v-2h-1.2a2.8 2.8 0 0 0-5.6 0H4v2z',
        widgets: 'M4 4h7v7H4V4zm9 0h7v7h-7V4zM4 13h7v7H4v-7zm9 0h7v7h-7v-7z',
        groups: 'M8.5 11a3.5 3.5 0 1 1 .01-7.01A3.5 3.5 0 0 1 8.5 11zm7 0a3 3 0 1 1 .01-6.01A3 3 0 0 1 15.5 11zM2 19c0-3 4.3-5 6.5-5s6.5 2 6.5 5v1H2v-1zm12.8-4.4c2.1.5 5.2 2.2 5.2 4.4v1h-3v-1c0-1.6-.8-3.1-2.2-4.4z',
        payments: 'M3 6h18v12H3V6zm2 3v2h14V9H5zm0 5h6v2H5v-2z',
        workspace_premium: 'M12 2l2.2 4.5 5 .7-3.6 3.5.8 5-4.4-2.4-4.4 2.4.8-5L4.8 7.2l5-.7L12 2zm-4 16h8v3H8v-3z',
        translate: 'M4 5h7V3h2v2h7v2h-2.2a13 13 0 0 1-3 5.3l2.2 2.2-1.4 1.4-2.3-2.3a13 13 0 0 1-3.3 2l-.8-1.8a10.5 10.5 0 0 0 2.7-1.6 12 12 0 0 1-2.3-3.2H8a10 10 0 0 0 2.1 2.8A11 11 0 0 0 12.7 7H4V5zm8 12l3-7h2l3 7h-2.1l-.5-1.3h-2.8L14.1 17H12zm3.2-3h1.6l-.8-2.2-.8 2.2z',
        settings: 'M19.43 12.98c.04-.32.07-.65.07-.98s-.02-.66-.07-.98l2.11-1.65-2-3.46-2.49 1a7.1 7.1 0 0 0-1.69-.98L15 3h-4l-.36 2.93c-.6.23-1.17.56-1.69.98l-2.49-1-2 3.46 2.11 1.65c-.05.32-.07.65-.07.98s.02.66.07.98l-2.11 1.65 2 3.46 2.49-1c.52.41 1.08.74 1.69.98L11 21h4l.36-2.93c.6-.23 1.17-.56 1.69-.98l2.49 1 2-3.46-2.11-1.65zM13 15.5A3.5 3.5 0 1 1 13 8a3.5 3.5 0 0 1 0 7.5z',
        people: 'M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zM8 11c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4zm8 0c-.31 0-.66.02-1.03.05 1.16.84 2.03 1.97 2.03 3.45V19h7v-2c0-2.66-5.33-4-8-4z',
        description: 'M6 2h9l5 5v15H6V2zm8 1.5V8h4.5L14 3.5zM8 12h10v1.5H8V12zm0 4h10v1.5H8V16z',
        store: 'M4 4h16l1 5v2a3 3 0 0 1-5 2.24A3 3 0 0 1 11 13a3 3 0 0 1-5 0 3 3 0 0 1-5-2V9l3-5zm2 11h12v7H6v-7z',
        forum: 'M4 4h16v11H7l-3 3V4zm3 4h10V6H7v2zm0 4h7v-2H7v2z',
        movie: 'M3 5h18v14H3V5zm3 2H5v2h1V7zm0 4H5v2h1v-2zm0 4H5v2h1v-2zm13-8h-1v2h1V7zm0 4h-1v2h1v-2zm0 4h-1v2h1v-2z',
        language: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm6.6 8h-3.1a13 13 0 0 0-1-4.3A7.02 7.02 0 0 1 18.6 11zM12 5.1c.7 1 1.3 2.1 1.6 3.9h-3.2c.3-1.8.9-2.9 1.6-3.9zM5.4 13h3.1c.1 1.5.5 3 1 4.3A7.02 7.02 0 0 1 5.4 13zm3.1-2H5.4a7.02 7.02 0 0 1 4.1-4.3 13 13 0 0 0-1 4.3zm3.5 7.9c-.7-1-1.3-2.1-1.6-3.9h3.2c-.3 1.8-.9 2.9-1.6 3.9zm2-5.9h-4c0-.3-.1-.7-.1-1s0-.7.1-1h4c0 .3.1.7.1 1s0 .7-.1 1zm.5 4.3c.5-1.3.9-2.8 1-4.3h3.1a7.02 7.02 0 0 1-4.1 4.3z',
        folder: 'M3 6h7l2 2h9v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6z',
        payment: 'M3 6h18v12H3V6zm2 3v2h14V9H5zm0 5h6v2H5v-2z',
        view_agenda: 'M4 5h16v5H4V5zm0 7h16v7H4v-7z',
        account_circle: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 4a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm0 14a8 8 0 0 1-6.3-3.1c1.4-2.2 3.7-3.4 6.3-3.4s4.9 1.2 6.3 3.4A8 8 0 0 1 12 20z',
        attach_money: 'M12 2v3c-2.3.2-4 1.5-4 3.5 0 2.2 1.7 3 4.6 3.8 2.2.6 2.9 1.1 2.9 2.2 0 1.2-1.1 2-2.9 2-1.8 0-3.2-.7-4.4-1.8L7 17c1.2 1 2.8 1.7 5 1.9V22h2v-3.1c2.5-.3 4.1-1.8 4.1-4 0-2.3-1.6-3.2-4.8-4-2.2-.6-2.8-1-2.8-2 0-1.1 1-1.8 2.5-1.8 1.4 0 2.5.4 3.5 1.2l1.1-2.2c-1-.8-2.1-1.2-3.6-1.4V2h-2z',
        stars: 'M12 2l2.1 5.4L20 8l-4.4 3.8L17 18l-5-3.2L7 18l1.4-6.2L4 8l5.9-.6L12 2zm0 5.4-.8 2.1-2.3.2 1.7 1.4-.5 2.3 1.9-1.2 1.9 1.2-.5-2.3 1.7-1.4-2.3-.2L12 7.4z',
        color_lens: 'M12 3a9 9 0 0 0 0 18h1.5a2.5 2.5 0 0 0 0-5H12a1.5 1.5 0 0 1 0-3h2a7 7 0 0 0 0-14h-2zm-4 6a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm4-2a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm4 2a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zM7 13.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z',
        build: 'M22 19.6l-6.3-6.3a6.5 6.5 0 0 1-8-8L11 8.6 8.6 11 5.3 7.7a6.5 6.5 0 0 1 8 8l6.3 6.3 2.4-2.4z',
        warning: 'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2V9h2v5z',
        bug_report: 'M20 8h-2.8a5.8 5.8 0 0 0-1.2-1.5L17.5 5 16 3.5l-1.6 1.6A6.2 6.2 0 0 0 12 4.5c-.9 0-1.7.2-2.4.6L8 3.5 6.5 5 8 6.5A5.8 5.8 0 0 0 6.8 8H4v2h2.2c-.1.3-.1.7-.1 1v1H4v2h2.1v1c0 .3 0 .7.1 1H4v2h2.8a6 6 0 0 0 10.4 0H20v-2h-2.2c.1-.3.1-.7.1-1v-1H20v-2h-2.1v-1c0-.3 0-.7-.1-1H20V8zm-8 9.5A3.5 3.5 0 1 1 12 10a3.5 3.5 0 0 1 0 7.5z',
        compare_arrows: 'M10 3L6 7l4 4V8h9V6h-9V3zM14 21l4-4-4-4v3H5v2h9v3z',
        cloud_download: 'M19.4 10.1A7.5 7.5 0 0 0 5 8.5 5.5 5.5 0 0 0 5.5 19H11v-5H8l4-4 4 4h-3v5h6a4.5 4.5 0 0 0 .4-8.9z',
        info: 'M11 17h2v-6h-2v6zm1-14a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm0 16a7 7 0 1 1 0-14 7 7 0 0 1 0 14zm-1-10h2V7h-2v2z',
        update: 'M21 12a9 9 0 1 1-2.6-6.4L21 3v7h-7l3-3a7 7 0 1 0 2 5h2zm-10-5h2v5l4 2-.9 1.8-5.1-2.8V7z',
        more_vert: 'M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
        default: 'M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm2 4v2h10V8H7zm0 4v2h10v-2H7zm0 4v2h7v-2H7z'
    };

    function iconSvg(path) {
        return '<svg class="rac-local-icon" viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path fill="currentColor" d="' + path + '"></path></svg>';
    }

    function normalizeIconName(value) {
        value = (value || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (iconPaths[value]) {
            return value;
        }
        if (value.indexOf('people') !== -1 || value.indexOf('person') !== -1 || value.indexOf('group') !== -1) {
            return 'people';
        }
        if (value.indexOf('widget') !== -1) {
            return 'widgets';
        }
        if (value.indexOf('tune') !== -1 || value.indexOf('slider') !== -1) {
            return 'tune';
        }
        if (value.indexOf('premium') !== -1 || value.indexOf('workspace') !== -1) {
            return 'workspace_premium';
        }
        if (value.indexOf('forum') !== -1 || value.indexOf('message') !== -1 || value.indexOf('chat') !== -1) {
            return 'forum';
        }
        if (value.indexOf('movie') !== -1 || value.indexOf('video') !== -1 || value.indexOf('play') !== -1) {
            return 'movie';
        }
        if (value.indexOf('store') !== -1 || value.indexOf('shopping') !== -1 || value.indexOf('cart') !== -1) {
            return 'store';
        }
        if (value.indexOf('language') !== -1 || value.indexOf('translate') !== -1) {
            return 'language';
        }
        if (value.indexOf('payment') !== -1 || value.indexOf('money') !== -1 || value.indexOf('credit') !== -1) {
            return 'payment';
        }
        if (value.indexOf('agenda') !== -1 || value.indexOf('feature') !== -1) {
            return 'view_agenda';
        }
        if (value.indexOf('account') !== -1 || value.indexOf('user') !== -1) {
            return 'account_circle';
        }
        if (value.indexOf('star') !== -1 || value.indexOf('pro') !== -1) {
            return 'stars';
        }
        if (value.indexOf('color') !== -1 || value.indexOf('design') !== -1) {
            return 'color_lens';
        }
        if (value.indexOf('build') !== -1 || value.indexOf('tool') !== -1) {
            return 'build';
        }
        if (value.indexOf('warning') !== -1 || value.indexOf('report') !== -1) {
            return 'warning';
        }
        if (value.indexOf('compare') !== -1 || value.indexOf('api') !== -1) {
            return 'compare_arrows';
        }
        if (value.indexOf('download') !== -1 || value.indexOf('update') !== -1) {
            return 'cloud_download';
        }
        if (value.indexOf('info') !== -1 || value.indexOf('status') !== -1) {
            return 'info';
        }
        if (value.indexOf('more') !== -1 || value.indexOf('faq') !== -1) {
            return 'more_vert';
        }
        if (value.indexOf('description') !== -1 || value.indexOf('article') !== -1 || value.indexOf('page') !== -1) {
            return 'description';
        }
        if (value.indexOf('folder') !== -1 || value.indexOf('category') !== -1) {
            return 'folder';
        }
        return 'default';
    }

    function replaceMaterialIcons(root) {
        var icons = (root || document).querySelectorAll('.material-icons:not([data-rac-local-icon])');
        Array.prototype.forEach.call(icons, function (icon) {
            var name = normalizeIconName(icon.textContent);
            icon.setAttribute('data-rac-local-icon', name);
            icon.setAttribute('aria-hidden', 'true');
            icon.innerHTML = iconSvg(iconPaths[name] || iconPaths.default);
        });
    }

    function setActiveNavState() {
        var active = document.querySelector('.navigation .navigation-menu-body a.active');
        if (active) {
            active.setAttribute('aria-current', 'page');
            var parent = active.closest('li.open');
            if (parent) {
                var parentLink = parent.querySelector(':scope > a');
                if (parentLink) {
                    parentLink.setAttribute('aria-expanded', 'true');
                }
            }
        }

        var groupedLinks = document.querySelectorAll('.navigation .navigation-menu-body li > a + ul');
        Array.prototype.forEach.call(groupedLinks, function (subnav) {
            var item = subnav.parentElement;
            item.classList.add('rac-has-subnav');
            var link = item.querySelector(':scope > a');
            if (link && !link.hasAttribute('aria-expanded')) {
                link.setAttribute('aria-expanded', subnav.parentElement.classList.contains('open') ? 'true' : 'false');
            }
        });
    }

    function setupSidebarMenuClicks() {
        var nav = document.querySelector('.navigation .navigation-menu-body');
        if (!nav) {
            return;
        }
        nav.addEventListener('click', function (event) {
            if (!event.target || !event.target.closest) {
                return;
            }
            var link = event.target.closest('li > a');
            if (!link || !nav.contains(link)) {
                return;
            }
            var subnav = link.nextElementSibling;
            if (!subnav || subnav.tagName !== 'UL') {
                if (window.matchMedia('(max-width: 991.98px)').matches) {
                    closeMobileNavigation();
                }
                return;
            }
            var href = (link.getAttribute('href') || '').trim();
            if (href === '#' || href === 'javascript:void(0);' || href === 'javascript:void(0)') {
                event.preventDefault();
                var item = link.parentElement;
                item.classList.toggle('open');
                link.setAttribute('aria-expanded', item.classList.contains('open') ? 'true' : 'false');
            }
        });
    }

    function ensureMobileBackdrop() {
        var backdrop = document.querySelector('.rac-admin-drawer-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('button');
            backdrop.type = 'button';
            backdrop.className = 'rac-admin-drawer-backdrop';
            backdrop.setAttribute('aria-label', 'Close navigation');
            document.body.appendChild(backdrop);
        }
        backdrop.addEventListener('click', closeMobileNavigation);
    }

    function closeMobileNavigation() {
        body.classList.remove('rac-mobile-nav-open');
        body.classList.remove('navigation-show');
        var toggler = document.querySelector('[data-action="navigation-toggler"]');
        if (toggler) {
            toggler.setAttribute('aria-expanded', 'false');
        }
    }

    function setupNavigation() {
        ensureMobileBackdrop();
        var togglers = document.querySelectorAll('[data-action="navigation-toggler"], .navigation-header > a');
        Array.prototype.forEach.call(togglers, function (toggler) {
            toggler.setAttribute('aria-label', toggler.getAttribute('aria-label') || 'Toggle navigation');
            toggler.setAttribute('aria-expanded', body.classList.contains('navigation-show') ? 'true' : 'false');
            toggler.addEventListener('click', function () {
                window.setTimeout(function () {
                    var isMobile = window.matchMedia('(max-width: 991.98px)').matches;
                    if (isMobile) {
                        body.classList.toggle('rac-mobile-nav-open');
                    } else {
                        body.classList.remove('rac-admin-sidebar-collapsed');
                        try {
                            window.localStorage.removeItem('racAdminSidebarCollapsed');
                        } catch (ignore) {}
                    }
                    toggler.setAttribute('aria-expanded', body.classList.contains('rac-mobile-nav-open') || body.classList.contains('navigation-show') ? 'true' : 'false');
                }, 0);
            });
        });

        body.classList.remove('rac-admin-sidebar-collapsed');
        try {
            window.localStorage.removeItem('racAdminSidebarCollapsed');
        } catch (ignore) {}

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMobileNavigation();
            }
        });
        window.addEventListener('resize', function () {
            if (!window.matchMedia('(max-width: 991.98px)').matches) {
                closeMobileNavigation();
            }
        });
    }

    function setupAjaxRefreshHooks() {
        if (window.MutationObserver) {
            var content = document.querySelector('.content');
            if (content) {
                new MutationObserver(function () {
                    replaceMaterialIcons(content);
                    setActiveNavState();
                    ensureDemoModeBanner();
                }).observe(content, { childList: true, subtree: true });
            }
        }
    }

    function ensureDemoModeBanner() {
        if (body.getAttribute('data-demo-mode') !== '1') {
            return null;
        }
        var content = document.querySelector('.content');
        if (!content) {
            return null;
        }
        var banner = content.querySelector('.rac-admin-demo-notice');
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'alert alert-warning rac-admin-demo-notice';
            banner.setAttribute('role', 'status');
            banner.setAttribute('aria-live', 'polite');
            banner.textContent = 'Demo mode: admin changes are disabled. User features remain active.';
            content.insertBefore(banner, content.firstChild);
        }
        return banner;
    }

    function setupDemoModeGuard() {
        if (body.getAttribute('data-demo-mode') !== '1') {
            return;
        }

        ensureDemoModeBanner();
        var $ = window.jQuery;
        if (!$) {
            return;
        }

        $(document)
            .off('ajaxError.ramzaDemoMode')
            .on('ajaxError.ramzaDemoMode', function (event, xhr) {
                var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                if (!response && xhr && xhr.responseText) {
                    try {
                        response = JSON.parse(xhr.responseText);
                    } catch (ignore) {}
                }
                if (!response || response.demo_mode !== true) {
                    return;
                }
                var banner = ensureDemoModeBanner();
                if (banner) {
                    banner.textContent = response.message || 'Demo mode: admin changes are disabled.';
                    banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
    }

    function setupPermissionManager() {
        var $ = window.jQuery;
        if (!$ || typeof window.Wo_Ajax_Requests_File !== 'function') {
            return;
        }

        var namespace = '.ramzaPermissions';

        function context() {
            var $manager = $('#ramza-permission-manager');
            return {
                manager: $manager,
                userId: parseInt($manager.attr('data-user-id'), 10) || 0,
                hashId: $('#permission_hash_id').val() || ''
            };
        }

        function showError(message) {
            var text = message || 'The permission could not be updated. Please try again.';
            var $alert = $('#PermissionModal .ModeratorModalAlert');
            if ($alert.length) {
                $alert.html('<div class="alert alert-danger">' + $('<div>').text(text).html() + '</div>');
                return;
            }
            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({ icon: 'error', text: text });
            }
        }

        $(document)
            .off('change' + namespace, '#ramza-permission-manager .switcher input[type="checkbox"]')
            .on('change' + namespace, '#ramza-permission-manager .switcher input[type="checkbox"]', function () {
                var checkbox = this;
                var requestContext = context();
                var requestedValue = checkbox.checked;

                if (!requestContext.userId || !requestContext.hashId) {
                    checkbox.checked = !requestedValue;
                    showError('Your admin session has expired. Reload the page and try again.');
                    return;
                }

                checkbox.disabled = true;
                $.ajax({
                    url: window.Wo_Ajax_Requests_File(),
                    method: 'GET',
                    dataType: 'json',
                    data: {
                        f: 'admin_setting',
                        s: 'update_moderator_permission',
                        permission: $(checkbox).attr('name'),
                        permission_val: requestedValue ? 1 : 0,
                        user_id: requestContext.userId,
                        hash_id: requestContext.hashId
                    }
                }).done(function (data) {
                    if (!data || parseInt(data.status, 10) !== 200) {
                        checkbox.checked = !requestedValue;
                        showError(data && data.message ? data.message : 'The permission could not be updated.');
                    }
                }).fail(function (xhr) {
                    checkbox.checked = !requestedValue;
                    var response = xhr.responseJSON || {};
                    showError(response.message || 'The server did not accept the permission change.');
                }).always(function () {
                    checkbox.disabled = false;
                });
            })
            .off('click' + namespace, '#ramza-permission-manager [data-permission-role]')
            .on('click' + namespace, '#ramza-permission-manager [data-permission-role]', function () {
                var role = $(this).attr('data-permission-role');
                var roleName = role === 'admin' ? 'an admin' : (role === 'moderator' ? 'a moderator' : 'a normal user');
                var $modal = $('#PermissionModal');

                $modal.find('.ModeratorModalAlert').empty();
                $modal.find('.modal-title').text('Add as ' + roleName + '?');
                $modal.find('.permission_text').text('Are you sure you want to add this account as ' + roleName + '?');
                $('#confirm_permission_role').attr('data-permission-role', role);
                $modal.modal('show');
            })
            .off('click' + namespace, '#confirm_permission_role')
            .on('click' + namespace, '#confirm_permission_role', function () {
                var $button = $(this);
                var requestContext = context();
                var role = $button.attr('data-permission-role');

                if (!requestContext.userId || !requestContext.hashId || $.inArray(role, ['normal', 'moderator', 'admin']) === -1) {
                    showError('The permission request is incomplete. Reload the page and try again.');
                    return;
                }

                $button.prop('disabled', true);
                $.ajax({
                    url: window.Wo_Ajax_Requests_File(),
                    method: 'GET',
                    dataType: 'json',
                    data: {
                        f: 'admin_setting',
                        s: 'permission',
                        user_id: requestContext.userId,
                        hash_id: requestContext.hashId,
                        type: role
                    }
                }).done(function (data) {
                    if (data && parseInt(data.status, 10) === 200) {
                        window.location.reload();
                        return;
                    }
                    showError(data && data.message ? data.message : 'The role could not be updated.');
                }).fail(function (xhr) {
                    var response = xhr.responseJSON || {};
                    showError(response.message || 'The server did not accept the role change.');
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });
    }

    replaceMaterialIcons(document);
    body.classList.remove('small-navigation');
    body.classList.remove('rac-admin-sidebar-collapsed');
    setActiveNavState();
    setupNavigation();
    setupSidebarMenuClicks();
    setupPermissionManager();
    setupDemoModeGuard();
    setupAjaxRefreshHooks();
    body.classList.add('rac-admin-ready');
})();
