// PRESETS DE NICHO — o "estilo" de cada segmento.
//
// Cada nicho NAO e um video fixo: e uma linguagem visual (cor, tipografia,
// filtro, clima). Os dados da loja (foto, nome, preco) e o sorteio de
// variante/trilha entram por cima. Isso e o que impede dois anuncios do
// mesmo nicho de sairem iguais (ver docs/plans/motor-anuncios.md).
//
// Campos:
//   accent   cor de destaque principal (titulo, preco, CTA)
//   accent2  cor secundaria (badges, detalhes)
//   creme    cor clara de texto/fundo de tag
//   bg       cor de fundo do card final
//   fonte    'display' (Anton) | 'serif' (Georgia, sem download)
//   filtro   filtro CSS aplicado na foto (contraste/saturacao)
//   clima    rotulo do humor da trilha — a fila de render sorteia a musica
//   badge    rotulo curto que aparece no topo (ex.: NOVO, EXCLUSIVO)

export const PRESETS = {
  comida: {
    accent: '#ff7a1a', accent2: '#ffd08a', creme: '#fff3e6', bg: '#140a06',
    fonte: 'display', filtro: 'contrast(1.12) saturate(1.25)',
    clima: 'animada', badge: 'NOVO',
  },
  moda: {
    accent: '#c9a24b', accent2: '#e8d9b8', creme: '#f6efe3', bg: '#1a1512',
    fonte: 'serif', filtro: 'contrast(1.05) saturate(1.05) brightness(1.02)',
    clima: 'sofisticada', badge: 'COLECAO',
  },
  joia: {
    accent: '#cba765', accent2: '#f0e2bf', creme: '#f4ecd8', bg: '#0a0a0a',
    fonte: 'serif', filtro: 'contrast(1.1) saturate(1.05)',
    clima: 'refinada', badge: 'EXCLUSIVO',
  },
  tech: {
    accent: '#2ea6ff', accent2: '#9be7ff', creme: '#eaf6ff', bg: '#05070d',
    fonte: 'display', filtro: 'contrast(1.1) saturate(1.15) brightness(1.05)',
    clima: 'eletronica', badge: 'LANCAMENTO',
  },
  beleza: {
    accent: '#7bb39a', accent2: '#cfe6da', creme: '#eef5f0', bg: '#12201a',
    fonte: 'serif', filtro: 'contrast(1.03) saturate(1.08) brightness(1.04)',
    clima: 'relaxante', badge: 'BEM-ESTAR',
  },
  fitness: {
    accent: '#35d06a', accent2: '#b8ffcf', creme: '#eafff0', bg: '#0d1410',
    fonte: 'display', filtro: 'contrast(1.15) saturate(1.2)',
    clima: 'pulsante', badge: 'SUPERE-SE',
  },
};

// Fallback pra nicho desconhecido: um estilo neutro escuro.
export const PRESET_PADRAO = {
  accent: '#4f8cff', accent2: '#bcd3ff', creme: '#eef3fb', bg: '#0b0e14',
  fonte: 'display', filtro: 'contrast(1.08) saturate(1.1)',
  clima: 'neutra', badge: 'DESTAQUE',
};

export function preset(nicho) {
  return PRESETS[nicho] || PRESET_PADRAO;
}

// TRILHA padrao por nicho (arquivo em public/mus, sem extensao). O servidor
// pode sortear de um pool maior no futuro; aqui fica o piso por clima.
export const TRILHA = {
  comida: 'comida_m', moda: 'moda_m', joia: 'joia_m',
  tech: 'tech_m', beleza: 'spa_m', fitness: 'fitness_m',
};
export function trilha(nicho) {
  return TRILHA[nicho] || 'comida_m';
}

// Familia de fonte resolvida (chamado dentro dos componentes que ja
// carregaram Anton). serif usa Georgia do sistema — nao baixa nada.
export function familia(p, anton) {
  return p.fonte === 'serif' ? 'Georgia, "Times New Roman", serif' : anton;
}
