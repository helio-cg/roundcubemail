(function () {
    'use strict';

    var bound = false;

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function $$(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function env() {
        return (window.rcmail && window.rcmail.env) || {};
    }

    function accountEmail() {
        var emailNode = $('.carius-account-email');
        var stored = emailNode ? (emailNode.getAttribute('data-email') || '').trim() : '';
        var user = env().username || '';
        var sidebar = $('.username');

        if (stored && stored.indexOf('@') !== -1) {
            return stored;
        }

        if (user && user.indexOf('@') !== -1) {
            return user;
        }

        if (sidebar && sidebar.textContent.indexOf('@') !== -1) {
            return sidebar.textContent.trim();
        }

        return stored || user;
    }

    function displayName(email) {
        var local = (email || '').split('@')[0] || '';
        var words = local.split(/[._-]+/).filter(Boolean);

        if (!words.length) {
            return '';
        }

        return words.map(function (word) {
            return word.charAt(0).toUpperCase() + word.slice(1);
        }).join(' ');
    }

    function initial(name) {
        var match = (name || '').trim().match(/[A-Za-zÀ-ÿ0-9]/);
        return match ? match[0].toUpperCase() : '?';
    }

    function setInitials() {
        var email = accountEmail();
        var name = displayName(email) || email;

        $$('.carius-avatar-initial').forEach(function (node) {
            node.textContent = initial(name);
        });

        var nameNode = $('.carius-account-name');
        var emailNode = $('.carius-account-email');

        if (nameNode) {
            nameNode.textContent = name || email;
        }

        if (emailNode) {
            emailNode.textContent = email;
        }
    }

    function unreadCounts() {
        var counts = env().unread_counts || {};
        var inbox = 0;
        var total = 0;

        Object.keys(counts).forEach(function (mailbox) {
            var value = parseInt(counts[mailbox], 10) || 0;
            total += value;
            if (mailbox.toUpperCase() === 'INBOX') {
                inbox = value;
            }
        });

        return { inbox: inbox, total: total };
    }

    function ensureMailBadge() {
        var mail = document.querySelector('#taskmenu > a.mail');

        if (mail && !mail.querySelector('.carius-mail-badge')) {
            var badge = document.createElement('span');
            badge.className = 'carius-mail-badge';
            badge.hidden = true;
            mail.appendChild(badge);
        }
    }

    function updateUnread() {
        ensureMailBadge();

        var counts = unreadCounts();
        var badge = $('.carius-bell-badge');
        var mailBadge = $('.carius-mail-badge');
        var text = $('.carius-bell-text');
        var count = counts.total > 0 ? counts.total : counts.inbox;
        var number = count > 99 ? '99+' : String(count);

        if (badge) {
            badge.textContent = number;
            badge.hidden = count <= 0;
        }

        if (mailBadge) {
            mailBadge.textContent = number;
            mailBadge.hidden = count <= 0;
        }

        if (text) {
            if (count <= 0) {
                text.textContent = 'Tudo em dia por aqui.';
            } else if (count === 1) {
                text.textContent = 'Você tem 1 mensagem não lida.';
            } else {
                text.textContent = 'Você tem ' + count + ' mensagens não lidas.';
            }
        }
    }

    function closePopovers(except) {
        $$('.carius-popover').forEach(function (popover) {
            if (popover !== except) {
                popover.hidden = true;
            }
        });

        $$('.carius-avatar, .carius-bell').forEach(function (trigger) {
            var popover = trigger.parentNode.querySelector('.carius-popover');
            if (!popover || popover.hidden) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function togglePopover(trigger, popover) {
        var open = popover.hidden;

        closePopovers(popover);
        popover.hidden = !open;
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function updateThemeLabel() {
        var label = $('.carius-theme-label');
        if (!label) {
            return;
        }

        label.textContent = document.documentElement.classList.contains('dark-mode')
            ? 'Modo claro'
            : 'Modo escuro';
    }

    function syncListToolbar() {
        var list = document.getElementById('layout-list');
        var header = document.getElementById('messagelist-header');
        var searchbar = list ? $('.searchbar', list) : null;

        if (!list || !header || !searchbar || !document.getElementById('toolbar-list-menu')) {
            return;
        }

        if (window.matchMedia('(min-width: 1000px)').matches) {
            if (header.parentNode !== searchbar) {
                searchbar.insertBefore(header, searchbar.firstChild);
            }
            searchbar.classList.add('carius-list-searchbar');
        } else {
            if (header.parentNode === searchbar) {
                list.insertBefore(header, searchbar);
            }
            searchbar.classList.remove('carius-list-searchbar');
        }
    }

    function bind() {
        if (bound) {
            return;
        }

        bound = true;

        var accountButton = $('.carius-account > .carius-avatar');
        var accountMenu = $('.carius-account-menu');
        var bellButton = $('.carius-bell');
        var bellMenu = $('.carius-bell-menu');

        if (accountButton && accountMenu) {
            accountButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                togglePopover(accountButton, accountMenu);
                updateThemeLabel();
            });
        }

        if (bellButton && bellMenu) {
            bellButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                updateUnread();
                togglePopover(bellButton, bellMenu);
            });
        }

        var themeToggle = $('.carius-theme-toggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var nativeTheme = $('#taskmenu a.theme');
                if (nativeTheme) {
                    nativeTheme.click();
                }

                setTimeout(updateThemeLabel, 60);
                closePopovers();
            });
        }

        var aboutItem = $('.carius-about');
        if (aboutItem) {
            aboutItem.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                closePopovers();

                if (window.UI && UI.about_dialog) {
                    UI.about_dialog(aboutItem);
                }
            });
        }

        var logoutItem = $('.carius-logout');
        if (logoutItem) {
            logoutItem.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                closePopovers();

                if (window.rcmail) {
                    rcmail.command('logout');
                }
            });
        }

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.carius-account') && !event.target.closest('.carius-bell-wrap')) {
                closePopovers();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closePopovers();
            }
        });

        window.addEventListener('resize', syncListToolbar);
    }

    function refresh() {
        bind();
        setInitials();
        updateUnread();
        updateThemeLabel();
        syncListToolbar();
        setTimeout(syncListToolbar, 50);
    }

    if (window.rcmail) {
        rcmail.addEventListener('init', refresh);
        rcmail.addEventListener('afterlist', function () {
            updateUnread();
            syncListToolbar();
        });
        rcmail.addEventListener('listupdate', function () {
            updateUnread();
            syncListToolbar();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh);
    } else {
        refresh();
    }
})();
