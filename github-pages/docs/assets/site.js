'use strict';
(() => {
  const root = document.documentElement;
  const themeButton = document.getElementById('theme-toggle');
  const menuButton = document.getElementById('menu-toggle');
  const sidebar = document.getElementById('sidebar');
  const search = document.getElementById('docs-search');
  const results = document.getElementById('search-results');
  const status = document.getElementById('search-status');
  let savedTheme;
  try { savedTheme = localStorage.getItem('nntmux-docs-theme'); } catch (_) { /* Storage may be blocked. */ }
  const setTheme = (theme) => {
    root.dataset.theme = theme;
    themeButton.textContent = theme === 'dark' ? 'Light theme' : 'Dark theme';
    themeButton.setAttribute('aria-label', `Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`);
  };
  setTheme(savedTheme === 'light' ? 'light' : 'dark');
  themeButton.addEventListener('click', () => {
    const theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    setTheme(theme);
    try { localStorage.setItem('nntmux-docs-theme', theme); } catch (_) { /* Keep the choice for this page. */ }
  });
  const setMenu = (open) => {
    sidebar.classList.toggle('is-open', open);
    menuButton.setAttribute('aria-expanded', String(open));
  };
  menuButton.addEventListener('click', () => setMenu(!sidebar.classList.contains('is-open')));
  document.addEventListener('keydown', (event) => {
    const editing = event.target instanceof Element && event.target.closest('input, textarea, select, [contenteditable]');
    if (event.key === '/' && !editing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      setMenu(true);
      search.focus();
    }
    if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
      setMenu(false);
      menuButton.focus();
    }
  });
  let indexPromise;
  const loadIndex = () => {
    if (!indexPromise) {
      indexPromise = fetch(new URL('search.json', document.baseURI)).then((response) => {
        if (!response.ok) throw new Error('Search unavailable');
        return response.json();
      }).catch((error) => { indexPromise = undefined; throw error; });
    }
    return indexPromise;
  };
  let revision = 0;
  search.addEventListener('input', async () => {
    const current = ++revision;
    const query = search.value.trim().toLocaleLowerCase();
    results.replaceChildren();
    results.hidden = true;
    status.textContent = '';
    if (!query) return;
    status.textContent = 'Searching…';
    try {
      const entries = await loadIndex();
      if (current !== revision) return;
      const terms = query.split(/\s+/);
      const matches = entries.filter((entry) => terms.every((term) => `${entry.title} ${entry.text}`.toLocaleLowerCase().includes(term)))
        .sort((a, b) => Number(b.title.toLocaleLowerCase().includes(query)) - Number(a.title.toLocaleLowerCase().includes(query)))
        .slice(0, 9);
      status.textContent = matches.length ? `${matches.length} matching page${matches.length === 1 ? '' : 's'}` : 'No matching pages';
      for (const entry of matches) {
        if (!/^[a-z][a-z-]*\.html$/.test(entry.url)) continue;
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = entry.url;
        link.textContent = entry.title;
        const preview = document.createElement('small');
        const position = entry.text.toLocaleLowerCase().indexOf(terms[0]);
        const start = Math.max(0, position - 35);
        preview.textContent = `${start ? '…' : ''}${entry.text.slice(start, start + 105)}…`;
        link.append(preview);
        item.append(link);
        results.append(item);
      }
      results.hidden = !results.childElementCount;
    } catch (_) {
      if (current === revision) status.textContent = 'Search unavailable. Browse the navigation links.';
    }
  });
})();
