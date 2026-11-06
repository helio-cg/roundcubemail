/**
 * Canga page header: page title and welcome subtitle.
 */
(function () {
    'use strict';

    function task() {
        var match = document.body.className.match(/\btask-([a-z0-9_-]+)\b/);
        return match ? match[1] : '';
    }

    function mailboxName() {
        var name = rcmail.env.mailbox;
        var mailboxes = rcmail.env.mailboxes || {};

        if (name && mailboxes[name]) {
            return mailboxes[name].name || name;
        }

        return name === 'INBOX' ? 'Caixa de entrada' : (name || 'Caixa de entrada');
    }

    var MENU_MAILBOXES = {
        inbox: 'INBOX',
        sent: 'Sent',
        drafts: 'Drafts',
        archive: 'Archive',
        junk: 'Junk',
        trash: 'Trash'
    };

    function mailboxForClass(cls) {
        var source = document.querySelector('#mailboxlist li.' + cls + ' a[rel]');

        if (source) {
            return { name: source.getAttribute('rel'), source: source };
        }

        var mailboxes = rcmail.env.mailboxes || {};

        for (var name in mailboxes) {
            if (mailboxes[name] && mailboxes[name].class === cls) {
                return { name: name, source: null };
            }
        }

        return { name: MENU_MAILBOXES[cls], source: null };
    }

    function updateUnreadCounts() {
        Object.keys(MENU_MAILBOXES).forEach(function (cls) {
            var target = document.querySelector('.canga-menu-nav > .canga-static-submenu a.' + cls);

            if (!target) {
                return;
            }

            var info = mailboxForClass(cls);
            var counts = rcmail.env.unread_counts || {};
            var hasData = !!info.source || (info.name && Object.prototype.hasOwnProperty.call(counts, info.name));
            var count = 0;

            if (info.source) {
                var counter = info.source.querySelector('.unreadcount');

                if (counter) {
                    count = parseInt(counter.textContent.replace(/\D/g, ''), 10) || 0;
                }
            } else if (hasData) {
                count = parseInt(counts[info.name] || 0, 10) || 0;
            }

            var badge = target.querySelector('.canga-nav-count');

            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'canga-nav-count';
                badge.style.marginLeft = 'auto';
                target.appendChild(badge);
                badge.hidden = !hasData;
            }

            if (hasData) {
                badge.textContent = count > 0 ? String(count) : '';
                badge.hidden = count <= 0;
            }
        });
    }

    function settingsName() {
        var action = rcmail.env.action || '';
        var names = {
            preferences: 'Preferências',
            folders: 'Pastas',
            identities: 'Identidades',
            responses: 'Respostas',
            'plugin.twofactor_gauthenticator': 'Verificação em Duas Etapas'
        };

        return names[action] || 'Preferências';
    }

    function title() {
        var taskName = task();

        if (taskName === 'mail') {
            return rcmail.env.action === 'compose' ? 'Criar e-mail' : mailboxName();
        }
        if (taskName === 'addressbook') {
            return 'Contatos';
        }
        if (taskName === 'calendar') {
            return 'Calendário';
        }
        if (taskName === 'settings') {
            return settingsName();
        }

        return '';
    }

    function userName() {
        var name = rcmail.env.username || '';
        var email = document.querySelector('.canga-brand-user');

        if (!name && email) {
            name = email.textContent.trim();
        }

        return name ? name.split('@')[0] : '';
    }

    function listHeader() {
        var list = document.getElementById('layout-list');

        if (!list) {
            return null;
        }

        for (var i = 0; i < list.children.length; i++) {
            if (list.children[i].classList.contains('header')) {
                return list.children[i];
            }
        }

        return null;
    }

    function hasHeaderActions(header) {
        return !!(header && header.querySelector('.toolbar, .select, .toolbar-button, .toolbar-menu-button, .toolbar-list-button'));
    }

    function listActionsHolder() {
        var list = document.getElementById('layout-list');
        var holder = document.getElementById('canga-list-actions');

        if (!list) {
            return null;
        }

        if (!holder) {
            holder = document.createElement('div');
            holder.id = 'canga-list-actions';
            holder.className = 'canga-list-actions';
        }

        // Keep the actions directly above the pagination footer.
        var footer = null;
        for (var i = list.children.length - 1; i >= 0; i--) {
            if (list.children[i].classList.contains('footer')) {
                footer = list.children[i];
                break;
            }
        }

        if (footer) {
            if (holder.nextElementSibling !== footer) {
                list.insertBefore(holder, footer);
            }
        } else if (holder.parentNode !== list) {
            list.appendChild(holder);
        }

        return holder;
    }

    function moveListToolbar() {
        var holder = document.getElementById('canga-list-actions');
        var bottom = holder ? holder.querySelector('.canga-header-toolbar') : null;
        var header = listHeader() || bottom;

        if (!hasHeaderActions(header)) {
            return;
        }

        header.classList.add('canga-header-toolbar');
        holder = listActionsHolder();

        if (!holder) {
            return;
        }

        // Every list keeps its actions at the bottom of its own column,
        // directly above the pagination.
        header.classList.add('canga-list-actions-bar');
        if (header.parentNode !== holder) {
            holder.appendChild(header);
        }
    }

    function setActiveMenu() {
        var links = document.querySelectorAll(
            '.canga-menu-nav > .canga-static-submenu a, .canga-topbar-dropdown a, .canga-topbar-trigger.calendar-all'
        );
        var taskName = task();
        var activeClass = '';
        var settingsActions = {
            preferences: 'preferences',
            folders: 'folders',
            identities: 'identities',
            responses: 'responses',
            'plugin.twofactor_gauthenticator': 'twofactor'
        };
        var mailboxes = {
            INBOX: 'inbox',
            SENT: 'sent',
            DRAFTS: 'drafts',
            ARCHIVE: 'archive',
            JUNK: 'junk',
            TRASH: 'trash'
        };

        links.forEach(function (link) {
            link.classList.remove('active');
        });

        if (taskName === 'mail') {
            activeClass = mailboxes[(rcmail.env.mailbox || 'INBOX').toUpperCase()] || 'inbox';
        } else if (taskName === 'addressbook') {
            if (rcmail.env.group || groupsRequested()) {
                activeClass = 'groups';
            } else if (rcmail.env.source === '1') {
                activeClass = 'collected';
            } else if (rcmail.env.source === '2') {
                activeClass = 'contacts-all';
            } else if (addressbookView() === 'personal') {
                activeClass = 'personal';
            } else {
                activeClass = 'contacts-all';
            }
        } else if (taskName === 'calendar') {
            activeClass = 'calendar-all';
        } else if (taskName === 'settings') {
            activeClass = settingsActions[rcmail.env.action || 'preferences'] || 'preferences';
        }

        var active = activeClass && document.querySelector(
            '.canga-menu-nav > .canga-static-submenu a.' + activeClass + ', .canga-topbar a.' + activeClass
        );
        if (active) {
            active.classList.add('active');
        }

        var settingsTrigger = document.querySelector('.canga-topbar-settings > .canga-topbar-trigger');
        if (settingsTrigger) {
            settingsTrigger.classList.toggle('is-active', taskName === 'settings');
        }
    }

    function closeTopbarMenus(except) {
        document.querySelectorAll('.canga-topbar-dropdown').forEach(function (menu) {
            if (menu !== except) {
                menu.hidden = true;
                var trigger = menu.parentNode.querySelector('.canga-topbar-trigger');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    function initTopbar() {
        document.querySelectorAll('.canga-topbar-settings').forEach(function (wrap) {
            var trigger = wrap.querySelector('.canga-topbar-trigger');
            var menu = wrap.querySelector('.canga-topbar-dropdown');

            if (!trigger || !menu || trigger.dataset.cangaBound) {
                return;
            }

            trigger.dataset.cangaBound = '1';
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var open = menu.hidden;
                closeTopbarMenus(menu);
                menu.hidden = !open;
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    }

    function addressbookView() {
        try {
            return sessionStorage.getItem('canga-addressbook-view') || '';
        } catch (e) {
            return '';
        }
    }

    function groupsRequested() {
        if (document.body.classList.contains('canga-show-groups')) {
            return true;
        }

        var match = window.location.search.match(/[?&]_canga=groups(?:&|$)/);
        return !!match || window.location.hash === '#groups';
    }

    function initGroupsLink() {
        var link = document.querySelector('.canga-menu-nav a.groups');

        if (!link || link.dataset.cangaBound) {
            return;
        }

        link.dataset.cangaBound = '1';
        link.addEventListener('click', function (event) {
            if (task() === 'addressbook'
                && rcmail.env.source === '0'
                && document.body.classList.contains('canga-show-groups')
            ) {
                event.preventDefault();
                document.body.classList.remove('canga-show-groups');
                setActiveMenu();
                return;
            }

            try {
                sessionStorage.setItem('canga-show-groups', '1');
            } catch (e) {
                // ignore
            }
        });
    }

    function applyGroupsView() {
        if (task() !== 'addressbook') {
            document.body.classList.remove('canga-show-groups');
            return;
        }

        var show = groupsRequested();

        try {
            if (sessionStorage.getItem('canga-show-groups') === '1') {
                sessionStorage.removeItem('canga-show-groups');
                show = true;
            }
        } catch (e) {
            // ignore
        }

        document.body.classList.toggle('canga-show-groups', show);
    }

    function scheduleHeader() {
        initTopbar();
        initGroupsLink();
        applyGroupsView();
        moveListToolbar();
        setActiveMenu();
        updateUnreadCounts();
        setTimeout(function () {
                moveListToolbar();
            setActiveMenu();
        }, 0);
        setTimeout(function () {
                moveListToolbar();
            setActiveMenu();
        }, 120);
        setTimeout(function () {
                moveListToolbar();
            setActiveMenu();
        }, 350);
    }

    if (window.rcmail) {
        rcmail.addEventListener('init', scheduleHeader);
        rcmail.addEventListener('afterlist', scheduleHeader);
        rcmail.addEventListener('listupdate', scheduleHeader);
        rcmail.addEventListener('set-unread-count', updateUnreadCounts);
    }

    document.addEventListener('click', function (event) {
        var viewLink = event.target.closest
            ? event.target.closest('.canga-menu-nav > .canga-static-submenu a.contacts-all,'
                + ' .canga-menu-nav > .canga-static-submenu a.personal')
            : null;

        if (viewLink) {
            try {
                sessionStorage.setItem('canga-addressbook-view',
                    viewLink.classList.contains('contacts-all') ? 'contacts-all' : 'personal');
            } catch (e) {
                // ignore
            }
        }

        closeTopbarMenus();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeTopbarMenus();
        }
    });

    window.addEventListener('hashchange', applyGroupsView);

    window.addEventListener('load', function () {
        setTimeout(function () {
            initTopbar();
            initGroupsLink();
            applyGroupsView();
            }, 50);
    });
})();
