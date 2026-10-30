/**
 * Car Wash Service Pro - Main JavaScript
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Theme Toggle (Light / Dark)
    const existingAttrTheme = document.documentElement.getAttribute('data-theme');
    const savedTheme = localStorage.getItem('carwash_theme') || existingAttrTheme || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    const themeToggleBtn = document.getElementById('themeToggleBtn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = (currentTheme === 'dark') ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('carwash_theme', newTheme);
            updateThemeIcon(newTheme);
        });
    }

    function updateThemeIcon(theme) {
        const icon = document.querySelector('#themeToggleBtn i');
        if (icon) {
            icon.className = theme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        }
    }

    // 2. Modals Open / Close Helper
    window.openModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    };

    window.closeModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    };

    // Close modal on clicking backdrop
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                backdrop.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });

    // 3. Fast Instant Table Search (Plate, Phone, Customer name)
    const tableSearchInput = document.getElementById('tableSearchInput');
    if (tableSearchInput) {
        tableSearchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const rows = document.querySelectorAll('.searchable-table tbody tr');
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // ==========================================================
    // 4. Sidebar Navigation: Categories, Collapse & Flyout Panel
    // ==========================================================
    const sidebar = document.getElementById('appSidebar');
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
    const sidebarTooltip = document.getElementById('sidebarTooltip');
    const sidebarFlyout = document.getElementById('sidebarFlyout');

    // Sidebar Accordion Option:
    // Set to true to automatically collapse other groups when a new group is opened.
    // Set to false to allow multiple groups to remain open simultaneously.
    // Can also be controlled via the data-accordion="true|false" attribute on #appSidebar.
    const SIDEBAR_ACCORDION_MODE = false;

    function isDesktop() {
        return window.innerWidth > 1024;
    }

    // Sync initial desktop collapsed state from localStorage
    const savedSidebarState = localStorage.getItem('carwash_sidebar_collapsed');
    const isInitiallyCollapsed = (savedSidebarState === 'true') || (savedSidebarState === null && document.documentElement.classList.contains('sidebar-collapsed'));

    if (isInitiallyCollapsed && isDesktop()) {
        if (sidebar) sidebar.classList.add('collapsed');
        document.documentElement.classList.add('sidebar-collapsed');
        if (sidebarToggleIcon) sidebarToggleIcon.className = 'fa-solid fa-chevron-right';
        if (sidebarToggleBtn) {
            sidebarToggleBtn.setAttribute('aria-label', 'Expand side menu');
            sidebarToggleBtn.setAttribute('title', 'Expand menu');
        }
    } else if (isDesktop()) {
        if (sidebar) sidebar.classList.remove('collapsed');
        document.documentElement.classList.remove('sidebar-collapsed');
        if (sidebarToggleIcon) sidebarToggleIcon.className = 'fa-solid fa-chevron-left';
        if (sidebarToggleBtn) {
            sidebarToggleBtn.setAttribute('aria-label', 'Collapse side menu');
            sidebarToggleBtn.setAttribute('title', 'Collapse menu');
        }
    }

    if (sidebarToggleBtn && sidebar) {
        sidebarToggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            hideTooltip();
            hideFlyout(true);
            const willCollapse = !sidebar.classList.contains('collapsed');
            if (willCollapse) {
                sidebar.classList.add('collapsed');
                document.documentElement.classList.add('sidebar-collapsed');
                localStorage.setItem('carwash_sidebar_collapsed', 'true');
                if (sidebarToggleIcon) sidebarToggleIcon.className = 'fa-solid fa-chevron-right';
                sidebarToggleBtn.setAttribute('aria-label', 'Expand side menu');
                sidebarToggleBtn.setAttribute('title', 'Expand menu');
            } else {
                sidebar.classList.remove('collapsed');
                document.documentElement.classList.remove('sidebar-collapsed');
                localStorage.setItem('carwash_sidebar_collapsed', 'false');
                if (sidebarToggleIcon) sidebarToggleIcon.className = 'fa-solid fa-chevron-left';
                sidebarToggleBtn.setAttribute('aria-label', 'Collapse side menu');
                sidebarToggleBtn.setAttribute('title', 'Collapse menu');
            }
        });
    }

    // Floating Tooltip for Single Nav Links & Profile
    function showTooltip(targetEl, text) {
        if (!sidebarTooltip || !sidebar || !sidebar.classList.contains('collapsed') || !isDesktop()) return;
        sidebarTooltip.textContent = text;
        const rect = targetEl.getBoundingClientRect();
        const top = rect.top + (rect.height / 2) - (sidebarTooltip.offsetHeight / 2 || 14);
        const left = rect.right + 10;
        sidebarTooltip.style.top = `${top}px`;
        sidebarTooltip.style.left = `${left}px`;
        sidebarTooltip.classList.add('visible');
    }

    function hideTooltip() {
        if (sidebarTooltip) {
            sidebarTooltip.classList.remove('visible');
        }
    }

    // Floating Flyout Menu for Category Groups (Desktop Collapsed Mode)
    let flyoutHideTimer = null;
    let activeFlyoutGroup = null;

    function showFlyoutForGroup(grp) {
        if (!sidebarFlyout || !sidebar || !sidebar.classList.contains('collapsed') || !isDesktop()) return;
        clearTimeout(flyoutHideTimer);
        const template = grp.querySelector('.nav-group-flyout-template');
        if (!template) return;

        sidebarFlyout.innerHTML = template.innerHTML;
        activeFlyoutGroup = grp;

        const btn = grp.querySelector('.nav-group-btn');
        const rect = btn.getBoundingClientRect();

        sidebarFlyout.querySelectorAll('a').forEach(a => {
            a.addEventListener('click', () => {
                hideFlyout(true);
                if (!isDesktop()) closeMobileSidebar();
            });
        });

        sidebarFlyout.style.left = `${rect.right + 8}px`;
        sidebarFlyout.style.top = `${rect.top}px`;
        sidebarFlyout.classList.add('visible');
        sidebarFlyout.setAttribute('aria-hidden', 'false');

        requestAnimationFrame(() => {
            const flyoutRect = sidebarFlyout.getBoundingClientRect();
            if (flyoutRect.bottom > window.innerHeight - 16) {
                const clampedTop = Math.max(16, window.innerHeight - flyoutRect.height - 16);
                sidebarFlyout.style.top = `${clampedTop}px`;
            }
        });
    }

    function scheduleHideFlyout(delay = 180) {
        clearTimeout(flyoutHideTimer);
        flyoutHideTimer = setTimeout(() => {
            if (sidebarFlyout) {
                sidebarFlyout.classList.remove('visible');
                sidebarFlyout.setAttribute('aria-hidden', 'true');
            }
            activeFlyoutGroup = null;
        }, delay);
    }

    function hideFlyout(immediate = false) {
        clearTimeout(flyoutHideTimer);
        if (immediate) {
            if (sidebarFlyout) {
                sidebarFlyout.classList.remove('visible');
                sidebarFlyout.setAttribute('aria-hidden', 'true');
            }
            activeFlyoutGroup = null;
        } else {
            scheduleHideFlyout(120);
        }
    }

    if (sidebarFlyout) {
        sidebarFlyout.addEventListener('mouseenter', () => clearTimeout(flyoutHideTimer));
        sidebarFlyout.addEventListener('mouseleave', () => scheduleHideFlyout(180));
    }

    // ==========================================================
    // 5. Collapsible Category Groups Behavior & Persistence
    // ==========================================================
    if (sidebar) {
        const isAccordion = (sidebar.getAttribute('data-accordion') === 'true') || SIDEBAR_ACCORDION_MODE;
        const navGroups = sidebar.querySelectorAll('.nav-group');

        function getStoredOpenGroups() {
            try {
                const stored = localStorage.getItem('carwash_sidebar_open_groups');
                return stored ? JSON.parse(stored) : null;
            } catch (e) {
                return null;
            }
        }

        function saveStoredOpenGroups() {
            try {
                const openIds = [];
                navGroups.forEach(g => {
                    if (g.classList.contains('is-open')) {
                        openIds.push(g.getAttribute('data-group-id'));
                    }
                });
                localStorage.setItem('carwash_sidebar_open_groups', JSON.stringify(openIds));
            } catch (e) {}
        }

        const storedGroups = getStoredOpenGroups();

        // Initialize groups: Group of current page is always open; others follow storage or stay closed
        navGroups.forEach(grp => {
            const grpId = grp.getAttribute('data-group-id');
            const btn = grp.querySelector('.nav-group-btn');
            const hasActive = grp.classList.contains('has-active');

            let shouldBeOpen = false;
            if (hasActive) {
                shouldBeOpen = true;
            } else if (!isAccordion && storedGroups && storedGroups.includes(grpId)) {
                shouldBeOpen = true;
            }

            if (shouldBeOpen) {
                grp.classList.add('is-open');
                if (btn) btn.setAttribute('aria-expanded', 'true');
            } else {
                grp.classList.remove('is-open');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }

            // Click handling on group title button
            if (btn) {
                btn.addEventListener('click', (e) => {
                    if (sidebar.classList.contains('collapsed') && isDesktop()) {
                        e.preventDefault();
                        e.stopPropagation();
                        hideTooltip();
                        showFlyoutForGroup(grp);
                        return;
                    }

                    e.preventDefault();
                    e.stopPropagation();

                    const isOpen = grp.classList.contains('is-open');

                    if (isAccordion) {
                        navGroups.forEach(otherGrp => {
                            otherGrp.classList.remove('is-open');
                            const otherBtn = otherGrp.querySelector('.nav-group-btn');
                            if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
                        });
                        if (!isOpen) {
                            grp.classList.add('is-open');
                            btn.setAttribute('aria-expanded', 'true');
                        }
                    } else {
                        if (isOpen) {
                            grp.classList.remove('is-open');
                            btn.setAttribute('aria-expanded', 'false');
                        } else {
                            grp.classList.add('is-open');
                            btn.setAttribute('aria-expanded', 'true');
                        }
                    }

                    saveStoredOpenGroups();
                });

                // Collapsed mode hover & focus
                btn.addEventListener('mouseenter', () => {
                    if (sidebar.classList.contains('collapsed') && isDesktop()) {
                        hideTooltip();
                        showFlyoutForGroup(grp);
                    }
                });
                btn.addEventListener('mouseleave', () => {
                    if (sidebar.classList.contains('collapsed') && isDesktop()) {
                        scheduleHideFlyout(180);
                    }
                });
                btn.addEventListener('focus', () => {
                    if (sidebar.classList.contains('collapsed') && isDesktop()) {
                        hideTooltip();
                        showFlyoutForGroup(grp);
                    }
                });
                btn.addEventListener('blur', () => {
                    if (sidebar.classList.contains('collapsed') && isDesktop()) {
                        scheduleHideFlyout(180);
                    }
                });
            }
        });

        // Single Nav Links (no dropdown) tooltip handling
        const singleLinks = sidebar.querySelectorAll('.nav-item-single .nav-link');
        singleLinks.forEach(link => {
            const span = link.querySelector('span');
            const labelText = span ? span.textContent.trim() : '';

            link.addEventListener('mouseenter', () => {
                hideFlyout(true);
                showTooltip(link, labelText);
            });
            link.addEventListener('mouseleave', hideTooltip);
            link.addEventListener('focus', () => {
                hideFlyout(true);
                showTooltip(link, labelText);
            });
            link.addEventListener('blur', hideTooltip);
        });

        // User avatar tooltip
        const userAvatarWrap = sidebar.querySelector('.user-avatar-wrap');
        if (userAvatarWrap) {
            const userNameEl = userAvatarWrap.querySelector('div:last-child');
            const userText = userNameEl ? userNameEl.innerText.replace('\n', ' - ') : 'User Profile';
            userAvatarWrap.addEventListener('mouseenter', () => {
                hideFlyout(true);
                showTooltip(userAvatarWrap, userText);
            });
            userAvatarWrap.addEventListener('mouseleave', hideTooltip);
        }
    }

    // ==========================================================
    // 6. Mobile & Tablet Drawer (Hamburger Button, Backdrop, Escape, Nav click)
    // ==========================================================
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const mobileSidebarClose = document.getElementById('mobileSidebarClose');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    function openMobileSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('mobile-open');
        if (sidebarBackdrop) sidebarBackdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeMobileSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('mobile-open');
        if (sidebarBackdrop) sidebarBackdrop.classList.remove('active');
        document.body.style.overflow = '';
        hideTooltip();
        hideFlyout(true);
    }

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }
        });
    }

    if (mobileSidebarClose) {
        mobileSidebarClose.addEventListener('click', (e) => {
            e.stopPropagation();
            closeMobileSidebar();
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', closeMobileSidebar);
    }

    // Close mobile drawer when pressing Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeMobileSidebar();
            hideTooltip();
            hideFlyout(true);
        }
    });

    // Close mobile drawer when choosing a page link
    if (sidebar) {
        sidebar.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                if (!isDesktop()) {
                    closeMobileSidebar();
                }
            });
        });
    }

    // Handle window resize between desktop and tablet/mobile
    window.addEventListener('resize', () => {
        if (isDesktop()) {
            closeMobileSidebar();
            const shouldCollapse = localStorage.getItem('carwash_sidebar_collapsed') === 'true';
            if (shouldCollapse) {
                sidebar.classList.add('collapsed');
                document.documentElement.classList.add('sidebar-collapsed');
                if (sidebarToggleIcon) sidebarToggleIcon.className = 'fa-solid fa-chevron-right';
            } else {
                sidebar.classList.remove('collapsed');
                document.documentElement.classList.remove('sidebar-collapsed');
                if (sidebarToggleIcon) sidebarToggleIcon.className = 'fa-solid fa-chevron-left';
            }
        } else {
            sidebar.classList.remove('collapsed');
            document.documentElement.classList.remove('sidebar-collapsed');
            hideTooltip();
            hideFlyout(true);
        }
    });

    // ==========================================================
    // 5. Notification Bell, Audio Synthesizer & Live Background Sync
    // ==========================================================
    let audioCtx = null;

    function getAudioContext() {
        if (!audioCtx) {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) {
                audioCtx = new AudioContextClass();
            }
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        return audioCtx;
    }

    // Ensure audio context is unlocked on first user gesture
    ['click', 'keydown', 'touchstart'].forEach(evt => {
        document.addEventListener(evt, () => {
            if (audioCtx && audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
        }, { once: true });
    });

    // Chime sound: Short pleasant 2-tone melodic chime for new jobs
    window.playChime = function() {
        if (window.CARWASH_APP && window.CARWASH_APP.soundMuted) return;
        try {
            const ctx = getAudioContext();
            if (!ctx) return;
            const now = ctx.currentTime;
            
            // Tone 1: 587.33 Hz (D5)
            const osc1 = ctx.createOscillator();
            const gain1 = ctx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(587.33, now);
            gain1.gain.setValueAtTime(0, now);
            gain1.gain.linearRampToValueAtTime(0.18, now + 0.03);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.22);
            osc1.connect(gain1);
            gain1.connect(ctx.destination);
            osc1.start(now);
            osc1.stop(now + 0.23);

            // Tone 2: 880 Hz (A5)
            const osc2 = ctx.createOscillator();
            const gain2 = ctx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(880, now + 0.12);
            gain2.gain.setValueAtTime(0, now + 0.12);
            gain2.gain.linearRampToValueAtTime(0.22, now + 0.15);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.45);
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.start(now + 0.12);
            osc2.stop(now + 0.46);
        } catch(e) {
            console.warn('Audio chime playback error:', e);
        }
    };

    // Alert sound: Stronger two-pulse alert for parts requests & critical alerts
    window.playAlert = function() {
        if (window.CARWASH_APP && window.CARWASH_APP.soundMuted) return;
        try {
            const ctx = getAudioContext();
            if (!ctx) return;
            const now = ctx.currentTime;

            // Pulse 1
            const osc1 = ctx.createOscillator();
            const gain1 = ctx.createGain();
            osc1.type = 'triangle';
            osc1.frequency.setValueAtTime(784, now); // G5
            gain1.gain.setValueAtTime(0, now);
            gain1.gain.linearRampToValueAtTime(0.35, now + 0.02);
            gain1.gain.exponentialRampToValueAtTime(0.01, now + 0.18);
            osc1.connect(gain1);
            gain1.connect(ctx.destination);
            osc1.start(now);
            osc1.stop(now + 0.19);

            // Pulse 2
            const osc2 = ctx.createOscillator();
            const gain2 = ctx.createGain();
            osc2.type = 'sawtooth';
            osc2.frequency.setValueAtTime(1046.5, now + 0.15); // C6
            gain2.gain.setValueAtTime(0, now + 0.15);
            gain2.gain.linearRampToValueAtTime(0.32, now + 0.17);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.42);
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.start(now + 0.15);
            osc2.stop(now + 0.43);
        } catch(e) {
            console.warn('Audio alert playback error:', e);
        }
    };

    // Toggle Sound Mute
    window.toggleNotificationSound = function() {
        const isCurrentlyMuted = window.CARWASH_APP ? window.CARWASH_APP.soundMuted : false;
        const newMuteState = !isCurrentlyMuted;
        
        // Optimistic UI update
        if (window.CARWASH_APP) window.CARWASH_APP.soundMuted = newMuteState;
        const icon = document.getElementById('soundToggleIcon');
        const btn = document.getElementById('soundToggleBtn');
        if (icon) {
            icon.className = newMuteState ? 'fa-solid fa-volume-xmark text-danger' : 'fa-solid fa-volume-high';
        }
        if (btn) {
            btn.title = newMuteState ? 'Unmute notification sounds' : 'Mute notification sounds';
        }

        if (!newMuteState) {
            // Play short confirmation chime when unmuting
            playChime();
        }

        // Persist to server
        fetch((window.CARWASH_APP ? window.CARWASH_APP.baseUrl : '') + '/index.php?action=toggle_sound', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'mute=' + (newMuteState ? '1' : '0')
        }).catch(err => console.error('Failed to update sound settings:', err));
    };

    // Toggle Notification Dropdown
    window.toggleNotifDropdown = function(event) {
        if (event) event.stopPropagation();
        const panel = document.getElementById('notifPanel');
        const btn = document.getElementById('notifBellBtn');
        if (!panel) return;
        
        const isOpen = panel.style.display !== 'none';
        if (isOpen) {
            panel.style.display = 'none';
            if (btn) btn.setAttribute('aria-expanded', 'false');
        } else {
            panel.style.display = 'flex';
            if (btn) btn.setAttribute('aria-expanded', 'true');
        }
    };

    // Close notification panel when clicking outside
    document.addEventListener('click', (e) => {
        const wrap = document.querySelector('.notification-dropdown-wrap');
        const panel = document.getElementById('notifPanel');
        if (wrap && panel && !wrap.contains(e.target)) {
            panel.style.display = 'none';
            const btn = document.getElementById('notifBellBtn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    });

    // Notification click handler
    window.handleNotifClick = function(event, notifId, linkUrl) {
        if (event) event.stopPropagation();
        fetch((window.CARWASH_APP ? window.CARWASH_APP.baseUrl : '') + '/index.php?action=mark_notif_read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'notification_id=' + encodeURIComponent(notifId)
        }).then(() => {
            if (linkUrl && linkUrl !== '#' && linkUrl !== '') {
                window.location.href = linkUrl;
            } else {
                const item = event.currentTarget || (event.target ? event.target.closest('.notif-item') : null);
                if (item) item.classList.remove('unread');
                decrementNotifBadge();
            }
        }).catch(err => {
            if (linkUrl && linkUrl !== '#' && linkUrl !== '') {
                window.location.href = linkUrl;
            }
        });
    };

    function decrementNotifBadge() {
        const badge = document.getElementById('notifBadge');
        if (!badge) return;
        let count = parseInt(badge.textContent.trim(), 10) || 0;
        count = Math.max(0, count - 1);
        if (count <= 0) {
            badge.style.display = 'none';
            badge.textContent = '0';
        } else {
            badge.textContent = count > 99 ? '99+' : count;
        }
    }

    // Mark All Notifications as Read
    window.markAllNotifsRead = function(event) {
        if (event) event.stopPropagation();
        fetch((window.CARWASH_APP ? window.CARWASH_APP.baseUrl : '') + '/index.php?action=mark_all_notifs_read', {
            method: 'POST'
        }).then(r => r.json()).then(res => {
            if (res.success) {
                const badge = document.getElementById('notifBadge');
                if (badge) {
                    badge.style.display = 'none';
                    badge.textContent = '0';
                }
                document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            }
        }).catch(err => console.error('Failed to mark all read:', err));
    };

    // Render notification items in panel
    function renderNotifsList(notifs, currentLang) {
        const listEl = document.getElementById('notifPanelList');
        if (!listEl) return;
        if (!notifs || notifs.length === 0) {
            listEl.innerHTML = `
                <div class="notif-empty">
                    <i class="fa-regular fa-bell-slash" style="font-size: 28px; color: var(--text-light); margin-bottom: 8px; display: block;"></i>
                    ${currentLang === 'km' ? 'គ្មានការជូនដំណឹងថ្មីទេ' : 'No notifications'}
                </div>`;
            return;
        }

        const iconMap = {
            transfer_approval: '<i class="fa-solid fa-building-columns text-primary"></i>',
            parts_request: '<i class="fa-solid fa-wrench text-warning"></i>',
            parts_approved: '<i class="fa-solid fa-circle-check text-success"></i>',
            parts_rejected: '<i class="fa-solid fa-circle-xmark text-danger"></i>',
            long_wait: '<i class="fa-solid fa-stopwatch text-danger"></i>',
            bay_idle: '<i class="fa-solid fa-triangle-exclamation text-warning"></i>',
            cash_variance: '<i class="fa-solid fa-sack-xmark text-danger"></i>',
            new_car: '<i class="fa-solid fa-car text-info"></i>'
        };

        let html = '';
        notifs.forEach(n => {
            const isUnread = parseInt(n.is_read, 10) === 0;
            const icon = iconMap[n.category] || '<i class="fa-solid fa-bell text-primary"></i>';
            const title = (currentLang === 'km' && n.title_km) ? n.title_km : n.title_en;
            const msg = (currentLang === 'km' && n.message_km) ? n.message_km : n.message_en;
            const safeUrl = (n.link_url || '').replace(/"/g, '&quot;');
            
            let timeStr = n.created_at;
            try {
                const d = new Date(n.created_at.replace(/-/g, '/'));
                timeStr = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch(e) {}

            html += `
                <div class="notif-item ${isUnread ? 'unread' : ''}" onclick="handleNotifClick(event, ${n.id}, '${safeUrl}')">
                    <div class="notif-item-icon">${icon}</div>
                    <div class="notif-item-content">
                        <div class="notif-item-title">${escapeHtml(title)}</div>
                        <div class="notif-item-msg">${escapeHtml(msg)}</div>
                        <div class="notif-item-time">${timeStr}</div>
                    </div>
                </div>`;
        });
        listEl.innerHTML = html;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // Background Live Sync (Runs every 3.5s for logged-in users)
    let lastScreenState = null;
    let lastInteractionTime = Date.now();
    ['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(evt => {
        window.addEventListener(evt, () => { lastInteractionTime = Date.now(); }, { passive: true });
    });

    function initLiveSync() {
        if (!window.CARWASH_APP || !window.CARWASH_APP.userId) return;

        setInterval(() => {
            fetch(window.CARWASH_APP.baseUrl + '/index.php?action=live_sync')
                .then(res => {
                    if (res.ok) {
                        window.lastServerHeartbeatTime = Date.now();
                        hideHeartbeatBanner();
                    }
                    return res.json();
                })
                .then(data => {
                    if (!data || !data.success) return;

                    // 1. Update unread count badge
                    const badge = document.getElementById('notifBadge');
                    if (badge) {
                        const count = data.unread_count || 0;
                        if (count > 0) {
                            badge.style.display = 'flex';
                            badge.textContent = count > 99 ? '99+' : count;
                        } else {
                            badge.style.display = 'none';
                            badge.textContent = '0';
                        }
                    }

                    // 2. Play audible sound if trigger flag present
                    if (data.sound === 'alert') {
                        playAlert();
                    } else if (data.sound === 'chime') {
                        playChime();
                    }

                    // 3. Update notification panel content if open
                    const panel = document.getElementById('notifPanel');
                    if (panel && panel.style.display !== 'none' && data.notifications) {
                        renderNotifsList(data.notifications, window.CARWASH_APP.currentLang);
                    }

                    // 4. Live update check for operational screens (Washer, Technical, Warehouse)
                    if (data.screen) {
                        const newHash = JSON.stringify(data.screen);
                        if (lastScreenState !== null && lastScreenState !== newHash) {
                            handleScreenLiveChange(data.screen);
                        }
                        lastScreenState = newHash;
                    }
                })
                .catch(err => {
                    // Non-blocking catch
                });
        }, 3500);
    }

    // Handle non-intrusive live updates on operational screens
    function handleScreenLiveChange(screen) {
        const url = window.location.href;
        const isWasher = url.includes('view=washer_jobs');
        const isTech = url.includes('view=mechanic_jobs');
        const isBays = url.includes('view=bays_queue');
        const isWarehouse = url.includes('view=oil_care');

        const hasActiveModal = !!document.querySelector('.modal.active, .modal[style*="display: flex"], .modal[style*="display: block"]');
        const isTyping = document.activeElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
        const idleSeconds = (Date.now() - lastInteractionTime) / 1000;

        if ((isWasher || isTech || isBays || isWarehouse) && !hasActiveModal && !isTyping && idleSeconds >= 3) {
            window.location.reload();
        }
    }

    // Toast notification for real-time background events
    window.showAppToast = function(msg) {
        let t = document.getElementById('globalAppToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'globalAppToast';
            t.style.cssText = 'position: fixed; bottom: 24px; right: 24px; background: #1e293b; color: #ffffff; padding: 12px 18px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); z-index: 99999; border-left: 5px solid #6366f1; font-size: 13px; display: flex; align-items: center; gap: 10px; transition: opacity 0.3s;';
            document.body.appendChild(t);
        }
        t.innerHTML = '<i class="fa-solid fa-cloud-arrow-down" style="color: #818cf8; font-size: 16px;"></i><span>' + escapeHtml(msg) + '</span>';
        t.style.display = 'flex';
        t.style.opacity = '1';
        setTimeout(() => {
            t.style.opacity = '0';
            setTimeout(() => { t.style.display = 'none'; }, 300);
        }, 7000);
    };

    // Fast 30-second Critical Sync Check (prices, permissions, status)
    function initCriticalSync() {
        if (!window.CARWASH_APP || !window.CARWASH_APP.userId) return;

        setInterval(() => {
            fetch(window.CARWASH_APP.baseUrl + '/index.php?action=check_critical_sync')
                .then(r => r.json())
                .then(data => {
                    if (data && data.success && data.has_update) {
                        window.showAppToast(data.message || 'System update: prices or permissions have been updated');
                        if (typeof window.onCriticalSystemUpdate === 'function') {
                            window.onCriticalSystemUpdate(data);
                        }
                    }
                })
                .catch(() => {});
        }, 30000);
    }

    // Tablet & Screen Connection Heartbeat (>60s Offline Monitor)
    window.lastServerHeartbeatTime = Date.now();

    function showHeartbeatBanner() {
        let b = document.getElementById('heartbeatOfflineBanner');
        if (!b) {
            b = document.createElement('div');
            b.id = 'heartbeatOfflineBanner';
            b.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; background: #eab308; color: #713f12; padding: 10px 16px; font-weight: 700; font-size: 13px; z-index: 999999; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
            b.innerHTML = `
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px;"></i>
                    <span>Offline - Use Manual Mode (Cannot reach Shop Server for &gt;60s)</span>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <a href="${window.CARWASH_APP ? window.CARWASH_APP.baseUrl : ''}/index.php?view=manual_receipt" target="_blank" class="btn btn-sm" style="background: #713f12; color: #ffffff; border: none; font-size: 11px; text-decoration: none; padding: 4px 10px; border-radius: 4px; display: inline-flex; align-items: center;">
                        <i class="fa-solid fa-receipt" style="margin-right: 4px;"></i> Manual Mode Receipt (PDF)
                    </a>
                    <a href="${window.CARWASH_APP ? window.CARWASH_APP.baseUrl : ''}/index.php?view=paper_forms&form=job_card" target="_blank" class="btn btn-sm" style="background: rgba(0,0,0,0.1); color: #713f12; border: 1px solid #713f12; font-size: 11px; text-decoration: none; padding: 4px 8px; border-radius: 4px;">
                        <i class="fa-solid fa-print"></i> Blank Cards
                    </a>
                    <button type="button" onclick="window.triggerHeartbeatRetry()" class="btn btn-sm" style="background: #ffffff; color: #713f12; border: 1px solid #ca8a04; font-size: 11px; padding: 4px 8px; border-radius: 4px; cursor: pointer;">
                        <i class="fa-solid fa-rotate"></i> Retry
                    </button>
                </div>
            `;
            document.body.appendChild(b);
        }
        b.style.display = 'flex';
    }

    function hideHeartbeatBanner() {
        const b = document.getElementById('heartbeatOfflineBanner');
        if (b) b.style.display = 'none';
    }

    window.triggerHeartbeatRetry = function() {
        if (!window.CARWASH_APP) return;
        fetch(window.CARWASH_APP.baseUrl + '/index.php?action=live_sync')
            .then(res => {
                if (res.ok) {
                    window.lastServerHeartbeatTime = Date.now();
                    hideHeartbeatBanner();
                    window.showAppToast('Connection to Shop Server restored!');
                }
            })
            .catch(() => {});
    };

    function initHeartbeatMonitor() {
        setInterval(() => {
            const elapsed = Date.now() - window.lastServerHeartbeatTime;
            if (elapsed > 60000 || !navigator.onLine) {
                showHeartbeatBanner();
            } else {
                hideHeartbeatBanner();
            }
        }, 2000);
    }

    // Start pollers once DOM is loaded
    initLiveSync();
    initCriticalSync();
    initHeartbeatMonitor();
});
