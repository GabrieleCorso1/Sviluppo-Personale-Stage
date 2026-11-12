(function () {
  var preference = 'system';
  try {
    var stored = localStorage.getItem('stage.theme');
    if (stored === 'light' || stored === 'dark') preference = stored;
  } catch (_) {}
  var dark = preference === 'dark' || (preference === 'system' &&
    typeof matchMedia === 'function' && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
})();
