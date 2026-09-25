import {loadFont} from '@remotion/fonts';
import {staticFile} from 'remotion';
import manifesto from './fontes-manifesto.json';

// FONTES LOCAIS — os mesmos .woff2 que o @remotion/google-fonts buscava na
// rede, baixados uma vez para public/fonts (node motor/fontes-baixar.js).
// O render nao faz requisicao nenhuma ao Google Fonts e funciona sem
// internet. Carrega todos os pesos/estilos/subsets com o mesmo
// unicode-range, como o loadFont() do google-fonts sem opcoes fazia, entao
// o visual nao muda.
//
// Uso nos modelos: `const ANTON = loadAnton().fontFamily;` (mesma forma do
// loadFont() do google-fonts).

const carregadas = {};

function carregar(nome) {
  const familia = manifesto[nome];
  if (!carregadas[nome]) {
    carregadas[nome] = true;
    for (const f of familia.faces) {
      loadFont({
        family: familia.fontFamily,
        url: staticFile('fonts/' + f.arquivo),
        format: 'woff2',
        style: f.estilo,
        weight: f.peso,
        unicodeRange: f.unicodeRange || undefined,
      });
    }
  }
  return {fontFamily: familia.fontFamily};
}

export const loadAnton = () => carregar('Anton');
export const loadPoppins = () => carregar('Poppins');
