
/* A filter box over a table of records.
 *
 * One implementation for every manage list - platforms, companies, models,
 * environments - because they are the same problem: a few hundred rows and you know the
 * name of the one you want. Marked up as:
 *
 *   <input data-tablefilter="#some-table" placeholder="Filter…">
 *   <table id="some-table"> … </table>
 *
 * Hides rows rather than removing them, so clearing the box restores everything without
 * a round trip. Group headings stay visible only while something under them matches -
 * a heading over nothing is worse than no heading.
 *
 * Server-rendered lists still work with no script: the box simply does nothing, which
 * is why it is not the only way to find a row.
 */
(function () {
  document.querySelectorAll('[data-tablefilter]').forEach(function (box) {
    var table = document.querySelector(box.getAttribute('data-tablefilter'));
    if (!table) { return; }

    var count = document.querySelector(box.getAttribute('data-tablefilter-count') || '');

    function apply() {
      var want = box.value.trim().toLowerCase();
      var rows = table.querySelectorAll('tbody > tr');
      var shown = 0;

      // Two passes: hide or show the records first, then decide about the headings,
      // because a heading's fate depends on the rows after it.
      rows.forEach(function (row) {
        if (row.classList.contains('grouphead')) { return; }
        var hit = want === '' || row.textContent.toLowerCase().indexOf(want) !== -1;
        row.hidden = !hit;
        if (hit) { shown++; }
      });

      rows.forEach(function (row) {
        if (!row.classList.contains('grouphead')) { return; }
        var any = false;
        var el = row.nextElementSibling;
        while (el && !el.classList.contains('grouphead')) {
          if (!el.hidden) { any = true; break; }
          el = el.nextElementSibling;
        }
        row.hidden = !any;
      });

      if (count) {
        count.textContent = want === '' ? '' : shown + ' shown';
      }
    }

    box.addEventListener('input', apply);
    // Escape clears, because reaching for the mouse to empty a search box is a small
    // annoyance repeated all day.
    box.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { box.value = ''; apply(); }
    });
    apply();
  });
})();


/* The software model picker narrows with the platform.
 *
 * Same rule and the same rebuild as the catalogue and environment pickers: Safari
 * ignores `hidden` on an <option>, so the list is rebuilt rather than filtered in
 * place. A Game Boy cartridge is not a shape an Amiga release comes in.
 *
 * A separate block rather than another selector on the shared one, because that script
 * narrows exactly one select and this is a second - which is why tagging the options
 * with data-platform did nothing on its own.
 */
(function () {
  var plat = document.querySelector('[data-platform-select]');
  var sel  = document.querySelector('[data-swmodel-select]');
  if (!plat || !sel) { return; }

  var all = Array.prototype.slice.call(sel.options).map(function (o) {
    return { value: o.value, text: o.textContent, owner: o.getAttribute('data-platform') || '0' };
  });

  function apply() {
    var want = plat.value;
    var keep = sel.value;
    while (sel.options.length) { sel.remove(0); }

    all.forEach(function (o) {
      // "Not a specific kind of release" always stays; a model with no platform suits
      // any machine.
      if (o.value && o.owner !== '0' && want !== '' && o.owner !== want) { return; }
      var el = document.createElement('option');
      el.value = o.value;
      el.textContent = o.text;
      if (o.owner) { el.setAttribute('data-platform', o.owner); }
      sel.appendChild(el);
    });

    sel.value = keep;
    if (sel.selectedIndex < 0) { sel.value = ''; }
  }

  plat.addEventListener('change', apply);
  apply();
})();


/* Repeating media rows on the software model editor.
 *
 * Same behaviour as the specification rows beside them - add, reorder, remove - because
 * a boxed release is often several media at once and a single text box could only ever
 * hold prose about it.
 */
(function () {
  var box = document.querySelector('[data-media-rows]');
  var add = document.querySelector('[data-media-add]');
  if (!box) { return; }

  function blank() {
    var first = box.querySelector('[data-media-row]');
    var row;
    if (first) {
      row = first.cloneNode(true);
      row.querySelectorAll('select, input').forEach(function (f) {
        if (f.type === 'number') { f.value = '1'; } else { f.selectedIndex = 0; }
      });
    } else {
      // Nothing to clone on a new model, so the markup carries a <template>. Its
      // content is the same loop that renders a real row, so the medium list cannot
      // drift between the two.
      var tpl = document.querySelector('[data-media-template]');
      if (tpl && tpl.content && tpl.content.firstElementChild) {
        row = tpl.content.firstElementChild.cloneNode(true);
      }
    }
    return row;
  }

  function sync() {
    if (add) {
      // The button is for when there is nothing there; once a row exists the + on the
      // row itself is nearer to hand.
      if (box.querySelector('[data-media-row]')) { add.setAttribute('hidden', 'hidden'); }
      else { add.removeAttribute('hidden'); }
    }
  }

  if (add) {
    add.addEventListener('click', function () {
      var row = blank();
      if (row) { box.appendChild(row); sync(); }
    });
  }

  box.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('button') : null;
    if (!btn) { return; }
    var row = btn.closest('[data-media-row]');
    if (!row) { return; }

    if (btn.hasAttribute('data-media-remove')) { row.remove(); sync(); return; }
    if (btn.hasAttribute('data-media-addafter')) {
      var made = blank();
      if (made) { row.after(made); }
      return;
    }
    if (btn.hasAttribute('data-media-up') && row.previousElementSibling) {
      row.previousElementSibling.before(row);
      return;
    }
    if (btn.hasAttribute('data-media-down') && row.nextElementSibling) {
      row.nextElementSibling.after(row);
    }
  });

  sync();
})();


/* A select that fills the text box beside it.
 *
 * Used by the developer and publisher pickers: choose a studio this library already
 * knows, or type one it does not. Two controls for one value, which is normally a
 * mistake - but here the list can never be complete, and a plain text box with no
 * suggestions is how a catalogue ends up holding "Team17", "Team 17" and "team17".
 */
(function () {
  document.querySelectorAll('[data-fills]').forEach(function (sel) {
    var target = document.querySelector(sel.getAttribute('data-fills'));
    if (!target) { return; }
    sel.addEventListener('change', function () {
      if (sel.value) { target.value = sel.value; }
    });
    // Typing a name that is not on the list clears the select, so the two never
    // disagree about what was chosen.
    target.addEventListener('input', function () {
      var match = Array.prototype.some.call(sel.options, function (o) { return o.value === target.value; });
      if (!match) { sel.value = ''; }
    });
  });
})();

/* Notices.
 *
 * One place that knows what a notice looks like, so the server and the page raise the
 * same thing. A flash rendered into the container on page load and a toast raised by
 * script after a save that never reloads are the same object with the same behaviour.
 *
 *   RetroVault.notify('Order saved.')            - a success, fades on its own
 *   RetroVault.notify('That did not save', 'error')  - stays until dismissed
 *
 * Errors do not auto-dismiss: missing "Saved." costs nothing, missing "That did not
 * save" costs the work. Everything is dismissable by hand either way, and with no
 * script at all the server-rendered ones are still readable - they simply do not fade.
 */
(function () {
  var HOLD = 4500;

  function box() {
    var el = document.querySelector('[data-toasts]');
    if (!el) {
      el = document.createElement('div');
      el.className = 'toasts';
      el.setAttribute('data-toasts', '');
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    return el;
  }

  function dismiss(toast) {
    if (!toast || toast.classList.contains('is-going')) { return; }
    toast.classList.add('is-going');
    // Remove after the animation, but on a timer rather than animationend: a browser
    // with animations disabled never fires that event, and the node would stay forever.
    setTimeout(function () { toast.remove(); }, 260);
  }

  function arm(toast) {
    if (toast.hasAttribute('data-toast-sticky')) { return; }
    var timer = setTimeout(function () { dismiss(toast); }, HOLD);
    // Reading it should not race the clock: hovering or focusing holds it open.
    ['mouseenter', 'focusin'].forEach(function (ev) {
      toast.addEventListener(ev, function () { clearTimeout(timer); });
    });
    ['mouseleave', 'focusout'].forEach(function (ev) {
      toast.addEventListener(ev, function () {
        clearTimeout(timer);
        timer = setTimeout(function () { dismiss(toast); }, HOLD);
      });
    });
  }

  function notify(message, type) {
    var toast = document.createElement('div');
    toast.className = 'toast toast--' + (type === 'error' ? 'error' : 'ok');
    if (type === 'error') { toast.setAttribute('data-toast-sticky', ''); }

    var text = document.createElement('span');
    text.className = 'toast__text';
    text.textContent = String(message);          // textContent, so a message cannot inject
    toast.appendChild(text);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast__close';
    close.setAttribute('data-toast-close', '');
    close.setAttribute('aria-label', 'Dismiss this notice');
    close.innerHTML = '&times;';
    toast.appendChild(close);

    box().appendChild(toast);
    arm(toast);
    return toast;
  }

  // The ones the server rendered into the page get the same treatment.
  document.querySelectorAll('[data-toasts] .toast').forEach(arm);

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-toast-close]') : null;
    if (btn) { dismiss(btn.closest('.toast')); }
  });

  window.RetroVault = window.RetroVault || {};
  window.RetroVault.notify = notify;
})();

/* Foldable sections, and the sold pair.
 *
 * Both work without scripting: a fieldset with no data-fold-open still renders, and the
 * sold fields are only hidden, never removed, so a form posted with scripting off
 * carries exactly what it always did.
 */
(function () {
  document.querySelectorAll('[data-fold]').forEach(function (fs) {
    var btn  = fs.querySelector('[data-fold-toggle]');
    var body = fs.querySelector('[data-fold-body]');
    if (!btn || !body) { return; }
    function paint() {
      var open = fs.hasAttribute('data-fold-open');
      body.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function () {
      if (fs.hasAttribute('data-fold-open')) { fs.removeAttribute('data-fold-open'); }
      else { fs.setAttribute('data-fold-open', ''); }
      paint();
    });
    paint();
  });

  // The sold tick that lived here is gone: the sale follows the status select on both
  // forms now, through data-toggle-when-sold below. Two controls for one fact could
  // disagree, and one of them had to win.
})();

/* Narrowing the filing list to the chosen machine.
 *
 * The taxonomy is per platform now, so the full list is every kind of every machine -
 * thousands of options, of which only one machine's are ever right.
 *
 * The list is rebuilt, not hidden. Safari ignores `hidden` on an <option>, so setting it
 * left every other machine's branches on screen, merely greyed out - which looks like a
 * bug, and is one. The same mistake was made and fixed once before on this codebase for
 * the genre select; this is the note that was there to be read.
 */
(function () {
  // Both forms: the software one names its selects one way, the hardware one another,
  // and the narrowing is the same question either way - which machine is this for.
  var plat = document.querySelector('[data-platform-select], [data-mfg-platform], [data-model-platform]');
  // data-type-select too: the model editors name theirs that way, and leaving it out
  // meant the platform picker narrowed nothing on the two pages with the longest lists.
  var cats = document.querySelector('[data-category-select], [data-kind-select], [data-type-select]');
  if (!plat || !cats) { return; }

  // Every option, taken once before anything is removed.
  var all = Array.prototype.slice.call(cats.options).map(function (o) {
    return { value: o.value, text: o.textContent, owner: o.getAttribute('data-platform') || '0' };
  });

  function apply() {
    var want    = plat.value;
    var keeping = cats.value;

    while (cats.options.length) { cats.remove(0); }

    all.forEach(function (o) {
      // "Choose…" always stays; a kind with no platform belongs to every machine.
      if (o.value && o.owner !== '0' && want !== '' && o.owner !== want) { return; }
      var el = document.createElement('option');
      el.value = o.value;
      el.textContent = o.text;
      if (o.owner) { el.setAttribute('data-platform', o.owner); }
      cats.appendChild(el);
    });

    // A selection that still applies is kept; one that does not is cleared rather than
    // left naming a kind on another machine.
    cats.value = keeping;
    if (cats.selectedIndex < 0) { cats.value = ''; }
  }

  plat.addEventListener('change', apply);
  apply();
})();

/* The environment box narrows with the platform, exactly as the hardware box does.
 *
 * It was a <select> and is a list of ticks now, so the narrowing is hiding labels rather
 * than rebuilding options - which is the same code the fits box uses, and safe here
 * because `hidden` on a <label> is respected everywhere `hidden` on an <option> is not.
 */
(function () {
  var plat = document.querySelector('[data-platform-select]');
  var box  = document.querySelector('[data-env-box]');
  if (!plat || !box) { return; }

  function apply() {
    var want = plat.value;

    box.querySelectorAll('label.checkline').forEach(function (row) {
      var input = row.querySelector('input[type="checkbox"]');
      var mine  = input ? (input.dataset.platform || '') : '';
      // A ticked box stays visible whatever the platform says: hiding a claim somebody
      // made is worse than showing one that no longer fits, and they can untick it.
      row.hidden = !(want === '' || mine === want || (input && input.checked));
    });

    // A heading with nothing under it is a heading for nothing.
    box.querySelectorAll('.fitsbox__group').forEach(function (h) {
      var any = false;
      var el  = h.nextElementSibling;
      while (el && !el.classList.contains('fitsbox__group')) {
        if (el.matches('label.checkline') && !el.hidden) { any = true; break; }
        el = el.nextElementSibling;
      }
      h.hidden = !any;
    });
  }

  plat.addEventListener('change', apply);
  apply();
})();


/* Filtering the category tree.
 *
 * In the page, not the server: sixty-three machines is a lot to scroll past to reach
 * the Amiga, but they are all already here, so narrowing is a matter of hiding rows
 * rather than fetching different ones. Nothing is posted, so filtering cannot lose an
 * arrangement that has not been saved yet.
 *
 * A row survives if it matches, or if anything beneath it does - otherwise filtering
 * for "memory" would show the leaf with no indication of which machine it belongs to.
 */
(function () {
  var bar = document.querySelector('[data-tree-filter]');
  var tree = document.querySelector('[data-tree]');
  if (!bar || !tree) { return; }

  var text  = bar.querySelector('[data-filter-text]');
  var klass = bar.querySelector('[data-filter-class]');
  var maker = bar.querySelector('[data-filter-maker]');
  var clear = bar.querySelector('[data-filter-clear]');
  var count = bar.querySelector('[data-filter-count]');

  function apply() {
    var q  = (text.value || '').trim().toLowerCase();
    var kc = klass.value;
    var mk = maker.value;
    var rows = Array.prototype.slice.call(tree.querySelectorAll('.treerow'));
    var idle = q === '' && kc === '' && mk === '';

    if (idle) {
      rows.forEach(function (r) { r.removeAttribute('data-filtered'); });
      tree.removeAttribute('data-filtering');
      count.textContent = '';
      return;
    }

    // Own match first.
    var hit = rows.map(function (r) {
      var okText  = q === '' || (r.getAttribute('data-name') || '').indexOf(q) !== -1;
      var okClass = kc === '' || r.getAttribute('data-class') === kc;
      var okMaker = mk === '' || r.getAttribute('data-maker') === mk;
      return okText && okClass && okMaker;
    });

    // Then let a match pull its ancestors in, walking upwards from the deepest.
    var keep = hit.slice();
    for (var i = rows.length - 1; i >= 0; i--) {
      if (!keep[i]) { continue; }
      var depth = +rows[i].getAttribute('data-depth');
      for (var j = i - 1; j >= 0 && depth > 0; j--) {
        var d = +rows[j].getAttribute('data-depth');
        if (d < depth) { keep[j] = true; depth = d; }
      }
    }

    var shown = 0;
    rows.forEach(function (r, i) {
      if (keep[i]) { r.removeAttribute('data-filtered'); shown++; }
      else { r.setAttribute('data-filtered', ''); }
    });
    tree.setAttribute('data-filtering', '');
    count.textContent = shown + ' of ' + rows.length + ' shown';
  }

  ['input', 'change'].forEach(function (ev) {
    bar.addEventListener(ev, function (e) {
      if (e.target === text || e.target === klass || e.target === maker) { apply(); }
    });
  });
  if (clear) {
    clear.addEventListener('click', function () {
      text.value = ''; klass.value = ''; maker.value = ''; apply();
    });
  }
})();

/* Reordering the category tree.
 *
 * In this file, not inline on the page. The instance sends
 * Content-Security-Policy: script-src 'self' with no 'unsafe-inline', so an inline
 * <script> is blocked outright - which is why the specification rows below have always
 * worked and the tree arrows did nothing at all. Same origin, external file, allowed.
 *
 * Nothing is submitted while you arrange things: the rows move, a Save bar appears,
 * and one request writes the result. The arrows are plain buttons, so there is no form
 * that could reload the page even if this never runs.
 */
(function () {
      var tree = document.querySelector('[data-tree]');
      var bar  = document.querySelector('[data-order-form]');
      if (!tree || !bar) { return; }

      var fields = bar.querySelector('[data-order-fields]');
      var undo   = bar.querySelector('[data-order-cancel]');
      var start  = Array.prototype.map.call(tree.children, function (li) { return li; });
      var dirty  = false;

      function rows() { return Array.prototype.slice.call(tree.querySelectorAll('.treerow')); }

      /* A node's block is itself plus every row indented under it, contiguous in a
         depth-first list - so moving a branch takes its children along. */
      function blockOf(row, all) {
        var depth = +row.getAttribute('data-depth');
        var out = [row];
        for (var i = all.indexOf(row) + 1; i < all.length; i++) {
          if (+all[i].getAttribute('data-depth') <= depth) { break; }
          out.push(all[i]);
        }
        return out;
      }

      function siblingBlock(row, all, dir) {
        var parent = row.getAttribute('data-parent');
        var depth  = +row.getAttribute('data-depth');
        var step   = dir === 'up' ? -1 : 1;
        for (var j = all.indexOf(row) + step; j >= 0 && j < all.length; j += step) {
          if (all[j].getAttribute('data-parent') === parent) { return blockOf(all[j], all); }
          if (+all[j].getAttribute('data-depth') < depth) { return null; }
        }
        return null;
      }

      function markDirty() {
        dirty = true;
        bar.removeAttribute('hidden');
        fields.innerHTML = '';
        rows().forEach(function (r) {
          var id = r.getAttribute('data-node');
          if (!id) { return; }
          var f = document.createElement('input');
          f.type = 'hidden';
          f.name = 'order[]';
          f.value = id;
          fields.appendChild(f);
        });
      }

      tree.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('[data-move]') : null;
        if (!btn) { return; }
        ev.preventDefault();

        var dir  = btn.getAttribute('data-move');
        var row  = btn.closest('.treerow');
        var all  = rows();
        var mine = blockOf(row, all);
        var next = siblingBlock(row, all, dir);
        if (!next) { return; }

        if (dir === 'up') {
          next[0].parentNode.insertBefore(mine[0], next[0]);
        } else {
          next[next.length - 1].after(mine[0]);
        }
        for (var i = 1; i < mine.length; i++) { mine[i - 1].after(mine[i]); }

        row.classList.add('just-moved');
        setTimeout(function () { row.classList.remove('just-moved'); }, 600);
        btn.focus();
        markDirty();
      });

      if (undo) {
        undo.addEventListener('click', function () {
          start.forEach(function (li) { tree.appendChild(li); });
          bar.setAttribute('hidden', 'hidden');
          fields.innerHTML = '';
          dirty = false;
          // Undo touches nothing on the server, so without this it is silent: the rows
          // move back and nothing says whether it worked.
          if (window.RetroVault && window.RetroVault.notify) {
            window.RetroVault.notify('Order restored.');
          }
        });
      }

      window.addEventListener('beforeunload', function (e) {
        if (!dirty) { return; }
        e.preventDefault();
        e.returnValue = '';
      });
    })();

/* RetroVault - small, dependency-free enhancements. Everything works without
   JavaScript; this only smooths the edges. */

(function () {
  'use strict';

  // --- Detail page gallery -------------------------------------------------
  var hero = document.querySelector('[data-hero]');
  document.querySelectorAll('[data-gallery] button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!hero) return;
      hero.src = btn.dataset.full;
      hero.alt = btn.dataset.caption || '';
      document.querySelectorAll('[data-gallery] button').forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
      });
    });
  });

  // --- Lightbox ------------------------------------------------------------
  var dialog = document.querySelector('dialog.lightbox');
  if (dialog) {
    var img = dialog.querySelector('img');
    var cap = dialog.querySelector('.lightbox__cap');
    document.querySelectorAll('[data-zoom]').forEach(function (el) {
      el.addEventListener('click', function () {
        img.src = el.dataset.zoom || el.src;
        cap.textContent = el.alt || '';
        if (typeof dialog.showModal === 'function') dialog.showModal();
      });
    });
    dialog.addEventListener('click', function () { dialog.close(); });
  }

  // The genre narrowing that lived here is gone with the genre select: a genre is a
  // category now, and the category picker narrows itself by platform.


  // --- Confirm destructive actions ----------------------------------------
  document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.dataset.confirm)) ev.preventDefault();
    });
  });

  // --- Drag-and-drop file queue --------------------------------------------
  //
  // Wraps a plain <input type=file> rather than replacing it: files dropped or
  // pasted are pushed back into input.files via DataTransfer, so the ordinary
  // form POST carries them and the server needs no separate upload endpoint.
  // Without JavaScript the input still works as a normal file picker.
  document.querySelectorAll('[data-dropzone]').forEach(function (zone) {
    var input = zone.querySelector('input[type="file"]');
    if (!input || typeof DataTransfer === 'undefined') return;

    var list     = zone.querySelector('[data-dropzone-list]');
    var multiple = input.hasAttribute('multiple');
    // A picture that is only ever one picture.
    //
    // This zone is shared with the entry forms, where a photo is one of several
    // and each needs a caption and a note of what it shows. An avatar is neither
    // of those: there is one, it is the avatar, and asking somebody to name it is
    // asking a question with no answer.
    var plain    = zone.hasAttribute('data-dropzone-plain');
    var queue    = [];
    var captions = {};
    var kinds    = {};
    // Four at a time, because the server has a per-request size limit and a
    // silent truncation is worse than being told the fifth was not taken.
    var max      = parseInt(zone.dataset.max || '0', 10) || 0;

    var keyOf = function (f) { return f.name + ':' + f.size; };
    var urls  = {};

    var human = function (bytes) {
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
      return (bytes / 1048576).toFixed(1) + ' MB';
    };

    var sync = function () {
      var dt = new DataTransfer();
      queue.forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;
      render();
    };

    var render = function () {
      if (!list) return;
      list.innerHTML = '';
      zone.classList.toggle('has-files', queue.length > 0);
      if (!queue.length) return;

      queue.forEach(function (file, index) {
        var card = document.createElement('div');
        card.className = 'dropzone__file';

        if (file.type.indexOf('image/') === 0) {
          var img = document.createElement('img');
          img.alt = '';
          var k = keyOf(file);

          // An object URL, not a data URL. createObjectURL is instant; reading a
          // six-megabyte photo into base64 takes long enough that the box sits
          // empty first - and an empty box with a dark card behind it is exactly
          // what "the thumbnail is black" looks like.
          //
          // Revoked only when the card goes, never on load: revoking on load
          // races the decode, which is the other way this ends up dark.
          if (!urls[k]) { urls[k] = URL.createObjectURL(file); }
          img.src = urls[k];

          // Until it decodes, say so. A failure names itself rather than looking
          // like a photo that happens to be black.
          card.classList.add('is-loading');
          img.addEventListener('load', function () {
            card.classList.remove('is-loading');
          });
          img.addEventListener('error', function () {
            card.classList.remove('is-loading');
            card.classList.add('is-broken');
            img.remove();
          });

          card.appendChild(img);
        }

        var meta = document.createElement('span');
        meta.className = 'dropzone__meta';
        meta.textContent = file.name + ' · ' + human(file.size);
        card.appendChild(meta);

        if (!plain) {
          var cap = document.createElement('input');
          cap.type = 'text';
          cap.className = 'dropzone__caption';
          cap.name = 'image_captions[]';
          cap.maxLength = 255;
          cap.placeholder = 'What this shows';
          cap.setAttribute('aria-label', 'Caption for ' + file.name);
          // Typed captions have to survive a re-render, which happens whenever a
          // photo is added or removed.
          cap.value = captions[keyOf(file)] || '';
          cap.addEventListener('input', function () { captions[keyOf(file)] = cap.value; });
          card.appendChild(cap);

          // What this particular photo shows.
          //
          // The batch select below still sets the default, because four photos of one box
          // usually are all "box front" - but a batch is just as often a box, a disc and a
          // manual, and relabelling three of them afterwards is worse than saying so once.
          var kindSel = document.createElement('select');
          kindSel.className = 'dropzone__kind';
          kindSel.name = 'image_kinds[]';
          kindSel.setAttribute('aria-label', 'What ' + file.name + ' shows');
          var batch = document.querySelector('#image_kind');
          var opts  = batch ? batch.options : [];
          for (var k = 0; k < opts.length; k++) {
            var o = document.createElement('option');
            o.value = opts[k].value;
            o.textContent = opts[k].textContent;
            kindSel.appendChild(o);
          }
          kindSel.value = kinds[keyOf(file)] || (batch ? batch.value : '');
          kindSel.addEventListener('change', function () { kinds[keyOf(file)] = kindSel.value; });
          card.appendChild(kindSel);
        }

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'dropzone__remove';
        remove.setAttribute('aria-label', 'Remove ' + file.name);
        remove.textContent = '×';
        remove.addEventListener('click', function () {
          var k = keyOf(file);
          if (urls[k]) { URL.revokeObjectURL(urls[k]); delete urls[k]; }
          delete captions[k];
          queue.splice(index, 1);
          sync();
        });
        card.appendChild(remove);

        list.appendChild(card);
      });
    };

    var accepted = function (file) {
      if (!file.type) return true;                       // let the server decide
      return file.type.indexOf('image/') === 0;
    };

    var add = function (files) {
      Array.prototype.forEach.call(files, function (file) {
        if (!accepted(file)) return;
        // Same name and size twice over is a double-drop, not two photos.
        var dup = queue.some(function (q) { return q.name === file.name && q.size === file.size; });
        if (dup) return;
        // Replacing comes before the limit, not after it.
        //
        // A single-file zone holds one picture, so a second drop is a replacement
        // and the queue is emptied first. Checked the other way round, a zone with
        // a limit of one would have refused every drop after the first - the new
        // avatar quietly discarded because the old one was still in the queue.
        if (!multiple) queue = [];
        if (max > 0 && queue.length >= max) { return; }
        queue.push(file);
      });
      sync();
    };

    ['dragenter', 'dragover'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        e.preventDefault();
        zone.classList.add('is-over');
      });
    });
    ['dragleave', 'dragend', 'drop'].forEach(function (evt) {
      zone.addEventListener(evt, function () { zone.classList.remove('is-over'); });
    });

    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files.length) add(e.dataTransfer.files);
    });

    // Clicking anywhere in the zone opens the picker, except on the controls.
    zone.addEventListener('click', function (e) {
      if (e.target.closest('button, input, label, a')) return;
      input.click();
    });

    input.addEventListener('change', function () {
      if (input.files && input.files.length) {
        var picked = Array.prototype.slice.call(input.files);
        // The picker replaces the selection, so start from what it gave us and
        // re-add anything already queued that it did not include.
        var existing = queue.slice();
        queue = [];
        add(picked);
        add(existing);
      }
    });

    // Pasting a screenshot straight in is the fastest path for box scans.
    document.addEventListener('paste', function (e) {
      if (!e.clipboardData || !e.clipboardData.files.length) return;
      if (!zone.closest('form')) return;
      add(e.clipboardData.files);
    });
  });

  // --- Show how many photos are queued -------------------------------------
  document.querySelectorAll('[data-filecount]').forEach(function (input) {
    var out = document.querySelector(input.dataset.filecount);
    if (!out) return;
    input.addEventListener('change', function () {
      var n = input.files ? input.files.length : 0;
      out.textContent = n === 0 ? 'No photos selected.'
        : n + (n === 1 ? ' photo ready to upload.' : ' photos ready to upload.');
    });
  });

  // --- Directory form: fill the fiddly bits in from the type ---------------
  //
  // The presets come from the server so PHP and the browser cannot drift apart:
  // ldap_default_params() is the single definition of what an AD or an OpenLDAP
  // install normally looks like.
  var ldapForm = document.querySelector('[data-ldap-form]');
  if (ldapForm) {
    var presets = {};
    try { presets = JSON.parse(ldapForm.dataset.presets || '{}'); } catch (e) { presets = {}; }

    var typeSelect = ldapForm.querySelector('[data-ldap-type]');
    var encSelect  = ldapForm.querySelector('[data-ldap-encrypted]');
    var portInput  = ldapForm.querySelector('[data-ldap-port]');

    var applyType = function () {
      var preset = presets[typeSelect.value];
      if (!preset) return;
      ldapForm.querySelectorAll('[data-ldap-field]').forEach(function (el) {
        var key = el.dataset.ldapField;
        if (!(key in preset)) return;
        if (el.type === 'checkbox') {
          el.checked = !!preset[key];
        } else {
          el.value = preset[key];
        }
      });
    };

    var standardPorts = [389, 636];
    var applyEncryption = function () {
      if (!portInput) return;
      var wanted = encSelect.checked ? 636 : 389;
      // Leave a deliberately odd port alone; only correct the standard ones.
      if (portInput.value === '' || standardPorts.indexOf(parseInt(portInput.value, 10)) !== -1) {
        portInput.value = wanted;
      }
    };

    if (typeSelect) {
      typeSelect.addEventListener('change', function () {
        applyType();
        // Opening the drawer makes it obvious that fields just changed.
        var adv = ldapForm.querySelector('details.advanced');
        if (adv) adv.open = true;
      });
    }
    var certField = ldapForm.querySelector('[data-cert-field]');
    var certBox   = ldapForm.querySelector('[data-verify-cert]');

    // Greyed rather than hidden: a field that vanishes makes the form jump and
    // leaves people wondering where it went. Disabled and dimmed says plainly
    // that it exists but has nothing to act on.
    var applyCertVisibility = function () {
      if (!certField || !encSelect) return;
      var on = encSelect.checked;
      certField.classList.toggle('field--disabled', !on);
      if (certBox) {
        certBox.disabled = !on;
        // Validating nothing is not a meaningful state to leave ticked.
        if (!on) { certBox.checked = false; }
      }
      // Nothing to say when encryption is on: the checkbox next to it already
      // reads "Validate the server certificate", and a sentence telling somebody
      // when they are allowed to untick it was advice rather than information.
      var hint = certField.querySelector('.hint');
      if (hint) {
        hint.textContent = on ? '' : 'Nothing to validate on an unencrypted connection.';
      }
    };

    if (encSelect) {
      encSelect.addEventListener('change', function () {
        applyEncryption();
        applyCertVisibility();
      });
      applyCertVisibility();

      // Bring the port into line on load as well, otherwise the checkbox and
      // the port can disagree until somebody happens to toggle it - which is
      // exactly the sort of thing you only notice after a failed connection.
      // Only when adding: an existing directory's stored port is deliberate
      // and must not be rewritten just by looking at the page.
      if (!ldapForm.querySelector('input[name="id"]')) {
        applyEncryption();
      }
    }
  }

  // --- Reveal a masked secret ----------------------------------------------
  document.querySelectorAll('[data-reveal-toggle]').forEach(function (button) {
    var input = button.parentNode.querySelector('[data-reveal-input]');
    if (!input) return;
    button.addEventListener('click', function () {
      var hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      button.textContent = hidden ? 'hide' : 'show';
      button.setAttribute('aria-pressed', hidden ? 'true' : 'false');
    });
  });

  // --- Metadata form: only ask for a key when the source uses one ----------
  var mdForm = document.querySelector('[data-metadata-form]');
  if (mdForm) {
    var needsKey = {};
    var homepages = {};
    try { needsKey = JSON.parse(mdForm.dataset.keyTypes || '{}'); } catch (e) { needsKey = {}; }
    try { homepages = JSON.parse(mdForm.dataset.homepages || '{}'); } catch (e) { homepages = {}; }

    var typeSelect = mdForm.querySelector('select[name="type"]');
    // Every credential box, each carrying the sources that ask for it.
    //
    // There used to be exactly one, called the API key, and it was shown or
    // hidden by whether the chosen source needed "a key". That could not express
    // IGDB, which needs two things with different names.
    var credFields = mdForm.querySelectorAll('[data-cred-field]');

    if (typeSelect && credFields.length) {
      var applyKeyVisibility = function () {
        var type = typeSelect.value;
        Array.prototype.forEach.call(credFields, function (field) {
          var forTypes = (field.getAttribute('data-cred-types') || '').split(/\s+/);
          var wanted   = forTypes.indexOf(type) !== -1;
          field.hidden = !wanted;
          // The same box can be two things: api_key is TheGamesDB's "API key"
          // and IGDB's "Client secret".
          var label = field.querySelector('[data-cred-label]');
          if (wanted && label) {
            var labels = {};
            try { labels = JSON.parse(field.getAttribute('data-cred-labels') || '{}'); } catch (e) {}
            if (labels[type]) { label.textContent = labels[type]; }
          }
          var hint = field.querySelector('[data-cred-hint]');
          if (wanted && hint && homepages[type]) {
            hint.textContent = 'Get it at ' + homepages[type] +
              '. Clearing this box and saving removes the stored value.';
          }
        });
      };
      typeSelect.addEventListener('change', applyKeyVisibility);
      applyKeyVisibility();
    }
  }

  // --- Close the user menu when clicking elsewhere -------------------------
  document.addEventListener('click', function (ev) {
    document.querySelectorAll('details.usermenu[open]').forEach(function (d) {
      if (!d.contains(ev.target)) d.removeAttribute('open');
    });
  });
})();

/**
 * The specification list on the hardware form.
 *
 * Choosing a model used to reload the whole page so the server could redraw the
 * model's fields. That threw away everything already typed, which is a poor
 * trade for avoiding a little JavaScript — and it happened on every change of
 * the select, including the one you make halfway through filling the form in.
 *
 * The suggestions for every model on the page are already in the DOM, so
 * filling the list is local and instant. Everything still works without
 * JavaScript: the rows are ordinary inputs, and the server accepts whatever
 * arrives.
 */
(function () {
  'use strict';

  var box = document.querySelector('[data-specs]');
  if (!box) { return; }

  var rows     = box.querySelector('[data-spec-rows]');
  var tmpl     = box.querySelector('[data-spec-template]');
  var addBtn   = box.querySelector('[data-spec-add]');
  var resetBtn = box.querySelector('[data-spec-reset]');
  if (!rows || !tmpl) { return; }

  var suggestions = {};
  try { suggestions = JSON.parse(box.dataset.suggestions || '{}'); } catch (e) { suggestions = {}; }

  function newRow(label, value) {
    var frag = tmpl.content.cloneNode(true);
    var el = frag.querySelector('[data-spec-row]');
    if (label) { el.querySelector('[name="hw_spec_label[]"]').value = label; }
    if (value) { el.querySelector('[name="hw_spec_value[]"]').value = value; }
    return el;
  }

  function addRow(label, value, focus) {
    var el = newRow(label, value);
    rows.appendChild(el);
    if (focus) { el.querySelector('input').focus(); }
    return el;
  }

  /** Is every row still exactly as some model suggested it? */
  function isPristine(against) {
    var current = Array.prototype.map.call(rows.querySelectorAll('[data-spec-row]'), function (r) {
      return [r.querySelector('[name="hw_spec_label[]"]').value.trim(),
              r.querySelector('[name="hw_spec_value[]"]').value.trim()].join('\u0000');
    }).filter(function (s) { return s !== '\u0000'; });

    if (current.length === 0) { return true; }
    if (!against || against.length !== current.length) { return false; }
    return against.every(function (row, i) {
      return current[i] === [(row.label || '').trim(), (row.value || '').trim()].join('\u0000');
    });
  }

  function fillFrom(modelId, force) {
    var rowsFor = suggestions[String(modelId)];

    // Never overwrite work. If the list has been touched, offer the model's
    // rows on a button instead of taking the decision away.
    if (!force && !isPristine(lastApplied)) {
      if (resetBtn && rowsFor) { resetBtn.hidden = false; }
      return;
    }

    rows.innerHTML = '';
    (rowsFor || []).forEach(function (r) { addRow(r.label, r.value, false); });
    if (!rowsFor || !rowsFor.length) { addRow('', '', false); }
    lastApplied = rowsFor || null;
    if (resetBtn) { resetBtn.hidden = true; }
  }

  var lastApplied = null;

  // The object's name defaults to the model's. Typing over it wins, and having
  // typed once the field is left alone - the point is to save the common case
  // of "an Amiga 500 is called an Amiga 500", not to fight anybody.
  var titleField = document.querySelector('[data-title-from-model]');
  var titleTouched = false;
  if (titleField) {
    titleTouched = titleField.value.trim() !== '';
    titleField.addEventListener('input', function () { titleTouched = true; });
  }

  function nameFromModel(sel) {
    if (!titleField || titleTouched) { return; }
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { return; }
    // Options read "Amiga 500, 1987"; the year is context for choosing, not
    // part of what the thing is called.
    titleField.value = opt.textContent.replace(/,\s*\d{4}\s*$/, '').trim();
  }

  document.querySelectorAll('[data-model-select], [data-part-select]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      nameFromModel(sel);
      fillFrom(sel.value, false);
    });
  });

  if (addBtn) {
    addBtn.addEventListener('click', function () { addRow('', '', true); });
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      var sel = document.querySelector('[data-model-select], [data-part-select]');
      if (sel) { fillFrom(sel.value, true); }
    });
  }

  // Move, add-below and remove - the same four the model editors offer, so the two
  // screens that edit the same shape of thing behave the same way. Ends are disabled
  // rather than hidden, because a control that vanishes is one you go looking for.
  function specEnds() {
    var all = rows.querySelectorAll('[data-spec-row]');
    Array.prototype.forEach.call(all, function (r, i) {
      var up = r.querySelector('[data-spec-up]');
      var dn = r.querySelector('[data-spec-down]');
      if (up) { up.disabled = i === 0; }
      if (dn) { dn.disabled = i === all.length - 1; }
    });
  }

  rows.addEventListener('click', function (ev) {
    var move = ev.target.closest('[data-spec-up], [data-spec-down]');
    if (move) {
      var here = move.closest('[data-spec-row]');
      if (!here) { return; }
      if (move.hasAttribute('data-spec-up')) {
        var before = here.previousElementSibling;
        if (before) { rows.insertBefore(here, before); }
      } else {
        var after = here.nextElementSibling;
        if (after) { rows.insertBefore(after, here); }
      }
      specEnds();
      // The same control stays under the pointer after the row moves.
      move.focus();
      return;
    }

    var addAfter = ev.target.closest('[data-spec-addafter]');
    if (addAfter) {
      var at = addAfter.closest('[data-spec-row]');
      addRow('', '', false);
      var made = rows.lastElementChild;
      if (at && made && made !== at) { rows.insertBefore(made, at.nextElementSibling); }
      var first = made && made.querySelector('input');
      if (first) { first.focus(); }
      specEnds();
      return;
    }

    var btn = ev.target.closest('[data-spec-remove]');
    if (!btn) { return; }
    var row = btn.closest('[data-spec-row]');
    if (row) { row.remove(); }
    // Removing the last row leaves none, which is a legitimate state.
    //
    // This used to put a blank one back, so a model with no contents always showed one
    // empty pair of boxes and "delete the last one" quietly did nothing. Where there is
    // an Add button the empty list has a way out of itself; that is what the button is
    // for.
    if (addBtn && !rows.querySelector('[data-spec-row]')) {
        addBtn.removeAttribute('hidden');
    } else if (!addBtn && !rows.querySelector('[data-spec-row]')) {
        addRow('', '', false);
    }
    specEnds();
  });

  specEnds();

  // Typing in the last row grows the list, so adding five things is five lines
  // of typing rather than five clicks and five lines of typing.
  rows.addEventListener('input', function (ev) {
    var row = ev.target.closest('[data-spec-row]');
    if (!row || row !== rows.lastElementChild) { return; }
    if (ev.target.value.trim() !== '') { addRow('', '', false); specEnds(); }
  });

  // An empty list stays empty where there is a button to fill it.
  //
  // This seeded a blank row on load, which is right for a form with no Add button - the
  // hardware entry form, where the list is the only way in - and wrong for the software
  // model editor, which has one and is meant to start with nothing. Asking whether the
  // button exists is the difference between the two.
  if (!rows.querySelector('[data-spec-row]') && !addBtn) {
    addRow('', '', false);
  }
})();

(function () {
  'use strict';

  /* Platform and manufacturer both narrow the machine model list.
   *
   * Two bugs lived here. The vendor test compared a model's data-vendor, which is
   * a slug, against the manufacturer select's value, which is a row id - so it
   * never matched and choosing a manufacturer emptied the list completely. And
   * platform was not considered at all, so picking Amiga still offered the Game
   * Boy and a 486 PC.
   *
   * Both are settled by comparing slugs, read off the selected option rather than
   * from its value: a model is a template row and the selects list this library's
   * copies, so the ids belong to different spaces and only the slug is shared. */
  var vendorSel = document.querySelector('[data-vendor-select]');
  var platSel   = document.querySelector('[data-platform-select]');
  var modelSel  = document.querySelector('[data-model-select]');
  if (!modelSel || (!vendorSel && !platSel)) { return; }

  var all = Array.prototype.map.call(modelSel.options, function (o) {
    return {
      value: o.value, text: o.text,
      vendor: o.dataset.vendor || '', platform: o.dataset.platform || ''
    };
  });

  function slugOf(sel) {
    if (!sel || sel.selectedIndex < 0) { return ''; }
    var o = sel.options[sel.selectedIndex];
    return o ? (o.dataset.slug || '') : '';
  }

  function apply() {
    var wantVendor = slugOf(vendorSel);
    var wantPlat   = slugOf(platSel);
    var keep       = modelSel.value;

    modelSel.innerHTML = '';
    all.forEach(function (m) {
      // "Not a specific model" always stays, and so does whatever is currently
      // chosen: dropping a saved answer because the filters no longer agree with
      // it would silently detach the model from an entry on opening its form.
      if (m.value !== '' && m.value !== keep) {
        if (wantVendor !== '' && m.vendor !== wantVendor) { return; }
        if (wantPlat !== '' && m.platform !== wantPlat) { return; }
      }
      var o = document.createElement('option');
      o.value = m.value;
      o.textContent = m.text;
      o.dataset.vendor = m.vendor;
      o.dataset.platform = m.platform;
      modelSel.appendChild(o);
    });

    modelSel.value = Array.prototype.some.call(modelSel.options,
      function (o) { return o.value === keep; }) ? keep : '';
  }

  if (vendorSel) { vendorSel.addEventListener('change', apply); }
  if (platSel)   { platSel.addEventListener('change', apply); }
  apply();
})();



/* Title picker on the entry form.
 *
 * Software has a canonical `titles` record the same way hardware has
 * `hardware_models`, so a second copy of a game should not mean retyping its
 * year, developer and genre. This links the entry to one if it exists, and
 * hands the typed name to the server to create if it does not.
 *
 * Everything still works with JavaScript off: the plain text field posts as
 * `title`, and the server falls back to matching or creating a title by name.
 */
(function () {
  'use strict';

  var wrap = document.querySelector('[data-title-picker]');
  if (!wrap) { return; }

  var input   = wrap.querySelector('[data-title-input]');
  var idField = wrap.querySelector('[data-title-id]');
  var nameFld = wrap.querySelector('[data-title-name]');
  var results = wrap.querySelector('[data-title-results]');
  var hint    = wrap.querySelector('[data-title-hint]');
  if (!input || !idField || !results) { return; }

  var base = document.querySelector('link[rel=stylesheet]');
  var prefix = base ? base.getAttribute('href').split('/assets/')[0] : '';

  var timer = null;
  var lastQuery = '';

  function platformId() {
    var sel = document.querySelector('[name="platform_id"]');
    return sel && sel.value ? sel.value : '';
  }

  function clearLink() {
    idField.value = '';
    // The typed name still travels, so the server can create the title.
    if (nameFld) { nameFld.value = input.value; }
  }

  function choose(row) {
    idField.value = String(row.id);
    if (nameFld) { nameFld.value = ''; }
    input.value = row.name;
    results.hidden = true;
    if (hint) {
      hint.textContent = 'Linked to "' + row.name + '"'
        + (row.year ? ' (' + row.year + ')' : '')
        + '. Anything you leave blank is inherited from it.';
    }
  }

  function render(rows) {
    results.innerHTML = '';
    if (!rows.length) { results.hidden = true; return; }

    rows.forEach(function (row) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'typeahead__row';
      var bits = [row.name];
      if (row.year) { bits.push(String(row.year)); }
      if (row.developer) { bits.push(row.developer); }
      if (row.copies) { bits.push(row.copies + (row.copies === 1 ? ' copy' : ' copies') + ' owned'); }
      b.textContent = bits.join(' · ');
      b.addEventListener('click', function () { choose(row); });
      results.appendChild(b);
    });
    results.hidden = false;
  }

  input.addEventListener('input', function () {
    clearLink();
    var q = input.value.trim();
    if (timer) { window.clearTimeout(timer); }
    if (q.length < 2) { results.hidden = true; return; }
    if (q === lastQuery) { return; }

    timer = window.setTimeout(function () {
      lastQuery = q;
      var url = prefix + '/titles/search?q=' + encodeURIComponent(q)
              + '&platform_id=' + encodeURIComponent(platformId());
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(render)
        .catch(function () { results.hidden = true; });
    }, 200);
  });

  // Clicking away closes the list without choosing anything.
  document.addEventListener('click', function (e) {
    if (!wrap.contains(e.target)) { results.hidden = true; }
  });
})();

/* Mail relay settings: show only what applies.
 *
 * A relay on your own network usually wants neither encryption nor a sign-in,
 * so those are questions the form asks only once you say yes to them. Choosing
 * an encryption mode fills in the port that mode normally uses, which is right
 * almost always and editable for the times it is not.
 *
 * Without JavaScript every field is simply visible, which is the old form and
 * still works. */
(function () {
  'use strict';

  var form = document.querySelector('[data-smtp-form]');
  if (!form) { return; }

  function reveal(box, panel) {
    if (!box || !panel) { return; }
    var sync = function () { panel.hidden = !box.checked; };
    box.addEventListener('change', sync);
    sync();
  }

  var encrypted = form.querySelector('[data-smtp-encrypted]');
  var security  = form.querySelector('[data-smtp-security]');
  var port      = form.querySelector('[data-smtp-port]');

  reveal(encrypted, form.querySelector('[data-smtp-encryption]'));
  reveal(form.querySelector('[data-smtp-auth]'), form.querySelector('[data-smtp-credentials]'));

  // The ports each arrangement conventionally uses. Only ever applied when the
  // box currently holds one of them or is empty: somebody who typed 2525
  // because their relay is on 2525 should not have it overwritten by ticking a
  // different checkbox.
  var conventional = ['25', '587', '465'];

  function suggestPort(value) {
    if (!port) { return; }
    var current = String(port.value || '').trim();
    if (current !== '' && conventional.indexOf(current) === -1) { return; }
    port.value = value;
  }

  if (encrypted) {
    encrypted.addEventListener('change', function () {
      if (!encrypted.checked) {
        suggestPort('25');
        return;
      }
      var opt = security && security.options[security.selectedIndex];
      suggestPort(opt && opt.dataset.port ? opt.dataset.port : '587');
    });
  }

  if (security) {
    security.addEventListener('change', function () {
      var opt = security.options[security.selectedIndex];
      if (opt && opt.dataset.port) { suggestPort(opt.dataset.port); }
    });
  }
})();

/* Adding a place: say what floor it will land on.
 *
 * Floors are inherited, so a shelf added inside a room on floor 1 is on floor 1
 * with nothing typed. That is the right behaviour and it was completely
 * invisible — the box said "—" whatever you picked as its parent, which reads
 * like "no floor" rather than "the same as upstairs".
 *
 * Without JavaScript the field is an ordinary number box and blank still
 * inherits; this only ever tells you what blank is going to mean. */
(function () {
  'use strict';

  var wrap = document.querySelector('[data-location-add]');
  if (!wrap) { return; }

  var form   = wrap.closest('form');
  var parent = wrap.querySelector('[data-parent-select]');
  var floor  = form && form.querySelector('[data-floor-input]');
  var hint   = form && form.querySelector('[data-floor-hint]');
  if (!parent || !floor) { return; }

  var baseHint = hint ? hint.textContent.trim() : '';

  // Matches floor_label() on the server. The number, not a name for it: a
  // building with two basements or one that counts from 1 needs no new words.
  function label(level) {
    var n = parseInt(level, 10);
    return isNaN(n) ? null : 'floor ' + n;
  }

  parent.addEventListener('change', function () {
    var opt = parent.options[parent.selectedIndex];
    var inherited = opt ? label(opt.dataset.floor) : null;

    floor.placeholder = inherited === null ? '—' : opt.dataset.floor;

    if (!hint) { return; }
    // Short, to match what the field says when nothing is selected. The long
    // version restated the rule in two sentences every time the parent changed,
    // which is a paragraph appearing under a number field.
    hint.textContent = inherited === null
      ? baseHint
      : 'Blank means ' + inherited + ', same as its parent.';
  });
})();


/* Syslog forwarding: the address only matters once it is switched on. */
(function () {
  'use strict';
  var box = document.querySelector('[data-syslog-toggle]');
  var fields = document.querySelector('[data-syslog-fields]');
  if (!box || !fields) { return; }
  var sync = function () { fields.hidden = !box.checked; };
  box.addEventListener('change', sync);
  sync();
})();

/* The library switcher submits itself. A Go button beside a select is one click
   too many for something changed this often; the button is still there without
   JavaScript. */
(function () {
  'use strict';
  var form = document.querySelector('[data-library-switch]');
  if (!form) { return; }
  var sel = form.querySelector('select');
  if (sel) { sel.addEventListener('change', function () { form.submit(); }); }
})();

/* The log file path only matters once the file is switched on. */
(function () {
  'use strict';
  var box = document.querySelector('[data-logfile-toggle]');
  var fields = document.querySelector('[data-logfile-fields]');
  if (!box || !fields) { return; }
  var sync = function () { fields.hidden = !box.checked; };
  box.addEventListener('change', sync);
  sync();
})();

/* On the model form, the manufacturer narrows the platform list.
 *
 * Commodore did not make the Mega Drive, and a list that offers it is a list
 * you have to read past. Choosing "any manufacturer" puts everything back.
 *
 * Without JavaScript every platform is listed, which is the old behaviour and
 * still correct — this only removes choices that would be wrong. */
(function () {
  'use strict';

  var vendor   = document.querySelector('[data-model-vendor]');
  var platform = document.querySelector('[data-model-platform]');
  if (!vendor || !platform) { return; }

  // Machines only, and the template decides which this is.
  //
  // This used to run for every model. A machine's maker is the platform's maker,
  // so Commodore narrowing to the Amiga is right. A peripheral's maker is not:
  // the Blizzard 1230 IV is a Phase 5 card whose platform is Commodore's Amiga,
  // and choosing Phase 5 removed the Amiga from the list and reset the platform
  // on a model that had one.
  if (!platform.dataset.narrowByVendor) { return; }

  // Kept because filtering removes options from the DOM, and switching back to
  // "any" has to be able to put them there again.
  var every = Array.prototype.map.call(platform.options, function (o) {
    return { value: o.value, text: o.textContent, vendor: o.dataset.vendor || '' };
  });

  function narrowPlatforms() {
    // Ids, not slugs. Both selects on this form list template rows, so they
    // line up directly - the slug comparison this used belongs to the entry
    // form, where a library's makers have to be matched to the template's.
    var want = vendor.value;
    var keep = platform.value;

    platform.innerHTML = '';
    every.forEach(function (opt) {
      // The blank option always stays: a model may belong to no platform in
      // particular, and a card that fits several is a real thing.
      if (opt.value !== '' && want !== '' && opt.vendor !== want) { return; }
      var el = document.createElement('option');
      el.value = opt.value;
      el.textContent = opt.text;
      el.dataset.vendor = opt.vendor;
      platform.appendChild(el);
    });

    // Hold the current choice if it survived the filter; otherwise fall back to
    // "not decided" rather than silently selecting somebody else's machine.
    platform.value = Array.prototype.some.call(platform.options, function (o) {
      return o.value === keep;
    }) ? keep : '';
  }

  vendor.addEventListener('change', narrowPlatforms);

  // On load, not only on change. Opening a saved model showed every platform
  // until the manufacturer was re-picked, which made the filter look broken and
  // invited choosing a machine its maker never built.
  narrowPlatforms();
})();

/* The fields a machine model carries: one row per field.
 *
 * Three boxes rather than a line of "Name = value | one, two, three". The
 * syntax was compact and had to be learnt; boxes cannot be ambiguous about
 * which part is which.
 *
 * Without JavaScript the rows that are there still work — this only adds and
 * removes them, and a form with one row is still a usable form. */
(function () {
  'use strict';

  var box = document.querySelector('[data-modelfields]');
  if (!box) { return; }

  var rows = box.querySelector('[data-mf-rows]');
  var tmpl = box.querySelector('[data-mf-template]');
  var add  = box.querySelector('[data-mf-add]');
  if (!rows || !tmpl || !add) { return; }

  add.addEventListener('click', function () {
    rows.appendChild(tmpl.content.cloneNode(true));
    var made = rows.lastElementChild;
    var first = made && made.querySelector('input');
    if (first) { first.focus(); }
  });

  // The order of the rows is the order of the fields: they post as parallel
  // arrays and the save numbers them as it walks. So moving the element is the
  // whole of it - no hidden position to keep in step, and nothing to disagree
  // with what is on screen.
  function ends() {
    var all = rows.querySelectorAll('[data-mf-row]');
    // Add is the way in when there is nothing there. Once there is a row it has
    // one of its own, so a second button above the list is a second thing doing
    // the same job - and it reappears the moment the last row goes.
    if (add) { add.hidden = all.length > 0; }
    all.forEach(function (row, i) {
      var up = row.querySelector('[data-mf-up]');
      var dn = row.querySelector('[data-mf-down]');
      if (up) { up.disabled = i === 0; }
      if (dn) { dn.disabled = i === all.length - 1; }
    });
  }

  rows.addEventListener('click', function (e) {
    var after = e.target.closest('[data-mf-addafter]');
    if (after) {
      var host = after.closest('[data-mf-row]');
      var made = tmpl.content.cloneNode(true);
      rows.insertBefore(made, host ? host.nextSibling : null);
      ends();
      var made2 = host ? host.nextElementSibling : rows.lastElementChild;
      var first = made2 && made2.querySelector('input');
      if (first) { first.focus(); }
      return;
    }

    var move = e.target.closest('[data-mf-up], [data-mf-down]');
    if (move) {
      var here = move.closest('[data-mf-row]');
      if (!here) { return; }
      if (move.hasAttribute('data-mf-up')) {
        var before = here.previousElementSibling;
        if (before) { rows.insertBefore(here, before); }
      } else {
        var after = here.nextElementSibling;
        if (after) { rows.insertBefore(after, here); }
      }
      ends();
      // Keep the button under the pointer usable: after a move the same
      // control should still be the one focused, not whatever took its place.
      move.focus();
      return;
    }

    var btn = e.target.closest('[data-mf-remove]');
    if (!btn) { return; }
    var row = btn.closest('[data-mf-row]');
    if (!row) { return; }

    // The last row goes too. A model with no specifications is a real thing -
    // a bare console that records nothing worth a box - and refusing to remove
    // the last one made it impossible to say so. Add is still there.
    row.remove();
    ends();
  });

  var addBtn = box.querySelector('[data-mf-add]');
  if (addBtn) { addBtn.addEventListener('click', ends); }
  ends();
})();

/* Hardware compatibility follows the platform.
 *
 * The box lists every machine model grouped by platform, because a card can fit
 * machines on more than one. But once a platform is chosen, the machines on other
 * platforms are noise — and ticking one would claim a Zorro card fits a Game Boy.
 *
 * Groups are hidden rather than removed, so switching back brings them straight
 * out again with their ticks intact. Without JavaScript everything is listed,
 * which is the honest fallback: more choices, none of them wrong to offer. */
(function () {
  'use strict';

  var box  = document.querySelector('[data-fits-box]');
  var plat = document.querySelector('[data-mfg-platform], [data-model-platform], [data-platform-select]');
  if (!box || !plat) { return; }
  // A locked box is what the model says, shown read-only. Narrowing it by the
  // platform select would hide part of that answer, which is not this script's to
  // edit.
  if (box.hasAttribute('data-fits-locked')) { return; }

  function apply() {
    var want = plat.value;
    var rows = box.querySelectorAll('label.checkline');
    var seen = {};

    rows.forEach(function (row) {
      var input = row.querySelector('input[type="checkbox"]');
      var mine  = input ? (input.dataset.platform || '') : '';
      // A ticked box always stays visible, even if the platform moved: hiding a
      // claim somebody made is worse than showing one that no longer fits, and
      // they can untick it.
      var show  = want === '' || mine === want || (input && input.checked);
      row.hidden = !show;
      if (show) { seen[mine] = true; }
    });

    // A heading with nothing under it is a heading for nothing.
    box.querySelectorAll('.fitsbox__group').forEach(function (h) {
      var any = false;
      var el  = h.nextElementSibling;
      while (el && !el.classList.contains('fitsbox__group')) {
        if (el.matches('label.checkline') && !el.hidden) { any = true; break; }
        el = el.nextElementSibling;
      }
      h.hidden = !any;
    });
  }

  plat.addEventListener('change', apply);
  apply();
})();

/* Adding a peripheral: the platform narrows the list, and the chosen model
 * fills in what it already knows.
 *
 * Matching is by slug, not id. A peripheral model names the *template* platform
 * while this form lists the library's copy of it — the same boundary that has
 * caught six queries in this codebase, and the reason an id comparison here
 * silently matched nothing.
 *
 * Kind and manufacturer come from the model because the model decided them once:
 * re-asking invites two answers to one question. Both stay editable — a model is
 * a starting point, not a contract. */
(function () {
  'use strict';

  var plat = document.querySelector('[data-platform-select]');
  var part = document.querySelector('[data-part-select]');
  if (!plat || !part) { return; }

  var kind  = document.querySelector('#category_id, [data-kind-select]');
  var maker = document.querySelector('[data-vendor-select]');

  var every = Array.prototype.slice.call(part.options).map(function (o) {
    return {
      value: o.value, text: o.textContent.trim(),
      platform: o.dataset.platform || '', category: o.dataset.category || '',
      maker: o.dataset.maker || '', iface: o.dataset.iface || '', fits: o.dataset.fits || ''
    };
  });

  function chosenSlug() {
    var o = plat.options[plat.selectedIndex];
    return o ? (o.dataset.slug || '') : '';
  }

  function narrow() {
    var want = chosenSlug();
    var keep = part.value;

    part.innerHTML = '';
    every.forEach(function (opt) {
      // "Not on the list" always stays: most peripherals somebody owns are not
      // in the catalogue yet, and that has to remain the easy answer.
      if (opt.value !== '' && want !== '' && opt.platform !== want) { return; }
      var el = document.createElement('option');
      el.value = opt.value;
      el.textContent = opt.text;
      Object.keys(opt).forEach(function (k) {
        if (k !== 'value' && k !== 'text') { el.dataset[k] = opt[k]; }
      });
      part.appendChild(el);
    });

    part.value = Array.prototype.some.call(part.options, function (o) {
      return o.value === keep;
    }) ? keep : '';
  }

  var fitsLine = document.querySelector('[data-part-fits]');

  function showFits(o) {
    if (!fitsLine) { return; }
    // What the model fits, read only: it is a fact about the product, not about
    // this particular card, so it is shown rather than asked. Where yours is
    // actually installed is a different question and not this field.
    var list = o ? (o.dataset.compatiblemodels || '') : '';
    var note = o ? (o.dataset.fits || '') : '';
    var text = list || note;
    fitsLine.textContent = text ? 'Fits ' + text : '';
    fitsLine.hidden = text === '';
  }

  function inherit() {
    var o = part.options[part.selectedIndex];
    showFits(o && o.value !== '' ? o : null);
    if (!o || o.value === '') { return; }

    if (kind && o.dataset.category && o.dataset.category !== '0') {
      kind.value = o.dataset.category;
    }
    if (plat && o.dataset.platform) {
      // By slug: the model names the template platform, this select lists the
      // library's copy of it.
      Array.prototype.forEach.call(plat.options, function (opt) {
        if (opt.dataset.slug === o.dataset.platform) { plat.value = opt.value; }
      });
    }
    if (maker && o.dataset.maker) {
      // By slug again: the model names the template maker, the select lists the
      // library's.
      Array.prototype.forEach.call(maker.options, function (opt) {
        if (opt.dataset.slug === o.dataset.maker) { maker.value = opt.value; }
      });
    }
  }

  plat.addEventListener('change', narrow);
  part.addEventListener('change', inherit);
  narrow();
  // On load too, so reopening a saved entry shows what its model fits.
  showFits(part.options[part.selectedIndex]);
})();

/* Grading a box that is not there is a question with no answer, so the grade only
   appears once there is a box to grade. Progressive disclosure only - the server
   clears the grade itself when the box is unticked, so this working or not cannot
   change what gets stored. */
(function () {
  var box = document.querySelector('[data-has-box]');
  var grade = document.querySelector('[data-box-grade]');
  if (!box || !grade) { return; }

  function sync() {
    if (box.checked) { grade.removeAttribute('hidden'); }
    else { grade.setAttribute('hidden', 'hidden'); }
  }
  box.addEventListener('change', sync);
  sync();
})();

/* Sections that can be switched off: Acquire and Sale on the hardware form.
   Progressive disclosure only — items_payload() clears the fields server-side when
   the box is unticked, so this failing changes what you see and never what gets
   stored. The date inputs are cleared here too, because a browser will not always
   let you empty one once its calendar has been opened, which is the whole reason
   these toggles exist. */
(function () {
  var boxes = document.querySelectorAll('[data-toggle]');

  // Sale is driven by the status select rather than a tick of its own, so that
  // "status: owned" and a filled-in sale block cannot both be on screen.
  var statusSel = document.querySelector('[data-toggle-when-sold]');
  if (statusSel) {
    var saleKey   = statusSel.getAttribute('data-toggle-when-sold');
    var saleBody  = document.querySelector('[data-toggle-body="' + saleKey + '"]');
    var saleEmpty = document.querySelector('[data-toggle-empty="' + saleKey + '"]');
    if (saleBody) {
      var syncSale = function () {
        if (statusSel.value === 'sold') {
          saleBody.removeAttribute('hidden');
          if (saleEmpty) { saleEmpty.setAttribute('hidden', 'hidden'); }
        } else {
          saleBody.setAttribute('hidden', 'hidden');
          if (saleEmpty) { saleEmpty.removeAttribute('hidden'); }
          Array.prototype.forEach.call(saleBody.querySelectorAll('input, textarea'), function (f) {
            if (f.type === 'checkbox' || f.type === 'radio') { f.checked = false; }
            else { f.value = ''; }
          });
        }
      };
      statusSel.addEventListener('change', syncSale);
      syncSale();
    }
  }

  if (!boxes.length) { return; }

  Array.prototype.forEach.call(boxes, function (box) {
    var key    = box.getAttribute('data-toggle');
    // querySelectorAll, not querySelector.
    //
    // A toggle usually governs more than one part of a form - the box contents
    // are in one fieldset and the box's condition is in another - and taking the
    // first match left every section after the first one on screen. Unticking
    // "there is a box" hid the contents and left Box condition sitting there.
    var bodies = document.querySelectorAll('[data-toggle-body="' + key + '"]');
    var empties = document.querySelectorAll('[data-toggle-empty="' + key + '"]');
    if (!bodies.length) { return; }

    function sync() {
      Array.prototype.forEach.call(bodies, function (body) {
        if (box.checked) {
          body.removeAttribute('hidden');
        } else {
          body.setAttribute('hidden', 'hidden');
          // Emptied on the way out, so reopening the section does not show
          // figures the server has already been told to discard.
          Array.prototype.forEach.call(body.querySelectorAll('input, textarea'), function (f) {
            if (f.type === 'checkbox' || f.type === 'radio') { f.checked = false; }
            else { f.value = ''; }
          });
        }
      });
      Array.prototype.forEach.call(empties, function (empty) {
        if (box.checked) { empty.setAttribute('hidden', 'hidden'); }
        else { empty.removeAttribute('hidden'); }
      });
    }
    box.addEventListener('change', sync);
    sync();
  });
})();

/* Private or shared, on the library pages. The "open to everyone signed in" flags
   only mean anything on a shared library - a private one ignores them - so showing
   them beside "private" would be offering a choice that does nothing.

   Progressive disclosure only: the server clears both flags for a private library
   regardless, so this failing cannot store a private library that is readable by
   everybody. */
(function () {
  var kinds  = document.querySelectorAll('[data-library-kind]');
  var shared = document.querySelector('[data-shared-only]');
  if (!kinds.length || !shared) { return; }

  function sync() {
    var on = false;
    Array.prototype.forEach.call(kinds, function (r) {
      if (r.checked && r.value === 'shared') { on = true; }
    });
    if (on) { shared.removeAttribute('hidden'); }
    else {
      shared.setAttribute('hidden', 'hidden');
      Array.prototype.forEach.call(shared.querySelectorAll('input[type="checkbox"]'), function (c) {
        c.checked = false;
      });
    }
  }
  Array.prototype.forEach.call(kinds, function (r) { r.addEventListener('change', sync); });
  sync();
})();

/* Collapsing the category editor.
   The server marks which rows have children and which are on the selected node's
   path; this only decides what is currently visible. A row is shown when every
   ancestor of it is open, which is computed by walking the flat list once in order -
   the rows arrive depth-first, so a parent is always seen before its children. */
(function () {
  var tree = document.querySelector('[data-tree]');
  if (!tree) { return; }

  var rows = Array.prototype.slice.call(tree.querySelectorAll('.treerow'));
  if (!rows.length) { return; }

  var open = {};
  rows.forEach(function (row) {
    if (row.hasAttribute('data-open')) { open[row.getAttribute('data-node')] = true; }
  });

  function paint() {
    var visible = { '0': true };   // the roots' notional parent
    rows.forEach(function (row) {
      var id     = row.getAttribute('data-node');
      var parent = row.getAttribute('data-parent');
      // Platform and domain rows are drawn by the page, not stored, so they carry no
      // id and no parent. They are signposts: always shown, and they do not take part
      // in what is open.
      if (id === null) { row.classList.remove('is-hidden'); return; }
      var show   = visible[parent] === true;
      row.classList.toggle('is-hidden', !show);
      // Children of this row are visible only if it is both shown and open.
      visible[id] = show && open[id] === true;
      var btn = row.querySelector('[data-toggle-node]');
      if (btn) { btn.setAttribute('aria-expanded', open[id] ? 'true' : 'false'); }
      if (open[id]) { row.setAttribute('data-open', ''); }
      else { row.removeAttribute('data-open'); }
    });
  }

  // Open or shut everything.
  //
  // In here rather than in a script of its own, because `open` is what paint() reads:
  // setting data-open from outside would be writing to a mirror, and the next repaint
  // would put it straight back. Sixty-three roots is a lot of chevrons when you are
  // looking for one thing and do not know where it is.
  function setAll(want) {
    rows.forEach(function (row) {
      var id = row.getAttribute('data-node');
      if (id === null || !row.hasAttribute('data-haskids')) { return; }
      if (want) { open[id] = true; } else { delete open[id]; }
    });
    paint();
  }
  var expandBtn   = document.querySelector('[data-tree-expand]');
  var collapseBtn = document.querySelector('[data-tree-collapse]');
  if (expandBtn)   { expandBtn.addEventListener('click',   function () { setAll(true); }); }
  if (collapseBtn) { collapseBtn.addEventListener('click', function () { setAll(false); }); }

  tree.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-toggle-node]') : null;
    if (!btn) { return; }
    ev.preventDefault();
    var id = btn.getAttribute('data-toggle-node');
    open[id] = !open[id];
    paint();
  });

  paint();
})();

/* Typed confirmation. Shows how far you have got against the name still on screen:
   amber while it is a correct prefix, green on an exact match, plain when it has
   diverged. Cosmetic only - the server compares the string itself, so this being
   wrong or absent cannot delete anything. */
(function () {
  var input  = document.querySelector('[data-confirm-input]');
  var target = document.querySelector('[data-confirm-target]');
  if (!input || !target) { return; }

  var want = target.textContent.trim();
  input.addEventListener('input', function () {
    var got = input.value;
    input.classList.remove('is-part', 'is-match');
    if (got === '') { return; }
    if (got === want) { input.classList.add('is-match'); }
    else if (want.indexOf(got) === 0) { input.classList.add('is-part'); }
  });
})();

/* The "what is in the box" rows on the title editor.
   Same four controls as the specification rows, on the same reasoning: two screens that
   edit a list of lines should behave identically. */
(function () {
  var rows = document.querySelector('[data-tc-rows]');
  if (!rows) { return; }

  function ends() {
    var all = rows.querySelectorAll('[data-tc-row]');
    Array.prototype.forEach.call(all, function (r, i) {
      var up = r.querySelector('[data-tc-up]');
      var dn = r.querySelector('[data-tc-down]');
      if (up) { up.disabled = i === 0; }
      if (dn) { dn.disabled = i === all.length - 1; }
    });
  }

  function blank(after) {
    var first = rows.querySelector('[data-tc-row]');
    var made  = first.cloneNode(true);
    Array.prototype.forEach.call(made.querySelectorAll('input'), function (i) { i.value = ''; });
    if (after && after.nextSibling) { rows.insertBefore(made, after.nextSibling); }
    else { rows.appendChild(made); }
    return made;
  }

  rows.addEventListener('click', function (ev) {
    var move = ev.target.closest('[data-tc-up], [data-tc-down]');
    if (move) {
      var here = move.closest('[data-tc-row]');
      if (move.hasAttribute('data-tc-up')) {
        var before = here.previousElementSibling;
        if (before) { rows.insertBefore(here, before); }
      } else {
        var after = here.nextElementSibling;
        if (after) { rows.insertBefore(after, here); }
      }
      ends(); move.focus(); return;
    }
    var add = ev.target.closest('[data-tc-addafter]');
    if (add) {
      var made = blank(add.closest('[data-tc-row]'));
      var f = made.querySelector('input'); if (f) { f.focus(); }
      ends(); return;
    }
    var rm = ev.target.closest('[data-tc-remove]');
    if (!rm) { return; }
    var row = rm.closest('[data-tc-row]');
    if (row) { row.remove(); }
    if (!rows.querySelector('[data-tc-row]')) { blank(null); }
    ends();
  });

  // Typing in the last line grows the list, so a long box is a run of typing.
  rows.addEventListener('input', function (ev) {
    var row = ev.target.closest('[data-tc-row]');
    if (!row || row !== rows.lastElementChild) { return; }
    if (ev.target.value.trim() !== '') { blank(null); ends(); }
  });

  ends();
})();

/* The same list on the entry form: add below, remove, and a blank line that appears as
   you type in the last one. No reordering - the order came from the release, and a copy
   rearranging it says nothing. */
/* What is in the box.
 *
 * Cloned from a <template> rather than from the first row, because there is no
 * first row any more: the list starts empty behind its button. The old version
 * cloned rows.querySelector('[data-ic-row]'), which is null on an empty list -
 * and it re-added a blank whenever the last one was removed, so the list could
 * never be emptied.
 *
 * Typing in the last row no longer appends another either. A row now costs one
 * click, and appearing unbidden is what made these forms look half-filled. */
(function () {
  var rows = document.querySelector('[data-ic-rows]');
  var tpl  = document.querySelector('[data-ic-template]');
  var add  = document.querySelector('[data-ic-add]');
  if (!rows || !tpl) { return; }

  function blank(after) {
    var made = tpl.content.firstElementChild.cloneNode(true);
    if (after && after.nextSibling) { rows.insertBefore(made, after.nextSibling); }
    else { rows.appendChild(made); }
    return made;
  }

  if (add) {
    add.addEventListener('click', function () {
      var f = blank(null).querySelector('input');
      if (f) { f.focus(); }
    });
  }

  rows.addEventListener('click', function (ev) {
    var after = ev.target.closest('[data-ic-addafter]');
    if (after) {
      var f = blank(after.closest('[data-ic-row]')).querySelector('input');
      if (f) { f.focus(); }
      return;
    }
    // Order matters here as much as it does for the media a release came on -
    // a manual, then the disks, then the registration card is the order things
    // sit in the box, and a list you cannot reorder is a list you retype.
    var moveBtn = ev.target.closest('[data-ic-up], [data-ic-down]');
    if (moveBtn) {
      var moving = moveBtn.closest('[data-ic-row]');
      if (!moving) { return; }
      if (moveBtn.hasAttribute('data-ic-up') && moving.previousElementSibling) {
        moving.previousElementSibling.before(moving);
      } else if (moveBtn.hasAttribute('data-ic-down') && moving.nextElementSibling) {
        moving.nextElementSibling.after(moving);
      }
      return;
    }

    var rm = ev.target.closest('[data-ic-remove]');
    if (!rm) { return; }
    var row = rm.closest('[data-ic-row]');
    if (row) { row.remove(); }
  });
})();


/* Choosing a software model fills in what that shape of release holds.
 *
 * The select narrowed itself by platform and did nothing else, so picking "Super Nintendo
 * boxed cartridge" changed a value in a dropdown and left "What is in the box" empty -
 * which is most of the point of a model gone. The contents were only ever applied on
 * save, from a title, which is a different thing chosen somewhere else.
 *
 * Typed lines are never overwritten. A model is a starting point, and somebody who has
 * already listed what is in their box has answered a question this would be re-asking. */
(function () {
  var sel  = document.querySelector('[data-swmodel-select]');
  var rows = document.querySelector('[data-ic-rows]');
  if (!sel || !rows) { return; }

  var presets = {};
  try { presets = JSON.parse(sel.getAttribute('data-presets') || '{}') || {}; } catch (e) { return; }

  function rowList() { return Array.prototype.slice.call(rows.querySelectorAll('[data-ic-row]')); }

  function typedIn() {
    return rowList().some(function (r) {
      var i = r.querySelector('input');
      return i && i.value.trim() !== '';
    });
  }

  function fill(labels) {
    var template = rows.querySelector('[data-ic-row]');
    if (!template) { return; }
    var blank = template.cloneNode(true);
    rowList().forEach(function (r) { r.remove(); });

    labels.concat(['']).forEach(function (label) {
      var made = blank.cloneNode(true);
      var input = made.querySelector('input');
      if (input) { input.value = label; }
      // "Not checked" rather than "Present": the model says what the release
      // shipped with, not what is in this copy of it. Saying Present here would
      // be filling in an answer nobody has looked for.
      var s = made.querySelector('select');
      if (s) { s.value = 'unknown'; }
      rows.appendChild(made);
    });
  }

  var lastApplied = null;

  sel.addEventListener('change', function () {
    var preset = presets[sel.value];
    if (!preset || !preset.contents || !preset.contents.length) { return; }
    // Only into an empty list, or over a list this script put there itself -
    // changing your mind about the model should change the suggestion, but
    // nothing typed is ever a suggestion.
    if (typedIn() && lastApplied === null) { return; }

    fill(preset.contents.map(function (c) { return c.label; }));
    lastApplied = sel.value;

    // The box exists if the model says what is in it.
    var box = document.querySelector('[data-toggle="box"]');
    if (box && !box.checked) {
      box.checked = true;
      box.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
})();


/* Choosing which part of a picture is the avatar.
 *
 * An avatar is a circle cut out of the middle, and the middle of a photograph is very
 * often not the face. So the circle is drawn over the picture at the size it will
 * actually be cut, and it can be dragged and resized until it is around the right thing.
 *
 * What is posted is three numbers in the picture's own pixels, because the server crops
 * the original and knows nothing about how big it happened to be drawn on screen. The
 * scale between the two is the one thing this has to get right, and it is recomputed on
 * every read rather than cached, so a window resize cannot leave it stale.
 *
 * Nothing here is required: with no JavaScript the three inputs stay empty and the whole
 * picture is used, which is what happened before this existed. */
(function () {
  var zone = document.querySelector('[data-dropzone-crop]');
  if (!zone) { return; }
  var input = zone.querySelector('input[type="file"]');
  var stage = document.querySelector('[data-crop-stage]');
  var outX  = document.querySelector('[data-crop-x]');
  var outY  = document.querySelector('[data-crop-y]');
  var outN  = document.querySelector('[data-crop-size]');
  if (!input || !stage || !outX || !outY || !outN) { return; }

  var url = null;

  function clear() {
    stage.hidden = true;
    stage.innerHTML = '';
    outX.value = outY.value = outN.value = '';
    if (url) { URL.revokeObjectURL(url); url = null; }
  }

  function build(file) {
    clear();
    if (!file || !/^image\//.test(file.type)) { return; }
    // An animated GIF is left whole: the server will not crop one either, because
    // putting it through GD would flatten it to a single frame.
    if (file.type === 'image/gif') { return; }

    url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function () {
      var natural = Math.min(img.naturalWidth, img.naturalHeight);
      if (natural < 32) { return; }

      var wrap = document.createElement('div');
      wrap.className = 'cropper';
      var shown = document.createElement('img');
      shown.className = 'cropper__img';
      shown.src = url;
      shown.alt = '';
      wrap.appendChild(shown);

      var hole = document.createElement('div');
      hole.className = 'cropper__hole';
      wrap.appendChild(hole);
      stage.appendChild(wrap);

      var row = document.createElement('div');
      row.className = 'cropper__row';
      var label = document.createElement('label');
      label.textContent = 'Size';
      label.className = 'hint';
      var range = document.createElement('input');
      range.type = 'range';
      range.min = '32';
      range.max = String(natural);
      range.value = String(Math.round(natural * 0.8));
      range.setAttribute('aria-label', 'How much of the picture the avatar uses');
      var note = document.createElement('span');
      note.className = 'hint';
      note.textContent = 'Drag the circle to choose what it shows.';
      row.appendChild(label);
      row.appendChild(range);
      row.appendChild(note);
      stage.appendChild(row);
      stage.hidden = false;

      // Natural pixels, kept here; the screen is only ever a view of them.
      var size = parseInt(range.value, 10);
      var x = Math.round((img.naturalWidth  - size) / 2);
      var y = Math.round((img.naturalHeight - size) / 2);

      // Recomputed rather than remembered: the picture is laid out by CSS and can
      // change width whenever the window does.
      function scale() { return shown.clientWidth / img.naturalWidth || 1; }

      function clamp() {
        size = Math.max(32, Math.min(size, img.naturalWidth, img.naturalHeight));
        x = Math.max(0, Math.min(x, img.naturalWidth  - size));
        y = Math.max(0, Math.min(y, img.naturalHeight - size));
      }

      function draw() {
        clamp();
        var s = scale();
        hole.style.width  = (size * s) + 'px';
        hole.style.height = (size * s) + 'px';
        hole.style.left   = (x * s) + 'px';
        hole.style.top    = (y * s) + 'px';
        outX.value = String(x);
        outY.value = String(y);
        outN.value = String(size);
      }

      range.addEventListener('input', function () {
        var was = size;
        size = parseInt(range.value, 10);
        // Grow and shrink about the middle, so the thing framed stays framed.
        x += Math.round((was - size) / 2);
        y += Math.round((was - size) / 2);
        draw();
      });

      var dragging = false, fromX = 0, fromY = 0, atX = 0, atY = 0;
      hole.addEventListener('pointerdown', function (ev) {
        dragging = true;
        fromX = ev.clientX; fromY = ev.clientY; atX = x; atY = y;
        hole.setPointerCapture(ev.pointerId);
        ev.preventDefault();
      });
      hole.addEventListener('pointermove', function (ev) {
        if (!dragging) { return; }
        var s = scale();
        x = Math.round(atX + (ev.clientX - fromX) / s);
        y = Math.round(atY + (ev.clientY - fromY) / s);
        draw();
      });
      ['pointerup', 'pointercancel'].forEach(function (e) {
        hole.addEventListener(e, function () { dragging = false; });
      });

      window.addEventListener('resize', draw);

      // Drawn again once the displayed copy has laid out.
      //
      // scale() is clientWidth / naturalWidth, and clientWidth is 0 until the img
      // in the document has been laid out - the || 1 fallback then puts the circle
      // at natural pixel size, which on a large photograph is a circle bigger than
      // the picture. The decode that fired onload above was a different element.
      if (shown.complete && shown.clientWidth > 0) {
        draw();
      } else {
        shown.addEventListener('load', draw, { once: true });
        // Belt and braces: a cached image can be complete with layout still
        // pending, and load will not fire again for it.
        requestAnimationFrame(draw);
      }
    };
    img.src = url;
  }

  input.addEventListener('change', function () {
    build(input.files && input.files[0]);
  });
  // The drop zone rewrites input.files after a drop, which does not fire change.
  zone.addEventListener('drop', function () {
    setTimeout(function () { build(input.files && input.files[0]); }, 0);
  });
})();


/* Document rows on the entry forms.
 *
 * The same add-and-remove the specification rows have, on the list of links to
 * manuals held elsewhere. No reordering: a specification list is read top to
 * bottom and its order carries meaning, while a set of links does not. */
(function () {
  var rows = document.querySelector('[data-doc-rows]');
  var tpl  = document.querySelector('[data-doc-template]');
  var add  = document.querySelector('[data-doc-add]');
  if (!rows || !tpl) { return; }

  function blank() { return tpl.content.firstElementChild.cloneNode(true); }

  if (add) {
    add.addEventListener('click', function () {
      var made = blank();
      rows.appendChild(made);
      var first = made.querySelector('input');
      if (first) { first.focus(); }
    });
  }

  rows.addEventListener('click', function (ev) {
    var after = ev.target.closest('[data-doc-addafter]');
    if (after) {
      var row  = after.closest('[data-doc-row]');
      var made = blank();
      if (row && row.nextSibling) { rows.insertBefore(made, row.nextSibling); }
      else { rows.appendChild(made); }
      var first = made.querySelector('input');
      if (first) { first.focus(); }
      return;
    }
    // Same reordering as the other lists: a manual before a schematic is a
    // choice somebody made, and without this the only way to change it was to
    // retype both rows.
    var docMove = ev.target.closest('[data-doc-up], [data-doc-down]');
    if (docMove) {
      var docRow = docMove.closest('[data-doc-row]');
      if (!docRow) { return; }
      if (docMove.hasAttribute('data-doc-up') && docRow.previousElementSibling) {
        docRow.previousElementSibling.before(docRow);
      } else if (docMove.hasAttribute('data-doc-down') && docRow.nextElementSibling) {
        docRow.nextElementSibling.after(docRow);
      }
      return;
    }

    var rm = ev.target.closest('[data-doc-remove]');
    if (!rm) { return; }
    var gone = rm.closest('[data-doc-row]');
    if (gone) { gone.remove(); }
    // Emptying it is allowed now: the button is the empty state, so a list with
    // no rows is still a list you can add to.
  });
})();


/* Repeating rows start empty, behind one wide button.
 *
 * Every one of these groups - box contents, external links, what a machine has,
 * the media a release came on, a model's fields - rendered a blank row whether
 * or not anybody wanted one. A form that opens with six empty pairs of boxes and
 * a row of ↑↓+× beside each reads as work already begun, and the blank rows are
 * dropped on save anyway, so they were furniture.
 *
 * The button is what shows when a group is empty; the rows are what show when it
 * is not. Watched rather than wired into each group's own add/remove code: those
 * five handlers already exist and disagree about details, and this only cares
 * how many rows there are.
 *
 * With no JavaScript the button is simply always visible and the server still
 * renders whatever rows exist, which is the behaviour before this. */
(function () {
  var groups = [
    ['[data-ic-rows]',    '[data-ic-add]'],
    ['[data-doc-rows]',   '[data-doc-add]'],
    ['[data-spec-rows]',  '[data-spec-add]'],
    ['[data-media-rows]', '[data-media-add]'],
    ['[data-mf-rows]',    '[data-mf-add]']
  ];

  groups.forEach(function (pair) {
    var rows = document.querySelector(pair[0]);
    var add  = document.querySelector(pair[1]);
    if (!rows || !add) { return; }

    function sync() {
      // A row that exists but has been emptied still counts: somebody is
      // typing in it.
      if (rows.children.length === 0) { add.removeAttribute('hidden'); }
      else { add.setAttribute('hidden', 'hidden'); }
    }

    // MutationObserver rather than a click handler, because rows are added and
    // removed by five different pieces of code and one of them is a template
    // clone rather than a button press.
    if (window.MutationObserver) {
      new MutationObserver(sync).observe(rows, { childList: true });
    }
    sync();
  });
})();


/* "Save and look up", only where a lookup would ask somebody.
 *
 * Sources are switched on per branch of the category tree and inherit downward,
 * so whether a lookup is worth offering depends on where the entry is being
 * filed - which is a choice made on this same form, three fields up.
 *
 * The option carries the answer (data-sources), worked out server-side in one
 * pass; this only has to read it. Without JavaScript the button stays as it is,
 * which is the harmless way round: an offer that leads to "asked: nobody" is
 * worse than a button that was always there. */
(function () {
  var button = document.querySelector('[data-lookup-button]');
  var select = document.querySelector('[data-category-select], [data-kind-select]');
  if (!button || !select) { return; }

  function apply() {
    var chosen = select.options[select.selectedIndex];
    // Nothing chosen yet is not "no sources" - it is not knowing, and the button
    // stays until the question has been answered.
    if (!chosen || !chosen.value || !chosen.hasAttribute('data-sources')) {
      button.hidden = false;
      return;
    }
    button.hidden = chosen.getAttribute('data-sources') !== '1';
  }

  select.addEventListener('change', apply);
  apply();
})();


/* Company narrows the platform, on the entry form.
 *
 * The same rule as the model form and the same limit: only when a machine is
 * being added. The template says which by setting data-narrow-by-vendor, so this
 * script does not have to work out what is being filed.
 *
 * Rebuilt through optgroups, because this select is grouped by machine class and
 * flattening it would lose the headings. A group left with no options is dropped
 * rather than shown empty.
 *
 * Ids, not slugs: both selects list this library's own rows, so a platform's
 * vendor_id is directly the id of a company in the list beside it.
 *
 * Without JavaScript every platform is listed, which is the old behaviour and
 * still correct - this only removes choices that would be wrong. */
(function () {
  'use strict';

  var vendor = document.querySelector('[data-vendor-select]');
  var plat   = document.querySelector('[data-platform-select]');
  if (!vendor || !plat || !plat.dataset.narrowByVendor) { return; }

  // Kept because filtering removes options from the DOM, and going back to
  // "choose" has to be able to put them there again.
  var groups = Array.prototype.map.call(plat.querySelectorAll('optgroup'), function (g) {
    return {
      label: g.label,
      options: Array.prototype.map.call(g.querySelectorAll('option'), function (o) {
        return { value: o.value, text: o.textContent.trim(),
                 slug: o.dataset.slug || '', vendor: o.dataset.vendor || '' };
      })
    };
  });
  var loose = Array.prototype.filter.call(plat.children, function (n) {
    return n.tagName === 'OPTION';
  }).map(function (o) {
    return { value: o.value, text: o.textContent.trim(),
             slug: o.dataset.slug || '', vendor: o.dataset.vendor || '' };
  });

  function build(opt) {
    var el = document.createElement('option');
    el.value = opt.value;
    el.textContent = opt.text;
    if (opt.slug) { el.dataset.slug = opt.slug; }
    if (opt.vendor) { el.dataset.vendor = opt.vendor; }
    return el;
  }

  function narrow() {
    var want = vendor.value;
    var keep = plat.value;

    plat.innerHTML = '';
    // The placeholder always stays: a form that opens with a machine already
    // chosen for you is a form that guesses.
    loose.forEach(function (o) { plat.appendChild(build(o)); });

    groups.forEach(function (g) {
      var wanted = g.options.filter(function (o) {
        return want === '' || o.vendor === want;
      });
      if (wanted.length === 0) { return; }
      var el = document.createElement('optgroup');
      el.label = g.label;
      wanted.forEach(function (o) { el.appendChild(build(o)); });
      plat.appendChild(el);
    });

    // Hold the current choice if it survived; otherwise back to the placeholder
    // rather than silently selecting somebody else's machine.
    plat.value = Array.prototype.some.call(plat.options, function (o) {
      return o.value === keep;
    }) ? keep : '';

    // Everything downstream reads the platform - the model list, the parts list -
    // so they have to be told it may just have changed underneath them.
    plat.dispatchEvent(new Event('change', { bubbles: true }));
  }

  vendor.addEventListener('change', narrow);

  // Not on load. Opening a saved entry would fire the change above and could
  // reset a platform somebody chose deliberately before this rule existed.
})();
