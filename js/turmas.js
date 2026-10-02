const $ = s => document.querySelector(s);
let turmaSel = null, ehDono = false;

function msg(txt, tipo='info') {
  const el = $('#msg');
  el.textContent = txt; el.className = 'msg ' + tipo;
  if (tipo === 'info') setTimeout(() => { el.className = 'msg'; }, 4000);
}

// Fecha todos os formulários "pop" e apaga o estado ativo dos botões ícone-＋.
function fecharForms() {
  document.querySelectorAll('.form-pop').forEach(f => f.classList.add('hidden'));
  document.querySelectorAll('.icon-add').forEach(b => b.classList.remove('ativo'));
}

// Abre um form pop (fechando os outros) e foca o primeiro campo. Reclicar fecha.
function abrirForm(btnSel, formSel) {
  const f = $(formSel), b = $(btnSel);
  const vaiAbrir = f.classList.contains('hidden');
  fecharForms();
  if (vaiAbrir) {
    f.classList.remove('hidden');
    b.classList.add('ativo');
    const campo = f.querySelector('input, select, textarea');
    if (campo) campo.focus();
  }
}

// Número da faixa com contagem animada (0..valor). Instantâneo se a pessoa
// pediu menos movimento.
function setStat(id, val) {
  const e = document.getElementById(id);
  if (!e) return;
  const alvo = Number(val) || 0;
  const menos = (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) || document.hidden;
  const ini = Number(e.textContent) || 0;
  if (menos || ini === alvo) { e.textContent = alvo; return; }
  const t0 = performance.now(), dur = 500;
  const passo = now => {
    const p = Math.min(1, (now - t0) / dur);
    e.textContent = Math.round(ini + (alvo - ini) * (1 - Math.pow(1 - p, 3)));
    if (p < 1) requestAnimationFrame(passo);
  };
  requestAnimationFrame(passo);
  // Garante o valor final mesmo se o rAF for pausado (aba em segundo plano).
  setTimeout(() => { e.textContent = alvo; }, dur + 80);
}

async function api(url, opts) {
  const r = await fetch(url, opts);
  const j = await r.json().catch(() => ({ error: 'Resposta inválida.' }));
  if (r.status === 401) { msg('Faça login no Echo primeiro (abra index.html e entre).', 'err'); throw new Error('401'); }
  return j;
}

async function carregarTurmas() {
  const j = await api('/api/circles/list.php');
  const box = $('#turmas');
  const turmas = (j.circles || []).filter(c => c.tipo === 'academia');
  if (!turmas.length) { box.innerHTML = '<span class="spin">nenhuma turma ainda — crie uma abaixo.</span>'; return; }
  box.innerHTML = '';
  // Papel é por turma: separa "que você dá" (dono) de "que você assiste".
  const dou = turmas.filter(c => c.is_owner);
  const assisto = turmas.filter(c => !c.is_owner);
  const grupos = [];
  if (dou.length) grupos.push(['Turmas que você dá', 'fa-chalkboard-user', dou]);
  if (assisto.length) grupos.push(['Turmas que você assiste', 'fa-user-graduate', assisto]);
  // Só rotula os grupos quando há os dois papéis; com um só, o título do
  // bloco ("Minhas turmas") já basta — evita a repetição.
  const comTitulo = grupos.length > 1;
  grupos.forEach(([t, ic, l]) => box.appendChild(grupoTurmas(comTitulo ? t : null, ic, l)));
}

function grupoTurmas(titulo, icone, lista) {
  const g = document.createElement('div');
  g.className = 'turma-grupo';
  const tit = titulo ? `<div class="turma-grupo-tit"><i class="fa-solid ${icone}"></i>${esc(titulo)}</div>` : '';
  g.innerHTML = `${tit}<div class="turma-grid"></div>`;
  const grid = g.querySelector('.turma-grid');
  lista.forEach(c => {
    const d = document.createElement('div');
    d.className = 'turma-card' + (turmaSel === c.id ? ' sel' : '');
    d.innerHTML = `<div class="tc-ic"><i class="fa-solid fa-graduation-cap"></i></div>
      <div class="tc-body"><div class="tc-name">${esc(c.name)}</div><div class="tc-meta">${c.member_count} aluno(s)</div></div>
      <span class="tag">${c.is_owner ? 'professor' : 'aluno'}</span>`;
    d.onclick = () => abrirTurma(c);
    grid.appendChild(d);
  });
  return g;
}

async function criarTurma() {
  const name = $('#novaTurma').value.trim();
  if (!name) return;
  $('#btnCriarTurma').disabled = true;
  const j = await api('/api/circles/create.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, tipo: 'academia' })
  });
  $('#btnCriarTurma').disabled = false;
  if (j.error) return msg(j.error, 'err');
  $('#novaTurma').value = '';
  msg('Turma criada.');
  await carregarTurmas();
  abrirTurma(j.circle);
}

async function abrirTurma(c) {
  turmaSel = c.id; ehDono = !!c.is_owner;
  $('#painel').classList.remove('hidden');
  document.querySelectorAll('.turmaNome').forEach(el => { el.textContent = c.name; });
  // Formulários abrem só ao clicar no botão ícone-＋ (menos poluição). O
  // professor vê os botões; os forms começam fechados a cada turma aberta.
  $('#btnAbrirSubir').classList.toggle('hidden', !ehDono);
  $('#btnAbrirAddAluno').classList.toggle('hidden', !ehDono);
  fecharForms();
  $('#riscoBox').classList.toggle('hidden', !ehDono);
  // Faixa de números: professor vê a da turma; aluno vê o próprio progresso.
  $('#turmaStats').classList.toggle('hidden', !ehDono);
  $('#alunoStats').classList.toggle('hidden', ehDono);
  $('#conviteBox').classList.toggle('hidden', !ehDono);
  if (!ehDono) $('#risco').innerHTML = '';
  carregarTurmas();
  carregarAlunos();
  if (ehDono) { carregarAmigos(); carregarRisco(); carregarConvite(); }
  else carregarAlunoPainel();
  await carregarMateriais();
}

// Convite por código (só o professor). Ver/gerar/copiar.
async function carregarConvite() {
  const j = await api('/api/turmas/convite_ver.php?circle_id=' + turmaSel);
  if (j.error) return;
  $('#conviteCodigo').textContent = j.codigo || '— gere um código';
  $('#chkAprovacao').checked = !!j.aceita_pedidos;
  carregarPedidos();
}

// Liga/desliga "exigir aprovação".
async function salvarAprovacao() {
  const aceita = $('#chkAprovacao').checked;
  const j = await api('/api/turmas/entrada_config.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ circle_id: turmaSel, aceita_pedidos: aceita })
  });
  if (j.error) { $('#chkAprovacao').checked = !aceita; return msg(j.error, 'err'); }
  msg(aceita ? 'Agora o código exige sua aprovação.' : 'Agora o código entra direto.');
  carregarPedidos();
}

// Pedidos pendentes (só o professor).
async function carregarPedidos() {
  const box = $('#pedidos');
  if (!ehDono) { box.innerHTML = ''; return; }
  const j = await api('/api/turmas/pedidos_listar.php?circle_id=' + turmaSel);
  if (j.error) { box.innerHTML = ''; return; }
  if (!j.pedidos.length) { box.innerHTML = ''; return; }
  box.innerHTML = '';
  j.pedidos.forEach(p => {
    const d = document.createElement('div');
    d.className = 'pedido';
    d.innerHTML = `<div><b>${esc(p.name)}</b><span> quer entrar</span></div>`;
    const acoes = document.createElement('div'); acoes.className = 'row';
    const ok = document.createElement('button'); ok.className = 'ok'; ok.textContent = 'Aprovar';
    ok.onclick = () => decidirPedido(p.id, true);
    const no = document.createElement('button'); no.className = 'ghost'; no.textContent = 'Recusar';
    no.onclick = () => decidirPedido(p.id, false);
    acoes.appendChild(ok); acoes.appendChild(no);
    d.appendChild(acoes);
    box.appendChild(d);
  });
}

async function decidirPedido(id, aprovar) {
  const j = await api('/api/turmas/pedido_decidir.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ request_id: id, aprovar })
  });
  if (j.error) return msg(j.error, 'err');
  msg(aprovar ? 'Aluno aprovado.' : 'Pedido recusado.');
  carregarPedidos();
  if (aprovar) { carregarAlunos(); carregarTurmas(); carregarRisco(); }
}
async function gerarCodigo() {
  const b = $('#btnGerarCodigo'); b.disabled = true;
  const j = await api('/api/turmas/convite_gerar.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ circle_id: turmaSel })
  });
  b.disabled = false;
  if (j.error) return msg(j.error, 'err');
  $('#conviteCodigo').textContent = j.codigo;
  msg('Código gerado. Compartilhe com os alunos.');
}
function copiarCodigo() {
  const c = $('#conviteCodigo').textContent.trim();
  if (!c || c.startsWith('—')) return;
  navigator.clipboard?.writeText(c).then(() => msg('Código copiado.')).catch(() => {});
}

// Aluno entra por código (forma B).
async function entrarPorCodigo() {
  const cod = $('#codigoEntrar').value.trim();
  if (!cod) return;
  const b = $('#btnEntrarCodigo'); b.disabled = true;
  const j = await api('/api/turmas/entrar_por_codigo.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ codigo: cod })
  });
  b.disabled = false;
  if (j.error) return msg(j.error, 'err');
  $('#codigoEntrar').value = '';

  // Turma que exige aprovação: vira pedido, não entra ainda.
  if (j.estado === 'pendente') {
    msg('Pedido enviado! Aguarde o professor aprovar.');
    fecharForms();
    return;
  }

  msg(j.estado === 'ja_membro' ? 'Você já está nessa turma.' : 'Você entrou na turma!');
  await carregarTurmas();
  abrirTurma({ id: j.circle.id, name: j.circle.name, is_owner: false });
}

// Progresso do próprio aluno (só quando ele NÃO é dono). Silencioso em erro:
// a lista de materiais/quizzes continua aparecendo de qualquer forma.
async function carregarAlunoPainel() {
  const j = await api('/api/turmas/aluno_painel.php?circle_id=' + turmaSel);
  if (j.error || !j.progresso) return;
  const p = j.progresso;
  setStat('stConcluido', p.pct_concluido); // o "%" é fixo no HTML, fora do número
  setStat('stPendentes', p.pendentes);
  setStat('stNovos', p.materiais_novos);
}

// Alerta de aluno em risco (so o professor; o PHP recusa aluno). Todo
// texto — nome, titulo do material, assunto vindo da IA — passa por esc().
async function carregarRisco() {
  const box = $('#risco');
  box.innerHTML = '<span class="spin">calculando…</span>';
  const j = await api('/api/turmas/alunos_risco.php?circle_id=' + turmaSel);
  if (j.error) { box.innerHTML = ''; return msg(j.error, 'err'); }
  setStat('stRisco', j.em_risco);
  const cls = j.em_risco > 0 ? 'alto' : 'ok';
  const assuntos = j.assuntos.map(a =>
    `<div class="rs-assunto">${a.alunos_em_risco} aluno(s) em risco em ${esc(a.assunto)} (de ${a.responderam} que responderam)</div>`).join('');
  const alunos = j.alunos.map(a =>
    `<div class="rs-aluno"><b>${esc(a.name)}</b><ul>${a.motivos.map(m => `<li>${esc(m.texto)}</li>`).join('')}</ul></div>`).join('');
  box.innerHTML = `<div class="rs-num ${cls}">${j.em_risco} de ${j.total_alunos} aluno(s) em risco</div>
    <div class="spin">Critério: menos de ${j.criterios.pct}% num quiz, quiz sem resposta ou material não aberto há mais de ${j.criterios.dias} dias.</div>
    ${assuntos}${alunos}`;
}

// Turma nao aparece em circulos.html, entao os alunos se gerenciam aqui.
// Mesmos endpoints de circulo: aluno = circle_members, por user_id.
async function carregarAlunos() {
  const box = $('#alunos');
  const j = await api('/api/circles/list_members.php?circle_id=' + turmaSel);
  if (j.error) { box.innerHTML = ''; return msg(j.error, 'err'); }
  const alunos = j.members || [];
  setStat('stAlunos', alunos.length);
  if (!alunos.length) { box.innerHTML = '<span class="spin">nenhum aluno ainda.</span>'; return; }
  box.innerHTML = '';
  alunos.forEach(u => {
    const d = document.createElement('div');
    d.className = 'turma';
    d.style.cursor = 'default';
    d.innerHTML = `<span>${esc(u.name)}</span>`;
    if (ehDono) {
      const b = document.createElement('button');
      b.className = 'ghost'; b.style.marginTop = '0'; b.textContent = 'Remover';
      b.onclick = () => removerAluno(u.user_id);
      d.appendChild(b);
    }
    box.appendChild(d);
  });
}

async function carregarAmigos() {
  const sel = $('#amigoSel');
  const j = await api('/api/friends/list.php');
  const amigos = j.friends || [];
  sel.innerHTML = amigos.length
    ? amigos.map(f => `<option value="${f.user_id}">${esc(f.name)}</option>`).join('')
    : '<option value="">nenhum amigo para adicionar</option>';
}

async function addAluno() {
  const userId = Number($('#amigoSel').value);
  if (!userId) return;
  $('#btnAddAluno').disabled = true;
  const j = await api('/api/circles/add_member.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ circle_id: turmaSel, user_id: userId })
  });
  $('#btnAddAluno').disabled = false;
  if (j.error) return msg(j.error, 'err');
  msg('Aluno adicionado.');
  fecharForms();
  carregarAlunos();
  carregarTurmas();
  carregarRisco();
}

async function removerAluno(userId) {
  const j = await api('/api/circles/remove_member.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ circle_id: turmaSel, user_id: userId })
  });
  if (j.error) return msg(j.error, 'err');
  carregarAlunos();
  carregarTurmas();
}

async function carregarMateriais() {
  const box = $('#materiais');
  box.innerHTML = '<span class="spin">carregando materiais…</span>';
  const j = await api('/api/turmas/material_listar.php?circle_id=' + turmaSel);
  if (j.error) { box.innerHTML = ''; return msg(j.error, 'err'); }
  setStat('stMateriais', (j.materiais || []).length);
  if (!j.materiais.length) { box.innerHTML = '<span class="spin">nenhum material ainda.</span>'; return; }
  box.innerHTML = '';
  j.materiais.forEach(m => box.appendChild(cardMaterial(m)));
}

function cardMaterial(m) {
  const d = document.createElement('div');
  d.className = 'mat';
  const tipo = m.tipo_arquivo ? m.tipo_arquivo.toUpperCase() : (m.tem_texto ? 'TEXTO' : '—');
  d.innerHTML = `<div class="t">${esc(m.titulo)}</div><div class="meta">${tipo}${m.tem_resumo ? ' · resumo pronto' : ''}</div>`;
  const acoes = document.createElement('div'); acoes.className = 'row';
  const ab = document.createElement('button');
  ab.className = 'ghost'; ab.textContent = 'Abrir';
  ab.onclick = () => abrirMaterial(m, d);
  acoes.appendChild(ab);
  if (m.resumivel) {
    const b = document.createElement('button');
    b.className = 'ok'; b.textContent = m.tem_resumo ? 'Ver resumo' : 'Resumir com IA';
    b.onclick = () => resumir(m, d, b, false);
    acoes.appendChild(b);
    if (ehDono && m.tem_resumo) {
      const r = document.createElement('button');
      r.className = 'ghost'; r.textContent = 'Gerar de novo';
      r.onclick = () => resumir(m, d, r, true);
      acoes.appendChild(r);
    }
    const q = document.createElement('button');
    q.className = 'ghost'; q.textContent = 'Quiz';
    q.onclick = () => abrirQuiz(m, d);
    acoes.appendChild(q);
  } else {
    const s = document.createElement('span'); s.className = 'spin'; s.textContent = 'não resumível (imagem)';
    acoes.appendChild(s);
  }
  // Reportar: só o aluno (quem vê a turma sem ser o dono é membro dela).
  if (!ehDono) {
    const rep = document.createElement('button');
    rep.className = 'ghost mat-reportar';
    rep.title = 'Reportar material fora do tema da turma';
    if (m.ja_reportei) marcarReportado(rep);
    else {
      rep.innerHTML = '<i class="fa-regular fa-flag"></i> Reportar';
      rep.onclick = () => abrirReporte(m, rep);
    }
    acoes.appendChild(rep);
  }
  d.appendChild(acoes);
  return d;
}

/* ---------------------------------------------------------------- reporte
   O aluno reporta um material fora do tema da turma. Vai para a
   administração do Echo (api/turmas/material_reportar.php); não tem IA. */

const REPORTE_MOTIVO_MAX = 500;

function marcarReportado(btn) {
  btn.disabled = true;
  btn.onclick = null;
  btn.innerHTML = '<i class="fa-solid fa-flag"></i> Reportado';
  btn.title = 'Você já reportou este material';
}

function abrirReporte(m, btn) {
  const fundo = document.createElement('div');
  fundo.className = 'echo-dialog-backdrop';
  fundo.innerHTML = `
    <div class="echo-dialog reporte-dialog" role="dialog" aria-modal="true" aria-labelledby="reporteTitulo">
      <h5 id="reporteTitulo">Reportar material</h5>
      <p class="reporte-alvo"></p>
      <p>O reporte vai para a administração do Echo. Use quando o conteúdo estiver fora do tema da turma.</p>
      <label class="reporte-label" for="reporteMotivo">Motivo (opcional)</label>
      <textarea id="reporteMotivo" rows="4" maxlength="${REPORTE_MOTIVO_MAX}"
                placeholder="Ex.: o material é de outra disciplina."></textarea>
      <small class="reporte-contador">0 / ${REPORTE_MOTIVO_MAX}</small>
      <div class="echo-dialog-actions">
        <button type="button" class="echo-dialog-cancel">Cancelar</button>
        <button type="button" class="echo-dialog-confirm danger">Enviar reporte</button>
      </div>
    </div>`;
  // Título vindo do professor: textContent, nunca HTML cru.
  fundo.querySelector('.reporte-alvo').textContent = '“' + m.titulo + '”';

  const campo = fundo.querySelector('textarea');
  const conta = fundo.querySelector('.reporte-contador');
  const enviar = fundo.querySelector('.echo-dialog-confirm');

  const fechar = () => { document.removeEventListener('keydown', aoTeclar); fundo.remove(); btn.focus(); };
  const aoTeclar = (e) => { if (e.key === 'Escape') fechar(); };

  campo.addEventListener('input', () => { conta.textContent = campo.value.length + ' / ' + REPORTE_MOTIVO_MAX; });
  fundo.querySelector('.echo-dialog-cancel').onclick = fechar;
  fundo.onclick = (e) => { if (e.target === fundo) fechar(); };
  enviar.onclick = async () => {
    enviar.disabled = true;
    const r = await enviarReporte(m.id, campo.value.trim());
    enviar.disabled = false;
    if (r.ok || r.status === 409) {
      marcarReportado(btn);
      fechar();
    }
    if (r.ok) EchoUIInstance.toastSuccess('Reporte enviado. Obrigado por avisar.');
    else EchoUIInstance.toastError(r.status === 409 ? 'Você já reportou este material.' : r.error);
  };

  document.addEventListener('keydown', aoTeclar);
  document.body.appendChild(fundo);
  campo.focus();
}

// fetch próprio (e não api()): o 409 precisa do status HTTP, não só do texto.
async function enviarReporte(materialId, motivo) {
  try {
    const r = await fetch('/api/turmas/material_reportar.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ material_id: materialId, motivo })
    });
    const j = await r.json().catch(() => ({}));
    return { ok: r.ok && !!j.ok, status: r.status, error: j.error || 'Não deu para enviar o reporte. Tente de novo.' };
  } catch (e) {
    return { ok: false, status: 0, error: 'Sem conexão. Tente de novo.' };
  }
}

// Abre o material (texto na tela ou link do arquivo). No PHP, a abertura do
// aluno fica registrada para o alerta de risco.
async function abrirMaterial(m, card) {
  const j = await api('/api/turmas/material_abrir.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ material_id: m.id })
  });
  if (j.error) return msg(j.error, 'err');
  let box = card.querySelector('.mat-texto');
  if (!box) { box = document.createElement('div'); box.className = 'mat-texto'; card.appendChild(box); }
  const mat = j.material;
  box.innerHTML = (mat.conteudo_texto ? esc(mat.conteudo_texto) : '')
    + (mat.arquivo_url ? `${mat.conteudo_texto ? '\n\n' : ''}<a href="${esc(mat.arquivo_url)}" target="_blank" rel="noopener">Abrir arquivo (${esc((mat.tipo_arquivo || '').toUpperCase())})</a>` : '');
}

async function resumir(m, card, btn, regerar) {
  btn.disabled = true; const orig = btn.textContent; btn.textContent = 'gerando…';
  const j = await api('/api/turmas/material_resumir.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ material_id: m.id, regerar })
  });
  btn.disabled = false; btn.textContent = orig;
  if (j.error) return msg(j.error, 'err');
  // Resumo novo: redesenha so este card (vira "resumo pronto" + "Gerar de
  // novo") em vez de recarregar a lista, que apagaria o texto recem-gerado.
  if (!j.do_cache) {
    m.tem_resumo = true;
    const novo = cardMaterial(m);
    card.replaceWith(novo);
    card = novo;
  }
  let box = card.querySelector('.resumo');
  if (!box) { box = document.createElement('div'); box.className = 'resumo'; card.appendChild(box); }
  box.innerHTML = markdownSeguro(j.resumo);
}

// Subconjunto minimo de Markdown (## titulo, - lista, **negrito**). O texto
// vem da IA a partir do material, entao TUDO e escapado primeiro e so depois
// ganha as poucas tags abaixo — nenhum HTML do resumo chega cru ao innerHTML.
function markdownSeguro(md) {
  const inline = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  const out = [];
  let lista = false;
  const fechaLista = () => { if (lista) { out.push('</ul>'); lista = false; } };
  String(md).split(/\r?\n/).forEach(linha => {
    const t = linha.trim();
    let r;
    if (!t) { fechaLista(); return; }
    if ((r = t.match(/^#{1,6}\s+(.*)$/))) { fechaLista(); out.push('<h3>' + inline(r[1]) + '</h3>'); return; }
    if ((r = t.match(/^[-*•]\s+(.*)$/))) { if (!lista) { out.push('<ul>'); lista = true; } out.push('<li>' + inline(r[1]) + '</li>'); return; }
    fechaLista();
    out.push('<p>' + inline(t) + '</p>');
  });
  fechaLista();
  return out.join('');
}

/* ---------------------------------------------------------------------
   Quiz por conteudo. Todo texto do quiz (enunciado, alternativas, assunto,
   fonte) vem da IA, entao passa SEMPRE por esc() antes de entrar no HTML.
   O gabarito so chega aqui para o professor ou para o aluno que ja
   respondeu — quem decide e o PHP (quiz_ver.php), nao esta tela.
   --------------------------------------------------------------------- */

const LETRAS = ['A', 'B', 'C', 'D'];

function caixaQuiz(card) {
  let box = card.querySelector('.quiz');
  if (!box) { box = document.createElement('div'); box.className = 'quiz'; card.appendChild(box); }
  return box;
}

async function abrirQuiz(m, card) {
  const box = caixaQuiz(card);
  box.innerHTML = '<span class="spin">carregando quiz…</span>';
  const j = await api('/api/turmas/quiz_ver.php?material_id=' + m.id);
  if (j.error) { box.innerHTML = ''; return msg(j.error, 'err'); }
  desenharQuiz(m, card, j.quiz, j.is_owner);
}

function desenharQuiz(m, card, quiz, professor) {
  const box = caixaQuiz(card);
  box.innerHTML = '';

  if (!quiz) {
    if (!professor) { box.innerHTML = '<span class="spin">O professor ainda não gerou o quiz deste material.</span>'; return; }
    box.innerHTML = '<p class="spin">Ainda não há quiz. A geração usa a IA (Sonnet) uma vez; depois todos os alunos respondem o mesmo quiz.</p>';
    const b = document.createElement('button');
    b.textContent = 'Gerar quiz';
    b.onclick = () => gerarQuiz(m, card, b, false);
    box.appendChild(b);
    return;
  }

  if (professor) return desenharQuizProfessor(m, card, quiz);
  if (quiz.respondido) return desenharResultado(box, quiz);
  desenharFormulario(m, card, quiz);
}

async function gerarQuiz(m, card, btn, regerar) {
  btn.disabled = true; const orig = btn.textContent; btn.textContent = 'gerando… (pode levar até 1 min)';
  const j = await api('/api/turmas/quiz_gerar.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ material_id: m.id, regerar })
  });
  btn.disabled = false; btn.textContent = orig;
  if (j.error) return msg(j.error, 'err');
  if (!j.do_cache) msg('Quiz gerado.');
  desenharQuiz(m, card, j.quiz, true);
}

// Enunciado + alternativas; `marcar(i)` devolve a classe de cada uma.
function htmlQuestao(q, marcar) {
  const alts = q.alternativas.map((a, i) =>
    `<li class="${marcar ? marcar(i) : ''}"><b>${LETRAS[i]})</b> ${esc(a)}</li>`).join('');
  return `<div class="qz-enun">${q.ordem}. ${esc(q.enunciado)}</div><ul class="qz-alts">${alts}</ul>`;
}

function htmlFonte(q) {
  const pag = q.pagina ? ` (p. ${esc(q.pagina)})` : '';
  return `<div class="qz-fonte">Fonte${pag}: “${esc(q.trecho_fonte)}” · <i>${esc(q.assunto)}</i></div>`;
}

function desenharQuizProfessor(m, card, quiz) {
  const box = caixaQuiz(card);
  const qs = quiz.questoes.map(q =>
    `<div class="qz-q">${htmlQuestao(q, i => i === q.correta ? 'certa' : '')}${htmlFonte(q)}</div>`).join('');
  box.innerHTML = `<div class="qz-cab">Quiz · ${quiz.total} questões · gabarito visível só para você</div>${qs}<div class="qz-painel"><span class="spin">carregando painel…</span></div>`;

  // Regerar zera o painel (o quiz antigo fica inativo): pede um 2º clique,
  // sem confirm() do navegador.
  const r = document.createElement('button');
  r.className = 'ghost'; r.textContent = 'Gerar quiz de novo';
  r.onclick = () => {
    if (r.dataset.armado) return gerarQuiz(m, card, r, true);
    r.dataset.armado = '1';
    r.textContent = 'Confirmar: o painel recomeça do zero';
  };
  box.appendChild(r);
  carregarPainel(m, box.querySelector('.qz-painel'));
}

async function carregarPainel(m, el) {
  const j = await api('/api/turmas/quiz_painel.php?material_id=' + m.id);
  if (j.error || !j.quiz) { el.innerHTML = ''; if (j.error) msg(j.error, 'err'); return; }
  const p = j.quiz;
  if (!p.responderam) {
    el.innerHTML = `<b>Painel</b><div class="spin">0 de ${p.total_alunos} aluno(s) responderam ainda.</div>`;
    return;
  }
  const destaque = p.pior_questao && p.pior_questao.pct_erro > 0
    ? `<div class="qz-destaque">${p.pior_questao.pct_erro}% errou a questão ${p.pior_questao.ordem} (${esc(p.pior_questao.assunto)})</div>` : '';
  const porQ = p.questoes.map(q =>
    `<li>Questão ${q.ordem} · ${esc(q.assunto)}: <b>${q.pct_acerto ?? '—'}%</b> de acerto (${q.acertos}/${q.respostas})</li>`).join('');
  const porA = p.assuntos.map(a =>
    `<li>${esc(a.assunto)}: <b>${a.pct_acerto ?? '—'}%</b> de acerto</li>`).join('');
  el.innerHTML = `<b>Painel</b>
    <div class="spin">${p.responderam} de ${p.total_alunos} aluno(s) responderam · média ${p.media_pct ?? '—'}%</div>
    ${destaque}
    <div class="qz-sub">Por questão</div><ul>${porQ}</ul>
    <div class="qz-sub">Por assunto</div><ul>${porA}</ul>`;
}

function desenharFormulario(m, card, quiz) {
  const box = caixaQuiz(card);
  const form = document.createElement('form');
  form.innerHTML = quiz.questoes.map(q => {
    const opts = q.alternativas.map((a, i) =>
      `<label class="qz-opt"><input type="radio" name="q${q.id}" value="${i}"> <b>${LETRAS[i]})</b> ${esc(a)}</label>`).join('');
    return `<div class="qz-q"><div class="qz-enun">${q.ordem}. ${esc(q.enunciado)}</div>${opts}</div>`;
  }).join('');
  const b = document.createElement('button');
  b.type = 'submit'; b.textContent = 'Enviar respostas';
  form.appendChild(b);
  form.onsubmit = async ev => {
    ev.preventDefault();
    const respostas = {};
    for (const q of quiz.questoes) {
      const marcada = form.querySelector(`input[name="q${q.id}"]:checked`);
      if (!marcada) return msg('Responda todas as questões.', 'err');
      respostas[q.id] = Number(marcada.value);
    }
    b.disabled = true;
    const j = await api('/api/turmas/quiz_responder.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ quiz_id: quiz.id, respostas })
    });
    b.disabled = false;
    if (j.error) return msg(j.error, 'err');
    desenharResultado(box, j.quiz);
  };
  box.innerHTML = '<div class="qz-cab">Quiz · responda e envie (uma tentativa)</div>';
  box.appendChild(form);
}

function desenharResultado(box, quiz) {
  const qs = quiz.questoes.map(q => {
    const marcar = i => i === q.correta ? 'certa' : (i === q.escolhida ? 'errada' : '');
    return `<div class="qz-q">${htmlQuestao(q, marcar)}<div class="qz-res ${q.acertou ? 'ok' : 'no'}">${q.acertou ? 'Acertou' : 'Errou'}</div>${htmlFonte(q)}</div>`;
  }).join('');
  box.innerHTML = `<div class="qz-cab">Seu resultado: <b>${quiz.acertos} de ${quiz.total}</b></div>${qs}`;
}

async function subir() {
  const titulo = $('#matTitulo').value.trim();
  const texto = $('#matTexto').value.trim();
  const file = $('#matArquivo').files[0];
  if (!titulo) return msg('Dê um nome ao material.', 'err');
  if (!texto && !file) return msg('Cole o texto ou envie um arquivo.', 'err');
  const fd = new FormData();
  fd.append('circle_id', turmaSel);
  fd.append('titulo', titulo);
  if (texto) fd.append('conteudo_texto', texto);
  if (file) fd.append('arquivo', file);
  $('#btnSubir').disabled = true;
  const j = await api('/api/turmas/material_criar.php', { method: 'POST', body: fd });
  $('#btnSubir').disabled = false;
  if (j.error) return msg(j.error, 'err');
  $('#matTitulo').value = ''; $('#matTexto').value = ''; $('#matArquivo').value = '';
  msg('Material adicionado.');
  fecharForms();
  carregarMateriais();
}

function esc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

// ---- Professor verificado (Fase 3): card na coluna direita ----
async function initProfessor() {
  const box = $('#profCardBody');
  if (!box) return;
  // Só status.php (libera a sessão cedo: sem corrida de lock no php -S).
  const st = await api('/api/professor/status.php');
  if (st.error) { box.innerHTML = ''; return; }

  const status = st.status || 'nenhum';
  const admin = !!st.is_admin;
  const linkAdmin = admin
    ? '<a href="admin_professores.html" class="prof-adminlink"><i class="fa-solid fa-shield-halved"></i> Painel de admin</a>'
    : '';

  if (status === 'verificado') {
    box.innerHTML = `<div class="prof-selo"><i class="fa-solid fa-circle-check"></i> Professor verificado</div>
      <p class="text-secondary small mb-0" style="margin-top:8px">Suas turmas mostram o selo de professor verificado.</p>${linkAdmin}`;
    return;
  }
  if (status === 'pendente') {
    box.innerHTML = `<p class="text-secondary small mb-0"><i class="fa-solid fa-hourglass-half"></i> Solicitação em análise. Avisamos quando o admin decidir.</p>${linkAdmin}`;
    return;
  }

  const recusado = status === 'recusado'
    ? '<p class="small" style="color:#ffb1bc;margin:0 0 8px">Seu pedido anterior foi recusado. Você pode pedir de novo.</p>' : '';
  box.innerHTML = `${recusado}
    <p class="text-secondary small" style="margin:0 0 8px">Qualquer um cria grupo de estudo. O selo de <b>professor verificado</b> passa por aprovação.</p>
    <input id="profArea" class="form-control form-control-sm mb-2" placeholder="Área (ex.: Biologia)" maxlength="80">
    <textarea id="profJust" class="form-control form-control-sm mb-2" rows="3" placeholder="Por que você quer o selo? (quem é você, o que ensina)"></textarea>
    <button id="btnProf" class="btn btn-primary btn-sm w-100">Pedir verificação</button>${linkAdmin}`;
  $('#btnProf').onclick = solicitarProfessor;
}

async function solicitarProfessor() {
  const area = $('#profArea').value.trim();
  const just = $('#profJust').value.trim();
  if (area.length < 2) return msg('Diga a área que você quer ensinar.', 'err');
  if (just.length < 20) return msg('Escreva uma justificativa (mín. 20 caracteres).', 'err');
  const b = $('#btnProf'); b.disabled = true;
  const j = await api('/api/professor/solicitar.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ area, justificativa: just })
  });
  b.disabled = false;
  if (j.error) return msg(j.error, 'err');
  msg('Pedido enviado! Um admin vai avaliar.');
  initProfessor();
}

$('#btnCriarTurma').onclick = criarTurma;
$('#btnSubir').onclick = subir;
$('#btnAddAluno').onclick = addAluno;
$('#btnEntrarCodigo').onclick = entrarPorCodigo;
$('#btnGerarCodigo').onclick = gerarCodigo;
$('#btnCopiarCodigo').onclick = copiarCodigo;
$('#chkAprovacao').onchange = salvarAprovacao;
// Botões ícone-＋ que abrem os formulários (fecham a poluição da tela).
$('#btnAbrirCriar').onclick = () => abrirForm('#btnAbrirCriar', '#formCriar');
$('#btnAbrirEntrar').onclick = () => abrirForm('#btnAbrirEntrar', '#formEntrar');
$('#btnAbrirAddAluno').onclick = () => abrirForm('#btnAbrirAddAluno', '#addAluno');
$('#btnAbrirSubir').onclick = () => abrirForm('#btnAbrirSubir', '#subir');
carregarTurmas().catch(() => {});
initProfessor().catch(() => {});
