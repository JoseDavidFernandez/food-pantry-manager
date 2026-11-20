// Applies the system dark mode before the first paint. It lives outside the page so that the
// Content-Security-Policy needs no inline script; the server writes the saved choice in
// <html data-appearance> and adds the "dark" class itself when the choice is "dark".
(function () {
    var appearance = document.documentElement.getAttribute('data-appearance');

    if (
        appearance !== 'light' &&
        appearance !== 'dark' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches
    ) {
        document.documentElement.classList.add('dark');
    }
})();
