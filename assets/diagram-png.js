// Download a diagram as a PNG, made in the browser from the SVG the server draws.
//
// David, 2026-09-16: "let's make this a button and download png instead of svg
// files please."
//
// ⚠ WHY THE BROWSER MAKES THE PNG, NOT THE SERVER. Turning an SVG into pixels
// on the server needs Imagick with an SVG renderer behind it, and this host has
// no guarantee of either - which is exactly why export_workflow.php chose SVG in
// the first place. Every browser, though, already knows how to draw an SVG onto
// a canvas and save the canvas as a PNG. So the server keeps producing the one
// thing it can reliably produce, and the page turns it into the format asked
// for.
//
// The SVG it starts from is export_workflow.php's own output, not the diagram on
// the page. That file is built to leave the building - styles carried inside
// it, a white ground, the project and date as a caption - where the on-page copy
// takes its look from assets/style.css, which a canvas never sees. Drawn from
// the page copy, the PNG would come out as black text on transparency with no
// boxes and no lines.
//
// Usage: <a href="(an SVG download)" data-png>Download as PNG</a>. Without
// JavaScript, or if anything below fails, the link still downloads the SVG,
// and the page says so rather than leaving a button that did nothing.
(function () {
  'use strict';

  // Twice the drawn size. A PNG lands in decks and on projectors, and at 1x its
  // step names blur the moment anybody zooms in.
  var SCALE = 2;

  function say(link, text) {
    var note = link.parentNode.querySelector('.png-note');
    if (!note) {
      note = document.createElement('span');
      note.className = 'png-note muted small';
      link.parentNode.insertBefore(note, link.nextSibling);
    }
    note.textContent = text;
  }

  /** The filename the server chose for the SVG, with a .png ending. */
  function filenameFrom(response) {
    var cd = response.headers.get('Content-Disposition') || '';
    var m = /filename="?([^";]+)"?/i.exec(cd);
    var name = m ? m[1] : 'diagram.svg';
    // One place decides what these files are called - the export itself - so
    // the SVG and the PNG of the same diagram can never be named differently.
    return name.replace(/\.svg$/i, '') + '.png';
  }

  function toPng(svgText) {
    return new Promise(function (resolve, reject) {
      var doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
      var root = doc.documentElement;
      if (!root || root.nodeName.toLowerCase() !== 'svg' || doc.querySelector('parsererror')) {
        reject(new Error('the diagram did not arrive as a drawing'));
        return;
      }
      var w = parseFloat(root.getAttribute('width'));
      var h = parseFloat(root.getAttribute('height'));
      if (!(w > 0 && h > 0)) {
        reject(new Error('the diagram has no size'));
        return;
      }
      // ⚠ ENLARGED BEFORE IT IS DRAWN, not scaled up afterwards. The SVG keeps
      // its viewBox, so giving it a larger width and height makes the browser
      // draw every line and letter at the larger size. Drawing it small and
      // stretching the pixels would just be a blurry picture at twice the size.
      root.setAttribute('width', String(w * SCALE));
      root.setAttribute('height', String(h * SCALE));
      var markup = new XMLSerializer().serializeToString(root);

      var img = new Image();
      img.onload = function () {
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(w * SCALE);
        canvas.height = Math.round(h * SCALE);
        var ctx = canvas.getContext('2d');
        // White under everything. The file already paints its own ground, but a
        // PNG with a transparent edge shows as a checkerboard or a black frame in
        // half the image viewers people will open it in.
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
          if (blob) resolve(blob);
          else reject(new Error('this browser would not save the picture'));
        }, 'image/png');
      };
      img.onerror = function () { reject(new Error('the browser could not draw the diagram')); };
      // A data: URL rather than a blob: URL. Older Safari marks a canvas as
      // "tainted" after drawing an SVG from a blob: URL and then refuses to
      // export it; a data: URL of the same bytes does not have that problem.
      // encodeURIComponent, not btoa, because step names are not all ASCII -
      // the caption alone carries a middle dot.
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
    });
  }

  function download(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    // Revoked a moment later, not immediately: some browsers start the download
    // asynchronously, and revoking first cancels it.
    setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
  }

  document.addEventListener('click', function (evt) {
    var link = evt.target.closest ? evt.target.closest('a[data-png]') : null;
    if (!link || link.classList.contains('is-busy')) return;
    // A modified click - open in a new tab, save link as - is left to do what
    // the reader asked for, which is the SVG the link points at.
    if (evt.metaKey || evt.ctrlKey || evt.shiftKey || evt.altKey || evt.button !== 0) return;
    evt.preventDefault();

    var label = link.textContent;
    link.classList.add('is-busy');
    link.textContent = 'Making the PNG…';
    say(link, '');

    var filename = 'diagram.png';
    fetch(link.href, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        // Not an SVG means the server said something else - most likely "there
        // are no steps yet", sent as a redirect to a page. Following the link is
        // the way to see that message, so that is what happens.
        var type = r.headers.get('Content-Type') || '';
        if (!r.ok || type.indexOf('svg') === -1) {
          throw Object.assign(new Error('not a diagram'), { follow: true });
        }
        filename = filenameFrom(r);
        return r.text();
      })
      .then(toPng)
      .then(function (blob) { download(blob, filename); })
      .catch(function (err) {
        if (err && err.follow) { window.location.href = link.href; return; }
        // ⚠ Never a button that silently did nothing. The SVG is the same
        // diagram, it is the file this link has always given, and the reader
        // is told which one they got and why.
        say(link, 'A PNG could not be made in this browser (' + (err && err.message || 'unknown error')
                + '), so the diagram was downloaded as an SVG instead.');
        window.location.href = link.href;
      })
      .then(function () {
        link.classList.remove('is-busy');
        link.textContent = label;
      });
  });
}());
