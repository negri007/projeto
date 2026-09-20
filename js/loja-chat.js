/* ======================================================================
   CHAT COM O AGENTE DA LOJA

   O markup e o CSS saem do `chat.html`, mas a tela é outra: o outro lado
   aqui não é uma pessoa, é o agente da loja. Isso muda três coisas.

   1. Não há poll de mensagem nova. Numa conversa entre pessoas, a outra
      pode escrever a qualquer momento; aqui só existe resposta ao que
      você mandou. Poll seria bater no servidor para sempre ouvir o
      mesmo silêncio.
   2. O "digitando..." é honesto: ele aparece enquanto a chamada está no
      ar e some quando ela volta, em vez de ser um enfeite temporizado.
   3. Quando o agente cita um produto, o card dele aparece embaixo da
      fala, com botão de adicionar ao carrinho. É a conversa virando
      compra sem sair da tela.
   ====================================================================== */

const LOJA_ID = parseInt(new URLSearchParams(location.search).get("loja_id") || "0", 10);

let loja = null;
let enviando = false;

document.addEventListener("DOMContentLoaded", async function () {
    const user = await EchoUIInstance.checkAuth({ redirectOnFail: true });
    if (!user) return;

    document.getElementById("notificationBellContainer").innerHTML =
        EchoUIInstance.getNotificationBellHTML();
    EchoUIInstance.initHeader("comercio");

    if (!LOJA_ID) {
        document.getElementById("chatMensagens").innerHTML =
            `<p class="text-center text-secondary p-4">Loja não informada.</p>`;
        return;
    }

    document.getElementById("chatEnviar").addEventListener("click", enviar);
    document.getElementById("chatCampo").addEventListener("keydown", e => {
        if (e.key === "Enter") enviar();
    });

    await carregarHistorico();
    carregarCarrinho();
});

async function carregarHistorico() {
    const box = document.getElementById("chatMensagens");

    try {
        const r = await fetch(`api/lojas/chat_historico.php?loja_id=${LOJA_ID}`,
                              { credentials: "same-origin" });
        const d = await r.json();

        if (!d.ok) {
            box.innerHTML = `<p class="text-center text-secondary p-4">${EchoUIInstance.escapeHTML(d.error || "Loja não encontrada.")}</p>`;
            return;
        }

        loja = d.loja;
        document.title = "ECHO - " + loja.nome;
        document.querySelector(".main-header-title").textContent = loja.nome;

        const logo = loja.logo
            ? `<span class="loja-logo" style="background-image:url('uploads/${encodeURIComponent(loja.logo)}')"></span>`
            : `<span class="loja-logo loja-logo-vazia"><i class="fa-solid fa-store"></i></span>`;

        document.getElementById("chatTopo").innerHTML = `
            <a class="loja-chat-identidade" href="loja_perfil.html?loja_id=${loja.id}">
                ${logo}
                <span>
                    <strong>${EchoUIInstance.escapeHTML(loja.nome)}</strong>
                    <small>atendimento pelo Echo da loja</small>
                </span>
            </a>`;

        box.innerHTML = "";

        /* A saudação é sempre a primeira bolha e NÃO vem do histórico: o
           lojista pode mudá-la depois, e quem já conversou veria a antiga
           congelada no topo para sempre. */
        bolha("agent", d.saudacao);

        d.mensagens.forEach(m => bolha(m.role, m.conteudo));

        rodape();
        aoFundo();
    } catch (e) {
        box.innerHTML = `<p class="text-center text-danger p-4">Erro ao carregar a conversa.</p>`;
    }
}

function rodape() {
    const p = document.getElementById("chatRodape");

    p.innerHTML = `Respondido pelo Echo da ${EchoUIInstance.escapeHTML(loja.nome)}.`;

    // "Falar com humano" só existe quando existe um humano alcançável.
    if (loja.whatsapp) {
        const n = (loja.whatsapp || "").replace(/\D+/g, "");
        const numero = n.length <= 11 ? "55" + n : n;

        p.innerHTML += ` <a href="https://wa.me/${numero}" target="_blank" rel="noopener">Falar com humano</a>`;
    }
}

function bolha(role, texto, produtos = []) {
    const box = document.getElementById("chatMensagens");
    const el  = document.createElement("div");

    el.className = "loja-bolha loja-bolha-" + (role === "agent" ? "agente" : "eu");
    el.innerHTML = `<p>${EchoUIInstance.escapeHTML(texto)}</p>`;

    if (produtos.length) {
        el.insertAdjacentHTML("beforeend", `
            <div class="loja-bolha-produtos">
                ${produtos.map(p => `
                    <div class="loja-card-mini" data-id="${p.id}">
                        ${p.imagem
                            ? `<div class="loja-card-mini-img" style="background-image:url('uploads/${encodeURIComponent(p.imagem)}')"></div>`
                            : `<div class="loja-card-mini-img loja-produto-sem-img"><i class="fa-solid fa-box"></i></div>`}
                        <div class="loja-card-mini-corpo">
                            <strong>${EchoUIInstance.escapeHTML(p.nome)}</strong>
                            <span>${p.preco !== null ? moeda(p.preco) : "sob consulta"}</span>
                        </div>
                        <button class="btn btn-sm btn-primary rounded-pill" type="button" data-add>
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>`).join("")}
            </div>`);

        el.querySelectorAll(".loja-card-mini").forEach(c =>
            c.querySelector("[data-add]").addEventListener("click", () =>
                adicionar(parseInt(c.dataset.id, 10))));
    }

    box.appendChild(el);
    return el;
}

function aoFundo() {
    const box = document.getElementById("chatMensagens");
    box.scrollTop = box.scrollHeight;
}

function moeda(v) {
    return "R$ " + Number(v).toFixed(2).replace(".", ",");
}

async function enviar() {
    if (enviando) return;

    const campo = document.getElementById("chatCampo");
    const texto = campo.value.trim();

    if (!texto) return;

    enviando = true;
    campo.value = "";
    bolha("user", texto);
    aoFundo();

    // Os três pontos ficam no ar exatamente enquanto a chamada está no ar.
    const digitando = document.createElement("div");
    digitando.className = "loja-bolha loja-bolha-agente loja-digitando";
    digitando.innerHTML = `<span class="ia-pontinhos"><i></i><i></i><i></i></span>`;
    document.getElementById("chatMensagens").appendChild(digitando);
    aoFundo();

    try {
        const r = await fetch("api/lojas/chat_mensagem.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ loja_id: LOJA_ID, mensagem: texto }),
        });
        const d = await r.json();

        digitando.remove();

        if (d.error) {
            bolha("agent", d.error);
            aoFundo();
            return;
        }

        bolha("agent", d.resposta, d.produtos_mencionados || []);
        aoFundo();
    } catch (e) {
        digitando.remove();
        bolha("agent", "Não consegui responder agora. Tente de novo em instantes.");
        aoFundo();
    } finally {
        enviando = false;
        campo.focus();
    }
}

/* ======================================================================
   CARRINHO — versão enxuta

   O carrinho de verdade mora no perfil da loja. Aqui ele é só um espelho
   na coluna da direita, para a pessoa ver o que já juntou conversando, e
   um atalho para finalizar lá.
   ====================================================================== */

async function adicionar(produtoId) {
    try {
        const r = await fetch("api/lojas/carrinho_adicionar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ produto_id: produtoId, quantidade: 1 }),
        });
        const d = await r.json();

        if (d.error) { EchoUIInstance.toastError(d.error); return; }

        EchoUIInstance.toastSuccess("Adicionado ao carrinho.");
        carregarCarrinho();
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão.");
    }
}

async function carregarCarrinho() {
    const box = document.getElementById("chatCarrinho");

    try {
        const r = await fetch(`api/lojas/carrinho.php?loja_id=${LOJA_ID}`, { credentials: "same-origin" });
        const d = await r.json();

        if (!d.ok || !d.itens.length) {
            box.innerHTML = `<p class="text-secondary mb-0 small">Nada no carrinho ainda.</p>`;
            return;
        }

        box.innerHTML = `
            ${d.itens.map(i => `
                <div class="loja-carrinho-linha">
                    <span>${i.quantidade}x ${EchoUIInstance.escapeHTML(i.nome)}</span>
                    <small>${i.preco !== null ? moeda(i.preco) : "—"}</small>
                </div>`).join("")}
            <div class="loja-carrinho-total"><span>Total</span><strong>${moeda(d.total)}</strong></div>
            <a class="btn btn-sm btn-success rounded-pill px-3 w-100 mt-2"
               href="loja_perfil.html?loja_id=${LOJA_ID}">
                Ver carrinho e finalizar
            </a>`;
    } catch (e) {
        box.innerHTML = `<p class="text-secondary mb-0 small">Não foi possível carregar.</p>`;
    }
}
