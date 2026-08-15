<?php if (!defined('ABSPATH')) exit; ?>
<div class="mbs-app">

  <header class="masthead">
    <div class="mh-inner">
      <div class="mh-top">
        <div class="studio"><span class="dot"></span>Mark Nicholas Photography &middot; Manhattan Beach Studios</div>
        <a class="cartbtn" id="mbsCartBtn" href="<?php echo esc_url(wc_get_cart_url()); ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
          Cart <span class="count" id="cartCount"><?php echo (int) (function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0); ?></span>
        </a>
      </div>
      <div class="mh-hero">
        <div class="hero-head">
          <div class="logo-slot">
            <img class="crest-logo" id="crestLogo" alt="Program logo" style="display:none">
            <div class="crest" id="crestShield"><span class="ini" id="crestIni">RU</span><span class="mas" id="crestMas">SEA HAWKS</span></div>
          </div>
          <div>
            <div class="eyebrow" id="mascotLine">Athletics</div>
            <h1 class="title" id="progTitle">Program</h1>
            <p class="subhead" id="progSub">Official team &amp; individual sports photos. Pick a package, add any extras, and check out securely.</p>
            <p class="prodlink" id="progPdf" style="display:none;margin:8px 0 0;font-size:15px">&#128196; <a id="progPdfLink" href="#" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline;font-weight:600">Download the paper order form (PDF)</a></p>
            <p class="prodlink" id="progProducts" style="display:none;margin:8px 0 0;font-size:15px">&#128247; <a id="progProductsLink" href="#" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline;font-weight:600">See photos &amp; descriptions of every product &rarr;</a></p>
          </div>
        </div>
        <div class="hero-side" id="heroSide">
        <div class="mh-card" id="howCard">
          <h4>How ordering works</h4>
          <ol>
            <li id="stepWho">Enter the athlete &amp; parent details</li>
            <li id="stepPick">Choose a package (or none) &amp; add extras</li>
            <li>Pay securely by card — done</li>
          </ol>
        </div>
        </div>
      </div>
    </div>
  </header>

  <main>
    <div class="order-grid">
      <div>

        <div class="panel">
          <div class="panel-h"><span class="n">1</span><h3 id="secWho">Athlete &amp; Parent</h3></div>
          <div class="panel-b">
            <div class="grid2" id="whoRow">
              <label class="fld"><span class="lab" id="labWhoFirst">Athlete First Name <span class="req">*</span></span><input class="inp" id="fAthFirst" placeholder="Jordan"></label>
              <label class="fld"><span class="lab" id="labWhoLast">Athlete Last Name <span class="req">*</span></span><input class="inp" id="fAthLast" placeholder="Reyes"></label>
            </div>
            <div class="grid2" id="teamRow">
              <label class="fld" id="jerseyField"><span class="lab" id="labJersey">Jersey #</span><input class="inp" id="fJersey" placeholder="24"></label>
              <label class="fld" id="sportField" style="display:none"><span class="lab" id="labSport">Sport</span><select class="inp" id="fSport"></select></label>
              <label class="fld" id="divisionField"><span class="lab" id="divLabel">Team / Division <span class="req">*</span></span><select class="inp" id="fDivision"></select></label>
            </div>
            <div class="grid2" id="buyerRow">
              <label class="fld"><span class="lab" id="labBuyerFirst">Parent First Name <span class="req">*</span></span><input class="inp" id="fParFirst" placeholder="Alex"></label>
              <label class="fld"><span class="lab" id="labBuyerLast">Parent Last Name <span class="req">*</span></span><input class="inp" id="fParLast" placeholder="Reyes"></label>
            </div>
            <div class="grid2" id="contactRow">
              <label class="fld" id="phoneField"><span class="lab" id="labPhone">Phone <span class="req">*</span></span><input class="inp" id="fPhone" type="tel" inputmode="tel" maxlength="20" placeholder="(310) 555-1234"></label>
              <label class="fld" id="emailField"><span class="lab" id="labEmail">Email (for receipt) <span class="req">*</span></span><input class="inp" id="fEmail" type="email" inputmode="email" placeholder="you@email.com"></label>
            </div>
            <label class="fld" id="notesField"><span class="lab">Notes / special requests <span style="color:var(--muted);font-weight:400">(optional)</span></span><textarea class="inp" id="fNotes" rows="2" maxlength="500" placeholder="Anything we should know? e.g. spelling of a name, sibling on another team…"></textarea></label>
          </div>
        </div>

        <div class="panel" id="pkgPanel">
          <div class="panel-h"><span class="n" id="pkgPanelNum">2</span><h3>Choose a Package</h3><span class="hint">A, B, C — or none</span></div>
          <div class="panel-b">
            <label class="fld pkg-select" style="margin-bottom:0">
              <select class="inp big" id="fPkg"></select>
              <span class="chev"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg></span>
            </label>
            <div class="pkg-detail" id="pkgDetail"></div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-h"><span class="n" id="extrasPanelNum">3</span><h3>Add Extras</h3><span class="hint">optional · set a quantity</span></div>
          <div class="panel-b" id="addonBox"></div>
        </div>

        <button class="addcart" id="mbsAddBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
          Add This Order to Cart · <span id="addPrice">$0.00</span>
        </button>

        <div id="mbsAdded" style="display:none;margin-top:16px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 20px;box-shadow:var(--shadow)">
          <div style="display:flex;align-items:center;gap:10px;font-weight:700;color:var(--ok);font-size:16px"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="var(--ok)" stroke-width="2.4"><path d="M20 6L9 17l-5-5"/></svg><span id="mbsAddedMsg">Added to cart</span></div>
          <p id="addedHint" style="color:var(--muted);font-size:14px;margin:8px 0 14px">You can review or change this order any time from your cart. Most families just head to checkout — only add another athlete if you have more than one.</p>
          <div style="display:flex;gap:12px;flex-wrap:wrap">
            <a class="btn btn-primary" id="mbsCheckout" href="<?php echo esc_url(wc_get_cart_url()); ?>">Go to cart &amp; checkout →</a>
            <button class="btn btn-ghost" id="mbsAddAnother" type="button">＋ Add another athlete</button>
          </div>
        </div>

      </div>

      <aside class="summary">
        <div class="sum-live">
          <h4 id="liveTitle">This Athlete's Order</h4>
          <div id="liveLines"></div>
          <div class="sum-total"><span class="l">Subtotal</span><span class="v" id="liveTotal">$0</span></div>
          <div class="trust">
            <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Secure encrypted card checkout</div>
            <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>Priced per item — never per day</div>
            <div><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M12 2l2.4 7.4H22l-6 4.4 2.3 7.2L12 16.6 5.7 21l2.3-7.2-6-4.4h7.6z"/></svg><span id="multiHint">Order multiple athletes in one cart</span></div>
          </div>
        </div>
      </aside>
    </div>
  </main>

  <div class="lightbox" id="lightbox"><div class="lb-inner"><img id="lbImg" alt="Sample"><div class="lb-cap"><span id="lbCap">Sample</span><small>Product photo</small></div><button class="lb-x" id="lbClose" type="button">✕</button></div></div>

  <div class="toast" id="toast"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg><span id="toastMsg">Added to cart</span></div>

  <div style="text-align:center;color:#9fb0c8;font-size:11px;margin-top:16px;letter-spacing:.02em">Order system v<?php echo esc_html(defined('MBS_SO_VER') ? MBS_SO_VER : ''); ?></div>

</div>
