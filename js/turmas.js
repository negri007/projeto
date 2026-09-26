const $ = s => document.querySelector(s);
let turmaSel = null, ehDono = false;

function msg(txt, tipo='info') {
  const el = $('#msg');
  el.textContent = txt; el.className = 'msg ' + tipo;
  if (tipo === 'info') setTimeout(() => { el.className = 'msg'; }, 4000);
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
  turmas.forEach(c => {
    const d = document.createElement('div');
    d.className = 'turma' + (turmaSel === c.id ? ' sel' : '');
    d.innerHTML = `<span>${esc(c.name)} <span class="tag">${c.is_owner ? 'professor' : 'aluno'}</span></span><span class="spin">${c.member_count} aluno(s)</span>`;
    d.onclick = () => abrirTurma(c);
    box.appendChild(d);
  });
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
  $('#subir').classList.toggle('hidden', !ehDono);
  $('#addAluno').classList.toggle('hidden', !ehDono);
  carregarTurmas();
  carregarAlunos();
  if (ehDono) carregarAmigos();
  await carregarMateriais();
}

// Turma nao aparece em circulos.html, entao os alunos se gerenciam aqui.
// Mesmos endpoints de circulo: aluno = circle_members, por user_id.
async function carregarAlunos() {
  const box = $('#alunos');
  const j = await api('/api/circles/list_members.php?circle_id=' + turmaSel);
  if (j.error) { box.innerHTML = ''; return msg(j.error, 'err'); }
  const alunos = j.members || [];
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
  carregarAlunos();
  carregarTurmas();
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
  d.appendChild(acoes);
  return d;
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
  carregarMateriais();
}

function esc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

$('#btnCriarTurma').onclick = criarTurma;
$('#btnSubir').onclick = subir;
$('#btnAddAluno').onclick = addAluno;
carregarTurmas().catch(() => {});
