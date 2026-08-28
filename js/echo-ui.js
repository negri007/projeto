/* ==========================================================================
   ECHO DESIGN SYSTEM - UI & NOTIFICATIONS MODULE (js/echo-ui.js)
   ========================================================================== */

// Demo notification items for visual preview before backend integration
const MOCK_NOTIFICATIONS = [
    {
        id: 101,
        type: 'like',
        actor_name: 'João Pedro',
        reference_id: 12,
        is_read: false,
        created_at: 'há 5 min'
    },
    {
        id: 102,
        type: 'comment',
        actor_name: 'Ana Clara',
        reference_id: 15,
        is_read: false,
        created_at: 'há 20 min'
    },
    {
        id: 103,
        type: 'friend_request',
        actor_name: 'Dev Nerd',
        reference_id: null,
        is_read: true,
        created_at: 'há 2h'
    },
    {
        id: 104,
        type: 'message',
        actor_name: 'Carlos Santos',
        reference_id: 3,
        is_read: true,
        created_at: 'há 5h'
    }
];

class EchoUI {
    constructor() {
        this.notifications = [...MOCK_NOTIFICATIONS];
        this.isBackendReady = false; // set to true when backend endpoint is ready
    }

    /**
     * Initializes header components (Notification Bell, Mobile Offcanvas Sidebar)
     * @param {string} currentPage - Current active page identifier (e.g., 'inicio', 'explorar', 'perfil')
     */
    initHeader(currentPage) {
        this.injectMobileOffcanvas(currentPage);
        this.setupNotificationDropdown();
        this.renderNotifications();
    }

    /**
     * Renders Notification Bell HTML inside container or main header
     */
    getNotificationBellHTML() {
        const unreadCount = this.notifications.filter(n => !n.is_read).length;
        return `
            <div class="dropdown d-inline-block">
                <button class="notification-bell-btn" type="button" id="notificationDropdownBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Notificações">
                    <i class="fa-regular fa-bell"></i>
                    ${unreadCount > 0 ? `<span class="notification-badge" id="notificationBadge">${unreadCount}</span>` : ''}
                </button>
                <div class="dropdown-menu dropdown-menu-end notification-dropdown p-0" aria-labelledby="notificationDropdownBtn">
                    <div class="notification-dropdown-header">
                        <h6><i class="fa-solid fa-bell me-2 text-info"></i>Notificações</h6>
                        <button class="btn-mark-all-read" onclick="EchoUIInstance.markAllAsRead()">
                            <i class="fa-solid fa-check-double me-1"></i>Marcar lidas
                        </button>
                    </div>
                    <div class="notification-list" id="notificationListContainer">
                        <!-- Notifications injected dynamically -->
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Renders notifications list UI
     */
    renderNotifications() {
        const container = document.getElementById('notificationListContainer');
        if (!container) return;

        if (this.notifications.length === 0) {
            container.innerHTML = `
                <div class="notification-empty">
                    <i class="fa-regular fa-bell-slash"></i>
                    <p class="mb-0">Nenhuma notificação por enquanto.</p>
                </div>
            `;
            return;
        }

        const itemsHTML = this.notifications.map(n => {
            let iconClass = 'notification-icon-like';
            let icon = 'fa-heart';
            let actionText = 'interagiu com você.';

            switch (n.type) {
                case 'like':
                    iconClass = 'notification-icon-like';
                    icon = 'fa-heart';
                    actionText = 'curtiu sua publicação.';
                    break;
                case 'comment':
                    iconClass = 'notification-icon-comment';
                    icon = 'fa-comment';
                    actionText = 'comentou na sua publicação.';
                    break;
                case 'share':
                    iconClass = 'notification-icon-share';
                    icon = 'fa-retweet';
                    actionText = 'compartilhou sua publicação.';
                    break;
                case 'friend_request':
                    iconClass = 'notification-icon-friend';
                    icon = 'fa-user-plus';
                    actionText = 'enviou uma solicitação de amizade.';
                    break;
                case 'friend_accept':
                    iconClass = 'notification-icon-friend';
                    icon = 'fa-user-check';
                    actionText = 'aceitou sua solicitação de amizade.';
                    break;
                case 'message':
                    iconClass = 'notification-icon-message';
                    icon = 'fa-envelope';
                    actionText = 'enviou uma nova mensagem.';
                    break;
            }

            return `
                <div class="notification-item ${!n.is_read ? 'unread' : ''}" onclick="EchoUIInstance.markAsRead(${n.id})">
                    <div class="notification-icon-wrap ${iconClass}">
                        <i class="fa-solid ${icon}"></i>
                    </div>
                    <div class="notification-content">
                        <strong>${this.escapeHTML(n.actor_name)}</strong> ${actionText}
                        <span class="notification-time">${n.created_at}</span>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = itemsHTML;
        this.updateBadge();
    }

    /**
     * Updates badge count
     */
    updateBadge() {
        const badge = document.getElementById('notificationBadge');
        const unreadCount = this.notifications.filter(n => !n.is_read).length;
        
        const btn = document.getElementById('notificationDropdownBtn');
        if (!btn) return;

        if (unreadCount > 0) {
            if (badge) {
                badge.textContent = unreadCount;
            } else {
                const newBadge = document.createElement('span');
                newBadge.className = 'notification-badge';
                newBadge.id = 'notificationBadge';
                newBadge.textContent = unreadCount;
                btn.appendChild(newBadge);
            }
        } else if (badge) {
            badge.remove();
        }
    }

    /**
     * Mark single notification as read
     * @param {number} id 
     */
    async markAsRead(id) {
        const item = this.notifications.find(n => n.id === id);
        if (item) {
            item.is_read = true;
            this.renderNotifications();
        }

        /* 
        // TODO: INTEGRAÇÃO BACK-END (quando GET /api/notifications/list.php estiver ativo)
        try {
            await fetch("api/notifications/mark_read.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ notification_id: id })
            });
        } catch (e) {
            console.error("Erro ao marcar notificação como lida:", e);
        }
        */
    }

    /**
     * Mark all notifications as read
     */
    async markAllAsRead() {
        this.notifications.forEach(n => n.is_read = true);
        this.renderNotifications();

        /*
        // TODO: INTEGRAÇÃO BACK-END (quando POST /api/notifications/mark_read.php estiver ativo)
        try {
            await fetch("api/notifications/mark_read.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ mark_all: true })
            });
        } catch (e) {
            console.error("Erro ao marcar todas notificações como lidas:", e);
        }
        */
    }

    /**
     * Fetch real notifications from API (TODO: Activate upon backend readiness)
     */
    async fetchNotificationsAPI() {
        /*
        // TODO: INTEGRAÇÃO BACK-END (GET /api/notifications/list.php)
        try {
            const res = await fetch("api/notifications/list.php", { credentials: "same-origin" });
            const data = await res.json();
            if (data.ok && Array.isArray(data.notifications)) {
                this.notifications = data.notifications;
                this.renderNotifications();
            }
        } catch (e) {
            console.error("Erro ao carregar notificações:", e);
        }
        */
    }

    /**
     * Setup Mobile Offcanvas Sidebar Drawer below 768px
     * @param {string} activePage 
     */
    injectMobileOffcanvas(activePage) {
        if (document.getElementById('mobileSidebarOffcanvas')) return;

        const offcanvasHTML = `
            <div class="offcanvas offcanvas-start offcanvas-dark" tabindex="-1" id="mobileSidebarOffcanvas" aria-labelledby="mobileSidebarLabel">
                <div class="offcanvas-header border-bottom border-secondary">
                    <div class="d-flex align-items-center gap-2">
                        <div class="logo-mark">
                            <i class="fa-solid fa-hashtag"></i>
                        </div>
                        <span class="logo-text">ECHO</span>
                    </div>
                    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
                </div>
                <div class="offcanvas-body d-flex flex-column justify-content-between">
                    <nav class="nav flex-column gap-1">
                        <a class="nav-link ${activePage === 'explorar' ? 'active' : ''}" href="explorar.html">
                            <i class="fa-solid fa-magnifying-glass"></i><span>Explorar</span>
                        </a>
                        <a class="nav-link ${activePage === 'inicio' ? 'active' : ''}" href="inicio.html">
                            <i class="fa-solid fa-house"></i><span>Início</span>
                        </a>
                        <a class="nav-link ${activePage === 'perfil' ? 'active' : ''}" href="perfil.html">
                            <i class="fa-solid fa-user"></i><span>Perfil</span>
                        </a>
                        <a class="nav-link ${activePage === 'circulos' ? 'active' : ''}" href="circulos.html">
                            <i class="fa-regular fa-circle"></i><span>Círculos</span>
                        </a>
                        <a class="nav-link ${activePage === 'amigos' ? 'active' : ''}" href="amigos.html">
                            <i class="fa-solid fa-user-group"></i><span>Amigos</span>
                        </a>
                        <a class="nav-link ${activePage === 'chat' ? 'active' : ''}" href="chat.html">
                            <i class="fa-solid fa-comments"></i><span>Mensagens</span>
                        </a>
                    </nav>

                    <div class="sidebar-profile d-flex align-items-center gap-2 mt-4">
                        <div class="prof-avatar">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div class="prof-info flex-grow-1">
                            <div class="prof-name" id="mobileSidebarName">Usuário</div>
                            <div class="prof-handle" id="mobileSidebarHandle">@usuario</div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill" onclick="logout()">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile Bottom Bar -->
            <div class="mobile-bottom-nav">
                <a href="inicio.html" class="${activePage === 'inicio' ? 'active' : ''}"><i class="fa-solid fa-house"></i></a>
                <a href="explorar.html" class="${activePage === 'explorar' ? 'active' : ''}"><i class="fa-solid fa-magnifying-glass"></i></a>
                <a href="circulos.html" class="${activePage === 'circulos' ? 'active' : ''}"><i class="fa-regular fa-circle"></i></a>
                <a href="amigos.html" class="${activePage === 'amigos' ? 'active' : ''}"><i class="fa-solid fa-user-group"></i></a>
                <a href="chat.html" class="${activePage === 'chat' ? 'active' : ''}"><i class="fa-solid fa-comments"></i></a>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', offcanvasHTML);

        // Sync mobile profile info if available
        if (this.currentUser) {
            this.updateUserProfileUI(this.currentUser);
        }
    }

    /**
     * Checks user authentication via PHP Session (GET /api/auth/me.php)
     * @param {Object} options - { redirectOnFail: boolean }
     * @returns {Promise<Object|null>} User object if authenticated, null if unauthenticated.
     */
    async checkAuth(options = { redirectOnFail: true }) {
        try {
            const res = await fetch("api/auth/me.php", {
                credentials: "same-origin"
            });
            const data = await res.json();

            if (data.authenticated && data.user) {
                this.currentUser = data.user;
                this.updateUserProfileUI(data.user);
                return data.user;
            }
        } catch (e) {
            console.error("Erro ao verificar sessão do usuário:", e);
        }

        if (options.redirectOnFail) {
            window.location = "index.html";
        }
        return null;
    }

    /**
     * Performs logout via POST /api/auth/logout.php
     */
    async logout() {
        try {
            await fetch("api/auth/logout.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({})
            });
        } catch (e) {
            console.error("Erro ao realizar logout:", e);
        }
        localStorage.removeItem("userEmail");
        window.location = "index.html";
    }

    /**
     * Updates sidebar and header username/handle elements
     * @param {Object} user 
     */
    updateUserProfileUI(user) {
        if (!user) return;
        const handle = (user.email ? user.email.split("@")[0] : (user.name || "usuario")).toLowerCase();
        const name = user.name || handle;

        const sidebarName = document.getElementById("sidebarName");
        const sidebarHandle = document.getElementById("sidebarHandle");
        const mobileName = document.getElementById("mobileSidebarName");
        const mobileHandle = document.getElementById("mobileSidebarHandle");

        if (sidebarName) sidebarName.textContent = name;
        if (sidebarHandle) sidebarHandle.textContent = "@" + handle;
        if (mobileName) mobileName.textContent = name;
        if (mobileHandle) mobileHandle.textContent = "@" + handle;
    }

    /**
     * Generates Feed Skeleton HTML
     * @param {number} count 
     */
    getFeedSkeletonHTML(count = 3) {
        let html = '';
        for (let i = 0; i < count; i++) {
            html += `
                <div class="skeleton-post">
                    <div class="skeleton skeleton-avatar"></div>
                    <div style="flex: 1;">
                        <div class="skeleton skeleton-text" style="width: 40%;"></div>
                        <div class="skeleton skeleton-text" style="width: 90%;"></div>
                        <div class="skeleton skeleton-text" style="width: 75%;"></div>
                        <div class="skeleton skeleton-image"></div>
                    </div>
                </div>
            `;
        }
        return html;
    }

    /**
     * Generates List Skeleton HTML (for Friends, Circles, Messages)
     * @param {number} count 
     */
    getListSkeletonHTML(count = 4) {
        let html = '';
        for (let i = 0; i < count; i++) {
            html += `
                <div class="d-flex align-items-center gap-3 p-3 border-bottom border-secondary-subtle">
                    <div class="skeleton skeleton-avatar"></div>
                    <div style="flex: 1;">
                        <div class="skeleton skeleton-text" style="width: 50%;"></div>
                        <div class="skeleton skeleton-text-sm" style="width: 30%;"></div>
                    </div>
                </div>
            `;
        }
        return html;
    }

    setupNotificationDropdown() {
        // Dropdown setup listener if needed
    }

    escapeHTML(str) {
        if (!str) return '';
        return String(str).replace(/[&<>'"]/g, 
            tag => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[tag] || tag)
        );
    }
}

const EchoUIInstance = new EchoUI();

// Expose global logout function for onclick="logout()" in HTML
window.logout = () => EchoUIInstance.logout();

