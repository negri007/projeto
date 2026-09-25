/**
 * fontes-baixar.js — baixa UMA VEZ as fontes dos modelos para public/fonts,
 * pra o render nao depender do Google Fonts (nem de internet).
 *
 *   node motor/fontes-baixar.js
 *
 * Le os enderecos do proprio @remotion/google-fonts (os MESMOS .woff2, mesma
 * versao, todos os pesos/estilos/subsets que o loadFont() sem opcoes
 * carregava) e grava:
 *   - public/fonts/<familia>-<estilo>-<peso>-<subset>.woff2
 *   - src/fontes-manifesto.json  (familia, estilo, peso, unicode-range, arquivo)
 * que o src/fontes.js carrega via @remotion/fonts.
 *
 * Os arquivos ja vem versionados; so precisa rodar de novo pra trocar de
 * versao de fonte ou incluir uma familia nova (lista FAMILIAS abaixo).
 */

const fs = require('fs');
const path = require('path');

const FAMILIAS = ['Anton', 'Poppins'];

const RAIZ = __dirname;
const DESTINO = path.join(RAIZ, 'public', 'fonts');
const MANIFESTO = path.join(RAIZ, 'src', 'fontes-manifesto.json');

async function main() {
  fs.mkdirSync(DESTINO, {recursive: true});
  const manifesto = {};

  for (const familia of FAMILIAS) {
    const info = require('@remotion/google-fonts/' + familia).getInfo();
    const faces = [];
    for (const [estilo, pesos] of Object.entries(info.fonts)) {
      for (const [peso, subsets] of Object.entries(pesos)) {
        for (const [subset, url] of Object.entries(subsets)) {
          const arquivo = [familia, estilo, peso, subset].join('-').toLowerCase().replace(/[^a-z0-9-]/g, '') + '.woff2';
          const r = await fetch(url);
          if (!r.ok) throw new Error(url + ' -> HTTP ' + r.status);
          fs.writeFileSync(path.join(DESTINO, arquivo), Buffer.from(await r.arrayBuffer()));
          faces.push({estilo, peso, subset, arquivo, unicodeRange: info.unicodeRanges[subset] || null});
        }
      }
    }
    manifesto[familia] = {fontFamily: info.fontFamily, versao: info.version, faces};
    console.log(familia + ' ' + info.version + ': ' + faces.length + ' arquivos');
  }

  fs.writeFileSync(MANIFESTO, JSON.stringify(manifesto, null, 2) + '\n');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
