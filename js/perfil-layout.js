/* ==========================================================================
   Layout por blocos do perfil (etapa 1, 30/09/2026)

   A pessoa reorganiza o PRÓPRIO perfil sem escrever nada: reordena os
   blocos, escolhe um de 3 tamanhos e uma forma. Sem layout salvo, o
   perfil é o de sempre — este arquivo não mexe em nada.

   Não desenha conteúdo: MOVE os elementos que o perfil já tem (avatar,
   nome, estatísticas, sobre, feed) para dentro de caixas de bloco, e os
   devolve ao lugar de origem no "Restaurar padrão". Quem preenche esses
   elementos continua sendo o código do perfil.html, pelos mesmos ids e
   com o mesmo escape — inclusive os botões "Enviar mensagem" / "Voltar"
   que entram no bloco "sobre" quando é o perfil de outra pessoa.

   Só tipos conhecidos (BLOCOS) viram bloco; tudo que aparece de texto
   aqui vem de constantes deste arquivo. O servidor valida de novo tudo o
   que for salvo (api/layout/, contrato em docs/API_CONTRACT.md).
   ========================================================================== */

const PerfilLayout = (() => {
    const TELA = "perfil";

    /* Os 5 blocos: rótulo, formas aceitas e a padrão (espelha
       LAYOUT_BLOCOS de api/layout/helpers.php). `alvo` diz qual elemento
       do perfil.html o bloco carrega. */
    const FORMAS_TEXTO = ["quadrado", "arredondado", "pilula"];
    const BLOCOS = {
        foto:         { rotulo: "Foto",          alvo: () => document.getElementById("profileAvatar"),     formas: ["quadrado", "arredondado", "pilula", "circulo"], padrao: "circulo", imagem: true },
        identidade:   { rotulo: "Nome",          alvo: () => document.getElementById("perfilIdentidade"),  formas: FORMAS_TEXTO, padrao: "arredondado" },
        estatisticas: { rotulo: "Estatísticas",  alvo: () => document.getElementById("perfilEstatisticas"), formas: FORMAS_TEXTO, padrao: "arredondado" },
        sobre:        { rotulo: "Sobre",         alvo: () => document.getElementById("perfilSobre"),        formas: FORMAS_TEXTO, padrao: "arredondado" },
        publicacoes:  { rotulo: "Publicações",   alvo: () => document.getElementById("myPostsContainer"),   formas: FORMAS_TEXTO, padrao: "arredondado" },
    };
    const TAMANHOS = { pequeno: "Pequeno", medio: "Médio", grande: "Grande" };
    const FORMAS   = { quadrado: "Quadrado", arredondado: "Arredondado", pilula: "Pílula", circulo: "Círculo" };

    const bloco = (tipo, tamanho, forma) => ({ tipo, tamanho, forma });
    const layout = (...blocos) => ({ versao: 1, blocos: blocos.map((b, i) => ({ ...b, ordem: i + 1 })) });

    /* Ponto de partida quando a pessoa entra na edição sem layout salvo:
       a mesma ordem do perfil automático. */
    const INICIAL = layout(
        bloco("foto", "pequeno", "circulo"),
        bloco("identidade", "medio", "arredondado"),
        bloco("estatisticas", "grande", "arredondado"),
        bloco("sobre", "grande", "arredondado"),
        bloco("publicacoes", "grande", "arredondado"),
    );

    /* Modelos prontos: só JSON no formato do layout salvo. Escolher um
       carrega na edição; nada vai ao servidor até "Salvar". */
    const MODELOS = [
        { nome: "Foco em fotos", descricao: "Foto grande no topo, publicações logo abaixo.",
          layout: layout(
              bloco("foto", "grande", "circulo"),
              bloco("identidade", "medio", "pilula"),
              bloco("estatisticas", "medio", "pilula"),
              bloco("publicacoes", "grande", "arredondado"),
              bloco("sobre", "grande", "arredondado"),
          ) },
        { nome: "Foco em texto", descricao: "Sobre e atividade recente primeiro, foto pequena.",
          layout: layout(
              bloco("identidade", "medio", "arredondado"),
              bloco("foto", "pequeno", "arredondado"),
              bloco("sobre", "grande", "arredondado"),
              bloco("publicacoes", "grande", "arredondado"),
              bloco("estatisticas", "grande", "quadrado"),
          ) },
        { nome: "Denso", descricao: "Tipo currículo: tudo compacto, lado a lado.",
          layout: layout(
              bloco("foto", "pequeno", "quadrado"),
              bloco("identidade", "pequeno", "quadrado"),
              bloco("estatisticas", "pequeno", "quadrado"),
              bloco("sobre", "medio", "quadrado"),
              bloco("publicacoes", "medio", "quadrado"),
          ) },
    ];

    let donoId = null;        // perfil aberto (null = o da sessão)
    let editavel = false;     // o servidor diz se é da sessão
    let salvo = null;         // layout salvo (null = automático)
    let edicao = null;        // cópia em edição (null = fora da edição)
    let sortable = null;
    const origem = new Map(); // tipo -> comentário marcando o lugar original

    /* ---------------------------------------------------------------- */

    function copia(l) {
        return l ? JSON.parse(JSON.stringify(l)) : null;
    }

    /** Só o que o front sabe desenhar: tipos conhecidos, uma vez cada. */
    function layoutConhecido(l) {
        if (!l || l.versao !== 1 || !Array.isArray(l.blocos)) return false;
        const tipos = l.blocos.map(b => b && b.tipo);
        return tipos.length === Object.keys(BLOCOS).length
            && tipos.every(t => Object.prototype.hasOwnProperty.call(BLOCOS, t))
            && new Set(tipos).size === tipos.length;
    }

    function formaValida(tipo, forma) {
        return BLOCOS[tipo].formas.includes(forma) ? forma : BLOCOS[tipo].padrao;
    }

    function tamanhoValido(tamanho) {
        return Object.prototype.hasOwnProperty.call(TAMANHOS, tamanho) ? tamanho : "grande";
    }

    /** Guarda o lugar original do elemento, uma vez, para poder devolvê-lo. */
    function marcarOrigem(tipo, el) {
        if (origem.has(tipo) || !el || !el.parentNode) return;
        const marca = document.createComment("bloco:" + tipo);
        el.parentNode.insertBefore(marca, el);
        origem.set(tipo, marca);
    }

    /** O perfil automático de sempre: cada elemento de volta ao seu lugar. */
    function desmontar() {
        if (sortable) { sortable.destroy(); sortable = null; }
        for (const [tipo, marca] of origem) {
            const el = BLOCOS[tipo].alvo();
            if (el && marca.parentNode) marca.parentNode.insertBefore(el, marca.nextSibling);
        }
        document.getElementById("perfilBlocos")?.remove();
        document.body.classList.remove("perfil-com-blocos");
    }

    /** Desenha um layout (o salvo, ou o em edição). */
    function montar(l) {
        if (!layoutConhecido(l)) { desmontar(); return; }

        let grade = document.getElementById("perfilBlocos");
        if (!grade) {
            grade = document.createElement("div");
            grade.id = "perfilBlocos";
            grade.className = "perfil-blocos";
            document.querySelector(".main-col > .main-header").after(grade);
        }

        const ordenados = [...l.blocos].sort((a, b) => a.ordem - b.ordem);

        for (const b of ordenados) {
            const def = BLOCOS[b.tipo];
            const el = def.alvo();
            if (!el) continue;
            marcarOrigem(b.tipo, el);

            // Duas camadas: a caixa da grade leva o TAMANHO (e, na edição,
            // os controles em cima); a de dentro leva a FORMA e o conteúdo.
            // Assim a barra de controles nunca fica dentro de um círculo.
            let caixa = grade.querySelector(`.perfil-bloco[data-tipo="${b.tipo}"]`);
            if (!caixa) {
                caixa = document.createElement("section");
                caixa.dataset.tipo = b.tipo;
                caixa.setAttribute("aria-label", def.rotulo);
            }
            caixa.className = "perfil-bloco perfil-bloco-" + tamanhoValido(b.tamanho);

            let forma = caixa.querySelector(":scope > .perfil-bloco-forma");
            if (!forma) {
                forma = document.createElement("div");
                caixa.appendChild(forma);
            }
            forma.className = "perfil-bloco-forma perfil-forma-" + formaValida(b.tipo, b.forma)
                + (def.imagem ? " perfil-bloco-imagem" : "");
            if (el.parentNode !== forma) forma.appendChild(el);

            grade.appendChild(caixa); // na ordem do layout
        }

        document.body.classList.add("perfil-com-blocos");
        desenharControles();
    }

    /* ---------------------------------------------------------------- */
    /* Edição                                                           */

    function botao(texto, classe, aoClicar, rotulo) {
        const b = document.createElement("button");
        b.type = "button";
        b.className = classe;
        b.textContent = texto;
        if (rotulo) b.setAttribute("aria-label", rotulo);
        b.addEventListener("click", aoClicar);
        return b;
    }

    function seletor(rotulo, opcoes, valor, aoMudar) {
        const rot = document.createElement("label");
        rot.className = "perfil-bloco-campo";
        const nome = document.createElement("span");
        nome.textContent = rotulo;
        const sel = document.createElement("select");
        sel.className = "form-select form-select-sm";
        for (const [v, texto] of opcoes) {
            const o = document.createElement("option");
            o.value = v;
            o.textContent = texto;
            o.selected = v === valor;
            sel.appendChild(o);
        }
        sel.addEventListener("change", () => aoMudar(sel.value));
        rot.append(nome, sel);
        return rot;
    }

    /** Barra de controles de cada bloco — só no modo edição. */
    function desenharControles() {
        document.querySelectorAll(".perfil-bloco-controles").forEach(c => c.remove());
        if (!edicao) return;

        const ordenados = [...edicao.blocos].sort((a, b) => a.ordem - b.ordem);

        ordenados.forEach((b, i) => {
            const caixa = document.querySelector(`.perfil-bloco[data-tipo="${b.tipo}"]`);
            if (!caixa) return;
            const def = BLOCOS[b.tipo];

            const barra = document.createElement("div");
            barra.className = "perfil-bloco-controles";

            const alca = document.createElement("span");
            alca.className = "perfil-bloco-alca";
            alca.title = "Arraste para mudar a ordem";
            alca.setAttribute("aria-hidden", "true");
            alca.innerHTML = '<i class="fa-solid fa-grip-vertical"></i>';

            const titulo = document.createElement("strong");
            titulo.textContent = def.rotulo;

            const subir = botao("↑", "btn btn-sm btn-outline-light", () => mover(b.tipo, -1), "Subir o bloco " + def.rotulo);
            const descer = botao("↓", "btn btn-sm btn-outline-light", () => mover(b.tipo, +1), "Descer o bloco " + def.rotulo);
            subir.disabled = i === 0;
            descer.disabled = i === ordenados.length - 1;

            barra.append(
                alca, titulo, subir, descer,
                seletor("Tamanho", Object.entries(TAMANHOS), tamanhoValido(b.tamanho), v => alterar(b.tipo, { tamanho: v })),
                seletor("Forma", def.formas.map(f => [f, FORMAS[f]]), formaValida(b.tipo, b.forma), v => alterar(b.tipo, { forma: v })),
            );
            caixa.prepend(barra);
        });
    }

    function renumerar(tiposNaOrdem) {
        tiposNaOrdem.forEach((tipo, i) => {
            edicao.blocos.find(b => b.tipo === tipo).ordem = i + 1;
        });
    }

    function mover(tipo, passo) {
        const ordem = [...edicao.blocos].sort((a, b) => a.ordem - b.ordem).map(b => b.tipo);
        const i = ordem.indexOf(tipo);
        const j = i + passo;
        if (j < 0 || j >= ordem.length) return;
        [ordem[i], ordem[j]] = [ordem[j], ordem[i]];
        renumerar(ordem);
        montar(edicao);
        // O foco fica no mesmo botão do bloco que andou, para quem usa teclado.
        const caixa = document.querySelector(`.perfil-bloco[data-tipo="${tipo}"]`);
        const alvo = caixa?.querySelectorAll(".perfil-bloco-controles button")[passo < 0 ? 0 : 1];
        (alvo && !alvo.disabled ? alvo : caixa?.querySelector(".perfil-bloco-controles button:not(:disabled)"))?.focus();
    }

    function alterar(tipo, mudanca) {
        Object.assign(edicao.blocos.find(b => b.tipo === tipo), mudanca);
        montar(edicao);
        document.querySelector(`.perfil-bloco[data-tipo="${tipo}"] .perfil-bloco-controles select`)?.focus();
    }

    function ligarArrastar() {
        if (sortable || typeof Sortable === "undefined") return;
        sortable = Sortable.create(document.getElementById("perfilBlocos"), {
            handle: ".perfil-bloco-alca",
            animation: 150,
            onEnd: () => {
                const tipos = [...document.querySelectorAll("#perfilBlocos > .perfil-bloco")].map(c => c.dataset.tipo);
                renumerar(tipos);
                montar(edicao);
            },
        });
    }

    function barraEdicao(ligada) {
        document.getElementById("perfilLayoutBarra")?.classList.toggle("d-none", !ligada);
        document.getElementById("btnOrganizarPerfil")?.classList.toggle("d-none", ligada || !editavel);
    }

    function entrar() {
        edicao = copia(salvo || INICIAL);
        montar(edicao);
        ligarArrastar();
        barraEdicao(true);
        document.getElementById("btnLayoutSalvar")?.focus();
    }

    function sair() {
        edicao = null;
        if (sortable) { sortable.destroy(); sortable = null; }
        barraEdicao(false);
        salvo ? montar(salvo) : desmontar();
        fecharModelos();
        document.body.dataset.perfilLayout = salvo ? "blocos" : "automatico";
    }

    async function salvar() {
        const btn = document.getElementById("btnLayoutSalvar");
        btn.disabled = true;
        try {
            const res = await fetch("api/layout/salvar.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ tela: TELA, layout: edicao }),
            });
            const data = await res.json();
            if (data.error) { EchoUIInstance.toastError(data.error); return; }
            salvo = data.layout;
            sair();
            EchoUIInstance.toastSuccess("Layout salvo.");
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão ao salvar o layout.");
        } finally {
            btn.disabled = false;
        }
    }

    async function restaurar() {
        const ok = await EchoUIInstance.confirm({
            title: "Restaurar o perfil padrão?",
            message: "O layout que você salvou será apagado e o perfil volta ao formato automático.",
            confirmText: "Restaurar",
        });
        if (!ok) return;
        try {
            const res = await fetch("api/layout/restaurar.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ tela: TELA }),
            });
            const data = await res.json();
            if (data.error) { EchoUIInstance.toastError(data.error); return; }
            salvo = null;
            sair();
            EchoUIInstance.toastSuccess("Perfil de volta ao padrão.");
        } catch (e) {
            EchoUIInstance.toastError("Erro de conexão ao restaurar.");
        }
    }

    /* ---------------------------------------------------------------- */
    /* Modelos                                                          */

    function miniatura(l) {
        const grade = document.createElement("div");
        grade.className = "perfil-modelo-mini";
        grade.setAttribute("aria-hidden", "true");
        for (const b of [...l.blocos].sort((a, c) => a.ordem - c.ordem)) {
            const c = document.createElement("span");
            c.className = "perfil-bloco-" + b.tamanho + " perfil-forma-" + b.forma
                + (BLOCOS[b.tipo].imagem ? " perfil-bloco-imagem" : "");
            c.textContent = BLOCOS[b.tipo].rotulo;
            grade.appendChild(c);
        }
        return grade;
    }

    function abrirModelos() {
        const painel = document.getElementById("perfilModelos");
        painel.replaceChildren();
        for (const m of MODELOS) {
            const card = document.createElement("button");
            card.type = "button";
            card.className = "perfil-modelo";
            const nome = document.createElement("strong");
            nome.textContent = m.nome;
            const desc = document.createElement("small");
            desc.textContent = m.descricao;
            card.append(miniatura(m.layout), nome, desc);
            card.addEventListener("click", () => {
                edicao = copia(m.layout);   // só na edição: nada salvo ainda
                montar(edicao);
                fecharModelos();
                EchoUIInstance.toastSuccess(`Modelo "${m.nome}" carregado. Ajuste e salve para ficar com ele.`);
            });
            painel.appendChild(card);
        }
        painel.classList.remove("d-none");
        document.getElementById("btnLayoutModelos").setAttribute("aria-expanded", "true");
        painel.querySelector(".perfil-modelo")?.focus();
    }

    function fecharModelos() {
        document.getElementById("perfilModelos")?.classList.add("d-none");
        document.getElementById("btnLayoutModelos")?.setAttribute("aria-expanded", "false");
    }

    /* ---------------------------------------------------------------- */

    /**
     * Carrega e aplica o layout do perfil aberto. `userId` = null para o
     * da sessão. Chamado depois que o perfil.html sabe de quem é a tela.
     */
    async function iniciar(userId) {
        donoId = userId;
        try {
            const url = "api/layout/obter.php?tela=" + TELA + (donoId ? "&user_id=" + encodeURIComponent(donoId) : "");
            const res = await fetch(url, { credentials: "same-origin" });
            const data = await res.json();
            if (data.error) return; // sem layout: o perfil automático segue
            editavel = Boolean(data.editavel);
            salvo = layoutConhecido(data.layout) ? data.layout : null;
        } catch (e) {
            console.error("PerfilLayout: erro ao carregar o layout", e);
        }

        salvo ? montar(salvo) : desmontar();
        barraEdicao(false);
        // Estado aplicado, legível de fora (depuração, testes).
        document.body.dataset.perfilLayout = salvo ? "blocos" : "automatico";
    }

    function ligarBotoes() {
        document.getElementById("btnOrganizarPerfil")?.addEventListener("click", entrar);
        document.getElementById("btnLayoutSalvar")?.addEventListener("click", salvar);
        document.getElementById("btnLayoutCancelar")?.addEventListener("click", sair);
        document.getElementById("btnLayoutRestaurar")?.addEventListener("click", restaurar);
        document.getElementById("btnLayoutModelos")?.addEventListener("click", () =>
            document.getElementById("perfilModelos").classList.contains("d-none") ? abrirModelos() : fecharModelos());
    }

    document.addEventListener("DOMContentLoaded", ligarBotoes);

    return { iniciar, MODELOS, BLOCOS };
})();
