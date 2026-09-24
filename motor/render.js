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
 * Sem dependencia de ffmpeg externo: a trilha entra via <Audio> do Remotion
 * (ver src/Root.jsx), entao basta `npm install` + `remotion browser ensure`.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const {execSync} = require('child_process');

const RAIZ = __dirname; // .../motor
const MODELOS_OK = new Set([
  'Flash', 'Historia', 'Manchete', 'Vitrine', 'Ficha', 'Luxo', 'Glitch',
  'Editorial', 'AntesDepois', 'Depoimento', 'Combo', 'Cupom', 'Countdown',
]);
const FORMATOS_OK = new Set(['quadrado', 'story', 'feed', 'paisagem']);

function sair(obj) {
  process.stdout.write(JSON.stringify(obj) + '\n');
  process.exit(obj.ok ? 0 : 1);
}

function main() {
  const jobPath = process.argv[2];
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

  // grava os props num arquivo temporario (evita inferno de aspas no shell).
  const propsFile = path.join(os.tmpdir(), 'echo_motor_' + Date.now() + '_' + Math.random().toString(36).slice(2) + '.json');
  fs.writeFileSync(propsFile, JSON.stringify(props), 'utf8');

  try {
    // usa o CLI do Remotion; formato/dimensao saem do calculateMetadata via prop.
    // execSync (roda via shell) resolve o npx.cmd do Windows, que o
    // execFile recusa (EINVAL no Node novo). modelo e whitelisted acima e
    // out/propsFile vao entre aspas — nada do cliente cai cru no shell.
    // fd1 (stdout do Remotion) vai pro nosso fd2 (stderr) pra nao sujar a
    // linha JSON que o PHP le no nosso stdout.
    const cmd = 'npx remotion render src/index.js ' + modelo
      + ' "' + out + '" --props="' + propsFile + '" --log=error';
    execSync(cmd, {cwd: RAIZ, stdio: ['ignore', 2, 2], maxBuffer: 64 * 1024 * 1024});
  } catch (e) {
    try { fs.unlinkSync(propsFile); } catch (_) {}
    sair({ok: false, erro: 'render falhou: ' + (e.message || 'erro desconhecido')});
  }

  try { fs.unlinkSync(propsFile); } catch (_) {}

  if (!fs.existsSync(out)) {
    sair({ok: false, erro: 'render terminou mas o arquivo nao existe'});
  }

  sair({ok: true, out});
}

main();
