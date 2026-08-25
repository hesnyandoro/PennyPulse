document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('theme-toggle');
    const body = document.body;

    window.applyTheme = (theme, persist = true) => {
        body.className = body.className.replace(/\b(light|dark)\b/g, theme);
        if (persist) {
            localStorage.setItem('theme', theme);
        }

        if (themeToggle) {
            const icon = themeToggle.querySelector('i');
            if (icon) {
                icon.className = theme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
            }
            themeToggle.dataset.themeText = theme === 'light' ? 'Dark Mode' : 'Light Mode';
        }
    };

    // Apply saved theme from localStorage if available
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        window.applyTheme(savedTheme, false);
    }

    // Theme toggle functionality
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const currentTheme = body.classList.contains('dark') ? 'dark' : 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';

            window.applyTheme(newTheme);
            
            // Save theme preference to server
            fetch('css/api/update_theme.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme: newTheme })
            }).catch(error => console.error('Error updating theme:', error));
        });
    }
});