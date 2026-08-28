# Firebase Push Notifications — IQ Pigeon Android

## 1. Firebase project

1. Go to [Firebase Console](https://console.firebase.google.com)
2. Create or select your project
3. **Add app** → **Android**
   - Package name: **`com.iqpigeon.app`** (must match exactly)
   - App nickname: IQ Pigeon
4. Download **`google-services.json`**
5. Place at: `android-app/app/google-services.json`
6. Sync Gradle in Android Studio

## 2. Server-side FCM (PHP)

1. Firebase → Project settings → **Service accounts**
2. **Generate new private key** → save JSON **outside** your web root
3. In `config.php`:

```php
define('FCM_PROJECT_ID', 'your-firebase-project-id');
define('FCM_SERVICE_ACCOUNT_PATH', '/home/user/firebase-service-account.json');
```

4. Deploy updated `config.php` to **https://iqpigeon.com**

## 3. Register device tokens

After a user logs in on the Android app, the WebView registers the FCM token with your backend (via existing push API).

Run migration once if upgrading from the old app:

```
https://iqpigeon.com/migrate-remember-push.php
```

## 4. Test

1. Install debug APK on a device
2. Log in as a client user
3. Trigger a lead alert from WhatsApp or admin
4. Confirm notification appears; tap opens the correct deep link

## Package change note

If you change the Android package name, create a **new** Firebase Android app entry with `com.iqpigeon.app` and replace `google-services.json`. Old tokens from a previous package will not work.
