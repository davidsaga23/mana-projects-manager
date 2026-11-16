# Desktop notifications, and Anima as an app

David, 2026-11-16, chose "desktop notifications with sound". His wish (2026-11-12): *"let's enable
desktop notifications so we can send sounds and reminders"*, and *"for the desktop notifications we
can ask them to install the webpage as an app in windows so it always opens on the startup"*.

## Turning them on (each person, on each computer)

1. In Anima, open the **bell** beside your name and click **Turn on desktop notifications** at its
   foot. In the Realm, click **Notifications: off** in the top bar.
2. A small window explains what you will get. Click **Turn on desktop notifications** again.
3. The browser asks whether Anima may show notifications: choose **Allow**. A first notification
   confirms it works.

The same window lets you switch off any kind (ravens, tags, knocks, calls, reminders, task changes),
mute the sound, send a test, or turn everything off. The choices are kept on that computer only.

If the browser was told **Block** at some point, the window says so: click the padlock at the left of
the address bar, set Notifications to **Allow**, and reload.

## Install Anima as an app, and open it when Windows starts

Notifications only arrive while at least one Anima tab or window is open. Installing Anima and
opening it at start-up makes that automatic.

**Edge** (recommended):
1. Open https://anima-mana.com and sign in.
2. Click the **App available / Install** icon at the right of the address bar (a small screen with an
   arrow), or the **…** menu → **Apps** → **Install Anima**.
3. Anima opens in its own window, with an icon in the Start menu and the taskbar (right-click it →
   Pin to taskbar).
4. Go to `edge://apps`, click the **…** on Anima, and turn on **Start automatically on device login**.

**Chrome**:
1. Open https://anima-mana.com, click the install icon at the right of the address bar (or **⋮** →
   **Cast, save and share** → **Install Anima**).
2. Go to `chrome://apps`, right-click Anima, choose **Start app when you sign in**.

## What arrives, and when

| What | Comes from | How late, at most |
|---|---|---|
| Raven to you, @tag in a channel, saved-message reminder, "log your time", task assigned / moved / review notes / mention | the bell (`notify_poll.php`) | about 30 s (about 1 min in a tab left in the background a long time) |
| Knock on your door | `realm_api.php?unread=1` (Realm button on every page; the Realm itself) | about 20–25 s |
| A call starting in the room you are in | the Realm page only | about 25 s |

- **Nothing pops up while you are looking at Anima** (any Anima tab or the app window in front):
  the bell, the Realm's notices and the Realm button's number already tell you. Whatever arrived then
  is not repeated later.
- **One notification per event**, however many Anima tabs are open.
- A raven's notification says **who** sent it, never what it says.
- Clicking a notification brings Anima to the front and opens the thing: in the Realm, a raven opens
  that conversation and a reminder opens its message; elsewhere it goes where the bell line goes.

## Limits, and the follow-up

Anima's host cannot keep a connection open, so this rides on the checks pages already make. Hence the
delay above, and nothing at all while every Anima tab and window is closed. **Web Push** (a service
worker plus a pair of VAPID keys, and a small table of each browser's subscription) would deliver
within seconds even with Anima closed, as Slack does. It needs more setup and is proposed to David as
a follow-up, not built.

Code: `assets/desk-notify.js` (all of it), wired in `assets/bell.js`, `assets/realm-nudge.js`,
`assets/realm.js` (`takeKnocks`, `deskBeat`, `callCheck`), `includes/header.php`, `realm.php`;
`manifest.json` and `assets/icon-192.png` / `icon-512.png` for installing. No migration.
