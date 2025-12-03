// Add client-side validation or enhancements if needed
// For now, Chart.js handles the report visualizations

// Active Page Indicator
document.addEventListener('DOMContentLoaded', function() {
    // Get the current page filename
    const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
    
    // Map pages to navigation links
    const pageMapping = {
        'dashboard.php': 'Dashboard',
        'edit_expenses.php': 'Manage Expenses',
        'view_expenses.php': 'View Expenses',
        'set_budget.php': 'Budgets',
        'reports.php': 'Reports',
        'settings.php': 'Settings'
    };
    
    // Get all navigation links
    const navLinks = document.querySelectorAll('.nav-links a');
    
    // Find and mark the active link
    navLinks.forEach(link => {
        const linkText = link.textContent.trim();
        const mappedPage = pageMapping[currentPage];
        
        if (linkText === mappedPage) {
            link.classList.add('active');
        }
    });
});