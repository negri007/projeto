/* ==========================================================================
   FEED DE COMÉRCIO (js/loja-feed.js)

   POR QUE NÃO É O `EchoFeed`. A classe do feed humano tem cinco endpoints
   de `api/posts/` escritos no corpo dela (list, like, save, share, delete)
   e um `postHTML` montado em torno de um AUTOR PESSOA: avatar, editar,
   apagar, salvar, compartilhar.

   Post de loja é outro objeto: o dono é uma loja, tem preço, tipo em
   badge, produto ligado, "Falar com a loja" e report — e não tem salvar,
   compartilhar nem editar. Reusar exigiria parametrizar os cinco
   endpoints e trocar o `postHTML` inteiro, que é reescrever a classe por
   dentro e arriscar o feed humano, que é a tela mais usada do app.

   O que se reusa é o DESENHO: mesma paginação por cursor, mesmos nomes de
   método, mesma forma de montar a lista. Quem já leu um entende o outro.
   ========================================================================== */

class LojaFeed {
    /**
     * @param {Object} opts
     * @param {string} opts.container   id do elemento que recebe o feed
     * @param {Object} opts.params      `categoria`, `loja_id`, `limit`
     * @param {string} opts.emptyText   texto de lista vazia
     * @param {boolean} opts.compacto   esconde o botão de falar com a loja
     */
    constructor({ container, params = {}, emptyText = "Nada por aqui ainda.", compacto = false } = {}) {
        this.containerId = container;
        this.params      = { limit: 10, ...params };
        this.emptyText   = emptyText;
        this.compacto    = compacto;

        this.cursor     = null;
        this.carregando = false;
        this.posts      = [];
    }

    get container() {
        return document.getElementById(this.containerId);
    }

    /** Troca o filtro e recomeça a lista do zero. */
    filtrar(params) {
        this.params = { ...this.params, ...params };
        this.cursor = null;
        this.posts  = [];
        return this.load();
    }

    async load({ append = false } = {}) {
        if (this.carregando) return;

        this.carregando = true;
        const box = this.container;

        if (!append) {
            box.innerHTML = `<p class="text-center text-secondary p-4">Carregando...</p>`;
        }

        try {
            const query = new URLSearchParams();

            Object.entries(this.params).forEach(([k, v]) => {
                if (v !== null && v !== undefined && v !== "") query.set(k, v);
            });

            if (append && this.cursor) query.set("before_id", this.cursor);

            const res  = await fetch("api/lojas/feed.php?" + query.toString(),
                                     { credentials: "same-origin" });
            const data = await res.json();

            if (!data.ok) {
                box.innerHTML = `<p class="text-center text-danger p-4">Erro ao carregar o feed.</p>`;
                return;
            }

            if (!append) {
                this.posts = [];
                box.innerHTML = "";
            }

            if (!data.posts.length && !this.posts.length) {
                box.innerHTML = `<p class="text-center text-secondary p-4">${this.emptyText}</p>`;
                return;
            }

            this.posts.push(...data.posts);
            box.querySelector(".loja-mais")?.remove();
            box.insertAdjacentHTML("beforeend", data.posts.map(p => this.postHTML(p)).join(""));

            if (data.has_more) {
                box.insertAdjacentHTML("beforeend",
                    `<button class="btn btn-outline-secondary rounded-pill w-100 my-3 loja-mais" type="button">
                        Carregar mais
                    </button>`);

                box.querySelector(".loja-mais").addEventListener("click", () => {
                    this.cursor = data.next_before_id;
                    this.load({ append: true });
                });
            }

            this.ligarAcoes();
        } catch (e) {
            box.innerHTML = `<p class="text-center text-danger p-4">Erro de conexão.</p>`;
        } finally {
            this.carregando = false;
        }
    }

    postHTML(p) {
        const l = p.loja;

        const logo = l.logo
            ? `<span class="loja-logo" style="background-image:url('uploads/${encodeURIComponent(l.logo)}')"></span>`
            : `<span class="loja-logo loja-logo-vazia"><i class="fa-solid fa-store"></i></span>`;

        return `
        <article class="loja-post" data-id="${p.id}" data-loja="${l.id}">
            <header class="loja-post-topo">
                <a class="loja-post-dono" href="loja_perfil.html?loja_id=${l.id}">
                    ${logo}
                    <span class="loja-post-nome">
                        <strong>${EchoUIInstance.escapeHTML(l.nome)}</strong>
                        <small>${EchoUIInstance.escapeHTML(l.categoria || "")} · ${EchoUIInstance.formatTime(p.created_at)}</small>
                    </span>
                </a>
                <span class="echo-badge echo-badge-${p.tipo}">${LojaFeed.rotulo(p.tipo)}</span>
            </header>

            ${p.imagem ? LojaFeed.midiaHTML(p.imagem) : ""}

            <p class="loja-post-texto">${EchoUIInstance.escapeHTML(p.conteudo)}</p>

            ${p.preco !== null ? `<div class="loja-post-preco">${LojaFeed.moeda(p.preco)}</div>` : ""}

            <div class="loja-post-acoes">
                <button type="button" data-acao="curtir" class="${p.eu_curti ? "curtido" : ""}">
                    <i class="${p.eu_curti ? "fa-solid" : "fa-regular"} fa-heart"></i>
                    <span class="loja-num">${p.curtidas || ""}</span>
                </button>
                <button type="button" data-acao="comentar">
                    <i class="fa-regular fa-comment"></i>
                    <span class="loja-num">${p.comentarios || ""}</span>
                </button>
                <button type="button" data-acao="compartilhar" title="Compartilhar">
                    <i class="fa-solid fa-arrow-up-from-bracket"></i>
                </button>
                <button type="button" data-acao="reportar" title="Reportar" class="loja-reportar">
                    <i class="fa-regular fa-flag"></i>
                </button>
            </div>

            ${this.compacto ? "" : `
            <a class="btn btn-primary rounded-pill w-100 loja-falar" href="loja_chat.html?loja_id=${l.id}">
                <i class="fa-solid fa-comments me-1"></i>Falar com a loja
            </a>`}

            <div class="loja-post-comentarios" hidden></div>
        </article>`;
    }

    static rotulo(tipo) {
        return { produto: "Produto", promocao: "Promoção", novidade: "Novidade", info: "Info" }[tipo] || tipo;
    }

    static moeda(v) {
        return "R$ " + Number(v).toFixed(2).replace(".", ",");
    }

    /** `uploads/videos/lojas/12/x.mp4` tem barra dentro do caminho —
        encodeURIComponent sozinho escaparia ela e quebraria o caminho.
        Codifica por segmento. */
    static videoSrc(arquivo) {
        return "uploads/" + arquivo.split("/").map(encodeURIComponent).join("/");
    }

    /** `imagem` com prefixo "video:" é vídeo gerado por IA, não foto —
        mesmo card, troca só a mídia e acrescenta o selo. */
    static midiaHTML(imagem) {
        if (imagem.startsWith("video:")) {
            const arquivo = imagem.slice("video:".length);

            return `<div class="loja-post-foto">
                <video src="${LojaFeed.videoSrc(arquivo)}" autoplay muted loop playsinline loading="lazy"></video>
                <span class="loja-post-video-badge"><i class="fa-solid fa-clapperboard"></i> Vídeo</span>
            </div>`;
        }

        return `<div class="loja-post-foto"><img src="uploads/${encodeURIComponent(imagem)}" alt="" loading="lazy"></div>`;
    }

    ligarAcoes() {
        this.container.querySelectorAll(".loja-post").forEach(el => {
            if (el.dataset.ligado) return;
            el.dataset.ligado = "1";

            const id     = parseInt(el.dataset.id, 10);
            const lojaId = parseInt(el.dataset.loja, 10);

            el.querySelector('[data-acao="curtir"]')
              ?.addEventListener("click", () => this.curtir(id, el));
            el.querySelector('[data-acao="comentar"]')
              ?.addEventListener("click", () => this.alternarComentarios(id, el));
            el.querySelector('[data-acao="compartilhar"]')
              ?.addEventListener("click", () => this.compartilhar(id, el));
            el.querySelector('[data-acao="reportar"]')
              ?.addEventListener("click", () => abrirReport({ loja_post_id: id, loja_id: lojaId }));
        });
    }

    async curtir(id, el) {
        try {
            const res = await fetch("api/lojas/post_like.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ loja_post_id: id }),
            });
            const d = await res.json();

            if (d.error) { EchoUIInstance.toastError(d.error); return; }

            const btn = el.querySelector('[data-acao="curtir"]');
            btn.classList.toggle("curtido", d.curtiu);
            btn.querySelector("i").className = (d.curtiu ? "fa-solid" : "fa-regular") + " fa-heart";
            btn.querySelector(".loja-num").textContent = d.curtidas || "";
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão.");
        }
    }

    /* Compartilhar usa a bandeja nativa quando existe (celular), e cai na
       área de transferência quando não. Não há integração com rede
       nenhuma: `navigator.share` já entrega WhatsApp, Instagram e o que
       a pessoa tiver instalado, sem chave nem aprovação de ninguém. */
    async compartilhar(id, el) {
        const texto = el.querySelector(".loja-post-texto")?.textContent || "";
        const nome  = el.querySelector(".loja-post-nome strong")?.textContent || "uma loja";
        const url   = location.origin + "/loja_perfil.html?loja_id=" + el.dataset.loja;
        const msg   = `${nome}: ${texto}`;

        try {
            if (navigator.share) {
                await navigator.share({ title: nome, text: msg, url });
                return;
            }

            await navigator.clipboard.writeText(`${msg}\n${url}`);
            EchoUIInstance.toastSuccess("Copiado para a área de transferência.");
        } catch (e) {
            /* A pessoa cancelou a bandeja: não é erro. */
        }
    }

    async alternarComentarios(id, el) {
        const box = el.querySelector(".loja-post-comentarios");

        if (!box.hidden) { box.hidden = true; return; }

        box.hidden = false;
        box.innerHTML = `<p class="text-secondary small m-0">Carregando...</p>`;

        try {
            const res = await fetch("api/lojas/post_comments.php?loja_post_id=" + id,
                                    { credentials: "same-origin" });
            const d = await res.json();

            const lista = (d.comentarios || []).map(c => `
                <div class="loja-comentario">
                    ${EchoUIInstance.avatarHTML(c, "sm")}
                    <div>
                        <strong>${EchoUIInstance.escapeHTML(c.name)}</strong>
                        <small>${EchoUIInstance.formatTime(c.created_at)}</small>
                        <p>${EchoUIInstance.escapeHTML(c.conteudo)}</p>
                    </div>
                </div>`).join("");

            box.innerHTML = `
                ${lista || `<p class="text-secondary small">Nenhum comentário ainda.</p>`}
                <div class="loja-comentar">
                    <input type="text" class="form-control form-control-sm" maxlength="1000"
                           placeholder="Escreva um comentário...">
                    <button class="btn btn-sm btn-primary rounded-pill px-3" type="button">Enviar</button>
                </div>`;

            const campo = box.querySelector("input");
            const envia = async () => {
                const txt = campo.value.trim();
                if (!txt) return;

                try {
                    const r = await fetch("api/lojas/post_comment.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        credentials: "same-origin",
                        body: JSON.stringify({ loja_post_id: id, conteudo: txt }),
                    });
                    const dd = await r.json();

                    if (dd.error) { EchoUIInstance.toastError(dd.error); return; }

                    campo.value = "";
                    box.hidden = true;
                    this.alternarComentarios(id, el);

                    const n = el.querySelector('[data-acao="comentar"] .loja-num');
                    n.textContent = (parseInt(n.textContent || "0", 10) + 1);
                } catch (e) {
                    EchoUIInstance.toastError("Erro de conexão.");
                }
            };

            box.querySelector("button").addEventListener("click", envia);
            campo.addEventListener("keydown", e => { if (e.key === "Enter") envia(); });
        } catch (e) {
            box.innerHTML = `<p class="text-secondary small m-0">Erro ao carregar.</p>`;
        }
    }
}

/* ======================================================================
   MODAL DE REPORT

   Fora da classe porque serve também ao perfil da loja, onde se reporta a
   LOJA e não um post. Um só lugar para o texto dos motivos.
   ====================================================================== */

const REPORT_MOTIVOS = [
    ["spam", "Spam ou repetição"],
    ["conteudo_inapropriado", "Conteúdo inapropriado"],
    ["produto_falso", "Produto falso ou enganoso"],
    ["golpe", "Parece golpe"],
    ["outro", "Outro motivo"],
];

function abrirReport(alvo) {
    document.getElementById("reportModal")?.remove();

    const wrap = document.createElement("div");
    wrap.id = "reportModal";
    wrap.className = "echo-dialog-backdrop";
    wrap.innerHTML = `
        <div class="echo-dialog">
            <h5>Reportar ${alvo.loja_post_id ? "publicação" : "loja"}</h5>
            <p class="echo-ajuda">Conte o que houve. O report vai para a moderação do Echo.</p>

            <div class="loja-report-motivos">
                ${REPORT_MOTIVOS.map(([v, r], i) => `
                    <label class="loja-report-opcao">
                        <input type="radio" name="motivoReport" value="${v}" ${i === 0 ? "checked" : ""}>
                        <span>${r}</span>
                    </label>`).join("")}
            </div>

            <textarea class="form-control mt-2" id="reportDescricao" rows="3" maxlength="1000"
                      placeholder="Quer detalhar? (opcional)"></textarea>

            <div class="echo-dialog-actions">
                <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" type="button" data-fecha>Cancelar</button>
                <button class="btn btn-sm btn-danger rounded-pill px-3" type="button" data-envia>Enviar report</button>
            </div>
        </div>`;

    document.body.appendChild(wrap);

    const fechar = () => wrap.remove();

    wrap.querySelector("[data-fecha]").addEventListener("click", fechar);
    wrap.addEventListener("click", e => { if (e.target === wrap) fechar(); });

    wrap.querySelector("[data-envia]").addEventListener("click", async () => {
        const motivo = wrap.querySelector('input[name="motivoReport"]:checked')?.value;

        try {
            const r = await fetch("api/lojas/report.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({
                    ...alvo,
                    motivo,
                    descricao: document.getElementById("reportDescricao").value.trim(),
                }),
            });
            const d = await r.json();

            fechar();

            if (d.error) { EchoUIInstance.toastError(d.error); return; }

            EchoUIInstance.toastSuccess("Report enviado. Obrigado por avisar.");
        } catch (e) {
            fechar();
            EchoUIInstance.toastError("Erro de conexão.");
        }
    });
}
