# Rebuild Android APK for iqpigeon.com

Build guide for the IQ Pigeon Android app (**https://iqpigeon.com**).

The app is a WebView wrapper — it loads your live site. Native code must use package **`com.iqpigeon.app`** and host **`iqpigeon.com`**.

---

## Production values

| Item | Value |
|------|-------|
| Domain | `iqpigeon.com` |
| Package | `com.iqpigeon.app` |
| App name | IQ Pigeon |
| User-Agent suffix | `IQPigeonApp` |
| Start URL | `https://iqpigeon.com/login.php` |

---

## Prerequisites

1. **Android Studio** (latest stable) — https://developer.android.com/studio  
2. **JDK 17** (bundled with Android Studio)  
3. Site live at **https://iqpigeon.com** with valid SSL  
4. **Firebase** (optional, for push): project with Android app package `com.iqpigeon.app`

---

## Step 1 — Open the project

1. Android Studio → **File → Open**
2. Select folder: `android-app` (inside this repo)
3. Wait for **Gradle Sync** to finish

If sync fails, install **SDK 35** via SDK Manager.

---

## Step 2 — Verify domain settings

Open `app/build.gradle.kts` and confirm:

```kotlin
applicationId = "com.iqpigeon.app"
buildConfigField("String", "APP_URL", "\"https://iqpigeon.com/login.php\"")
buildConfigField("String", "ALLOWED_HOST_SUFFIX", "\"iqpigeon.com\"")
```

Deep links are in `app/src/main/AndroidManifest.xml`:

```xml
android:host="iqpigeon.com"
```

---

## Step 3 — Firebase (push notifications)

Because the package name changed, you need a **new** Firebase Android app (or update the existing one):

1. [Firebase Console](https://console.firebase.google.com) → your project  
2. **Add app** → Android  
3. Package name: **`com.iqpigeon.app`** (must match exactly)  
4. Download **`google-services.json`**  
5. Place it at: `android-app/app/google-services.json`  
6. Sync Gradle again  

On the server, set in `config.php`:

```php
define('FCM_PROJECT_ID', 'your-firebase-project-id');
define('FCM_SERVICE_ACCOUNT_PATH', '/path/outside/webroot/firebase-service-account.json');
```

See `PUSH_SETUP.md` for full push configuration.

---

## Step 4 — Clean old build artifacts

In Android Studio: **Build → Clean Project**, then **Build → Rebuild Project**.

Or from `android-app/` (if `gradlew.bat` exists):

```bat
gradlew.bat clean assembleDebug
```

---

## Step 5 — Test on device (debug APK)

1. Enable **USB debugging** on your Android phone  
2. Connect via USB  
3. Click **Run ▶** in Android Studio  
4. Confirm the app opens **https://iqpigeon.com/login.php**  
5. Log in, test dashboard, pull-to-refresh, back button  

**Debug APK output** (typical):

```
android-app/app/build/outputs/apk/debug/app-debug.apk
```

---

## Step 6 — Copy APK to website download

For the landing page “Download app” button, copy the debug or release APK to:

```
downloads/sales-app.apk
```

(relative to the PHP project root on your server)

Users sideload this file; Play Store uses an AAB (Step 8).

---

## Step 7 — Release signing (Play Store or production sideload)

### Create keystore (first time only)

```bat
keytool -genkey -v -keystore iqpigeon-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias iqpigeon
```

Store the `.jks` file and passwords securely.

### Build signed release

1. **Build → Generate Signed App Bundle / APK**  
2. Choose **APK** (sideload) or **Android App Bundle** (Play Store)  
3. Select keystore, alias, passwords  
4. **release** build type  

**Release APK path** (typical):

```
android-app/app/release/app-release.apk
```

Before each store upload, bump in `app/build.gradle.kts`:

```kotlin
versionCode = 2        // integer, must increase every upload
versionName = "1.0.1"
```

---

## Step 8 — Google Play (optional)

- **New Play Store listing** if this is your first publish under `com.iqpigeon.app` (package names cannot change on an existing app).  
- App name: **IQ Pigeon**  
- Privacy policy: `https://iqpigeon.com/about.php` (or your privacy page)  
- Upload `app-release.aab` to Internal testing first  

For **App Links** verification, host this file on your site:

```
https://iqpigeon.com/.well-known/assetlinks.json
```

Include your app’s SHA-256 signing certificate fingerprint and package `com.iqpigeon.app`.

---

## Step 9 — Server checklist (same deploy as website)

After deploying PHP to **iqpigeon.com**:

| Service | Update to |
|---------|-----------|
| Meta WhatsApp webhook | `https://iqpigeon.com/api/whatsapp-webhook.php` |
| Webhook verify token | Value of `WEBHOOK_VERIFY_TOKEN` in `config.local.php` |
| Meta OAuth redirect URIs | `https://iqpigeon.com/client/whatsapp-oauth-callback.php` |
| Stripe webhooks | `https://iqpigeon.com/api/stripe-webhook.php` |
| Cron job URL | `https://iqpigeon.com/api/cron.php?key=YOUR_CRON_SECRET` |
| SMTP / from address | `info@iqpigeon.com` |

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| White screen | Open `https://iqpigeon.com/login.php` in Chrome on the same device |
| SSL error | Ensure valid certificate on `iqpigeon.com`; check `network_security_config.xml` |
| Login not saved | Don’t clear app data; cookies are enabled in `MainActivity` |
| Push not working | New `google-services.json` for `com.iqpigeon.app`; FCM keys in `config.php` |
| Old app still installed | Uninstall any previous IQ Pigeon APK with a different package — `com.iqpigeon.app` installs side-by-side |
| Gradle package errors | **Build → Clean Project** after package rename |

---

## Quick command reference

```bat
cd android-app
gradlew.bat clean assembleDebug
gradlew.bat assembleRelease
```

APK for website download → copy to `downloads/sales-app.apk` on the server.
