/**
 * render.js — ponte que o back-end (PHP) chama para renderizar UM anuncio.
 *
 *   node motor/render.js <caminho-do-job.json>
 *
 * O job.json descreve o que renderizar:
 *   {
 *     "modelo":  "Flash",              // id registrado em src/Root.jsx
 *     "formato": "story",              // quadrado | story | feed | paisagem
 *     "nicho":   "comida",             // define preset + trilha padrao
 *     "musica":  "comida_m",           // opcional; "" = sem trilha
 *     "out":     "C:/.../saida.mp4",   // caminho absoluto do MP4 final
 *     "props":   { ...campos da loja... }  // chamada, preco, cta, foto, etc.
 *   }
 *
 * Imprime UMA linha JSON no stdout: {"ok":true,"out":"..."} ou
 * {"ok":false,"erro":"..."}. O PHP le essa linha. Todo ruido do Remotion
 * vai pro stderr, entao o stdout fica limpo pra ser parseado.
 *
 * Usa a API programatica do Remotion (@remotion/bundler + @remotion/renderer)
 * em vez do CLI: o CLI re-empacota o projeto (webpack + copia do public/) a
 * cada video. Aqui o bundle fica cacheado em motor/.bundle e so e refeito
 * quando algo em src/ ou public/ fica mais novo que ele. As fotos que o PHP
 * copia pra public/uploads a cada peca NAO invalidam o cache — cada render
 * copia so as do proprio job pra .bundle/public/uploads, e as sem uso ha
 * mais de 7 dias saem de la (ver copiarFotosDoJob / limparFotosVelhas).
 *
 * Sem dependencia de ffmpeg externo: a trilha entra via <Audio> do Remotion
 * (ver src/Root.jsx), entao basta `npm install` + `remotion browser ensure`.
 */

const fs = require('fs');
const path = require('path');

// Guarda o stdout real so pra linha JSON final; todo o resto (console.* do
// Remotion, logs do browser, progresso do webpack) vai pro stderr.
const stdoutWrite = process.stdout.write.bind(process.stdout);
process.stdout.write = process.stderr.write.bind(process.stderr);
for (const m of ['log', 'info', 'warn', 'debug', 'trace']) {
  console[m] = (...args) => process.stderr.write(require('util').format(...args) + '\n');
}

const RAIZ = __dirname; // .../motor
const SRC = path.join(RAIZ, 'src');
const PUBLIC = path.join(RAIZ, 'public');
const UPLOADS = path.join(PUBLIC, 'uploads');
const BUNDLE = path.join(RAIZ, '.bundle');
const CARIMBO = path.join(BUNDLE, '.carimbo'); // gravado so quando o bundle termina inteiro

const MODELOS_OK = new Set([
  'Flash', 'Historia', 'Manchete', 'Vitrine', 'Ficha', 'Luxo', 'Glitch',
  'Editorial', 'AntesDepois', 'Depoimento', 'Combo', 'Cupom', 'Countdown',
]);
const FORMATOS_OK = new Set(['quadrado', 'story', 'feed', 'paisagem']);

function sair(obj) {
  stdoutWrite(JSON.stringify(obj) + '\n');
  process.exit(obj.ok ? 0 : 1);
}

// TEMPO MAXIMO do render. O PHP manda `timeout_s` no job (render_timeout_s
// do video_config.php); sem ele, 480s. Teto de 540s: tem de estourar ANTES
// dos 10 min em que o PHP da a peca por travada e mata tudo.
const PRAZO_PADRAO_S = 480;
const PRAZO_MAX_S = 540;
function prazoDoJob(job) {
  const n = Number(job.timeout_s);
  if (!Number.isFinite(n) || n <= 0) return PRAZO_PADRAO_S;
  return Math.min(Math.max(Math.round(n), 1), PRAZO_MAX_S);
}

// O Chrome e aberto aqui (openBrowser) e passado ao selectComposition e ao
// renderMedia, pra ser SEMPRE fechado por nos — no fim, no erro e no
// estouro do prazo. Sem isso, um process.exit no meio deixaria o Chrome
// orfao.
let chrome = null;
async function fecharChrome() {
  const c = chrome;
  chrome = null;
  if (c) {
    try { await c.close({silent: true}); } catch (_) {}
  }
}

// Bundle temporario sendo gerado agora (apagado se o prazo estourar nele).
let bundleEmAndamento = null;

// Bundle temporario de um render que morreu no meio: apaga depois de 1h.
function limparBundlesOrfaos() {
  const pai = path.dirname(BUNDLE);
  const prefixo = path.basename(BUNDLE) + '-tmp-';
  let nomes;
  try { nomes = fs.readdirSync(pai); } catch (_) { return; }
  for (const nome of nomes) {
    if (!nome.startsWith(prefixo)) continue;
    const p = path.join(pai, nome);
    try {
      if (fs.statSync(p).mtimeMs < Date.now() - 60 * 60 * 1000) fs.rmSync(p, {recursive: true, force: true});
    } catch (_) {}
  }
}

// mtime mais novo de uma arvore; `pular` sao pastas ignoradas.
function mtimeMaisNovo(dir, pular = new Set()) {
  let max = 0;
  let ents;
  try { ents = fs.readdirSync(dir, {withFileTypes: true}); } catch (_) { return 0; }
  for (const e of ents) {
    const p = path.join(dir, e.name);
    if (pular.has(p)) continue;
    if (e.isDirectory()) {
      max = Math.max(max, mtimeMaisNovo(p, pular));
    } else {
      try { max = Math.max(max, fs.statSync(p).mtimeMs); } catch (_) {}
    }
  }
  return max;
}

function bundleValido() {
  let carimbo;
  try { carimbo = fs.statSync(CARIMBO).mtimeMs; } catch (_) { return false; }
  const fonte = Math.max(
    mtimeMaisNovo(SRC),
    mtimeMaisNovo(PUBLIC, new Set([UPLOADS])),
  );
  // troca de versao do Remotion (npm install) tambem pede bundle novo.
  let lock = 0;
  try { lock = fs.statSync(path.join(RAIZ, 'package-lock.json')).mtimeMs; } catch (_) {}
  return Math.max(fonte, lock) <= carimbo;
}

// Empacota numa pasta temporaria e so depois troca pela .bundle, pra um
// render concorrente nunca pegar um bundle pela metade. Devolve a pasta que
// deve ser servida (normalmente .bundle).
async function garantirBundle() {
  if (bundleValido()) return {dir: BUNDLE, temporario: false, novo: false};

  const {bundle} = require('@remotion/bundler');
  const tmp = BUNDLE + '-tmp-' + process.pid + '-' + Date.now();
  bundleEmAndamento = tmp;
  await bundle({
    entryPoint: path.join(SRC, 'index.js'),
    outDir: tmp,
    enableCaching: true,
  });
  // O bundle() copia o public/ inteiro, inclusive todas as fotos que as
  // lojas ja mandaram. Elas nao precisam estar ali: cada render copia so as
  // do proprio job (copiarFotosDoJob), entao o bundle novo nasce sem elas.
  fs.rmSync(path.join(tmp, 'public', 'uploads'), {recursive: true, force: true});
  fs.writeFileSync(path.join(tmp, '.carimbo'), new Date().toISOString());

  try {
    fs.rmSync(BUNDLE, {recursive: true, force: true});
    fs.renameSync(tmp, BUNDLE);
    bundleEmAndamento = null;
    return {dir: BUNDLE, temporario: false, novo: true};
  } catch (e) {
    // .bundle em uso por outro render (Windows trava arquivo aberto):
    // renderiza deste temporario e apaga no fim; o proximo render refaz.
    console.warn('render.js: nao troquei .bundle (' + e.code + '), usando ' + tmp);
    return {dir: tmp, temporario: true, novo: true};
  }
}

// Fotos que o PHP gravou em public/uploads (props com "uploads/<arquivo>":
// foto, fotos[], fotoAntes/fotoDepois, itens[].foto...). Procura em qualquer
// lugar dos props pra nao depender do formato de cada modelo.
function fotosDoJob(valor, achadas = new Set()) {
  if (typeof valor === 'string') {
    const m = /^uploads\/([^/\\]+)$/.exec(valor);
    // so nome de arquivo simples: nada de ".." nem subpasta.
    if (m && m[1] !== '.' && m[1] !== '..') achadas.add(m[1]);
  } else if (valor && typeof valor === 'object') {
    for (const v of Object.values(valor)) fotosDoJob(v, achadas);
  }
  return achadas;
}

// Copia so as fotos DESTE job pro bundle, onde o staticFile() as procura, e
// renova a data delas — a data de modificacao da copia e o "ultimo uso".
// Copiar tudo a cada render fazia dois renders simultaneos brigarem pelo
// mesmo arquivo (EBUSY no Windows); por isso tambem a copia vai pra um nome
// temporario e so depois e renomeada.
function copiarFotosDoJob(dir, nomes) {
  const destDir = path.join(dir, 'public', 'uploads');
  fs.mkdirSync(destDir, {recursive: true});
  const agora = new Date();
  for (const nome of nomes) {
    const de = path.join(UPLOADS, nome);
    const para = path.join(destDir, nome);
    let sDe;
    try { sDe = fs.statSync(de); } catch (_) { continue; } // o render acusa a falta
    if (!sDe.isFile()) continue;
    let pronta = false;
    try { pronta = fs.statSync(para).size === sDe.size; } catch (_) {}
    if (!pronta) {
      const tmp = para + '.tmp-' + process.pid;
      fs.copyFileSync(de, tmp);
      try {
        fs.renameSync(tmp, para);
      } catch (e) {
        // outro render terminou a mesma copia antes: vale a dele.
        try { fs.unlinkSync(tmp); } catch (_) {}
        if (!fs.existsSync(para)) throw e;
      }
    }
    try { fs.utimesSync(para, agora, agora); } catch (_) { /* em uso: renova no proximo */ }
  }
}

// Apaga do bundle as fotos sem uso ha mais de 7 dias. Os originais em
// public/uploads ficam; se a foto voltar a ser usada, e copiada de novo.
const UPLOADS_VALIDADE_MS = 7 * 24 * 60 * 60 * 1000;
function limparFotosVelhas(dir, emUso) {
  const destDir = path.join(dir, 'public', 'uploads');
  let nomes;
  try { nomes = fs.readdirSync(destDir); } catch (_) { return; }
  const limite = Date.now() - UPLOADS_VALIDADE_MS;
  for (const nome of nomes) {
    if (emUso.has(nome)) continue;
    const p = path.join(destDir, nome);
    try {
      if (fs.statSync(p).mtimeMs < limite) fs.unlinkSync(p);
    } catch (_) { /* em uso por outro render: fica pro proximo */ }
  }
}

async function main() {
  const jobPath = process.argv[2] ? path.resolve(process.argv[2]) : '';

  // O renderer acha o Chromium (node_modules/.remotion, baixado pelo
  // ensure-browser) e o bundler acha a raiz do projeto a partir do cwd. O
  // PHP chama de outra pasta, entao roda sempre de dentro de motor/ — como
  // o CLI antigo fazia com execSync({cwd: RAIZ}).
  process.chdir(RAIZ);

  if (!jobPath || !fs.existsSync(jobPath)) {
    sair({ok: false, erro: 'job.json nao encontrado'});
  }

  let job;
  try {
    job = JSON.parse(fs.readFileSync(jobPath, 'utf8'));
  } catch (e) {
    sair({ok: false, erro: 'job.json invalido: ' + e.message});
  }

  const modelo = String(job.modelo || '');
  const formato = String(job.formato || 'quadrado');
  const out = String(job.out || '');

  if (!MODELOS_OK.has(modelo)) sair({ok: false, erro: 'modelo desconhecido: ' + modelo});
  if (!FORMATOS_OK.has(formato)) sair({ok: false, erro: 'formato desconhecido: ' + formato});
  if (!out) sair({ok: false, erro: 'out ausente'});

  // props finais entregues ao componente: campos da loja + nicho/formato/musica.
  const props = Object.assign({}, job.props || {}, {
    nicho: job.nicho || 'comida',
    formato,
  });
  if (job.musica !== undefined) props.musica = job.musica;

  // garante a pasta de saida.
  try {
    fs.mkdirSync(path.dirname(out), {recursive: true});
  } catch (e) { /* ok se ja existe */ }

  limparBundlesOrfaos();

  const prazo = prazoDoJob(job);
  const {selectComposition, renderMedia, openBrowser, makeCancelSignal} = require('@remotion/renderer');
  const {cancelSignal, cancel} = makeCancelSignal();
  let estourou = false;
  const msgPrazo = 'render excedeu ' + prazo + 's';

  // Estourou: cancela o renderMedia e fecha o Chrome (o bundle e o
  // selectComposition nao aceitam cancelamento; fechar o Chrome derruba o
  // segundo). Se em 10s o main ainda nao tiver saido, sai na marra — ja com
  // o Chrome fechado, entao nada fica orfao.
  const relogio = setTimeout(() => {
    estourou = true;
    console.warn('render.js: ' + msgPrazo + ', cancelando');
    cancel();
    fecharChrome();
    setTimeout(async () => {
      await fecharChrome();
      try { fs.unlinkSync(out); } catch (_) {}
      if (bundleEmAndamento) {
        try { fs.rmSync(bundleEmAndamento, {recursive: true, force: true}); } catch (_) {}
      }
      sair({ok: false, erro: msgPrazo});
    }, 10000);
  }, prazo * 1000);

  let serve;
  let erro = null;
  try {
    const t0 = Date.now();
    serve = await garantirBundle();
    const t1 = Date.now();
    const fotos = fotosDoJob(props);
    copiarFotosDoJob(serve.dir, fotos);
    limparFotosVelhas(serve.dir, fotos);

    if (estourou) throw new Error(msgPrazo);
    chrome = await openBrowser('chrome', {logLevel: 'error'});
    // selectComposition roda o calculateMetadata do Root.jsx: e ele que tira
    // a dimensao do `formato` (story 1080x1920 etc).
    const composition = await selectComposition({
      serveUrl: serve.dir,
      id: modelo,
      inputProps: props,
      logLevel: 'error',
      puppeteerInstance: chrome,
    });
    await renderMedia({
      composition,
      serveUrl: serve.dir,
      codec: 'h264',
      outputLocation: out,
      inputProps: props,
      logLevel: 'error',
      puppeteerInstance: chrome,
      cancelSignal,
    });
    // tempos por fase no stderr (o PHP descarta; util pra diagnostico).
    console.warn('render.js: bundle ' + (serve.novo ? 'refeito' : 'reaproveitado') + ' em ' + ((t1 - t0) / 1000).toFixed(1)
      + 's, render em ' + ((Date.now() - t1) / 1000).toFixed(1) + 's');
  } catch (e) {
    console.error(e);
    erro = 'render falhou: ' + (e && e.message ? e.message : 'erro desconhecido');
  }

  clearTimeout(relogio);
  await fecharChrome();
  if (estourou) {
    erro = msgPrazo;
    try { fs.unlinkSync(out); } catch (_) {} // MP4 pela metade nao serve
  }

  if (serve && serve.temporario) {
    try { fs.rmSync(serve.dir, {recursive: true, force: true}); } catch (_) {}
  }
  if (erro) sair({ok: false, erro});

  if (!fs.existsSync(out)) {
    sair({ok: false, erro: 'render terminou mas o arquivo nao existe'});
  }

  sair({ok: true, out});
}

main().catch(async (e) => {
  console.error(e);
  await fecharChrome();
  sair({ok: false, erro: 'render falhou: ' + (e && e.message ? e.message : 'erro desconhecido')});
});
