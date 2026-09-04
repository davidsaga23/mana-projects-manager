// Stay where you were when a save reloads the page.
//
// David, 2026-09-02: "when I change anything and it saves, it refreshes and
// takes me to the top part of the page."
//
// Every write in this app is a form POST followed by a redirect, which is the
// right shape - it means a refresh never re-submits anything - but a redirect
// is a fresh page and a fresh page starts at the top. On the Staffing screen
// that means changing a candidate's stage on the thirtieth position throws you
// back to the first one.
//
// ⚠ WHY THIS IS NOT JUST A submit LISTENER. Fifteen controls in this app save
// with onchange="this.form.submit()" - the status picker, the role picker, the
// stage picker, every filter. Calling form.submit() from script does NOT fire
// the submit event; that is a rule of the platform, not a browser quirk. A
// listener alone would work for the buttons and silently miss every dropdown,
// which is most of the saves somebody makes in a day. So the prototype method
// is wrapped as well, and between them both routes are covered.
//
// ⚠ AND WHY IT ALSO MOVES THE FLASH. The confirmation, and more importantly the
// FAILURE, is rendered at the top of the page. Restoring the scroll position
// without doing anything else would mean an error nobody sees: "that did not
// save" scrolled off above the fold, with the row still showing the old value
// and no explanation. Trading an annoyance for a silent failure is not a fix.
// So when this scrolls you back down, any message comes with you.
//
// No PHP changed anywhere. Nothing here is required for the app to work: with
// JavaScript off, saving behaves exactly as it did before.
(function () {
  'use strict';

  var KEY = 'anima:keepplace';
  // Long enough to cover a slow save on a shared host, short enough that a
  // stored position cannot survive somebody wandering off and coming back.
  var FRESH_MS = 30000;

  function place() {
    return window.pageYOffset || document.documentElement.scrollTop || 0;
  }

  function remember() {
    try {
      sessionStorage.setItem(KEY, JSON.stringify({
        path: location.pathname,
        y: place(),
        at: Date.now()
      }));
    } catch (e) {
      // Private browsing, or storage turned off. Nothing to do: the page will
      // behave as it always did.
    }
  }

  // --- the two ways a form goes -------------------------------------------
  // Capture phase, so a handler that stops propagation cannot cost somebody
  // their place.
  document.addEventListener('submit', remember, true);

  var nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    remember();
    return nativeSubmit.apply(this, arguments);
  };

  // --- coming back ---------------------------------------------------------
  var target = null;
  try {
    var raw = sessionStorage.getItem(KEY);
    if (raw) {
      sessionStorage.removeItem(KEY);   // once, whatever happens next
      var saved = JSON.parse(raw);
      if (saved && saved.y > 0
          && saved.path === location.pathname
          && (Date.now() - saved.at) < FRESH_MS) {
        target = saved.y;
      }
    }
  } catch (e) {
    target = null;
  }

  if (target === null) return;

  // If the reader has already started scrolling, they have chosen where to be
  // and this must not argue with them.
  var userMoved = false;
  var restoring = false;
  window.addEventListener('scroll', function () {
    if (!restoring) userMoved = true;
  }, { passive: true });

  function goBack() {
    if (userMoved) return;
    restoring = true;
    window.scrollTo(0, target);
    // Cleared on the next frame, so the scroll this causes is not mistaken for
    // the reader moving.
    setTimeout(function () { restoring = false; }, 0);
  }

  /**
   * Bring any message down to where the reader now is.
   *
   * A copy, not a move: the original stays at the top of the document where it
   * belongs, so scrolling up shows it in its usual place, and nothing about the
   * page's layout changes.
   *
   * An ERROR does not time out. A confirmation can be missed harmlessly; a
   * refusal is the only thing telling somebody their change did not happen.
   */
  function floatFlashes() {
    // ⚠ .flash-msg, NOT .flash-warn. The "database updates are waiting" banner
    // and the view-only notice are standing states drawn on every page load;
    // floating those after every save would turn a helpful message into a nag
    // that follows somebody down the page. header.php marks only what
    // take_flashes() emitted - news about what just happened - with .flash-msg.
    var flashes = document.querySelectorAll('.flash-msg');
    if (!flashes.length) return;

    var box = document.createElement('div');
    box.className = 'keepplace-toasts';
    var anyError = false;

    for (var i = 0; i < flashes.length; i++) {
      var isErr = flashes[i].classList.contains('flash-err');
      if (isErr) anyError = true;

      var t = document.createElement('div');
      t.className = 'keepplace-toast ' + (isErr ? 'is-err' : 'is-ok');
      t.textContent = flashes[i].textContent.trim();

      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'keepplace-x';
      x.setAttribute('aria-label', 'Dismiss');
      x.textContent = '×';
      t.appendChild(x);
      box.appendChild(t);
    }
    if (!box.childNodes.length) return;

    box.addEventListener('click', function (ev) {
      var hit = ev.target.closest ? ev.target.closest('.keepplace-toast') : null;
      if (hit) hit.remove();
    });
    document.body.appendChild(box);

    if (!anyError) {
      setTimeout(function () { box.remove(); }, 6000);
    }
  }

  // Twice on purpose. The first is as early as the document allows so the jump
  // is not seen; the second is after images and fonts have settled, because a
  // late-loading row can change how far down the thing you were looking at
  // actually is.
  document.addEventListener('DOMContentLoaded', function () {
    goBack();
    floatFlashes();
  });
  window.addEventListener('load', goBack);
}());
