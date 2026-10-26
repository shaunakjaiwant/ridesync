/**
 * RideSync - Instant Navigation & Tab Switching Engine
 * 
 * Provides instantaneous (0-20ms) tab switching and SPA page transitions
 * with touch prefetching, DOM morphing, active state prediction, and history management.
 */

(function () {
    'use strict';

    if (window.__ridesyncInstantNavInitialized) return;
    window.__ridesyncInstantNavInitialized = true;

    // Cache storage: Map<url, { title, bodyClass, content, mainSelector, timestamp }>
    var pageCache = new Map();
    var MAX_CACHE_ENTRIES = 20;
    var CACHE_TTL_MS = 60000; // 60 seconds
    var prefetchInFlight = new Set();
    var isNavigating = false;

    // Create top progress bar (Nanobar)
    var nanobar = document.createElement('div');
    nanobar.id = 'ridesync-nanobar';
    nanobar.setAttribute('aria-hidden', 'true');
    nanobar.style.cssText = 'position:fixed;top:0;left:0;height:3px;width:0%;background:linear-gradient(90deg,#2563eb,#38bdf8,#6366f1);box-shadow:0 0 10px rgba(56,189,248,0.7);z-index:999999;transition:width 0.2s ease, opacity 0.3s ease;pointer-events:none;opacity:0;';
    document.documentElement.appendChild(nanobar);

    function startProgress() {
        nanobar.style.opacity = '1';
        nanobar.style.width = '35%';
        setTimeout(function () {
            if (isNavigating) nanobar.style.width = '75%';
        }, 150);
    }

    function finishProgress() {
        nanobar.style.width = '100%';
        setTimeout(function () {
            nanobar.style.opacity = '0';
            setTimeout(function () {
                nanobar.style.width = '0%';
            }, 300);
        }, 120);
    }

    function normalizeUrl(href) {
        try {
            var u = new URL(href, window.location.href);
            u.hash = '';
            return u;
        } catch (e) {
            return null;
        }
    }

    function getClosestLink(target) {
        if (!target) return null;
        if (typeof target.closest === 'function') return target.closest('a');
        if (target.parentElement && typeof target.parentElement.closest === 'function') {
            return target.parentElement.closest('a');
        }
        return null;
    }

    function isEligibleLink(link) {
        if (!link || !link.href) return false;
        if (link.getAttribute('target') === '_blank') return false;
        if (link.hasAttribute('download')) return false;
        if (link.dataset.instant === 'off') return false;
        if (link.getAttribute('role') === 'button') return false;

        var url = normalizeUrl(link.href);
        if (!url) return false;
        if (url.origin !== window.location.origin) return false;
        if (url.href === normalizeUrl(window.location.href).href) return false;

        var path = url.pathname;
        // Do not intercept logout, actions, api, or asset downloads
        if (path.includes('/actions/') || path.includes('/api/') || path.includes('logout')) {
            return false;
        }

        // If target page requires Leaflet maps and Leaflet is not yet loaded on current page, allow standard full-page load
        var requiresMaps = path.includes('search_rides') || path.includes('post_ride') || path.includes('ride_detail') || path.includes('admin_dashboard');
        if (requiresMaps && typeof window.L === 'undefined') {
            return false;
        }

        // Only intercept app pages and index
        if (path.startsWith('/ridesync/pages/') || path.endsWith('/ridesync/') || path.endsWith('/ridesync/index.php')) {
            return true;
        }

        return false;
    }

    function getMainContainer(doc) {
        return doc.querySelector('main.main-content, main.driver-main, main.admin-main, main, .main-content, .driver-main, .admin-main');
    }

    function parsePage(htmlString) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(htmlString, 'text/html');
        var main = getMainContainer(doc);
        if (!main) return null;

        return {
            title: doc.title || document.title,
            bodyClass: doc.body.className || '',
            contentHtml: main.innerHTML,
            mainClass: main.className || '',
            unreadBadge: (function () {
                var badge = doc.querySelector('.nav-badge');
                return badge ? badge.textContent.trim() : null;
            })()
        };
    }

    function prefetchUrl(href) {
        var url = normalizeUrl(href);
        if (!url) return Promise.resolve(null);

        var key = url.href;
        var now = Date.now();
        if (pageCache.has(key)) {
            var cached = pageCache.get(key);
            if (now - cached.timestamp < CACHE_TTL_MS) {
                return Promise.resolve(cached.data);
            }
        }

        if (prefetchInFlight.has(key)) return Promise.resolve(null);
        prefetchInFlight.add(key);

        return fetch(url.href, {
            headers: { 'X-Requested-With': 'RideSync-Instant' },
            credentials: 'same-origin'
        })
        .then(function (res) {
            if (!res.ok) throw new Error('Fetch failed: ' + res.status);
            return res.text();
        })
        .then(function (html) {
            prefetchInFlight.delete(key);
            var parsed = parsePage(html);
            if (parsed) {
                if (pageCache.size >= MAX_CACHE_ENTRIES) {
                    var firstKey = pageCache.keys().next().value;
                    pageCache.delete(firstKey);
                }
                pageCache.set(key, { data: parsed, timestamp: Date.now() });
            }
            return parsed;
        })
        .catch(function () {
            prefetchInFlight.delete(key);
            return null;
        });
    }

    function updateActiveNavigationStates(targetPath) {
        var allNavLinks = document.querySelectorAll(
            '.mobile-bottom-nav a[href], .nav-links a[href], .driver-nav-links a[href], ' +
            '.admin-side-nav a[href], .segmented-tab-nav a[href], .auth-role-tabs a[href]'
        );

        allNavLinks.forEach(function (link) {
            var linkUrl = normalizeUrl(link.href);
            if (!linkUrl) return;

            var isCurrent = linkUrl.pathname === targetPath;
            link.classList.toggle('is-active', isCurrent);
            link.classList.toggle('active', isCurrent);

            if (isCurrent) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    function reinitializePageComponents() {
        try {
            if (typeof window.initTabs === 'function') window.initTabs();
            if (typeof window.initResponsiveDataTables === 'function') window.initResponsiveDataTables();
            if (typeof window.initMobileActiveNavigation === 'function') window.initMobileActiveNavigation();
            if (typeof window.initAdminPanelFilters === 'function') window.initAdminPanelFilters();
            if (typeof window.initSmartSearchSuggestions === 'function') window.initSmartSearchSuggestions();
            if (typeof window.initFareSliders === 'function') window.initFareSliders();
            if (typeof window.initLiveRideStatus === 'function') window.initLiveRideStatus();
            if (typeof window.initDriverLiveStatus === 'function') window.initDriverLiveStatus();
            if (typeof window.initEmailValidation === 'function') window.initEmailValidation();
            if (typeof window.initConfirmActions === 'function') window.initConfirmActions();
            if (typeof window.initPasswordToggles === 'function') window.initPasswordToggles();
            if (typeof window.initButtonLoading === 'function') window.initButtonLoading();
            if (typeof window.initTripShare === 'function') window.initTripShare();

            // Rebind map pickers if leaflet is loaded
            if (typeof window.L !== 'undefined') {
                if (typeof window.initMapPicker === 'function') window.initMapPicker();
            }

            // Dispatch global event for custom page widgets
            window.dispatchEvent(new CustomEvent('ridesync:navigated', {
                detail: { url: window.location.href }
            }));
        } catch (err) {
            console.warn('[RideSync Instant] Reinitialization warning:', err);
        }
    }

    function navigateTo(href, pushState) {
        var url = normalizeUrl(href);
        if (!url) {
            window.location.href = href;
            return;
        }

        var key = url.href;

        // Visual feedback immediately on the clicked tab (0ms latency response)
        updateActiveNavigationStates(url.pathname);

        isNavigating = true;
        var startTimer = setTimeout(startProgress, 40);

        function applyParsedPage(pageData) {
            clearTimeout(startTimer);
            var currentMain = getMainContainer(document);

            if (!currentMain) {
                window.location.href = url.href;
                return;
            }

            // Smooth fade micro-transition
            currentMain.style.opacity = '0';
            currentMain.style.transform = 'translateY(4px)';
            currentMain.style.transition = 'opacity 0.12s ease, transform 0.12s ease';

            setTimeout(function () {
                document.title = pageData.title;
                if (pageData.bodyClass) {
                    document.body.className = pageData.bodyClass;
                }
                if (pageData.mainClass) {
                    currentMain.className = pageData.mainClass;
                }

                currentMain.innerHTML = pageData.contentHtml;

                if (pushState) {
                    window.history.pushState({ ridesync: true, href: url.href }, pageData.title, url.href);
                }

                // Scroll smoothly to top
                window.scrollTo(0, 0);

                // Reveal new content
                currentMain.style.opacity = '1';
                currentMain.style.transform = 'translateY(0)';

                updateActiveNavigationStates(url.pathname);
                reinitializePageComponents();
                finishProgress();
                isNavigating = false;
            }, 60);
        }

        // Check if already in cache
        if (pageCache.has(key)) {
            var cached = pageCache.get(key);
            if (Date.now() - cached.timestamp < CACHE_TTL_MS) {
                applyParsedPage(cached.data);
                return;
            }
        }

        // Not in cache: fetch now
        prefetchUrl(url.href).then(function (pageData) {
            if (pageData) {
                applyParsedPage(pageData);
            } else {
                // Fallback to normal navigation if fetch failed
                finishProgress();
                isNavigating = false;
                window.location.href = url.href;
            }
        });
    }

    // Attach delegated events to document for maximum speed and instant coverage
    document.addEventListener('pointerdown', function (e) {
        var link = getClosestLink(e.target);
        if (isEligibleLink(link)) {
            prefetchUrl(link.href);
        }
    }, { passive: true });

    document.addEventListener('touchstart', function (e) {
        var link = getClosestLink(e.target);
        if (isEligibleLink(link)) {
            prefetchUrl(link.href);
        }
    }, { passive: true });

    document.addEventListener('pointerenter', function (e) {
        var link = getClosestLink(e.target);
        if (isEligibleLink(link)) {
            prefetchUrl(link.href);
        }
    }, { passive: true });

    document.addEventListener('click', function (e) {
        // Ignore modified clicks (Ctrl+Click, Cmd+Click, middle click)
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }

        var link = getClosestLink(e.target);
        if (!isEligibleLink(link)) return;

        e.preventDefault();
        navigateTo(link.href, true);
    });

    // Handle browser back/forward buttons (popstate)
    window.addEventListener('popstate', function () {
        navigateTo(window.location.href, false);
    });

    // Warm up current page into cache
    var currentMain = getMainContainer(document);
    if (currentMain) {
        pageCache.set(normalizeUrl(window.location.href).href, {
            data: {
                title: document.title,
                bodyClass: document.body.className || '',
                contentHtml: currentMain.innerHTML,
                mainClass: currentMain.className || ''
            },
            timestamp: Date.now()
        });
    }

    // Prefetch all visible tabs in bottom navigation and top navbar after load
    window.addEventListener('load', function () {
        setTimeout(function () {
            var priorityTabs = document.querySelectorAll('.mobile-bottom-nav a[href], .nav-links a[href], .driver-nav-links a[href]');
            priorityTabs.forEach(function (tab) {
                if (isEligibleLink(tab)) {
                    prefetchUrl(tab.href);
                }
            });
        }, 300);
    });

    // Expose API
    window.RideSyncInstant = {
        prefetch: prefetchUrl,
        navigate: function (href) { navigateTo(href, true); },
        clearCache: function () { pageCache.clear(); }
    };

})();
