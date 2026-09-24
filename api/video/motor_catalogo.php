<?php
/**
 * Catálogo do MOTOR DE ANÚNCIOS — fonte única dos modelos e dos campos
 * editáveis de cada um. Consumido por:
 *   - modelos.php     (devolve isto como JSON pro front montar a tela)
 *   - marketing.php   (valida o que o lojista mandou contra isto)
 *   - o motor Remotion em /motor (os `campos[].key` batem com os props dos
 *     componentes em motor/src/*.jsx)
 *
 * Cada campo:
 *   key      nome do prop no componente Remotion
 *   label    rótulo na tela
 *   tipo     texto | textarea | preco | lista | numero | estrelas
 *   max      limite de caracteres (texto/textarea/preco)
 *   req      obrigatório?
 *
 * `foto_alvos` diz para quais props as fotos enviadas vão, na ordem. Vazio =
 * modelo sem foto do produto.
 */

function motor_formatos(): array
{
    return [
        ["id" => "story",    "nome" => "Story / Reels (9:16)", "w" => 1080, "h" => 1920],
        ["id" => "feed",     "nome" => "Feed (4:5)",           "w" => 1080, "h" => 1350],
        ["id" => "quadrado", "nome" => "Quadrado (1:1)",       "w" => 1080, "h" => 1080],
        ["id" => "paisagem", "nome" => "Paisagem (16:9)",      "w" => 1920, "h" => 1080],
    ];
}

/** Nichos que têm preset visual próprio no motor (motor/src/presets.js). */
function motor_nichos(): array
{
    return [
        ["id" => "comida",  "nome" => "Comida / Alimentação"],
        ["id" => "moda",    "nome" => "Moda / Roupas"],
        ["id" => "joia",    "nome" => "Joia / Acessórios"],
        ["id" => "tech",    "nome" => "Tecnologia / Eletrônicos"],
        ["id" => "beleza",  "nome" => "Beleza / Estética"],
        ["id" => "fitness", "nome" => "Fitness / Academia"],
    ];
}

/** Mapa categoria-da-loja (lojas.categoria) -> nicho do motor. */
function motor_nicho_da_categoria(string $categoria): string
{
    $mapa = [
        "Alimentação" => "comida", "Moda" => "moda", "Tecnologia" => "tech",
        "Beleza" => "beleza", "Saúde" => "beleza", "Serviços" => "comida",
        "Petshop" => "comida", "Outro" => "comida",
    ];
    return $mapa[$categoria] ?? "comida";
}

function motor_modelos(): array
{
    $t = fn($k, $l, $max = 80, $req = false) => ["key" => $k, "label" => $l, "tipo" => "texto", "max" => $max, "req" => $req];
    $preco = fn($k = "preco", $l = "Preço") => ["key" => $k, "label" => $l, "tipo" => "preco", "max" => 20, "req" => false];
    $lista = fn($k, $l, $max = 120) => ["key" => $k, "label" => $l, "tipo" => "lista", "max" => $max, "req" => false];

    return [
        "Flash" => [
            "nome" => "Flash — 1 cena", "desc" => "Rápido: produto, preço e chamada. Plano básico.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("chamada", "Chamada (2 palavras)", 40, true), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Historia" => [
            "nome" => "História — 3 cenas", "desc" => "Abre, mostra o detalhe e fecha. Plano Pro.",
            "foto_alvos" => ["fotos", "fotos"],
            "campos" => [$t("chamada", "Chamada", 40, true), $t("sub", "Frase do meio", 40), $lista("tags", "Destaques (vírgula)"), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Manchete" => [
            "nome" => "Manchete — gira e trava", "desc" => "Card gira e trava com impacto. Ótimo pra oferta.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("selo", "Selo (ex.: OFERTA)", 16), $t("chamada", "Chamada", 40, true), $preco(), $lista("infos", "Informações (vírgula)"), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Vitrine" => [
            "nome" => "Vitrine — vira em 3D", "desc" => "Card vira e mostra as specs no verso.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("produto", "Nome do produto", 30, true), $lista("specs", "Specs (vírgula)"), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Ficha" => [
            "nome" => "Ficha — números contando", "desc" => "Estatísticas sobem de zero. Fitness, tech, auto.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("chamada", "Chamada", 40, true), $lista("stats_txt", "Números (ex.: 24H Aberto, 80+ Aparelhos)", 160), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Luxo" => [
            "nome" => "Luxo — brilho dourado", "desc" => "Alto padrão. Joia, moda, produto premium.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("chamada", "Título", 40, true), $t("sub", "Subtítulo", 60), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Glitch" => [
            "nome" => "Glitch — tech", "desc" => "RGB split e scanlines. Cara de tecnologia.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("chamada", "Chamada", 40, true), $lista("specs", "Specs (vírgula)"), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Editorial" => [
            "nome" => "Editorial — revista", "desc" => "Estilo revista de moda, elegante.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("fundo", "Palavra de fundo", 12), $t("chamada", "Título", 40, true), $t("sub", "Subtítulo", 40), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "AntesDepois" => [
            "nome" => "Antes / Depois", "desc" => "Divisória desliza. Estética, beleza, reforma.",
            "foto_alvos" => ["fotoAntes", "fotoDepois"],
            "campos" => [$t("chamada", "Chamada", 40, true), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Depoimento" => [
            "nome" => "Depoimento — avaliação", "desc" => "Prova social com estrelas.",
            "foto_alvos" => ["foto"],
            "campos" => [["key" => "texto", "label" => "Depoimento", "tipo" => "textarea", "max" => 160, "req" => true], $t("cliente", "Nome do cliente", 30, true), ["key" => "estrelas", "label" => "Estrelas (1-5)", "tipo" => "numero", "max" => 1, "req" => false], $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Combo" => [
            "nome" => "Combo / Cardápio", "desc" => "Grade de itens com preços (usa seus produtos).",
            "foto_alvos" => [], "auto" => "produtos",
            "campos" => [$t("titulo", "Título (ex.: CARDÁPIO)", 20, true), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Cupom" => [
            "nome" => "Cupom — desconto", "desc" => "Código de desconto em destaque.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("desconto", "Desconto (ex.: 20% OFF)", 16, true), $t("codigo", "Código", 16, true), $t("validade", "Validade", 40), $t("chamada", "Chamada", 40), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
        "Countdown" => [
            "nome" => "Countdown — urgência", "desc" => "Relógio regressivo. Vaga limitada, só hoje.",
            "foto_alvos" => ["foto"],
            "campos" => [$t("selo", "Selo (ex.: SÓ HOJE)", 16), $t("chamada", "Chamada", 40, true), $preco(), $t("cta", "Botão / ação", 40), $t("marca", "Nome da loja", 40, true)],
        ],
    ];
}
