/* MBS School Orders — front-end. Reads window.MBS (config + ajax) and posts to the WooCommerce cart. */
(function () {
  if (typeof MBS === 'undefined' || !MBS.program) return;
  var P = MBS.program;
  var $ = function (id) { return document.getElementById(id); };
  var money = function (n) { return '$' + Number(n).toFixed(2); };

  // Flatten add-ons + keep group order
  var ADDONS = P.addons || [];
  var byId = {};
  ADDONS.forEach(function (a) { byId[a.id] = a; });
  var qty = {};
  ADDONS.forEach(function (a) { qty[a.id] = 0; });
  var buddyNames = '';

  var CAM = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>';

  function sampleImg(label) {
    var t = String(label).replace(/&/g, 'and');
    var svg = "<svg xmlns='http://www.w3.org/2000/svg' width='520' height='360'>" +
      "<defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0' stop-color='#16365f'/><stop offset='1' stop-color='#0b1f3a'/></linearGradient></defs>" +
      "<rect width='520' height='360' fill='url(#g)'/>" +
      "<circle cx='260' cy='140' r='46' fill='none' stroke='#e6b34a' stroke-width='3'/>" +
      "<circle cx='260' cy='140' r='15' fill='#e6b34a'/>" +
      "<rect x='236' y='104' width='48' height='10' rx='3' fill='#e6b34a'/>" +
      "<text x='260' y='250' fill='#fff' font-family='Arial,sans-serif' font-size='20' font-weight='bold' text-anchor='middle'>" + t + "</text>" +
      "<text x='260' y='282' fill='#9fb0c8' font-family='Arial,sans-serif' font-size='13' text-anchor='middle'>Sample product photo</text></svg>";
    return "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg);
  }
  function openLb(label, img) { $('lbImg').src = (img && img.length) ? img : sampleImg(label); $('lbCap').textContent = label; $('lightbox').classList.add('show'); }
  function closeLb() { $('lightbox').classList.remove('show'); }

  /* ---------- program header + selects ---------- */
  function initProgram() {
    if (P.logoUrl) { $('crestLogo').src = P.logoUrl; $('crestLogo').style.display = 'block'; $('crestShield').style.display = 'none'; }
    else { $('crestLogo').style.display = 'none'; $('crestShield').style.display = 'flex'; }
    $('crestIni').textContent = P.crest || '';
    $('crestMas').textContent = P.crestMascot || '';
    $('mascotLine').textContent = P.mascot || '';
    $('progTitle').innerHTML = (P.line1 || '') + '<br>' + (P.line2 || '') + ' <span class="yr">' + (P.year || '') + '</span>';
    if (P.deadline) $('progSub').innerHTML = 'Official team &amp; individual sports photos. Pick a package, add any extras, and check out securely. Deadline to order: <b>' + P.deadline + '</b>.';

    // divisions
    $('divLabel').innerHTML = (P.divisionLabel || 'Team / Division') + ' <span class="req">*</span>';
    $('fDivision').innerHTML = (P.divisions || []).map(function (d) { return '<option>' + d + '</option>'; }).join('');

    // sport (only if >1)
    var multi = P.sports && P.sports.length > 1;
    if (multi) {
      $('fSport').innerHTML = P.sports.map(function (s) { return '<option>' + s + '</option>'; }).join('');
      $('sportField').style.display = 'block';
      $('teamRow').className = 'grid3';
    } else {
      $('sportField').style.display = 'none';
      $('teamRow').className = 'grid2';
    }

    // packages dropdown
    var opts = '';
    Object.keys(P.packages || {}).forEach(function (k) {
      opts += '<option value="' + k + '">' + P.packages[k].name + ' — ' + money(P.packages[k].price) + '</option>';
    });
    opts += '<option value="NONE">No package — just add-ons</option>';
    $('fPkg').innerHTML = opts;
  }
  function currentSport() {
    if (P.sports && P.sports.length > 1) return $('fSport').value;
    return (P.sports && P.sports.length === 1) ? P.sports[0] : '';
  }
  function curPkg() {
    var v = $('fPkg').value;
    if (v === 'NONE') return { value: 'NONE', name: 'No Package', price: 0, tag: '—', inc: 'No package selected — just add individual items below.' };
    var p = P.packages[v]; p.value = v; return p;
  }

  /* ---------- render ---------- */
  function renderPkg() {
    var p = curPkg();
    $('pkgDetail').innerHTML =
      '<div class="pic">' + p.tag + '</div>' +
      '<div><div style="font-weight:700;font-size:16px">' + p.name + '</div><div class="inc">' + p.inc + '</div>' +
      (p.value === 'NONE' ? '' : '<button class="seebtn" type="button" style="margin-top:9px" data-lb="' + p.name + ' — sample" data-img="' + (p.imgUrl || '') + '">' + CAM + ' See sample</button>') +
      '</div><div class="pr">' + (p.price ? money(p.price) : '—') + '</div>';
    renderLive();
  }
  function renderAddons() {
    var groups = [];
    ADDONS.forEach(function (a) { if (groups.indexOf(a.group) < 0) groups.push(a.group); });
    var h = '';
    groups.forEach(function (g) {
      h += '<div class="grp-title">' + g + '</div><div class="addon-list">';
      ADDONS.filter(function (a) { return a.group === g; }).forEach(function (a) {
        h += '<div class="addon" id="ad_' + a.id + '">' +
          '<div class="txt"><div class="t">' + a.t + '</div><div class="p">' + money(a.p) + ' each</div></div>' +
          '<button class="seebtn" type="button" title="See sample" aria-label="See sample" data-lb="' + a.t + '" data-img="' + (a.imgUrl || '') + '">' + CAM + '</button>' +
          '<div class="stepper"><button type="button" data-step="-1" data-id="' + a.id + '">−</button><span class="v" id="q_' + a.id + '">0</span><button type="button" data-step="1" data-id="' + a.id + '">+</button></div>' +
          '</div>';
        if (a.buddy) {
          h += '<label class="fld buddy-names" id="buddyWrap" style="display:none"><span class="lab">Name(s) of buddies <span class="req">*</span></span><input class="inp" id="fBuddy" placeholder="e.g. Sam Lee, Chris Ray"></label>';
        }
      });
      h += '</div>';
    });
    $('addonBox').innerHTML = h;
  }
  function setQty(id, d) {
    qty[id] = Math.max(0, Math.min(20, qty[id] + d));
    $('q_' + id).textContent = qty[id];
    $('ad_' + id).classList.toggle('on', qty[id] > 0);
    if (byId[id] && byId[id].buddy && $('buddyWrap')) $('buddyWrap').style.display = qty[id] > 0 ? 'block' : 'none';
    renderLive();
  }
  function selectedAddons() {
    return ADDONS.filter(function (a) { return qty[a.id] > 0; }).map(function (a) {
      return { id: a.id, t: a.t, p: a.p, q: qty[a.id], line: a.p * qty[a.id] };
    });
  }
  function orderTotal() {
    return curPkg().price + selectedAddons().reduce(function (s, a) { return s + a.line; }, 0);
  }
  function renderLive() {
    var p = curPkg(), ad = selectedAddons(), lines = '';
    if (p.price) lines += '<div class="sum-line"><span>' + p.name + '</span><span>' + money(p.price) + '</span></div>';
    ad.forEach(function (a) { lines += '<div class="sum-line"><span>' + a.t + (a.q > 1 ? ' × ' + a.q : '') + '</span><span>' + money(a.line) + '</span></div>'; });
    if (!lines) lines = '<div class="sum-line muted">Choose a package or add an item to begin</div>';
    $('liveLines').innerHTML = lines;
    $('liveTotal').textContent = money(orderTotal());
    $('addPrice').textContent = money(orderTotal());
  }

  /* ---------- toast ---------- */
  var toastT;
  function toast(m) { $('toastMsg').textContent = m; $('toast').classList.add('show'); clearTimeout(toastT); toastT = setTimeout(function () { $('toast').classList.remove('show'); }, 2400); }

  /* ---------- add to cart (AJAX -> WooCommerce) ---------- */
  function addToCart() {
    var af = $('fAthFirst').value.trim(), al = $('fAthLast').value.trim();
    var pf = $('fParFirst').value.trim(), pl = $('fParLast').value.trim();
    var miss = [];
    if (!af) miss.push('fAthFirst'); if (!al) miss.push('fAthLast');
    if (!pf) miss.push('fParFirst'); if (!pl) miss.push('fParLast');
    miss.forEach(function (id) { $(id).style.borderColor = 'var(--scarlet)'; });
    if (miss.length) { $(miss[0]).focus(); toast('Please fill in the athlete and parent names'); return; }
    ['fAthFirst', 'fAthLast', 'fParFirst', 'fParLast'].forEach(function (id) { $(id).style.borderColor = ''; });

    var p = curPkg(), ad = selectedAddons();
    if (!p.price && !ad.length) { toast('Pick a package or add at least one item'); return; }
    buddyNames = $('fBuddy') ? $('fBuddy').value.trim() : '';
    if (qty['buddy'] > 0 && !buddyNames) { $('fBuddy').style.borderColor = 'var(--scarlet)'; $('fBuddy').focus(); toast('Please enter the buddy name(s)'); return; }

    var qmap = {};
    ad.forEach(function (a) { qmap[a.id] = a.q; });

    var btn = $('mbsAddBtn'); btn.disabled = true; var orig = btn.innerHTML; btn.innerHTML = 'Adding…';

    var body = new URLSearchParams();
    body.set('action', 'mbs_add');
    body.set('nonce', MBS.nonce);
    body.set('program', MBS.programKey);
    body.set('pkg', p.value);
    body.set('addons', JSON.stringify(qmap));
    body.set('athlete', af + ' ' + al);
    body.set('parent', pf + ' ' + pl);
    body.set('jersey', $('fJersey').value.trim());
    body.set('team', $('fDivision').value);
    body.set('sport', currentSport());
    body.set('phone', $('fPhone').value.trim());
    body.set('email', $('fEmail').value.trim());
    body.set('buddy', buddyNames);

    fetch(MBS.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false; btn.innerHTML = orig;
        if (!res || !res.success) { toast((res && res.data) ? res.data : 'Could not add to cart'); return; }
        if (res.data.count != null) $('cartCount').textContent = res.data.count;
        toast('Added to cart ✓');
        $('mbsAddedMsg').textContent = af + ' ' + al + ' added to cart';
        $('mbsAdded').style.display = 'block';
        // reset item selections; keep it ready for the next athlete
        ADDONS.forEach(function (a) { qty[a.id] = 0; if ($('q_' + a.id)) $('q_' + a.id).textContent = '0'; if ($('ad_' + a.id)) $('ad_' + a.id).classList.remove('on'); });
        buddyNames = ''; if ($('fBuddy')) { $('fBuddy').value = ''; if ($('buddyWrap')) $('buddyWrap').style.display = 'none'; }
        $('fAthFirst').value = ''; $('fAthLast').value = ''; $('fJersey').value = '';
        $('fPkg').selectedIndex = 0; renderPkg();
      })
      .catch(function () { btn.disabled = false; btn.innerHTML = orig; toast('Network error — please try again'); });
  }

  /* ---------- wire events ---------- */
  function bind() {
    $('fPkg').addEventListener('change', renderPkg);
    $('mbsAddBtn').addEventListener('click', addToCart);
    $('mbsAddAnother').addEventListener('click', function () { $('mbsAdded').style.display = 'none'; $('fAthFirst').focus(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    $('lbClose').addEventListener('click', closeLb);
    $('lightbox').addEventListener('click', function (e) { if (e.target === this) closeLb(); });
    // delegated clicks for steppers + sample buttons (content is rendered dynamically)
    document.querySelector('.mbs-app').addEventListener('click', function (e) {
      var step = e.target.closest('[data-step]');
      if (step) { setQty(step.getAttribute('data-id'), parseInt(step.getAttribute('data-step'), 10)); return; }
      var lb = e.target.closest('[data-lb]');
      if (lb) { openLb(lb.getAttribute('data-lb'), lb.getAttribute('data-img')); }
    });
  }

  /* ---------- init ---------- */
  initProgram();
  renderAddons();
  renderPkg();
  bind();
})();
