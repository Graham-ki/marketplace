/* ═══════════════════════════════════════════════════════
   APP.JS — Marketplace front-end behaviours
   ═══════════════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════════════
   01. HELPERS
   ═══════════════════════════════════════════════════════ */

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
  );
}

function toast(msg, type = 'info') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  requestAnimationFrame(() => t.classList.add('show'));
  setTimeout(() => {
    t.classList.remove('show');
    setTimeout(() => t.remove(), 300);
  }, 2600);
}

function updateCartCount(n) {
  document.querySelectorAll('.cart-count').forEach(el => {
    el.textContent = n;
    el.hidden = n === 0;
  });
}

/* ═══════════════════════════════════════════════════════
   02. CART
   ═══════════════════════════════════════════════════════ */
(() => {

  document.querySelectorAll('[data-add-to-cart]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const fd = new FormData();
      fd.append('action', 'add');
      fd.append('product_id', btn.dataset.addToCart);
      fd.append('quantity', 1);
      try {
        const r = await fetch('cart_api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) { updateCartCount(j.count); toast('Added to cart'); }
        else toast(j.error || 'Could not add', 'error');
      } catch { toast('Network error', 'error'); }
    });
  });

  document.querySelectorAll('[data-buy-now]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const fd = new FormData();
      fd.append('action', 'add');
      fd.append('product_id', btn.dataset.buyNow);
      fd.append('quantity', 1);
      try { await fetch('cart_api.php', { method: 'POST', body: fd }); } catch {}
      location.href = 'cart.php';
    });
  });

  document.querySelectorAll('.cart-row').forEach(row => {
    const cartId = row.dataset.cartId;
    const qty = row.querySelector('[data-qty]');

    async function setQty(v) {
      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('cart_id', cartId);
      fd.append('quantity', Math.max(1, v));
      try {
        const r = await fetch('cart_api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) location.reload();
      } catch { toast('Network error', 'error'); }
    }

    row.querySelector('[data-qty-inc]')?.addEventListener('click', () => setQty(+qty.value + 1));
    row.querySelector('[data-qty-dec]')?.addEventListener('click', () => setQty(+qty.value - 1));
    qty?.addEventListener('change', () => setQty(+qty.value));

    row.querySelector('[data-remove]')?.addEventListener('click', async () => {
      const fd = new FormData();
      fd.append('action', 'remove');
      fd.append('cart_id', cartId);
      try {
        const r = await fetch('cart_api.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) {
          row.remove();
          if (typeof j.count === 'number') updateCartCount(j.count);
        }
      } catch { toast('Network error', 'error'); }
    });
  });

})();

/* ═══════════════════════════════════════════════════════
   03. SEARCH
   ═══════════════════════════════════════════════════════ */
(() => {

  const search   = document.querySelector('.searchbar input[name="q"]');
  const dropdown = document.getElementById('searchResults');

  if (search && dropdown) {
    let timer;
    search.addEventListener('input', () => {
      clearTimeout(timer);
      const q = search.value.trim();
      if (!q) { dropdown.hidden = true; return; }

      timer = setTimeout(async () => {
        try {
          const r = await fetch('search.php?q=' + encodeURIComponent(q));
          const j = await r.json();
          if (!j.ok) return;

          dropdown.innerHTML = '';

          if (j.sellers?.length) {
            dropdown.insertAdjacentHTML('beforeend', '<div class="search-section">Shops</div>');
            j.sellers.forEach(s => {
              dropdown.insertAdjacentHTML('beforeend',
                `<a class="search-item" href="index.php?q=${encodeURIComponent(s.shop_name)}">
                   <span class="search-avatar">${escapeHtml(s.shop_name[0] || '?')}</span>
                   <div>
                     <strong>${escapeHtml(s.shop_name)}</strong>
                     <small>${s.item_count} item${s.item_count === 1 ? '' : 's'}</small>
                   </div>
                 </a>`);
            });
          }

          if (j.products?.length) {
            dropdown.insertAdjacentHTML('beforeend', '<div class="search-section">Items</div>');
            j.products.forEach(p => {
              dropdown.insertAdjacentHTML('beforeend',
                `<a class="search-item" href="product.php?id=${p.id}">
                   <span class="search-thumb" style="background-image:url('${escapeHtml(p.cover_image || '')}')"></span>
                   <div>
                     <strong>${escapeHtml(p.title)}</strong>
                     <small>${escapeHtml(p.seller_name)} · $${Number(p.price).toFixed(2)}</small>
                   </div>
                 </a>`);
            });
          }

          if (!j.products?.length && !j.sellers?.length) {
            dropdown.innerHTML = '<div class="search-empty">No results</div>';
          }
          dropdown.hidden = false;
        } catch {}
      }, 220);
    });

    document.addEventListener('click', e => {
      if (!e.target.closest('.searchbar, #searchResults')) dropdown.hidden = true;
    });
  }

  /* Mobile search toggle */
  const mobilePanel = document.getElementById('mobileSearch');

  document.querySelectorAll('[data-open-mobile-search]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (!mobilePanel) return;
      mobilePanel.hidden = false;
      mobilePanel.querySelector('input')?.focus();
    });
  });

  document.querySelectorAll('[data-close-mobile-search]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (mobilePanel) mobilePanel.hidden = true;
    });
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && mobilePanel && !mobilePanel.hidden) {
      mobilePanel.hidden = true;
    }
  });

})();

/* ═══════════════════════════════════════════════════════
   04. DISMISSABLE ALERTS
   ═══════════════════════════════════════════════════════ */
document.addEventListener('click', e => {
  const btn = e.target.closest('.alert-close');
  if (!btn) return;
  const alert = btn.closest('.alert');
  if (!alert) return;
  alert.style.transition = 'opacity .2s, transform .2s';
  alert.style.opacity    = '0';
  alert.style.transform  = 'translateY(-4px)';
  setTimeout(() => alert.remove(), 200);
});

/* ═══════════════════════════════════════════════════════
   05. CATALOG BUILDER (sell.php)
   ═══════════════════════════════════════════════════════ */
(() => {
  const catalog = document.getElementById('catalog');
  if (!catalog) return;

  const tpl        = document.getElementById('itemTpl');
  const addBtn     = document.getElementById('addItemBtn');
  const publishBtn = document.getElementById('publishBtn');
  const itemCount  = document.getElementById('itemCount');

  function updateCount() {
    const n = catalog.querySelectorAll('.catalog-item').length;
    itemCount.textContent = n;
    publishBtn.disabled   = n === 0;
  }

  function renumber() {
    catalog.querySelectorAll('.catalog-item [data-num]').forEach((el, i) => {
      el.textContent = i + 1;
    });
  }

  function addItem() {
    const node = tpl.content.firstElementChild.cloneNode(true);
    catalog.appendChild(node);
    wireItem(node);
    updateCount();
    node.querySelector('[data-field="title"]')?.focus();
  }

  function wireItem(node) {
    // Remove
    node.querySelector('[data-remove]')?.addEventListener('click', () => {
      node.remove(); updateCount(); renumber();
    });

    // Image upload
    const zone = node.querySelector('[data-dropzone]');
    const file = zone.querySelector('[data-field="image"]');
    const prev = zone.querySelector('[data-preview]');

    zone.addEventListener('click', () => file.click());
    file.addEventListener('change', async e => {
      const f = e.target.files[0];
      if (!f) return;

      const reader = new FileReader();
      reader.onload = ev => {
        prev.style.backgroundImage = `url("${ev.target.result}")`;
        zone.classList.add('has-image');
      };
      reader.readAsDataURL(f);

      const fd = new FormData();
      fd.append('image', f);
      try {
        const r = await fetch('upload.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) node.dataset.tempPath = j.path;
        else toast('Upload failed', 'error');
      } catch { toast('Upload failed', 'error'); }
    });

    /* ── LIVE DISCOUNT PREVIEW ── */
    const priceEl    = node.querySelector('[data-field="price"]');
    const discountEl = node.querySelector('[data-field="discount_percent"]');
    const preview    = node.querySelector('[data-discount-preview]');
    const origEl     = preview.querySelector('[data-original-display]');
    const saveEl     = preview.querySelector('[data-save-display]');

    function recalcDiscount() {
      const current = parseFloat(priceEl.value) || 0;
      const pct     = parseFloat(discountEl.value) || 0;
      if (current <= 0 || pct <= 0) { preview.hidden = true; return; }

      const original = current / (1 - pct / 100);
      const saved    = original - current;
      origEl.textContent = '$' + original.toFixed(2);
      saveEl.textContent = `save $${saved.toFixed(2)}`;
      preview.hidden = false;
    }
    priceEl.addEventListener('input', recalcDiscount);
    discountEl.addEventListener('input', recalcDiscount);
  }

  function collectItems() {
    return [...catalog.querySelectorAll('.catalog-item')]
      .map(node => {
        const price = parseFloat(node.querySelector('[data-field="price"]').value) || 0;
        const pct   = parseFloat(node.querySelector('[data-field="discount_percent"]').value) || 0;
        const original = (pct > 0 && price > 0)
          ? +(price / (1 - pct / 100)).toFixed(2)
          : null;

        return {
          title:            node.querySelector('[data-field="title"]').value.trim(),
          price:            price,
          discount_percent: pct,
          original_price:   original,
          quantity:         node.querySelector('[data-field="quantity"]').value,
          category_id:      node.querySelector('[data-field="category_id"]').value,
          description:      node.querySelector('[data-field="description"]').value.trim(),
          location:         node.querySelector('[data-field="location"]').value.trim(),
          tempPath:         node.dataset.tempPath || null,
        };
      })
      .filter(it => it.title && it.price > 0);
  }

  addBtn.addEventListener('click', addItem);
  addItem();   // seed with one item

  /* Preview modal */
  const modal       = document.getElementById('publishModal');
  const previewGrid = document.getElementById('previewGrid');

  publishBtn.addEventListener('click', () => {
    const items = collectItems();
    if (!items.length) return toast('Add at least one complete item', 'error');

    const nodes = [...catalog.querySelectorAll('.catalog-item')]
      .filter(node => {
        const t = node.querySelector('[data-field="title"]').value.trim();
        const p = node.querySelector('[data-field="price"]').value;
        return t && +p > 0;
      });

    previewGrid.innerHTML = items.map((it, i) => {
      const node      = nodes[i];
      const previewEl = node?.querySelector('[data-preview]');
      const bg        = previewEl?.style.backgroundImage || '';

      let imgUrl = '';
      const m = bg.match(/^url\(["']?(.*?)["']?\)$/);
      if (m) imgUrl = m[1];

      const pct = Number(it.discount_percent) || 0;

      return `
        <div class="product-card" style="cursor:default">
          <div class="product-thumb">
            ${imgUrl
              ? `<img src="${imgUrl}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">`
              : `<span class="thumb-placeholder">📦</span>`}
            ${pct > 0 ? `<span class="product-badge">−${pct}%</span>` : ''}
          </div>
          <div class="product-body">
            <strong class="product-title">${escapeHtml(it.title)}</strong>
            <div class="product-foot">
              <span class="product-price">
                $${Number(it.price).toFixed(2)}
                ${it.original_price ? `<s class="price-was">$${Number(it.original_price).toFixed(2)}</s>` : ''}
              </span>
              <span class="product-loc">${escapeHtml(it.location || '—')}</span>
            </div>
          </div>
        </div>`;
    }).join('');

    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  });

  modal.querySelectorAll('[data-close-modal]').forEach(el =>
    el.addEventListener('click', () => {
      modal.hidden = true;
      document.body.style.overflow = '';
    }));

  modal.querySelectorAll('[data-goto-step]').forEach(el =>
    el.addEventListener('click', () => {
      const n = +el.dataset.gotoStep;
      modal.querySelectorAll('.modal-step').forEach(s => {
        s.hidden = (+s.dataset.step !== n);
      });
    }));

  const form = document.getElementById('publishForm');
  form.addEventListener('submit', async e => {
    e.preventDefault();

    const items = collectItems();
    if (!items.length) return toast('No items', 'error');

    const fd = new FormData(form);
    fd.append('items_json', JSON.stringify(items));

    const btn  = form.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Publishing…';

    try {
      const r = await fetch('publish.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) location.href = j.redirect || 'dashboard.php?tab=stock';
      else {
        toast(j.error || 'Publish failed', 'error');
        btn.disabled = false;
        btn.textContent = orig;
      }
    } catch {
      toast('Network error', 'error');
      btn.disabled = false;
      btn.textContent = orig;
    }
  });

})();