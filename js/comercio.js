/* ======================================================================
   FEED DE COMÉRCIO — a tela.

   O feed em si é o `LojaFeed` (js/loja-feed.js). Aqui ficam só as coisas
   que são desta página: os chips de categoria, o card da própria loja e
   a lista de lojas em destaque.
   ====================================================================== */

const CATEGORIAS = [
    "Alimentação", "Moda", "Tecnologia", "Beleza", "Serviços", "Saúde", "Outro",
];

let feed = null;

document.addEventListener("DOMContentLoaded", async function () {
    const user = await EchoUIInstance.checkAuth({ redirectOnFail: true });
    if (!user) return;

    document.getElementById("notificationBellContainer").innerHTML =
        EchoUIInstance.getNotificationBellHTML();
    EchoUIInstance.initHeader("comercio");

    desenharCategorias();

    feed = new LojaFeed({
        container: "comercioFeed",
        emptyText: "Nenhuma loja publicou ainda. Se você tem uma, comece você.",
    });

    feed.load();

    carregarMinhaLoja();
    carregarDestaques();
});

/* Os chips guardam a categoria escolhida na própria URL (`?categoria=`),
   e não só em memória: assim o filtro sobrevive a um recarregamento e dá
   para mandar o link de uma categoria para alguém. */
function desenharCategorias() {
    const box   = document.getElementById("lojaCategorias");
    const atual = new URLSearchParams(location.search).get("categoria") || "";

    box.innerHTML = [["", "Tudo"], ...CATEGORIAS.map(c => [c, c])]
        .map(([valor, rotulo]) => `
            <button type="button" class="echo-chip ${valor === atual ? "ativo" : ""}"
                    data-cat="${EchoUIInstance.escapeHTML(valor)}">
                ${EchoUIInstance.escapeHTML(rotulo)}
            </button>`).join("");

    box.querySelectorAll(".echo-chip").forEach(b =>
        b.addEventListener("click", () => {
            box.querySelectorAll(".echo-chip").forEach(x => x.classList.remove("ativo"));
            b.classList.add("ativo");

            const cat = b.dataset.cat;
            const url = new URL(location);

            if (cat) url.searchParams.set("categoria", cat);
            else     url.searchParams.delete("categoria");

            history.replaceState(null, "", url);
            feed.filtrar({ categoria: cat });
        }));

    if (atual) feed?.filtrar({ categoria: atual });
}

async function carregarMinhaLoja() {
    const box = document.getElementById("minhaLojaCard");

    try {
        const r = await fetch("api/lojas/perfil.php", { credentials: "same-origin" });
        const d = await r.json();

        // Sem loja, o card vira convite. Com loja, vira atalho para ela.
        if (!d.ok) {
            box.innerHTML = `
                <p class="text-secondary small mb-2">
                    Você ainda não tem loja no Echo. Criar leva um minuto, e seu
                    agente já nasce atendendo.
                </p>
                <a class="btn btn-sm btn-primary rounded-pill px-3 w-100" href="meu_echo.html">
                    Abrir minha loja
                </a>`;
            return;
        }

        const l = d.loja;
        const s = d.stats || {};

        carregarMeusVideos();

        box.innerHTML = `
            <a class="echo-trend" href="loja_perfil.html?loja_id=${l.id}">
                <span class="echo-trend-body">
                    <strong>${EchoUIInstance.escapeHTML(l.nome)}</strong>
                    <small>${s.produtos || 0} produtos · ${s.posts || 0} posts · ${s.chats || 0} conversas</small>
                </span>
            </a>
            <a class="btn btn-sm btn-outline-primary rounded-pill px-3 w-100 mt-2" href="meu_echo.html">
                Gerenciar
            </a>`;
    } catch (e) {
        box.innerHTML = `<p class="text-secondary mb-0 small">Não foi possível carregar.</p>`;
    }
}

/* ------------------------------ meus vídeos ------------------------------

   As peças do motor de anúncios da MINHA loja (api/video/meus.php — a loja
   vem da sessão). O lojista pode fechar o modal do Canvas enquanto o vídeo
   renderiza: ele aparece aqui como "Na fila (posição N)…" ou "Gerando…" e
   vira player quando fica pronto. Enquanto houver algum na fila ou gerando,
   consulta de novo a cada 6s (e não consulta com a aba oculta); sem
   nenhum em andamento, não consulta. */

const MEUS_VIDEOS_POLL_MS = 6000;
let meusVideosTimer = null;
let meusVideosEstado = {};   // id -> status, para avisar quando um fica pronto

async function carregarMeusVideos() {
    clearTimeout(meusVideosTimer);
    const box = document.getElementById("meusVideos");

    let d;
    try {
        const r = await fetch("api/video/meus.php", { credentials: "same-origin" });
        d = await r.json();
    } catch (e) {
        meusVideosTimer = setTimeout(carregarMeusVideos, MEUS_VIDEOS_POLL_MS);
        return;
    }

    if (!d.ok) { box.hidden = true; return; }

    const videos = d.videos || [];

    // Avisa quem estava na fila/gerando e acabou de ficar pronto.
    const emAndamento = s => s === "na_fila" || s === "gerando";
    videos.forEach(v => {
        if (emAndamento(meusVideosEstado[v.id]) && v.status === "pronto") {
            EchoUIInstance.toastSuccess("Seu vídeo ficou pronto! Está em Meus vídeos.");
        }
    });
    meusVideosEstado = Object.fromEntries(videos.map(v => [v.id, v.status]));

    desenharMeusVideos(videos);

    if (videos.some(v => emAndamento(v.status))) {
        meusVideosTimer = setTimeout(esperarVisivelEAtualizar, MEUS_VIDEOS_POLL_MS);
    }
}

function esperarVisivelEAtualizar() {
    if (!document.hidden) { carregarMeusVideos(); return; }
    document.addEventListener("visibilitychange", carregarMeusVideos, { once: true });
}

function desenharMeusVideos(videos) {
    const box = document.getElementById("meusVideos");

    // Um card aberto no meio da edição da legenda não pode sumir num poll.
    const editando = box.querySelector(".meu-video-legenda:not([hidden])");
    if (editando) return;

    box.hidden = false;

    const cabecalho = `
        <div class="meus-videos-topo">
            <h2><i class="fa-solid fa-film"></i>Meus vídeos</h2>
            <small>Só você vê · pronto aparece aqui</small>
        </div>`;

    if (!videos.length) {
        box.innerHTML = cabecalho + `
            <p class="meus-videos-vazio">
                Seus vídeos de marketing aparecem aqui assim que ficam prontos.
                <a href="canvas.html">Gerar um no Canvas</a>
            </p>`;
        return;
    }

    box.innerHTML = cabecalho + `<div class="meus-videos-lista">${videos.map(meuVideoHTML).join("")}</div>`;

    box.querySelectorAll(".meu-video").forEach(card => {
        const id = parseInt(card.dataset.id, 10);
        const legenda = card.querySelector(".meu-video-legenda");

        card.querySelector('[data-acao="publicar"]')?.addEventListener("click", () => {
            legenda.hidden = false;
            legenda.querySelector("textarea").focus();
        });
        card.querySelector('[data-acao="cancelar"]')?.addEventListener("click", () => {
            legenda.hidden = true;
        });
        card.querySelector('[data-acao="confirmar"]')?.addEventListener("click", ev =>
            publicarMeuVideo(id, legenda.querySelector("textarea").value.trim(), ev.currentTarget));
    });
}

function meuVideoHTML(v) {
    const quando = EchoUIInstance.formatTime(v.created_at);
    const nome = EchoUIInstance.escapeHTML(v.modelo || "Vídeo");

    if (v.status === "na_fila" || v.status === "gerando") {
        const naFila = v.status === "na_fila";
        const rotulo = naFila ? `Na fila (posição ${parseInt(v.posicao_fila, 10) || 1})…` : "Gerando…";
        const dica = naFila ? "começa sozinho" : "1 a 3 min";
        return `
        <article class="meu-video meu-video-gerando" data-id="${v.id}">
            <div class="meu-video-midia">
                <span class="spinner-border spinner-border-sm"></span>
                <span>${rotulo}</span>
            </div>
            <div class="meu-video-info"><strong>${nome}</strong><small>${quando} · ${dica}</small></div>
        </article>`;
    }

    if (v.status === "erro") {
        return `
        <article class="meu-video meu-video-erro" data-id="${v.id}">
            <div class="meu-video-midia">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Não deu certo</span>
            </div>
            <div class="meu-video-info"><strong>${nome}</strong>
                <small title="${EchoUIInstance.escapeHTML(v.erro || "")}">${quando} · tente gerar de novo</small></div>
        </article>`;
    }

    const src = LojaFeed.videoSrc(v.arquivo);

    return `
    <article class="meu-video" data-id="${v.id}">
        <div class="meu-video-midia">
            <video src="${src}" controls muted playsinline preload="metadata"></video>
        </div>
        <div class="meu-video-info"><strong>${nome}</strong><small>${quando}</small></div>
        <div class="meu-video-acoes">
            ${v.publicado
                ? `<span class="meu-video-publicado"><i class="fa-solid fa-circle-check"></i>Publicado</span>`
                : `<button type="button" class="btn btn-sm btn-primary rounded-pill" data-acao="publicar">
                       <i class="fa-solid fa-paper-plane me-1"></i>Publicar</button>`}
            <a class="btn btn-sm btn-outline-secondary rounded-pill" href="${src}" download title="Baixar">
                <i class="fa-solid fa-download"></i></a>
        </div>
        <div class="meu-video-legenda" hidden>
            <textarea class="form-control form-control-sm" rows="2" maxlength="3000"
                      placeholder="Legenda (opcional)"></textarea>
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-primary rounded-pill flex-grow-1" data-acao="confirmar">Publicar na loja</button>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-acao="cancelar">Cancelar</button>
            </div>
        </div>
    </article>`;
}

/* Mesmo endpoint do botão do modal (post_criar.php com `video_id`): o post
   só referencia o arquivo, sem re-upload. Publicado, recarrega o feed pra
   ele aparecer no topo. */
async function publicarMeuVideo(id, legenda, btn) {
    const fd = new FormData();
    fd.append("video_id", id);
    fd.append("tipo", "novidade");
    fd.append("conteudo", legenda);

    btn.disabled = true;

    try {
        const r = await fetch("api/lojas/post_criar.php", { method: "POST", credentials: "same-origin", body: fd });
        const d = await r.json();

        if (d.error) {
            EchoUIInstance.toastError(d.error);
            btn.disabled = false;
            return;
        }

        EchoUIInstance.toastSuccess("Publicado! Já está no feed.");
        btn.closest(".meu-video-legenda").hidden = true;
        carregarMeusVideos();
        feed.load();
    } catch (e) {
        EchoUIInstance.toastError("Erro de conexão. Tente de novo.");
        btn.disabled = false;
    }
}

/* "Destaque" aqui é quem tem mais interação recente, e sai do próprio
   feed: as lojas que aparecem nas primeiras páginas, ordenadas por
   curtida e comentário. Um endpoint só para isso seria uma consulta a
   mais para ordenar dado que já veio. */
async function carregarDestaques() {
    const box = document.getElementById("lojasDestaque");

    try {
        const r = await fetch("api/lojas/feed.php?limit=30", { credentials: "same-origin" });
        const d = await r.json();

        if (!d.ok || !d.posts.length) {
            EchoUIInstance.esconderCartaoVazio(box);
            return;
        }

        const porLoja = {};

        d.posts.forEach(p => {
            const id = p.loja.id;
            porLoja[id] = porLoja[id] || { loja: p.loja, peso: 0, posts: 0 };
            porLoja[id].peso  += (p.curtidas || 0) + (p.comentarios || 0) * 2;
            porLoja[id].posts += 1;
        });

        const top = Object.values(porLoja).sort((a, b) => b.peso - a.peso).slice(0, 4);

        EchoUIInstance.revelarCartao(box);

        box.innerHTML = top.map(x => `
            <a class="echo-trend" href="loja_perfil.html?loja_id=${x.loja.id}">
                <span class="echo-search-hash"><i class="fa-solid fa-store"></i></span>
                <span class="echo-trend-body">
                    <strong>${EchoUIInstance.escapeHTML(x.loja.nome)}</strong>
                    <small>${EchoUIInstance.escapeHTML(x.loja.categoria || "")}
                           · ${x.posts} ${x.posts === 1 ? "post" : "posts"}</small>
                </span>
            </a>`).join("");
    } catch (e) {
        EchoUIInstance.esconderCartaoVazio(box);
    }
}
