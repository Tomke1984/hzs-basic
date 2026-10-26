(function () {
  'use strict';
  var body = document.body;
  var CSRF = body.getAttribute('data-csrf');
  var UPLOAD = body.getAttribute('data-upload');

  // Menu op smalle schermen
  var navbtn = document.querySelector('.navbtn');
  if (navbtn) navbtn.addEventListener('click', function () {
    var open = body.classList.toggle('nav-open');
    navbtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // Bevestiging bij verwijderen
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute && ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  // Uploaden
  function pickFile(accept, done) {
    var inp = document.createElement('input');
    inp.type = 'file';
    if (accept) inp.accept = accept;
    inp.addEventListener('change', function () {
      if (!inp.files.length) return;
      var fd = new FormData();
      fd.append('file', inp.files[0]);
      fd.append('_csrf', CSRF);
      var note = toast('Bezig met uploaden…');
      fetch(UPLOAD, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          note.remove();
          if (res.error) { alert(res.error); return; }
          done(res);
        })
        .catch(function () { note.remove(); alert('Het uploaden is mislukt.'); });
    });
    inp.click();
  }
  function toast(text) {
    var t = document.createElement('div');
    t.className = 'alert';
    t.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:99;box-shadow:0 8px 24px rgba(0,0,0,.15)';
    t.textContent = text;
    document.body.appendChild(t);
    return t;
  }

  document.querySelectorAll('[data-upload-to]').forEach(function (b) {
    b.addEventListener('click', function () {
      var target = b.form.querySelector('[name="' + b.getAttribute('data-upload-to') + '"]');
      pickFile(b.getAttribute('data-accept'), function (res) {
        target.value = res.url;
        var img = b.closest('.field').querySelector('img.thumb');
        if (!img) { img = document.createElement('img'); img.className = 'thumb'; b.closest('.field').appendChild(img); }
        img.src = res.src;
      });
    });
  });
  document.querySelectorAll('[data-clear]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.form.querySelector('[name="' + b.getAttribute('data-clear') + '"]').value = '';
      var img = b.closest('.field').querySelector('img.thumb'); if (img) img.remove();
    });
  });
  var only = document.querySelector('[data-upload-only]');
  if (only) only.addEventListener('click', function () { pickFile('', function () { location.reload(); }); });

  // Rijen (uren per groep)
  var rows = document.querySelector('[data-rows]');
  var tpl = document.querySelector('[data-row-template]');
  if (rows && tpl) {
    var n = 1000;
    document.querySelector('[data-add-row]').addEventListener('click', function () {
      var html = tpl.innerHTML.replace(/\[999\]/g, '[' + (n++) + ']');
      var tb = document.createElement('tbody'); tb.innerHTML = html;
      var row = tb.firstElementChild; rows.appendChild(row);
      row.querySelector('select').focus();
    });
    rows.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-remove-row]');
      if (b) b.closest('tr').remove();
    });
  }

  // Activiteit: uren verbergen bij "hele dag"
  var hd = document.querySelector('input[name=hele_dag]');
  var times = document.querySelector('[data-times]');
  if (hd && times) { var sync = function () { times.hidden = hd.checked; }; hd.addEventListener('change', sync); sync(); }

  // Pagina: editor verbergen bij "menulink"
  var tl = document.querySelector('[data-toggle-link]');
  var ed = document.querySelector('[data-hide-for-link]');
  if (tl && ed) { var syncL = function () { ed.hidden = tl.value === 'link'; }; tl.addEventListener('change', syncL); syncL(); }


  // Trainer: telefoon/e-mail enkel tonen als "Competitie" aangevinkt is
  var compBox = document.querySelector('input[name="afdelingen[]"][value=competitie]');
  var compFields = document.querySelector('[data-show-afdeling=competitie]');
  if (compBox && compFields) { var syncC = function () { compFields.hidden = !compBox.checked; }; compBox.addEventListener('change', syncC); syncC(); }

  // ---------- Teksteditor ----------
  var BASE = (UPLOAD || '').replace(/(index\.php\?p=.*|beheer\/upload\/)$/, '');
  var LEGACY = body.getAttribute('data-legacy') || '';
  function rx(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function toDisplay(html) { // opgeslagen links -> zichtbaar in de editor
    html = html.replace(/(src|href)="\/uploads\//g, '$1="' + BASE + 'uploads/');
    if (LEGACY) html = html.replace(/(src|href)="\/(wp-content|_w)\//g, '$1="' + LEGACY + '/$2/');
    return html;
  }
  function toStore(html) {
    html = html.replace(new RegExp('(src|href)="' + rx(BASE) + 'uploads/', 'g'), '$1="/uploads/');
    if (LEGACY) html = html.replace(new RegExp('(src|href)="' + rx(LEGACY) + '/(wp-content|_w)/', 'g'), '$1="/$2/');
    return html;
  }

  document.querySelectorAll('.rte').forEach(function (box) {
    var ta = box.querySelector('textarea');
    var area = document.createElement('div');
    area.className = 'rte-area';
    area.contentEditable = 'true';
    area.innerHTML = toDisplay(ta.value);
    var bar = document.createElement('div');
    bar.className = 'rte-bar';
    var source = false;

    var tools = [
      ['P', 'Gewone tekst', function () { exec('formatBlock', '<p>'); }],
      ['K2', 'Kop', function () { exec('formatBlock', '<h2>'); }],
      ['K3', 'Tussenkop', function () { exec('formatBlock', '<h3>'); }],
      '|',
      ['<b>B</b>', 'Vet', function () { exec('bold'); }],
      ['<i>I</i>', 'Cursief', function () { exec('italic'); }],
      ['• Lijst', 'Opsomming', function () { exec('insertUnorderedList'); }],
      ['1. Lijst', 'Genummerde lijst', function () { exec('insertOrderedList'); }],
      '|',
      ['Link', 'Link toevoegen', function () {
        var u = prompt('Adres van de link (bv. /lid-worden/ of https://…)', 'https://');
        if (u) exec('createLink', u);
      }],
      ['Link weg', 'Link verwijderen', function () { exec('unlink'); }],
      ['Afbeelding', 'Afbeelding uploaden', function () {
        var r = saveSel();
        pickFile('image/*', function (res) {
          restoreSel(r);
          exec('insertHTML', '<p><img src="' + res.src + '" alt=""></p>');
        });
      }],
      ['Bestand', 'Document (pdf, Word, Excel) uploaden', function () {
        var r = saveSel();
        pickFile('.pdf,.docx,.doc,.xlsx,.xls', function (res) {
          restoreSel(r);
          var label = prompt('Tekst van de link', res.name) || res.name;
          exec('insertHTML', '<a href="' + res.src + '">' + escapeHtml(label) + '</a>');
        });
      }],
      ['Tabel', 'Tabel invoegen', function () {
        var c = parseInt(prompt('Aantal kolommen?', '3'), 10) || 3;
        var r = parseInt(prompt('Aantal rijen (zonder kopregel)?', '3'), 10) || 3;
        var h = '<div class="table-wrap"><table><thead><tr>' + new Array(c + 1).join('<th>Kop</th>') + '</tr></thead><tbody>';
        for (var i = 0; i < r; i++) h += '<tr>' + new Array(c + 1).join('<td>&nbsp;</td>') + '</tr>';
        exec('insertHTML', h + '</tbody></table></div><p></p>');
      }],
      ['Kader', 'Opvallend kader rond de alinea', function () {
        var sel = window.getSelection();
        var txt = sel && sel.toString() ? escapeHtml(sel.toString()) : 'Belangrijke mededeling';
        exec('insertHTML', '<div class="callout">' + txt + '</div><p></p>');
      }],
      '|',
      ['↶', 'Ongedaan maken', function () { exec('undo'); }],
      ['HTML', 'HTML-code bekijken of bewerken', toggleSource],
    ];
    tools.forEach(function (t) {
      if (t === '|') { var s = document.createElement('span'); s.className = 'sep'; bar.appendChild(s); return; }
      var b = document.createElement('button');
      b.type = 'button'; b.innerHTML = t[0]; b.title = t[1]; b.setAttribute('aria-label', t[1]);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () { t[2](b); });
      bar.appendChild(b);
    });

    function exec(cmd, val) {
      if (source) return;
      area.focus();
      document.execCommand(cmd, false, val);
    }
    function saveSel() {
      var s = window.getSelection();
      return s.rangeCount && area.contains(s.anchorNode) ? s.getRangeAt(0).cloneRange() : null;
    }
    function restoreSel(r) {
      area.focus();
      if (!r) return;
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
    }
    function toggleSource(btn) {
      source = !source;
      if (source) { ta.value = toStore(area.innerHTML); ta.hidden = false; area.hidden = true; }
      else { area.innerHTML = toDisplay(ta.value); ta.hidden = true; area.hidden = false; }
      btn.setAttribute('aria-pressed', source ? 'true' : 'false');
    }

    ta.hidden = true;
    box.insertBefore(bar, ta);
    box.insertBefore(area, ta);
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
    area.addEventListener('paste', function (e) { // plakken als platte tekst met alinea's
      var html = e.clipboardData && e.clipboardData.getData('text/html');
      var text = e.clipboardData && e.clipboardData.getData('text/plain');
      if (!text && !html) return;
      e.preventDefault();
      var parts = (text || '').split(/\r?\n\s*\r?\n/).map(function (p) { return '<p>' + escapeHtml(p).replace(/\r?\n/g, '<br>') + '</p>'; });
      document.execCommand('insertHTML', false, parts.join(''));
    });
    var form = box.closest('form');
    var dirty = false;
    area.addEventListener('input', function () { dirty = true; });
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('submit', function () {
      if (!source) ta.value = toStore(area.innerHTML);
      dirty = false;
    });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  });

  function escapeHtml(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
})();
