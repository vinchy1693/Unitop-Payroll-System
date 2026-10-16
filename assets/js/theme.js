(function () {
    var KEY = 'unitop-theme';
    function system() {
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    function current() {
        return document.documentElement.getAttribute('data-theme') || system();
    }
    function label() {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
            b.textContent = current() === 'dark' ? '\u2600\uFE0F Light' : '\uD83C\uDF19 Dark';
            b.setAttribute('aria-label', current() === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
        });
    }
    function apply(t) {
        document.documentElement.setAttribute('data-theme', t);
        try { localStorage.setItem(KEY, t); } catch (e) {}
        label();
    }
    window.__toggleTheme = function () { apply(current() === 'dark' ? 'light' : 'dark'); };
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
            b.addEventListener('click', function (e) { e.preventDefault(); window.__toggleTheme(); });
        });
        label();
    });
})();
