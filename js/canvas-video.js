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
      var dica = c.tipo === "lista" ? " (separe por vírgula)" : "";
      input.placeholder = c.label + dica;
      inputs[c.key] = c;
      input._el = input;
      inputs[c.key]._input = input;
      form.appendChild(campo(c.label + (c.req ? " *" : "") + dica, input));
    });

    // ---- ação ----
    var btn = el('<button type="button" class="btn btn-primary rounded-pill mt-2"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Gerar vídeo</button>');
    var status = el('<div class="mt-3"></div>');
    dir.appendChild(btn);
    dir.appendChild(status);

    btn.onclick = function () { gerar(m, selNicho, fotoInputs, inputs, btn, status); };
  }

  function campo(label, controle) {
    var w = el('<div></div>');
    var l = el('<label class="form-label small mb-1"></label>');
    l.textContent = label;
    w.appendChild(l);
    w.appendChild(controle);
    return w;
  }

  function gerar(m, selNicho, fotoInputs, inputs, btn, status) {
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

  function escapeHtml(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  window.MotorVideo = { abrir: abrir };
})();
