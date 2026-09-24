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

   CONTRA A POLUIÇÃO: o desenho é relativo ao tamanho (W/H), então formato
   novo não exige recalcular coordenada na mão; e os controles ficam em
   seções numeradas curtas, cada campo com um exemplo no placeholder.
   ====================================================================== */

// Formatos da peça. O desenho é proporcional, então trocar aqui basta.
const FORMATOS = [
    { id: "feed",  nome: "Feed 1:1",   w: 1080, h: 1080 },
    { id: "story", nome: "Story 9:16", w: 1080, h: 1920 },
    { id: "capa",  nome: "Capa 16:9",  w: 1280, h: 720  },
];

// Templates por contexto. Cada um sabe se usa preço e como o feed vai
// classificar o post (o `tipo` do loja_posts).
const TEMPLATES = {
    pessoal: [
        { id: "frase",   nome: "Frase",   icone: "fa-quote-left", preco: false },
        { id: "momento", nome: "Momento", icone: "fa-image",      preco: false },
    ],
    loja: [
        { id: "produto",  nome: "Produto",  icone: "fa-box",         preco: true,  tipo: "produto"  },
        { id: "promocao", nome: "Promoção", icone: "fa-tag",         preco: true,  tipo: "promocao" },
        { id: "novidade", nome: "Novidade", icone: "fa-star",        preco: false, tipo: "novidade" },
        { id: "info",     nome: "Recado",   icone: "fa-circle-info", preco: false, tipo: "info"     },
    ],
};

// Filtros aplicados à foto base. `css` entra em ctx.filter antes de desenhar.
const FILTROS = [
    { id: "original",  nome: "Original",  css: "none" },
    { id: "escurecer", nome: "Escurecer", css: "brightness(0.6)" },
    { id: "pb",        nome: "P&B",       css: "grayscale(1)" },
    { id: "vivo",      nome: "Vívido",    css: "saturate(1.5) contrast(1.1)" },
];

// Temas de cor. Mudam o acento (rótulo/preço), a cor do texto e o fundo
// quando não há foto. Só famílias web-safe nas fontes, para o canvas não
// depender de download assíncrono e desenhar na hora.
const TEMAS = {
    pessoal: [
        { id: "marca",  nome: "Marca",  acento: "#1d9bf0", texto: "#fff", bg1: "#1d9bf0", bg2: "#0a0a0a" },
        { id: "noite",  nome: "Noite",  acento: "#7c5cff", texto: "#fff", bg1: "#241b4d", bg2: "#000"    },
        { id: "claro",  nome: "Claro",  acento: "#1d9bf0", texto: "#111", bg1: "#f5f5f5", bg2: "#d9d9d9" },
    ],
    loja: [
        { id: "vitrine", nome: "Vitrine", acento: "#f45d22", texto: "#fff", bg1: "#f45d22", bg2: "#2a0a00" },
        { id: "verde",   nome: "Fresco",  acento: "#17bf63", texto: "#fff", bg1: "#0f5132", bg2: "#000"    },
        { id: "claro",   nome: "Claro",   acento: "#f45d22", texto: "#111", bg1: "#faf6f2", bg2: "#e7ddd4" },
    ],
};

const FONTES = [
    { id: "padrao",   nome: "Padrão",   familia: "system-ui, -apple-system, sans-serif" },
    { id: "serifada", nome: "Serifada", familia: "Georgia, 'Times New Roman', serif" },
    { id: "forte",    nome: "Forte",    familia: "'Arial Black', 'Impact', sans-serif" },
    { id: "maquina",  nome: "Máquina",  familia: "'Courier New', monospace" },
];

const estado = {
    ctx: "pessoal",
    formato: FORMATOS[0],
    template: null,
    filtro: FILTROS[0],
    tema: null,
    fonte: FONTES[0],
    imagem: null,
    loja: null,
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

    try {
        const r = await fetch("api/lojas/perfil.php", { credentials: "same-origin" });
        const d = await r.json();
        if (d.ok) { estado.loja = d.loja; estado.temLoja = true; document.getElementById("ctxLoja").hidden = false; }
    } catch (e) { /* sem loja: segue só pessoal */ }

    document.querySelectorAll(".canvas-ctx").forEach(b =>
        b.addEventListener("click", () => trocarContexto(b.dataset.ctx)));

    ["campoChamada", "campoSub", "campoRotulo", "campoPreco"].forEach(id =>
        document.getElementById(id).addEventListener("input", desenhar));

    document.getElementById("campoFonte").addEventListener("change", e => {
        estado.fonte = FONTES.find(f => f.id === e.target.value) || FONTES[0];
        desenhar();
    });

    document.getElementById("canvasUpload").addEventListener("change", aoEnviarFoto);
    document.getElementById("btnBaixar").addEventListener("click", baixar);
    document.getElementById("btnPublicar").addEventListener("click", publicar);
    // O botão de vídeo abre o painel do MOTOR DE ANÚNCIOS (js/canvas-video.js):
    // modelo + formato + campos + foto -> render local ($0 de API). O fluxo
    // antigo por prompt (gerarVideo/acompanharVideo, abaixo) fica de reserva.
    document.getElementById("btnVideo").addEventListener("click", () => {
        if (window.MotorVideo) window.MotorVideo.abrir(estado.loja);
        else gerarVideo();
    });

    desenharFormatos();
    desenharFiltros();
    montarFontes();
    trocarContexto("pessoal");
});

function logout() { EchoUIInstance.logout(); }

/* ---------------------------------------------------------------- contexto */

function trocarContexto(c) {
    estado.ctx = c;
    estado.template = null;
    estado.tema = TEMAS[c][0];

    document.querySelectorAll(".canvas-ctx").forEach(b =>
        b.classList.toggle("ativo", b.dataset.ctx === c));
    document.documentElement.style.setProperty("--canvas-acento", estado.tema.acento);

    const ehLoja = c === "loja";
    document.getElementById("campoPreco").hidden = !ehLoja;
    document.getElementById("btnVideo").hidden    = !ehLoja;

    document.getElementById("canvasIntro").textContent = ehLoja
        ? "Marketing da sua loja: produto, promoção, novidade. Publica no feed de comércio."
        : "Sua peça pessoal: uma frase, um momento. Publica no seu feed.";

    // Exemplos mudam com o contexto — mostram como usar cada campo.
    document.getElementById("campoChamada").placeholder = ehLoja
        ? "Ex.: Feijoada aos sábados" : "Ex.: Bom dia, rede!";
    document.getElementById("campoSub").placeholder = ehLoja
        ? "Ex.: peça pelo WhatsApp até 11h" : "Ex.: mais um dia de correria";
    document.getElementById("campoRotulo").placeholder = ehLoja ? "Ex.: OFERTA" : "Ex.: NOVO";

    desenharTemplates();
    desenharTemas();
    carregarFontes();
    desenhar();
}

/* ---------------------------------------------------------------- chips */

function desenharFormatos() {
    const box = document.getElementById("canvasFormatos");
    box.innerHTML = FORMATOS.map((f, i) => `
        <button type="button" class="canvas-chip ${i === 0 ? "ativo" : ""}" data-fmt="${f.id}">${EchoUIInstance.escapeHTML(f.nome)}</button>`).join("");
    box.querySelectorAll("[data-fmt]").forEach(b =>
        b.addEventListener("click", () => {
            box.querySelectorAll("[data-fmt]").forEach(x => x.classList.remove("ativo"));
            b.classList.add("ativo");
            estado.formato = FORMATOS.find(f => f.id === b.dataset.fmt);
            desenhar();
        }));
}

function desenharFiltros() {
    const box = document.getElementById("canvasFiltros");
    box.innerHTML = FILTROS.map((f, i) => `
        <button type="button" class="canvas-chip ${i === 0 ? "ativo" : ""}" data-flt="${f.id}">${EchoUIInstance.escapeHTML(f.nome)}</button>`).join("");
    box.querySelectorAll("[data-flt]").forEach(b =>
        b.addEventListener("click", () => {
            box.querySelectorAll("[data-flt]").forEach(x => x.classList.remove("ativo"));
            b.classList.add("ativo");
            estado.filtro = FILTROS.find(f => f.id === b.dataset.flt);
            desenhar();
        }));
}

function desenharTemas() {
    const box = document.getElementById("canvasTemas");
    const lista = TEMAS[estado.ctx];
    box.innerHTML = lista.map((t, i) => `
        <button type="button" class="canvas-tema ${i === 0 ? "ativo" : ""}" data-tema="${t.id}"
                title="${EchoUIInstance.escapeHTML(t.nome)}"
                style="background:linear-gradient(135deg, ${t.bg1}, ${t.bg2})">
            <span style="background:${t.acento}"></span>
        </button>`).join("");
    box.querySelectorAll("[data-tema]").forEach(b =>
        b.addEventListener("click", () => {
            box.querySelectorAll("[data-tema]").forEach(x => x.classList.remove("ativo"));
            b.classList.add("ativo");
            estado.tema = lista.find(t => t.id === b.dataset.tema);
            document.documentElement.style.setProperty("--canvas-acento", estado.tema.acento);
            desenhar();
        }));
}

function montarFontes() {
    document.getElementById("campoFonte").innerHTML =
        FONTES.map(f => `<option value="${f.id}">${EchoUIInstance.escapeHTML(f.nome)}</option>`).join("");
}

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

/* ---------------------------------------------------------------- fontes de imagem */

async function carregarFontes() {
    const box = document.getElementById("canvasFontes");
    box.innerHTML = `<button type="button" class="canvas-fonte" data-fonte="upload">
        <i class="fa-solid fa-upload"></i><span>Enviar foto</span></button>`;
    box.querySelector('[data-fonte="upload"]')
       .addEventListener("click", () => document.getElementById("canvasUpload").click());

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
    carregarImagem(URL.createObjectURL(file));
}

function carregarImagem(src) {
    const img = new Image();
    img.onload  = () => { estado.imagem = img; desenhar(); };
    img.onerror = () => { estado.imagem = null; desenhar(); };
    img.src = src;
}

/* ---------------------------------------------------------------- desenho */

function desenhar() {
    if (!canvas) return;

    const W = estado.formato.w, H = estado.formato.h;
    if (canvas.width !== W || canvas.height !== H) { canvas.width = W; canvas.height = H; }

    const tema = estado.tema, acento = tema.acento, corTexto = tema.texto;
    const menor = Math.min(W, H);
    const margem = Math.round(menor * 0.055);

    ctx2d.clearRect(0, 0, W, H);

    // Fundo: foto (com o filtro escolhido) cobrindo, ou gradiente do tema.
    if (estado.imagem) {
        ctx2d.filter = estado.filtro.css;
        desenharCobrindo(estado.imagem, W, H);
        ctx2d.filter = "none";
    } else {
        const g = ctx2d.createLinearGradient(0, 0, W, H);
        g.addColorStop(0, tema.bg1);
        g.addColorStop(1, tema.bg2);
        ctx2d.fillStyle = g;
        ctx2d.fillRect(0, 0, W, H);
    }

    const tpl = estado.template || {};
    const chamada = document.getElementById("campoChamada").value.trim();
    const sub     = document.getElementById("campoSub").value.trim();
    const rotulo  = document.getElementById("campoRotulo").value.trim();
    const preco   = document.getElementById("campoPreco").value.trim();
    const fam     = estado.fonte.familia;

    // Véu de contraste embaixo — só quando há foto e o tema é escuro. No
    // tema claro sobre foto, o texto ganha sombra em vez de véu preto.
    const temaClaro = corTexto === "#111";
    if (estado.imagem && !temaClaro) {
        const veu = ctx2d.createLinearGradient(0, H * 0.45, 0, H);
        veu.addColorStop(0, "rgba(0,0,0,0)");
        veu.addColorStop(1, "rgba(0,0,0,0.82)");
        ctx2d.fillStyle = veu;
        ctx2d.fillRect(0, 0, W, H);
    }

    // Rótulo (faixa) no topo.
    if (rotulo) {
        const fs = Math.round(menor * 0.038);
        ctx2d.font = `700 ${fs}px ${fam}`;
        const txt = rotulo.toUpperCase();
        const w = ctx2d.measureText(txt).width + fs * 1.3;
        const h = fs * 1.8;
        ctx2d.fillStyle = acento;
        arredondado(margem, margem, w, h, h / 2);
        ctx2d.fill();
        ctx2d.fillStyle = "#fff";
        ctx2d.textBaseline = "middle";
        ctx2d.fillText(txt, margem + fs * 0.65, margem + h / 2);
    }

    // Bloco de texto ancorado embaixo: chamada (grande) + subtítulo (menor).
    ctx2d.textBaseline = "alphabetic";
    const somberaSeClaroSobreFoto = () => {
        if (temaClaro && estado.imagem) { ctx2d.shadowColor = "rgba(255,255,255,0.6)"; ctx2d.shadowBlur = menor * 0.02; }
        else ctx2d.shadowBlur = 0;
    };

    const precoAltura = (estado.ctx === "loja" && tpl.preco && preco) ? menor * 0.09 : 0;
    let baseY = H - margem - precoAltura;

    if (sub) {
        const fs = Math.round(menor * 0.036);
        ctx2d.font = `600 ${fs}px ${fam}`;
        ctx2d.fillStyle = corTexto === "#111" ? "#333" : "rgba(255,255,255,0.9)";
        somberaSeClaroSobreFoto();
        const linhas = quebrar(sub, W - margem * 2, ctx2d.font);
        for (let i = linhas.length - 1; i >= 0; i--) { ctx2d.fillText(linhas[i], margem, baseY); baseY -= fs * 1.25; }
        ctx2d.shadowBlur = 0;
        baseY -= fs * 0.4;
    }

    if (chamada) {
        const fs = Math.round(menor * 0.066);
        ctx2d.font = `800 ${fs}px ${fam}`;
        ctx2d.fillStyle = corTexto;
        somberaSeClaroSobreFoto();
        const linhas = quebrar(chamada, W - margem * 2, ctx2d.font);
        for (let i = linhas.length - 1; i >= 0; i--) { ctx2d.fillText(linhas[i], margem, baseY); baseY -= fs * 1.2; }
        ctx2d.shadowBlur = 0;
    }

    // Preço — só na loja.
    if (estado.ctx === "loja" && tpl.preco && preco) {
        const fs = Math.round(menor * 0.08);
        ctx2d.font = `800 ${fs}px ${fam}`;
        ctx2d.fillStyle = acento;
        ctx2d.fillText(preco, margem, H - margem);
    }

    // Assinatura no topo direito: nome da loja (marketing) ou @handle.
    const assinatura = estado.ctx === "loja" && estado.loja
        ? estado.loja.nome
        : "@" + (EchoUIInstance.currentUser?.email?.split("@")[0] || "echo");
    const fsA = Math.round(menor * 0.032);
    ctx2d.font = `600 ${fsA}px ${fam}`;
    ctx2d.fillStyle = corTexto === "#111" ? "rgba(0,0,0,0.7)" : "rgba(255,255,255,0.85)";
    ctx2d.textAlign = "right";
    ctx2d.fillText(assinatura, W - margem, margem + fsA);
    ctx2d.textAlign = "left";
}

function desenharCobrindo(img, W, H) {
    const escala = Math.max(W / img.width, H / img.height);
    const w = img.width * escala, h = img.height * escala;
    ctx2d.fillStyle = "#000";
    ctx2d.fillRect(0, 0, W, H);
    ctx2d.drawImage(img, (W - w) / 2, (H - h) / 2, w, h);
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
    return linhas.slice(0, 5);
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

function pecaBlob() { return new Promise(resolve => canvas.toBlob(resolve, "image/png")); }

async function baixar() {
    const blob = await pecaBlob();
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = `echo-canvas-${estado.ctx}-${estado.formato.id}-${Date.now()}.png`;
    a.click();
    URL.revokeObjectURL(a.href);
}

async function publicar() {
    const chamada = document.getElementById("campoChamada").value.trim();
    const sub     = document.getElementById("campoSub").value.trim();
    if (!chamada) { aviso("Escreva ao menos a chamada antes de publicar.", true); return; }

    // O texto do post junta chamada + subtítulo — a imagem é a peça.
    const texto = sub ? `${chamada}\n${sub}` : chamada;

    const btn = document.getElementById("btnPublicar");
    btn.disabled = true;

    try {
        const blob = await pecaBlob();
        const fd = new FormData();
        let endpoint;

        if (estado.ctx === "loja") {
            endpoint = "api/lojas/post_criar.php";
            fd.append("conteudo", texto);
            fd.append("tipo", estado.template?.tipo || "info");
            const preco = document.getElementById("campoPreco").value.trim().replace(/[^\d.,]/g, "");
            if (preco) fd.append("preco", preco);
            fd.append("imagem", blob, "canvas.png");
        } else {
            endpoint = "api/posts/create.php";
            fd.append("content", texto);
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
