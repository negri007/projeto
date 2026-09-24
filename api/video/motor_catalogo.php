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

/**
 * Estilos visuais do motor (motor/src/presets.js). Não são categorias de
 * produto — são LOOKS. Um produto que não cai num tema (camisinha, ferramenta)
 * usa o "Neutro", que é premium de propósito, não um tapa-buraco. A loja pode
 * escolher qualquer estilo, independente do que vende.
 */
function motor_nichos(): array
{
    return [
        ["id" => "neutro",   "nome" => "Neutro / Universal (serve pra tudo)"],
        ["id" => "comida",   "nome" => "Comida / Alimentação"],
        ["id" => "moda",     "nome" => "Moda / Roupas"],
        ["id" => "joia",     "nome" => "Joia / Acessórios"],
        ["id" => "tech",     "nome" => "Tecnologia / Eletrônicos"],
        ["id" => "beleza",   "nome" => "Beleza / Estética"],
        ["id" => "fitness",  "nome" => "Fitness / Academia"],
        ["id" => "saude",    "nome" => "Saúde / Farmácia"],
        ["id" => "casa",     "nome" => "Casa / Móveis / Decoração"],
        ["id" => "pet",      "nome" => "Pet / Animais"],
        ["id" => "servicos", "nome" => "Serviços / Profissional"],
        ["id" => "infantil", "nome" => "Infantil / Kids"],
    ];
}

/** Mapa categoria-da-loja (lojas.categoria) -> estilo padrão do motor. */
function motor_nicho_da_categoria(string $categoria): string
{
    $mapa = [
        "Alimentação" => "comida", "Moda" => "moda", "Tecnologia" => "tech",
        "Beleza" => "beleza", "Saúde" => "saude", "Serviços" => "servicos",
        "Petshop" => "pet", "Casa" => "casa", "Farmácia" => "saude",
        "Outro" => "neutro",
    ];
    return $mapa[$categoria] ?? "neutro";
}

/**
 * Modelos com foto "full-bleed" (a foto ocupa a tela toda) — nesses o
 * encaixe Preencher/Foto-inteira e o foco/zoom fazem diferença, então o front
 * mostra os controles. Os de card (Manchete, Vitrine, Cupom...) já enquadram
 * a foto, e o Antes/Depois tem duas fotos com divisória, então ficam de fora.
 */
function motor_foto_ajustavel(string $modelo): bool
{
    return in_array($modelo, ["Flash", "Historia", "Ficha", "Countdown"], true);
}

function motor_modelos(): array
{
    // Builders com RÓTULO em português claro + EXEMPLO concreto (vira o
    // placeholder no campo) + DICA curta. Pensado pra lojista leigo: o rótulo
    // diz o que é, o exemplo mostra na prática. `ex` sempre começa com "Ex.:".
    $c = fn($k, $l, $ex, $dica = "", $max = 80, $req = false, $tipo = "texto") =>
        ["key" => $k, "label" => $l, "ex" => $ex, "dica" => $dica, "tipo" => $tipo, "max" => $max, "req" => $req];

    // Campos comuns, com texto de leigo — reaproveitados entre modelos.
    $preco = fn($ex = "Ex.: R$ 32,90") => $c("preco", "Preço", $ex, "aparece em destaque; deixe em branco se não quiser mostrar", 20);
    $botao = fn($ex = "Ex.: Peça no WhatsApp") => $c("cta", "O que o cliente deve fazer", $ex, "a frase do botão no fim do vídeo", 40);
    $marca = fn() => $c("marca", "Nome da sua loja", "Ex.: Loja do Gabriel", "aparece assinando o vídeo", 40, true);

    return [
        "Flash" => [
            "nome" => "Flash — 1 cena", "desc" => "Rápido: produto, preço e chamada. Plano básico.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("chamada", "Título grande (o nome ou a oferta)", "Ex.: Smash Burger", "poucas palavras, é o que aparece maior", 40, true),
                $preco(), $botao(), $marca(),
            ],
        ],
        "Historia" => [
            "nome" => "História — 3 cenas", "desc" => "Abre, mostra o detalhe e fecha. Plano Pro.",
            "foto_alvos" => ["fotos", "fotos"],
            "campos" => [
                $c("chamada", "Título grande (nome ou oferta)", "Ex.: Smash Burger", "aparece na abertura", 40, true),
                $c("sub", "Frase do meio", "Ex.: Suculência de verdade", "uma frase curta na cena do meio", 40),
                $c("tags", "Vantagens rápidas", "Ex.: Pão brioche, Carne 180g, Cheddar duplo", "separe por vírgula (até 3)", 120, false, "lista"),
                $preco(), $botao(), $marca(),
            ],
        ],
        "Manchete" => [
            "nome" => "Manchete — gira e trava", "desc" => "Card gira e trava com impacto. Ótimo pra oferta.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("selo", "Etiqueta em destaque", "Ex.: OFERTA", "uma palavra que chama atenção", 16),
                $c("chamada", "Título grande (nome ou oferta)", "Ex.: Combo do Dia", "poucas palavras", 40, true),
                $preco(),
                $c("infos", "Vantagens rápidas", "Ex.: Entrega grátis, Feito na hora", "separe por vírgula (até 3)", 120, false, "lista"),
                $botao(), $marca(),
            ],
        ],
        "Vitrine" => [
            "nome" => "Vitrine — vira em 3D", "desc" => "Card vira e mostra os detalhes no verso.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("produto", "Nome do produto", "Ex.: Fone Bluetooth", "", 30, true),
                $c("specs", "Detalhes do produto", "Ex.: 40h de bateria, À prova d'água, Sem fio", "separe por vírgula (até 3)", 120, false, "lista"),
                $preco("Ex.: R$ 349"), $botao("Ex.: Garanta o seu"), $marca(),
            ],
        ],
        "Ficha" => [
            "nome" => "Ficha — números contando", "desc" => "Números sobem de zero. Academia, tech, automóvel.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("chamada", "Título grande", "Ex.: Nossa Academia", "poucas palavras", 40, true),
                $c("stats_txt", "Números de destaque", "Ex.: 24h Aberto, 80 Aparelhos, 12 Aulas", "cada número + o que ele é, separados por vírgula", 160, false, "lista"),
                $preco("Ex.: R$ 99/mês"), $botao("Ex.: Matricule-se"), $marca(),
            ],
        ],
        "Luxo" => [
            "nome" => "Luxo — brilho dourado", "desc" => "Alto padrão. Joia, moda, produto premium.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("chamada", "Título elegante", "Ex.: Coleção Aurora", "", 40, true),
                $c("sub", "Frase de apoio", "Ex.: peças exclusivas em ouro 18k", "", 60),
                $preco("Ex.: R$ 1.290"), $botao("Ex.: Agende uma visita"), $marca(),
            ],
        ],
        "Glitch" => [
            "nome" => "Glitch — tecnologia", "desc" => "Efeito digital, cara de tecnologia.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("chamada", "Título grande", "Ex.: Som Puro", "poucas palavras", 40, true),
                $c("specs", "Detalhes do produto", "Ex.: Bluetooth 5.3, 40h bateria", "separe por vírgula (até 3)", 120, false, "lista"),
                $preco("Ex.: R$ 349"), $botao("Ex.: Garanta o seu"), $marca(),
            ],
        ],
        "Editorial" => [
            "nome" => "Editorial — revista", "desc" => "Estilo revista de moda, elegante.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("fundo", "Palavra gigante de fundo", "Ex.: ESTILO", "uma palavra só, fica marca d'água atrás", 12),
                $c("chamada", "Título", "Ex.: Nova Coleção", "", 40, true),
                $c("sub", "Frase de apoio", "Ex.: outono / inverno", "", 40),
                $preco("Ex.: R$ 189"), $botao("Ex.: Compre online"), $marca(),
            ],
        ],
        "AntesDepois" => [
            "nome" => "Antes / Depois", "desc" => "Compara resultado. Estética, beleza, reforma.",
            "foto_alvos" => ["fotoAntes", "fotoDepois"],
            "campos" => [
                $c("chamada", "Título grande", "Ex.: Resultado Real", "poucas palavras", 40, true),
                $preco("Ex.: R$ 180"), $botao("Ex.: Agende sua sessão"), $marca(),
            ],
        ],
        "Depoimento" => [
            "nome" => "Depoimento — avaliação", "desc" => "Elogio de cliente com estrelas.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("texto", "O que o cliente falou", "Ex.: Melhor lanche da cidade, chega quentinho!", "a frase do elogio", 160, true, "textarea"),
                $c("cliente", "Nome do cliente", "Ex.: Marina Alves", "", 30, true),
                $c("estrelas", "Nota (1 a 5 estrelas)", "5", "de 1 a 5", 1, false, "numero"),
                $botao("Ex.: Peça o seu"), $marca(),
            ],
        ],
        "Combo" => [
            "nome" => "Combo / Cardápio", "desc" => "Lista de itens com preços (usa seus produtos).",
            "foto_alvos" => [], "auto" => "produtos",
            "campos" => [
                $c("titulo", "Título do cardápio", "Ex.: Cardápio", "aparece no topo", 20, true),
                $botao("Ex.: Peça no WhatsApp"), $marca(),
            ],
        ],
        "Cupom" => [
            "nome" => "Cupom — desconto", "desc" => "Código de desconto em destaque.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("desconto", "Tamanho do desconto", "Ex.: 20% OFF", "o número grande do desconto", 16, true),
                $c("codigo", "Código do cupom", "Ex.: PROMO20", "o cliente digita isso no pedido", 16, true),
                $c("validade", "Até quando vale", "Ex.: válido até domingo", "", 40),
                $c("chamada", "Frase do topo", "Ex.: Cupom de Desconto", "", 40),
                $botao("Ex.: Use no pedido"), $marca(),
            ],
        ],
        "Countdown" => [
            "nome" => "Countdown — urgência", "desc" => "Relógio correndo. Vaga limitada, só hoje.",
            "foto_alvos" => ["foto"],
            "campos" => [
                $c("selo", "Etiqueta de urgência", "Ex.: SÓ HOJE", "uma palavra ou duas", 16),
                $c("chamada", "Título grande", "Ex.: Últimas Vagas", "poucas palavras", 40, true),
                $preco("Ex.: R$ 99/mês"), $botao("Ex.: Garanta a sua"), $marca(),
            ],
        ],
    ];
}
