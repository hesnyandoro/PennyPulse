<?php if (isset($_SESSION['user_id'])): ?>
    <div class="notification-wrapper">
        <button type="button" class="notification-toggle" data-notification-toggle aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <span class="notification-badge" data-notification-badge>0</span>
        </button>
        <div class="notification-dropdown" data-notification-dropdown>
            <div class="notification-header">Notifications</div>
            <ul class="notification-list" data-notification-list></ul>
            <div class="notification-empty" data-notification-empty>No new notifications.</div>
        </div>
    </div>
<?php endif; ?>
