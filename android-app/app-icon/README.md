# App icon — where to put your PNG

## 1. Copy your PNG here (recommended)

Save your icon file as:

```
android-app/app-icon/app-icon.png
```

**Recommended size:** 1024×1024 px (square, PNG, transparent or solid background)

You can use any filename, but `app-icon.png` keeps things simple.

---

## 2. Set icon in Android Studio (generates all sizes)

1. Open project: `android-app` in Android Studio
2. Menu: **File → New → Image Asset**
   - Or right-click **`app`** folder → **New → Image Asset**
3. **Icon Type:** `Launcher Icons (Adaptive and Legacy)`
4. **Name:** `ic_launcher` (keep default)
5. **Foreground Layer**
   - **Source Asset Type:** `Image`
   - Click folder icon → select your PNG:
     ```
     C:\Users\dell\Desktop\ai Sales Setter\android-app\app-icon\app-icon.png
     ```
   - **Trim:** Yes (usually)
   - **Resize:** 70–85% (adjust so logo isn’t cropped on round icons)
6. **Background Layer**
   - **Source Asset Type:** `Color`
   - **Color:** `#FFFFFF` (white — matches Fav-Icon-White.svg / IQ Pigeon brand)
   - Or use `#4aad36` if you prefer green background
7. Click **Next** → **Finish**

Android Studio writes icons into:

```
android-app/app/src/main/res/mipmap-mdpi/
android-app/app/src/main/res/mipmap-hdpi/
android-app/app/src/main/res/mipmap-xhdpi/
android-app/app/src/main/res/mipmap-xxhdpi/
android-app/app/src/main/res/mipmap-xxxhdpi/
android-app/app/src/main/res/mipmap-anydpi-v26/
```

8. **Rebuild APK:** **Build → Build Bundle(s) / APK(s) → Build APK(s)**

9. **If the old placeholder icon still shows on your phone**
   - The app manifest must use `@mipmap/ic_launcher` (not `@drawable/ic_launcher`). This is already fixed in the project.
   - **Uninstall** the old app from your phone first — Android caches launcher icons.
   - Install the **new** APK you just built.
   - If you used Image Asset but still see a green square with a white “O”, the manifest was pointing at the wrong resource; rebuild after pulling the latest project files.

---

## 3. Play Store icon (separate upload — NOT in the APK project)

For Google Play Console store listing, upload a **512×512 PNG** directly in:

**Play Console → Your app → Main store listing → App icon**

This is separate from the launcher icon inside the app. You can export the same design at 512×512.

---

## Quick checklist

- [ ] PNG copied to `android-app/app-icon/app-icon.png`
- [ ] Image Asset wizard run (Foreground = your PNG, Background = #FFFFFF or #4aad36)
- [ ] Rebuild APK and install on phone — check home screen icon
- [ ] 512×512 PNG ready for Play Console store listing
