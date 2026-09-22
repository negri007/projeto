/* ======================================================================
   CANVAS — transforma conteúdo em peça de marketing.

   A LINHA QUE SEPARA OS DOIS CONTEXTOS é o eixo do arquivo. "Pessoal" e
   "Loja" não são só dois conjuntos de templates: são dois destinos e dois
   tons. O pessoal fala do próprio usuário (uma frase, um momento) e publica
   no feed de gente (posts/create.php). O da loja é marketing (produto,
   preço, promoção, slogan), publica no feed de comércio (lojas/post_criar.php)
   e é o único que gera vídeo. Trocar de aba troca as fontes, os modelos, os
   campos visíveis e o endpoint de publicação — de propósito.

   A COMPOSIÇÃO É CLIENT-SIDE. O <canvas> desenha a foto, a moldura do
   template e os textos; a peça sai por toBlob() sem tocar no servidor. Sem
   API, sem custo, sem espera. O servidor só entra quando a pessoa PUBLICA
   (o PNG vira upload, com a mesma validação de MIME de qualquer imagem) ou
   pede VÍDEO (aí sim gasta as APIs de vídeo).
   ====================================================================== */

const TAM = 1080; // peça quadrada 1080x1080 — o formato do feed

// Templates por contexto. Cada um sabe se usa preço e como o feed vai
// classificar o post (o `tipo` do loja_posts).
const TEMPLATES = {
    pessoal: [
        { id: "frase",   nome: "Frase",   icone: "fa-quote-left", preco: false },
        { id: "momento", nome: "Momento", icone: "fa-image",      preco: false },
    ],
    loja: [
        { id: "produto",  nome: "Produto",  icone: "fa-box",          preco: true,  tipo: "produto"  },
        { id: "promocao", nome: "Promoção", icone: "fa-tag",          preco: true,  tipo: "promocao" },
        { id: "novidade", nome: "Novidade", icone: "fa-star",         preco: false, tipo: "novidade" },
        { id: "info",     nome: "Recado",   icone: "fa-circle-info",  preco: false, tipo: "info"     },
    ],
};

// Cor de acento por contexto: o pessoal puxa o azul da marca; a loja, um
// tom quente de vitrine. Ajuda a pessoa a saber em que aba está sem ler.
const ACENTO = { pessoal: "#1d9bf0", loja: "#f45d22" };

const estado = {
    ctx: "pessoal",
    template: null,
    imagem: null,       // HTMLImageElement da foto base, ou null
    loja: null,         // dados da loja do usuário, ou null
    temLoja: false,
};

let canvas, ctx2d;

document.addEventListener("DOMContentLoaded", async function () {
    const user = await EchoUIInstance.checkAuth({ redirectOnFail: true });
    if (!user) return;

    document.getElementById("notificationBellContainer").innerHTML =
        EchoUIInstance.getNotificationBellHTML();
    EchoUIInstance.initHeader("canvas");

    canvas = document.getElementById("canvasPeca");
    ctx2d  = canvas.getContext("2d");

    // A aba Loja só existe se a pessoa tem loja. Sem loja, o Canvas é só
    // pessoal — não faz sentido oferecer marketing de uma loja que não há.
    try {
        const r = await fetch("api/lojas/perfil.php", { credentials: "same-origin" });
        const d = await r.json();
        if (d.ok) { estado.loja = d.loja; estado.temLoja = true; document.getElementById("ctxLoja").hidden = false; }
    } catch (e) { /* sem loja: segue só pessoal */ }

    document.querySelectorAll(".canvas-ctx").forEach(b =>
        b.addEventListener("click", () => trocarContexto(b.dataset.ctx)));

    ["campoChamada", "campoRotulo", "campoPreco"].forEach(id =>
        document.getElementById(id).addEventListener("input", desenhar));

    document.getElementById("canvasUpload").addEventListener("change", aoEnviarFoto);
    document.getElementById("btnBaixar").addEventListener("click", baixar);
    document.getElementById("btnPublicar").addEventListener("click", publicar);
    document.getElementById("btnVideo").addEventListener("click", gerarVideo);

    trocarContexto("pessoal");
});

function logout() { EchoUIInstance.logout(); }

/* ---------------------------------------------------------------- contexto */

function trocarContexto(ctx) {
    estado.ctx = ctx;
    estado.template = null;

    document.querySelectorAll(".canvas-ctx").forEach(b =>
        b.classList.toggle("ativo", b.dataset.ctx === ctx));
    document.documentElement.style.setProperty("--canvas-acento", ACENTO[ctx]);

    // Campos e botões que só fazem sentido num dos lados.
    const ehLoja = ctx === "loja";
    document.getElementById("campoPreco").hidden = !ehLoja;
    document.getElementById("btnVideo").hidden   = !ehLoja;

    document.getElementById("canvasIntro").textContent = ehLoja
        ? "Marketing da sua loja: produto, promoção, novidade. Publica no feed de comércio."
        : "Sua peça pessoal: uma frase, um momento. Publica no seu feed.";

    desenharTemplates();
    carregarFontes();
    desenhar();
}

/* ---------------------------------------------------------------- templates */

function desenharTemplates() {
    const box = document.getElementById("canvasTemplates");
    const lista = TEMPLATES[estado.ctx];

    box.innerHTML = lista.map((t, i) => `
        <button type="button" class="canvas-template ${i === 0 ? "ativo" : ""}" data-tpl="${t.id}">
            <i class="fa-solid ${t.icone}"></i><span>${EchoUIInstance.escapeHTML(t.nome)}</span>
        </button>`).join("");

    estado.template = lista[0];

    box.querySelectorAll(".canvas-template").forEach(b =>
        b.addEventListener("click", () => {
            box.querySelectorAll(".canvas-template").forEach(x => x.classList.remove("ativo"));
            b.classList.add("ativo");
            estado.template = lista.find(t => t.id === b.dataset.tpl);
            document.getElementById("campoPreco").hidden = !(estado.ctx === "loja" && estado.template.preco);
            desenhar();
        }));
}

/* ---------------------------------------------------------------- fontes */

async function carregarFontes() {
    const box = document.getElementById("canvasFontes");
    box.innerHTML = `<button type="button" class="canvas-fonte" data-fonte="upload">
        <i class="fa-solid fa-upload"></i><span>Enviar foto</span></button>`;

    box.querySelector('[data-fonte="upload"]')
       .addEventListener("click", () => document.getElementById("canvasUpload").click());

    // Na aba Loja, oferece os produtos do catálogo como fonte de foto —
    // é o atalho que faz "transformar em peça" virar um clique.
    if (estado.ctx === "loja" && estado.loja) {
        try {
            const r = await fetch(`api/lojas/produtos.php?loja_id=${estado.loja.id}`, { credentials: "same-origin" });
            const d = await r.json();
            (d.produtos || []).filter(p => p.imagem).slice(0, 8).forEach(p => {
                const el = document.createElement("button");
                el.type = "button";
                el.className = "canvas-fonte";
                el.innerHTML = `<span class="canvas-fonte-mini" style="background-image:url('uploads/${encodeURIComponent(p.imagem)}')"></span>
                                <span>${EchoUIInstance.escapeHTML(p.nome)}</span>`;
                el.addEventListener("click", () => {
                    carregarImagem(`uploads/${encodeURIComponent(p.imagem)}`);
                    document.getElementById("campoChamada").value = p.nome;
                    if (p.preco !== null) document.getElementById("campoPreco").value = moeda(p.preco);
                    desenhar();
                });
                box.appendChild(el);
            });
        } catch (e) { /* catálogo indisponível: fica só o upload */ }
    }
}

function aoEnviarFoto() {
    const file = this.files[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    carregarImagem(url);
}

function carregarImagem(src) {
    const img = new Image();
    // Fotos vêm de uploads/ (mesma origem) ou de object URL local — não
    // sujam o canvas, então toBlob() na publicação funciona.
    img.onload = () => { estado.imagem = img; desenhar(); };
    img.onerror = () => { estado.imagem = null; desenhar(); };
    img.src = src;
}

/* ---------------------------------------------------------------- desenho */

function desenhar() {
    if (!canvas) return;
    const acento = ACENTO[estado.ctx];

    // Fundo: a foto cobrindo tudo, ou um gradiente do acento quando não há foto.
    if (estado.imagem) {
        desenharCobrindo(estado.imagem);
    } else {
        const g = ctx2d.createLinearGradient(0, 0, TAM, TAM);
        g.addColorStop(0, acento);
        g.addColorStop(1, "#0a0a0a");
        ctx2d.fillStyle = g;
        ctx2d.fillRect(0, 0, TAM, TAM);
    }

    const tpl = estado.template || {};
    const chamada = document.getElementById("campoChamada").value.trim();
    const rotulo  = document.getElementById("campoRotulo").value.trim();
    const preco   = document.getElementById("campoPreco").value.trim();

    // Véu escuro embaixo, para o texto ter contraste sobre qualquer foto.
    const veu = ctx2d.createLinearGradient(0, TAM * 0.45, 0, TAM);
    veu.addColorStop(0, "rgba(0,0,0,0)");
    veu.addColorStop(1, "rgba(0,0,0,0.82)");
    ctx2d.fillStyle = veu;
    ctx2d.fillRect(0, 0, TAM, TAM);

    // Faixa do rótulo (OFERTA / NOVO / etc.) no topo.
    if (rotulo) {
        ctx2d.font = "700 42px system-ui, sans-serif";
        const w = ctx2d.measureText(rotulo.toUpperCase()).width + 56;
        ctx2d.fillStyle = acento;
        arredondado(56, 56, w, 74, 37);
        ctx2d.fill();
        ctx2d.fillStyle = "#fff";
        ctx2d.textBaseline = "middle";
        ctx2d.fillText(rotulo.toUpperCase(), 84, 56 + 39);
    }

    // Chamada principal, quebrada por palavra, ancorada embaixo.
    if (chamada) {
        ctx2d.fillStyle = "#fff";
        ctx2d.textBaseline = "alphabetic";
        const linhas = quebrar(chamada, TAM - 120, "800 68px system-ui, sans-serif");
        let y = TAM - 120 - (linhas.length - 1) * 82 - (preco ? 96 : 0);
        ctx2d.font = "800 68px system-ui, sans-serif";
        linhas.forEach(l => { ctx2d.fillText(l, 60, y); y += 82; });
    }

    // Preço — só na loja, destaque no acento.
    if (estado.ctx === "loja" && tpl.preco && preco) {
        ctx2d.font = "800 84px system-ui, sans-serif";
        ctx2d.fillStyle = acento;
        ctx2d.fillText(preco, 60, TAM - 70);
    }

    // Assinatura: nome da loja (marketing) ou @handle (pessoal). É a marca
    // da peça — o que diz de quem ela é.
    const assinatura = estado.ctx === "loja" && estado.loja
        ? estado.loja.nome
        : "@" + (EchoUIInstance.currentUser?.email?.split("@")[0] || "echo");
    ctx2d.font = "600 34px system-ui, sans-serif";
    ctx2d.fillStyle = "rgba(255,255,255,0.85)";
    ctx2d.textAlign = "right";
    ctx2d.fillText(assinatura, TAM - 60, 100);
    ctx2d.textAlign = "left";
}

function desenharCobrindo(img) {
    const escala = Math.max(TAM / img.width, TAM / img.height);
    const w = img.width * escala, h = img.height * escala;
    ctx2d.fillStyle = "#000";
    ctx2d.fillRect(0, 0, TAM, TAM);
    ctx2d.drawImage(img, (TAM - w) / 2, (TAM - h) / 2, w, h);
}

function quebrar(texto, larguraMax, fonte) {
    ctx2d.font = fonte;
    const palavras = texto.split(/\s+/);
    const linhas = [];
    let atual = "";
    palavras.forEach(p => {
        const teste = atual ? atual + " " + p : p;
        if (ctx2d.measureText(teste).width > larguraMax && atual) { linhas.push(atual); atual = p; }
        else atual = teste;
    });
    if (atual) linhas.push(atual);
    return linhas.slice(0, 4); // no máximo 4 linhas; peça não é parágrafo
}

function arredondado(x, y, w, h, r) {
    ctx2d.beginPath();
    ctx2d.moveTo(x + r, y);
    ctx2d.arcTo(x + w, y, x + w, y + h, r);
    ctx2d.arcTo(x + w, y + h, x, y + h, r);
    ctx2d.arcTo(x, y + h, x, y, r);
    ctx2d.arcTo(x, y, x + w, y, r);
    ctx2d.closePath();
}

function moeda(v) { return "R$ " + Number(v).toFixed(2).replace(".", ","); }

/* ---------------------------------------------------------------- saídas */

function pecaBlob() {
    return new Promise(resolve => canvas.toBlob(resolve, "image/png"));
}

async function baixar() {
    const blob = await pecaBlob();
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = `echo-canvas-${estado.ctx}-${Date.now()}.png`;
    a.click();
    URL.revokeObjectURL(a.href);
}

async function publicar() {
    const chamada = document.getElementById("campoChamada").value.trim();
    if (!chamada) { aviso("Escreva ao menos a chamada antes de publicar.", true); return; }

    const btn = document.getElementById("btnPublicar");
    btn.disabled = true;

    try {
        const blob = await pecaBlob();
        const fd = new FormData();

        let endpoint;
        if (estado.ctx === "loja") {
            // Feed de comércio: a chamada é o conteúdo, o template vira o
            // badge do post, e o preço vai junto quando existe.
            endpoint = "api/lojas/post_criar.php";
            fd.append("conteudo", chamada);
            fd.append("tipo", estado.template?.tipo || "info");
            const preco = document.getElementById("campoPreco").value.trim().replace(/[^\d.,]/g, "");
            if (preco) fd.append("preco", preco);
            fd.append("imagem", blob, "canvas.png");
        } else {
            // Feed de gente: o mesmo create.php de um post escrito à mão.
            endpoint = "api/posts/create.php";
            fd.append("content", chamada);
            fd.append("image", blob, "canvas.png");
        }

        const r = await fetch(endpoint, { method: "POST", credentials: "same-origin", body: fd });
        const d = await r.json();

        if (d.error) { aviso(d.error, true); return; }

        EchoUIInstance.toastSuccess("Peça publicada no feed.");
        aviso(estado.ctx === "loja" ? "Publicada no comércio." : "Publicada no seu feed.", false);
    } catch (e) {
        aviso("Não consegui publicar agora.", true);
    } finally {
        btn.disabled = false;
    }
}

/* ---------------------------------------------------------------- vídeo (loja) */

async function gerarVideo() {
    const chamada = document.getElementById("campoChamada").value.trim();
    const prompt = [estado.loja?.nome, chamada, document.getElementById("campoRotulo").value.trim()]
        .filter(Boolean).join(". ");

    if (!prompt) { aviso("Escreva a chamada para o vídeo ter sobre o que falar.", true); return; }

    const btn = document.getElementById("btnVideo");
    btn.disabled = true;
    aviso("Gerando vídeo… isso pode levar um tempo.", false);

    try {
        const r = await fetch("api/video/gerar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ prompt }),
        });
        const d = await r.json();

        // gerar.php aplica o próprio freio (1 vídeo por loja por hora) e a
        // moderação; quando recusa, a mensagem dele explica. Só repassamos.
        if (d.error) { aviso(d.error, true); return; }

        const videoId = d.video_id;
        if (!videoId) { aviso("Vídeo enfileirado, mas sem id de acompanhamento.", true); return; }

        acompanharVideo(videoId);
    } catch (e) {
        aviso("Não consegui iniciar o vídeo agora.", true);
    } finally {
        btn.disabled = false;
    }
}

async function acompanharVideo(id) {
    const inicio = Date.now();
    const poll = async () => {
        try {
            const r = await fetch(`api/video/status.php?video_id=${encodeURIComponent(id)}`, { credentials: "same-origin" });
            const d = await r.json();

            if (d.status === "pronto") {
                // Prefere o arquivo local (baixado do provider); cai na URL da
                // plataforma se o download ainda não materializou.
                const url = d.arquivo ? ("uploads/" + d.arquivo) : d.url_plataforma;
                aviso("Vídeo pronto! Abrindo em nova aba.", false);
                if (url) window.open(url, "_blank", "noopener");
                return;
            }
            if (d.status === "erro") { aviso("A geração do vídeo falhou.", true); return; }
            if (Date.now() - inicio > 5 * 60 * 1000) { aviso("O vídeo está demorando; confira mais tarde.", true); return; }

            setTimeout(poll, 4000);
        } catch (e) {
            aviso("Perdi o acompanhamento do vídeo.", true);
        }
    };
    poll();
}

/* ---------------------------------------------------------------- util */

function aviso(texto, erro) {
    const el = document.getElementById("canvasAviso");
    el.textContent = texto;
    el.classList.toggle("erro", !!erro);
    el.hidden = false;
}
