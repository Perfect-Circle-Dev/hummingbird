// Search as you type on the My order page.
// Without JavaScript the Search button still works; this only makes it faster.
(function () {
  var input = document.getElementById('q');
  var results = document.getElementById('results');
  if (!input || !results) return;

  var timer = null;
  var latest = 0;

  function search() {
    var query = input.value.trim();
    var mine = ++latest;
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
    clearTimeout(timer);
    timer = setTimeout(search, 200);
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
  var regions = ['results', 'favorites', 'order-lists'];
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
        if (same) same.focus({ preventScroll: true });
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
