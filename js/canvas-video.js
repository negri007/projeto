/* ====================================================================
   MOTOR DE ANÚNCIOS — painel de vídeo de marketing (aba Loja do Canvas).

   O lojista escolhe um modelo + formato, edita os campos e envia a foto do
   produto. O back (api/video/marketing.php) renderiza localmente pelo motor
   Remotion ($0 de API) e o front faz poll em status.php até o MP4 ficar
   pronto. Ver docs/plans/motor-anuncios.md.
   ==================================================================== */
(function () {
  "use strict";

  var catalogo = null;      // { modelos, formatos, nichos } — cacheado
  var loja = null;
  var sel = { modelo: null, formato: "story" };
  var modal = null;

  // Rótulos das fotos por modelo (senão cai no genérico "Foto N").
  var ROTULOS_FOTO = {
    AntesDepois: ["Foto ANTES", "Foto DEPOIS"],
    Historia: ["Foto principal", "Foto de detalhe"],
  };

  function el(html) {
    var d = document.createElement("div");
    d.innerHTML = html.trim();
    return d.firstChild;
  }

  function abrir(lojaAtual) {
    loja = lojaAtual || null;
    var elModal = document.getElementById("motorModal");
    if (!elModal) return;
    modal = bootstrap.Modal.getOrCreateInstance(elModal);
    modal.show();

    if (catalogo) { render(); return; }

    fetch("api/video/modelos.php", { credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || "falhou");
        catalogo = d;
        render();
      })
      .catch(function () {
        document.getElementById("motorBody").innerHTML =
          '<div class="alert alert-danger">Não consegui carregar os modelos. Tente de novo.</div>';
      });
  }

  function render() {
    var body = document.getElementById("motorBody");
    body.innerHTML = "";

    var row = el('<div class="row g-4"></div>');
    var esq = el('<div class="col-lg-5"></div>');
    var dir = el('<div class="col-lg-7"></div>');
    row.appendChild(esq);
    row.appendChild(dir);
    body.appendChild(row);

    // ---- FORMATO ----
    esq.appendChild(el('<label class="form-label fw-semibold">1. Formato</label>'));
    var fRow = el('<div class="d-flex flex-wrap gap-2 mb-4"></div>');
    catalogo.formatos.forEach(function (f) {
      var b = el('<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill"></button>');
      b.textContent = f.nome;
      b.classList.toggle("active", f.id === sel.formato);
      b.onclick = function () {
        sel.formato = f.id;
        fRow.querySelectorAll("button").forEach(function (x) { x.classList.remove("active"); });
        b.classList.add("active");
      };
      fRow.appendChild(b);
    });
    esq.appendChild(fRow);

    // ---- MODELO (galeria) ----
    esq.appendChild(el('<label class="form-label fw-semibold">2. Modelo</label>'));
    var grid = el('<div class="d-flex flex-column gap-2"></div>');
    catalogo.modelos.forEach(function (m) {
      var card = el(
        '<button type="button" class="btn text-start p-3 rounded-3 border w-100" style="border-color:var(--bs-border-color)">' +
          '<div class="fw-semibold">' + escapeHtml(m.nome) + "</div>" +
          '<div class="small text-secondary">' + escapeHtml(m.desc) + "</div>" +
        "</button>"
      );
      card.onclick = function () {
        sel.modelo = m.id;
        grid.querySelectorAll("button").forEach(function (x) {
          x.classList.remove("border-primary"); x.style.borderColor = "var(--bs-border-color)";
        });
        card.classList.add("border-primary"); card.style.borderColor = "";
        montarFormulario(m, dir);
      };
      grid.appendChild(card);
    });
    esq.appendChild(grid);

    dir.appendChild(el('<div class="text-secondary text-center py-5">Escolha um modelo à esquerda para preencher os detalhes.</div>'));

    // reabre no modelo selecionado, se havia um.
    if (sel.modelo) {
      var m = catalogo.modelos.find(function (x) { return x.id === sel.modelo; });
      if (m) { montarFormulario(m, dir); grid.querySelectorAll("button").forEach(function (b, i) {
        if (catalogo.modelos[i].id === sel.modelo) b.classList.add("border-primary");
      }); }
    }
  }

  function montarFormulario(m, dir) {
    dir.innerHTML = "";
    dir.appendChild(el('<label class="form-label fw-semibold">3. Detalhes — ' + escapeHtml(m.nome) + "</label>"));

    var form = el('<div class="d-flex flex-column gap-3"></div>');
    dir.appendChild(form);

    // ---- nicho (opcional) ----
    var selNicho = el('<select class="form-select form-select-sm"></select>');
    selNicho.appendChild(el('<option value="">Estilo automático (pela categoria da loja)</option>'));
    catalogo.nichos.forEach(function (n) {
      var o = el("<option></option>"); o.value = n.id; o.textContent = "Estilo: " + n.nome; selNicho.appendChild(o);
    });
    form.appendChild(campo("Estilo visual", selNicho));

    // ---- fotos ----
    var fotoInputs = [];
    if (m.auto === "produtos") {
      form.appendChild(el('<div class="alert alert-info py-2 small mb-0">Este modelo monta o cardápio com os produtos da sua loja (precisa de pelo menos 2 com foto).</div>'));
    } else if (m.fotos > 0) {
      for (var i = 0; i < m.fotos; i++) {
        var rot = (ROTULOS_FOTO[m.id] && ROTULOS_FOTO[m.id][i]) || (m.fotos === 1 ? "Foto do produto" : "Foto " + (i + 1));
        var inp = el('<input type="file" class="form-control form-control-sm" accept="image/*">');
        var prev = el('<img class="mt-2 rounded" style="max-height:90px;display:none">');
        (function (inp, prev) {
          inp.onchange = function () {
            if (inp.files && inp.files[0]) { prev.src = URL.createObjectURL(inp.files[0]); prev.style.display = "block"; }
          };
        })(inp, prev);
        var wrap = el("<div></div>"); wrap.appendChild(inp); wrap.appendChild(prev);
        form.appendChild(campo(rot + (i === 0 ? " *" : ""), wrap));
        fotoInputs.push(inp);
      }
    }

    // ---- encaixe da foto: Preencher x Foto inteira + crop (arrastar/zoom) ----
    // Só pra modelos com foto full-bleed (m.ajustavel). O usuário decide se a
    // foto preenche a tela (podendo cortar, e ajusta O QUE aparece arrastando/
    // dando zoom) ou aparece inteira (com fundo desfocado). Manda foco {x,y,zoom}.
    var ajusteState = {modo: "preencher", foco: {x: 0.5, y: 0.5, zoom: 1}};
    if (m.ajustavel && m.fotos > 0 && m.auto !== "produtos") {
      var fmt = (catalogo.formatos || []).find(function (f) { return f.id === sel.formato; }) || {w: 1080, h: 1920};

      var toggle = el('<div class="btn-group btn-group-sm w-100 mb-2" role="group"></div>');
      var bPre = el('<button type="button" class="btn btn-outline-primary active">Preencher a tela</button>');
      var bInt = el('<button type="button" class="btn btn-outline-primary">Mostrar foto inteira</button>');
      toggle.appendChild(bPre); toggle.appendChild(bInt);

      // caixa de crop, na proporção do formato escolhido.
      var boxW = 220, boxH = Math.round(boxW * (fmt.h / fmt.w));
      var cropWrap = el('<div style="display:none"></div>');
      var box = el('<div style="position:relative;overflow:hidden;border-radius:10px;background:#111;cursor:grab;margin:0 auto;width:' + boxW + 'px;height:' + boxH + 'px;touch-action:none"></div>');
      var cimg = el('<img alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;user-select:none;pointer-events:none">');
      box.appendChild(cimg);
      var zoomRow = el('<div class="d-flex align-items-center gap-2 mt-2" style="max-width:' + boxW + 'px;margin:8px auto 0"></div>');
      var zoomIn = el('<input type="range" class="form-range" min="1" max="3" step="0.05" value="1">');
      zoomRow.appendChild(el('<i class="fa-solid fa-magnifying-glass small text-secondary"></i>'));
      zoomRow.appendChild(zoomIn);
      var dica = el('<div class="form-text text-center" style="font-size:.78rem">Arraste a foto e use o zoom pra escolher o que aparece.</div>');
      cropWrap.appendChild(box); cropWrap.appendChild(zoomRow); cropWrap.appendChild(dica);

      function aplica() {
        cimg.style.objectPosition = (ajusteState.foco.x * 100) + "% " + (ajusteState.foco.y * 100) + "%";
        cimg.style.transform = "scale(" + ajusteState.foco.zoom + ")";
      }
      function mostraCrop() {
        cropWrap.style.display = (ajusteState.modo === "preencher" && cimg.getAttribute("src")) ? "block" : "none";
      }

      bPre.onclick = function () { ajusteState.modo = "preencher"; bPre.classList.add("active"); bInt.classList.remove("active"); mostraCrop(); };
      bInt.onclick = function () { ajusteState.modo = "inteira"; bInt.classList.add("active"); bPre.classList.remove("active"); mostraCrop(); };

      // quando a foto principal (foto0) muda, carrega no crop.
      if (fotoInputs[0]) {
        fotoInputs[0].addEventListener("change", function () {
          if (fotoInputs[0].files && fotoInputs[0].files[0]) {
            cimg.src = URL.createObjectURL(fotoInputs[0].files[0]);
            ajusteState.foco = {x: 0.5, y: 0.5, zoom: 1};
            zoomIn.value = 1; aplica(); mostraCrop();
          }
        });
      }

      // arrastar pra deslocar (pan).
      var arrastando = false, lx = 0, ly = 0;
      box.addEventListener("pointerdown", function (e) { arrastando = true; lx = e.clientX; ly = e.clientY; box.setPointerCapture(e.pointerId); box.style.cursor = "grabbing"; });
      box.addEventListener("pointermove", function (e) {
        if (!arrastando) return;
        var dx = e.clientX - lx, dy = e.clientY - ly; lx = e.clientX; ly = e.clientY;
        ajusteState.foco.x = Math.min(1, Math.max(0, ajusteState.foco.x - dx / boxW));
        ajusteState.foco.y = Math.min(1, Math.max(0, ajusteState.foco.y - dy / boxH));
        aplica();
      });
      var solta = function () { arrastando = false; box.style.cursor = "grab"; };
      box.addEventListener("pointerup", solta);
      box.addEventListener("pointercancel", solta);
      zoomIn.addEventListener("input", function () { ajusteState.foco.zoom = parseFloat(zoomIn.value) || 1; aplica(); });

      form.appendChild(campo("Encaixe da foto", toggle));
      form.appendChild(cropWrap);
    }

    // ---- campos de texto ----
    var inputs = {};
    m.campos.forEach(function (c) {
      var input;
      if (c.tipo === "textarea") {
        input = el('<textarea class="form-control form-control-sm" rows="2"></textarea>');
      } else if (c.tipo === "numero") {
        input = el('<input type="number" class="form-control form-control-sm" min="1" max="5">');
      } else {
        input = el('<input type="text" class="form-control form-control-sm">');
      }
      if (c.max) input.setAttribute("maxlength", c.max);
      // O EXEMPLO concreto vira o placeholder (o lojista leigo vê logo o que pôr);
      // o rótulo em cima diz o que é; a dica embaixo explica em uma linha.
      input.placeholder = c.ex || c.label;
      inputs[c.key] = c;
      inputs[c.key]._input = input;
      form.appendChild(campo(c.label + (c.req ? " *" : ""), input, c.dica));
    });

    // ---- ação ----
    var btn = el('<button type="button" class="btn btn-primary rounded-pill mt-2"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Gerar vídeo</button>');
    var status = el('<div class="mt-3"></div>');
    dir.appendChild(btn);
    dir.appendChild(status);

    btn.onclick = function () { gerar(m, selNicho, fotoInputs, inputs, btn, status, ajusteState); };
  }

  function campo(label, controle, dica) {
    var w = el('<div></div>');
    var l = el('<label class="form-label small mb-1 fw-semibold"></label>');
    l.textContent = label;
    w.appendChild(l);
    w.appendChild(controle);
    if (dica) {
      var d = el('<div class="form-text mt-1" style="font-size:.78rem"></div>');
      d.textContent = dica;
      w.appendChild(d);
    }
    return w;
  }

  function gerar(m, selNicho, fotoInputs, inputs, btn, status, ajusteState) {
    var campos = {};
    for (var key in inputs) {
      var c = inputs[key];
      campos[key] = c._input.value;
    }

    var fd = new FormData();
    fd.append("modelo", m.id);
    fd.append("formato", sel.formato);
    if (selNicho.value) fd.append("nicho", selNicho.value);
    fd.append("campos", JSON.stringify(campos));
    if (ajusteState) {
      fd.append("ajuste", ajusteState.modo);
      if (ajusteState.modo === "preencher") fd.append("foco", JSON.stringify(ajusteState.foco));
    }
    fotoInputs.forEach(function (inp, i) {
      if (inp.files && inp.files[0]) fd.append("foto" + i, inp.files[0]);
    });

    btn.disabled = true;
    status.innerHTML = '<div class="text-secondary"><span class="spinner-border spinner-border-sm me-2"></span>Enviando…</div>';

    fetch("api/video/marketing.php", { method: "POST", credentials: "same-origin", body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) { status.innerHTML = '<div class="alert alert-warning py-2 mb-0">' + escapeHtml(d.error) + "</div>"; btn.disabled = false; return; }
        if (!d.video_id) { status.innerHTML = '<div class="alert alert-warning py-2 mb-0">Enfileirado sem id.</div>'; btn.disabled = false; return; }
        status.innerHTML = '<div class="text-secondary"><span class="spinner-border spinner-border-sm me-2"></span>Renderizando o vídeo… (30–90s)</div>';
        acompanhar(d.video_id, btn, status);
      })
      .catch(function () {
        status.innerHTML = '<div class="alert alert-danger py-2 mb-0">Não consegui iniciar. Tente de novo.</div>';
        btn.disabled = false;
      });
  }

  function acompanhar(id, btn, status) {
    var inicio = Date.now();
    var poll = function () {
      fetch("api/video/status.php?video_id=" + encodeURIComponent(id), { credentials: "same-origin" })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.status === "pronto") {
            var url = d.arquivo ? ("uploads/" + d.arquivo) : d.url_plataforma;
            status.innerHTML =
              '<div class="alert alert-success py-2">Pronto!</div>' +
              '<video controls style="width:100%;max-height:60vh;border-radius:12px;background:#000"></video>' +
              '<a class="btn btn-outline-primary rounded-pill mt-2" download href="' + url + '"><i class="fa-solid fa-download me-1"></i>Baixar vídeo</a>';
            status.querySelector("video").src = url;
            // Publicar só existe pro vídeo salvo no servidor (arquivo local):
            // o post referencia o arquivo, não faz upload de novo.
            if (d.arquivo) montarPublicar(id, status);
            btn.disabled = false;
            return;
          }
          if (d.status === "erro") {
            status.innerHTML = '<div class="alert alert-danger py-2 mb-0">A geração falhou' + (d.erro ? ": " + escapeHtml(d.erro) : "") + ".</div>";
            btn.disabled = false;
            return;
          }
          if (Date.now() - inicio > 5 * 60 * 1000) {
            status.innerHTML = '<div class="alert alert-warning py-2 mb-0">Está demorando; confira mais tarde.</div>';
            btn.disabled = false;
            return;
          }
          setTimeout(poll, 4000);
        })
        .catch(function () {
          status.innerHTML = '<div class="alert alert-danger py-2 mb-0">Perdi o acompanhamento.</div>';
          btn.disabled = false;
        });
    };
    poll();
  }

  /* Publica o vídeo pronto como post da loja (api/lojas/post_criar.php com
     `video_id`). O servidor confere que o vídeo é desta loja e está pronto;
     a loja vem da sessão, então aqui não vai loja_id nenhum. */
  function montarPublicar(videoId, status) {
    var box = el(
      '<div class="mt-3 pt-3 border-top">' +
        '<label class="form-label small mb-1 fw-semibold">Legenda (opcional)</label>' +
        '<textarea class="form-control form-control-sm" rows="2" maxlength="3000" placeholder="Ex.: Chegou novidade! Peça pelo chat da loja."></textarea>' +
        '<button type="button" class="btn btn-primary rounded-pill mt-2"><i class="fa-solid fa-paper-plane me-1"></i>Publicar na loja</button>' +
        '<div class="mt-2"></div>' +
      "</div>"
    );
    var legenda = box.querySelector("textarea");
    var bPub = box.querySelector("button");
    var msg = box.querySelector("div.mt-2");
    status.appendChild(box);

    bPub.onclick = function () {
      var fd = new FormData();
      fd.append("video_id", videoId);
      fd.append("tipo", "novidade");
      fd.append("conteudo", legenda.value.trim());

      bPub.disabled = true;
      msg.innerHTML = '<span class="text-secondary small"><span class="spinner-border spinner-border-sm me-2"></span>Publicando…</span>';

      fetch("api/lojas/post_criar.php", { method: "POST", credentials: "same-origin", body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.error) {
            msg.innerHTML = '<div class="alert alert-warning py-2 mb-0">' + escapeHtml(d.error) + "</div>";
            bPub.disabled = false;
            return;
          }
          legenda.disabled = true;
          bPub.remove();
          var link = loja && loja.id ? "loja_perfil.html?loja_id=" + encodeURIComponent(loja.id) : "comercio.html";
          msg.innerHTML =
            '<div class="alert alert-success py-2 mb-0 d-flex align-items-center justify-content-between gap-2 flex-wrap">' +
              '<span><i class="fa-solid fa-circle-check me-1"></i>Publicado!</span>' +
              '<span class="d-flex gap-3">' +
                '<a href="comercio.html" class="alert-link">Ver no feed</a>' +
                '<a href="' + link + '" class="alert-link">Ver na loja</a>' +
              "</span>" +
            "</div>";
        })
        .catch(function () {
          msg.innerHTML = '<div class="alert alert-danger py-2 mb-0">Erro de conexão. Tente de novo.</div>';
          bPub.disabled = false;
        });
    };
  }

  function escapeHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  window.MotorVideo = { abrir: abrir };
})();
