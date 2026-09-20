/* ======================================================================
   PERFIL PÚBLICO DA LOJA

   Banner, catálogo, publicações e o carrinho que termina no WhatsApp.

   O CARRINHO É SERVIDOR + TELA, e a divisão importa: a quantidade e o
   total são recalculados em JS para responder na hora ao +/-, mas quem
   monta o pedido que vai para o lojista é `carrinho_finalizar.php`, com
   os preços lidos do banco. A tela é rápida; o servidor é a verdade.
   ====================================================================== */

const params = new URLSearchParams(location.search);
const LOJA_ID = parseInt(params.get("loja_id") || "0", 10);

let loja = null;
let carrinho = [];

document.addEventListener("DOMContentLoaded", async function () {
    const user = await EchoUIInstance.checkAuth({ redirectOnFail: true });
    if (!user) return;

    document.getElementById("notificationBellContainer").innerHTML =
        EchoUIInstance.getNotificationBellHTML();
    EchoUIInstance.initHeader("comercio");

    if (!LOJA_ID) {
        document.getElementById("lojaCapa").innerHTML =
            `<p class="text-center text-secondary p-4">Loja não informada.</p>`;
        return;
    }

    document.getElementById("falarLoja").href = `loja_chat.html?loja_id=${LOJA_ID}`;
    document.getElementById("reportarLoja").addEventListener("click", () =>
        abrirReport({ loja_id: LOJA_ID }));
    document.getElementById("carrinhoBotao").addEventListener("click", abrirCarrinho);

    await carregarLoja();
    carregarProdutos();
    carregarPosts();
    carregarCarrinho();
});

async function carregarLoja() {
    try {
        const r = await fetch(`api/lojas/perfil.php?loja_id=${LOJA_ID}`, { credentials: "same-origin" });
        const d = await r.json();

        if (!d.ok) {
            document.getElementById("lojaCapa").innerHTML =
                `<p class="text-center text-secondary p-4">${EchoUIInstance.escapeHTML(d.error || "Loja não encontrada.")}</p>`;
            return;
        }

        loja = d.loja;
        document.title = "ECHO - " + loja.nome;
        document.querySelector(".main-header-title").textContent = loja.nome;

        const capa = loja.banner
            ? `<div class="loja-banner" style="background-image:url('uploads/${encodeURIComponent(loja.banner)}')"></div>`
            : `<div class="loja-banner loja-banner-vazio"></div>`;

        const logo = loja.logo
            ? `<div class="loja-logo-grande" style="background-image:url('uploads/${encodeURIComponent(loja.logo)}')"></div>`
            : `<div class="loja-logo-grande loja-logo-vazia"><i class="fa-solid fa-store"></i></div>`;

        document.getElementById("lojaCapa").innerHTML = `
            ${capa}
            <div class="loja-cabecalho">
                ${logo}
                <div class="loja-cabecalho-texto">
                    <h2>${EchoUIInstance.escapeHTML(loja.nome)}</h2>
                    <span class="loja-categoria">${EchoUIInstance.escapeHTML(loja.categoria || "")}</span>
                    <p>${EchoUIInstance.escapeHTML(loja.descricao || "")}</p>
                </div>
            </div>`;

        const contato = document.getElementById("lojaContato");
        const linhas  = [];

        if (loja.whatsapp) {
            linhas.push(`<div class="loja-contato-item"><i class="fa-brands fa-whatsapp"></i>
                         ${EchoUIInstance.escapeHTML(loja.whatsapp)}</div>`);
        }
        if (loja.telefone) {
            linhas.push(`<div class="loja-contato-item"><i class="fa-solid fa-phone"></i>
                         ${EchoUIInstance.escapeHTML(loja.telefone)}</div>`);
        }
        if (loja.site) {
            linhas.push(`<div class="loja-contato-item"><i class="fa-solid fa-globe"></i>
                         ${EchoUIInstance.escapeHTML(loja.site)}</div>`);
        }

        contato.innerHTML = linhas.length
            ? linhas.join("")
            : `<p class="text-secondary mb-0 small">Fale pelo chat da loja.</p>`;
    } catch (e) {
        document.getElementById("lojaCapa").innerHTML =
            `<p class="text-center text-danger p-4">Erro ao carregar a loja.</p>`;
    }
}

async function carregarProdutos() {
    const box = document.getElementById("lojaProdutos");

    try {
        const r = await fetch(`api/lojas/produtos.php?loja_id=${LOJA_ID}`, { credentials: "same-origin" });
        const d = await r.json();

        if (!d.ok || !d.produtos.length) {
            box.innerHTML = `<p class="text-secondary small">Esta loja ainda não cadastrou produtos.</p>`;
            return;
        }

        box.innerHTML = d.produtos.map(p => `
            <div class="loja-produto-card" data-id="${p.id}">
                ${p.imagem
                    ? `<div class="loja-produto-img" style="background-image:url('uploads/${encodeURIComponent(p.imagem)}')"></div>`
                    : `<div class="loja-produto-img loja-produto-sem-img"><i class="fa-solid fa-box"></i></div>`}
                <div class="loja-produto-corpo">
                    <strong>${EchoUIInstance.escapeHTML(p.nome)}</strong>
                    ${p.descricao ? `<small>${EchoUIInstance.escapeHTML(p.descricao)}</small>` : ""}
                    <span class="loja-produto-preco">${p.preco !== null ? moeda(p.preco) : "sob consulta"}</span>
                    <button class="btn btn-sm btn-primary rounded-pill w-100" type="button" data-add>
                        <i class="fa-solid fa-plus me-1"></i>Adicionar
                    </button>
                </div>
            </div>`).join("");

        box.querySelectorAll(".loja-produto-card").forEach(el =>
            el.querySelector("[data-add]").addEventListener("click", () =>
                adicionar(parseInt(el.dataset.id, 10))));
    } catch (e) {
        box.innerHTML = `<p class="text-secondary small">Erro ao carregar os produtos.</p>`;
    }
}

function carregarPosts() {
    // `compacto`: dentro do perfil da loja, um botão "Falar com a loja" em
    // cada post competiria com o flutuante que já está na tela.
    new LojaFeed({
        container: "lojaPosts",
        params: { loja_id: LOJA_ID },
        emptyText: "Esta loja ainda não publicou nada.",
        compacto: true,
    }).load();
}

function moeda(v) {
    return "R$ " + Number(v).toFixed(2).replace(".", ",");
}

/* ======================================================================
   CARRINHO
   ====================================================================== */

async function carregarCarrinho() {
    try {
        const r = await fetch(`api/lojas/carrinho.php?loja_id=${LOJA_ID}`, { credentials: "same-origin" });
        const d = await r.json();

        carrinho = d.ok ? d.itens : [];
        pintarBotao();
    } catch (e) {
        carrinho = [];
    }
}

function pintarBotao() {
    const btn   = document.getElementById("carrinhoBotao");
    const badge = document.getElementById("carrinhoBadge");
    const total = carrinho.reduce((s, i) => s + i.quantidade, 0);

    btn.hidden = total === 0;
    badge.textContent = total;
}

async function adicionar(produtoId, quantidade = null) {
    const atual = carrinho.find(i => i.produto_id === produtoId);
    const qtd   = quantidade !== null ? quantidade : ((atual?.quantidade || 0) + 1);

    try {
        const r = await fetch("api/lojas/carrinho_adicionar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ produto_id: produtoId, quantidade: qtd }),
        });
        const d = await r.json();

        if (d.error) { EchoUIInstance.toastError(d.error); return; }

        await carregarCarrinho();

        // O badge pulsa com `echo-pulo`, a mesma animação do coração ao
        // curtir: dois jeitos diferentes de dizer "recebi seu clique" na
        // mesma tela seria ruído.
        const badge = document.getElementById("carrinhoBadge");
        badge.classList.remove("pulsa");
        void badge.offsetWidth;
        badge.classList.add("pulsa");

        if (document.getElementById("carrinhoModal")) desenharModal();
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão.");
    }
}

async function remover(produtoId) {
    try {
        await fetch("api/lojas/carrinho_remover.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ produto_id: produtoId, loja_id: LOJA_ID }),
        });

        await carregarCarrinho();

        if (document.getElementById("carrinhoModal")) desenharModal();
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão.");
    }
}

function abrirCarrinho() {
    document.getElementById("carrinhoModal")?.remove();

    const wrap = document.createElement("div");
    wrap.id = "carrinhoModal";
    wrap.className = "echo-dialog-backdrop";
    wrap.innerHTML = `<div class="echo-dialog loja-carrinho-modal"><div id="carrinhoCorpo"></div></div>`;

    document.body.appendChild(wrap);
    wrap.addEventListener("click", e => { if (e.target === wrap) wrap.remove(); });

    desenharModal();
}

function desenharModal() {
    const box = document.getElementById("carrinhoCorpo");
    if (!box) return;

    if (!carrinho.length) {
        box.innerHTML = `
            <h5>Seu carrinho</h5>
            <p class="echo-ajuda">Nada aqui ainda.</p>
            <div class="echo-dialog-actions">
                <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" type="button" data-fecha>Fechar</button>
            </div>`;
        box.querySelector("[data-fecha]").addEventListener("click", () =>
            document.getElementById("carrinhoModal").remove());
        return;
    }

    // Total recalculado aqui, sem ida ao servidor: o +/- precisa responder
    // no mesmo quadro do clique. O servidor recalcula ao finalizar.
    const total = carrinho.reduce((s, i) => s + (i.preco || 0) * i.quantidade, 0);
    const temIndisponivel = carrinho.some(i => !i.disponivel);

    box.innerHTML = `
        <h5>Seu carrinho</h5>
        <p class="echo-ajuda">O pedido vai pronto para o WhatsApp da loja.</p>

        <div class="loja-carrinho-itens">
            ${carrinho.map(i => `
                <div class="loja-carrinho-item ${i.disponivel ? "" : "indisponivel"}" data-id="${i.produto_id}">
                    <div class="loja-carrinho-info">
                        <strong>${EchoUIInstance.escapeHTML(i.nome)}</strong>
                        <small>${i.preco !== null ? moeda(i.preco) : "sob consulta"}
                            ${i.disponivel ? "" : "· indisponível agora"}</small>
                    </div>
                    <div class="loja-carrinho-qtd">
                        <button type="button" data-menos>−</button>
                        <span>${i.quantidade}</span>
                        <button type="button" data-mais>+</button>
                    </div>
                    <button type="button" class="loja-carrinho-tirar" data-tirar title="Remover">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>`).join("")}
        </div>

        <div class="loja-carrinho-total">
            <span>Total</span><strong>${moeda(total)}</strong>
        </div>

        ${temIndisponivel ? `<p class="loja-carrinho-aviso">
            Há item indisponível no carrinho. Tire antes de finalizar, ou pergunte à loja.</p>` : ""}

        <div class="echo-dialog-actions">
            <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" type="button" data-fecha>Continuar comprando</button>
            <button class="btn btn-sm btn-success rounded-pill px-3" type="button" data-finaliza>
                <i class="fa-brands fa-whatsapp me-1"></i>Finalizar pelo WhatsApp
            </button>
        </div>`;

    box.querySelectorAll(".loja-carrinho-item").forEach(el => {
        const id = parseInt(el.dataset.id, 10);
        const it = carrinho.find(x => x.produto_id === id);

        el.querySelector("[data-mais]").addEventListener("click", () => adicionar(id, it.quantidade + 1));
        el.querySelector("[data-menos]").addEventListener("click", () =>
            it.quantidade <= 1 ? remover(id) : adicionar(id, it.quantidade - 1));
        el.querySelector("[data-tirar]").addEventListener("click", () => remover(id));
    });

    box.querySelector("[data-fecha]").addEventListener("click", () =>
        document.getElementById("carrinhoModal").remove());
    box.querySelector("[data-finaliza]").addEventListener("click", finalizar);
}

async function finalizar() {
    try {
        const r = await fetch("api/lojas/carrinho_finalizar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ loja_id: LOJA_ID }),
        });
        const d = await r.json();

        if (d.error) { EchoUIInstance.toastError(d.error); return; }

        document.getElementById("carrinhoModal")?.remove();
        await carregarCarrinho();

        /* Nova aba, e não `location`: a pessoa costuma voltar ao Echo
           depois de mandar o pedido, e trocar a aba atual pelo WhatsApp
           apagaria a loja que ela estava olhando. */
        window.open(d.link, "_blank", "noopener");

        EchoUIInstance.toastSuccess("Pedido montado. É só enviar no WhatsApp.");
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão.");
    }
}
