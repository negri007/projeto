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
 *     "timeout_s": 480,                // opcional; tempo maximo do render
 *   }
 *
 * Imprime UMA linha JSON no stdout: {"ok":true,"out":"..."} ou
 * {"ok":false,"erro":"..."}. O PHP le essa linha. Todo ruido do Remotion
 * vai pro stderr, entao o stdout fica limpo pra ser parseado.
 *
 * TEMPO MAXIMO (padrao 8 min, `timeout_s` no job ou ECHO_RENDER_TIMEOUT_S):
 * passado dele, encerra a ARVORE inteira do render (npx -> node do Remotion
 * -> Chrome headless) e sai com erro. Precisa ser menor que a limpeza de
 * 'gerando' do PHP (10 min), senao a fila dispara o proximo com este ainda
 * ocupando RAM. O arquivo de props fica ao lado do job (<job>.props.json):
 * assim o nome do job aparece na linha de comando do Remotion e o PHP
 * consegue achar e encerrar o que sobrar se ESTE processo morrer antes
 * (ver video_render_encerrar_orfaos em api/video/helpers.php).
 *
 * Sem dependencia de ffmpeg externo: a trilha entra via <Audio> do Remotion
 * (ver src/Root.jsx), entao basta `npm install` + `remotion browser ensure`.
 */

const fs = require('fs');
const path = require('path');
const {spawn, execSync} = require('child_process');

const RAIZ = __dirname; // .../motor
const MODELOS_OK = new Set([
  'Flash', 'Historia', 'Manchete', 'Vitrine', 'Ficha', 'Luxo', 'Glitch',
  'Editorial', 'AntesDepois', 'Depoimento', 'Combo', 'Cupom', 'Countdown',
]);
const FORMATOS_OK = new Set(['quadrado', 'story', 'feed', 'paisagem']);
const TIMEOUT_PADRAO_S = 8 * 60;

function sair(obj) {
  process.stdout.write(JSON.stringify(obj) + '\n');
  process.exit(obj.ok ? 0 : 1);
}

/** Encerra o processo E os filhos (o Chrome do Remotion nao morre sozinho
 *  quando o pai morre). Windows: taskkill /T. Linux/macOS: o filho nasce
 *  em grupo proprio (detached), entao mata o grupo inteiro. */
function matarArvore(filho) {
  if (!filho || filho.exitCode !== null) return;
  try {
    if (process.platform === 'win32') {
      execSync('taskkill /PID ' + filho.pid + ' /T /F', {stdio: 'ignore'});
    } else {
      process.kill(-filho.pid, 'SIGKILL');
    }
  } catch (_) { /* ja tinha saido */ }
}

async function main() {
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

  const timeoutS = Math.max(10, parseInt(job.timeout_s || process.env.ECHO_RENDER_TIMEOUT_S || TIMEOUT_PADRAO_S, 10) || TIMEOUT_PADRAO_S);

  // grava os props num arquivo temporario (evita inferno de aspas no shell).
  // Ao lado do job e com o nome dele: o nome aparece na linha de comando do
  // Remotion, que e como o PHP acha os orfaos se este processo morrer.
  const propsFile = jobPath + '.props.json';
  fs.writeFileSync(propsFile, JSON.stringify(props), 'utf8');

  // usa o CLI do Remotion; formato/dimensao saem do calculateMetadata via prop.
  // shell:true resolve o npx.cmd do Windows, que o spawn sem shell recusa
  // (EINVAL no Node novo). modelo e whitelisted acima e out/propsFile vao
  // entre aspas — nada do cliente cai cru no shell. stdout do Remotion vai
  // pro nosso stderr pra nao sujar a linha JSON que o PHP le no stdout.
  const cmd = 'npx remotion render src/index.js ' + modelo
    + ' "' + out + '" --props="' + propsFile + '" --log=error';

  const resultado = await new Promise(resolve => {
    const filho = spawn(cmd, {
      cwd: RAIZ,
      shell: true,
      stdio: ['ignore', 2, 2],
      detached: process.platform !== 'win32', // grupo proprio = da pra matar tudo
      windowsHide: true,
    });

    const relogio = setTimeout(() => {
      matarArvore(filho);
      resolve({ok: false, erro: 'render passou de ' + timeoutS + 's e foi encerrado'});
    }, timeoutS * 1000);

    // Se ESTE processo receber sinal de parada, leva o render junto.
    for (const sinal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
      process.on(sinal, () => { matarArvore(filho); process.exit(1); });
    }

    filho.on('error', e => { clearTimeout(relogio); resolve({ok: false, erro: 'render falhou: ' + e.message}); });
    filho.on('exit', codigo => {
      clearTimeout(relogio);
      resolve(codigo === 0 ? {ok: true} : {ok: false, erro: 'render falhou (codigo ' + codigo + ')'});
    });
  });

  if (!resultado.ok) {
    try { fs.unlinkSync(propsFile); } catch (_) {}
    try { if (fs.existsSync(out)) fs.unlinkSync(out); } catch (_) {} // MP4 pela metade nao serve
    sair(resultado);
  }

  try { fs.unlinkSync(propsFile); } catch (_) {}

  if (!fs.existsSync(out)) {
    sair({ok: false, erro: 'render terminou mas o arquivo nao existe'});
  }

  sair({ok: true, out});
}

main().catch(e => sair({ok: false, erro: 'render falhou: ' + (e && e.message || e)}));
