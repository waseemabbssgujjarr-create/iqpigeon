# IQ Pigeon — Android App Wrapper

Native Android WebView shell that loads your live SaaS at **https://iqpigeon.com**.

No website code is bundled inside the APK — the app is a secure wrapper around your hosted PHP app. Updates to the website appear in the app instantly without republishing (unless you change native code or the start URL).

**Rebuild guide after domain migration:** see **[REBUILD.md](REBUILD.md)**.

---

## What is included

| Feature | Details |
|---------|---------|
| WebView | Loads `/login.php` on launch |
| Sessions | Cookies + localStorage enabled (login persists) |
| Back button | Goes back in WebView history |
| Pull to refresh | Swipe down to reload |
| External links | Stripe, Meta OAuth, etc. open in Chrome |
| HTTPS only | No cleartext traffic |
| Deep links | Opens `https://iqpigeon.com/*` in the app |
| Push (optional) | Firebase Cloud Messaging — see `PUSH_SETUP.md` |

**Package name:** `com.iqpigeon.app`  
**Project folder:** `android-app/` (inside your repo)

---

## Prerequisites (one-time setup)

1. **Android Studio** (latest stable) — https://developer.android.com/studio  
2. **JDK 17** — Android Studio includes it (Settings → Build → Gradle → JDK 17).  
3. **Google Play Developer account** ($25 one-time) — https://play.google.com/console/signup  

---

## Step 1 — Open the project

1. Open **Android Studio**
2. **File → Open**
3. Select folder: `android-app` inside this repo
4. Wait for **Gradle Sync** to finish (first time may take 5–10 minutes)

If Gradle asks for SDK 35, click **Install**.

---

## Step 2 — Run on your phone (test)

1. On your Android phone: **Settings → Developer options → USB debugging** (ON)
2. Connect phone via USB
3. In Android Studio, click the green **Run ▶** button
4. Select your device

The app should open and show the login page at **iqpigeon.com**.

**Or use an emulator:** Device Manager → Create Virtual Device → Pixel 6 → API 34+.

---

## Step 3 — Change start URL (optional)

Default start page is the client login screen.

Edit `app/build.gradle.kts`:

```kotlin
buildConfigField("String", "APP_URL", "\"https://iqpigeon.com/login.php\"")
```

Examples:

- Client login: `.../login.php`
- Marketing home: `.../` or `.../index.php`
- Client dashboard (only if already logged in): `.../client/dashboard.php`

After changing, click **Sync Project with Gradle Files**, then rebuild.

---

## Step 4 — App icon

Launcher icons use brand green `#4aad36` on white. To customize:

1. **File → New → Image Asset**
2. **Icon Type:** Launcher Icons (Adaptive and Legacy)
3. **Foreground:** upload your logo PNG (512×512)
4. **Background color:** `#ffffff`
5. **Next → Finish**

See `app-icon/README.md` for details.

---

## Step 5 — Create signing key (required for Play Store)

Do this once. **Keep the keystore file safe** — you cannot update the app without it.

### Option A — Android Studio (easiest)

1. **Build → Generate Signed App Bundle / APK**
2. Choose **Android App Bundle**
3. **Create new...** keystore
4. Save as e.g. `iqpigeon-release.jks`
5. Set alias + passwords (write them down securely)
6. Build **release** variant

### Option B — Command line

From `android-app/` folder:

```bat
keytool -genkey -v -keystore iqpigeon-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias iqpigeon
```

---

## Step 6 — Build release AAB (upload file for Play Store)

1. **Build → Generate Signed App Bundle / APK**
2. **Android App Bundle** → Next
3. Select your keystore + passwords
4. **release** build type → **Create**

Output path (typical):

```
android-app/app/release/app-release.aab
```

This `.aab` file is what you upload to Google Play.

For direct APK download on your website, copy a signed APK to `downloads/sales-app.apk` on the server.

---

## Step 7 — Publish on Google Play Console

1. Go to https://play.google.com/console
2. **Create app**
   - App name: **IQ Pigeon**
   - Default language: English
   - App / Game: App
   - Free or paid: your choice
3. Complete **Dashboard** checklist:

### Required store listing

- **Short description** (80 chars max)
- **Full description**
- **App icon** 512×512 PNG
- **Feature graphic** 1024×500 PNG
- **Phone screenshots** (at least 2)
- **Privacy policy URL** — e.g. `https://iqpigeon.com/about.php`

### App content

- Privacy policy
- Data safety form
- Content rating questionnaire
- Target audience

### Release

1. **Testing → Internal testing** (recommended first)
2. **Create new release**
3. Upload `app-release.aab`
4. Add release notes → **Review release → Start rollout**

---

## Step 8 — Website integration

The Android app loads your **already hosted** site. Ensure:

- HTTPS valid certificate on **iqpigeon.com**
- Login / register / client dashboard responsive
- Stripe checkout opens in external browser (handled by app)

Detect app users in PHP via User-Agent suffix `IQPigeonApp`:

```php
function is_native_app(): bool
{
    return stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'IQPigeonApp') !== false;
}
```

---

## Version updates (after first publish)

When you fix native code or change `APP_URL`:

1. In `app/build.gradle.kts` increment:
   ```kotlin
   versionCode = 2        // must increase every upload
   versionName = "1.0.1"
   ```
2. Build new signed AAB
3. Upload to Play Console → new release

Website-only changes do **not** require a new APK.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Gradle sync failed | Install SDK 35; use JDK 17 |
| White screen | Check phone internet; open URL in Chrome |
| Login not saved | Ensure cookies enabled; don't clear WebView data |
| Stripe / Meta login fails | External browser should open automatically |
| Push not working | Add `google-services.json` for `com.iqpigeon.app`; see `PUSH_SETUP.md` |

---

## File map

```
android-app/
├── app/src/main/
│   ├── AndroidManifest.xml       Permissions + deep links
│   ├── java/com/iqpigeon/app/    MainActivity, push, WebView bridge
│   └── res/                      Icons, layout, colors
├── app/build.gradle.kts          versionCode, APP_URL
├── REBUILD.md                    Domain migration + APK rebuild guide
├── PUSH_SETUP.md                 Firebase push setup
└── README.md                     This guide
```

---

## Quick checklist

- [ ] Open `android-app` in Android Studio
- [ ] Run on phone — login works at iqpigeon.com
- [ ] Add `google-services.json` if using push
- [ ] Create signing keystore (backup safely)
- [ ] Build signed AAB or APK
- [ ] Copy APK to `downloads/sales-app.apk` on server (optional)
- [ ] Create Play Console app + store listing
- [ ] Upload AAB to Internal testing
- [ ] Roll out to Production
