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
