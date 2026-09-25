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
 * copia pra public/uploads a cada peca NAO invalidam o cache — sao copiadas
 * direto pra .bundle/public/uploads (ver sincronizarUploads).
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
  await bundle({
    entryPoint: path.join(SRC, 'index.js'),
    outDir: tmp,
    enableCaching: true,
  });
  fs.writeFileSync(path.join(tmp, '.carimbo'), new Date().toISOString());

  try {
    fs.rmSync(BUNDLE, {recursive: true, force: true});
    fs.renameSync(tmp, BUNDLE);
    return {dir: BUNDLE, temporario: false, novo: true};
  } catch (e) {
    // .bundle em uso por outro render (Windows trava arquivo aberto):
    // renderiza deste temporario e apaga no fim; o proximo render refaz.
    console.warn('render.js: nao troquei .bundle (' + e.code + '), usando ' + tmp);
    return {dir: tmp, temporario: true, novo: true};
  }
}

// Fotos que o PHP gravou em public/uploads depois do bundle: copia as que
// faltam (ou mudaram) pra dentro do bundle, onde o staticFile() as procura.
function sincronizarUploads(dir) {
  let nomes;
  try { nomes = fs.readdirSync(UPLOADS); } catch (_) { return; }
  const destDir = path.join(dir, 'public', 'uploads');
  fs.mkdirSync(destDir, {recursive: true});
  for (const nome of nomes) {
    const de = path.join(UPLOADS, nome);
    const para = path.join(destDir, nome);
    let sDe;
    try { sDe = fs.statSync(de); } catch (_) { continue; }
    if (!sDe.isFile()) continue;
    try {
      const sPara = fs.statSync(para);
      if (sPara.size === sDe.size && sPara.mtimeMs >= sDe.mtimeMs) continue;
    } catch (_) { /* nao existe: copia */ }
    fs.copyFileSync(de, para);
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

  let serve;
  let erro = null;
  try {
    const t0 = Date.now();
    serve = await garantirBundle();
    const t1 = Date.now();
    sincronizarUploads(serve.dir);

    const {selectComposition, renderMedia} = require('@remotion/renderer');
    // selectComposition roda o calculateMetadata do Root.jsx: e ele que tira
    // a dimensao do `formato` (story 1080x1920 etc).
    const composition = await selectComposition({
      serveUrl: serve.dir,
      id: modelo,
      inputProps: props,
      logLevel: 'error',
    });
    await renderMedia({
      composition,
      serveUrl: serve.dir,
      codec: 'h264',
      outputLocation: out,
      inputProps: props,
      logLevel: 'error',
    });
    // tempos por fase no stderr (o PHP descarta; util pra diagnostico).
    console.warn('render.js: bundle ' + (serve.novo ? 'refeito' : 'reaproveitado') + ' em ' + ((t1 - t0) / 1000).toFixed(1)
      + 's, render em ' + ((Date.now() - t1) / 1000).toFixed(1) + 's');
  } catch (e) {
    console.error(e);
    erro = 'render falhou: ' + (e && e.message ? e.message : 'erro desconhecido');
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

main().catch((e) => {
  console.error(e);
  sair({ok: false, erro: 'render falhou: ' + (e && e.message ? e.message : 'erro desconhecido')});
});
