/* ======================================================================
   "Criar seu Echo" — criação e edição do agente pessoal e do da loja.

   Uma página só para os dois fluxos porque eles são o mesmo objeto em dois
   momentos: quem não tem agente cria, quem tem edita. Duas páginas
   obrigariam a decidir para onde mandar a pessoa antes de saber se ela já
   tem um — e essa decisão só existe depois de uma chamada ao servidor.

   Ver docs/plans/plano-agente-echo.md.
   ====================================================================== */

/** De quanto em quanto tempo o card de status se atualiza. */
const ECHO_POLL_MS = 30000;

let estado = {
    agente: null,   // status.php
    loja:   null,   // lojas/perfil.php
    nivel:  0,      // autonomia escolhida na tela de criação
};

let pollStatus = null;

document.addEventListener("DOMContentLoaded", async function () {
    const user = await EchoUIInstance.checkAuth({ redirectOnFail: true });
    if (!user) return;

    document.getElementById("notificationBellContainer").innerHTML =
        EchoUIInstance.getNotificationBellHTML();
    EchoUIInstance.initHeader("meu_echo");

    ligarEventos();
    await carregarTudo();
});

/* ======================================================================
   CARGA E ROTEAMENTO DE TELA
   ====================================================================== */

async function carregarTudo() {
    const [agente, loja] = await Promise.all([buscarAgente(), buscarLoja()]);

    estado.agente = agente;
    estado.loja   = loja;

    /* A pessoa cai direto na edição se já tiver QUALQUER um dos dois.
       Mandar quem já tem loja para a tela de "qual Echo você quer criar?"
       seria perguntar de novo uma coisa que ela já respondeu. */
    const temAlgo = (agente && agente.existe && agente.agente.autonomia !== null && jaConfigurou(agente))
        || loja !== null;

    if (temAlgo) {
        mostrar("telaEdicao");
        desenharEdicao();
    } else {
        mostrar("telaEscolha");
    }
}

/* `me.php` cria o agente de todo mundo em autonomia 0 na primeira visita,
   então "existe" não quer dizer "a pessoa configurou". O que distingue é
   ter mexido: nome próprio ou personalidade escrita. */
function jaConfigurou(status) {
    if (!status || !status.existe) return false;

    const a = status.agente;
    return (a.personalidade && a.personalidade.trim() !== "")
        || (a.nome && a.nome !== "Meu Echo" && !a.nome.startsWith("Echo de "));
}

async function buscarAgente() {
    try {
        const r = await fetch("api/user_agent/status.php", { credentials: "same-origin" });
        const d = await r.json();
        return d.ok ? d : null;
    } catch (e) { return null; }
}

async function buscarLoja() {
    try {
        const r = await fetch("api/lojas/perfil.php", { credentials: "same-origin" });
        const d = await r.json();
        return d.ok ? d : null;
    } catch (e) { return null; }
}

function mostrar(id) {
    document.querySelectorAll(".echo-passo").forEach(s => s.hidden = s.id !== id);
    window.scrollTo({ top: 0, behavior: "smooth" });

    // O poll só corre na edição, que é a única tela que mostra contadores.
    if (id === "telaEdicao") {
        iniciarPoll();
    } else if (pollStatus) {
        clearInterval(pollStatus);
        pollStatus = null;
    }
}

function iniciarPoll() {
    if (pollStatus) return;

    pollStatus = setInterval(async () => {
        if (document.hidden) return;

        const s = await buscarAgente();
        if (!s || !s.existe) return;

        estado.agente = s;
        pintarStatus(s);
        desenharSugestoes();
    }, ECHO_POLL_MS);
}

/* ======================================================================
   TELA 1 e 2 — CRIAÇÃO
   ====================================================================== */

function ligarEventos() {
    document.querySelectorAll(".echo-card-tipo").forEach(b =>
        b.addEventListener("click", () =>
            mostrar(b.dataset.tipo === "loja" ? "telaLoja" : "telaPessoal")));

    document.querySelectorAll(".echo-voltar").forEach(b =>
        b.addEventListener("click", () => mostrar(b.dataset.volta)));

    document.querySelectorAll("#peNiveis .echo-nivel").forEach(b =>
        b.addEventListener("click", () => escolherNivel(parseInt(b.dataset.nivel, 10))));

    document.getElementById("peSalvar").addEventListener("click", salvarPessoal);
    document.getElementById("loSalvar").addEventListener("click", salvarLoja);
    document.getElementById("ePGerar").addEventListener("click", gerarSugestao);
    document.getElementById("eLCriar").addEventListener("click", () => mostrar("telaLoja"));
    document.getElementById("eLNovoProduto").addEventListener("click", alternarFormProduto);

    document.getElementById("prontoVer").addEventListener("click", async () => {
        await carregarTudo();
        mostrar("telaEdicao");
        desenharEdicao();
    });

    escolherNivel(0);
}

function escolherNivel(n) {
    estado.nivel = n;

    document.querySelectorAll("#peNiveis .echo-nivel").forEach(b =>
        b.classList.toggle("ativo", parseInt(b.dataset.nivel, 10) === n));

    // O campo da frase só existe enquanto o nível 3 está escolhido: um
    // campo de confirmação visível o tempo todo perde o peso que ele tem
    // de ter no momento em que aparece.
    const box = document.getElementById("peConfirmaBox");
    box.hidden = n !== 3;

    if (n !== 3) document.getElementById("peConfirma").value = "";
}

function erro(id, msg) {
    const e = document.getElementById(id);
    e.textContent = msg || "";
    e.hidden = !msg;
}

async function salvarPessoal() {
    const btn = document.getElementById("peSalvar");
    erro("peErro", "");

    const corpo = {
        nome:          document.getElementById("peNome").value.trim(),
        personalidade: document.getElementById("pePersonalidade").value.trim(),
        autonomia:     estado.nivel,
        confirmacao:   document.getElementById("peConfirma").value,
    };

    if (!corpo.nome) {
        erro("peErro", "Dê um nome ao seu Echo.");
        return;
    }

    btn.disabled = true;

    try {
        const r = await fetch("api/user_agent/configurar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify(corpo),
        });
        const d = await r.json();

        if (d.error) {
            erro("peErro", d.error);
            return;
        }

        pronto("pessoal");
    } catch (e) {
        erro("peErro", "Erro de conexão. Tente de novo.");
    } finally {
        btn.disabled = false;
    }
}

async function salvarLoja() {
    const btn = document.getElementById("loSalvar");
    erro("loErro", "");

    const nome  = document.getElementById("loNome").value.trim();
    const whats = document.getElementById("loWhats").value.trim();

    if (!nome)  { erro("loErro", "Dê um nome à sua loja."); return; }
    if (!whats) { erro("loErro", "Informe o WhatsApp da loja."); return; }

    // Multipart porque a loja já nasce podendo ter logo e capa.
    const fd = new FormData();
    fd.append("nome", nome);
    fd.append("whatsapp", whats);
    fd.append("categoria", document.getElementById("loCategoria").value);
    fd.append("descricao", document.getElementById("loDescricao").value.trim());
    fd.append("instrucoes", document.getElementById("loInstrucoes").value.trim());
    fd.append("saudacao", document.getElementById("loSaudacao").value.trim());

    const logo   = document.getElementById("loLogo").files[0];
    const banner = document.getElementById("loBanner").files[0];
    if (logo)   fd.append("logo", logo);
    if (banner) fd.append("banner", banner);

    btn.disabled = true;

    try {
        const r = await fetch("api/lojas/cadastrar.php", {
            method: "POST", credentials: "same-origin", body: fd,
        });
        const d = await r.json();

        if (d.error) {
            erro("loErro", d.error);
            return;
        }

        pronto("loja");
    } catch (e) {
        erro("loErro", "Erro de conexão. Tente de novo.");
    } finally {
        btn.disabled = false;
    }
}

function pronto(tipo) {
    document.getElementById("prontoTexto").textContent = tipo === "loja"
        ? "Seu Echo da loja está pronto! Você já pode postar no feed de comércio."
        : "Seu Echo foi criado! Agora ele começa a aprender com você.";

    document.getElementById("prontoFeed").href = tipo === "loja" ? "comercio.html" : "inicio.html";
    document.getElementById("prontoFeed").textContent = tipo === "loja"
        ? "Ir para o comércio" : "Ir para o feed";

    mostrar("telaPronto");

    // O Bit comemora junto, se estiver ligado.
    if (window.EchoBit && EchoBit.ativo && EchoBit.ativo()) {
        try { EchoBit.marcar(document.querySelector(".echo-pronto-icone")); } catch (e) { /* enfeite */ }
    }
}

/* ======================================================================
   MODO EDIÇÃO
   ====================================================================== */

function desenharEdicao() {
    desenharPessoal();
    desenharLoja();
}

function desenharPessoal() {
    const s = estado.agente;
    if (!s || !s.existe) return;

    pintarStatus(s);
    desenharSugestoes();

    const a = s.agente;

    document.getElementById("ePForm").innerHTML = `
        <div data-enter="#edSalvar">
        <div class="echo-bloco">
            <label class="echo-rotulo" for="edNome">
                <i class="fa-solid fa-signature"></i>Nome do seu Echo
            </label>
            <input type="text" class="form-control echo-campo-medio" id="edNome" maxlength="100"
                   value="${EchoUIInstance.escapeHTML(a.nome || "")}">
        </div>

        <div class="echo-bloco">
            <label class="echo-rotulo" for="edPersonalidade">
                <i class="fa-solid fa-comment-dots"></i>Como ele fala?
            </label>
            <p class="echo-ajuda">Descreva o jeito que você quer que ele escreva.</p>
            <textarea class="form-control" id="edPersonalidade" rows="4" maxlength="2000"
                      placeholder="Ex.: Fala de forma direta, usa gírias, não é formal.">${EchoUIInstance.escapeHTML(a.personalidade || "")}</textarea>
            <div class="echo-exemplos" data-alvo="edPersonalidade">
                <span class="echo-exemplos-rotulo">Escreva sobre</span>
                <button type="button" class="echo-exemplo"
                        data-texto="Fala de forma direta e curta, sem rodeio.">Tom</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Usa gíria, nada formal, e emoji de vez em quando.">Gírias e emoji</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Não gosta de texto comprido: no máximo duas frases por resposta.">Tamanho</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Assuntos que eu comento: tecnologia, futebol e música.">Assuntos</button>
            </div>
        </div>

        <div class="echo-bloco">
            <span class="echo-rotulo"><i class="fa-solid fa-sliders"></i>Autonomia</span>
            <p class="echo-ajuda">O quanto ele pode fazer sem te perguntar.</p>
            <div class="echo-niveis" id="edNiveis">
                ${nivelHTML(0, "fa-magnifying-glass", "Só observa", "Aprende com você, não faz nada ainda.", a.autonomia)}
                ${nivelHTML(1, "fa-lightbulb", "Sugere", "Propõe respostas e posts. Você aprova tudo.", a.autonomia, true)}
                ${nivelHTML(2, "fa-bolt", "Age sozinho", "Age no seu lugar. Você pode desfazer em 24h.", a.autonomia)}
                ${nivelHTML(3, "fa-robot", "Autônomo total", "Faz tudo sozinho. Requer confirmação especial.", a.autonomia)}
            </div>

            <div class="echo-confirma" id="edConfirmaBox" hidden>
                <p class="echo-ajuda mb-2">
                    <i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>
                    Nesse nível ele publica e responde sem te perguntar nada.
                </p>
                <input type="text" class="form-control echo-campo-medio" id="edConfirma"
                       placeholder="confirmo autonomia total" autocomplete="off">
            </div>
        </div>

        <div class="echo-acoes">
            <button class="btn btn-primary rounded-pill px-4" type="button" id="edSalvar">
                Salvar alterações
            </button>
            <p class="echo-acoes-nota">Vale a partir da próxima sugestão dele.</p>
        </div>
        <div class="echo-erro" id="edErro"></div>
        </div>`;

    let nivel = a.autonomia;

    document.querySelectorAll("#edNiveis .echo-nivel").forEach(b =>
        b.addEventListener("click", () => {
            nivel = parseInt(b.dataset.nivel, 10);
            document.querySelectorAll("#edNiveis .echo-nivel").forEach(x =>
                x.classList.toggle("ativo", parseInt(x.dataset.nivel, 10) === nivel));
            document.getElementById("edConfirmaBox").hidden = nivel !== 3;
        }));

    document.getElementById("edSalvar").addEventListener("click", async () => {
        erro("edErro", "");

        try {
            const r = await fetch("api/user_agent/configurar.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({
                    nome: document.getElementById("edNome").value.trim(),
                    personalidade: document.getElementById("edPersonalidade").value.trim(),
                    autonomia: nivel,
                    confirmacao: document.getElementById("edConfirma")?.value || "",
                }),
            });
            const d = await r.json();

            if (d.error) { erro("edErro", d.error); return; }

            EchoUIInstance.toastSuccess("Echo atualizado.");
            estado.agente = await buscarAgente();
        } catch (e) {
            erro("edErro", "Erro de conexão.");
        }
    });
}

function nivelHTML(n, icone, titulo, desc, atual, recomendado = false) {
    return `<button type="button" class="echo-nivel ${n === atual ? "ativo" : ""}" data-nivel="${n}">
        <i class="fa-solid ${icone}"></i>
        <strong>${titulo}${recomendado ? ' <span class="echo-selo">recomendado</span>' : ""}</strong>
        <span>${desc}</span>
    </button>`;
}

function pintarStatus(s) {
    document.getElementById("ePMemorias").textContent  = s.memorias;
    document.getElementById("ePPendentes").textContent = s.pendentes;
}

/* As sugestões aparecem NESTA página, e não numa separada: a decisão de
   aprovar depende de ver o agente e o nível de autonomia dele, que estão
   aqui do lado. Mandar para outra tela quebraria esse contexto. */
async function desenharSugestoes() {
    const box = document.getElementById("ePSugestoes");

    try {
        const r = await fetch("api/user_agent/sugestoes.php", { credentials: "same-origin" });
        const d = await r.json();

        if (!d.ok || !d.sugestoes.length) { box.innerHTML = ""; return; }

        box.innerHTML = d.sugestoes.map(s => `
            <div class="echo-sugestao" data-id="${s.id}">
                <div class="echo-sugestao-topo">
                    <span class="echo-sugestao-tipo">${rotuloTipo(s.tipo)}</span>
                    <small>expira em ${tempoRestante(s.expira_em_min)}</small>
                </div>
                <p>${EchoUIInstance.escapeHTML(s.sugestao)}</p>
                <div class="echo-sugestao-acoes">
                    <button class="btn btn-sm btn-primary rounded-pill px-3" data-acao="aprovar">Aprovar</button>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-acao="rejeitar">Rejeitar</button>
                </div>
            </div>`).join("");

        box.querySelectorAll(".echo-sugestao").forEach(el => {
            el.querySelectorAll("[data-acao]").forEach(b =>
                b.addEventListener("click", () => responder(parseInt(el.dataset.id, 10), b.dataset.acao)));
        });
    } catch (e) {
        box.innerHTML = "";
    }
}

function rotuloTipo(t) {
    return { post: "Post", resposta: "Resposta", comentario: "Comentário", curtida: "Curtida" }[t] || t;
}

function tempoRestante(min) {
    if (min < 60) return `${min} min`;
    return `${Math.floor(min / 60)}h`;
}

async function responder(id, acao) {
    try {
        const r = await fetch("api/user_agent/sugestao_responder.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ sugestao_id: id, acao }),
        });
        const d = await r.json();

        if (d.error) { EchoUIInstance.toastError(d.error); return; }

        /* Aprovar NÃO publica: o servidor devolve o texto e a pessoa é
           levada ao Início com ele pronto no campo. Publicar daqui criaria
           um segundo caminho para um post nascer, com uma moderação e um
           gancho de notificação próprios para manter em dia. */
        if (acao === "aprovar" && d.texto) {
            try {
                sessionStorage.setItem("echo_rascunho", d.texto);
                EchoUIInstance.toastSuccess("Aprovado. Abrindo o Início para você publicar.");
                setTimeout(() => { window.location = "inicio.html"; }, 900);
                return;
            } catch (e) {
                EchoUIInstance.toastSuccess("Aprovado.");
            }
        } else {
            EchoUIInstance.toastSuccess("Sugestão rejeitada.");
        }

        estado.agente = await buscarAgente();
        pintarStatus(estado.agente);
        desenharSugestoes();
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão.");
    }
}

async function gerarSugestao() {
    const btn = document.getElementById("ePGerar");
    btn.disabled = true;

    try {
        const r = await fetch("api/user_agent/gerar_sugestao_post.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: "{}",
        });
        const d = await r.json();

        if (d.error)      { EchoUIInstance.toastError(d.error); return; }
        if (!d.gerou)     { EchoUIInstance.toast(d.motivo, "secondary", 5000); return; }

        EchoUIInstance.toastSuccess("Sugestão pronta.");
        estado.agente = await buscarAgente();
        pintarStatus(estado.agente);
        desenharSugestoes();
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão.");
    } finally {
        btn.disabled = false;
    }
}

/* ======================================================================
   ABA DA LOJA
   ====================================================================== */

function desenharLoja() {
    const tem = estado.loja !== null;

    document.getElementById("eLSemLoja").hidden = tem;
    document.getElementById("eLComLoja").hidden = !tem;

    if (!tem) return;

    const l = estado.loja.loja;
    const s = estado.loja.stats || {};

    document.getElementById("eLStats").innerHTML = `
        ${statHTML(s.produtos, "produtos")}
        ${statHTML(s.posts, "posts")}
        ${statHTML(s.curtidas, "curtidas")}
        ${statHTML(s.comentarios, "comentários")}
        ${statHTML(s.chats, "conversas")}`;

    desenharFormLoja(l);
    carregarProdutos();
    carregarPostsDaLoja();
    carregarVideo();
}

function statHTML(n, rotulo) {
    return `<div class="echo-status-num"><strong>${n || 0}</strong><small>${rotulo}</small></div>`;
}

function desenharFormLoja(l) {
    const ag = estado.loja.agente || {};

    document.getElementById("eLFormLoja").innerHTML = `
        <div data-enter="#elSalvar">
        <div class="echo-bloco">
            <label class="echo-rotulo" for="elNome">
                <i class="fa-solid fa-store"></i>Nome da loja
            </label>
            <input type="text" class="form-control echo-campo-medio" id="elNome" maxlength="150"
                   value="${EchoUIInstance.escapeHTML(l.nome || "")}">
        </div>

        <div class="echo-bloco">
            <label class="echo-rotulo" for="elWhats">
                <i class="fa-brands fa-whatsapp"></i>WhatsApp
            </label>
            <p class="echo-ajuda">Para onde o pedido é enviado ao finalizar.</p>
            <input type="tel" class="form-control echo-campo-curto" id="elWhats" maxlength="20"
                   placeholder="Ex.: (11) 98765-4321"
                   value="${EchoUIInstance.escapeHTML(l.whatsapp || "")}">
            <div class="echo-exemplos" data-alvo="elWhats">
                <span class="echo-exemplos-rotulo">Formatos</span>
                <button type="button" class="echo-exemplo">(11) 98765-4321</button>
                <button type="button" class="echo-exemplo">11987654321</button>
            </div>
        </div>

        <div class="echo-bloco">
            <label class="echo-rotulo" for="elDescricao">
                <i class="fa-solid fa-align-left"></i>Descrição
            </label>
            <textarea class="form-control" id="elDescricao" rows="2" maxlength="2000"
                      placeholder="Ex.: Pão quentinho de hora em hora.">${EchoUIInstance.escapeHTML(l.descricao || "")}</textarea>
            <div class="echo-exemplos" data-alvo="elDescricao">
                <span class="echo-exemplos-rotulo">Exemplos</span>
                <button type="button" class="echo-exemplo"
                        data-texto="Pão quentinho de hora em hora, bolos por encomenda e café passado na hora.">Padaria</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Roupas de segunda mão selecionadas peça a peça, do P ao GG.">Brechó</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Conserto de celular e notebook, com orçamento na hora.">Serviço</button>
            </div>
        </div>

        <div class="echo-bloco">
            <label class="echo-rotulo" for="elInstrucoes">
                <i class="fa-solid fa-book"></i>O que seu agente deve saber?
            </label>
            <p class="echo-ajuda">Quanto mais detalhe, menos ele manda o cliente pro WhatsApp.</p>
            <textarea class="form-control" id="elInstrucoes" rows="6" maxlength="6000"
                      placeholder="Ex.: Abrimos das 6h às 20h. Entrega até 3km, taxa R$ 5. Aceitamos pix.">${EchoUIInstance.escapeHTML(ag.instrucoes || "")}</textarea>
            <div class="echo-exemplos" data-alvo="elInstrucoes">
                <span class="echo-exemplos-rotulo">Não esqueça de</span>
                <button type="button" class="echo-exemplo"
                        data-texto="Abrimos de segunda a sábado, das 6h às 20h. Domingo não abrimos.">Horário</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Entregamos no bairro até 3km, taxa de R$ 5. Acima disso, combinar.">Entrega</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Aceitamos pix, débito e crédito. Sem parcelamento.">Pagamento</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Encomenda precisa de 2 dias de antecedência.">Prazo</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Não trabalhamos com opções sem glúten nem sem lactose.">O que não tem</button>
            </div>
        </div>

        <div class="echo-bloco">
            <label class="echo-rotulo" for="elSaudacao">
                <i class="fa-regular fa-comment"></i>Primeira mensagem ao cliente
            </label>
            <input type="text" class="form-control" id="elSaudacao" maxlength="500"
                   placeholder="Ex.: Oi! Bateu a fome?"
                   value="${EchoUIInstance.escapeHTML(ag.saudacao || "")}">
            <div class="echo-exemplos" data-alvo="elSaudacao">
                <span class="echo-exemplos-rotulo">Exemplos</span>
                <button type="button" class="echo-exemplo">Oi! Bateu a fome? Me diz o que você procura.</button>
                <button type="button" class="echo-exemplo">Olá! Posso te mostrar o que temos hoje?</button>
            </div>
        </div>

        <div class="echo-acoes">
            <button class="btn btn-primary rounded-pill px-4" type="button" id="elSalvar">
                Salvar loja e agente
            </button>
            <p class="echo-acoes-nota">Vale na hora, na próxima conversa do cliente.</p>
        </div>
        <div class="echo-erro" id="elErro"></div>
        </div>`;

    document.getElementById("elSalvar").addEventListener("click", salvarEdicaoLoja);
}

async function salvarEdicaoLoja() {
    erro("elErro", "");

    const fd = new FormData();
    fd.append("nome", document.getElementById("elNome").value.trim());
    fd.append("whatsapp", document.getElementById("elWhats").value.trim());
    fd.append("descricao", document.getElementById("elDescricao").value.trim());

    try {
        // Dois endpoints porque são dois objetos: a loja e o agente dela.
        const r1 = await fetch("api/lojas/atualizar.php", {
            method: "POST", credentials: "same-origin", body: fd,
        });
        const d1 = await r1.json();

        if (d1.error) { erro("elErro", d1.error); return; }

        const r2 = await fetch("api/lojas/agente_configurar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({
                instrucoes: document.getElementById("elInstrucoes").value.trim(),
                saudacao:   document.getElementById("elSaudacao").value.trim(),
                ativo:      true,
            }),
        });
        const d2 = await r2.json();

        if (d2.error) { erro("elErro", d2.error); return; }

        EchoUIInstance.toastSuccess("Loja atualizada.");
        estado.loja = await buscarLoja();
    } catch (e) {
        erro("elErro", "Erro de conexão.");
    }
}

/* ------------------------------ vídeo ------------------------------ */

/* Espelha VIDEO_PROMPTS_NICHO de api/video/helpers.php — só para
   preencher o placeholder antes do primeiro gerar. Quem decide de
   verdade o que vale é o servidor; isto é só a sugestão inicial. */
const VIDEO_PROMPTS_NICHO = {
    "Alimentação": "{nome}, comida fresca sendo preparada, câmera lenta, luz quente de cozinha profissional, sem texto",
    "Moda":        "{nome}, roupas em destaque, modelo em movimento suave, iluminação de estúdio, paleta de cores harmoniosa",
    "Tecnologia":  "{nome}, dispositivo tecnológico em close, luz azul dramática, superfície espelhada, câmera lenta",
    "Beleza":      "{nome}, produto de beleza em destaque, pétalas ou glitter caindo, fundo neutro, cinematográfico",
    "Saúde":       "{nome}, ambiente limpo e moderno, luz clara, transmite confiança e bem-estar",
    "Serviços":    "{nome}, profissional trabalhando com cuidado, ambiente organizado, luz natural",
    "Outro":       "{nome}, produto ou serviço em destaque, qualidade cinematográfica, sem texto",
};

let videoPollTimer = null;

/** `uploads/videos/lojas/12/x.mp4` tem barra dentro — encodeURIComponent
    sozinho escaparia ela e quebraria o caminho. Codifica por segmento. */
function videoSrc(arquivo) {
    return "uploads/" + arquivo.split("/").map(encodeURIComponent).join("/");
}

function videoPromptSugerido() {
    const l = estado.loja.loja;
    const modelo = VIDEO_PROMPTS_NICHO[l.categoria] || VIDEO_PROMPTS_NICHO["Outro"];
    return modelo.replace("{nome}", l.nome || "Minha loja");
}

async function carregarVideo() {
    pararPollVideo();

    const lojaId = estado.loja.loja.id;

    try {
        const r = await fetch(`api/video/loja.php?loja_id=${lojaId}`, { credentials: "same-origin" });
        const d = await r.json();

        if (d.ok && d.tem_video) {
            desenharVideoPronto(d.video);
        } else {
            desenharVideoForm(videoPromptSugerido());
        }
    } catch (e) {
        desenharVideoForm(videoPromptSugerido());
    }
}

function desenharVideoForm(promptInicial) {
    const box = document.getElementById("eLVideo");

    box.innerHTML = `
        <div class="echo-video-card">
            <h5><i class="fa-solid fa-clapperboard me-1"></i>Vídeo de apresentação</h5>
            <p class="echo-ajuda">
                Descreva sua loja em uma frase e geramos um vídeo profissional pra você.
            </p>

            <textarea class="form-control mb-2" id="vdPrompt" rows="3" maxlength="500">${EchoUIInstance.escapeHTML(promptInicial)}</textarea>

            <button class="btn btn-primary rounded-pill px-4" type="button" id="vdGerar">
                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Gerar vídeo
            </button>
            <p class="echo-acoes-nota">
                <i class="fa-regular fa-clock"></i>
                Leva cerca de 30 a 60 segundos. Você pode fechar e voltar depois.
            </p>
            <div class="echo-erro" id="vdErro"></div>
        </div>`;

    document.getElementById("vdGerar").addEventListener("click", gerarVideo);
}

function desenharVideoGerando() {
    const box = document.getElementById("eLVideo");

    box.innerHTML = `
        <div class="echo-video-card">
            <h5><i class="fa-solid fa-clapperboard me-1"></i>Vídeo de apresentação</h5>
            <div class="echo-video-progresso">
                <div class="echo-video-progresso-barra"></div>
            </div>
            <p class="echo-ajuda mb-0">
                Gerando seu vídeo... pode levar até 60 segundos.
            </p>
        </div>`;
}

function desenharVideoPronto(video) {
    const box = document.getElementById("eLVideo");

    box.innerHTML = `
        <div class="echo-video-card">
            <h5><i class="fa-solid fa-clapperboard me-1"></i>Vídeo de apresentação</h5>
            <video class="echo-video-player" src="${videoSrc(video.arquivo)}"
                   autoplay muted loop playsinline></video>
            <button class="btn btn-outline-primary rounded-pill px-4 mt-2" type="button" id="vdRegenerar">
                <i class="fa-solid fa-arrows-rotate me-1"></i>Regenerar
            </button>
        </div>`;

    document.getElementById("vdRegenerar").addEventListener("click", () => {
        desenharVideoForm(videoPromptSugerido());
    });
}

function desenharVideoErro(mensagem) {
    const box = document.getElementById("eLVideo");

    box.innerHTML = `
        <div class="echo-video-card">
            <h5><i class="fa-solid fa-clapperboard me-1"></i>Vídeo de apresentação</h5>
            <p class="text-danger small">${EchoUIInstance.escapeHTML(mensagem || "Não conseguimos gerar o vídeo agora.")}</p>
            <button class="btn btn-primary rounded-pill px-4" type="button" id="vdTentarDeNovo">
                Tentar de novo
            </button>
        </div>`;

    document.getElementById("vdTentarDeNovo").addEventListener("click", () => {
        desenharVideoForm(videoPromptSugerido());
    });
}

async function gerarVideo() {
    erro("vdErro", "");

    const prompt = document.getElementById("vdPrompt").value.trim();

    if (!prompt) { erro("vdErro", "Descreva o vídeo antes de gerar."); return; }

    const btn = document.getElementById("vdGerar");
    btn.disabled = true;

    try {
        const r = await fetch("api/video/gerar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ prompt }),
        });
        const d = await r.json();

        if (d.error) { erro("vdErro", d.error); btn.disabled = false; return; }

        desenharVideoGerando();
        iniciarPollVideo(d.video_id);
    } catch (e) {
        erro("vdErro", "Erro de conexão.");
        btn.disabled = false;
    }
}

function iniciarPollVideo(videoId) {
    pararPollVideo();

    videoPollTimer = setInterval(async () => {
        // Sem aba visível, sem poll: ninguém está olhando a barra andar.
        if (document.hidden) return;

        try {
            const r = await fetch(`api/video/status.php?video_id=${videoId}`, { credentials: "same-origin" });
            const d = await r.json();

            if (!d.ok) { pararPollVideo(); desenharVideoErro(d.error); return; }

            if (d.status === "pronto") {
                pararPollVideo();
                desenharVideoPronto({ arquivo: d.arquivo });
            } else if (d.status === "erro") {
                pararPollVideo();
                desenharVideoErro(d.erro);
            }
            // 'gerando': segue no poll.
        } catch (e) {
            // Falha de rede num poll não é motivo pra desistir — tenta de
            // novo no próximo tick.
        }
    }, 3000);
}

function pararPollVideo() {
    if (videoPollTimer) {
        clearInterval(videoPollTimer);
        videoPollTimer = null;
    }
}

/* ---------------------------- produtos ---------------------------- */

function alternarFormProduto() {
    const box = document.getElementById("eLFormProduto");

    if (!box.hidden) { box.hidden = true; return; }

    box.innerHTML = `
        <div class="echo-form-inline" data-enter="#pdSalvar">
            <div class="echo-form-inline-titulo">
                <i class="fa-solid fa-box"></i>Novo produto
            </div>

            <input type="text" class="form-control mb-1" id="pdNome" maxlength="200"
                   placeholder="Ex.: Pão francês">
            <div class="echo-exemplos mb-2" data-alvo="pdNome">
                <span class="echo-exemplos-rotulo">Exemplos</span>
                <button type="button" class="echo-exemplo">Pão francês</button>
                <button type="button" class="echo-exemplo">Bolo de cenoura (fatia)</button>
                <button type="button" class="echo-exemplo">Café coado 300ml</button>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <input type="text" class="form-control" id="pdPreco"
                           placeholder="Preço — ex.: 0,90">
                </div>
                <div class="col-6">
                    <label class="echo-upload w-100 m-0">
                        <i class="fa-solid fa-image"></i><span>Foto</span>
                        <input type="file" id="pdImagem" accept="image/*" hidden>
                    </label>
                </div>
            </div>
            <textarea class="form-control mb-1" id="pdDescricao" rows="2" maxlength="2000"
                      placeholder="Ex.: Quentinho, saído do forno de hora em hora."></textarea>
            <div class="echo-exemplos mb-2" data-alvo="pdDescricao">
                <span class="echo-exemplos-rotulo">Diga</span>
                <button type="button" class="echo-exemplo"
                        data-texto="Quentinho, saído do forno de hora em hora.">Como é</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Serve duas pessoas. Vem em pacote de 500g.">Tamanho</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Encomenda com 1 dia de antecedência.">Prazo</button>
            </div>
            <button class="btn btn-primary rounded-pill px-4" type="button" id="pdSalvar">
                Salvar produto
            </button>
            <div class="echo-erro" id="pdErro"></div>
        </div>`;

    box.hidden = false;
    document.getElementById("pdSalvar").addEventListener("click", salvarProduto);
}

async function salvarProduto() {
    erro("pdErro", "");

    const nome = document.getElementById("pdNome").value.trim();

    if (!nome) { erro("pdErro", "Dê um nome ao produto."); return; }

    const fd = new FormData();
    fd.append("nome", nome);
    fd.append("preco", document.getElementById("pdPreco").value.trim());
    fd.append("descricao", document.getElementById("pdDescricao").value.trim());

    const img = document.getElementById("pdImagem").files[0];
    if (img) fd.append("imagem", img);

    try {
        const r = await fetch("api/lojas/produto_criar.php", {
            method: "POST", credentials: "same-origin", body: fd,
        });
        const d = await r.json();

        if (d.error) { erro("pdErro", d.error); return; }

        document.getElementById("eLFormProduto").hidden = true;
        EchoUIInstance.toastSuccess("Produto adicionado.");
        carregarProdutos();
    } catch (e) {
        erro("pdErro", "Erro de conexão.");
    }
}

async function carregarProdutos() {
    const box = document.getElementById("eLProdutos");

    try {
        const r = await fetch("api/lojas/produtos.php", { credentials: "same-origin" });
        const d = await r.json();

        if (!d.ok || !d.produtos.length) {
            box.innerHTML = `<p class="text-secondary small mb-0">
                Nenhum produto ainda. O agente só oferece o que estiver aqui.</p>`;
            return;
        }

        box.innerHTML = d.produtos.map(p => `
            <div class="echo-produto" data-id="${p.id}">
                <div class="echo-produto-foto">
                    ${p.imagem
                        ? `<img src="uploads/${encodeURIComponent(p.imagem)}" alt="">`
                        : `<i class="fa-solid fa-box"></i>`}
                </div>
                <div class="echo-produto-info">
                    <strong>${EchoUIInstance.escapeHTML(p.nome)}</strong>
                    <span class="echo-produto-preco">${p.preco !== null ? moeda(p.preco) : "sob consulta"}</span>
                    <span class="echo-badge ${p.disponivel ? "echo-badge-ok" : "echo-badge-off"}">
                        ${p.disponivel ? "Disponível" : "Indisponível"}
                    </span>
                </div>
                <div class="echo-produto-acoes">
                    <button class="btn btn-sm btn-outline-secondary rounded-pill" data-acao="toggle"
                            title="${p.disponivel ? "Marcar indisponível" : "Marcar disponível"}">
                        <i class="fa-solid ${p.disponivel ? "fa-eye" : "fa-eye-slash"}"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger rounded-pill" data-acao="apagar" title="Remover">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>`).join("");

        box.querySelectorAll(".echo-produto").forEach(el => {
            const id = parseInt(el.dataset.id, 10);
            const p  = d.produtos.find(x => x.id === id);

            el.querySelector('[data-acao="toggle"]').addEventListener("click", () => togglarProduto(p));
            el.querySelector('[data-acao="apagar"]').addEventListener("click", () => apagarProduto(p));
        });
    } catch (e) {
        box.innerHTML = `<p class="text-secondary small mb-0">Não foi possível carregar os produtos.</p>`;
    }
}

function moeda(v) {
    return "R$ " + Number(v).toFixed(2).replace(".", ",");
}

async function togglarProduto(p) {
    const fd = new FormData();
    fd.append("produto_id", p.id);
    fd.append("disponivel", p.disponivel ? "0" : "1");

    try {
        const r = await fetch("api/lojas/produto_editar.php", {
            method: "POST", credentials: "same-origin", body: fd,
        });
        const d = await r.json();

        if (d.error) { EchoUIInstance.toastError(d.error); return; }
        carregarProdutos();
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão.");
    }
}

async function apagarProduto(p) {
    const ok = await EchoUIInstance.confirm({
        title: `Remover "${p.nome}" do catálogo?`,
        message: "O agente deixa de oferecer esse produto. Os posts que falaram "
            + "dele continuam no feed, com as curtidas e comentários que já têm.",
        confirmText: "Remover",
        danger: true,
    });

    if (!ok) return;

    try {
        const r = await fetch("api/lojas/produto_apagar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ produto_id: p.id }),
        });
        const d = await r.json();

        if (d.error) { EchoUIInstance.toastError(d.error); return; }

        EchoUIInstance.toastSuccess("Produto removido.");
        carregarProdutos();
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão.");
    }
}

/* ---------------------------- posts da loja ---------------------------- */

function carregarPostsDaLoja() {
    const novo = document.getElementById("eLNovoPost");

    novo.innerHTML = `
        <div class="echo-form-inline mb-3" data-enter="#poSalvar">
            <div class="echo-form-inline-titulo">
                <i class="fa-solid fa-bullhorn"></i>Publicar no comércio
            </div>

            <textarea class="form-control mb-1" id="poConteudo" rows="3" maxlength="3000"
                      placeholder="Ex.: Bolo de cenoura saindo do forno agora, com cobertura de brigadeiro."></textarea>
            <div class="echo-exemplos mb-2" data-alvo="poConteudo">
                <span class="echo-exemplos-rotulo">Ideias</span>
                <button type="button" class="echo-exemplo"
                        data-texto="Bolo de cenoura saindo do forno agora, com cobertura de brigadeiro.">Novidade do dia</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Hoje até as 18h: na compra de dois pães de queijo, o terceiro é nosso.">Promoção</button>
                <button type="button" class="echo-exemplo"
                        data-texto="Amanhã abrimos mais cedo, às 5h30.">Aviso</button>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <select class="form-select" id="poTipo">
                        <option value="produto">Produto</option>
                        <option value="promocao">Promoção</option>
                        <option value="novidade">Novidade</option>
                        <option value="info">Info</option>
                    </select>
                </div>
                <div class="col-6">
                    <input type="text" class="form-control" id="poPreco" placeholder="Preço (opcional)">
                </div>
            </div>
            <label class="echo-upload w-100 mb-2">
                <i class="fa-solid fa-image"></i><span>Foto do post</span>
                <input type="file" id="poImagem" accept="image/*" hidden>
            </label>
            <button class="btn btn-primary rounded-pill px-4" type="button" id="poSalvar">
                Publicar no comércio
            </button>
            <div class="echo-erro" id="poErro"></div>
        </div>`;

    document.getElementById("poSalvar").addEventListener("click", publicarPost);

    listarPostsDaLoja();
}

async function publicarPost() {
    erro("poErro", "");

    const conteudo = document.getElementById("poConteudo").value.trim();

    if (!conteudo) { erro("poErro", "Escreva alguma coisa."); return; }

    const fd = new FormData();
    fd.append("conteudo", conteudo);
    fd.append("tipo", document.getElementById("poTipo").value);
    fd.append("preco", document.getElementById("poPreco").value.trim());

    const img = document.getElementById("poImagem").files[0];
    if (img) fd.append("imagem", img);

    try {
        const r = await fetch("api/lojas/post_criar.php", {
            method: "POST", credentials: "same-origin", body: fd,
        });
        const d = await r.json();

        if (d.error) { erro("poErro", d.error); return; }

        document.getElementById("poConteudo").value = "";
        document.getElementById("poPreco").value = "";
        EchoUIInstance.toastSuccess("Publicado no comércio.");
        listarPostsDaLoja();
    } catch (e) {
        erro("poErro", "Erro de conexão.");
    }
}

async function listarPostsDaLoja() {
    const box = document.getElementById("eLPosts");

    if (!estado.loja) return;

    try {
        const r = await fetch(`api/lojas/feed.php?loja_id=${estado.loja.loja.id}&limit=20`,
                              { credentials: "same-origin" });
        const d = await r.json();

        if (!d.ok || !d.posts.length) {
            box.innerHTML = `<p class="text-secondary small mb-0">Você ainda não publicou nada.</p>`;
            return;
        }

        box.innerHTML = d.posts.map(p => `
            <div class="echo-meu-post">
                <div class="echo-meu-post-topo">
                    <span class="echo-badge echo-badge-${p.tipo}">${rotuloPost(p.tipo)}</span>
                    <small>${EchoUIInstance.formatTime(p.created_at)}</small>
                </div>
                <p>${EchoUIInstance.escapeHTML(p.conteudo)}</p>
                <small class="text-secondary">
                    <i class="fa-regular fa-heart"></i> ${p.curtidas}
                    &nbsp;<i class="fa-regular fa-comment"></i> ${p.comentarios}
                </small>
            </div>`).join("");
    } catch (e) {
        box.innerHTML = "";
    }
}

function rotuloPost(t) {
    return { produto: "Produto", promocao: "Promoção", novidade: "Novidade", info: "Info" }[t] || t;
}
