/**
 * Native Android app bridge — register push token + open links from notifications.
 */
const NativeBridge = {
    init() {
        if (typeof AndroidBridge === 'undefined') {
            return;
        }

        this.registerPushToken();

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.registerPushToken();
            }
        });
    },

    async registerPushToken() {
        try {
            const token = AndroidBridge.getFcmToken?.();
            if (!token) {
                return;
            }

            const appVersion = AndroidBridge.getAppVersion?.() || '';

            await fetch('/api/device-register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    token: token,
                    platform: 'android',
                    app_version: appVersion,
                }),
            });
        } catch (e) {
            /* silent — user may be on web */
        }
    },
};

document.addEventListener('DOMContentLoaded', () => NativeBridge.init());
