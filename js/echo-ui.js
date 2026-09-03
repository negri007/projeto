/* ==========================================================================
   ECHO DESIGN SYSTEM - UI & NOTIFICATIONS MODULE (js/echo-ui.js)
   ========================================================================== */

class EchoUI {
    constructor() {
        this.notifications = [];
        this.unreadCount = 0;
        this.currentUser = null;
        this.notificationTimer = null;
        // Ultimo contador pintado no sino, para saber quando ele SOBE.
        this.ultimoUnread = undefined;
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
        // O campo "Buscar no ECHO" existe no cabeçalho de todas as telas;
        // ligá-lo aqui evita repetir a mesma ligação em cada uma.
        this.initSearchBox();
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
                case 'mention':
                    iconClass = 'notification-icon-mention';
                    icon = 'fa-at';
                    actionText = 'mencionou você.';
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

        // `reference_id` e o post em like/comment/share e o remetente em
        // message — por isso cada tipo monta um link diferente.
        switch (item.type) {
            case 'like':
            case 'comment':
            case 'share':
            // `mention` tambem aponta para o post: e onde o texto que
            // citou a pessoa esta, mesmo quando a mencao veio num
            // comentario.
            case 'mention':
                window.location = item.reference_id
                    ? "inicio.html?post=" + encodeURIComponent(item.reference_id)
                    : "inicio.html";
                break;
            case 'friend_request':
            case 'friend_accept':
                window.location = "amigos.html";
                break;
            case 'message':
                window.location = item.reference_id
                    ? "chat.html?friend=" + encodeURIComponent(item.reference_id)
                    : "chat.html";
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

        // Chegou coisa nova desde a ultima pintura: o sino balanca uma
        // vez. So quando o numero SOBE — reabrir a tela com tres avisos
        // parados nao e novidade nenhuma.
        if (this.ultimoUnread !== undefined && unreadCount > this.ultimoUnread) {
            btn.classList.remove("tocando");
            void btn.offsetWidth;
            btn.classList.add("tocando");
            btn.addEventListener("animationend",
                () => btn.classList.remove("tocando"), { once: true });
        }

        this.ultimoUnread = unreadCount;

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
                        <a class="nav-link ${activePage === 'rede_ia' ? 'active' : ''}" href="rede_ia.html">
                            <i class="fa-solid fa-robot"></i><span>Rede IA</span>
                        </a>
                        <a class="nav-link ${activePage === 'salvos' ? 'active' : ''}" href="salvos.html">
                            <i class="fa-regular fa-bookmark"></i><span>Salvos</span>
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
                <a href="rede_ia.html" class="${activePage === 'rede_ia' ? 'active' : ''}"><i class="fa-solid fa-robot"></i></a>
                <a href="salvos.html" class="${activePage === 'salvos' ? 'active' : ''}"><i class="fa-regular fa-bookmark"></i></a>
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

    /* ======================================================================
       TOASTS — feedback nao bloqueante
       ====================================================================== */

    /**
     * Mostra um aviso no canto da tela. Substitui alert(): nao trava a
     * aba, nao exige clique, e some sozinho.
     *
     * @param {string} message
     * @param {"success"|"danger"|"info"} type
     * @param {number} duration ms; 0 mantem ate o usuario fechar
     */
    toast(message, type = "info", duration = 4000) {
        let stack = document.getElementById("echoToastStack");

        if (!stack) {
            stack = document.createElement("div");
            stack.className = "echo-toast-stack";
            stack.id = "echoToastStack";
            document.body.appendChild(stack);
        }

        const icones = {
            success: "fa-circle-check",
            danger:  "fa-circle-exclamation",
            info:    "fa-circle-info"
        };

        const el = document.createElement("div");
        el.className = `echo-toast echo-toast-${type}`;
        el.setAttribute("role", type === "danger" ? "alert" : "status");
        el.innerHTML = `
            <i class="fa-solid ${icones[type] || icones.info} echo-toast-icon"></i>
            <div class="echo-toast-body">${this.escapeHTML(message)}</div>
            <button class="echo-toast-close" type="button" aria-label="Fechar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        `;

        const remover = () => {
            if (!el.isConnected) return;
            el.classList.add("leaving");
            // Espera a animacao de saida antes de tirar do DOM.
            setTimeout(() => el.remove(), 200);
        };

        el.querySelector(".echo-toast-close").onclick = remover;
        stack.appendChild(el);

        if (duration > 0) setTimeout(remover, duration);

        return el;
    }

    /** Atalhos de leitura mais direta nas telas. */
    toastSuccess(msg) { return this.toast(msg, "success"); }
    toastError(msg)   { return this.toast(msg, "danger", 6000); }

    /**
     * Mostra o campo `error` de uma resposta da API, se houver.
     * Devolve true quando havia erro — o padrao das telas vira:
     *
     *     if (EchoUIInstance.showApiError(data)) return;
     */
    showApiError(data, fallback = "Não foi possível completar a ação.") {
        if (data && data.error) {
            this.toastError(data.error);
            return true;
        }
        return false;
    }

    /* ======================================================================
       CONFIRMACAO
       ====================================================================== */

    /**
     * Dialogo de confirmacao no tema do app. Substitui confirm(), que
     * trava a aba e ignora o CSS da pagina.
     *
     * @returns {Promise<boolean>}
     */
    confirm({ title = "Tem certeza?", message = "", confirmText = "Confirmar",
              cancelText = "Cancelar", danger = false } = {}) {
        return new Promise(resolve => {
            const backdrop = document.createElement("div");
            backdrop.className = "echo-dialog-backdrop";
            backdrop.innerHTML = `
                <div class="echo-dialog" role="dialog" aria-modal="true">
                    <h5>${this.escapeHTML(title)}</h5>
                    ${message ? `<p>${this.escapeHTML(message)}</p>` : ""}
                    <div class="echo-dialog-actions">
                        <button type="button" class="echo-dialog-cancel">${this.escapeHTML(cancelText)}</button>
                        <button type="button" class="echo-dialog-confirm ${danger ? "danger" : ""}">${this.escapeHTML(confirmText)}</button>
                    </div>
                </div>
            `;

            const fechar = (resultado) => {
                document.removeEventListener("keydown", aoTeclar);
                backdrop.remove();
                resolve(resultado);
            };

            // Esc cancela; Enter NAO confirma. O atalho de teclado servia
            // ate a acao ser destrutiva: um Enter distraido com o dialogo
            // de apagar aberto apagava o post. Confirmar exige o clique
            // (ou Tab ate o botao e entao Enter, que ja e uma escolha).
            const aoTeclar = (e) => {
                if (e.key === "Escape") fechar(false);
            };

            backdrop.querySelector(".echo-dialog-cancel").onclick  = () => fechar(false);
            backdrop.querySelector(".echo-dialog-confirm").onclick = () => fechar(true);
            // Clique fora cancela; clique dentro do card, nao.
            backdrop.onclick = (e) => { if (e.target === backdrop) fechar(false); };

            document.addEventListener("keydown", aoTeclar);
            document.body.appendChild(backdrop);

            // O foco vai para Cancelar, e nao para Confirmar: botao
            // focado responde a Enter por conta propria, entao focar o
            // Confirmar traria de volta exatamente o acidente que a
            // mudanca acima evita. O padrao de um dialogo destrutivo e
            // nao fazer nada.
            backdrop.querySelector(".echo-dialog-cancel").focus();
        });
    }

    /* ======================================================================
       AVATARES
       ====================================================================== */

    /**
     * Cor estavel a partir do id do usuario: a mesma pessoa recebe
     * sempre a mesma cor, em qualquer tela, sem guardar nada no banco.
     */
    avatarColor(userId) {
        const paleta = [
            "#1d9bf0", "#00ba7c", "#f91880", "#ff7a00", "#7856ff",
            "#00b0d8", "#e0245e", "#17bf63", "#794bc4", "#f45d22"
        ];
        return paleta[Math.abs(Number(userId) || 0) % paleta.length];
    }

    /**
     * HTML do avatar: a foto quando existe, senao a inicial do nome
     * sobre a cor da pessoa.
     *
     * @param {{user_id?:number, id?:number, name?:string, avatar?:string}} user
     * @param {"sm"|"md"|"lg"} size
     * @param {boolean} link se true, clicar abre o perfil da pessoa
     */
    avatarHTML(user, size = "md", link = false) {
        const id      = Number(user?.user_id ?? user?.id ?? 0);
        const nome    = user?.name || "?";
        const inicial = this.escapeHTML(nome.trim().charAt(0) || "?");
        const classes = `echo-avatar echo-avatar-${size}${link ? " echo-avatar-link" : ""}`;
        const onclick = link && id ? ` onclick="EchoUIInstance.openProfile(${id})"` : "";

        if (user?.avatar) {
            const url = `uploads/${encodeURIComponent(user.avatar)}`;
            return `<div class="${classes}" style="background-image:url('${url}')"
                         title="${this.escapeHTML(nome)}"${onclick}></div>`;
        }

        return `<div class="${classes}" style="background:${this.avatarColor(id)}"
                     title="${this.escapeHTML(nome)}"${onclick}>${inicial}</div>`;
    }

    /** Abre o perfil de alguem. O proprio perfil vai sem parametro. */
    openProfile(userId) {
        const id = Number(userId);

        if (this.currentUser && id === Number(this.currentUser.id)) {
            window.location = "perfil.html";
            return;
        }

        window.location = "perfil.html?user_id=" + encodeURIComponent(id);
    }

    /** Nome clicavel que leva ao perfil da pessoa. */
    authorLinkHTML(user, className = "") {
        const id = Number(user?.user_id ?? user?.id ?? 0);
        const nome = this.escapeHTML(user?.name || "Usuário");

        if (!id) return `<span class="${className}">${nome}</span>`;

        return `<a class="echo-author-link ${className}" role="button"
                   onclick="EchoUIInstance.openProfile(${id})">${nome}</a>`;
    }

    /* ======================================================================
       EDICAO DE POST
       ====================================================================== */

    /**
     * Troca o texto do post por um editor no lugar. Fica aqui, e nao em
     * cada tela, porque o feed aparece igual em inicio, explorar e
     * perfil — e um editor duplicado tres vezes vira tres bugs.
     *
     * @param {number} postId
     * @param {Function} onSaved chamado apos salvar (a tela recarrega o feed)
     */
    async editPost(postId, onSaved) {
        const box = document.getElementById(`post-content-${postId}`);
        if (!box || box.dataset.editing === "1") return;

        const textoOriginal = box.textContent.trim();
        box.dataset.editing = "1";
        box.dataset.original = textoOriginal;

        box.innerHTML = `
            <textarea class="form-control form-control-sm mb-2"
                      id="post-edit-input-${postId}" rows="3"
                      maxlength="5000">${this.escapeHTML(textoOriginal)}</textarea>
            <div class="d-flex gap-2 justify-content-end">
                <button class="btn btn-sm btn-outline-light rounded-pill px-3"
                        type="button" id="post-edit-cancel-${postId}">Cancelar</button>
                <button class="btn btn-sm btn-primary rounded-pill px-3"
                        type="button" id="post-edit-save-${postId}">Salvar</button>
            </div>
        `;

        const input  = document.getElementById(`post-edit-input-${postId}`);
        const salvar = document.getElementById(`post-edit-save-${postId}`);

        input.focus();
        // Cursor no fim, nao no comeco do texto.
        input.setSelectionRange(input.value.length, input.value.length);

        const cancelar = () => {
            box.dataset.editing = "0";
            box.textContent = textoOriginal;
        };

        document.getElementById(`post-edit-cancel-${postId}`).onclick = cancelar;

        input.onkeydown = (e) => {
            if (e.key === "Escape") cancelar();
            // Ctrl+Enter salva, como em qualquer caixa de texto longa.
            if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) salvar.click();
        };

        salvar.onclick = async () => {
            const novo = input.value.trim();

            if (novo === textoOriginal) {
                cancelar();
                return;
            }

            salvar.disabled = true;

            try {
                const res = await fetch("api/posts/edit.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    credentials: "same-origin",
                    body: JSON.stringify({ post_id: postId, content: novo })
                });
                const data = await res.json();

                if (this.showApiError(data)) {
                    salvar.disabled = false;
                    return;
                }

                box.dataset.editing = "0";
                this.toastSuccess("Publicação atualizada.");
                if (typeof onSaved === "function") onSaved();
            } catch (e) {
                this.toastError("Erro de conexão ao salvar a edição.");
                salvar.disabled = false;
            }
        };
    }

    /* ======================================================================
       TEXTO RICO — #etiquetas e @menções
       ====================================================================== */

    /**
     * Escapa o texto e transforma `#etiqueta` em link de busca e
     * `@handle` em link para a pessoa.
     *
     * A ordem importa: **escapar primeiro, ligar depois**. Ligar antes
     * de escapar deixaria o HTML dos links ser comido pelo escape — ou,
     * pior, deixaria passar HTML do usuário.
     */
    richTextHTML(texto) {
        if (!texto) return "";

        let html = this.escapeHTML(texto);

        // #etiqueta -> filtro do explorar. As regras (letras, números,
        // `_`, `-`; nunca só número) são as mesmas de
        // posts_extract_tags() no back — se divergirem, o link leva a uma
        // busca vazia.
        html = html.replace(
            /(^|[^\w#&])#([\p{L}\p{N}_-]{1,64})/gu,
            (todo, antes, tag) => /^\d+$/u.test(tag)
                ? todo
                : `${antes}<a class="echo-tag" href="explorar.html?tag=${encodeURIComponent(tag.toLowerCase())}">#${tag}</a>`
        );

        // @handle -> busca por aquela pessoa. O handle é a parte do
        // e-mail antes do `@`, igual ao que o back usa para notificar.
        html = html.replace(
            /(^|[^\w@.&;])@([a-z0-9._-]{2,64})/gi,
            (todo, antes, handle) => {
                const limpo = handle.replace(/\.+$/, "");
                return `${antes}<a class="echo-mention" href="explorar.html?q=${encodeURIComponent(limpo)}">@${limpo}</a>`
                     + handle.slice(limpo.length);
            }
        );

        // Quebra de linha digitada é quebra de linha na tela.
        return html.replace(/\n/g, "<br>");
    }

    /**
     * Empurra uma rodada da rede de agentes, em fire-and-forget.
     *
     * Não espera resposta e engole erro de propósito: o carregamento da
     * tela não pode depender disso. O servidor já cuida de trava,
     * intervalo mínimo e moderação — chamar demais é inofensivo.
     */
    pingRedeIA() {
        try {
            fetch("api/ai/tick.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: "{}",
                keepalive: true
            }).catch(() => {});
        } catch (e) {
            /* silêncio: a rede das IAs é entretenimento, não pode
               atrapalhar a tela de ninguém */
        }
    }

    /** Abre o explorar já filtrado por uma etiqueta. */
    openTag(tag) {
        window.location = "explorar.html?tag=" + encodeURIComponent(String(tag).replace(/^#/, ""));
    }

    /* ======================================================================
       BUSCA GLOBAL — o campo "Buscar no ECHO" do cabeçalho
       ====================================================================== */

    /**
     * Liga o campo de busca do cabeçalho: sugestões enquanto se digita e
     * Enter para a tela cheia de resultados.
     *
     * Uma chamada só (`api/search/all.php`) traz pessoas, publicações,
     * etiquetas e círculos — o campo é um, a requisição é uma.
     */
    initSearchBox() {
        const caixa = document.querySelector(".search-box");
        const input = caixa?.querySelector("input");

        if (!input || caixa.dataset.ligado === "1") return;

        caixa.dataset.ligado = "1";
        caixa.classList.add("echo-search");
        input.setAttribute("autocomplete", "off");

        const painel = document.createElement("div");
        painel.className = "echo-search-results";
        painel.hidden = true;
        caixa.appendChild(painel);

        const fechar = () => { painel.hidden = true; };

        // Espera a digitação parar: uma requisição por tecla seria uma
        // requisição por tecla.
        let timer = null;

        const buscar = async () => {
            const q = input.value.trim();

            if (q.length < 2) {
                fechar();
                return;
            }

            try {
                const res = await fetch("api/search/all.php?limit=5&q=" + encodeURIComponent(q), {
                    credentials: "same-origin"
                });
                const data = await res.json();

                if (data.error) {
                    fechar();
                    return;
                }

                painel.innerHTML = this.searchResultsHTML(data);
                painel.hidden = false;
            } catch (e) {
                fechar();
            }
        };

        input.addEventListener("input", () => {
            clearTimeout(timer);
            timer = setTimeout(buscar, 250);
        });

        input.addEventListener("keydown", (e) => {
            if (e.key === "Enter") {
                const q = input.value.trim();
                if (q) window.location = "explorar.html?q=" + encodeURIComponent(q);
            }
            if (e.key === "Escape") fechar();
        });

        input.addEventListener("focus", () => {
            if (painel.innerHTML.trim() && input.value.trim().length >= 2) painel.hidden = false;
        });

        // Clique fora fecha; clique dentro do painel, não — senão o link
        // some antes do clique chegar nele.
        document.addEventListener("click", (e) => {
            if (!caixa.contains(e.target)) fechar();
        });
    }

    /** Prévia dos resultados da busca, agrupada por tipo. */
    searchResultsHTML(data) {
        const q = data.query || "";
        const partes = [];

        if (data.users?.length) {
            partes.push(`<div class="echo-search-group">Pessoas</div>`);
            data.users.forEach(u => {
                partes.push(`
                    <a class="echo-search-item" role="button" onclick="EchoUIInstance.openProfile(${u.user_id})">
                        ${this.avatarHTML(u, "sm")}
                        <span class="echo-search-text">
                            <strong>${this.escapeHTML(u.name)}</strong>
                            <small>@${this.escapeHTML((u.email || "").split("@")[0])}</small>
                        </span>
                    </a>`);
            });
        }

        if (data.hashtags?.length) {
            partes.push(`<div class="echo-search-group">Etiquetas</div>`);
            data.hashtags.forEach(h => {
                partes.push(`
                    <a class="echo-search-item" href="explorar.html?tag=${encodeURIComponent(h.tag)}">
                        <span class="echo-search-hash"><i class="fa-solid fa-hashtag"></i></span>
                        <span class="echo-search-text">
                            <strong>#${this.escapeHTML(h.tag)}</strong>
                            <small>${h.post_count} ${h.post_count === 1 ? "publicação" : "publicações"}</small>
                        </span>
                    </a>`);
            });
        }

        if (data.circles?.length) {
            partes.push(`<div class="echo-search-group">Círculos</div>`);
            data.circles.forEach(c => {
                partes.push(`
                    <a class="echo-search-item" href="circle_chat.html?circle_id=${c.id}">
                        <span class="echo-search-hash"><i class="fa-regular fa-circle"></i></span>
                        <span class="echo-search-text">
                            <strong>${this.escapeHTML(c.name)}</strong>
                            <small>${c.is_owner ? "seu círculo" : "você é membro"}</small>
                        </span>
                    </a>`);
            });
        }

        if (data.posts?.length) {
            partes.push(`<div class="echo-search-group">Publicações</div>`);
            data.posts.slice(0, 3).forEach(p => {
                partes.push(`
                    <a class="echo-search-item" href="inicio.html?post=${p.id}">
                        ${this.avatarHTML(p, "sm")}
                        <span class="echo-search-text">
                            <strong>${this.escapeHTML(p.name)}</strong>
                            <small>${this.escapeHTML((p.content || "").slice(0, 60))}</small>
                        </span>
                    </a>`);
            });
        }

        if (!partes.length) {
            return `<div class="echo-search-empty">Nada encontrado para “${this.escapeHTML(q)}”.</div>`;
        }

        partes.push(`
            <a class="echo-search-all" href="explorar.html?q=${encodeURIComponent(q)}">
                Ver todos os resultados de “${this.escapeHTML(q)}”
            </a>`);

        return partes.join("");
    }

    /* ======================================================================
       AUTOCOMPLETE DE MENÇÃO
       ====================================================================== */

    /**
     * Sugere pessoas quando se digita `@` num campo de texto.
     *
     * Só o handle exato vira notificação no servidor, então errar o nome
     * é escrever uma menção que não avisa ninguém — o autocomplete
     * existe para isso não acontecer.
     */
    attachMentionAutocomplete(campo) {
        if (!campo || campo.dataset.mencao === "1") return;
        campo.dataset.mencao = "1";

        const painel = document.createElement("div");
        painel.className = "echo-mention-box";
        painel.hidden = true;

        // O painel é posicionado em relação ao campo; o container precisa
        // ser o pai posicionado.
        const pai = campo.parentElement;
        if (pai && getComputedStyle(pai).position === "static") pai.style.position = "relative";
        pai?.appendChild(painel);

        let timer = null;

        const fechar = () => { painel.hidden = true; };

        const trechoDaMencao = () => {
            const ate = campo.value.slice(0, campo.selectionStart ?? campo.value.length);
            const m   = ate.match(/(?:^|[^\w@.])@([a-z0-9._-]{1,64})$/i);
            return m ? m[1] : null;
        };

        const escolher = (email) => {
            const handle = (email || "").split("@")[0];
            const pos    = campo.selectionStart ?? campo.value.length;
            const antes  = campo.value.slice(0, pos).replace(/@[a-z0-9._-]*$/i, "@" + handle + " ");
            const depois = campo.value.slice(pos);

            campo.value = antes + depois;
            campo.focus();
            campo.setSelectionRange(antes.length, antes.length);
            fechar();
        };

        campo.addEventListener("input", () => {
            const termo = trechoDaMencao();

            if (termo === null || termo.length < 1) {
                fechar();
                return;
            }

            clearTimeout(timer);
            timer = setTimeout(async () => {
                try {
                    const res = await fetch("api/friends/search.php?q=" + encodeURIComponent(termo), {
                        credentials: "same-origin"
                    });
                    const data = await res.json();

                    if (!data.ok || !data.users?.length) {
                        fechar();
                        return;
                    }

                    painel.innerHTML = data.users.slice(0, 6).map(u => `
                        <button type="button" class="echo-mention-item" data-email="${this.escapeHTML(u.email)}">
                            ${this.avatarHTML(u, "sm")}
                            <span class="echo-search-text">
                                <strong>${this.escapeHTML(u.name)}</strong>
                                <small>@${this.escapeHTML((u.email || "").split("@")[0])}</small>
                            </span>
                        </button>
                    `).join("");

                    painel.querySelectorAll(".echo-mention-item").forEach(btn => {
                        btn.onclick = () => escolher(btn.dataset.email);
                    });

                    painel.hidden = false;
                } catch (e) {
                    fechar();
                }
            }, 200);
        });

        campo.addEventListener("keydown", (e) => {
            if (e.key === "Escape") fechar();
        });

        campo.addEventListener("blur", () => {
            // Espera o clique no item acontecer antes de esconder.
            setTimeout(fechar, 150);
        });
    }

    /* ======================================================================
       CARDS DA COLUNA DIREITA — tendências e sugestões
       ====================================================================== */

    /**
     * Preenche o card de tendências com as etiquetas reais dos últimos
     * dias. Até aqui o card era texto fixo no HTML (#PHP, #Linux, #IA).
     */
    async renderTrending(containerId = "trendingCard", limit = 5) {
        const box = document.getElementById(containerId);
        if (!box) return;

        try {
            const res  = await fetch(`api/hashtags/trending.php?limit=${limit}`, { credentials: "same-origin" });
            const data = await res.json();

            if (!data.ok || !data.hashtags.length) {
                box.innerHTML = `<p class="text-secondary mb-0 small">
                    Nenhum assunto em alta ainda. Publique com <strong>#etiqueta</strong> para começar um.</p>`;
                return;
            }

            box.innerHTML = data.hashtags.map((h, i) => `
                <a class="echo-trend" href="explorar.html?tag=${encodeURIComponent(h.tag)}">
                    <span class="echo-trend-pos">${i + 1}</span>
                    <span class="echo-trend-body">
                        <strong>#${this.escapeHTML(h.tag)}</strong>
                        <small>${h.post_count} ${h.post_count === 1 ? "publicação" : "publicações"}
                               · ${h.people_count} ${h.people_count === 1 ? "pessoa" : "pessoas"}</small>
                    </span>
                </a>
            `).join("");
        } catch (e) {
            box.innerHTML = `<p class="text-secondary mb-0 small">Não foi possível carregar as tendências.</p>`;
        }
    }

    /**
     * Fileira de etiquetas em alta, em forma de chip. É o topo do
     * Explorar: entrar num assunto tem de ser um toque, não uma busca
     * digitada.
     */
    async renderTagChips(containerId = "tagChips", limit = 10) {
        const box = document.getElementById(containerId);
        if (!box) return;

        try {
            const res  = await fetch(`api/hashtags/trending.php?limit=${limit}&days=30`, { credentials: "same-origin" });
            const data = await res.json();

            if (!data.ok || !data.hashtags.length) {
                box.innerHTML = `<p class="text-secondary small mb-0">
                    Nenhuma etiqueta ainda. Publique com <strong>#etiqueta</strong> no Início para abrir o primeiro assunto.</p>`;
                return;
            }

            box.innerHTML = data.hashtags.map(h => `
                <a class="echo-chip" href="explorar.html?tag=${encodeURIComponent(h.tag)}">
                    #${this.escapeHTML(h.tag)}<span class="echo-chip-count">${h.post_count}</span>
                </a>
            `).join("");
        } catch (e) {
            box.innerHTML = `<p class="text-secondary small mb-0">Não foi possível carregar as etiquetas.</p>`;
        }
    }

    /**
     * Card "Seus círculos" do Início. Fica aqui, e não no Explorar, de
     * propósito: o Início é a sua roda; o Explorar é o que está fora
     * dela.
     */
    async renderMyCircles(containerId = "circlesCard", limit = 4) {
        const box = document.getElementById(containerId);
        if (!box) return;

        try {
            const res  = await fetch("api/circles/list.php", { credentials: "same-origin" });
            const data = await res.json();

            if (!data.ok || !data.circles?.length) {
                box.innerHTML = `<p class="text-secondary mb-0 small">
                    Você ainda não participa de nenhum círculo.
                    <a href="circulos.html">Criar um</a>.</p>`;
                return;
            }

            box.innerHTML = data.circles.slice(0, limit).map(c => `
                <a class="echo-trend" href="circle_chat.html?circle_id=${c.id}">
                    <span class="echo-search-hash"><i class="fa-regular fa-circle"></i></span>
                    <span class="echo-trend-body">
                        <strong>${this.escapeHTML(c.name)}</strong>
                        <small>${c.is_owner ? "seu círculo" : "você é membro"} ·
                               ${c.member_count} ${c.member_count === 1 ? "membro" : "membros"}</small>
                    </span>
                </a>
            `).join("");
        } catch (e) {
            box.innerHTML = `<p class="text-secondary mb-0 small">Não foi possível carregar os círculos.</p>`;
        }
    }

    /**
     * Preenche o card "Talvez você conheça" com gente de verdade
     * (`api/friends/suggestions.php`), com botão de adicionar.
     */
    async renderSuggestions(containerId = "suggestionsCard", limit = 3) {
        const box = document.getElementById(containerId);
        if (!box) return;

        try {
            const res  = await fetch("api/friends/suggestions.php", { credentials: "same-origin" });
            const data = await res.json();

            if (!data.ok || !data.users?.length) {
                box.innerHTML = `<p class="text-secondary mb-0 small">Nenhuma sugestão por enquanto.</p>`;
                return;
            }

            box.innerHTML = data.users.slice(0, limit).map(u => `
                <div class="echo-suggestion" id="suggestion-${u.user_id}">
                    ${this.avatarHTML(u, "sm", true)}
                    <div class="echo-suggestion-body">
                        ${this.authorLinkHTML(u, "fw-bold small")}
                        <small class="text-secondary">@${this.escapeHTML((u.email || "").split("@")[0])}</small>
                    </div>
                    <button class="btn btn-sm btn-outline-light rounded-pill px-3" type="button"
                            onclick="EchoUIInstance.addFriend(${u.user_id})">Seguir</button>
                </div>
            `).join("");
        } catch (e) {
            box.innerHTML = `<p class="text-secondary mb-0 small">Não foi possível carregar as sugestões.</p>`;
        }
    }

    /**
     * Envia pedido de amizade (card de sugestões e resultados da busca).
     * Devolve true quando o pedido entrou — quem chamou usa isso para
     * decidir se troca o botão.
     */
    async addFriend(userId) {
        try {
            const res = await fetch("api/friends/send.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ user_id: userId })
            });
            const data = await res.json();

            if (this.showApiError(data)) return false;

            this.toastSuccess(data.auto_accepted
                ? "Vocês agora são amigos."
                : "Pedido de amizade enviado.");

            document.getElementById("suggestion-" + userId)?.remove();
            return true;
        } catch (e) {
            this.toastError("Erro de conexão ao enviar o pedido.");
            return false;
        }
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

