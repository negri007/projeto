/* ==========================================================================
   ECHO DESIGN SYSTEM - UI & NOTIFICATIONS MODULE (js/echo-ui.js)
   ========================================================================== */

class EchoUI {
    constructor() {
        this.notifications = [];
        this.unreadCount = 0;
        this.currentUser = null;
        this.notificationTimer = null;
        // Intervalo do polling do sino, em ms.
        this.POLL_INTERVAL = 20000;
    }

    /**
     * Initializes header components (Notification Bell, Mobile Offcanvas Sidebar)
     * @param {string} currentPage - Current active page identifier (e.g., 'inicio', 'explorar', 'perfil')
     */
    initHeader(currentPage) {
        this.injectMobileOffcanvas(currentPage);
        this.setupNotificationDropdown();
        this.renderNotifications();
        this.startNotificationPolling();
    }

    /**
     * Busca as notificacoes agora e passa a repetir a cada
     * POLL_INTERVAL. Chamar duas vezes nao cria dois timers.
     */
    startNotificationPolling() {
        this.fetchNotificationsAPI();

        if (this.notificationTimer) return;

        this.notificationTimer = setInterval(() => {
            // Aba escondida nao precisa de polling: economiza requisicao
            // e bateria, e o retorno a aba dispara um fetch imediato.
            if (document.hidden) return;
            this.fetchNotificationsAPI();
        }, this.POLL_INTERVAL);

        document.addEventListener("visibilitychange", () => {
            if (!document.hidden) this.fetchNotificationsAPI();
        });
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
                <div class="notification-item ${!n.is_read ? 'unread' : ''}" onclick="EchoUIInstance.openNotification(${n.id})">
                    <div class="notification-icon-wrap ${iconClass}">
                        <i class="fa-solid ${icon}"></i>
                    </div>
                    <div class="notification-content">
                        <strong>${this.escapeHTML(n.actor_name)}</strong> ${actionText}
                        <span class="notification-time">${this.formatTime(n.created_at)}</span>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = itemsHTML;
        this.updateBadge();
    }

    /**
     * Marca como lida e leva para a tela correspondente ao tipo.
     * `reference_id` e o post (like/comment/share) ou o outro usuario
     * (message), conforme docs/API_CONTRACT.md.
     */
    async openNotification(id) {
        const item = this.notifications.find(n => n.id === id);
        await this.markAsRead(id);

        if (!item) return;

        switch (item.type) {
            case 'like':
            case 'comment':
            case 'share':
                window.location = "inicio.html";
                break;
            case 'friend_request':
            case 'friend_accept':
                window.location = "amigos.html";
                break;
            case 'message':
                window.location = "chat.html";
                break;
        }
    }

    /**
     * "YYYY-MM-DD HH:MM:SS" -> tempo relativo curto ("há 5 min").
     * Datas invalidas voltam como vieram, sem quebrar a lista.
     */
    formatTime(value) {
        if (!value) return "";

        // Safari nao aceita o espaco entre data e hora do MySQL.
        const date = new Date(String(value).replace(" ", "T"));
        if (isNaN(date.getTime())) return this.escapeHTML(String(value));

        const segundos = Math.floor((Date.now() - date.getTime()) / 1000);

        if (segundos < 60)    return "agora";
        if (segundos < 3600)  return `há ${Math.floor(segundos / 60)} min`;
        if (segundos < 86400) return `há ${Math.floor(segundos / 3600)}h`;
        if (segundos < 604800) return `há ${Math.floor(segundos / 86400)}d`;

        return date.toLocaleDateString("pt-BR");
    }

    /**
     * Updates badge count
     */
    updateBadge() {
        const badge = document.getElementById('notificationBadge');
        // O contador vem do servidor: ele conta TODAS as nao lidas, nao
        // apenas as que couberam no limite da listagem.
        const unreadCount = this.unreadCount;
        
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

        // Pinta como lida na hora; o servidor confirma logo em seguida.
        if (item && !item.is_read) {
            item.is_read = true;
            this.unreadCount = Math.max(0, this.unreadCount - 1);
            this.renderNotifications();
        }

        try {
            const res = await fetch("api/notifications/mark_read.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ notification_id: id })
            });
            const data = await res.json();

            if (typeof data.unread_count === "number") {
                this.unreadCount = data.unread_count;
                this.updateBadge();
            }
        } catch (e) {
            console.error("Erro ao marcar notificação como lida:", e);
        }
    }

    /**
     * Mark all notifications as read
     */
    async markAllAsRead() {
        this.notifications.forEach(n => n.is_read = true);
        this.unreadCount = 0;
        this.renderNotifications();

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
    }

    /**
     * Fetch real notifications from API (TODO: Activate upon backend readiness)
     */
    async fetchNotificationsAPI() {
        try {
            const res = await fetch("api/notifications/list.php", {
                credentials: "same-origin"
            });

            // Sessao caiu no meio da navegacao: para o polling em vez de
            // ficar batendo em 401 para sempre.
            if (res.status === 401) {
                if (this.notificationTimer) {
                    clearInterval(this.notificationTimer);
                    this.notificationTimer = null;
                }
                return;
            }

            const data = await res.json();

            if (data.ok && Array.isArray(data.notifications)) {
                this.notifications = data.notifications;
                this.unreadCount = data.unread_count || 0;
                this.renderNotifications();
            }
        } catch (e) {
            console.error("Erro ao carregar notificações:", e);
        }
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
                this.unreadCount = 0;
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
        if (this.notificationTimer) {
            clearInterval(this.notificationTimer);
            this.notificationTimer = null;
        }

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

