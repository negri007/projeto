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

  // NEUTRO / UNIVERSAL — estilo premium minimalista que serve pra QUALQUER
  // produto que nao cai num nicho (camisinha, ferramenta, guarda-chuva...).
  // Preto/branco de alto contraste com um acento claro: parece caro e nao
  // impoe tema. A loja pode trocar `cor` pela cor da marca. NAO e fallback
  // pobre — e um estilo de primeira, igual aos outros.
  neutro: {
    accent: '#f2f4f8', accent2: '#9aa3b2', creme: '#f6f8fb', bg: '#0c0d11',
    fonte: 'display', filtro: 'contrast(1.12) saturate(1.02) brightness(1.02)',
    clima: 'neutra', badge: 'DESTAQUE',
  },
  saude: {
    accent: '#19b36b', accent2: '#a9ecc9', creme: '#eafaf1', bg: '#0a1712',
    fonte: 'display', filtro: 'brightness(1.05) contrast(1.05) saturate(1.05)',
    clima: 'relaxante', badge: 'SAUDE',
  },
  casa: {
    accent: '#c08457', accent2: '#e6c8a8', creme: '#f4ece1', bg: '#171310',
    fonte: 'serif', filtro: 'contrast(1.05) saturate(1.08) brightness(1.02)',
    clima: 'sofisticada', badge: 'PARA CASA',
  },
  pet: {
    accent: '#ffa62b', accent2: '#ffd9a1', creme: '#fff3e2', bg: '#141009',
    fonte: 'display', filtro: 'saturate(1.2) contrast(1.1)',
    clima: 'animada', badge: 'PET',
  },
  servicos: {
    accent: '#3f7bf2', accent2: '#b7cdfb', creme: '#eaf1fe', bg: '#0a0f1a',
    fonte: 'display', filtro: 'contrast(1.08) saturate(1.05)',
    clima: 'neutra', badge: 'SERVICOS',
  },
  infantil: {
    accent: '#ff5ea8', accent2: '#ffd1e6', creme: '#fff0f7', bg: '#1a0f1a',
    fonte: 'display', filtro: 'saturate(1.25) contrast(1.1) brightness(1.03)',
    clima: 'animada', badge: 'KIDS',
  },
};

// Fallback pra nicho desconhecido = o Neutro (que e um estilo forte, nao um
// tapa-buraco).
export const PRESET_PADRAO = PRESETS.neutro;

export function preset(nicho) {
  return PRESETS[nicho] || PRESET_PADRAO;
}

// TRILHA padrao por nicho (arquivo em public/mus, sem extensao). O servidor
// pode sortear de um pool maior no futuro; aqui fica o piso por clima.
export const TRILHA = {
  comida: 'comida_m', moda: 'moda_m', joia: 'joia_m',
  tech: 'tech_m', beleza: 'spa_m', fitness: 'fitness_m',
  neutro: 'moda_m', saude: 'spa_m', casa: 'joia_m',
  pet: 'comida_m', servicos: 'tech_m', infantil: 'fitness_m',
};
export function trilha(nicho) {
  return TRILHA[nicho] || 'moda_m';
}

// Familia de fonte resolvida (chamado dentro dos componentes que ja
// carregaram Anton). serif usa Georgia do sistema — nao baixa nada.
export function familia(p, anton) {
  return p.fonte === 'serif' ? 'Georgia, "Times New Roman", serif' : anton;
}

// Cor de texto que LE em cima do acento usado como fundo (botao/chip). Acento
// claro (ex.: Neutro) -> texto escuro; acento escuro -> texto branco. Evita
// branco-no-branco. Aceita "#rgb" ou "#rrggbb".
export function corTexto(hex) {
  let h = String(hex || '').replace('#', '');
  if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
  const r = parseInt(h.slice(0, 2), 16) || 0;
  const g = parseInt(h.slice(2, 4), 16) || 0;
  const b = parseInt(h.slice(4, 6), 16) || 0;
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return lum > 0.62 ? '#111' : '#fff';
}
