import './bootstrap';

const themeToggle = document.querySelector('.theme-toggle');

if (themeToggle instanceof HTMLButtonElement) {
    const updateThemeToggle = () => {
        const isDark = document.documentElement.dataset.theme === 'dark';
        const action = isDark ? 'light' : 'dark';

        themeToggle.setAttribute('aria-label', `Switch to ${action} mode`);
        themeToggle.setAttribute('title', `Switch to ${action} mode`);
        themeToggle.setAttribute('aria-pressed', String(isDark));
    };

    updateThemeToggle();

    themeToggle.addEventListener('click', () => {
        const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';

        document.documentElement.dataset.theme = nextTheme;
        localStorage.setItem('gpcci-theme', nextTheme);
        updateThemeToggle();
    });
}
