{{--
    Dark mode and color scheme initialization - MUST be included at the very top of <head>,
    BEFORE any CSS/Vite tags, to prevent white flash on page load.
    1. The blocking script applies the 'dark' class, data-color-scheme, and data-loading to <html> synchronously.
    2. The style tag covers background, text color, color-scheme, x-cloak hiding, and transition
       suppression so the first paint matches the user's theme with zero flash.
--}}
<script nonce="{{ csp_nonce() }}">
(function() {
    var d = document.documentElement;
    d.setAttribute('data-loading', '');
    @auth
        var t = '{{ auth()->user()->theme_preference ?? "light" }}';
        var scheme = '{{ auth()->user()->color_scheme ?? "blue" }}';
    @else
        var t = localStorage.getItem('theme') || 'light';
        var scheme = localStorage.getItem('color_scheme') || 'blue';
    @endauth
    var allowedSchemes = ['blue', 'indigo', 'cyan', 'teal', 'emerald', 'violet', 'pink', 'rose', 'red', 'orange', 'amber'];
    if (allowedSchemes.indexOf(scheme) === -1) {
        scheme = 'blue';
    }
    var isDark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (isDark) {
        d.classList.add('dark');
    }
    d.setAttribute('data-color-scheme', scheme);
})();
</script>
<style nonce="{{ csp_nonce() }}">
[x-cloak] { display: none !important; }
html[data-loading], html[data-loading] *, html[data-loading] *::before, html[data-loading] *::after { transition: none !important; }
html { color-scheme: light; }
html.dark { color-scheme: dark; }
html, body { background-color: #f8fafc; color: #1e293b; }
html.dark, html.dark body { background-color: #0f172a; color: #e2e8f0; }
html[data-color-scheme="emerald"], html[data-color-scheme="emerald"] body { background-color: #f0fdf4; color: #1e293b; }
html.dark[data-color-scheme="emerald"], html.dark[data-color-scheme="emerald"] body { background-color: #071a12; color: #d1fae5; }
html[data-color-scheme="violet"], html[data-color-scheme="violet"] body { background-color: #faf5ff; color: #1e293b; }
html.dark[data-color-scheme="violet"], html.dark[data-color-scheme="violet"] body { background-color: #120b20; color: #e9d5ff; }
html[data-color-scheme="rose"], html[data-color-scheme="rose"] body { background-color: #fff1f2; color: #1e293b; }
html.dark[data-color-scheme="rose"], html.dark[data-color-scheme="rose"] body { background-color: #1f0a12; color: #fecdd3; }
html[data-color-scheme="amber"], html[data-color-scheme="amber"] body { background-color: #fffbeb; color: #1e293b; }
html.dark[data-color-scheme="amber"], html.dark[data-color-scheme="amber"] body { background-color: #1c1304; color: #fde68a; }
html[data-color-scheme="cyan"], html[data-color-scheme="cyan"] body { background-color: #ecfeff; color: #1e293b; }
html.dark[data-color-scheme="cyan"], html.dark[data-color-scheme="cyan"] body { background-color: #06191d; color: #cffafe; }
html[data-color-scheme="indigo"], html[data-color-scheme="indigo"] body { background-color: #eef2ff; color: #1e293b; }
html.dark[data-color-scheme="indigo"], html.dark[data-color-scheme="indigo"] body { background-color: #0b1024; color: #c7d2fe; }
html[data-color-scheme="teal"], html[data-color-scheme="teal"] body { background-color: #f0fdfa; color: #1e293b; }
html.dark[data-color-scheme="teal"], html.dark[data-color-scheme="teal"] body { background-color: #061a18; color: #ccfbf1; }
html[data-color-scheme="orange"], html[data-color-scheme="orange"] body { background-color: #fff7ed; color: #1e293b; }
html.dark[data-color-scheme="orange"], html.dark[data-color-scheme="orange"] body { background-color: #1f1007; color: #ffedd5; }
html[data-color-scheme="red"], html[data-color-scheme="red"] body { background-color: #fef2f2; color: #1e293b; }
html.dark[data-color-scheme="red"], html.dark[data-color-scheme="red"] body { background-color: #200a0a; color: #fee2e2; }
html[data-color-scheme="pink"], html[data-color-scheme="pink"] body { background-color: #fdf2f8; color: #1e293b; }
html.dark[data-color-scheme="pink"], html.dark[data-color-scheme="pink"] body { background-color: #200a17; color: #fce7f3; }
</style>
