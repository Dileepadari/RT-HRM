# Not for you

An honest list of reasons to close this tab.

**You want a medical device.** This is not one and cannot be made into one. The arrhythmia detection is a heuristic over beat-to-beat intervals with a made-up confidence number attached. The "EKG chart" is drawn from RR timings; there is no electrode, no lead, no waveform. If a clinical decision would rest on the output, this is the wrong software.

**You want to monitor someone else.** It is built for one person wearing one strap, reporting to their own server. It has a single credential, no accounts, no consent flow, and no audit trail. Pointing it at another person's heartbeat is trivially easy and is not something the design defends against or supports.

**You want multi-user.** One API key, one dashboard password, one `$_SESSION['hr_auth']` boolean. Multiple *devices* work; multiple *people* do not.

**You do not want to run a server.** There is no hosted version. You need PHP, MySQL and somewhere to put them, and the app is useless without the server.

**You want it from an app store.** It is not published anywhere. Build it from source, or sideload a debug APK, and accept what that means for updates.

**You want iOS.** Android only, and the BLE and foreground-service handling is specifically shaped around Android's background restrictions.

**You want the dashboard to be maintainable.** `live.php` is one file of roughly four thousand lines holding HTML, CSS, JavaScript, SQL, and the English and Hindi translation tables. It works and it is tested at the HTTP boundary, but there is no build step, no component model, and no way to change the charts without reading a lot of it.

**You need it to survive Android's battery management untouched.** It cannot, on most phones. The app ships a per-manufacturer instruction screen precisely because the user has to go and disable optimisations by hand. Miss that and the service dies quietly in the background.

**You want strong auth.** A shared secret, compared in constant time, over whatever transport you deploy on. No rotation, no expiry, no second factor, no rate limiting on the dashboard login. Put it behind HTTPS and treat the key as the whole security model, because it is.

**You are looking for Android tests.** There are none. The PHP access gate has real coverage, the hygiene tripwires are real, and the Android side is verified by building it and using it.
