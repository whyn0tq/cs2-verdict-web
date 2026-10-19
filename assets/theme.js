(function () {
  var theme;
  try {
    theme = localStorage.getItem('theme');
  } catch (e) {}
  document.documentElement.dataset.theme = theme === 'light' ? 'light' : 'dark';
})();
