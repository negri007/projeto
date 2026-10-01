/* ==========================================================================
   ECHO DESIGN SYSTEM - UI & NOTIFICATIONS MODULE (js/echo-ui.js)
   ========================================================================== */

/**
 * Alterna um campo de senha entre oculto e visivel. `btn` e o botao-olho;
 * o input alvo e o irmao anterior a ele no DOM (ver .pwd-wrapper /
 * .input-icon-wrapper nos formularios de login, cadastro, reset e troca
 * de senha).
 */
function toggleSenhaVisibilidade(btn) {
    const input = btn.previousElementSibling;
    if (!input) return;

    const icon = btn.querySelector("i");
    const vaiMostrar = input.type === "password";

    input.type = vaiMostrar ? "text" : "password";

    if (icon) {
        icon.classList.toggle("fa-eye", !vaiMostrar);
        icon.classList.toggle("fa-eye-slash", vaiMostrar);
    }

    btn.setAttribute("aria-label", vaiMostrar ? "Ocultar senha" : "Mostrar senha");
}

/**
 * Fonte única do menu. A ordem é a do desktop; cada item tem `key` (a mesma
 * string passada para initHeader), `href`, `icon` (classe FontAwesome) e
 * `label`. Antes esse menu era escrito à mão em cada página; agora vem daqui,
 * então muda num lugar só e nunca diverge entre telas.
 */
const ECHO_NAV = [
    { key: "explorar", href: "explorar.html", icon: "fa-solid fa-magnifying-glass", label: "Explorar" },
    { key: "academico", href: "turmas.html",  icon: "fa-solid fa-graduation-cap", label: "Aulas" },
    { key: "inicio",   href: "inicio.html",   icon: "fa-solid fa-house", label: "Início" },
    { key: "perfil",   href: "perfil.html",   icon: "fa-solid fa-user", label: "Perfil" },
    { key: "salvos",   href: "salvos.html",   icon: "fa-regular fa-bookmark", label: "Salvos" },
    { key: "circulos", href: "circulos.html", icon: "fa-regular fa-circle", label: "Círculos" },
    { key: "amigos",   href: "amigos.html",   icon: "fa-solid fa-user-group", label: "Amigos" },
    { key: "chat",     href: "chat.html",     icon: "fa-solid fa-comments", label: "Mensagens" },
];

// Barra inferior do mobile: subconjunto do menu, com ordem própria (Início
// primeiro). Referencia os itens de ECHO_NAV por key, então label/ícone/href
// continuam vindo de um lugar só.
const ECHO_BOTTOM = ["inicio", "explorar", "academico", "salvos", "circulos", "amigos", "chat"];

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
    /* ==================================================================
       CONTADORES NO MENU

       Sobrou do "pulso do Echo", que trazia também um gráfico de barras na
       barra lateral. O gráfico saiu a pedido do dono do projeto, e com
       razão: barra de navegação não tem largura para eixo, escala nem
       legenda, e sem isso o gráfico virava um widget solto no meio do
       menu. Os contadores ficaram, porque resolvem um problema real de
       navegação.

       `api/pulso.php` continua servindo os números. A parte de atividade
       da resposta deixou de ser usada aqui — fica disponível para quem
       quiser, e não custa nada, porque é a mesma consulta.
       ================================================================== */

    /** Busca os números e pendura no menu. Falha em silêncio: o menu
     *  funciona sem contador, e aviso de erro na navegação atrapalha
     *  mais do que a falta do número. */
    async carregarContadoresDoMenu() {
        try {
            const res  = await fetch("api/pulso.php", { credentials: "same-origin" });
            const data = await res.json();

            if (data.ok) {
                this.pendurarContadores(data.contadores);
            }
        } catch (e) { /* silêncio de propósito */ }
    }

    /**
     * Bolinha com número em cima do item de menu.
     *
     * É a informação que o menu já devia dar e não dava: até agora só se
     * descobria um pedido de amizade entrando na página de amigos.
     * "Salvos" entra sem bolinha de alerta, com contagem discreta — ter
     * post salvo não é pendência, é acervo.
     */
    pendurarContadores(c) {
        const mapa = {
            "chat.html":      { n: c.mensagens, alerta: true },
            "amigos.html":    { n: c.amigos,    alerta: true },
            "salvos.html":    { n: c.salvos,    alerta: false },
        };

        document.querySelectorAll(".sidebar .nav-link").forEach(link => {
            const arquivo = (link.getAttribute("href") || "").split("/").pop();
            const info    = mapa[arquivo];

            link.querySelector(".echo-nav-contador")?.remove();
            if (!info || !info.n) return;

            const b = document.createElement("span");
            b.className = "echo-nav-contador" + (info.alerta ? " echo-nav-alerta" : "");
            b.textContent = info.n > 99 ? "99+" : info.n;
            link.appendChild(b);
        });
    }

    /**
     * Cartão da coluna da direita que a pessoa pode recolher, e que lembra
     * a escolha dela.
     *
     * Na Rede IA a coluna soma 2194px de cartões numa tela de 960: é o
     * problema inverso do vazio da barra lateral. Dois desses cartões são
     * TEXTO EXPLICATIVO ("Como funciona", 495px) — útil na primeira
     * visita, empurrão de scroll em todas as outras.
     *
     * Recolher, e não remover: quem chega novo precisa da explicação. A
     * escolha fica em localStorage por cartão, então quem dobrou uma vez
     * não dobra de novo, e quem nunca mexeu continua vendo tudo.
     */
    tornarCartoesDobraveis() {
        // Todo cartão da direita vira dobrável: título com seta, clique ou
        // teclado (Enter/Espaço) recolhe, e o app lembra por cartão. Abre por
        // padrão — esconder tudo no carregamento deixaria a coluna "vazia".
        // Um cartão sai da regra com data-dobravel="nao".
        document.querySelectorAll(".right-card").forEach((card, i) => {
            if (card.dataset.dobravel === "nao") return;

            const titulo = card.querySelector("h5");
            if (!titulo || titulo.dataset.ligado) return;

            // Chave estável: o data-dobravel quando existe, senão um slug do
            // título (com o índice de reserva, para dois títulos iguais).
            const slug = (card.dataset.dobravel
                || titulo.textContent.trim().toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "")
                || ("card-" + i));
            const chave = "echo_card_" + slug;

            titulo.dataset.ligado = "1";
            titulo.classList.add("right-card-titulo-dobravel");

            // Envolve o conteúdo depois do título num corpo animável (grade
            // 1fr->0fr no CSS), para a altura fechar sem max-height fixo.
            const corpo = document.createElement("div");
            corpo.className = "right-card-corpo";
            corpo.id = "rc-" + slug + "-" + i;
            const dentro = document.createElement("div");
            dentro.className = "right-card-corpo-in";
            while (titulo.nextSibling) dentro.appendChild(titulo.nextSibling);
            corpo.appendChild(dentro);
            card.appendChild(corpo);

            const seta = document.createElement("i");
            seta.className = "fa-solid fa-chevron-up right-card-seta";
            titulo.appendChild(seta);

            // O título é o botão de expandir/recolher (padrão disclosure).
            titulo.setAttribute("role", "button");
            titulo.setAttribute("tabindex", "0");
            titulo.setAttribute("aria-controls", corpo.id);

            // Padrão: recolhido. A pessoa abre o que quiser e o app lembra;
            // só reabre sozinho um cartão que ela já tinha aberto ("0").
            let dobrado = true;

            // localStorage pode explodir em aba anônima ou com dados de
            // site bloqueados; o cartão segue recolhido do mesmo jeito.
            try {
                if (localStorage.getItem(chave) === "0") dobrado = false;
            } catch (e) { /* segue recolhido */ }

            const aplicar = () => {
                card.classList.toggle("right-card-dobrado", dobrado);
                // aria-expanded = aberto; a seta girada é só reforço visual.
                titulo.setAttribute("aria-expanded", String(!dobrado));
            };
            aplicar();

            const alterna = () => {
                dobrado = !dobrado;
                aplicar();
                try {
                    localStorage.setItem(chave, dobrado ? "1" : "0");
                } catch (e) { /* a sessão atual já respeitou o clique */ }
            };

            titulo.addEventListener("click", alterna);
            titulo.addEventListener("keydown", (e) => {
                if (e.key === "Enter" || e.key === " " || e.key === "Spacebar") {
                    e.preventDefault();
                    alterna();
                }
            });
        });
    }

    initHeader(currentPage) {
        // Primeiro de tudo: preenche a barra lateral, o mais cedo possível,
        // para a navegação aparecer antes do resto ligar.
        this.buildSidebar(currentPage);
        this.injectMobileOffcanvas(currentPage);
        this.setupNotificationDropdown();
        this.renderNotifications();
        this.startNotificationRealtime();
        // O campo "Buscar no ECHO" existe no cabeçalho de todas as telas;
        // ligá-lo aqui evita repetir a mesma ligação em cada uma.
        this.initSearchBox();
        // Preenche o vazio da barra lateral e pendura os contadores no
        // menu. Vale em todas as páginas, porque a barra é a mesma.
        this.carregarContadoresDoMenu();
        this.tornarCartoesDobraveis();
    }

    /**
     * Preenche a barra lateral do desktop (#echoSidebar) a partir de ECHO_NAV.
     * Marca o item ativo por `key` (com aria-current="page"), preenche o
     * mini-perfil e mantém o botão de logout.
     *
     * Se a página não tem #echoSidebar (fora do shell do app), não faz nada.
     * Se a montagem quebrar por algum motivo, cai num menu mínimo só com os
     * links, para a pessoa nunca ficar sem navegação.
     */
    buildSidebar(activeKey) {
        const aside = document.getElementById("echoSidebar");
        if (!aside) return;

        try {
            const itens = ECHO_NAV.map(it => {
                const ativo = it.key === activeKey;
                return `<a class="nav-link${ativo ? " active" : ""}" href="${it.href}"${ativo ? ' aria-current="page"' : ""}>
                    <i class="${it.icon}"></i><span>${it.label}</span>
                </a>`;
            }).join("");

            aside.innerHTML = `
                <div class="logo d-flex align-items-center gap-2">
                    <div class="logo-mark"><i class="fa-solid fa-hashtag"></i></div>
                    <span class="logo-text">ECHO</span>
                </div>

                <nav class="nav flex-column mb-3" aria-label="Principal">
                    ${itens}
                </nav>

                <div class="sidebar-profile d-flex align-items-center gap-2">
                    <div class="prof-avatar"><i class="fa-solid fa-user"></i></div>
                    <div class="prof-info flex-grow-1">
                        <div class="prof-name" id="sidebarName">Usuário</div>
                        <div class="prof-handle" id="sidebarHandle">@usuario</div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill" onclick="logout()" title="Sair">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    </button>
                </div>`;

            if (this.currentUser) this.updateUserProfileUI(this.currentUser);
        } catch (e) {
            // Menu mínimo de emergência: só os links, sem ícones nem perfil.
            aside.innerHTML = '<nav class="nav flex-column mb-3" aria-label="Principal">' +
                ECHO_NAV.map(it => `<a class="nav-link" href="${it.href}"><span>${it.label}</span></a>`).join("") +
                '</nav>';
        }
    }

    /**
     * Notificação do sino em tempo real via SSE (api/notifications/stream.php),
     * com o polling antigo como reserva — usado se o navegador não tiver
     * EventSource, ou se a conexão falhar repetidas vezes seguidas (sessão
     * caiu, servidor fora do ar).
     */
    startNotificationRealtime() {
        this.fetchNotificationsAPI();

        if (typeof EventSource === "undefined") {
            this.startNotificationPolling();
            return;
        }

        this.sseErrosSeguidos = 0;
        this.abrirNotificationStream();

        document.addEventListener("visibilitychange", () => {
            if (!document.hidden && !this.notificationStream && !this.notificationTimer) {
                this.abrirNotificationStream();
            }
        });
    }

    /**
     * Abre a conexão SSE do sino. O servidor fecha sozinho a cada ~25s
     * (ver comentário em stream.php) e o EventSource reconecta sozinho —
     * isso dispara `onerror` mesmo em reconexão normal, então só cai pro
     * polling depois de vários erros seguidos sem nenhuma mensagem entre
     * eles.
     */
    abrirNotificationStream() {
        if (this.notificationStream) return;

        const es = new EventSource("api/notifications/stream.php");
        this.notificationStream = es;

        es.addEventListener("notification", (ev) => {
            this.sseErrosSeguidos = 0;

            try {
                const data = JSON.parse(ev.data);
                this.mesclarNotificacoes(data.notifications || []);

                if (typeof data.unread_count === "number") {
                    this.unreadCount = data.unread_count;
                }

                this.renderNotifications();
            } catch (e) {
                console.error("Erro ao processar notificação SSE:", e);
            }
        });

        es.onopen = () => {
            this.sseErrosSeguidos = 0;
        };

        es.onerror = () => {
            this.sseErrosSeguidos = (this.sseErrosSeguidos || 0) + 1;

            if (this.sseErrosSeguidos >= 8) {
                es.close();
                this.notificationStream = null;
                this.startNotificationPolling();
            }
        };
    }

    /**
     * Funde notificações novas (vindas do SSE, ordem crescente de id) na
     * lista já carregada, sem duplicar, mantendo ordem decrescente de id
     * — o mesmo formato que list.php devolve.
     */
    mesclarNotificacoes(novas) {
        if (!novas.length) return;

        const porId = new Map(this.notifications.map(n => [n.id, n]));
        novas.forEach(n => porId.set(n.id, n));

        this.notifications = Array.from(porId.values()).sort((a, b) => b.id - a.id);
    }

    /**
     * Reserva: busca as notificacoes agora e passa a repetir a cada
     * POLL_INTERVAL. Só entra em uso se o SSE não estiver disponível.
     * Chamar duas vezes nao cria dois timers.
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

            // Sessao caiu no meio da navegacao: para o polling e o SSE em
            // vez de ficar batendo em 401 para sempre.
            if (res.status === 401) {
                if (this.notificationTimer) {
                    clearInterval(this.notificationTimer);
                    this.notificationTimer = null;
                }
                if (this.notificationStream) {
                    this.notificationStream.close();
                    this.notificationStream = null;
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

        // Menu do drawer: mesma lista e mesma ordem do desktop (ECHO_NAV).
        const navItens = ECHO_NAV.map(it => {
            const ativo = it.key === activePage;
            return `<a class="nav-link${ativo ? " active" : ""}" href="${it.href}"${ativo ? ' aria-current="page"' : ""}>
                            <i class="${it.icon}"></i><span>${it.label}</span>
                        </a>`;
        }).join("");

        // Barra inferior: subconjunto com ordem própria (ECHO_BOTTOM), só ícone.
        const porKey = k => ECHO_NAV.find(it => it.key === k);
        const bottomItens = ECHO_BOTTOM.map(k => {
            const it = porKey(k);
            if (!it) return "";
            const ativo = it.key === activePage;
            return `<a href="${it.href}" class="${ativo ? "active" : ""}"${ativo ? ' aria-current="page"' : ""}><i class="${it.icon}"></i></a>`;
        }).join("");

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
                    <nav class="nav flex-column gap-1" aria-label="Principal">
                        ${navItens}
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
                ${bottomItens}
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', offcanvasHTML);

        // Sync mobile profile info if available
        if (this.currentUser) {
            this.updateUserProfileUI(this.currentUser);
        }
    }

    /**
     * Confere a sessão em GET /api/auth/me.php.
     *
     * Só 401 quer dizer "não logado". Servidor fora do ar, erro 500 ou rede
     * caída NÃO mandam ninguém para a tela de login: antes mandavam, e o
     * resultado era o pior sintoma possível de depurar — a pessoa entrava
     * com a senha certa, a sessão abria, e o app a devolvia para o login sem
     * dizer por quê. Foi exatamente o que aconteceu quando a coluna
     * `users.ai_credits` faltava no banco e o `me.php` respondia 500.
     *
     * @param {Object} options - { redirectOnFail: boolean }
     * @returns {Promise<Object|null>} usuário autenticado, ou null.
     */
    async checkAuth(options = { redirectOnFail: true }) {
        let naoAutenticado = false;

        try {
            const res = await fetch("api/auth/me.php", {
                credentials: "same-origin"
            });

            if (res.status === 401) {
                naoAutenticado = true;
            } else if (!res.ok) {
                // 5xx: é falha do servidor, e a sessão pode estar ótima.
                console.error("me.php respondeu " + res.status);
                this.toastError("O servidor não respondeu. Recarregue a página em instantes.");
                return null;
            } else {
                const data = await res.json();

                if (data.authenticated && data.user) {
                    this.currentUser = data.user;
                    this.unreadCount = 0;
                    this.updateUserProfileUI(data.user);
                    return data.user;
                }

                naoAutenticado = true;
            }
        } catch (e) {
            // Rede caída ou JSON quebrado: também não é logout.
            console.error("Erro ao verificar sessão do usuário:", e);
            this.toastError("Sem conexão com o servidor.");
            return null;
        }

        if (naoAutenticado && options.redirectOnFail) {
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

        // Fecha o SSE ANTES do fetch de logout: com o php -S do ambiente
        // local (single-thread), a conexao aberta do sino segura o unico
        // processo do servidor por ate ~25s, e o logout ficava preso na
        // fila atras dela. Fechar aqui faz o stream.php notar
        // connection_aborted() na proxima volta do loop (~1s) em vez de
        // esperar o ciclo inteiro.
        if (this.notificationStream) {
            this.notificationStream.close();
            this.notificationStream = null;
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
    /* ==================================================================
       LAYOUT QUE REAGE AO CONTEÚDO

       A coluna da direita foi desenhada para três colunas de altura
       parecida, e nunca teve o que pôr na terceira: numa tela de 950px de
       altura sobravam 680 de preto embaixo do último cartão, e os dois
       cartões que havia estavam ocupados anunciando que não tinham nada
       ("Nenhum assunto em alta ainda").

       Anunciar o vazio é pior que não mostrar: chama atenção justamente
       para o que falta. Agora o cartão sem conteúdo some, e quando TODOS
       somem a coluna inteira sai e o feed ocupa o espaço. Quando dado
       aparecer, tudo volta sozinho — é o mesmo caminho, só que ao
       contrário.
       ================================================================== */

    /**
     * Esconde o `.right-card` que contém `box` e reavalia a coluna.
     * Esconde o CARTÃO, não só o miolo: o título ("Assuntos em alta")
     * sozinho é tão vazio quanto o aviso que ele encabeça.
     */
    esconderCartaoVazio(box) {
        const cartao = box.closest(".right-card") || box;
        cartao.hidden = true;
        this.ajustarColunaDireita();
    }

    /** Revela de novo quando o conteúdo voltou. */
    revelarCartao(box) {
        const cartao = box.closest(".right-card") || box;

        if (cartao.hidden) {
            cartao.hidden = false;
            this.ajustarColunaDireita();
        }
    }

    /**
     * Coluna da direita sem nenhum cartão visível sai da tela, e o feed
     * herda a largura. A marca vai na `.layout`, não na coluna, porque
     * quem precisa reagir é o irmão ao lado — e CSS não tem seletor de
     * "elemento anterior".
     */
    ajustarColunaDireita() {
        const coluna = document.querySelector(".right-col");
        const layout = document.querySelector(".layout");
        if (!coluna || !layout) return;

        const vivos = [...coluna.querySelectorAll(".right-card")].filter(c => !c.hidden);

        coluna.hidden = vivos.length === 0;
        layout.classList.toggle("layout-sem-direita", vivos.length === 0);

        // Coluna com um cartão só não precisa dos 430px reservados para
        // três: encolhe, e a largura devolvida vai para o feed. É o mesmo
        // princípio do sumiço, em grau menor — o espaço acompanha o que
        // existe, em vez de ficar guardado para conteúdo que não veio.
        layout.classList.toggle("layout-direita-magra", vivos.length === 1);
    }

    async renderTrending(containerId = "trendingCard", limit = 5) {
        const box = document.getElementById(containerId);
        if (!box) return;

        try {
            const res  = await fetch(`api/hashtags/trending.php?limit=${limit}`, { credentials: "same-origin" });
            const data = await res.json();

            if (!data.ok || !data.hashtags.length) {
                this.esconderCartaoVazio(box);
                return;
            }

            this.revelarCartao(box);

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

            // Turmas (tipo 'academia') ficam em turmas.html, fora deste card.
            const circulos = (data.circles || []).filter(c => c.tipo !== "academia");

            if (!data.ok || !circulos.length) {
                this.esconderCartaoVazio(box);
                return;
            }

            this.revelarCartao(box);

            box.innerHTML = circulos.slice(0, limit).map(c => `
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

    /* ==================================================================
       O QUE A COLUNA DA DIREITA MOSTRA

       A coluna tinha dois cartões e sobrava tela. Não faltava dado:
       faltava mostrar o dado que o Echo já tem e ninguém vê de fora da
       página onde ele mora. Os três cartões abaixo não criaram endpoint
       nenhum — são `api/ai/feed.php`, `api/friends/suggestions.php` e
       `api/profile/get.php`, que já existiam e já respondiam isto.

       Todos passam por `esconderCartaoVazio()`/`revelarCartao()`: cartão
       sem conteúdo sai, e quando todos saem a coluna inteira sai e o feed
       herda a largura. Encher a tela não pode virar encher de aviso de
       vazio — seria trocar um buraco por outro pior.
       ================================================================== */

    /**

    /**
     * "Seu Echo": o próprio número da pessoa, no lugar onde ela começa o
     * dia. Vem de `api/profile/get.php` sem `user_id`, que já devolvia
     * `stats` prontinho para o próprio perfil — o mesmo número que a
     * página de perfil mostra, sem segunda fonte da verdade.
     */
    async renderMeuResumo(containerId = "resumoCard") {
        const box = document.getElementById(containerId);
        if (!box) return;

        try {
            const res  = await fetch("api/profile/get.php", { credentials: "same-origin" });
            const data = await res.json();

            if (!data.ok || !data.stats) {
                this.esconderCartaoVazio(box);
                return;
            }

            const s = data.stats;

            // Conta zerada: o cartão vira convite, e não quatro zeros. Zero
            // publicação em letra grande é pior do que cartão nenhum.
            if (!s.posts && !s.friends && !s.circles && !s.likes_received) {
                this.revelarCartao(box);
                box.innerHTML = `
                    <p class="text-secondary small mb-2">Sua conta ainda está em branco.</p>
                    <a class="rede-agora-todos" href="#postText">Escrever a primeira publicação
                       <i class="fa-solid fa-arrow-right-long"></i></a>`;
                return;
            }

            this.revelarCartao(box);

            const numeros = [
                ["posts",          s.posts,          "publicação",  "publicações"],
                ["friends",        s.friends,        "amigo",        "amigos"],
                ["likes_received", s.likes_received, "curtida",      "curtidas"],
                ["circles",        s.circles,        "círculo",     "círculos"],
            ];

            box.innerHTML = `<div class="echo-resumo">` + numeros.map(([, n, um, muitos]) => `
                <div class="echo-resumo-item">
                    <strong>${Number(n) || 0}</strong>
                    <small>${Number(n) === 1 ? um : muitos}</small>
                </div>
            `).join("") + `</div>`;
        } catch (e) {
            this.esconderCartaoVazio(box);
        }
    }


    /** Corta texto no limite sem partir palavra no meio. */
    cortar(texto, limite) {
        const t = String(texto || "").trim();
        if (t.length <= limite) return t;

        const corte = t.slice(0, limite);
        const espaco = corte.lastIndexOf(" ");

        return (espaco > limite * 0.6 ? corte.slice(0, espaco) : corte) + "…";
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
                // Entrou no layout que se adapta (ver esconderCartaoVazio):
                // "nenhuma sugestão por enquanto" ocupava espaço só para
                // dizer que não tinha nada para ocupar espaço.
                this.esconderCartaoVazio(box);
                return;
            }

            this.revelarCartao(box);

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
            this.esconderCartaoVazio(box);
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


/* ==========================================================================
   COMPORTAMENTOS DE FORMULÁRIO (20/09/2026)

   Três coisas pequenas que valem para qualquer tela e por isso moram aqui,
   e não na página. Todas por delegação no document: os formulários do
   "Criar seu Echo" e da loja são redesenhados por innerHTML a cada carga,
   e listener pendurado no elemento morre junto com ele.
   ========================================================================== */

/* --- Enter envia ---
   Quem digita e-mail e senha termina com a mão no teclado; tirar a mão
   para procurar o botão é o passo que sobra. O bloco marca qual é o botão
   principal com data-enter="#id", e só campos de UMA linha disparam:
   em textarea, Enter é parágrafo. */
document.addEventListener("keydown", (ev) => {
    if (ev.key !== "Enter" || ev.isComposing) return;
    if (ev.shiftKey || ev.ctrlKey || ev.altKey || ev.metaKey) return;

    const campo = ev.target;
    if (!(campo instanceof HTMLInputElement)) return;
    if (["checkbox", "radio", "file", "button", "submit", "reset"].includes(campo.type)) return;

    const escopo = campo.closest("[data-enter]");
    if (!escopo) return;

    const botao = escopo.querySelector(escopo.dataset.enter);
    if (!botao || botao.disabled) return;

    ev.preventDefault();
    botao.click();
});

/* --- exemplos que não somem ---
   O exemplo estava só no placeholder, que some no primeiro caractere.
   Estes ficam embaixo do campo; clicar escreve no campo indicado por
   data-alvo. Para texto longo o rótulo do chip é o assunto e o que entra
   é a frase inteira, em data-texto. Não apaga o que já está escrito:
   acrescenta ao fim, porque o campo de instruções é feito de várias
   frases dessas. */
document.addEventListener("click", (ev) => {
    const chip = ev.target.closest(".echo-exemplo");
    if (!chip) return;

    const lista = chip.closest(".echo-exemplos");
    const campo = lista && document.getElementById(lista.dataset.alvo);
    if (!campo) return;

    const texto = chip.dataset.texto || chip.textContent.trim();
    const atual = campo.value.trim();

    if (!atual) {
        campo.value = texto;
    } else if (campo.tagName === "TEXTAREA") {
        campo.value = atual + "\n" + texto;
    } else {
        // Campo de uma linha já preenchido: trocar o que a pessoa escreveu
        // seria perder texto dela por um clique. O exemplo continua à vista.
        campo.focus();
        return;
    }

    // Corta o que passou do limite em vez de deixar o campo recusar em
    // silêncio, e avisa o resto (contador, autosize) que o valor mudou.
    const limite = parseInt(campo.getAttribute("maxlength") || "0", 10);
    if (limite > 0 && campo.value.length > limite) campo.value = campo.value.slice(0, limite);

    campo.dispatchEvent(new Event("input", { bubbles: true }));
    campo.focus();

    // setSelectionRange explode em input[type=email|number]; o cursor no
    // fim é conforto, não requisito.
    try { campo.setSelectionRange(campo.value.length, campo.value.length); } catch (e) {}
});

/* --- contador de caracteres ---
   Só em campo de texto longo com limite, e só enquanto ele está em foco:
   contador aceso o tempo todo em seis campos é ruído. Fica âmbar nos
   últimos 10%, que é quando a informação passa a importar. */
document.addEventListener("focusin", (ev) => {
    const campo = ev.target;
    if (!(campo instanceof HTMLTextAreaElement)) return;

    const limite = parseInt(campo.getAttribute("maxlength") || "0", 10);
    if (!limite || !campo.closest(".echo-bloco, .echo-form-inline")) return;

    let marcador = campo.nextElementSibling;
    if (!marcador || !marcador.classList.contains("echo-contador")) {
        marcador = document.createElement("small");
        marcador.className = "echo-contador";
        campo.insertAdjacentElement("afterend", marcador);
    }

    const pintar = () => {
        marcador.textContent = campo.value.length + " / " + limite;
        marcador.classList.toggle("perto", campo.value.length > limite * 0.9);
    };

    pintar();
    campo.addEventListener("input", pintar);
    campo.addEventListener("blur", () => marcador.remove(), { once: true });
});
