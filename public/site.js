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
