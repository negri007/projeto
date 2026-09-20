<?php
/**
 * As 20 lojas do seed — dados puros, sem efeito nenhum ao incluir.
 *
 * Arquivo separado porque dois módulos precisam da mesma lista: o 3 cria
 * as lojas e o 4 escreve os posts delas, e o 4 precisa saber o nicho e a
 * query de foto de cada uma para pedir o texto e a imagem certos.
 *
 * `[nome, nicho, categoria da interface, whatsapp, query da Pexels]`
 *
 * Sobre a coluna `categoria`: o plano dá "Pets", "Varejo", "Presentes" e
 * "Arte", que não existem no filtro do Comércio (js/comercio.js só
 * conhece Alimentação, Moda, Tecnologia, Beleza, Serviços, Saúde e
 * Outro). Loja com categoria fora dessa lista sumiria de qualquer filtro
 * e só apareceria na aba "Tudo". O nicho do plano continua inteiro — é
 * ele que guia o texto do agente, dos produtos e dos posts —, e a coluna
 * fica com a categoria que a interface sabe filtrar.
 *
 * WhatsApp fictício com DDD 18, como o plano manda. Passa pelo mesmo
 * `loja_whatsapp_digitos()` do cadastro de verdade antes de ir ao banco.
 */

const SEED_LOJAS = [
    ["Sabor & Arte",        "restaurante",            "Alimentação", "18991110001", "restaurant interior"],
    ["Lanche Rápido",       "lanchonete",             "Alimentação", "18991110002", "snack bar burger"],
    ["Pizza do Bairro",     "pizzaria",               "Alimentação", "18991110003", "pizza oven"],
    ["Burger House",        "hamburgueria",           "Alimentação", "18991110004", "burger restaurant"],
    ["Açaí da Vila",        "loja de açaí",           "Alimentação", "18991110005", "acai bowl fruit"],
    ["Moda Feminina SP",    "loja de moda feminina",  "Moda",        "18991110006", "women fashion store"],
    ["Estilo Masculino",    "loja de moda masculina", "Moda",        "18991110007", "men fashion store"],
    ["Mundo Kids",          "loja de moda infantil",  "Moda",        "18991110008", "kids clothing store"],
    ["Brechó Vintage",      "brechó",                 "Moda",        "18991110009", "vintage clothing rack"],
    ["Passos & Estilo",     "loja de calçados",       "Moda",        "18991110010", "shoe store sneakers"],
    ["PetAmor",             "petshop",                "Outro",       "18991110011", "pet shop dog"],
    ["TechZone",            "loja de informática",    "Tecnologia",  "18991110012", "computer store tech"],
    ["Barbearia do João",   "barbearia",              "Beleza",      "18991110013", "barber shop"],
    ["Salão Bella",         "salão de beleza",        "Beleza",      "18991110014", "beauty salon hair"],
    ["Farmácia Saúde+",     "farmácia",               "Saúde",       "18991110015", "pharmacy shelf"],
    ["Academia FitLife",    "academia",               "Saúde",       "18991110016", "gym equipment"],
    ["Papelaria Criativa",  "papelaria",              "Outro",       "18991110017", "stationery store"],
    ["Floricultura Jardim", "floricultura",           "Outro",       "18991110018", "flower shop"],
    ["Ateliê Artesanal",    "ateliê de artesanato",   "Outro",       "18991110019", "handmade craft workshop"],
    ["Imobiliária Lar",     "imobiliária",            "Serviços",    "18991110020", "real estate house"],
];

/**
 * Nicho, categoria e query de foto de uma loja pelo nome.
 *
 * Loja que não está na lista (criada à mão, fora do seed) cai num
 * genérico em vez de null: quem chama usa isso para pedir texto e foto, e
 * um null ali viraria erro no meio do seed.
 */
function seed_meta_da_loja(string $nome): array
{
    foreach (SEED_LOJAS as [$n, $nicho, $categoria, $whats, $foto]) {
        if ($n === $nome) {
            return ["nicho" => $nicho, "categoria" => $categoria, "whatsapp" => $whats, "foto" => $foto];
        }
    }

    return ["nicho" => "loja de bairro", "categoria" => "Outro", "whatsapp" => "", "foto" => "small business store"];
}
