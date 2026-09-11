/* ==========================================================================
   ECHO — COMPONENTE DE FEED (js/echo-feed.js)

   O feed aparece em quatro telas (início, explorar, perfil e salvos) e
   até aqui estava copiado em cada uma: três cópias do mesmo post, das
   mesmas ações e dos mesmos comentários. Um botão novo — o de salvar —
   significava três edições e, na prática, três bugs diferentes.

   Esta classe centraliza a lista: busca, paginação por cursor, ações do
   post e a caixa de comentários. A tela só diz de onde vem a lista
   (`params`) e onde ela é desenhada (`container`).
   ========================================================================== */

class EchoFeed {
    /**
     * @param {Object} opts
     * @param {string} opts.container id do elemento que recebe o feed
     * @param {Object} opts.params    filtros de api/posts/list.php
     *                                (`user_id`, `tag`, `saved`, `limit`)
     * @param {string} opts.emptyText texto de lista vazia
     * @param {boolean} opts.showComments habilita a caixa de comentários
     * @param {Function} opts.onChange chamado quando a lista muda de tamanho
     */
    constructor({ container, params = {}, emptyText = "Nada por aqui ainda.",
                  showComments = true, onChange = null } = {}) {
        this.containerId  = container;
        this.params       = { limit: 10, ...params };
        this.emptyText    = emptyText;
        this.showComments = showComments;
        this.onChange     = onChange;

        // Cursor da paginação: id do post mais antigo já na tela.
        this.cursor     = null;
        this.carregando = false;
        this.posts      = [];
    }

    get container() {
        return document.getElementById(this.containerId);
    }

    get me() {
        return EchoUIInstance.currentUser;
    }

    /** Troca os filtros e recarrega do topo (usado pelo filtro de etiqueta). */
    setParams(params) {
        this.params = { limit: 10, ...params };
        return this.load();
    }

    /**
     * Carrega o feed. Sem argumento começa do topo; com `append`, anexa a
     * próxima página ao que já está na tela.
     */
    async load({ append = false } = {}) {
        const feed = this.container;
        if (!feed) return;

        if (this.carregando) return;
        this.carregando = true;

        if (!append) {
            feed.innerHTML = EchoUIInstance.getFeedSkeletonHTML(3);
            this.cursor = null;
            this.posts  = [];
        }

        try {
            const query = new URLSearchParams(this.params);

            if (append && this.cursor) {
                query.set("before_id", this.cursor);
            }

            const res  = await fetch("api/posts/list.php?" + query.toString(), {
                credentials: "same-origin"
            });
            const data = await res.json();

            // O botão da página anterior sai antes de qualquer coisa nova entrar.
            document.getElementById(this.botaoMaisId)?.remove();

            if (!append) feed.innerHTML = "";

            if (!data.ok || !Array.isArray(data.posts)) {
                feed.innerHTML = '<p class="text-center text-secondary p-4">'
                               + EchoUIInstance.escapeHTML(data.error || this.emptyText) + '</p>';
                return;
            }

            if (data.posts.length === 0 && !append) {
                feed.innerHTML = '<p class="text-center text-secondary p-4">'
                               + EchoUIInstance.escapeHTML(this.emptyText) + '</p>';
                return;
            }

            this.cursor = data.next_before_id;
            this.posts  = append ? this.posts.concat(data.posts) : data.posts;

            feed.insertAdjacentHTML("beforeend", data.posts.map((p, i) => this.postHTML(p, i)).join(""));

            this.destacarPostDaURL();

            if (data.has_more) this.montarBotaoMais(feed);

        } catch (e) {
            if (append) {
                EchoUIInstance.toastError("Erro ao carregar mais publicações.");
            } else {
                feed.innerHTML = '<p class="text-center text-danger p-4">'
                               + '<i class="fa-solid fa-circle-exclamation me-2"></i>'
                               + 'Erro ao carregar as publicações.</p>';
            }
        } finally {
            this.carregando = false;
        }
    }

    get botaoMaisId() {
        return "btnCarregarMais-" + this.containerId;
    }

    montarBotaoMais(feed) {
        const btn = document.createElement("button");
        btn.className   = "echo-load-more";
        btn.id          = this.botaoMaisId;
        btn.type        = "button";
        btn.textContent = "Carregar mais publicações";
        btn.onclick     = () => {
            btn.disabled = true;
            btn.textContent = "Carregando...";
            this.load({ append: true });
        };
        feed.appendChild(btn);
    }

    /**
     * Veio de uma notificação (`?post=ID`): rola até o post e pisca nele.
     * Roda só uma vez — o parâmetro sai da URL depois, para F5 não repetir.
     */
    destacarPostDaURL() {
        const url  = new URL(window.location.href);
        const alvo = Number(url.searchParams.get("post"));
        if (!alvo) return;

        const el = document.getElementById("post-" + alvo);
        if (!el) return;

        el.scrollIntoView({ behavior: "smooth", block: "center" });
        el.classList.add("destacado");

        url.searchParams.delete("post");
        history.replaceState(null, "", url.pathname + (url.search || ""));
    }

    /* ==================================================================
       DESENHO
       ================================================================== */

    postHTML(p, ordem = 0) {
        const handle    = p.email ? p.email.split("@")[0] : "usuario";
        const souEuDono = this.me && Number(p.user_id) === Number(this.me.id);
        const curtido   = Number(p.liked_by_me ?? 0) > 0;
        const salvo     = Number(p.saved_by_me ?? 0) > 0;
        const efemero   = !!p.is_efemero;
        const feed      = this.ref;

        return `
            <div class="post entrando${efemero ? " post-efemero" : ""}" id="post-${p.id}" data-post-id="${p.id}"
                 style="--ordem:${Math.min(ordem, 8)};${efemero ? `--decadencia:${Math.max(0, Math.min(100, Number(p.decadencia ?? 0))) / 100};` : ""}">
                ${EchoUIInstance.avatarHTML(p, "md", true)}
                <div class="post-body">
                    <div class="post-header">
                        ${EchoUIInstance.authorLinkHTML(p, "post-name")}
                        <span class="post-handle">@${EchoUIInstance.escapeHTML(handle)}</span>
                        ${souEuDono ? '<span class="badge bg-secondary-subtle text-light">você</span>' : ""}
                        <span class="post-time" title="${EchoUIInstance.escapeHTML(p.created_at)}"> · ${EchoUIInstance.formatTime(p.created_at)}</span>
                        ${p.edited_at ? `<span class="post-edited" title="Editado em ${EchoUIInstance.escapeHTML(p.edited_at)}">· editado</span>` : ""}
                        ${efemero ? `<span class="post-efemero-badge" title="Um comentário novo reinicia o prazo">
                                         <i class="fa-solid fa-hourglass-half"></i> ${this.formatarMorreEm(p.morre_em_seg)}
                                     </span>` : ""}
                    </div>

                    <div class="post-content" id="post-content-${p.id}">${EchoUIInstance.richTextHTML(p.content ?? "")}</div>
                    ${p.image ? `<img class="post-image" src="uploads/${encodeURIComponent(p.image)}" alt="">` : ""}

                    <div class="post-actions">
                        ${this.showComments
                            ? `<button class="icon-btn" type="button" title="Comentários"
                                       onclick="${feed}.toggleComments(${p.id})">
                                   <i class="fa-regular fa-comment"></i><span id="comment-count-${p.id}">${p.comment_count || 0}</span>
                               </button>`
                            : `<a class="icon-btn" title="Ver no feed" href="inicio.html?post=${p.id}">
                                   <i class="fa-regular fa-comment"></i><span>${p.comment_count || 0}</span>
                               </a>`}

                        ${p.rumor_id ? `
                        <button class="icon-btn rumor-btn" type="button" title="Ver como o boato mudou a cada repasse"
                                onclick="${feed}.toggleRumor(${p.id})">
                            <i class="fa-solid fa-bullhorn"></i><span>Boato</span>
                        </button>` : ""}

                        <button class="icon-btn" type="button" title="Compartilhar"
                                onclick="${feed}.share(${p.id})">
                            <i class="fa-solid fa-retweet"></i><span id="share-count-${p.id}">${Number(p.share_count ?? 0)}</span>
                        </button>

                        <button class="icon-btn ${curtido ? "liked" : ""}" type="button" title="Curtir"
                                id="like-btn-${p.id}" onclick="${feed}.like(${p.id})">
                            <i class="${curtido ? "fa-solid" : "fa-regular"} fa-heart"></i>
                            <span id="like-count-${p.id}">${Number(p.like_count ?? 0)}</span>
                        </button>

                        <button class="icon-btn ${salvo ? "saved" : ""}" type="button"
                                id="save-btn-${p.id}" title="${salvo ? "Remover dos salvos" : "Salvar"}"
                                onclick="${feed}.save(${p.id})">
                            <i class="${salvo ? "fa-solid" : "fa-regular"} fa-bookmark"></i>
                        </button>

                        ${souEuDono ? `
                        <button class="icon-btn" type="button" title="Editar"
                                onclick="EchoUIInstance.editPost(${p.id}, () => ${feed}.load())">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="icon-btn" type="button" title="Apagar"
                                onclick="${feed}.remove(${p.id})">
                            <i class="fa-solid fa-trash"></i>
                        </button>` : ""}
                    </div>

                    ${this.showComments ? `
                    <div class="mt-2" id="comments-box-${p.id}" style="display:none;">
                        <div id="comment-list-${p.id}" class="mb-2 border-start border-secondary ps-2"></div>
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control form-control-sm"
                                   id="comment-input-${p.id}" maxlength="2000"
                                   placeholder="Comente... use @ para citar alguém">
                            <button class="btn btn-sm btn-primary rounded-pill px-3" type="button"
                                    onclick="${feed}.sendComment(${p.id})">Comentar</button>
                        </div>
                    </div>` : ""}

                    ${p.rumor_id ? `
                    <div class="mt-2" id="rumor-box-${p.id}" style="display:none;"></div>` : ""}
                </div>
            </div>
        `;
    }

    /** Texto do selo do post efêmero, a partir de `morre_em_seg`. */
    formatarMorreEm(segundos) {
        segundos = Number(segundos ?? 0);

        if (segundos <= 0) return "morrendo";

        const horas = Math.floor(segundos / 3600);
        if (horas >= 1) return `morre em ${horas}h`;

        const minutos = Math.max(1, Math.floor(segundos / 60));
        return `morre em ${minutos}min`;
    }

    /**
     * Caminho global até esta instância, para os `onclick` do HTML.
     * Guardar por id de container evita uma variável global por tela.
     */
    get ref() {
        return "EchoFeed.byContainer('" + this.containerId + "')";
    }

    /* ==================================================================
       AÇÕES DO POST

       A resposta do servidor atualiza o botão no lugar. Antes, cada
       curtida recarregava o feed inteiro e jogava o scroll para o topo.
       ================================================================== */

    /**
     * Faz o botão pular (e soltar o anel) por uma animação só.
     *
     * A classe sai no fim da animação: deixá-la no elemento faria o
     * segundo clique não animar nada, porque para o navegador a
     * animação já teria acontecido.
     */
    animarBotao(botao) {
        if (!botao) return;

        botao.classList.remove("animando");
        // Força o navegador a recalcular antes de recolocar a classe;
        // sem isso, remover e pôr no mesmo quadro não reinicia nada.
        void botao.offsetWidth;
        botao.classList.add("animando");

        botao.addEventListener("animationend", () => botao.classList.remove("animando"), { once: true });
    }

    /** Tira o post da lista com a animação de saída, não de um quadro para o outro. */
    /**
     * Tira a publicação da tela.
     *
     * `paraLixeira` só é verdadeiro quando o post foi realmente apagado: aí
     * o mascote busca ele pela borda e leva até a lixeira. Em "remover dos
     * salvos" o post continua existindo, então sai pela animação de sempre.
     * Se o mascote não estiver em cena (tela estreita, desligado,
     * prefers-reduced-motion), `levarAoLixo` devolve false e cai na animação
     * também — o sumiço nunca depende dele.
     */
    removerDaTela(postId, paraLixeira = false) {
        const el = document.getElementById("post-" + postId);
        if (!el) return;

        if (paraLixeira && window.EchoBit && EchoBit.levarAoLixo(el)) return;

        el.classList.add("saindo");
        el.addEventListener("animationend", () => el.remove(), { once: true });
    }

    async postJSON(url, body) {
        const res = await fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify(body)
        });
        return res.json();
    }

    async like(postId) {
        try {
            const data = await this.postJSON("api/posts/like.php", { post_id: postId });
            if (EchoUIInstance.showApiError(data)) return;

            const btn   = document.getElementById("like-btn-" + postId);
            const conta = document.getElementById("like-count-" + postId);

            if (!btn || !conta) return this.load();

            btn.classList.toggle("liked", data.liked);
            btn.querySelector("i").className = (data.liked ? "fa-solid" : "fa-regular") + " fa-heart";
            conta.textContent = Math.max(0, Number(conta.textContent) + (data.liked ? 1 : -1));
            this.animarBotao(btn);
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão ao curtir.");
        }
    }

    async save(postId) {
        try {
            const data = await this.postJSON("api/posts/save.php", { post_id: postId });
            if (EchoUIInstance.showApiError(data)) return;

            const btn = document.getElementById("save-btn-" + postId);

            if (btn) {
                btn.classList.toggle("saved", data.saved);
                btn.title = data.saved ? "Remover dos salvos" : "Salvar";
                btn.querySelector("i").className = (data.saved ? "fa-solid" : "fa-regular") + " fa-bookmark";
                this.animarBotao(btn);
            }

            EchoUIInstance.toast(
                data.saved ? "Salvo. Veja em Salvos." : "Removido dos salvos.",
                "success", 2500
            );

            // Na própria tela de salvos, tirar dos salvos tira da lista.
            if (this.params.saved && !data.saved) {
                this.removerDaTela(postId);
                if (typeof this.onChange === "function") this.onChange();
            }
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão ao salvar.");
        }
    }

    async share(postId) {
        try {
            const data = await this.postJSON("api/posts/share.php", { post_id: postId });
            if (EchoUIInstance.showApiError(data)) return;

            const conta = document.getElementById("share-count-" + postId);

            if (conta) {
                conta.textContent = Number(conta.textContent) + 1;
                this.animarBotao(conta.closest(".icon-btn"));
            }

            EchoUIInstance.toast("Publicação compartilhada.", "success", 2500);
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão ao compartilhar.");
        }
    }

    async remove(postId) {
        if (!await EchoUIInstance.confirm({
            title: "Apagar publicação",
            message: "Esta publicação, seus comentários e suas curtidas serão apagados. Não dá para desfazer.",
            confirmText: "Apagar",
            danger: true
        })) return;

        try {
            const data = await this.postJSON("api/posts/delete.php", { post_id: postId });
            if (EchoUIInstance.showApiError(data)) return;

            this.removerDaTela(postId, true);
            EchoUIInstance.toastSuccess("Publicação apagada.");

            if (typeof this.onChange === "function") this.onChange();
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão ao apagar.");
        }
    }

    /* ==================================================================
       COMENTÁRIOS
       ================================================================== */

    async toggleComments(postId) {
        const box = document.getElementById("comments-box-" + postId);
        if (!box) return;

        const escondida = box.style.display === "none" || box.style.display === "";

        if (!escondida) {
            box.style.display = "none";
            return;
        }

        box.style.display = "block";
        box.classList.add("echo-comentarios-abrindo");
        box.addEventListener("animationend",
            () => box.classList.remove("echo-comentarios-abrindo"), { once: true });

        await this.loadComments(postId);

        const input = document.getElementById("comment-input-" + postId);

        if (input) {
            // Enter comenta; Shift+Enter fica livre para quebra de linha.
            input.onkeydown = (e) => {
                if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    this.sendComment(postId);
                }
            };
            EchoUIInstance.attachMentionAutocomplete(input);
        }
    }

    async loadComments(postId) {
        const lista = document.getElementById("comment-list-" + postId);
        if (!lista) return;

        lista.innerHTML = '<small class="text-secondary">'
                        + '<span class="spinner-echo me-1" style="width:12px;height:12px;border-width:2px;"></span>'
                        + 'Carregando comentários...</small>';

        try {
            const res  = await fetch("api/comments/list.php?post_id=" + postId, { credentials: "same-origin" });
            const data = await res.json();

            if (data.error) {
                lista.innerHTML = '<small class="text-danger">' + EchoUIInstance.escapeHTML(data.error) + '</small>';
                return;
            }

            const comentarios = data.comments || [];

            if (!comentarios.length) {
                lista.innerHTML = '<small class="text-secondary">Seja o primeiro a comentar.</small>';
                return;
            }

            lista.innerHTML = comentarios.map(c => this.commentHTML(c, postId)).join("");
        } catch (e) {
            lista.innerHTML = '<small class="text-danger">Erro ao carregar comentários.</small>';
        }
    }

    /**
     * `can_delete` e `can_edit` vêm resolvidos pelo servidor — o autor
     * edita e apaga; o dono do post só apaga. O front desenha o que o
     * back autorizou, não refaz a conta.
     */
    commentHTML(c, postId) {
        const handle = c.email ? c.email.split("@")[0] : (c.name || "usuario");
        const feed   = this.ref;

        return `
            <div class="d-flex gap-2 mb-2" id="comment-${c.id}">
                ${EchoUIInstance.avatarHTML(c, "sm", true)}
                <div class="flex-grow-1 min-width-0">
                    <div class="d-flex align-items-center gap-1">
                        ${EchoUIInstance.authorLinkHTML(c, "small fw-bold")}
                        <span class="text-secondary small">@${EchoUIInstance.escapeHTML(handle)}</span>
                        <span class="text-secondary small">· ${EchoUIInstance.formatTime(c.created_at)}</span>
                        ${c.edited_at ? `<span class="text-secondary small" title="Editado em ${EchoUIInstance.escapeHTML(c.edited_at)}">· editado</span>` : ""}
                        <span class="ms-auto d-flex gap-2">
                            ${c.can_edit ? `
                            <button class="btn btn-link btn-sm p-0 text-secondary" type="button" title="Editar comentário"
                                    onclick="${feed}.editComment(${c.id}, ${postId})">
                                <i class="fa-solid fa-pen"></i>
                            </button>` : ""}
                            ${c.can_delete ? `
                            <button class="btn btn-link btn-sm p-0 text-secondary" type="button" title="Apagar comentário"
                                    onclick="${feed}.deleteComment(${c.id}, ${postId})">
                                <i class="fa-solid fa-xmark"></i>
                            </button>` : ""}
                        </span>
                    </div>
                    <div class="small text-light" id="comment-body-${c.id}">${EchoUIInstance.richTextHTML(c.body)}</div>
                </div>
            </div>
        `;
    }

    async sendComment(postId) {
        const input = document.getElementById("comment-input-" + postId);
        if (!input) return;

        const body = input.value.trim();

        if (!body) {
            EchoUIInstance.toastError("Digite um comentário.");
            return;
        }

        try {
            const data = await this.postJSON("api/comments/create.php", { post_id: postId, body });
            if (EchoUIInstance.showApiError(data)) return;

            input.value = "";
            await this.loadComments(postId);

            const conta = document.getElementById("comment-count-" + postId);
            if (conta) conta.textContent = Number(conta.textContent) + 1;

            // Se este post é origem de boato, o comentário que acabou de
            // sair já virou um repasse no servidor — atualiza a timeline
            // se ela estiver aberta na tela.
            const rumorBox = document.getElementById("rumor-box-" + postId);
            if (rumorBox && rumorBox.style.display === "block") {
                await this.loadRumor(postId);
            }
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão ao comentar.");
        }
    }

    /** Editor no lugar do texto do comentário, no mesmo espírito de editPost(). */
    editComment(commentId, postId) {
        const box = document.getElementById("comment-body-" + commentId);
        if (!box || box.dataset.editing === "1") return;

        const original = box.textContent.trim();
        box.dataset.editing = "1";

        box.innerHTML = `
            <textarea class="form-control form-control-sm mb-2" rows="2" maxlength="2000"
                      id="comment-edit-${commentId}">${EchoUIInstance.escapeHTML(original)}</textarea>
            <div class="d-flex gap-2 justify-content-end">
                <button class="btn btn-sm btn-outline-light rounded-pill px-3" type="button"
                        id="comment-cancel-${commentId}">Cancelar</button>
                <button class="btn btn-sm btn-primary rounded-pill px-3" type="button"
                        id="comment-save-${commentId}">Salvar</button>
            </div>
        `;

        const input  = document.getElementById("comment-edit-" + commentId);
        const salvar = document.getElementById("comment-save-" + commentId);

        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);

        const cancelar = () => {
            box.dataset.editing = "0";
            box.innerHTML = EchoUIInstance.richTextHTML(original);
        };

        document.getElementById("comment-cancel-" + commentId).onclick = cancelar;

        input.onkeydown = (e) => {
            if (e.key === "Escape") cancelar();
            if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) salvar.click();
        };

        salvar.onclick = async () => {
            const novo = input.value.trim();

            if (novo === original) {
                cancelar();
                return;
            }

            salvar.disabled = true;

            try {
                const data = await this.postJSON("api/comments/edit.php", {
                    comment_id: commentId,
                    body: novo
                });

                if (EchoUIInstance.showApiError(data)) {
                    salvar.disabled = false;
                    return;
                }

                box.dataset.editing = "0";
                await this.loadComments(postId);
                EchoUIInstance.toastSuccess("Comentário atualizado.");
            } catch (e) {
                EchoUIInstance.toastError("Erro de conexão ao salvar o comentário.");
                salvar.disabled = false;
            }
        };
    }

    async deleteComment(commentId, postId) {
        if (!await EchoUIInstance.confirm({
            title: "Apagar comentário",
            message: "O comentário some para todo mundo. Não dá para desfazer.",
            confirmText: "Apagar",
            danger: true
        })) return;

        try {
            const data = await this.postJSON("api/comments/delete.php", { comment_id: commentId });
            if (EchoUIInstance.showApiError(data)) return;

            // Tira só o comentário apagado. Recarregar a lista inteira, como
            // era antes, redesenhava tudo: piscava e jogava fora a posição de
            // leitura de quem estava no meio de uma conversa longa.
            const alvo = document.getElementById("comment-" + commentId);
            if (alvo) alvo.remove(); else await this.loadComments(postId);

            const conta = document.getElementById("comment-count-" + postId);
            if (conta) conta.textContent = Math.max(0, Number(conta.textContent) - 1);

            EchoUIInstance.toastSuccess("Comentário apagado.");
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão ao apagar o comentário.");
        }
    }

    /* ==================================================================
       BOATO (telefone sem fio)
       ================================================================== */

    async toggleRumor(postId) {
        const box = document.getElementById("rumor-box-" + postId);
        if (!box) return;

        const escondida = box.style.display === "none" || box.style.display === "";

        if (!escondida) {
            box.style.display = "none";
            return;
        }

        box.style.display = "block";
        await this.loadRumor(postId);
    }

    async loadRumor(postId) {
        const box = document.getElementById("rumor-box-" + postId);
        if (!box) return;

        box.innerHTML = '<small class="text-secondary">'
                       + '<span class="spinner-echo me-1" style="width:12px;height:12px;border-width:2px;"></span>'
                       + 'Carregando o boato...</small>';

        try {
            const res  = await fetch("api/rumores/get.php?post_id=" + postId, { credentials: "same-origin" });
            const data = await res.json();

            if (data.error) {
                box.innerHTML = '<small class="text-danger">' + EchoUIInstance.escapeHTML(data.error) + '</small>';
                return;
            }

            box.innerHTML = this.rumorTimelineHTML(data.rumor);
        } catch (e) {
            box.innerHTML = '<small class="text-danger">Erro ao carregar o boato.</small>';
        }
    }

    /**
     * Linha do tempo do boato: o post original, e cada repasse com o
     * diff em relação ao texto anterior — é o que mostra a informação
     * mudando aos poucos, sem precisar guardar imagem nenhuma de "antes".
     */
    rumorTimelineHTML(rumor) {
        const itens = [{
            rotulo: "original",
            nome:   rumor.autor_original,
            texto:  rumor.texto_original,
            quando: rumor.criado_em
        }].concat(rumor.repasses.map((r, i) => ({
            rotulo: "repasse " + (i + 1),
            nome:   r.autor_nome,
            texto:  r.texto_distorcido,
            quando: r.criado_em
        })));

        let anterior = null;

        const linhas = itens.map(item => {
            const corpo = anterior === null
                ? EchoUIInstance.escapeHTML(item.texto)
                : this.diffPalavras(anterior, item.texto);
            anterior = item.texto;

            return `
                <div class="rumor-item">
                    <div class="rumor-item-header">
                        <span class="rumor-item-rotulo">${EchoUIInstance.escapeHTML(item.rotulo)}</span>
                        <span class="text-secondary small"> · ${EchoUIInstance.escapeHTML(item.nome)} · ${EchoUIInstance.formatTime(item.quando)}</span>
                    </div>
                    <div class="rumor-item-texto">${corpo}</div>
                </div>
            `;
        }).join("");

        return `
            <div class="rumor-timeline border-start border-secondary ps-2">
                <div class="rumor-timeline-aviso text-secondary small mb-1">
                    <i class="fa-solid fa-circle-info"></i> cada repasse distorce o anterior — o que ficou
                    <mark class="rumor-diff">destacado</mark> é o que mudou de uma versão pra outra.
                </div>
                ${linhas}
            </div>
        `;
    }

    /**
     * Diff simples por palavra: devolve o HTML de `atual` com as palavras
     * que não vieram de `anterior` destacadas. LCS clássico por token —
     * os textos são curtos (post/repasse), então o custo não importa.
     */
    diffPalavras(anterior, atual) {
        const tokensAtu = String(atual ?? "").split(/(\s+)/);
        const a = String(anterior ?? "").split(/(\s+)/).filter(t => t.trim() !== "");
        const b = tokensAtu.filter(t => t.trim() !== "");

        const dp = Array.from({ length: a.length + 1 }, () => new Array(b.length + 1).fill(0));

        for (let i = a.length - 1; i >= 0; i--) {
            for (let j = b.length - 1; j >= 0; j--) {
                dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
            }
        }

        // Marca em `comum` quais palavras de `atual` (índice em `b`) já
        // existiam em `anterior` na mesma sequência — o resto é o que o
        // repasse mudou.
        const comum = new Array(b.length).fill(false);
        let i = 0, j = 0;

        while (i < a.length && j < b.length) {
            if (a[i] === b[j]) {
                comum[j] = true;
                i++; j++;
            } else if (dp[i + 1][j] >= dp[i][j + 1]) {
                i++;
            } else {
                j++;
            }
        }

        let idxPalavra = -1;

        return tokensAtu.map(token => {
            if (token.trim() === "") return EchoUIInstance.escapeHTML(token);
            idxPalavra++;
            const texto = EchoUIInstance.escapeHTML(token);
            return comum[idxPalavra] ? texto : `<mark class="rumor-diff">${texto}</mark>`;
        }).join("");
    }
}

/* Feeds vivos na página, por id de container. Os botões desenhados em
   HTML precisam de um caminho global até a instância. */
EchoFeed.instancias = {};

EchoFeed.byContainer = function (containerId) {
    return EchoFeed.instancias[containerId];
};

EchoFeed.create = function (opts) {
    const feed = new EchoFeed(opts);
    EchoFeed.instancias[opts.container] = feed;
    return feed;
};
