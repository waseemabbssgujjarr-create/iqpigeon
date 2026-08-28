/**
 * Marketing site interactions — scroll reveal, bottom nav, counters, smooth scroll.
 */
document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('.marketing-header');
    const bottomNav = document.getElementById('marketing-bottom-nav');
    const heroVideo = document.querySelector('.marketing-hero__video');

    if (heroVideo) {
        heroVideo.playbackRate = 0.85;
        const tryPlay = () => heroVideo.play().catch(() => {});
        tryPlay();
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) tryPlay();
        });
    }

    const syncHeaderScroll = () => {
        if (!header) return;
        const hippoOrHero = document.body.classList.contains('marketing-page--hero')
            || document.body.classList.contains('marketing-page--hippo');
        if (!hippoOrHero) return;
        header.classList.toggle('is-scrolled', window.scrollY > 48);
    };
    syncHeaderScroll();
    window.addEventListener('scroll', syncHeaderScroll, { passive: true });

    const normalizePath = (path) => {
        const p = (path || '/').replace(/\/$/, '') || '/';
        if (p === '/index.php') return '/';
        return p;
    };

    const isHomePath = (path) => normalizePath(path) === '/';

    if (header) {
        document.body.classList.add('marketing-page');

        const syncHeaderHeight = () => {
            document.documentElement.style.setProperty('--marketing-header-h', `${header.offsetHeight}px`);
        };
        syncHeaderHeight();
        window.addEventListener('resize', syncHeaderHeight);
    }

    const marketingMenuOverlay = document.getElementById('marketing-mobile-menu-overlay');
    const marketingMenuDrawer = document.getElementById('marketing-mobile-menu');
    const marketingMenuOpenBtn = document.querySelector('[data-marketing-menu-open]');

    const setMarketingMenuOpen = (open) => {
        if (!marketingMenuOverlay || !marketingMenuDrawer) return;
        marketingMenuOverlay.classList.toggle('open', open);
        marketingMenuDrawer.classList.toggle('open', open);
        marketingMenuOverlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        marketingMenuDrawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (marketingMenuOpenBtn) {
            marketingMenuOpenBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        document.body.style.overflow = open ? 'hidden' : '';
    };

    marketingMenuOpenBtn?.addEventListener('click', () => setMarketingMenuOpen(true));
    document.querySelectorAll('[data-marketing-menu-close]').forEach(el => {
        el.addEventListener('click', () => setMarketingMenuOpen(false));
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && marketingMenuDrawer?.classList.contains('open')) {
            setMarketingMenuOpen(false);
        }
    });

    const scrollToSection = (hash) => {
        if (!hash || hash === '#') return false;
        const id = hash.replace(/^#/, '');
        const target = document.getElementById(id);
        if (!target) return false;

        const headerHeight = header ? header.offsetHeight : 72;
        const top = target.getBoundingClientRect().top + window.scrollY - headerHeight - 12;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });

        const newUrl = `${window.location.pathname}${window.location.search}${hash}`;
        history.replaceState(null, '', newUrl);
        return true;
    };

    document.querySelectorAll('.nav-scroll[href*="#"]').forEach(link => {
        link.addEventListener('click', (e) => {
            const url = new URL(link.href, window.location.origin);
            const hash = url.hash;
            if (!hash) return;

            const targetPath = normalizePath(url.pathname);
            const currentPath = normalizePath(window.location.pathname);
            const sameHome = isHomePath(targetPath) && isHomePath(currentPath);
            const samePage = targetPath === currentPath;

            if ((samePage || sameHome) && scrollToSection(hash)) {
                e.preventDefault();
            }
        });
    });

    if (window.location.hash) {
        requestAnimationFrame(() => {
            setTimeout(() => scrollToSection(window.location.hash), 50);
        });
    }

    const fadeEls = document.querySelectorAll('.fade-up');
    if (fadeEls.length && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    setTimeout(() => entry.target.classList.add('visible'), i * 80);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        fadeEls.forEach(el => observer.observe(el));
    } else {
        fadeEls.forEach(el => el.classList.add('visible'));
    }

    document.querySelectorAll('[data-count]').forEach(el => {
        const target = parseInt(el.dataset.count, 10);
        if (isNaN(target)) return;

        const observer = new IntersectionObserver(([entry]) => {
            if (!entry.isIntersecting) return;
            observer.disconnect();
            let current = 0;
            const step = Math.ceil(target / 40);
            const timer = setInterval(() => {
                current += step;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                el.textContent = current.toLocaleString() + (el.dataset.suffix || '');
            }, 30);
        }, { threshold: 0.5 });
        observer.observe(el);
    });

    document.querySelectorAll('.mockup-bar').forEach((bar, i) => {
        bar.style.animationDelay = (i * 0.1) + 's';
    });

    document.querySelectorAll('.chat-bubble-demo').forEach((bubble, i) => {
        bubble.style.animationDelay = (0.3 + i * 0.4) + 's';
    });

    const sectionIds = ['hero', 'features', 'how-it-works', 'integrations', 'pricing', 'demo', 'about', 'contact'];
    const desktopNavLinks = document.querySelectorAll('.marketing-header .nav-link[data-nav-section]');
    const bottomNavLinks = bottomNav ? bottomNav.querySelectorAll('[data-nav-section]') : [];

    const setNavActive = (sectionKey) => {
        desktopNavLinks.forEach(link => {
            const active = link.dataset.navSection === sectionKey;
            link.classList.toggle('bg-primary-container/20', active);
            link.classList.toggle('text-primary', active);
            link.classList.toggle('font-medium', active);
        });
        bottomNavLinks.forEach(link => {
            const active = link.dataset.navSection === sectionKey;
            link.classList.toggle('active', active);
            if (active) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    if ((desktopNavLinks.length || bottomNavLinks.length) && 'IntersectionObserver' in window) {
        const sectionObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const id = entry.target.id;
                const sectionKey = id === 'hero' ? 'home' : id;
                setNavActive(sectionKey);
            });
        }, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });

        sectionIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) sectionObserver.observe(el);
        });
    }

    bottomNavLinks.forEach(link => {
        link.addEventListener('click', () => {
            const key = link.dataset.navSection;
            if (key) setNavActive(key);
        });
    });

    const subscribeForm = document.getElementById('footer-subscribe-form');
    const subscribeMsg = document.getElementById('footer-subscribe-msg');
    if (subscribeForm) {
        subscribeForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = subscribeForm.querySelector('[name="email"]')?.value?.trim();
            if (!email) return;

            const btn = subscribeForm.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;

            try {
                const csrf = subscribeForm.querySelector('[name="csrf_token"]')?.value || '';
                const res = await fetch('/api/subscribe.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'subscribe', email, csrf_token: csrf }),
                });
                const data = await res.json();
                if (subscribeMsg) {
                    subscribeMsg.textContent = data.message || (data.success ? 'Subscribed!' : 'Could not subscribe.');
                    subscribeMsg.classList.remove('hidden');
                    subscribeMsg.classList.toggle('marketing-footer__heading', !!data.success);
                    subscribeMsg.classList.toggle('text-error-container', !data.success);
                }
                if (data.success) subscribeForm.reset();
            } catch {
                if (subscribeMsg) {
                    subscribeMsg.textContent = 'Something went wrong. Please try again.';
                    subscribeMsg.classList.remove('hidden');
                }
            } finally {
                if (btn) btn.disabled = false;
            }
        });
    }

    document.querySelectorAll('.hip-faq__list').forEach((list) => {
        list.addEventListener('click', (e) => {
            const summary = e.target.closest('.hip-faq__summary');
            if (!summary || !list.contains(summary)) return;

            const item = summary.closest('.hip-faq__item');
            if (!item) return;

            // Click on an already-open item → let the browser close it only.
            if (item.hasAttribute('open')) return;

            list.querySelectorAll('.hip-faq__item[open]').forEach((openItem) => {
                openItem.removeAttribute('open');
            });
        }, true);
    });

    const roiRoot = document.getElementById('hip-roi');
    if (roiRoot) {
        const leadsInput = roiRoot.querySelector('[data-roi-leads]');
        const dealInput = roiRoot.querySelector('[data-roi-deal]');
        const liftInput = roiRoot.querySelector('[data-roi-lift]');
        const leadsVal = roiRoot.querySelector('[data-roi-leads-val]');
        const dealVal = roiRoot.querySelector('[data-roi-deal-val]');
        const liftVal = roiRoot.querySelector('[data-roi-lift-val]');
        const output = roiRoot.querySelector('[data-roi-output]');
        const currency = roiRoot.dataset.currency || 'PKR';

        const formatMoney = (amount) => {
            const rounded = Math.round(amount);
            if (currency === 'PKR') {
                return 'PKR ' + rounded.toLocaleString('en-PK');
            }
            return '$' + rounded.toLocaleString('en-US');
        };

        const updateRoi = () => {
            const leads = parseInt(leadsInput?.value || '0', 10);
            const deal = parseInt(dealInput?.value || '0', 10);
            const lift = parseInt(liftInput?.value || '0', 10);
            if (leadsVal) leadsVal.textContent = String(leads);
            if (dealVal) dealVal.textContent = String(deal);
            if (liftVal) liftVal.textContent = String(lift);
            const extra = leads * deal * (lift / 100);
            if (output) output.textContent = formatMoney(extra);
        };

        [leadsInput, dealInput, liftInput].forEach((el) => {
            el?.addEventListener('input', updateRoi);
        });
        updateRoi();
    }
});
