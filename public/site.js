// Every page: once the page has scrolled far enough for the header to be sitting at the top
// of the window, mark it "stuck". On a wide screen site.css makes a stuck header about a
// third shorter. Without JavaScript the header stays in view at its full size.
(function () {
  var header = document.querySelector('header.top');
  if (!header) return;
  var strip = document.querySelector('.tiles');
  function check() {
    // The header reaches the top of the window when the tile strip above it has scrolled away.
    var reached = window.scrollY > (strip ? strip.getBoundingClientRect().height : 0) + 4;
    header.classList.toggle('stuck', reached);
  }
  window.addEventListener('scroll', check, { passive: true });
  check();
})();

// Search as you type on the My order page.
// Without JavaScript the Search button still works; this only makes it faster.
(function () {
  var input = document.getElementById('q');
  var results = document.getElementById('results');
  if (!input || !results) return;

  var timer = null;
  var latest = 0;
  var cats = document.getElementById('cats');
  var clear = document.getElementById('clear-search');

  // The Clear button inside the box shows only when there is something to clear: text in
  // the box, or a category being browsed.
  function showClear() {
    if (!clear) return;
    var something = input.value !== '' || (cats && cats.querySelector('a[aria-current]') !== null);
    clear.hidden = !something;
    clear.parentNode.classList.toggle('has-clear', something);
  }

  // Fills in the chip of the category being shown, and clears the others. With no link
  // given (a search, or Clear), none is filled in.
  function markCategory(link) {
    if (!cats) return;
    Array.prototype.forEach.call(cats.querySelectorAll('a'), function (a) {
      if (a === link) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
    });
    showClear();
  }

  function search() {
    var query = input.value.trim();
    var mine = ++latest;
    markCategory(null); // a search takes the place of a category
    // Keep the address in step, so adding an item returns to the same search.
    history.replaceState(null, '', '/?a=order' + (query ? '&q=' + encodeURIComponent(query) : ''));
    if (query.length < 2) {
      results.innerHTML = '';
      return;
    }
    fetch('/?a=order&partial=1&q=' + encodeURIComponent(query), { credentials: 'same-origin' })
      .then(function (response) { return response.ok ? response.text() : ''; })
      .then(function (html) {
        // Ignore answers that arrive after a newer search has started.
        if (mine === latest) results.innerHTML = html;
      })
      .catch(function () { /* leave the current results; the Search button still works */ });
  }

  input.addEventListener('input', function () {
    showClear();
    clearTimeout(timer);
    timer = setTimeout(search, 200);
  });

  // The category chips: show that category in the results on the spot. The search box is
  // emptied, since a category and a search are never shown together. The chips sit in a
  // card of their own, away from the results, so the page moves to bring the results into
  // view when they are off the screen. (Without JavaScript a chip is a link that reloads
  // the page at the results, with the category showing.)
  function bringResultsIntoView() {
    var where = results.getBoundingClientRect();
    if (where.top >= 60 && where.top < window.innerHeight * 0.7) return; // already in view
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    results.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
  }
  if (cats) {
    cats.addEventListener('click', function (event) {
      var link = event.target.closest ? event.target.closest('a') : null;
      if (!link) return;
      event.preventDefault();
      clearTimeout(timer);
      input.value = '';
      var mine = ++latest;
      var address = link.getAttribute('href').split('#')[0]; // the link ends in #results, for use without the script
      history.replaceState(null, '', address); // so adding an item returns to the same category
      markCategory(link);
      fetch(address + '&partial=1', { credentials: 'same-origin' })
        .then(function (response) { return response.ok ? response.text() : ''; })
        .then(function (html) {
          if (mine !== latest) return;
          results.innerHTML = html;
          bringResultsIntoView();
        })
        .catch(function () { window.location.href = link.getAttribute('href'); }); // fall back to an ordinary page load
    });
  }

  // Pressing Clear: empty the box and the results on the spot, and put the cursor in the
  // box ready for the next search. (Without JavaScript it is a link that reloads the page.)
  // The Close button over a list of results puts the list away, as Clear does. The results
  // are redrawn each time, so the press is caught here, on the box that holds them. The
  // cursor is left where it is: on a phone, moving it to the search box would bring up the
  // keyboard, which nobody asked for by pressing Close.
  results.addEventListener('click', function (event) {
    var close = event.target.closest ? event.target.closest('a[data-close]') : null;
    if (!close) return;
    event.preventDefault();
    clearTimeout(timer);
    input.value = '';
    search();
    // Close may have been pressed far down a long list. With the list gone, go back up to
    // the search box if it is now off the top of the window.
    var card = results.closest('.card');
    if (card && card.getBoundingClientRect().top < 0) card.scrollIntoView({ block: 'start' });
    // Pressed from the keyboard (a click with no mouse or finger behind it): the button has
    // just gone, so put the keyboard's place in the search box, where it is not lost.
    if (event.detail === 0) input.focus();
  });

  if (clear) {
    clear.addEventListener('click', function (event) {
      event.preventDefault();
      clearTimeout(timer);
      input.value = '';
      search(); // empties the results and any category, which also hides this button
      input.focus();
    });
  }
})();

// The "Description" pop-ups on My order open and close by themselves (each is a <details>).
// This adds the two ways people expect to close one: the Esc key, and a click or tap anywhere
// else. Opening one also closes any other that was open.
(function () {
  function closeAll(except) {
    Array.prototype.forEach.call(document.querySelectorAll('details.desc[open]'), function (box) {
      if (box !== except) box.open = false;
    });
  }
  document.addEventListener('click', function (event) {
    var inside = event.target.closest ? event.target.closest('details.desc') : null;
    closeAll(inside);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    var open = document.querySelector('details.desc[open]');
    if (!open) return;
    open.open = false;
    open.querySelector('summary').focus(); // back to the link that opened it
  });
})();

// Organizer's combined order: ticking "Out of stock for everyone" ticks each
// household's box for that item, and unticking it clears them.
// Without JavaScript nothing moves on screen, but saving gives the same result.
(function () {
  var everyoneBoxes = document.querySelectorAll('input[data-all-for]');
  Array.prototype.forEach.call(everyoneBoxes, function (everyone) {
    everyone.addEventListener('change', function () {
      var own = document.querySelectorAll('input[data-item="' + everyone.getAttribute('data-all-for') + '"]');
      Array.prototype.forEach.call(own, function (box) { box.checked = everyone.checked; });
    });
  });
})();

// My order page: adding an item, saving one for next month, starring a favorite, and the
// plus and minus buttons all update the page in place. The page no longer reloads and
// jumps back to the top. Without JavaScript the same buttons reload the page, as before.
(function () {
  // The parts of the page that are refreshed. Each is an element with this id.
  var regions = ['results', 'favorites', 'favorites-count', 'order-lists'];
  if (!document.getElementById('order-lists') || !window.fetch || !window.DOMParser || !window.FormData) return;
  var busy = false;
  var waiting = []; // taps made while an earlier change was still under way

  // Notes which button was pressed, so the same one can be given the keyboard focus
  // again once its part of the page has been replaced.
  function describe(button) {
    var id = button.form.querySelector('input[name="line_id"], input[name="variant_id"]');
    var region = button.closest('#results, #favorites, #order-lists');
    return {
      region: region ? region.id : '',
      idName: id ? id.name : '',
      idValue: id ? id.value : '',
      // Plus and minus are both named "qty", so they are told apart by their label.
      kind: button.name === 'qty' ? 'qty:' + button.getAttribute('aria-label') : button.name + ':' + button.value
    };
  }

  // Finds that button again in the refreshed page, or null if it is no longer there
  // (the minus button of an item that has just been taken off, for example).
  function findAgain(mark) {
    var region = document.getElementById(mark.region);
    if (!region || !mark.idName) return null;
    var buttons = region.querySelectorAll('button');
    for (var i = 0; i < buttons.length; i++) {
      var id = buttons[i].form && buttons[i].form.querySelector('input[name="' + mark.idName + '"]');
      if (id && id.value === mark.idValue && describe(buttons[i]).kind === mark.kind) return buttons[i];
    }
    return null;
  }

  // Shows on the button itself that the item went on. For a moment "Add" reads "Added" and
  // "Save for next month" reads "Saved", in another colour with a check mark (the "done"
  // style in site.css). Then it goes back, ready to be pressed again.
  function showDone(button, mark) {
    var word = mark.kind === 'do:add' ? 'Added' : (mark.kind === 'do:later' ? 'Saved' : '');
    if (!word) return;
    var was = button.textContent;
    button.style.minWidth = button.offsetWidth + 'px'; // keep its width, so nothing beside it moves
    button.textContent = word;
    button.classList.add('done');
    // The same news for someone using a screen reader, with the item's name.
    // The name is on the product's card, or on the row itself in My favorites; the size is on the row.
    var said = document.getElementById('order-said');
    var row = button.closest('.item');
    var name = (button.closest('.product') || row || document).querySelector('.item-name');
    var size = row ? row.querySelector('.size-name') : null;
    if (said && name) {
      said.textContent = (word === 'Added' ? 'Added to your order: ' : 'Saved for next month: ')
        + name.textContent + (size ? ', ' + size.textContent : '');
    }
    setTimeout(function () {
      button.textContent = was;
      button.classList.remove('done');
      button.style.minWidth = '';
    }, 1600);
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var button = event.submitter;
    if (form.getAttribute('action') !== '/?a=order' || (form.getAttribute('method') || '').toLowerCase() !== 'post') return;
    // Left to the browser: an older browser that can't say which button was pressed, a form
    // that has already failed once here, and "Submit my order", which reloads to show its message.
    if (!button || form.getAttribute('data-plain') || (button.name === 'do' && button.value === 'submit')) return;

    event.preventDefault();
    var mark = describe(button);
    if (busy) {
      // One change at a time. A tap made meanwhile is remembered and carried out next, on the
      // refreshed page, so three quick taps on plus add three and not one.
      waiting.push(mark);
      return;
    }
    busy = true;

    var data = new FormData(form);
    if (button.name) data.append(button.name, button.value);
    // Send what is in the search box now, so the page that comes back shows the same results
    // as are on screen. (The form remembers the search from when it was drawn, which may be
    // out of date after more typing or after Clear.)
    var box = document.getElementById('q');
    if (box) data.set('q', box.value.trim().length < 2 ? '' : box.value.trim());
    // The same for a category being browsed: the address says which one is showing now.
    var showing = /[?&]cat=([a-z]+)/.exec(window.location.search);
    data.set('cat', showing ? showing[1] : '');

    fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) throw new Error('The change was not accepted');
        return response.text();
      })
      .then(function (html) {
        // The server answers with the whole page as it now stands. Take the refreshed
        // parts out of it and put them in place of the old ones.
        var fresh = new DOMParser().parseFromString(html, 'text/html');
        if (!fresh.getElementById('order-lists')) {
          window.location.reload(); // not the order page: most likely signed out
          return;
        }
        regions.forEach(function (id) {
          var now = document.getElementById(id);
          var next = fresh.getElementById(id);
          if (now && next) now.innerHTML = next.innerHTML;
        });
        var same = findAgain(mark);
        if (same) {
          same.focus({ preventScroll: true });
          showDone(same, mark);
        }
        busy = false;
        // Carry out the next remembered tap, if its button is still on the page.
        while (waiting.length) {
          var next = findAgain(waiting.shift());
          if (next) { next.click(); break; }
        }
      })
      .catch(function () {
        // Something went wrong, so do it the ordinary way: send the form and reload.
        busy = false;
        waiting = [];
        form.setAttribute('data-plain', '1');
        if (form.requestSubmit) form.requestSubmit(button); else form.submit();
      });
  });
})();

// My order page: "My favorites" and "Browse by category" fold away when their heading is
// pressed (the browser does that by itself). This only remembers the choice, so a box is
// still folded on the next visit. Each box says what to remember it as (data-remember).
// A box marked data-fold-on-phone starts folded on a narrow screen until the person opens
// it, because there it would push the results a long way down.
(function () {
  var boxes = document.querySelectorAll('details[data-remember]');
  Array.prototype.forEach.call(boxes, function (box) {
    var name = box.getAttribute('data-remember');
    try {
      var stored = window.localStorage.getItem(name);
      if (stored === '1') box.open = false;
      if (stored === null && box.hasAttribute('data-fold-on-phone') && window.innerWidth < 700) box.open = false;
      box.addEventListener('toggle', function () {
        window.localStorage.setItem(name, box.open ? '0' : '1');
      });
    } catch (err) { /* private browsing can refuse storage: folding still works, it is just not remembered */ }
  });
})();
