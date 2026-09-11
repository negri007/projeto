/* ============================================================================
   js/echo-bit.js — Bit, o mascote do ECHO

   Passarinho que mora na interface: voa pela tela e pousa nas linhas reais do
   layout (borda de post, topo de card, divisor da barra lateral). Não depende
   de nada: sem biblioteca, sem CSS próprio, sem chamada de API.

   Como incluir numa página:
       <script src="js/echo-bit.js"></script>

   API pública (window.EchoBit):
       EchoBit.ligar() / .desligar() / .alternar()   liga, desliga e lembra
       EchoBit.ativo()                               true se está na tela
       EchoBit.levarAoLixo(el, opcoes)               ele busca o elemento,
                                                     pega com os pés e leva
       EchoBit.poleiros()                            linhas de pouso válidas
       EchoBit.marcar(el) / .desmarcar(el)           poleiro manual
       EchoBit.debug(true)                           desenha as linhas

   Ele não aparece em tela estreita (< 768px), com prefers-reduced-motion, nem
   se o usuário desligou (fica em localStorage).
   ========================================================================== */

(function () {
"use strict";

/* ----------------------------------------------------------------------------
   O que separa isto de "um passarinho genérico voando na tela":

   - Os pés ficam TRAVADOS na borda real de um elemento do DOM. O corpo balança
     em cima; as pernas absorvem o balanço por IK de dois ossos. É o corpo que
     se mexe sobre o pé, nunca o pé que acompanha o corpo.
   - Os dedos DOBRAM por cima da linha e descem pela frente dela, cruzando o
     y=0. Essa travessia (oclusão) é a pista número um de "empoleirado".
   - A cauda cai ABAIXO da linha. Pista número dois. Bicho de pé no chão tem
     cauda pra cima; bicho empoleirado tem cauda pendurada.
   - Perna de pousado é curta: ~9px de tarso para ~55px de bicho. Perna
     comprida e reta é exatamente o que dava cara de pinguim em pé.
   - Pousar tem freada com asas abertas, pernas esticando à frente, impacto com
     recuo elástico, a linha do layout vergando sob o peso e reajuste de garra.
   -------------------------------------------------------------------------- */

var SVGNS = "http://www.w3.org/2000/svg";
function $(s, r) { return (r || ceu || document).querySelector(s); }
function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
function lerp(a, b, t) { return a + (b - a) * t; }
function rnd(a, b) { return a + Math.random() * (b - a); }
function sorteio(a) { return a[(Math.random() * a.length) | 0]; }
function easeOut(t) { return 1 - Math.pow(1 - t, 3); }
function grau(r) { return (r * 180) / Math.PI; }

var PARADO = matchMedia("(prefers-reduced-motion: reduce)").matches;
var CHAVE_PREF = "echo_bit_desligado";
var LARGURA_MIN = 768;          // abaixo disso a tela é do conteúdo, não dele

function preferenciaDesligada() {
  try { return localStorage.getItem(CHAVE_PREF) === "1"; } catch (e) { return false; }
}
function guardarPreferencia(desligado) {
  try {
    if (desligado) localStorage.setItem(CHAVE_PREF, "1");
    else localStorage.removeItem(CHAVE_PREF);
  } catch (e) { /* modo privado: só não lembra */ }
}
function podeAparecer() {
  return !PARADO && !preferenciaDesligada() && innerWidth >= LARGURA_MIN;
}

/* ---------------------------------------------------------------- paleta */
var C = {
  dorso: "#1d9bf0", capuz: "#0e4f8f", asa: "#1878c4", asaEsc: "#0d4a85",
  peito: "#d8ecff", faceCl: "#a9d8ff",
  bico: "#ffb648", bicoEs: "#d98a1c",
  pata: "#f0a73c", pataEs: "#b9741d",
  olho: "#06141f", luz: "#ffffff"
};

/* =========================================================================
   1. Desenho — dois retratos do mesmo bicho

   Coordenadas locais: (0,0) é o PONTO DE CONTATO do pé com a linha de pouso.
   y negativo sobe. O bicho olha para +x. Espelhar é escala -1 no grupo raiz,
   então tudo aqui dentro está sempre virado para a direita.
   ======================================================================= */

var DESENHO = [
'<defs>',
'  <radialGradient id="gPeito" cx="50%" cy="36%" r="72%">',
'    <stop offset="0%" stop-color="#ffffff"/>',
'    <stop offset="58%" stop-color="' + C.peito + '"/>',
'    <stop offset="100%" stop-color="#9ecdf2"/>',
'  </radialGradient>',
'  <linearGradient id="gDorso" x1="0" y1="0" x2="0" y2="1">',
'    <stop offset="0%" stop-color="#49b4ff"/>',
'    <stop offset="55%" stop-color="' + C.dorso + '"/>',
'    <stop offset="100%" stop-color="#0f5fa8"/>',
'  </linearGradient>',
'  <linearGradient id="gAsa" x1="0" y1="0" x2="1" y2="1">',
'    <stop offset="0%" stop-color="#58bcff"/>',
'    <stop offset="70%" stop-color="' + C.asa + '"/>',
'    <stop offset="100%" stop-color="' + C.asaEsc + '"/>',
'  </linearGradient>',
'  <filter id="fSombra" x="-60%" y="-300%" width="220%" height="700%">',
'    <feGaussianBlur stdDeviation="2.4"/>',
'  </filter>',
'  <filter id="fBrilho" x="-70%" y="-400%" width="240%" height="900%">',
'    <feGaussianBlur stdDeviation="2.6"/>',
'  </filter>',
'</defs>',

// marca de pressão na superfície + a linha do layout vergando
'<g id="contato">',
'  <ellipse id="sombra" rx="15" ry="2.6" fill="#000" opacity=".5" filter="url(#fSombra)"/>',
'  <path id="vergadura" fill="none" stroke="' + C.dorso + '" stroke-width="1.8" stroke-linecap="round" opacity="0" filter="url(#fBrilho)"/>',
'  <path id="vergadura2" fill="none" stroke="#a9dcff" stroke-width="1" stroke-linecap="round" opacity="0"/>',
'  <path id="pressao" fill="none" stroke="#cfeaff" stroke-width="1.5" stroke-linecap="round" opacity="0"/>',
'</g>',

'<g id="efeitos"></g>',

'<g id="passaro">',

// pernas: redesenhadas a cada quadro por IK, por baixo do tronco
'  <g id="pernas">',
'    <path id="pernaB" fill="none" stroke="' + C.pataEs + '" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
'    <path id="pernaA" fill="none" stroke="' + C.pata + '" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round"/>',
'  </g>',

'  <g id="tronco">',

/* ================= PERFIL: voo, pouso, decolagem ================= */
'    <g id="vPerfil">',

'      <g id="asaLonge"><path id="asaLongeP" fill="' + C.asaEsc + '"/></g>',

// cauda: sai do corpo e cai PARA BAIXO da linha de pouso
'      <g id="caudaP" transform="translate(-11,-21)">',
'        <path d="M 1,-6 C -5,-2 -12,7 -15,20 C -16,25.5 -14,29.5 -11,30.5 L -3,25 L 0.5,16 L 4,7 Z" fill="url(#gDorso)"/>',
'        <path d="M -14.4,26.8 L -11,30.5 L -3,25 L -4.8,21 Z" fill="' + C.peito + '" opacity=".9"/>',
'        <path d="M -2,2 C -6,7 -10,15 -12,23" fill="none" stroke="' + C.capuz + '" stroke-width=".9" opacity=".5"/>',
'      </g>',

// corpo
'      <path d="M 12.5,-30 C 16,-24.5 14.2,-16.5 8,-12.2 C 2,-8.2 -7.5,-9.8 -11.5,-16 C -15.6,-22.8 -12,-34.5 -3.5,-39.2 C 3.5,-43 9.6,-35.8 12.5,-30 Z" fill="url(#gDorso)"/>',
'      <path d="M 12.5,-30 C 16,-24.5 14.2,-16.5 8,-12.2 C 3.5,-9.2 -2.5,-9.6 -6.5,-12 C -5,-20 -0.5,-28.5 5,-33.5 C 9,-35 11.4,-32.6 12.5,-30 Z" fill="url(#gPeito)" opacity=".95"/>',
// marca do Echo no peito: eco em três arcos
'      <g opacity=".45" fill="none" stroke="#3a86c8" stroke-linecap="round">',
'        <path d="M 3.5,-26.5 A 5.2,5.2 0 0 1 9.5,-22.5" stroke-width="1.2"/>',
'        <path d="M 1.6,-22.6 A 7.6,7.6 0 0 1 10,-17.6" stroke-width="1.1"/>',
'        <path d="M 0,-18.4 A 9.8,9.8 0 0 1 9.4,-12.6" stroke-width="1"/>',
'      </g>',

'      <g id="asaPerto">',
'        <path id="asaPertoP" fill="url(#gAsa)" stroke="' + C.asaEsc + '" stroke-width=".7"/>',
'        <path id="asaPertoBarra" fill="' + C.peito + '" opacity=".8"/>',
'      </g>',

// cabeça (pivô no pescoço)
'      <g id="cabecaP" transform="translate(3,-38)">',
'        <g fill="url(#gDorso)">',
'          <path d="M -1.5,-13 C -4,-19 -7.5,-21.5 -10,-21 C -8.5,-17.5 -6,-14.5 -3,-12.5 Z"/>',
'          <path d="M 1.5,-14.5 C 0.6,-21.5 -1.4,-25 -4,-25.5 C -3.6,-21 -2.6,-17 -0.6,-13.6 Z"/>',
'          <path d="M 4.5,-14 C 5.6,-20 5,-23.6 3,-24.8 C 2,-21 1.6,-17.4 2.2,-13.8 Z"/>',
'        </g>',
'        <circle cx="4" cy="-8" r="9.6" fill="url(#gDorso)"/>',
'        <path d="M -4.6,-10.6 C -3,-16.4 2.2,-18.4 8,-16.4 C 11.6,-15 13.4,-12.2 13.4,-9.4 C 8,-12.6 0.6,-13 -4.6,-10.6 Z" fill="' + C.capuz + '"/>',
'        <path d="M -5.4,-8.6 C -2.6,-9.8 1.2,-9.4 3.2,-7 C 1.2,-3.4 -2.4,-1.4 -5.2,-2 C -6.4,-4.2 -6.2,-6.8 -5.4,-8.6 Z" fill="' + C.faceCl + '" opacity=".9"/>',
'        <path id="bicoPsup" d="M 12.4,-10.2 L 23.6,-7.4 L 12.4,-6.4 Z" fill="' + C.bico + '"/>',
'        <path id="bicoPinf" d="M 12.4,-6.2 L 23.6,-7.4 L 12.4,-4.4 Z" fill="' + C.bicoEs + '"/>',
'        <g id="olhoP">',
'          <ellipse id="olhoPglobo" cx="7.4" cy="-9.6" rx="3.5" ry="3.5" fill="' + C.olho + '"/>',
'          <circle id="olhoPluz" cx="8.6" cy="-10.8" r="1.25" fill="' + C.luz + '"/>',
'          <circle cx="6.2" cy="-8.2" r=".62" fill="' + C.luz + '" opacity=".5"/>',
'        </g>',
'      </g>',

'    </g>',

/* ================= FRENTE: parado, empoleirado ================= */
'    <g id="vFrente">',

// cauda por trás, pendurada abaixo da linha
'      <g id="caudaF" transform="translate(0,-13)">',
'        <path d="M -6.6,-2 L 6.6,-2 L 5.2,21 L 0,27 L -5.2,21 Z" fill="url(#gDorso)" stroke="' + C.capuz + '" stroke-width=".8"/>',
'        <path d="M -5.6,15 L 5.6,15 L 5.2,20.4 L -5.2,20.4 Z" fill="#eaf6ff" opacity=".9"/>',
'        <path d="M 0,0 L 0,24" stroke="' + C.capuz + '" stroke-width=".9" opacity=".5"/>',
'      </g>',

// corpo: largo embaixo, barriga quase encostando no poleiro
'      <path d="M 0,-36.5 C 12.8,-36.5 19,-29 19,-20.6 C 19,-12.4 12.2,-6.4 0,-6.4 C -12.2,-6.4 -19,-12.4 -19,-20.6 C -19,-29 -12.8,-36.5 0,-36.5 Z" fill="url(#gDorso)"/>',
'      <path d="M 0,-31.6 C 8.8,-31.6 13.4,-25.6 13.4,-19.6 C 13.4,-13.2 8.4,-8 0,-8 C -8.4,-8 -13.4,-13.2 -13.4,-19.6 C -13.4,-25.6 -8.8,-31.6 0,-31.6 Z" fill="url(#gPeito)"/>',
'      <path d="M -9.6,-8.6 C -5,-6.6 5,-6.6 9.6,-8.6 C 6,-5.6 -6,-5.6 -9.6,-8.6 Z" fill="' + C.capuz + '" opacity=".55"/>',
'      <path d="M -13,-30.2 C -7.4,-34.4 7.4,-34.4 13,-30.2" fill="none" stroke="#eaf6ff" stroke-width="2.4" opacity=".75" stroke-linecap="round"/>',
'      <g opacity=".4" fill="none" stroke="#3a86c8" stroke-linecap="round">',
'        <path d="M -5.4,-22.6 A 5.4,5.4 0 0 0 5.4,-22.6" stroke-width="1.2"/>',
'        <path d="M -7.8,-19.4 A 7.8,7.8 0 0 0 7.8,-19.4" stroke-width="1.1"/>',
'        <path d="M -10,-15.8 A 10,10 0 0 0 10,-15.8" stroke-width="1"/>',
'      </g>',

'      <g id="asaFE">',
'        <path d="M -13,-32.8 C -20.8,-29.4 -22.4,-18 -17.2,-8.8 C -16,-6.8 -13.4,-7.4 -12.6,-9.8 C -11,-18 -10.2,-26.4 -10,-32 Z" fill="url(#gAsa)" stroke="' + C.asaEsc + '" stroke-width=".7"/>',
'        <path d="M -19.4,-14.6 C -17.4,-13.8 -15.4,-14.4 -14.4,-15.8" fill="none" stroke="' + C.peito + '" stroke-width="1.8" opacity=".8"/>',
'        <g stroke="' + C.asaEsc + '" stroke-width=".7" opacity=".7" fill="none">',
'          <path d="M -16.4,-10.6 C -15.4,-16.4 -14,-23 -12.6,-28.6"/>',
'          <path d="M -18.6,-12.4 C -17.8,-18.4 -16.4,-25 -15,-30"/>',
'        </g>',
'      </g>',
'      <g id="asaFD">',
'        <path d="M 13,-32.8 C 20.8,-29.4 22.4,-18 17.2,-8.8 C 16,-6.8 13.4,-7.4 12.6,-9.8 C 11,-18 10.2,-26.4 10,-32 Z" fill="url(#gAsa)" stroke="' + C.asaEsc + '" stroke-width=".7"/>',
'        <path d="M 19.4,-14.6 C 17.4,-13.8 15.4,-14.4 14.4,-15.8" fill="none" stroke="' + C.peito + '" stroke-width="1.8" opacity=".8"/>',
'        <g stroke="' + C.asaEsc + '" stroke-width=".7" opacity=".7" fill="none">',
'          <path d="M 16.4,-10.6 C 15.4,-16.4 14,-23 12.6,-28.6"/>',
'          <path d="M 18.6,-12.4 C 17.8,-18.4 16.4,-25 15,-30"/>',
'        </g>',
'      </g>',

'      <g id="cabecaF" transform="translate(0,-34.2)">',
'        <g fill="url(#gDorso)">',
'          <path d="M -3.6,-16.4 C -7,-22.4 -10.4,-24.6 -12.6,-23.8 C -10.6,-20.2 -7.6,-17.4 -4.6,-15.4 Z"/>',
'          <path d="M 0,-18.2 C 0,-25.4 -1.4,-29.2 -3.8,-30.2 C -3.8,-25.6 -2.6,-21.4 -1,-17.6 Z"/>',
'          <path d="M 3.6,-16.4 C 5.4,-22.6 5.2,-26.2 3.4,-27.6 C 2,-23.8 1.2,-20 1.4,-16 Z"/>',
'        </g>',
'        <circle cx="0" cy="-10.4" r="11.6" fill="url(#gDorso)"/>',
'        <path d="M -11.4,-12.6 C -10.2,-18.6 -5.4,-22 0,-22 C 5.4,-22 10.2,-18.6 11.4,-12.6 C 7.4,-16.4 3.6,-17.6 0,-17.6 C -3.6,-17.6 -7.4,-16.4 -11.4,-12.6 Z" fill="' + C.capuz + '"/>',
'        <ellipse cx="0" cy="-6.2" rx="9.4" ry="6.6" fill="' + C.faceCl + '" opacity=".85"/>',
'        <g id="olhoFE">',
'          <ellipse id="olhoFEglobo" cx="-5" cy="-11" rx="3.5" ry="3.5" fill="' + C.olho + '"/>',
'          <circle id="olhoFEluz" cx="-4" cy="-12.1" r="1.2" fill="' + C.luz + '"/>',
'        </g>',
'        <g id="olhoFD">',
'          <ellipse id="olhoFDglobo" cx="5" cy="-11" rx="3.5" ry="3.5" fill="' + C.olho + '"/>',
'          <circle id="olhoFDluz" cx="6" cy="-12.1" r="1.2" fill="' + C.luz + '"/>',
'        </g>',
'        <path id="bicoFsup" d="M 0,-9 L 4.4,-4.4 L 0,-3.2 L -4.4,-4.4 Z" fill="' + C.bico + '"/>',
'        <path id="bicoFinf" d="M 0,-3.6 L 4.1,-4.2 L 0,0.8 L -4.1,-4.2 Z" fill="' + C.bicoEs + '"/>',
'      </g>',
'    </g>',

'  </g>',

// pés por último: os dedos precisam aparecer POR CIMA da borda real
'  <g id="peA"></g>',
'  <g id="peB"></g>',
'</g>',

// A borda do elemento, repintada POR CIMA do pe. O dedo sai de tras da linha
// e reaparece na frente dela: e oclusao de verdade, e e o que faz o olho
// aceitar que ele esta agarrado em alguma coisa, nao encostado nela.
'<g id="tiras" opacity="0"><rect id="tira2"/><rect id="tira"/></g>'
].join("\n");

// O céu é criado aqui: nenhuma página precisa ganhar marcação nova, e nenhum
// seletor de CSS do projeto encosta nele.
var ceu = document.createElementNS(SVGNS, "svg");
ceu.setAttribute("id", "echo-bit-ceu");
ceu.setAttribute("aria-hidden", "true");
// camada 900: acima da barra lateral (100) e do cabecalho (90), abaixo de
// dropdown (1050), sino (1080), toast (2000), dialogo (2100) e dos modais do
// Bootstrap. Ele voa por cima do layout, mas some atras de qualquer coisa que
// peca atencao do usuario.
ceu.style.cssText = "position:fixed;top:0;left:0;width:100vw;height:100vh;" +
                    "z-index:900;pointer-events:none;overflow:visible;";
ceu.innerHTML = DESENHO;
document.body.appendChild(ceu);

var el = {
  contato: $("#contato"), sombra: $("#sombra"),
  verga: $("#vergadura"), verga2: $("#vergadura2"), pressao: $("#pressao"),
  efeitos: $("#efeitos"),
  passaro: $("#passaro"), tronco: $("#tronco"),
  vPerfil: $("#vPerfil"), vFrente: $("#vFrente"),
  pernaA: $("#pernaA"), pernaB: $("#pernaB"),
  peA: $("#peA"), peB: $("#peB"),
  cabecaP: $("#cabecaP"), cabecaF: $("#cabecaF"),
  caudaP: $("#caudaP"), caudaF: $("#caudaF"),
  asaPerto: $("#asaPerto"), asaLonge: $("#asaLonge"),
  asaPertoP: $("#asaPertoP"), asaLongeP: $("#asaLongeP"), asaPertoBarra: $("#asaPertoBarra"),
  asaFE: $("#asaFE"), asaFD: $("#asaFD"),
  tiras: $("#tiras"), tira: $("#tira"), tira2: $("#tira2"),
  bicoFinf: $("#bicoFinf"), bicoPinf: $("#bicoPinf")
};
var olhos = [
  [$("#olhoPglobo"), $("#olhoPluz")],
  [$("#olhoFEglobo"), $("#olhoFEluz")],
  [$("#olhoFDglobo"), $("#olhoFDluz")]
];

/* =========================================================================
   2. Poleiros — as linhas REAIS do layout

   Um poleiro é a borda de cima de um elemento que existe na página. O pé do
   bicho fica exatamente nessa coordenada e ela é relida a cada quadro, então
   ele continua colado mesmo com scroll ou redimensionamento.
   ======================================================================= */

var MIN_LARGURA = 54;

/* Ele pousa em qualquer linha que o layout realmente desenhe, em qualquer
   página — não há lista de classes para manter. A varredura olha o que está
   na tela e pergunta uma coisa só: existe uma linha visível nesta aresta?

   Uma aresta vale como poleiro quando:
     - tem border-top (ou border-bottom) com cor opaca; ou
     - o elemento é um fio (altura <= 3px) com fundo próprio — divisores; ou
     - o fundo dele é opaco e DIFERENTE do fundo de quem está atrás.

   A última condição é o que separa uma borda de verdade de uma aresta
   invisível: caixa transparente dentro de caixa transparente não desenha
   linha nenhuma, e pousar ali é flutuar no meio do nada. */
var VARREDURA = "div,section,article,aside,header,footer,nav,main,form,fieldset," +
                "ul,ol,li,table,tr,td,th,a,button,label,img,input,textarea,select," +
                "h1,h2,h3,h4,h5,h6,blockquote,pre,[data-poleiro]";

var FORA = "#echo-bit-ceu,#echo-bit-lixeira,.modal,.offcanvas,.echo-dialog-backdrop," +
           ".echo-toast-stack,.echo-toast,[data-bit-nao-pousar]";

var TETO_VARREDURA = 1400;        // candidatos examinados por rodada
var estilos = new WeakMap();      // cache de getComputedStyle por elemento

function opaca(c) {
  return !!c && c !== "transparent" && c.indexOf("rgba(0, 0, 0, 0)") === -1;
}

// Fundo que aparece atrás do elemento: sobe na árvore até achar quem pinta.
function fundoAtras(el) {
  var n = el.parentElement;
  while (n && n !== document.documentElement) {
    var c = getComputedStyle(n).backgroundColor;
    if (opaca(c)) return c;
    n = n.parentElement;
  }
  return getComputedStyle(document.body).backgroundColor || "rgb(0, 0, 0)";
}

/* Cor e espessura da linha, para repintá-la por cima da garra — e, de quebra,
   o teste de "isto é mesmo uma linha". Devolve null quando não é. */
function estiloDaAresta(el, borda) {
  var chave = borda === "base" ? "_base" : "_topo";
  var cache = estilos.get(el);
  if (cache && cache[chave] !== undefined) return cache[chave];
  if (!cache) { cache = {}; estilos.set(el, cache); }

  var cs = getComputedStyle(el), r = el.getBoundingClientRect();
  var lb = parseFloat(borda === "base" ? cs.borderBottomWidth : cs.borderTopWidth) || 0;
  var cb = borda === "base" ? cs.borderBottomColor : cs.borderTopColor;
  var res = null;

  if (lb > 0 && opaca(cb)) {
    res = { cor: cb, esp: Math.max(1, lb),
            cor2: opaca(cs.backgroundColor) ? cs.backgroundColor : null, esp2: 3 };
  } else if (r.height <= 3 && opaca(cs.backgroundColor)) {
    res = { cor: cs.backgroundColor, esp: Math.max(1, r.height), cor2: null, esp2: 0 };
  } else if (opaca(cs.backgroundColor) && cs.backgroundColor !== fundoAtras(el)) {
    res = { cor: cs.backgroundColor, esp: 3.5, cor2: null, esp2: 0 };
  }

  cache[chave] = res;
  return res;
}

function estiloPoleiro(p) {
  return estiloDaAresta(p.el, p.borda);
}

// Está realmente visível ali? Um elemento pode estar dentro de um container com
// scroll (a lista de mensagens), coberto pelo cabeçalho fixo ou por um modal.
// O teste de acerto resolve os três de uma vez.
function descoberto(el, x, y) {
  var alvo = document.elementFromPoint(x, y);
  if (!alvo) return false;
  return alvo === el || el.contains(alvo) || alvo.contains(el);
}

function listarPoleiros() {
  var saida = [], vh = innerHeight, vw = innerWidth;
  var nos = document.querySelectorAll(VARREDURA);
  var lim = Math.min(nos.length, TETO_VARREDURA);

  for (var i = 0; i < lim; i++) {
    var e = nos[i];
    if (e === ceu || ceu.contains(e) || e.closest(FORA)) continue;

    var r = e.getBoundingClientRect();
    if (r.width < MIN_LARGURA || r.width > vw + 80) continue;
    if (r.height < 8) continue;
    if (r.right < 24 || r.left > vw - 24) continue;

    var manual = e.hasAttribute("data-poleiro");

    for (var b = 0; b < 2; b++) {
      var borda = b ? "base" : "topo";
      var y = b ? r.bottom : r.top;
      if (y < 56 || y > vh - 40) continue;
      // marcado à mão dispensa o teste de linha; o resto precisa passar
      if (!manual && !estiloDaAresta(e, borda)) continue;
      if (!descoberto(e, r.left + r.width / 2, y + (b ? -4 : 4))) continue;
      saida.push({ el: e, borda: borda, area: r.width * r.height, y: y });
    }
  }

  // Caixas aninhadas encostam a mesma aresta no mesmo lugar (um cartão dentro
  // de uma coluna dentro de um main). Quem fica é a menor, que é a peça que o
  // olho identifica como sendo a linha.
  saida.sort(function (a, b) { return a.area - b.area; });
  var limpos = [];
  for (var j = 0; j < saida.length; j++) {
    var c = saida[j], repetido = false;
    for (var k = 0; k < limpos.length; k++) {
      if (Math.abs(limpos[k].y - c.y) <= 3 && limpos[k].el.contains(c.el)) { repetido = true; break; }
      if (Math.abs(limpos[k].y - c.y) <= 3 && c.el.contains(limpos[k].el)) { repetido = true; break; }
    }
    if (!repetido) limpos.push(c);
  }
  return limpos;
}

function linhaDo(p) {
  var r = p.el.getBoundingClientRect();
  var y = p.borda === "base" ? r.bottom : r.top;
  var rec = Math.min(16, r.width * 0.2);
  return { x0: r.left + rec, x1: r.right - rec, y: y, larg: r.width };
}
function pontoDoPoleiro(p) {
  var l = linhaDo(p);
  return { x: lerp(l.x0, l.x1, p.frac), y: l.y, l: l };
}

/* =========================================================================
   3. Estado
   ======================================================================= */

var B = {
  x: innerWidth * 0.5, y: -60,
  vx: 120, vy: 40,
  rumo: 1,
  vista: "perfil",
  fase: "voo",
  t: 0,
  poleiro: null, destino: null, destinoFuturo: null,
  alvo: { x: innerWidth * 0.5, y: 200 },

  corpoX: 0, corpoY: 0, pitch: 0,
  escX: 1, escY: 1,
  aperto: 1,
  peY: 0,
  batida: 0, batidaVel: 0,
  caudaAng: 0, caudaAlvo: 0,
  cabecaAng: 0, cabecaAlvo: 0, cabecaTombo: 0,
  pescoco: 0,
  piscar: 2,
  esc: 0.9,
  fofo: 0, coca: 0, bicoAberto: 0,

  proximo: 2.2,
  gesto: null, gestoT: 0, gestoDur: 0, gestoSinal: 1,
  trocaEm: 12,                          // segundos ate procurar outro poleiro
  ultimaLinhaY: 0, desequilibrio: 0,    // reacao ao poleiro se mexer (scroll)
  andar: null,                          // ciclo de passos ao longo da linha
  olhar: null,                          // ponto que rouba a atenção por uns segundos
  sono: 0,                              // 0 desperto, 1 caindo de sono (madrugada)
  tarefa: null,
  susto: 0,
  freadaDe: null,
  _largou: 0, _pousouPulo: 0, _rumoEsc: 0
};

var mouse = { x: innerWidth / 2, y: innerHeight / 2, vivo: false };
addEventListener("mousemove", function (e) { mouse.x = e.clientX; mouse.y = e.clientY; mouse.vivo = true; });

/* =========================================================================
   4. Pernas e pés
   ======================================================================= */

// IK de dois ossos. O tornozelo da ave dobra PARA TRÁS: daí o parâmetro lado.
function ik(hx, hy, fx, fy, a, b, lado) {
  var dx = fx - hx, dy = fy - hy;
  var d = Math.hypot(dx, dy) || 0.001;
  var ux = dx / d, uy = dy / d;
  d = clamp(d, Math.abs(a - b) + 0.6, a + b - 0.4);
  var px = hx + ux * d, py = hy + uy * d;
  var t = (d * d + a * a - b * b) / (2 * d);
  var h = Math.sqrt(Math.max(0, a * a - t * t));
  var mx = hx + ux * t, my = hy + uy * t;
  return { jx: mx - uy * h * lado, jy: my + ux * h * lado, fx: px, fy: py };
}

// Um dedo. aperto=1 dobra por cima da linha e desce pela frente dela: é essa
// travessia do y=0 que faz o desenho "morder" a borda em vez de encostar nela.
function dedo(px, py, dir, lat, aperto, comp) {
  comp = comp || 1;
  var bx = px, by = py - 2.4;
  var esp = lerp(6.8, 2.5, aperto) * comp;
  var prof = lerp(1.0, 4.3, aperto) * comp;
  // agarrado: o dedo sai para fora, contorna a quina e a ponta volta para dentro.
  // Solto: estica reto para a frente. O gancho e o que separa garra de pe chato.
  var tx = bx + dir * lat * esp * lerp(1, 0.78, aperto);
  var ty = by + prof + 2.4;
  var cx = bx + dir * lat * esp * lerp(0.85, 1.18, aperto);
  var cy = by + lerp(1.8, -0.2, aperto);
  return {
    d: "M " + bx.toFixed(2) + "," + by.toFixed(2) +
       " Q " + cx.toFixed(2) + "," + cy.toFixed(2) + " " + tx.toFixed(2) + "," + ty.toFixed(2),
    tx: tx, ty: ty, lat: lat
  };
}

function montaPe(g, px, py, aperto, modo, dir) {
  if (!g._d) {
    g._h = document.createElementNS(SVGNS, "path");
    g._h.setAttribute("fill", "none"); g._h.setAttribute("stroke", C.pataEs);
    g._h.setAttribute("stroke-width", "1.9"); g._h.setAttribute("stroke-linecap", "round");
    g._d = document.createElementNS(SVGNS, "path");
    g._d.setAttribute("fill", "none"); g._d.setAttribute("stroke", C.pata);
    g._d.setAttribute("stroke-width", "1.95"); g._d.setAttribute("stroke-linecap", "round");
    g._u = document.createElementNS(SVGNS, "path");
    g._u.setAttribute("fill", "none"); g._u.setAttribute("stroke", "#3a2810");
    g._u.setAttribute("stroke-width", "1.25"); g._u.setAttribute("stroke-linecap", "round");
    g.appendChild(g._h); g.appendChild(g._d); g.appendChild(g._u);
  }

  // dedo do meio mais longo que os laterais: leque de garra, não pé de pato
  var lats = modo === "frente" ? [-1, 0.05, 1] : [0.4, 1.0];
  var comps = modo === "frente" ? [0.82, 1.18, 0.82] : [1.06, 0.88];
  var d = "", pontas = [];
  for (var i = 0; i < lats.length; i++) {
    var t = dedo(px, py, dir, lats[i], aperto, comps[i]);
    d += t.d + " "; pontas.push(t);
  }
  g._d.setAttribute("d", d);

  // hálux: dedo de trás, curto e escuro, ancorando do outro lado da borda
  var hx = px - dir * 1.2, hy = py - 2.4;
  g._h.setAttribute("d", aperto > 0.5
    ? "M " + hx + "," + hy + " Q " + (hx - dir * 2.8) + "," + (hy + 0.4) + " " + (hx - dir * 3.4) + "," + (hy + 3.6)
    : "M " + hx + "," + hy + " Q " + (hx - dir * 3.6) + "," + (hy + 1.8) + " " + (hx - dir * 5.2) + "," + (hy + 1.2));

  // unhas: só aparecem quando a garra está fechada sobre a linha
  if (aperto > 0.55) {
    var u = "";
    for (var j = 0; j < pontas.length; j++) {
      var t2 = pontas[j];
      u += "M " + t2.tx.toFixed(2) + "," + t2.ty.toFixed(2) +
           " L " + (t2.tx + dir * t2.lat * 0.9).toFixed(2) + "," + (t2.ty + 1.7).toFixed(2) + " ";
    }
    g._u.setAttribute("d", u);
    g._u.setAttribute("opacity", ((aperto - 0.55) / 0.45).toFixed(2));
  } else {
    g._u.setAttribute("opacity", 0);
  }
}

/* =========================================================================
   5. Efeitos: vergadura da linha, poeira, eco
   ======================================================================= */

var particulas = [];
function solta(x, y, n, forca, cor) {
  for (var i = 0; i < n; i++) {
    var p = document.createElementNS(SVGNS, "circle");
    p.setAttribute("r", rnd(0.9, 2.1).toFixed(2));
    p.setAttribute("fill", cor || "#8ac6f0");
    el.efeitos.appendChild(p);
    particulas.push({ n: p, x: x, y: y, vx: rnd(-forca, forca), vy: rnd(-forca * 0.9, -forca * 0.15), t: 0, dur: rnd(0.45, 0.85) });
  }
}
function passoParticulas(dt) {
  for (var i = particulas.length - 1; i >= 0; i--) {
    var p = particulas[i];
    p.t += dt;
    if (p.t >= p.dur) { p.n.parentNode && p.n.parentNode.removeChild(p.n); particulas.splice(i, 1); continue; }
    p.vy += 260 * dt; p.x += p.vx * dt; p.y += p.vy * dt;
    p.n.setAttribute("cx", p.x.toFixed(1)); p.n.setAttribute("cy", p.y.toFixed(1));
    p.n.setAttribute("opacity", (1 - p.t / p.dur).toFixed(2));
  }
}

var impacto = 0;   // 1 -> 0: a linha do layout verga sob o peso e volta
function desenhaVergadura(x, y, l) {
  if (impacto <= 0.002) { el.verga.setAttribute("opacity", 0); el.verga2.setAttribute("opacity", 0); return; }
  var amp = impacto * 4.6 * Math.cos((1 - impacto) * 15);
  var meia = 48;
  var a = clamp(x - meia, l.x0 - 18, x - 6), b = clamp(x + meia, x + 6, l.x1 + 18);
  var d = "M " + a.toFixed(1) + "," + y.toFixed(1) + " Q " + x.toFixed(1) + "," + (y + amp * 2.2).toFixed(1) + " " + b.toFixed(1) + "," + y.toFixed(1);
  el.verga.setAttribute("d", d);
  el.verga.setAttribute("opacity", clamp(impacto * 0.9, 0, 0.9).toFixed(2));
  el.verga2.setAttribute("d", d);
  el.verga2.setAttribute("opacity", clamp(impacto * 0.75, 0, 0.75).toFixed(2));
}

function ecoNoPeito() {
  if (!B.poleiro) return;
  var pt = pontoDoPoleiro(B.poleiro);
  for (var i = 0; i < 3; i++) (function (i) {
    var c = document.createElementNS(SVGNS, "circle");
    c.setAttribute("fill", "none"); c.setAttribute("stroke", C.dorso); c.setAttribute("stroke-width", "1.4");
    c.setAttribute("cx", (pt.x).toFixed(1)); c.setAttribute("cy", (pt.y - 44 * B.esc).toFixed(1));
    el.efeitos.appendChild(c);
    var t0 = performance.now() + i * 140;
    (function anima() {
      var k = (performance.now() - t0) / 640;
      if (k < 0) { requestAnimationFrame(anima); return; }
      if (k >= 1) { c.parentNode && c.parentNode.removeChild(c); return; }
      c.setAttribute("r", (3 + k * 22).toFixed(1));
      c.setAttribute("opacity", ((1 - k) * 0.55).toFixed(2));
      requestAnimationFrame(anima);
    })();
  })(i);
}

/* =========================================================================
   6. Máquina de estados
   ======================================================================= */

function troca(fase) { B.fase = fase; B.t = 0; }

function escolhePoleiro(evitar) {
  var lista = listarPoleiros().filter(function (p) { return !(evitar && p.el === evitar.el); });
  if (!lista.length) return null;
  // prefere destinos longe do ponto atual, senão ele fica sempre no mesmo canto
  var cand = lista.map(function (p) {
    var r = p.el.getBoundingClientRect();
    var d = Math.hypot(r.left + r.width / 2 - B.x, r.top - B.y);
    // linha desenhada de verdade (borda de card, divisor) vale mais que a
    // aresta invisivel de uma caixa qualquer
    // linha estreita é mais difícil de acertar e mais bonita de ver: peso extra
    var l = linhaDo(p), estreita = (l.x1 - l.x0) < 260 ? 1.3 : 1;
    return { p: p, peso: d * estreita * rnd(0.6, 1.5) };
  }).sort(function (a, b) { return b.peso - a.peso; });
  var topo = cand.slice(0, Math.max(3, (cand.length / 2) | 0));
  var alvo = sorteio(topo).p;
  alvo.frac = rnd(0.2, 0.8);
  alvo.ang = rnd(-1.6, 1.6);
  return alvo;
}

function mandaPousar(p) {
  B.destino = p || escolhePoleiro(B.poleiro);
  if (!B.destino) {                       // nenhuma linha visível: dá uma volta e tenta de novo
    B.alvo = { x: rnd(140, Math.max(200, innerWidth - 140)), y: rnd(110, 320) };
    B.poleiro = null; troca("voo"); return;
  }
  if (B.destino.frac == null) { B.destino.frac = rnd(0.2, 0.8); B.destino.ang = rnd(-1.6, 1.6); }
  B.poleiro = null;
  var alvo = pontoDoPoleiro(B.destino);
  var lado = alvo.x > innerWidth / 2 ? 1 : -1;
  B.alvo = { x: alvo.x + lado * rnd(90, 155), y: alvo.y - rnd(70, 120) };
  troca("voo");
}

function levanta() { if (B.fase === "pousado") { B.ultimaLinhaY = 0; B.desequilibrio = 0; troca("agacha"); } }

var GESTOS = ["olhar", "inclinar", "ajeitar", "limpar", "cocar", "arrepiar",
              "andar", "andar", "pular", "cantar"];

/* De madrugada ele fica sonolento: penas arrepiadas, pescoço encolhido, pisca
   devagar, troca menos de poleiro e às vezes cochila de pé. Não é enfeite —
   é o mesmo bicho num horário diferente, e quem usa o app de madrugada nota.
   Rampa entre 21h e 23h para entrar, e entre 5h e 7h para sair. */
function sonolencia() {
  var h = new Date().getHours() + new Date().getMinutes() / 60;
  if (h >= 23 || h < 5) return 1;
  if (h >= 21) return (h - 21) / 2;
  if (h < 7) return (7 - h) / 2;
  return 0;
}
function novoGesto() {
  var g = sorteio(GESTOS);
  B.gesto = g; B.gestoT = 0;
  B.gestoDur = { olhar: .9, inclinar: 1.1, ajeitar: .7, limpar: 1.5, cocar: 1.2,
                 arrepiar: .8, andar: 1.5, pular: .44, cantar: 1.1, cochilar: 2.8 }[g];
  B.gestoSinal = Math.random() < .5 ? -1 : 1;

  if (g === "andar") {
    // passinhos ao longo da própria linha. Quantos cabem depende do que sobra
    // de linha para aquele lado — ele não anda para fora do poleiro.
    var la = linhaDo(B.poleiro);
    var atual = lerp(la.x0, la.x1, B.poleiro.frac);
    var sobra = B.gestoSinal > 0 ? la.x1 - atual : atual - la.x0;
    var passos = clamp(Math.floor(sobra / 13), 0, 5);
    if (passos < 2) { B.gestoSinal = -B.gestoSinal;
      sobra = B.gestoSinal > 0 ? la.x1 - atual : atual - la.x0;
      passos = clamp(Math.floor(sobra / 13), 0, 5);
    }
    if (passos < 2) { B.gesto = "ajeitar"; B.gestoDur = 0.7; return; }
    B.andar = { passos: passos, dir: B.gestoSinal, passo: 12.5, de: B.poleiro.frac, larg: Math.max(1, la.x1 - la.x0) };
    B.gestoDur = passos * 0.42;
    return;
  }

  if (g === "pular") {
    var l = linhaDo(B.poleiro);
    var passo = rnd(26, 64) * B.gestoSinal;
    var fx = clamp(lerp(l.x0, l.x1, B.poleiro.frac) + passo, l.x0, l.x1);
    B.poleiro.fracDe = B.poleiro.frac;
    B.poleiro.fracAlvo = (fx - l.x0) / Math.max(1, l.x1 - l.x0);
  }
  if (g === "cantar") ecoNoPeito();
}

function aplicaGesto() {
  var k = clamp(B.gestoT / B.gestoDur, 0, 1);
  var s = Math.sin(k * Math.PI);
  switch (B.gesto) {
    case "olhar":
      B.cabecaAlvo = lerp(-19, 19, k < .5 ? k * 2 : 2 - k * 2) * B.gestoSinal;
      break;
    case "inclinar":
      B.cabecaTombo = s * 15 * B.gestoSinal;
      break;
    case "ajeitar":                                  // solta e reagarra a garra
      B.aperto = 1 - s * 0.4;
      B.corpoX += Math.sin(k * 26) * 0.45;
      break;
    case "limpar":                                   // alisa a asa com o bico
      B.pescoco = -s * 6;
      B.cabecaTombo = -s * 32 * B.gestoSinal;
      B.cabecaAlvo = s * 11 * B.gestoSinal;
      break;
    case "cocar":                                    // levanta um pé até a cabeça
      B.coca = s;
      B.cabecaTombo = s * 12;
      break;
    case "arrepiar":                                 // infla as penas
      B.fofo = s;
      break;
    case "andar": {
      // O corpo avança contínuo e os pés se revezam: um fica plantado e desliza
      // para trás (porque o corpo passou por cima dele), o outro levanta e vai
      // à frente. É o revezamento que separa "andar" de "escorregar".
      var a = B.andar;
      if (!a) break;
      var avanco = (a.dir * a.passos * a.passo) / a.larg;
      B.poleiro.frac = clamp(a.de + avanco * easeOut(k), 0.04, 0.96);
      a.u = (k * a.passos) % 1;                       // fase dentro do passo
      var balanco = Math.sin(k * a.passos * Math.PI * 2);
      B.corpoX = balanco * 0.9;                       // peso troca de pé
      B.corpoY = Math.abs(balanco) * -0.8;
      // cabeça de pombo: adianta e espera o corpo alcançar
      B.pescoco = -Math.max(0, Math.sin(k * a.passos * Math.PI * 2 + 1.2)) * 1.6;
      B.cabecaAlvo = a.dir * 7;
      if (k >= 1) B.andar = null;
      break;
    }

    case "cochilar":                                 // de madrugada, de pé mesmo
      B.fofo = Math.min(1, s * 1.4);
      B.pescoco = s * 3.2;
      B.cabecaTombo = -s * 6;
      break;

    case "pular":                                    // salta de lado NO MESMO poleiro
      B.poleiro.frac = lerp(B.poleiro.fracDe, B.poleiro.fracAlvo, easeOut(k));
      B.peY = -s * 9;
      B.corpoY = -s * 5;
      B.aperto = (k > 0.12 && k < 0.88) ? 0.2 : 1;
      B.batidaVel = s * 15;
      if (k >= 0.9 && !B._pousouPulo) {
        B._pousouPulo = 1; impacto = 0.5;
        var pt = pontoDoPoleiro(B.poleiro);
        solta(pt.x, pt.y, 3, 60, "#7fc2ea");
      }
      if (k >= 1) B._pousouPulo = 0;
      break;
    case "cantar":
      B.pescoco = -s * 3.5;
      B.cabecaTombo = -s * 9;
      B.bicoAberto = s;
      break;
  }
}

/* =========================================================================
   7. Passo de simulação
   ======================================================================= */

var VEL_VOO = 330;

function passo(dt) {
  B.t += dt;
  var f = B.fase;

  /* ---------------------------------------------------------------- voo */
  if (f === "voo" || f === "indoPegar" || f === "indoLixo") {
    B.vista = "perfil";

    if (f === "indoPegar" && B.tarefa) {
      // o feed rola e o elemento anda junto: a mira na borda dele é relida
      // a cada quadro, senão ele pousa onde a coisa estava, não onde está
      if (!B.tarefa.alvoEl || !B.tarefa.alvoEl.isConnected) { B.tarefa = null; mandaPousar(null); return; }
      var rb = B.tarefa.alvoEl.getBoundingClientRect();
      B.tarefa.borda = { x: rb.left + rb.width * 0.34, y: rb.top };
      B.alvo = { x: B.tarefa.borda.x + (B.x < B.tarefa.borda.x ? -74 : 74), y: B.tarefa.borda.y - 78 };
    }
    if (f === "indoLixo") B.alvo = destinoDoLixo();

    var alvo = B.alvo;
    var dx = alvo.x - B.x, dy = alvo.y - B.y;
    var dist = Math.hypot(dx, dy) || 1;
    var vel = f === "voo" ? VEL_VOO : VEL_VOO * 0.84;
    var k = Math.min(1, dt * 2.6);
    B.vx += ((dx / dist) * vel - B.vx) * k;
    B.vy += ((dy / dist) * vel - B.vy) * k;
    B.x += B.vx * dt; B.y += B.vy * dt;

    B.batidaVel = 13.5;
    B.aperto = 0.7;
    B.peY = -13;
    B.corpoY = 0; B.corpoX = 0;
    B.caudaAlvo = clamp(-B.vy * 0.02, -12, 12);

    if (Math.abs(B.vx) > 45) B.rumo = B.vx > 0 ? 1 : -1;   // histerese: nada de tremelique perto da vertical
    B.pitch = clamp(grau(Math.atan2(B.vy, Math.abs(B.vx) + 40)) * 0.55, -26, 26) * B.rumo;

    if (dist < 28) {
      if (f === "voo") {
        // sem poleiro escolhido (primeiro quadro, ou nenhum visível) ele
        // sorteia um novo em vez de tentar frear no vazio
        if (!B.destino || !B.destino.el.isConnected) { mandaPousar(null); return; }
        B.freadaDe = null; troca("freada");
      } else if (f === "indoPegar") { B.pegaDe = null; troca("pegando"); }
      else troca("largando");
    }
    return;
  }

  /* ------------------------------------------------------------- freada
     Asas abertas, nariz pra cima, velocidade caindo, pernas esticando na
     direção da linha. Termina EXATAMENTE no ponto de contato. */
  if (f === "freada") {
    if (!B.destino || !B.destino.el.isConnected) { mandaPousar(null); return; }
    var DUR = 0.46;
    var kk = clamp(B.t / DUR, 0, 1), e = easeOut(kk);
    var pt = pontoDoPoleiro(B.destino);
    if (!B.freadaDe) B.freadaDe = { x: B.x, y: B.y };
    B.x = lerp(B.freadaDe.x, pt.x, e);
    B.y = lerp(B.freadaDe.y, pt.y, e) - Math.sin(kk * Math.PI) * 17;  // arco de chegada
    B.rumo = pt.x >= B.freadaDe.x ? 1 : -1;
    B.pitch = -30 * Math.sin(kk * Math.PI) * B.rumo;                  // empina pra frear
    B.batidaVel = lerp(13, 3.5, kk);
    B.aperto = 1 - e;                                                 // dedos ABREM pra alcançar
    B.peY = lerp(-13, 0, e);                                          // pernas descem à frente
    B.caudaAlvo = -34;                                                // cauda em leque
    if (kk >= 1) {
      B.freadaDe = null;
      B.poleiro = B.destino;
      impacto = 1;
      solta(pt.x, pt.y, 6, 95, "#7fc2ea");
      troca("impacto");
    }
    return;
  }

  /* ------------------------------------------------------------ impacto
     O peso cai em cima da linha: corpo afunda, garra trava, asas dão dois
     tremidos pra recuperar o equilíbrio, cauda bombeia. */
  if (f === "impacto") {
    if (!B.poleiro || !B.poleiro.el.isConnected) { mandaPousar(null); return; }
    var DI = 0.55, ki = clamp(B.t / DI, 0, 1);
    var pi = pontoDoPoleiro(B.poleiro);
    B.x = pi.x; B.y = pi.y; B.peY = 0;
    B.aperto = clamp(B.t / 0.09, 0, 1);
    var mola = Math.exp(-ki * 7) * Math.cos(ki * 22);
    B.corpoY = -mola * 5.5;
    B.pitch = (lerp(-18, 2, easeOut(ki)) + mola * 7) * B.rumo;
    B.batidaVel = ki < 0.45 ? 16 : lerp(16, 0, (ki - 0.45) / 0.55);
    B.caudaAlvo = lerp(-26, 4, easeOut(ki)) + mola * 12;
    if (ki >= 1) { B.corpoY = 0; troca("virando"); }
    return;
  }

  /* ------------------------------------------------------------- virando
     Achata na horizontal e reabre: lê como girar em cima do próprio pé. */
  if (f === "virando") {
    var DV = 0.28, kv = clamp(B.t / DV, 0, 1);
    B.escX = Math.max(0.06, Math.abs(Math.cos(kv * Math.PI)));
    if (kv >= 0.5) B.vista = "frente";
    B.pitch = lerp(B.pitch, 2.5, Math.min(1, dt * 10));
    B.caudaAlvo = 0;
    if (kv >= 1) { B.escX = 1; B.rumo = 1; B.proximo = rnd(1.4, 3.2); B.trocaEm = rnd(9, 22); troca("pousado"); }
    return;
  }

  /* ------------------------------------------------------------- rodopio
     Saiu voando de susto: dá uma volta no ar antes de procurar onde pousar.
     Não é decoração — sem a volta ele parecia teletransportado de um poleiro
     para o outro toda vez que alguém publicava. */
  if (f === "rodopio") {
    var DR = 1.15, kr = clamp(B.t / DR, 0, 1);
    var giro = B.giroInfo;
    if (!giro) { mandaPousar(null); return; }
    var ang = giro.a0 + giro.sentido * kr * Math.PI * 2.4;
    var raio = giro.raio * (0.35 + 0.65 * Math.sin(clamp(kr * 1.15, 0, 1) * Math.PI));
    var nx = giro.cx + Math.cos(ang) * raio;
    var ny = giro.cy + Math.sin(ang) * raio * 0.55;
    B.vx = (nx - B.x) / Math.max(dt, 0.001);
    B.vy = (ny - B.y) / Math.max(dt, 0.001);
    B.x = nx; B.y = ny;
    B.vista = "perfil";
    if (Math.abs(B.vx) > 45) B.rumo = B.vx > 0 ? 1 : -1;
    B.batidaVel = 19;
    B.aperto = 0.7; B.peY = -13;
    B.pitch = clamp(grau(Math.atan2(B.vy, Math.abs(B.vx) + 40)) * 0.55, -26, 26) * B.rumo;
    B.caudaAlvo = clamp(-B.vy * 0.02, -14, 14);
    if (kr >= 1) { B.giroInfo = null; mandaPousar(null); }
    return;
  }

  /* ------------------------------------------------------------ espiando
     Cabeça de fora na beirada da tela, o resto do corpo escondido além da
     borda. Ele olha em volta antes de se decidir a entrar. */
  if (f === "espiando") {
    var DUR_E = 1.5;
    var ke = clamp(B.t / DUR_E, 0, 1);
    B.batidaVel = 0; B.aperto = 1; B.peY = 0;
    B.corpoY = Math.sin(B.t * 3.4) * 0.7;
    var deBaixo = B.espiada && B.espiada.lado === "baixo";
    // inclinado para dentro da tela: é a inclinação que projeta a cabeça
    // para além do corpo, e chega crescendo, como quem se arrisca devagar
    B.pitch = deBaixo ? Math.sin(B.t * 2.1) * 2
                      : lerp(8, 26, clamp(B.t / 0.5, 0, 1)) + Math.sin(B.t * 2.4) * 2.5;

    // varre a cena com a cabeça, e dá uma olhada extra se o cursor se mexeu
    B.cabecaAlvo = Math.sin(B.t * 2.3) * 15;
    if (mouse.vivo) {
      var edx = mouse.x - B.x;
      B.cabecaAlvo = clamp(edx * 0.05, -18, 18);
    }
    B.cabecaTombo = Math.sin(B.t * 1.7) * 7;

    if (ke >= 1) {
      B.espiaDe = { x: B.x, y: B.y };
      troca("escalando");
    }
    return;
  }

  /* ------------------------------------------------------------ escalando
     Desliza da borda para a linha e fecha a garra nela. Sem bater asa: é
     escalada, não voo — e é isso que amarra a entrada ao layout. */
  if (f === "escalando") {
    if (!B.espiada || !B.espiada.poleiro.el.isConnected) { mandaPousar(null); return; }
    var DUR_S = 0.72;
    var ks = clamp(B.t / DUR_S, 0, 1), es = easeOut(ks);
    var pe = pontoDoPoleiro(B.espiada.poleiro);
    var de = B.espiaDe || { x: B.x, y: B.y };

    B.x = lerp(de.x, pe.x, es);
    B.y = lerp(de.y, pe.y, es) - Math.sin(ks * Math.PI) * 14;
    B.aperto = ks < 0.62 ? lerp(1, 0.15, ks / 0.62) : lerp(0.15, 1, (ks - 0.62) / 0.38);
    B.batidaVel = ks < 0.75 ? 9 : 0;
    // desfaz a inclinação da espiada enquanto entra
    B.pitch = lerp(B.vista === "perfil" ? 26 : 0, -6, Math.sin(ks * Math.PI * 0.5));
    B.caudaAlvo = -14 * Math.sin(ks * Math.PI);

    if (ks >= 1) {
      B.poleiro = B.espiada.poleiro;
      B.espiada = null; B.espiaDe = null;
      impacto = 0.6;
      solta(pe.x, pe.y, 4, 70, "#7fc2ea");
      troca("impacto");
    }
    return;
  }

  /* ------------------------------------------------------------- pousado */
  if (f === "pousado") {
    if (!B.poleiro || !B.poleiro.el.isConnected) { mandaPousar(null); return; }
    var pp = pontoDoPoleiro(B.poleiro);
    B.x = pp.x; B.y = pp.y; B.peY = 0; B.aperto = 1;
    B.vista = "frente"; B.rumo = 1;

    // respiração: o TRONCO sobe e desce, os pés não saem do lugar e as pernas
    // absorvem. É esse detalhe que dá peso ao bicho em cima da borda.
    // o poleiro se mexeu debaixo dele (scroll): abre a asa e balanca para
    // reequilibrar, como quem esta mesmo apoiado em algo que se mexeu
    var dLinha = B.ultimaLinhaY ? (pp.y - B.ultimaLinhaY) : 0;
    B.ultimaLinhaY = pp.y;
    if (Math.abs(dLinha) > 1.2) B.desequilibrio = clamp(B.desequilibrio + Math.abs(dLinha) * 0.05, 0, 1);
    B.desequilibrio = Math.max(0, B.desequilibrio - dt * 1.5);

    var agora = performance.now();
    var resp = Math.sin(agora / 900) * 0.85;
    var tremor = B.desequilibrio * Math.sin(agora / 42) * 2.6;
    B.corpoY = resp - B.desequilibrio * 1.4;
    B.corpoX = Math.sin(agora / 1400) * 0.7 + tremor * 0.5;
    B.batidaVel = B.desequilibrio * 22;
    B.pitch = 2.5 + resp * 0.8 + tremor;              // sempre levemente inclinado pra frente
    B.caudaAlvo = -resp * 4 - tremor * 2.5;                 // cauda contrabalança

    // Um ponto que roubou a atenção (uma curtida, por exemplo) manda mais que
    // o cursor enquanto dura.
    var foco = null;
    if (B.olhar) {
      B.olhar.t -= dt;
      if (B.olhar.t <= 0) B.olhar = null; else foco = B.olhar;
    }
    if (foco) {
      var fdx = foco.x - pp.x, fdy = foco.y - (pp.y - 46 * B.esc);
      B.cabecaAlvo = clamp(fdx * 0.09, -19, 19);
      B.pescoco = clamp(-fdy * 0.025, -2.6, 3.5);
    } else if (mouse.vivo) {
      var mdx = mouse.x - pp.x, mdy = mouse.y - (pp.y - 46 * B.esc);
      B.cabecaAlvo = clamp(mdx * 0.06, -17, 17);
      B.pescoco = clamp(-mdy * 0.02, -2.2, 3.5);
      if (Math.hypot(mdx, mdy) < 60) B.susto = 1;
    }

    // Madrugada: encolhe, arrepia e desacelera. O sono não impede nada, só
    // deixa tudo mais lento — é ele mesmo, com menos disposição.
    B.sono = sonolencia();
    if (B.sono > 0.05 && !B.gesto) {
      B.fofo = Math.max(B.fofo, B.sono * 0.55);
      B.pescoco += B.sono * 2.2;
      B.pitch -= B.sono * 1.5;
    }

    if (B.gesto) {
      B.gestoT += dt;
      aplicaGesto();
      if (B.gestoT >= B.gestoDur) { B.gesto = null; B.proximo = rnd(1.1, 2.8); }
    } else {
      B.proximo -= dt * (1 - B.sono * 0.6);
      if (B.proximo <= 0) {
        if (B.sono > 0.5 && Math.random() < B.sono * 0.45) {
          B.gesto = "cochilar"; B.gestoT = 0; B.gestoDur = rnd(2.2, 4.2); B.gestoSinal = 1;
        } else novoGesto();
      }
    }

    // cansa do lugar e procura outra linha; tambem sai se assustar ou se o
    // poleiro saiu da tela com o scroll
    B.trocaEm -= dt * (1 - B.sono * 0.65);   // de madrugada ele fica mais no lugar
    var lp = linhaDo(B.poleiro);
    if (B.trocaEm <= 0 && !B.gesto) { B.destinoFuturo = escolhePoleiro(B.poleiro); levanta(); }
    else if (B.susto > 0.9 || lp.y < 48 || lp.y > innerHeight - 30) { B.susto = 0; levanta(); }
    return;
  }

  /* -------------------------------------------------------------- agacha */
  if (f === "agacha") {
    if (!B.poleiro || !B.poleiro.el.isConnected) { mandaPousar(null); return; }
    var DA = 0.18, ka = clamp(B.t / DA, 0, 1);
    var pa = pontoDoPoleiro(B.poleiro);
    B.x = pa.x; B.y = pa.y;
    B.corpoY = lerp(0, 4.4, easeOut(ka));
    B.pitch = lerp(2.5, 8, ka);
    B.aperto = 1;
    if (ka >= 1) troca("giraPraVoar");
    return;
  }
  if (f === "giraPraVoar") {
    var DG = 0.2, kg = clamp(B.t / DG, 0, 1);
    B.escX = Math.max(0.06, Math.abs(Math.cos(kg * Math.PI)));
    if (kg >= 0.5 && !B._rumoEsc) {
      B.vista = "perfil";
      var dest = B.tarefa ? B.tarefa.ponto : (B.destinoFuturo ? pontoDoPoleiro(B.destinoFuturo) : null);
      B.rumo = dest ? (dest.x > B.x ? 1 : -1) : (B.x < innerWidth / 2 ? 1 : -1);
      B._rumoEsc = 1;
    }
    if (kg >= 1) { B.escX = 1; B._rumoEsc = 0; troca("impulso"); }
    return;
  }

  /* ------------------------------------------------------------- impulso
     Ninguém decola sem esticar a perna contra o poleiro primeiro. */
  if (f === "impulso") {
    var DP = 0.2, kp = clamp(B.t / DP, 0, 1);
    var pt2 = B.poleiro ? pontoDoPoleiro(B.poleiro) : { x: B.x, y: B.y };
    if (kp < 0.28) {
      B.corpoY = lerp(4.4, -6, kp / 0.28);
      B.aperto = 1;
      B.x = pt2.x; B.y = pt2.y;
    } else {
      if (B.aperto === 1) { impacto = 0.55; solta(pt2.x, pt2.y, 5, 110, "#9ed6ff"); }
      B.aperto = 0.3;
      var kr = (kp - 0.28) / 0.72;
      B.corpoY = lerp(-6, 0, kr);
      B.x = pt2.x + B.rumo * kr * 34;
      B.y = pt2.y - kr * 48;
      B.peY = lerp(0, -13, kr);
    }
    B.batidaVel = 17;
    B.pitch = lerp(8, -14, kp) * B.rumo;
    B.caudaAlvo = 20;
    if (kp >= 1) {
      B.vx = B.rumo * 190; B.vy = -160;
      B.poleiro = null;
      if (B.tarefa) { B.alvo = B.tarefa.ponto; troca(B.tarefa.tipo); }
      else { mandaPousar(B.destinoFuturo); B.destinoFuturo = null; }
    }
    return;
  }

  /* ------------------------------------------------ tarefa: levar pro lixo
     Ele pousa na BORDA DE CIMA do que vai sumir, como pousa em qualquer
     linha do layout, crava a garra, puxa duas vezes para soltar do lugar e
     só então levanta voo carregando. É a mesma mecânica de poleiro — o que
     muda é que desta vez o poleiro vai junto. */
  if (f === "pegando") {
    if (!B.tarefa) { mandaPousar(null); return; }
    var DC = 0.85, kc = clamp(B.t / DC, 0, 1);
    var borda = B.tarefa.borda;                       // a linha do elemento

    if (kc < 0.34) {                                   // descida final até a borda
      var kd = kc / 0.34, ed = easeOut(kd);
      if (!B.pegaDe) B.pegaDe = { x: B.x, y: B.y };
      B.x = lerp(B.pegaDe.x, borda.x, ed);
      B.y = lerp(B.pegaDe.y, borda.y, ed) - Math.sin(kd * Math.PI) * 14;
      B.aperto = 1 - ed;                               // dedos abrem para agarrar
      B.peY = lerp(-13, 0, ed);
      B.batidaVel = lerp(15, 4, kd);
      B.pitch = -26 * Math.sin(kd * Math.PI) * B.rumo;
      B.caudaAlvo = -30;
    } else {                                           // garra crava e puxa
      var kt = (kc - 0.34) / 0.66;
      B.x = borda.x; B.y = borda.y; B.peY = 0;
      B.aperto = 1;
      if (!B._cravou) {
        B._cravou = 1; impacto = 1;
        solta(borda.x, borda.y, 5, 85, "#7fc2ea");
      }
      // dois puxões: o corpo sobe e desce, a carga ainda presa
      var puxao = Math.sin(kt * Math.PI * 2) * (1 - kt);
      B.corpoY = -Math.abs(puxao) * 6;
      B.pitch = puxao * 10 * B.rumo;
      B.batidaVel = kt > 0.45 ? 16 : 5;
      B.caudaAlvo = puxao * 18;
    }

    if (kc >= 1) {
      B.pegaDe = null; B._cravou = 0; B.corpoY = 0;
      prenderCarga(B.tarefa.alvoEl);                   // o elemento vira fardo
      mostrarLixeira(true);
      troca("levantando");
    }
    return;
  }

  /* ----------------------------------------------------------- levantando
     Arranca do lugar com duas batidas fortes antes de ganhar altura — peso
     pendurado não sobe de graça. */
  if (f === "levantando") {
    var DV = 0.45, kv2 = clamp(B.t / DV, 0, 1);
    if (!B.levantaDe) B.levantaDe = { x: B.x, y: B.y };
    B.aperto = 1;
    B.peY = lerp(0, -6, easeOut(kv2));
    B.y = B.levantaDe.y - easeOut(kv2) * 58;
    B.x = B.levantaDe.x + B.rumo * kv2 * 16;
    B.batidaVel = 20;
    B.pitch = lerp(6, -16, kv2) * B.rumo;
    B.caudaAlvo = 16;
    if (kv2 >= 1) {
      B.levantaDe = null;
      B.vx = B.rumo * 120; B.vy = -120;
      B.alvo = destinoDoLixo();
      troca("indoLixo");
    }
    return;
  }
  if (f === "largando") {
    var DL = 0.8, kl = clamp(B.t / DL, 0, 1);
    B.vx *= 0.8; B.vy *= 0.8;
    B.x += B.vx * dt; B.y += B.vy * dt;
    B.batidaVel = 19; B.pitch = -24 * B.rumo; B.caudaAlvo = -14;
    if (kl > 0.3 && !B._largou) {
      B._largou = 1;
      B.aperto = 0;                                    // abre a garra
      soltarCarga();                                   // e o fardo cai
      sacudirLixeira();
      solta(B.x, B.y + 10, 7, 110, "#f4212e");
    }
    if (kl >= 1) {
      B._largou = 0;
      mostrarLixeira(false);
      if (B.tarefa && B.tarefa.aoTerminar) { try { B.tarefa.aoTerminar(); } catch (e) {} }
      B.tarefa = null;
      mandaPousar(null);
    }
    return;
  }
}

/* =========================================================================
   8. Desenho do quadro
   ======================================================================= */

var FASES_VOO = { voo: 1, freada: 1, impulso: 1, indoPegar: 1, indoLixo: 1, rodopio: 1,
                  largando: 1, giraPraVoar: 1, levantando: 1, mergulhando: 1 };

/* Um NaN que escape para um atributo SVG derruba o desenho do quadro inteiro e
   enche o console. Em vez de caçar a origem em cada fase nova, o estado é
   saneado na fronteira do desenho: quem chegar quebrado volta ao padrão. */
var PADROES = {
  x: 0, y: 0, corpoX: 0, corpoY: 0, pitch: 0, escX: 1, escY: 1,
  aperto: 1, peY: 0, batida: 0, batidaVel: 0, caudaAng: 0, caudaAlvo: 0,
  cabecaAng: 0, cabecaAlvo: 0, cabecaTombo: 0, pescoco: 0,
  esc: 0.9, fofo: 0, coca: 0, bicoAberto: 0, desequilibrio: 0, susto: 0
};

function sanear() {
  for (var k in PADROES) if (!isFinite(B[k])) B[k] = PADROES[k];
  if (!isFinite(impacto)) impacto = 0;
}

function desenha(dt) {
  sanear();
  var esc = B.esc;

  B.caudaAng += (B.caudaAlvo - B.caudaAng) * Math.min(1, dt * 11);
  B.cabecaAng += (B.cabecaAlvo - B.cabecaAng) * Math.min(1, dt * 7.5);
  B.batida += B.batidaVel * dt;
  B.susto = Math.max(0, B.susto - dt * 1.6);
  impacto = Math.max(0, impacto - dt * 2.1);
  // decai sempre: o gesto reescreve o valor todo quadro, entao nao briga com
  // isto, e um gesto cortado no meio (susto, scroll) nao deixa perna no ar
  B.fofo *= (1 - Math.min(1, dt * 5));
  B.coca *= (1 - Math.min(1, dt * 6));
  B.cabecaTombo *= (1 - Math.min(1, dt * 6));
  B.bicoAberto *= (1 - Math.min(1, dt * 8));

  var voando = !!FASES_VOO[B.fase];

  // raiz: leva o bicho até o ponto de contato, inclina com o poleiro, espelha
  var angPol = B.poleiro && !voando ? (B.poleiro.ang || 0) : 0;
  el.passaro.setAttribute("transform",
    "translate(" + B.x.toFixed(2) + "," + B.y.toFixed(2) + ") rotate(" + angPol.toFixed(2) + ")" +
    " scale(" + (B.rumo * esc * B.escX).toFixed(3) + "," + (esc * B.escY).toFixed(3) + ")");

  // tronco: balança em cima do pé. Pivô = ponto de contato, como um bicho real
  // que gira sobre a garra.
  var fofo = 1 + B.fofo * 0.1;
  el.tronco.setAttribute("transform",
    "translate(" + B.corpoX.toFixed(2) + "," + B.corpoY.toFixed(2) + ") rotate(" + B.pitch.toFixed(2) + ") scale(" + fofo.toFixed(3) + ")");
  el.vPerfil.style.display = B.vista === "perfil" ? "" : "none";
  el.vFrente.style.display = B.vista === "frente" ? "" : "none";

  // pernas por IK: quadril vem do tronco, pé fica onde está a linha
  var pr = (B.pitch * Math.PI) / 180, cosP = Math.cos(pr), sinP = Math.sin(pr);
  function quadril(hx, hy) {
    return { x: B.corpoX + (hx * cosP - hy * sinP) * fofo, y: B.corpoY + (hx * sinP + hy * cosP) * fofo };
  }

  // Quadril baixo (a barriga quase encosta) e ossos curtos: sobra pouco tarso
  // aparente. Era a perna longa que fazia ele parecer de pé em vez de pousado.
  var pes = B.vista === "frente"
    ? [{ hx: 4.6, hy: -9.0, px: 4.4, py: 0, lado: -1 }, { hx: -4.6, hy: -9.0, px: -4.4, py: 0, lado: 1 }]
    : [{ hx: 1.2, hy: -9.8, px: 2.4, py: 0, lado: 1 }, { hx: -1.8, hy: -10.0, px: -2.8, py: 0, lado: 1 }];

  var destinos = [[el.pernaA, el.peA], [el.pernaB, el.peB]];
  for (var i = 0; i < 2; i++) {
    var p = pes[i], perna = destinos[i][0], pe = destinos[i][1];
    var q = quadril(p.hx, p.hy);
    var fx = p.px, fy = p.py + B.peY, apertoPe = B.aperto;

    if (B.andar && B.vista === "frente") {
      // os dois pés na mesma fase seria pulo; meia volta de diferença é passo
      var u = ((B.andar.u || 0) + (i ? 0.5 : 0)) % 1;
      var meio = B.andar.passo / 2;
      if (u < 0.5) {                                  // apoio: plantado, desliza p/ trás
        fx += B.andar.dir * lerp(meio, -meio, u / 0.5) * 0.42;
      } else {                                        // balanço: levanta e vai à frente
        var kb = (u - 0.5) / 0.5;
        fx += B.andar.dir * lerp(-meio, meio, kb) * 0.42;
        fy -= Math.sin(kb * Math.PI) * 3.6;
        apertoPe = 0.25;                              // garra aberta no ar
      }
    }

    if (B.coca > 0.05 && i === 1 && B.vista === "frente") {   // pé que coça sobe até a cabeça
      fy = lerp(p.py, -30, B.coca); fx = lerp(p.px, -9, B.coca);
    }
    if (voando) fx += (B.fase === "freada" ? 5 : -3) * (1 - B.aperto);
    var r = ik(q.x, q.y, fx, fy, 5.6, 5.6, p.lado);
    perna.setAttribute("d", "M " + q.x.toFixed(2) + "," + q.y.toFixed(2) +
      " L " + r.jx.toFixed(2) + "," + r.jy.toFixed(2) + " L " + r.fx.toFixed(2) + "," + r.fy.toFixed(2));
    var fundo = (i === 1 && B.vista === "perfil") ? 0.82 : 1;
    perna.setAttribute("opacity", fundo);
    montaPe(pe, r.fx, r.fy, apertoPe, B.vista, 1);
    pe.setAttribute("opacity", fundo);
  }

  // cauda
  if (B.vista === "perfil") el.caudaP.setAttribute("transform", "translate(-11,-21) rotate(" + B.caudaAng.toFixed(2) + ")");
  else el.caudaF.setAttribute("transform", "translate(0,-15) rotate(" + (B.caudaAng * 0.5).toFixed(2) + ")");

  // cabeça
  if (B.vista === "perfil") {
    el.cabecaP.setAttribute("transform",
      "translate(3," + (-38 + B.pescoco).toFixed(2) + ") rotate(" + (B.cabecaTombo - B.cabecaAng * 0.25).toFixed(2) + ")");
    el.bicoPinf.setAttribute("transform", "rotate(" + (B.bicoAberto * 16).toFixed(1) + ",12.4,-6.2)");
  } else {
    el.cabecaF.setAttribute("transform",
      "translate(" + (B.cabecaAng * 0.16).toFixed(2) + "," + (-34.2 + B.pescoco).toFixed(2) + ") rotate(" + B.cabecaTombo.toFixed(2) + ")");
    var dpx = clamp(B.cabecaAng * 0.055, -1.5, 1.5);
    olhos[1][1].setAttribute("cx", (-4 + dpx).toFixed(2));
    olhos[2][1].setAttribute("cx", (6 + dpx).toFixed(2));
    el.bicoFinf.setAttribute("transform", "translate(0," + (B.bicoAberto * 2.4).toFixed(2) + ")");
  }

  // asas
  var bat = Math.sin(B.batida);
  if (B.vista === "perfil") {
    if (voando) {
      var ang = B.fase === "freada"
        ? lerp(-48, 6, (bat + 1) / 2) * 0.5 - 30     // freando: asas pra frente, batendo curto
        : lerp(-58, 34, (bat + 1) / 2);
      el.asaPertoP.setAttribute("d", "M 3,-35.6 C -6,-44.5 -22,-47 -35,-40.5 C -30.5,-33.4 -16,-28.4 -2,-31 Z");
      el.asaPertoBarra.setAttribute("d", "M -8,-38.8 C -16,-41 -24,-41.4 -30.4,-39.8 C -25,-37.6 -17,-36 -9.6,-35.8 Z");
      el.asaLongeP.setAttribute("d", "M 3,-35.6 C -6,-44.5 -22,-47 -35,-40.5 C -30.5,-33.4 -16,-28.4 -2,-31 Z");
      el.asaPerto.setAttribute("transform", "rotate(" + ang.toFixed(1) + ",3,-35.6)");
      el.asaLonge.setAttribute("transform", "rotate(" + (ang * 0.82 + 10).toFixed(1) + ",3,-35.6) translate(-2,3)");
      el.asaLonge.setAttribute("opacity", 0.72);
    } else {
      el.asaPertoP.setAttribute("d", "M 4.2,-35.6 C -2.4,-34 -8.6,-26.6 -8.6,-18.6 C -8.6,-15.6 -5.4,-14.6 -3.2,-16.8 C 0.8,-22 5,-29.2 6.2,-34 Z");
      el.asaPertoBarra.setAttribute("d", "M -6.8,-22.6 C -4.4,-23 -2.2,-24.6 -0.8,-26.8 C -3,-24.4 -5.2,-23.2 -6.8,-22.6 Z");
      el.asaLongeP.setAttribute("d", "M 2,-34.6 C -3.4,-32.6 -8,-26 -8,-19.6 C -6,-23.6 -1.6,-29.6 2,-32 Z");
      el.asaPerto.setAttribute("transform", "rotate(0,4.2,-35.6)");
      el.asaLonge.setAttribute("transform", "rotate(0,4.2,-35.6)");
      el.asaLonge.setAttribute("opacity", 0.8);
    }
  } else {
    var abre = B.fase === "impacto" ? Math.max(0, 1 - B.t / 0.45) : (B.gesto === "pular" ? Math.sin(clamp(B.gestoT / B.gestoDur, 0, 1) * Math.PI) : B.desequilibrio);
    var base = abre * 40 + (abre ? bat * 15 * abre : 0);
    el.asaFE.setAttribute("transform", "rotate(" + base.toFixed(1) + ",-12.6,-34.2)");
    el.asaFD.setAttribute("transform", "rotate(" + (-base).toFixed(1) + ",12.6,-34.2)");
  }

  // piscar
  B.piscar -= dt * (1 - B.sono * 0.45);
  var abertura = 1 - B.sono * 0.42;                  // pálpebra pesada
  if (B.piscar < 0.3 * (1 + B.sono * 2)) {
    abertura *= clamp(Math.abs(B.piscar) / (0.07 + B.sono * 0.22), 0.08, 1);
  }
  if (B.piscar < -0.07 - B.sono * 0.5) B.piscar = rnd(1.8, 5.2);
  if (B.gesto === "cochilar") abertura = Math.min(abertura, 0.1);
  if (B.susto > 0.2) abertura = 1.3;
  for (var o = 0; o < olhos.length; o++) {
    olhos[o][0].setAttribute("ry", (3.5 * abertura).toFixed(2));
    olhos[o][1].setAttribute("opacity", abertura > 0.4 ? 1 : 0);
  }

  // contato com a superfície: sombra de pressão + a linha vergando
  if (B.poleiro && !voando) {
    var l = linhaDo(B.poleiro);
    el.contato.setAttribute("opacity", 1);
    var ap = clamp(1 - Math.abs(B.peY) / 12, 0.12, 1);
    el.sombra.setAttribute("cx", B.x.toFixed(1));
    el.sombra.setAttribute("cy", (l.y + 1.6).toFixed(1));
    el.sombra.setAttribute("rx", (15 * esc * lerp(0.72, 1, ap)).toFixed(2));
    el.sombra.setAttribute("opacity", (0.5 * ap).toFixed(2));
    // risco claro sob as garras: em fundo escuro a sombra some, e sem nada
    // ali o pé parece flutuar rente à borda
    el.pressao.setAttribute("d",
      "M " + (B.x - 11 * esc).toFixed(1) + "," + (l.y + 0.5).toFixed(1) +
      " L " + (B.x + 11 * esc).toFixed(1) + "," + (l.y + 0.5).toFixed(1));
    el.pressao.setAttribute("opacity", (0.34 * ap * B.aperto).toFixed(2));

    var est = estiloPoleiro(B.poleiro);
    if (est && B.aperto > 0.3) {
      var larg = 30 * esc;
      el.tira.setAttribute("x", clamp(B.x - larg / 2, l.x0 - 22, l.x1 + 22 - larg).toFixed(1));
      el.tira.setAttribute("y", l.y.toFixed(1));
      el.tira.setAttribute("width", larg.toFixed(1));
      el.tira.setAttribute("height", est.esp.toFixed(2));
      el.tira.setAttribute("fill", est.cor);
      if (est.cor2) {
        el.tira2.setAttribute("x", el.tira.getAttribute("x"));
        el.tira2.setAttribute("y", (l.y + est.esp).toFixed(1));
        el.tira2.setAttribute("width", larg.toFixed(1));
        el.tira2.setAttribute("height", est.esp2);
        el.tira2.setAttribute("fill", est.cor2);
        el.tira2.setAttribute("opacity", 1);
      } else el.tira2.setAttribute("opacity", 0);
      el.tiras.setAttribute("opacity", 1);
    } else el.tiras.setAttribute("opacity", 0);

    desenhaVergadura(B.x, l.y, l);
  } else {
    el.contato.setAttribute("opacity", 0);
    el.tiras.setAttribute("opacity", 0);
  }

  passoParticulas(dt);
  passoCarga(dt);
}

/* =========================================================================
   9. Laço + entradas
   ======================================================================= */

var anterior = performance.now();
var rodando = false;

function quadro(agora) {
  if (!rodando) return;
  var dt = Math.min(0.045, (agora - anterior) / 1000);
  anterior = agora;
  // aba escondida congela o rAF de qualquer jeito; sem isto o primeiro quadro
  // de volta vem com um dt enorme e ele "teleporta"
  if (!document.hidden) { passo(dt); desenha(dt); }
  requestAnimationFrame(quadro);
}

function comecarLaco() {
  if (rodando) return;
  rodando = true;
  anterior = performance.now();
  requestAnimationFrame(quadro);
}
function pararLaco() { rodando = false; }

/* =========================================================================
   10. Entrar em cena, sair de cena, e o que a página pode pedir a ele
   ======================================================================= */

/* ------------------------------------------------------------------ entradas
   Trocar de página é a hora em que ele reaparece, e sempre pelo mesmo canto
   vira papel de parede. São quatro chegadas diferentes, sorteadas: a que ele
   espia pela borda pesa o dobro porque é a mais interessante de ver.
   -------------------------------------------------------------------------- */
var ENTRADAS = ["espiar", "espiar", "mergulho", "lateral", "subir"];

function entrarEmCena(estilo) {
  ceu.style.display = "";
  B.poleiro = null; B.tarefa = null; B.destino = null;
  B.corpoX = 0; B.corpoY = 0; B.escX = 1; B.desequilibrio = 0; B.ultimaLinhaY = 0;
  comecarLaco();

  estilo = estilo || sorteio(ENTRADAS);
  var vh = innerHeight, vw = innerWidth;

  if (estilo === "espiar" && prepararEspiada()) return;

  if (estilo === "mergulho") {                       // cai do alto, quase a pique
    B.x = rnd(vw * 0.25, vw * 0.75); B.y = -80;
    B.vx = rnd(-60, 60); B.vy = 380;
    B.vista = "perfil";
  } else if (estilo === "subir") {                   // sobe de baixo da tela
    B.x = rnd(vw * 0.2, vw * 0.8); B.y = vh + 80;
    B.vx = rnd(-90, 90); B.vy = -340;
    B.vista = "perfil";
  } else {                                           // entra por um dos lados
    var daEsquerda = Math.random() < 0.5;
    B.x = daEsquerda ? -70 : vw + 70;
    B.y = vh * rnd(0.18, 0.55);
    B.vx = daEsquerda ? 300 : -300;
    B.vy = rnd(-20, 60);
    B.vista = "perfil";
  }
  mandaPousar(escolhePoleiro(null));
}

/* Espiada pela borda: ele aparece na beirada da tela com só a cabeça de fora,
   olha em volta e então desliza para dentro e se agarra na linha mais próxima
   daquele lado. Só depois disso volta a voar pelo layout. */
function prepararEspiada() {
  var lista = listarPoleiros();
  if (!lista.length) return false;

  var vh = innerHeight, vw = innerWidth;
  var lado = sorteio(["esquerda", "direita", "baixo"]);

  // a linha escolhida é a mais próxima da borda por onde ele espia, senão a
  // escalada viraria um voo travessando a tela inteira
  var melhor = null, melhorD = Infinity;
  for (var i = 0; i < lista.length; i++) {
    var l = linhaDo(lista[i]);
    var cx = (l.x0 + l.x1) / 2;
    var d = lado === "esquerda" ? l.x0
          : lado === "direita"  ? vw - l.x1
          : vh - l.y;
    if (lado === "baixo" && l.y < vh * 0.45) continue;   // perto do pé da tela
    if (d < melhorD) { melhorD = d; melhor = lista[i]; }
  }
  if (!melhor) return false;

  var linha = linhaDo(melhor);
  melhor.ang = rnd(-1.6, 1.6);

  if (lado === "baixo") {
    melhor.frac = clamp((rnd(linha.x0 + 30, linha.x1 - 30) - linha.x0) / Math.max(1, linha.x1 - linha.x0), 0.1, 0.9);
    B.vista = "frente"; B.rumo = 1;
    B.x = lerp(linha.x0, linha.x1, melhor.frac);
    // pé bem abaixo da tela: passa da borda só da testa aos olhos
    B.y = vh + 30 * B.esc;
  } else {
    melhor.frac = lado === "esquerda" ? rnd(0.12, 0.35) : rnd(0.65, 0.88);
    B.vista = "perfil";
    B.rumo = lado === "esquerda" ? 1 : -1;
    // o corpo fica além da borda; quem entra na tela é a cabeça, e ela só
    // chega lá porque ele se inclina para fora (o B.pitch da fase espiando).
    // Sem a inclinação o peito apareceria junto, e aí não é espiar, é estar.
    B.x = lado === "esquerda" ? -21 * B.esc : vw + 21 * B.esc;
    B.y = clamp(linha.y + rnd(-30, 30), vh * 0.22, vh * 0.78);
  }

  B.espiada = { lado: lado, poleiro: melhor };
  B.vx = 0; B.vy = 0;
  B.aperto = 1; B.peY = 0; B.pitch = 0;
  B.cabecaAlvo = 0; B.cabecaAng = 0;
  troca("espiando");
  return true;
}

function sairDeCena() {
  pararLaco();
  largarCargaNaMarra();
  ceu.style.display = "none";
  B.poleiro = null; B.tarefa = null;
  apagarDebug();
}

/* =========================================================================
   11. Lixeira e carga

   A lixeira não existe no HTML de nenhuma página: ela é criada aqui e só
   aparece enquanto ele está carregando alguma coisa. Assim o mascote não
   acrescenta mobília permanente à interface.
   ======================================================================= */

var lixeira = null;

function criarLixeira() {
  if (lixeira) return lixeira;
  lixeira = document.createElement("div");
  lixeira.id = "echo-bit-lixeira";
  lixeira.setAttribute("aria-hidden", "true");
  lixeira.style.cssText =
    "position:fixed;right:30px;bottom:30px;width:58px;height:58px;border-radius:50%;" +
    "display:grid;place-items:center;z-index:899;pointer-events:none;" +
    "background:#0f1419;border:1px solid #2f3336;box-shadow:0 8px 24px rgba(0,0,0,.55);" +
    "opacity:0;transform:translateY(26px) scale(.8);transition:opacity .28s ease,transform .28s cubic-bezier(.2,1.4,.4,1);";
  lixeira.innerHTML =
    '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#8b98a5" ' +
    'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
    '<path id="echo-bit-tampa" d="M3 6h18M9 6V4h6v2" style="transform-origin:12px 6px;transition:transform .18s ease"/>' +
    '<path d="M5 6l1 14h12l1-14"/><path d="M10 10v7M14 10v7"/></svg>';
  document.body.appendChild(lixeira);
  return lixeira;
}

function mostrarLixeira(ligar) {
  var n = criarLixeira();
  if (ligar) {
    n.style.opacity = "1";
    n.style.transform = "translateY(0) scale(1)";
    n.style.borderColor = "#f4212e";
  } else {
    n.style.opacity = "0";
    n.style.transform = "translateY(26px) scale(.8)";
    n.style.borderColor = "#2f3336";
  }
}

function sacudirLixeira() {
  var n = criarLixeira();
  var tampa = n.querySelector("#echo-bit-tampa");
  if (tampa) {
    tampa.style.transform = "rotate(-26deg) translateY(-2px)";
    setTimeout(function () { tampa.style.transform = ""; }, 320);
  }
  var t0 = performance.now();
  (function treme() {
    var k = (performance.now() - t0) / 420;
    if (k >= 1) { n.style.transform = "translateY(0) scale(1)"; return; }
    var a = Math.sin(k * 34) * (1 - k) * 4;
    n.style.transform = "translateY(0) scale(1) translateX(" + a.toFixed(1) + "px)";
    requestAnimationFrame(treme);
  })();
}

// Boca da lixeira: é para lá que ele voa carregando.
function destinoDoLixo() {
  var alvo = document.querySelector("[data-bit-lixeira]") || lixeira;
  if (alvo) {
    var r = alvo.getBoundingClientRect();
    if (r.width) return { x: r.left + r.width / 2, y: r.top - 34 };
  }
  return { x: innerWidth - 60, y: innerHeight - 100 };
}

/* ------------------------------------------------------------------ o fardo
   O elemento apagado não some e vira um retângulo genérico: ele é clonado,
   encolhido e passa a pendurar nos pés do bicho. O usuário vê exatamente a
   coisa que mandou apagar sendo levada. */
var carga = null;

function prenderCarga(alvoEl) {
  if (!alvoEl) return;
  var r = alvoEl.getBoundingClientRect();

  // Encolher um post inteiro (perto de 900px de largura) dá uma tira fina e
  // ilegível. Em vez disso recorta-se um pedaço quase quadrado do canto de
  // cima e é ele que viaja: dá para reconhecer o que está sendo levado.
  var recorteL = Math.min(r.width, 330);
  var recorteA = Math.min(r.height, 220);
  var escala = clamp(140 / recorteL, 0.22, 0.9);
  var cs = getComputedStyle(alvoEl);

  var caixa = document.createElement("div");
  caixa.setAttribute("aria-hidden", "true");
  caixa.style.cssText =
    "position:fixed;left:0;top:0;z-index:898;pointer-events:none;overflow:hidden;" +
    "width:" + recorteL + "px;height:" + recorteA + "px;" +
    "transform-origin:50% 0;will-change:transform;" +
    "background:" + (cs.backgroundColor && cs.backgroundColor.indexOf("rgba(0, 0, 0, 0)") === -1 ? cs.backgroundColor : "#0f1419") + ";" +
    "border:" + (2 / escala).toFixed(1) + "px solid #2f3336;" +
    "border-radius:" + (14 / escala).toFixed(1) + "px;" +
    "box-shadow:0 " + (18 / escala).toFixed(0) + "px " + (40 / escala).toFixed(0) + "px rgba(0,0,0,.65);";

  var copia = alvoEl.cloneNode(true);
  copia.removeAttribute("id");
  copia.style.margin = "0";
  copia.style.width = r.width + "px";
  copia.style.border = "0";
  caixa.appendChild(copia);
  document.body.appendChild(caixa);

  carga = { n: caixa, larg: recorteL, escala: escala, giro: 0, giroVel: 0, solto: false, vy: 0 };

  // o original sai agora: daqui pra frente quem viaja é a cópia
  if (alvoEl.parentNode) alvoEl.parentNode.removeChild(alvoEl);
}

function soltarCarga() {
  if (!carga) return;
  carga.solto = true;
  carga.vy = 40;
}

function passoCarga(dt) {
  if (!carga) return;
  var alvoX = B.x, alvoY = B.y + 4 * B.esc;

  if (carga.solto) {
    carga.vy += 1500 * dt;
    carga.quedaY = (carga.quedaY || 0) + carga.vy * dt;
    var dest = destinoDoLixo();
    alvoX = carga.soltoEm ? carga.soltoEm.x : (carga.soltoEm = { x: B.x, y: B.y }).x;
    alvoY = carga.soltoEm.y + carga.quedaY;
    carga.escala *= (1 - Math.min(1, dt * 2.2));          // afunila ao entrar
    if (alvoY > dest.y + 60) { carga.n.remove(); carga = null; return; }
  } else {
    // balança como peso pendurado: atrasa o giro em relação ao movimento
    var alvoGiro = clamp(B.vx * 0.035, -22, 22);
    carga.giroVel += (alvoGiro - carga.giro) * dt * 9;
    carga.giroVel *= 0.86;
    carga.giro += carga.giroVel;
  }

  carga.n.style.transform =
    "translate(" + (alvoX - carga.larg / 2).toFixed(1) + "px," + alvoY.toFixed(1) + "px)" +
    " scale(" + carga.escala.toFixed(3) + ") rotate(" + carga.giro.toFixed(1) + "deg)";
}

function largarCargaNaMarra() {
  if (carga) { carga.n.remove(); carga = null; }
  mostrarLixeira(false);
}

// Alerta curto quando a pessoa começa a digitar: ele percebe e olha.
// Nenhuma tecla é capturada nem cancelada — o app continua dono do teclado.
addEventListener("keydown", function () {
  if (!rodando || B.fase !== "pousado" || B.gesto) return;
  B.gesto = "olhar"; B.gestoT = 0; B.gestoDur = 0.5; B.gestoSinal = 1;
}, { passive: true });

/* ------------------------------------------------- ele reage ao que você faz

   Delegação num único listener, em fase de captura, passivo: nenhum handler da
   página é tocado e nenhum clique é interceptado. Publicar espanta; os botões
   de ação de um post (curtir, comentar, salvar) só puxam o olhar.

   Para ligar qualquer outro elemento: data-bit-susto ou data-bit-olhar. */
var ESPANTAM = "#btnPostar,[data-bit-susto]";
var CHAMAM_ATENCAO = ".icon-btn,.echo-post-toggle,[data-bit-olhar]";

document.addEventListener("click", function (ev) {
  if (!rodando) return;
  var alvo = ev.target;
  if (!alvo || !alvo.closest) return;

  var espanta = alvo.closest(ESPANTAM);
  if (espanta) {
    var r = espanta.getBoundingClientRect();
    EchoBit.assustar({ x: r.left + r.width / 2, y: r.top + r.height / 2 });
    return;
  }

  var chama = alvo.closest(CHAMAM_ATENCAO);
  if (chama) {
    var r2 = chama.getBoundingClientRect();
    EchoBit.olharPara(r2.left + r2.width / 2, r2.top + r2.height / 2, rnd(1.4, 2.2));
  }
}, true);

// A largura manda: em telas estreitas a coluna é do conteúdo.
function conferirLargura() {
  estilos = new WeakMap();          // o CSS responsivo pode ter trocado as bordas
  if (rodando && innerWidth < LARGURA_MIN) sairDeCena();
  else if (!rodando && podeAparecer()) entrarEmCena();
  if (debug) pintaDebug();
}
addEventListener("resize", conferirLargura);
// alguns casos não disparam resize (rotação, painel embutido, zoom de página):
// observar a raiz pega todos
if (window.ResizeObserver) new ResizeObserver(conferirLargura).observe(document.documentElement);
addEventListener("scroll", function () { if (debug) pintaDebug(); }, { passive: true });

/* ------------------------------------------------------------ linhas de debug */
var debug = false, linhasDebug = [];
function apagarDebug() {
  for (var i = 0; i < linhasDebug.length; i++) {
    if (linhasDebug[i].parentNode) linhasDebug[i].parentNode.removeChild(linhasDebug[i]);
  }
  linhasDebug = [];
}
function pintaDebug() {
  apagarDebug();
  if (!debug) return;
  listarPoleiros().forEach(function (p) {
    var l = linhaDo(p);
    var n = document.createElement("div");
    n.style.cssText = "position:fixed;height:2px;background:#ff2d95;opacity:.75;" +
                      "z-index:899;pointer-events:none;left:" + l.x0 + "px;top:" +
                      l.y + "px;width:" + (l.x1 - l.x0) + "px";
    document.body.appendChild(n); linhasDebug.push(n);
  });
}

/* ---------------------------------------------------------------- API pública */
window.EchoBit = {
  ligar: function () { guardarPreferencia(false); if (!rodando && podeAparecer()) entrarEmCena(); pintarBotao(); },
  desligar: function () { guardarPreferencia(true); sairDeCena(); pintarBotao(); },
  alternar: function () { if (rodando) this.desligar(); else this.ligar(); return rodando; },
  ativo: function () { return rodando; },

  // Ele voa até o elemento, fecha os pés nele e leva embora. O elemento some
  // no instante da pegada; aoTerminar dispara quando ele solta no destino —
  // é ali que a página faz o DELETE de verdade, se for o caso.
  levarAoLixo: function (elemento, opcoes) {
    // sem ele em cena, ou com uma carga já pendurada, quem chamou trata do
    // sumiço por conta própria — por isso devolve false em vez de enfileirar
    if (!rodando || !elemento || !elemento.isConnected || B.tarefa || carga) return false;
    opcoes = opcoes || {};
    var r = elemento.getBoundingClientRect();
    if (!r.width || r.bottom < 0 || r.top > innerHeight) return false;

    B.tarefa = {
      tipo: "indoPegar",
      alvoEl: elemento,
      aoTerminar: opcoes.aoTerminar || null,
      borda: { x: r.left + r.width * 0.34, y: r.top },
      ponto: { x: r.left + r.width * 0.34, y: r.top - 78 }
    };
    if (B.fase === "pousado") levanta();
    else { B.alvo = B.tarefa.ponto; B.pegaDe = null; troca("indoPegar"); }
    return true;
  },

  // Ele sai voando na hora, sem o agachamento de sempre, dá uma volta no ar e
  // procura outro poleiro. É a reação a algo que "aconteceu" na tela.
  assustar: function (origem) {
    if (!rodando || B.tarefa || carga) return false;
    if (B.fase !== "pousado" && B.fase !== "espiando") return false;
    B.susto = 1;
    B.gesto = null; B.andar = null; B.olhar = null;
    B.poleiro = null; B.espiada = null;
    var lado = B.x < innerWidth / 2 ? 1 : -1;
    B.rumo = lado;
    B.giroInfo = {
      cx: clamp(B.x + lado * 120, 140, innerWidth - 140),
      cy: clamp(B.y - 70, 110, innerHeight - 140),
      raio: rnd(95, 150),
      a0: Math.atan2(B.y - (B.y - 70), 0.001) + Math.PI,
      sentido: lado
    };
    if (origem && origem.x != null) {
      B.giroInfo.cx = clamp(origem.x + lado * 130, 140, innerWidth - 140);
      B.giroInfo.cy = clamp(origem.y - 90, 110, innerHeight - 140);
    }
    troca("rodopio");
    return true;
  },

  // Vira a cabeça para um ponto da tela por alguns segundos.
  olharPara: function (x, y, segundos) {
    if (!rodando || !isFinite(x) || !isFinite(y)) return false;
    B.olhar = { x: x, y: y, t: segundos || 1.6 };
    if (B.piscar > 0.5) B.piscar = 0.28;              // uma piscada de "ué?"
    return true;
  },

  marcar: function (el) { if (el) el.setAttribute("data-poleiro", ""); },
  desmarcar: function (el) { if (el) el.removeAttribute("data-poleiro"); },
  poleiros: listarPoleiros,
  debug: function (v) { debug = v !== false; pintaDebug(); },

  // acesso cru, para ajustar no console
  _estado: B,
  // "espiar" | "mergulho" | "lateral" | "subir" — sem argumento, sorteia
  _entrar: function (estilo) { sairDeCena(); entrarEmCena(estilo); },
  _passo: function (dt) { passo(dt || 0.016); desenha(dt || 0.016); },
  _rodar: function (seg, dt) { dt = dt || 0.016; for (var i = 0; i < seg / dt; i++) { passo(dt); desenha(dt); } }
};

/* =========================================================================
   12. Botão de ligar/desligar

   Ele se instala sozinho ao lado do botão de sair, no mini perfil da barra
   lateral, herdando as classes do projeto para não destoar. Se a página não
   tiver esse bloco, vira um botão flutuante no canto. Fica FORA do overlay:
   precisa continuar clicável com o mascote desligado.
   ======================================================================= */

var botao = null;

function svgBit(cortado) {
  return '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
         '<path d="M20.5 7.2c0-2.6-2.1-4.7-4.7-4.7-2.2 0-4 1.5-4.5 3.5-2.6.5-4.6 2.8-4.6 5.6 0 .9.2 1.7.6 2.4l-3.6 4.7c-.3.4-.2 1 .2 1.3.4.3 1 .2 1.3-.2l3.4-4.5c1 .8 2.3 1.3 3.7 1.3 3.2 0 5.8-2.6 5.8-5.8V9.6c1.5-.4 2.4-1.3 2.4-2.4z"/>' +
         '<circle cx="16.6" cy="6.6" r="1.05" fill="#0f1419"/>' +
         (cortado ? '<path d="M3.5 3.5 L20.5 20.5" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" fill="none"/>' : "") +
         "</svg>";
}

function pintarBotao() {
  if (!botao) return;
  var ligado = !preferenciaDesligada();
  botao.innerHTML = svgBit(!ligado);
  botao.title = ligado ? "Esconder o Bit" : "Mostrar o Bit";
  botao.setAttribute("aria-label", botao.title);
  botao.setAttribute("aria-pressed", ligado ? "true" : "false");
  botao.style.opacity = ligado ? "1" : ".55";
}

function instalarBotao() {
  if (botao || PARADO) return;          // com reduced-motion ele nunca entra em cena

  var perfil = document.querySelector(".sidebar-profile");
  botao = document.createElement("button");
  botao.type = "button";
  botao.id = "echo-bit-botao";

  if (perfil) {
    // mesmas classes do botão de sair, para sair igualzinho
    botao.className = "btn btn-sm btn-outline-secondary rounded-pill";
    botao.style.cssText = "display:inline-grid;place-items:center;line-height:1;";
    var sair = perfil.querySelector("button");
    perfil.insertBefore(botao, sair || null);
  } else {
    botao.style.cssText =
      "position:fixed;left:18px;bottom:18px;z-index:901;width:36px;height:36px;" +
      "display:grid;place-items:center;border-radius:50%;cursor:pointer;" +
      "background:#0f1419;color:#8b98a5;border:1px solid #2f3336;";
    document.body.appendChild(botao);
  }

  botao.addEventListener("click", function (ev) {
    ev.preventDefault();
    ev.stopPropagation();
    if (rodando) EchoBit.desligar(); else EchoBit.ligar();
    pintarBotao();
  });

  pintarBotao();
}

if (document.readyState === "loading") addEventListener("DOMContentLoaded", instalarBotao);
else instalarBotao();

if (podeAparecer()) setTimeout(entrarEmCena, rnd(700, 2200));
else ceu.style.display = "none";


})();
