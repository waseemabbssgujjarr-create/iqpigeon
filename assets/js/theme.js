/**
 * Light / dark theme — localStorage + system preference.
 */
const Theme = {
    storageKey: 'iqpigeon_theme',

    getPreference() {
        const stored = localStorage.getItem(this.storageKey);
        return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
    },

    isDark(mode) {
        const pref = mode ?? this.getPreference();
        if (pref === 'dark') {
            return true;
        }
        if (pref === 'light') {
            return false;
        }
        return window.matchMedia('(prefers-color-scheme: dark)').matches;
    },

    apply(mode) {
        const pref = mode ?? this.getPreference();
        const dark = this.isDark(pref);
        const root = document.documentElement;

        root.classList.toggle('dark', dark);
        root.dataset.theme = dark ? 'dark' : 'light';
        root.dataset.themePreference = pref;

        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.content = dark ? '#0a0c10' : '#4aad36';
        }

        const favicon = document.getElementById('site-favicon');
        if (favicon) {
            favicon.type = 'image/png';
            const cache = favicon.href.match(/(\?v=\d+)/);
            const suffix = cache ? cache[1] : '';
            favicon.href = (dark ? '/assets/img/Fav-Icon-on-black-bg.png' : '/assets/img/Fav-Icon-on-white-bg.png') + suffix;
        }

        document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
            const icon = btn.querySelector('.material-symbols-outlined');
            if (!icon) {
                return;
            }
            icon.textContent = dark ? 'light_mode' : 'dark_mode';
        });

        document.querySelectorAll('[data-theme-option]').forEach((btn) => {
            const active = btn.dataset.themeOption === pref;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    },

    set(mode) {
        if (!['light', 'dark', 'system'].includes(mode)) {
            return;
        }
        localStorage.setItem(this.storageKey, mode);
        this.apply(mode);
    },

    toggle() {
        this.set(this.isDark() ? 'light' : 'dark');
    },

    init() {
        this.apply(this.getPreference());

        if (!window.__iqpigeonThemeMediaBound) {
            window.__iqpigeonThemeMediaBound = true;
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                if (this.getPreference() === 'system') {
                    this.apply('system');
                }
            });
        }

        document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
            if (btn.dataset.themeBound) {
                return;
            }
            btn.dataset.themeBound = '1';
            btn.addEventListener('click', () => this.toggle());
        });

        document.querySelectorAll('[data-theme-option]').forEach((btn) => {
            if (btn.dataset.themeBound) {
                return;
            }
            btn.dataset.themeBound = '1';
            btn.addEventListener('click', () => this.set(btn.dataset.themeOption));
        });
    },
};

if (typeof window !== 'undefined') {
    window.Theme = Theme;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => Theme.init());
    } else {
        Theme.init();
    }
}
