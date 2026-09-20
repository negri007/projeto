<?php
/**
 * Módulo 3 do seed — 20 lojas com logo, capa, agente e catálogo.
 * Ver docs/plans/seed-echo.md, "Módulo 3".
 *
 * Idempotente por `lojas.user_id`, que é UNIQUE: uma loja por dono, e a
 * n-ésima pessoa da lista de SEED_PESSOAS é a dona da n-ésima loja de
 * SEED_LOJAS (em seed_lojas_dados.php, junto com a nota sobre a coluna
 * `categoria`).
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/helpers_seed.php";
require_once __DIR__ . "/seed_lojas_dados.php";
require_once __DIR__ . "/../lojas/helpers.php";

/**
 * Catálogo fixo por nicho, para quando a API não responde. Quatro itens
 * por nicho: é o piso do plano (4 a 6) e o bastante para o agente ter o
 * que oferecer e o carrinho ter o que receber.
 */
const SEED_PRODUTOS_FALLBACK = [
    "restaurante" => [
        ["Prato executivo", "Arroz, feijão, guarnição e a carne do dia.", 32.90],
        ["Feijoada completa", "Servida aos sábados, acompanhamentos inclusos.", 58.00],
        ["Massa da casa", "Molho artesanal, serve duas pessoas.", 46.50],
        ["Sobremesa do chef", "Muda toda semana, pergunte a de hoje.", 18.00],
    ],
    "lanchonete" => [
        ["X-salada", "Pão, hambúrguer, queijo, alface e tomate.", 18.90],
        ["Misto quente", "Na chapa, do jeito tradicional.", 12.00],
        ["Porção de fritas", "Serve duas pessoas, com cheddar opcional.", 24.90],
        ["Suco natural 500ml", "Laranja, abacaxi ou maracujá.", 10.00],
    ],
    "pizzaria" => [
        ["Pizza grande mussarela", "Oito fatias, massa fina.", 49.90],
        ["Pizza calabresa", "Calabresa fatiada e cebola.", 54.90],
        ["Pizza doce de chocolate", "Com morango, oito fatias.", 59.90],
        ["Broto de frango com catupiry", "Quatro fatias, ideal para um.", 34.90],
    ],
    "hamburgueria" => [
        ["Smash duplo", "Dois blends de 90g, queijo e molho da casa.", 34.90],
        ["Cheddar bacon", "Bacon crocante e cheddar cremoso.", 38.90],
        ["Veggie", "Hambúrguer de grão-de-bico e maionese verde.", 32.00],
        ["Combo do dia", "Burger, fritas e refrigerante.", 45.00],
    ],
    "loja de açaí" => [
        ["Açaí 500ml", "Com dois acompanhamentos à escolha.", 22.00],
        ["Açaí 300ml", "Copo tradicional, um acompanhamento.", 16.00],
        ["Barca família 1L", "Serve três pessoas, cinco acompanhamentos.", 48.00],
        ["Açaí zero açúcar", "Adoçado com tâmara.", 25.00],
    ],
    "loja de moda feminina" => [
        ["Vestido midi", "Tecido leve, do P ao GG.", 149.90],
        ["Blusa de alça", "Malha canelada, várias cores.", 69.90],
        ["Calça pantalona", "Cintura alta, caimento solto.", 159.90],
        ["Conjunto de tricô", "Blusa e saia, peça de inverno.", 219.90],
    ],
    "loja de moda masculina" => [
        ["Camisa social slim", "Algodão, do P ao GG.", 139.90],
        ["Calça chino", "Corte reto, cinco cores.", 169.90],
        ["Camiseta básica", "Algodão penteado, leve.", 59.90],
        ["Jaqueta jeans", "Lavagem escura, unissex.", 249.90],
    ],
    "loja de moda infantil" => [
        ["Conjunto infantil verão", "Camiseta e bermuda, 2 a 8 anos.", 79.90],
        ["Vestido infantil", "Algodão, estampa exclusiva.", 89.90],
        ["Macacão bebê", "Tamanhos P, M e G.", 69.90],
        ["Kit body 3 peças", "Algodão macio para recém-nascido.", 99.90],
    ],
    "brechó" => [
        ["Jaqueta jeans vintage", "Peça única, tamanho M.", 89.00],
        ["Camisa estampada anos 90", "Peça única, tamanho G.", 59.00],
        ["Vestido floral garimpado", "Peça única, tamanho P.", 75.00],
        ["Bolsa de couro usada", "Em bom estado, com alça ajustável.", 120.00],
    ],
    "loja de calçados" => [
        ["Tênis casual", "Do 36 ao 44, solado leve.", 199.90],
        ["Sandália rasteira", "Couro sintético, do 34 ao 40.", 89.90],
        ["Bota coturno", "Cano curto, do 37 ao 44.", 279.90],
        ["Chinelo slide", "Confortável, várias cores.", 59.90],
    ],
    "petshop" => [
        ["Banho e tosa porte pequeno", "Agendamento pelo chat.", 70.00],
        ["Ração premium 10kg", "Para cães adultos.", 189.90],
        ["Areia higiênica 4kg", "Para gatos, controle de odor.", 34.90],
        ["Brinquedo mordedor", "Borracha atóxica, resistente.", 29.90],
    ],
    "loja de informática" => [
        ["Teclado mecânico", "Switch marrom, ABNT2.", 289.90],
        ["Mouse sem fio", "1600 DPI, bateria recarregável.", 119.90],
        ["SSD 480GB", "Leitura até 550MB/s.", 249.90],
        ["Suporte para notebook", "Alumínio, altura regulável.", 89.90],
    ],
    "barbearia" => [
        ["Corte masculino", "Tesoura e máquina, com finalização.", 45.00],
        ["Barba completa", "Toalha quente e navalha.", 35.00],
        ["Corte + barba", "O combo mais pedido.", 70.00],
        ["Pezinho", "Acabamento rápido entre cortes.", 20.00],
    ],
    "salão de beleza" => [
        ["Escova progressiva", "Cabelo até os ombros, valor a combinar acima disso.", 220.00],
        ["Corte feminino", "Com lavagem e escova.", 80.00],
        ["Manicure e pedicure", "Esmaltação inclusa.", 65.00],
        ["Hidratação profunda", "Três etapas, com finalização.", 110.00],
    ],
    "farmácia" => [
        ["Dipirona 500mg", "Caixa com 20 comprimidos.", 12.90],
        ["Protetor solar FPS 50", "Facial, 50g.", 59.90],
        ["Vitamina C 1g", "Efervescente, 10 comprimidos.", 24.90],
        ["Medição de pressão", "Gratuita para clientes.", 0.00],
    ],
    "academia" => [
        ["Plano mensal", "Musculação e aulas coletivas.", 119.90],
        ["Plano trimestral", "Três meses, com desconto.", 299.90],
        ["Aula avulsa", "Para experimentar.", 25.00],
        ["Avaliação física", "Com professor, agendada.", 60.00],
    ],
    "papelaria" => [
        ["Caderno 10 matérias", "Capa dura, 200 folhas.", 39.90],
        ["Kit canetas coloridas", "12 cores, ponta fina.", 27.90],
        ["Agenda 2027", "Planejamento semanal.", 54.90],
        ["Impressão colorida", "Por página A4.", 2.00],
    ],
    "floricultura" => [
        ["Buquê de rosas", "Doze unidades, embalagem para presente.", 129.90],
        ["Arranjo de mesa", "Flores da estação em vaso de cerâmica.", 99.90],
        ["Orquídea em vaso", "Com cuidados por escrito.", 89.90],
        ["Cesta de flores e chocolate", "Entrega na cidade.", 159.90],
    ],
    "ateliê de artesanato" => [
        ["Caneca personalizada", "Com nome ou frase à escolha.", 45.00],
        ["Quadro em macramê", "Feito à mão, 40x60cm.", 139.00],
        ["Kit lembrancinha", "Dez unidades para festa.", 120.00],
        ["Peça sob encomenda", "Orçamento pelo chat.", 0.00],
    ],
    "imobiliária" => [
        ["Avaliação de imóvel", "Visita e laudo de valor de mercado.", 0.00],
        ["Apartamento 2 quartos", "Bairro central, 62m².", 320000.00],
        ["Casa com quintal", "Três quartos, garagem para dois carros.", 480000.00],
        ["Sala comercial", "35m², pronta para uso.", 1800.00],
    ],
];

seed_titulo("lojas, agentes e produtos");

$pdo      = seed_pdo();
$usuarios = seed_usuarios_do_seed($pdo);

if (count($usuarios) < count(SEED_LOJAS)) {
    seed_diz("  aviso: " . count($usuarios) . " usuários para " . count(SEED_LOJAS) . " lojas — rode seed_usuarios.php antes.");
}

$buscaLoja = $pdo->prepare("SELECT id FROM lojas WHERE user_id = ?");
$criaLoja  = $pdo->prepare(
    "INSERT INTO lojas (user_id, nome, descricao, categoria, whatsapp, logo, banner, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$criaAgente = $pdo->prepare(
    "INSERT INTO loja_agente (loja_id, instrucoes, saudacao, ativo) VALUES (?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE instrucoes = VALUES(instrucoes), saudacao = VALUES(saudacao)"
);
$contaProdutos = $pdo->prepare("SELECT COUNT(*) FROM loja_produtos WHERE loja_id = ?");
$criaProduto   = $pdo->prepare(
    "INSERT INTO loja_produtos (loja_id, nome, descricao, preco, imagem, ordem, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);

foreach (SEED_LOJAS as $i => [$nome, $nicho, $categoria, $whats, $queryFoto]) {
    if (!isset($usuarios[$i])) {
        break;
    }

    $dono = (int)$usuarios[$i]["id"];

    $buscaLoja->execute([$dono]);
    $lojaId = (int)$buscaLoja->fetchColumn();

    if ($lojaId > 0) {
        seed_diz("  já existe: {$nome}");
    } else {
        seed_diz("  criando {$nome} ({$nicho})...");

        $config = seed_agente_da_loja($pdo, $nome, $nicho);

        $criaLoja->execute([
            $dono,
            $nome,
            $config["descricao"],
            $categoria,
            loja_whatsapp_digitos($whats),
            seed_pexels_imagem($queryFoto, "square"),
            seed_pexels_imagem($queryFoto, "landscape"),
            seed_data_passada(90),
        ]);

        $lojaId = (int)$pdo->lastInsertId();
        seed_conta("lojas");

        $criaAgente->execute([$lojaId, $config["instrucoes"], $config["saudacao"]]);
        seed_conta("agentes_loja");
    }

    // Catálogo: só se a loja ainda estiver vazia.
    $contaProdutos->execute([$lojaId]);

    if ((int)$contaProdutos->fetchColumn() > 0) {
        continue;
    }

    $produtos = seed_produtos_da_loja($pdo, $nome, $nicho, mt_rand(4, 6));

    foreach ($produtos as $ordem => $p) {
        $criaProduto->execute([
            $lojaId,
            mb_substr($p["nome"], 0, 200),
            mb_substr($p["descricao"], 0, 1000),
            $p["preco"],
            seed_pexels_imagem($p["nome"] . " " . $nicho, "square"),
            $ordem,
            seed_data_passada(80),
        ]);

        seed_conta("produtos");
    }

    seed_diz("    " . count($produtos) . " produtos no catálogo.");
}

$placar = seed_placar();

seed_diz(sprintf(
    "  %d lojas, %d agentes de loja, %d produtos",
    $placar["lojas"] ?? 0,
    $placar["agentes_loja"] ?? 0,
    $placar["produtos"] ?? 0
));

/* ======================================================================
   GERAÇÃO
   ====================================================================== */

/**
 * Descrição da loja, instruções do agente e saudação — uma chamada só.
 *
 * O plano pede instruções + saudação; a descrição entra no mesmo JSON
 * porque é o mesmo assunto e economiza uma chamada por loja num seed que
 * já encosta no teto por hora.
 */
function seed_agente_da_loja(PDO $pdo, string $loja, string $nicho): array
{
    $system = "Gere a configuração de um agente de IA que atende os clientes de uma {$nicho} chamada \"{$loja}\", no Brasil.\n"
        . "As instruções dizem: como saudar, que produtos e serviços típicos oferecer, como responder dúvidas comuns,\n"
        . "como conduzir para a venda e que tom de voz usar. Seja específico e prático, no máximo 400 palavras.\n"
        . "Responda APENAS com JSON: {\"descricao\": \"uma frase sobre a loja\", \"instrucoes\": \"...\", \"saudacao\": \"boas-vindas de 1 a 2 linhas\"}";

    $dados = seed_json(seed_ai_chamar($pdo, $system, "Configure o agente da {$loja}.", 1200, 8000));

    $descricao  = trim((string)($dados["descricao"]  ?? ""));
    $instrucoes = trim((string)($dados["instrucoes"] ?? ""));
    $saudacao   = trim((string)($dados["saudacao"]   ?? ""));

    // Fallback: template do nicho. Genérico, mas o agente responde com
    // ele sem passar vergonha, e o atendimento não fica mudo.
    if ($instrucoes === "") {
        $instrucoes =
            "Você atende os clientes da {$loja}, uma {$nicho}.\n"
            . "Horário: de segunda a sábado, das 9h às 19h. Domingo não abrimos.\n"
            . "Responda sempre em português do Brasil, com simpatia e sem enrolar.\n"
            . "Mostre os produtos do catálogo quando o cliente perguntar o que temos, com nome e preço.\n"
            . "Formas de pagamento: pix, débito e crédito.\n"
            . "Entrega na região, combinada pelo WhatsApp ao fechar o pedido.\n"
            . "Quando não souber alguma coisa, diga que vai confirmar com a equipe e leve a conversa para o WhatsApp.";
    }

    if ($saudacao === "") {
        $saudacao = "Oi! Bem-vindo à {$loja}. Me diz o que você procura que eu te mostro.";
    }

    if ($descricao === "") {
        $descricao = ucfirst($nicho) . " de bairro, com atendimento no Echo e pedido fechado pelo WhatsApp.";
    }

    return [
        "descricao"  => mb_substr($descricao, 0, 1000),
        "instrucoes" => mb_substr($instrucoes, 0, 6000),
        "saudacao"   => mb_substr($saudacao, 0, 500),
    ];
}

/** Catálogo da loja. Uma chamada por loja; fallback genérico do nicho. */
function seed_produtos_da_loja(PDO $pdo, string $loja, string $nicho, int $quantos): array
{
    $system = "Gere {$quantos} produtos ou serviços típicos de uma {$nicho} brasileira, com nome curto,\n"
        . "descrição de uma linha e preço realista em reais (só o número).\n"
        . "Responda APENAS com JSON: {\"produtos\": [{\"nome\": \"...\", \"descricao\": \"...\", \"preco\": 0.00}]}";

    $dados = seed_json(seed_ai_chamar($pdo, $system, "Catálogo da {$loja}.", 1000, 6000));
    $lista = $dados["produtos"] ?? null;

    $saida = [];

    if (is_array($lista)) {
        foreach ($lista as $p) {
            $nome = trim((string)($p["nome"] ?? ""));

            if ($nome === "") {
                continue;
            }

            $saida[] = [
                "nome"      => $nome,
                "descricao" => trim((string)($p["descricao"] ?? "")),
                // Preço pode vir "R$ 12,90" mesmo com a instrução: a
                // coluna é DECIMAL, então normaliza antes de gravar.
                "preco"     => seed_preco((string)($p["preco"] ?? "")),
            ];
        }
    }

    if (count($saida) >= 3) {
        return array_slice($saida, 0, $quantos);
    }

    $base = SEED_PRODUTOS_FALLBACK[$nicho] ?? null;

    if ($base === null) {
        $base = [
            ["Item do catálogo", "Peça disponível na loja, pergunte no chat.", 49.90],
            ["Serviço padrão",   "Atendimento combinado pelo WhatsApp.",       89.90],
            ["Combo da casa",    "A escolha mais pedida por aqui.",            120.00],
            ["Edição limitada",  "Enquanto durar o estoque.",                  159.90],
        ];
    }

    $saida = [];

    foreach (array_slice($base, 0, $quantos) as $p) {
        $saida[] = ["nome" => $p[0], "descricao" => $p[1], "preco" => (float)$p[2]];
    }

    return $saida;
}

/** "R$ 12,90" / "12.90" / 12.9 -> 12.90. Null quando não dá para ler. */
function seed_preco(string $bruto): ?float
{
    $n = preg_replace('/[^\d,.]/', "", $bruto);

    if ($n === "") {
        return null;
    }

    // Vírgula decimal do português vira ponto; ponto de milhar sai.
    if (strpos($n, ",") !== false) {
        $n = str_replace(".", "", $n);
        $n = str_replace(",", ".", $n);
    }

    return is_numeric($n) ? round((float)$n, 2) : null;
}
