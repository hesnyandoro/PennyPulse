document.addEventListener('DOMContentLoaded', () => {
    const badge = document.querySelector('[data-notification-badge]');
    const dropdown = document.querySelector('[data-notification-dropdown]');
    const list = document.querySelector('[data-notification-list]');
    const emptyState = document.querySelector('[data-notification-empty]');
    const toggle = document.querySelector('[data-notification-toggle]');

    if (!toggle || !badge) {
        return;
    }

    const renderNotifications = (data) => {
        const notifications = Array.isArray(data.notifications) ? data.notifications : [];
        badge.textContent = data.unread_count > 0 ? data.unread_count : '0';
        badge.style.display = data.unread_count > 0 ? 'inline-flex' : 'none';

        if (!notifications.length) {
            list.innerHTML = '';
            emptyState.style.display = 'block';
            return;
        }

        emptyState.style.display = 'none';
        list.innerHTML = notifications.map((item) => `
            <li class="notification-item ${item.is_read ? '' : 'unread'}" data-notification-id="${item.id}">
                <div class="notification-message">${item.message}</div>
                <div class="notification-meta">${new Date(item.created_at).toLocaleString()}</div>
            </li>
        `).join('');

        list.querySelectorAll('.notification-item').forEach((item) => {
            item.addEventListener('click', async () => {
                const id = item.getAttribute('data-notification-id');
                if (!id) {
                    return;
                }

                const formData = new URLSearchParams();
                formData.append('notification_id', id);

                await fetch('mark_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: formData.toString()
                });

                await fetchNotifications();
            });
        });
    };

    const fetchNotifications = async () => {
        try {
            const response = await fetch('get_notifications.php', { cache: 'no-store' });
            const data = await response.json();
            renderNotifications(data);
        } catch (error) {
            console.error('Unable to load notifications', error);
        }
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        dropdown.classList.toggle('open');
    });

    document.addEventListener('click', () => {
        dropdown.classList.remove('open');
    });

    dropdown.addEventListener('click', (event) => {
        event.stopPropagation();
    });

    fetchNotifications();
    window.setInterval(fetchNotifications, 60000);
});
