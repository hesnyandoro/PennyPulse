document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.getElementById('theme-toggle');
    const body = document.body;

    // Apply saved theme from localStorage if available
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
        body.className = body.className.replace(/\b(light|dark)\b/g, savedTheme);
        if (themeToggle) {
            const icon = themeToggle.querySelector('i');
            if (icon) {
                icon.className = savedTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
            }
        }
    }

    // Theme toggle functionality
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const currentTheme = body.classList.contains('dark') ? 'dark' : 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            body.className = body.className.replace(/\b(light|dark)\b/g, newTheme);
            localStorage.setItem('theme', newTheme);
            
            const icon = themeToggle.querySelector('i');
            if (icon) {
                icon.className = newTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
            }
            
            // Save theme preference to server
            fetch('css/api/update_theme.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme: newTheme })
            }).catch(error => console.error('Error updating theme:', error));
        });
    }
});