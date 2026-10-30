// THE FIRST-TIME TOUR (David, 2026-10-30: "can you create a FTU for new users on
// how to use the platform and where to find stuff?").
//
// Loaded only when includes/tour.php says the tour is running on this page,
// either by itself on a person's first login or from "Tour" in the top bar.
// No library: a spotlight box over the real element, a card beside it, and
// Next / Back / Skip.
//
// ⚠ THE COPY LIVES HERE, in STEPS below, one list per role, so David can edit
// the words without touching anything else. Each step says:
//
//   page   the screen it is shown on ('home.php'), or '*' for any screen
//   via    for a screen that needs a project (project.php, crew.php): the link
//          on the CURRENT page to follow to reach it, e.g. the first project in
//          the list. The tour cannot guess a project id, so it borrows one.
//   find   what to highlight, tried in order: a CSS selector, or
//          {heading: 'Your team'} for a section heading by its words
//   only   when the step applies at all (salaried staff get no invoice step)
//
// ⚠ IF THE ELEMENT IS NOT THERE (a new artist with no tasks yet has no timer
// button; a show-less editor has no project to open), the card is shown in
// the middle of the screen without a highlight and the tour carries on. It
// never stops on a missing element, because a new person is exactly the
// person whose screens are still empty.
//
// CROSSING SCREENS: Next on the last step of a screen opens the next screen
// with ?tour=N in the address, and the header loads this script again there,
// which opens at step N. Back across screens is the browser's own Back.

(function () {
  'use strict';

  var me = document.getElementById('tourScript');
  if (!me) return;

  // ---- The steps and their words, per role. Edit freely. -------------------

  var welcomeArtist = {
    page: '*', title: 'Welcome to Anima',
    body: 'Anima is where the studio keeps track of the work: your tasks, your hours and your ' +
          'reviews. This takes about a minute, and Tour in the top bar brings it back any time.'
  };
  var homeStep = {
    page: 'home.php', find: ['.dash-strip', 'h2.wizard-title'], only: function (c) { return c.home; },
    title: 'Home',
    body: function (c) {
      return 'Home is your desk: the shows you are on, your contract and rate, and who your ' +
        'supervisor is. It adds up the hours you have logged and, if you missed a day, says so at the top.' +
        (c.role === 'supervisor'
          ? ' Further down, Your team shows who on your shows has not logged and whose notes are waiting.'
          : '');
    }
  };
  var invoiceStep = {
    page: 'home.php', find: [{ heading: 'Invoice information' }],
    only: function (c) { return c.home && !c.salaried; },
    title: 'Your invoice',
    body: 'You send one invoice a month, with one line per project. This panel lists what goes on it. ' +
          'Send it only to accounting@mana.tv; it is paid from there.'
  };
  var myTasksStep = {
    page: 'mytasks.php', find: [{ heading: 'Production tasks' }, 'h2.wizard-title'],
    title: 'My tasks',
    body: 'Everything assigned to you, on every show, in one list. When you start a task, set it to ' +
          'In progress. When it is ready, set it to Pending review.'
  };
  var logTimeStep = {
    page: 'mytasks.php', find: ['.timer-btn', { heading: 'Studio time' }],
    title: 'Log your time every day',
    body: 'Start the timer on the task you are working on, or type the hours in. Meetings and desk ' +
          'time go under Studio time. Please log every day: Home reminds you of any day you missed.'
  };
  var spellArtistStep = {
    page: 'spell.php', find: ['.board-open-task', '.board-rows', '.board-title'],
    title: 'Spell',
    body: 'Spell shows a whole show, shot by shot. Click a task to open its side panel: its status, ' +
          'details, versions and notes.'
  };
  var versionsStep = {
    page: 'spell.php', find: ['#bell', '.board-open-task'],
    title: 'Versions and notes',
    body: 'When your work is ready, upload a version from the side panel and set the task to Pending ' +
          'review. It opens in the review viewer, where notes are drawn on the frame. Type @ and a name ' +
          'in a note to mention someone: they are told in this bell.'
  };
  var reviewsStep = {
    page: 'review.php', find: ['.rq-grid', 'h2.wizard-title'],
    title: 'Reviews',
    body: 'Everything waiting for your review on your shows, newest version first. Click the picture ' +
          'to open the review viewer, step through it and leave notes. Approve moves it on; Retake ' +
          'sends it back. The number on the Reviews button is how many are waiting.'
  };
  var bugStep = {
    page: '*', find: ['.bug-fab'],
    title: 'Report a bug',
    body: 'If something is broken or confusing, press Report a bug, say what went wrong and add a ' +
          'screenshot if you can. Tour in the top bar brings this guide back any time.'
  };

  var STEPS = {
    artist: [
      welcomeArtist, homeStep, invoiceStep, myTasksStep, logTimeStep, spellArtistStep, versionsStep, bugStep
    ],
    // The artist tour plus Reviews. The team panel is a sentence in the Home
    // step, so the tour stays at eight steps.
    supervisor: [
      welcomeArtist, homeStep, invoiceStep, myTasksStep, logTimeStep, spellArtistStep, reviewsStep, bugStep
    ],
    // Production ('editor') and admins.
    production: [
      {
        page: '*', title: 'Welcome to Anima',
        body: 'Anima is where the studio plans and tracks every show: projects, people, tasks and ' +
              'reviews. This takes about a minute, and Tour in the top bar brings it back any time.'
      },
      {
        page: 'index.php', find: ['.project-list', '.empty-state'],
        title: 'Projects',
        body: 'Every show, with its status and delivery date. Click a name to open it. The Anima logo ' +
              'at the top left always brings you back to this list.'
      },
      {
        page: 'project.php', via: 'a[href^="project.php?id="]', find: ['.proj-tabs'],
        title: 'Inside a project',
        body: 'The tabs cover the whole show: Brief and Dashboard to read; Staffing, Schedule, Timeline ' +
              'and Shots to plan; Production tracking and Tasks to follow the work. Setup, Import and ' +
              'Export sit after the line.'
      },
      {
        page: 'project.php', find: ['.proj-sections'], only: function (c) { return c.role === 'admin'; },
        title: 'Finance management',
        body: 'This switch takes the same show to its money side: expense projection, invoices, budget ' +
              'pool and artist statements. Only admins see it. Anima tracks the budget against cost, ' +
              'never profit.'
      },
      {
        page: 'crew.php', via: '.proj-tabs a[href^="crew.php"]',
        find: ['a[href^="person.php"]', '.proj-tabs a[href^="crew.php"]'],
        title: 'Staffing',
        body: 'Who is on the show, in which position, and when. Click a name to open their page. If they ' +
              'have no login yet, Create user there emails them a link to set a password; Resend link ' +
              'sends a fresh one.'
      },
      {
        page: 'spell.php', find: ['.board-open-task', '.board-rows', '.board-title'],
        title: 'Spell',
        body: 'Every task of a show on one screen, shot by shot. Filter by step, status, person or due ' +
              'date, and click a task to open its side panel: assign it, set its status, and see its ' +
              'versions and notes.'
      },
      reviewsStep,
      {
        page: '*', find: ['.topbar a[href="users.php"]'], only: function (c) { return c.role === 'admin'; },
        title: 'Users and Bugs',
        body: 'Users lists every login: its role, the Salaried tick and when it was last seen. Bugs, ' +
              'beside it, holds every report sent with the Report a bug button.'
      },
      // Admins read the reports on Bugs, so the step above covers them.
      { page: '*', find: bugStep.find, title: bugStep.title, body: bugStep.body,
        only: function (c) { return c.role !== 'admin'; } }
    ]
  };

  // ---- Who is looking -------------------------------------------------------

  var ctx = {
    role: me.getAttribute('data-role') || 'artist',
    home: me.getAttribute('data-home') === '1',
    salaried: me.getAttribute('data-salaried') === '1'
  };
  var auto = me.getAttribute('data-auto') || '';
  var list = STEPS[ctx.role === 'admin' || ctx.role === 'editor' ? 'production' : ctx.role] || STEPS.artist;
  var steps = list.filter(function (s) { return !s.only || s.only(ctx); });

  // Before the migration there is no column to remember "seen", so the browser
  // does. Storage can be blocked (private windows); then it simply shows again.
  if (auto === 'local') {
    try {
      if (localStorage.getItem('anima.tourSeen')) return;
      localStorage.setItem('anima.tourSeen', String(Date.now()));
    } catch (e) { /* show it; nothing to remember it with */ }
  }

  var here = (location.pathname.split('/').pop() || 'index.php');
  var at = Math.max(0, Math.min(steps.length - 1, parseInt(me.getAttribute('data-start'), 10) || 0));

  // ---- Finding the element --------------------------------------------------

  function visible(el) {
    if (!el || !el.getClientRects().length) return false;
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function findOne(spec) {
    var nodes;
    if (typeof spec === 'string') {
      nodes = document.querySelectorAll(spec);
    } else if (spec && spec.heading) {
      nodes = Array.prototype.filter.call(document.querySelectorAll('h2, h3'), function (h) {
        return h.textContent.replace(/\s+/g, ' ').trim().indexOf(spec.heading) === 0;
      });
    } else {
      return null;
    }
    for (var i = 0; i < nodes.length; i++) if (visible(nodes[i])) return nodes[i];
    return null;
  }

  function target(step) {
    if (step.page !== '*' && step.page !== here) return null;
    var specs = step.find || [];
    for (var i = 0; i < specs.length; i++) {
      var el = findOne(specs[i]);
      if (el) return el;
    }
    return null;
  }

  // ---- Drawing --------------------------------------------------------------

  var catcher = document.createElement('div');
  catcher.className = 'tour-catch';
  var spot = document.createElement('div');
  spot.className = 'tour-spot';
  var card = document.createElement('div');
  card.className = 'tour-card';
  card.setAttribute('role', 'dialog');
  card.setAttribute('aria-modal', 'true');
  card.setAttribute('aria-labelledby', 'tourTitle');
  card.innerHTML =
    '<div class="tour-count muted small"></div>' +
    '<h3 id="tourTitle" class="tour-title"></h3>' +
    '<p class="tour-body"></p>' +
    '<div class="tour-actions">' +
      '<button type="button" class="tour-skip linkish">Skip tour</button>' +
      '<span class="tour-gap"></span>' +
      '<button type="button" class="secondary tour-back">Back</button>' +
      '<button type="button" class="tour-next">Next</button>' +
    '</div>';

  var el = null;

  function place() {
    var pad = 6, gap = 12, margin = 12;
    var vw = window.innerWidth, vh = window.innerHeight;
    var cw = card.offsetWidth, ch = card.offsetHeight;
    if (!el || !visible(el)) {
      spot.hidden = true;
      card.classList.add('is-centred');
      card.style.left = Math.max(margin, (vw - cw) / 2) + 'px';
      card.style.top = Math.max(margin, (vh - ch) / 2) + 'px';
      return;
    }
    card.classList.remove('is-centred');
    var r = el.getBoundingClientRect();
    spot.hidden = false;
    spot.style.left = (r.left - pad) + 'px';
    spot.style.top = (r.top - pad) + 'px';
    spot.style.width = (r.width + pad * 2) + 'px';
    spot.style.height = (r.height + pad * 2) + 'px';

    // Below if it fits, else above, else beside it on the roomier side (a
    // tall table); then kept on screen.
    var below = r.bottom + pad + gap, above = r.top - pad - gap - ch;
    var top, left = r.left;
    if (below + ch <= vh - margin) top = below;
    else if (above >= margin) top = above;
    else {
      top = Math.min(vh - ch - margin, r.top);
      left = (vw - r.right > r.left) ? r.right + pad + gap : r.left - pad - gap - cw;
    }
    left = Math.min(vw - cw - margin, Math.max(margin, left));
    card.style.left = left + 'px';
    card.style.top = Math.max(margin, top) + 'px';
  }

  function show(i) {
    at = i;
    var step = steps[i];
    var body = typeof step.body === 'function' ? step.body(ctx) : step.body;
    card.querySelector('.tour-count').textContent = (i + 1) + ' of ' + steps.length;
    card.querySelector('.tour-title').textContent = step.title;
    card.querySelector('.tour-body').textContent = body;
    card.querySelector('.tour-back').hidden = i === 0;
    card.querySelector('.tour-next').textContent = i === steps.length - 1 ? 'Done' : 'Next';
    card.querySelector('.tour-skip').hidden = i === steps.length - 1;

    el = target(step);
    if (el) {
      el.scrollIntoView({ block: 'center', inline: 'nearest' });
    }
    place();
    card.querySelector('.tour-next').focus({ preventScroll: true });
  }

  // The address of step i's screen, or null to show it on this one.
  function hrefFor(step, i) {
    if (step.page === '*' || step.page === here) return null;
    var href = step.page;
    if (step.via) {
      var link = document.querySelector(step.via);
      if (!link) return null;   // no project to borrow: show the words here
      href = link.getAttribute('href');
    }
    return href + (href.indexOf('?') === -1 ? '?' : '&') + 'tour=' + i;
  }

  function go(i) {
    if (i < 0 || i >= steps.length) return close();
    var href = hrefFor(steps[i], i);
    if (href) { location.href = href; return; }
    show(i);
  }

  function back() {
    var prev = steps[at - 1];
    if (prev && prev.page !== '*' && prev.page !== here && history.length > 1) {
      history.back();
      return;
    }
    show(at - 1);
  }

  function close() {
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
    [catcher, spot, card].forEach(function (n) { if (n.parentNode) n.parentNode.removeChild(n); });
    // Take ?tour= out of the address, so a reload does not start it again.
    try {
      var u = new URL(location.href);
      if (u.searchParams.has('tour')) {
        u.searchParams.delete('tour');
        history.replaceState(history.state, '', u.pathname + u.search + u.hash);
      }
    } catch (e) { /* an old browser keeps the address as it is */ }
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); go(at + 1); }
    else if (e.key === 'ArrowLeft' && at > 0) { e.preventDefault(); back(); }
  }

  card.querySelector('.tour-next').addEventListener('click', function () { go(at + 1); });
  card.querySelector('.tour-back').addEventListener('click', back);
  card.querySelector('.tour-skip').addEventListener('click', close);

  function start() {
    document.body.appendChild(catcher);
    document.body.appendChild(spot);
    document.body.appendChild(card);
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    // A step that belongs to another screen than this one (the Tour link
    // pressed on Spell opens at the welcome, which is fine; ?tour=3 typed on
    // the wrong page is not) is shown here without a highlight.
    show(at);
  }

  // Coming Back from the next screen, the browser may restore this page as it
  // was left, card and all; only the positions need redoing.
  window.addEventListener('pageshow', function (e) { if (e.persisted) place(); });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
