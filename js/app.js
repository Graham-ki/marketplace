/* ═══════════════════════════════════════════════════════
   APP.JS — search, cart, catalog builder, publish
   ═══════════════════════════════════════════════════════ */
(() => {

  /* ─────────── CART: add to cart ─────────── */
  document.querySelectorAll('[data-add-to-cart]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const pid = btn.dataset.addToCart;
      const fd = new FormData();
      fd.append('action', 'add');
      fd.append('product_id', pid);
      fd.append('quantity', 1);
      const r = await fetch('cart_api.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) { updateCartCount(j.count); toast('Added to cart'); }
      else toast(j.error || 'Could not add', 'error');
    });
  });

  document.querySelectorAll('[data-buy-now]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const pid = btn.dataset.buyNow;
      const fd = new FormData();
      fd.append('action', 'add');
      fd.append('product_id', pid);
      fd.append('quantity', 1);
      await fetch('cart_api.php', { method:'POST', body: fd });
      location.href = 'cart.php';
    });
  });

  function updateCartCount(n) {
    document.querySelectorAll('.cart-count').forEach(el => el.textContent = n);
  }

  /* ─────────── CART PAGE: qty + remove ─────────── */
  document.querySelectorAll('.cart-row').forEach(row => {
    const cartId = row.dataset.cartId;
    const qty = row.querySelector('[data-qty]');
    const setQty = async (v) => {
      const fd = new FormData();
      fd.append('action','update');
      fd.append('cart_id', cartId);
      fd.append('quantity', Math.max(1, v));
      const r = await fetch('cart_api.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) location.reload();
    };
    row.querySelector('[data-qty-inc]')?.addEventListener('click', () => setQty(+qty.value + 1));
    row.querySelector('[data-qty-dec]')?.addEventListener('click', () => setQty(+qty.value - 1));
    qty?.addEventListener('change', () => setQty(+qty.value));
    row.querySelector('[data-remove]')?.addEventListener('click', async () => {
      const fd = new FormData();
      fd.append('action','remove');
      fd.append('cart_id', cartId);
      const r = await fetch('cart_api.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) row.remove();
    });
  });

  /* ─────────── SELL: catalog builder ─────────── */
  const catalog = document.getElementById('catalog');
  if (catalog) {
    const tpl = document.getElementById('itemTpl');
    const addBtn = document.getElementById('addItemBtn');
    const publishBtn = document.getElementById('publishBtn');
    const itemCount = document.getElementById('itemCount');

    function updateCount() {
      const n = catalog.querySelectorAll('.catalog-item').length;
      itemCount.textContent = n;
      publishBtn.disabled = n === 0;
    }

    function addItem() {
      const node = tpl.content.firstElementChild.cloneNode(true);
      catalog.appendChild(node);
      wireItem(node);
      updateCount();
      node.querySelector('[data-field="title"]').focus();
    }

    function wireItem(node) {
      // Remove
      node.querySelector('[data-remove]').addEventListener('click', () => {
        node.remove(); updateCount(); renumber();
      });

      // Image upload
      const zone = node.querySelector('[data-dropzone]');
      const file = zone.querySelector('[data-field="image"]');
      const prev = zone.querySelector('[data-preview]');

      zone.addEventListener('click', () => file.click());
      file.addEventListener('change', async e => {
        const f = e.target.files[0]; if (!f) return;
        // Local preview
        const reader = new FileReader();
        reader.onload = ev => {
          prev.style.backgroundImage = `url(${ev.target.result})`;
          zone.classList.add('has-image');
        };
        reader.readAsDataURL(f);
        // Upload temp
        const fd = new FormData(); fd.append('image', f);
        const r = await fetch('upload.php', { method:'POST', body: fd });
        const j = await r.json();
        if (j.ok) node.dataset.tempPath = j.path;
        else toast('Upload failed', 'error');
      });
    }

    function renumber() {
      catalog.querySelectorAll('.catalog-item [data-num]').forEach((el, i) => {
        el.textContent = i + 1;
      });
    }

    function collectItems() {
      return [...catalog.querySelectorAll('.catalog-item')].map(node => ({
        title:       node.querySelector('[data-field="title"]').value.trim(),
        price:       node.querySelector('[data-field="price"]').value,
        quantity:    node.querySelector('[data-field="quantity"]').value,
        category_id: node.querySelector('[data-field="category_id"]').value,
        description: node.querySelector('[data-field="description"]').value.trim(),
        location:    node.querySelector('[data-field="location"]').value.trim(),
        tempPath:    node.dataset.tempPath || null,
      })).filter(it => it.title && +it.price > 0);
    }

    addBtn.addEventListener('click', addItem);

    // Seed with one item
    addItem();

    // Publish flow
    const modal = document.getElementById('publishModal');
    const previewGrid = document.getElementById('previewGrid');

   publishBtn.addEventListener('click', () => {
  const items = collectItems();
  if (!items.length) return toast('Add at least one complete item','error');

  // Get the DOM nodes in order — same order as collectItems()
  const nodes = [...catalog.querySelectorAll('.catalog-item')]
    .filter(node => {
      const t = node.querySelector('[data-field="title"]').value.trim();
      const p = node.querySelector('[data-field="price"]').value;
      return t && +p > 0;
    });

  previewGrid.innerHTML = items.map((it, i) => {
    const node = nodes[i];
    const previewEl = node?.querySelector('[data-preview]');
    const bg = previewEl?.style.backgroundImage || '';

    // Extract the raw URL out of `url("...")` or `url(...)`
    let imgUrl = '';
    if (bg) {
      const m = bg.match(/^url\(["']?(.*?)["']?\)$/);
      if (m) imgUrl = m[1];
    }

    return `
      <div class="product-card" style="cursor:default">
        <div class="product-thumb">
          ${imgUrl
            ? `<img src="${imgUrl}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">`
            : `<span class="thumb-placeholder">📦</span>`}
        </div>
        <div class="product-body">
          <strong class="product-title">${escapeHtml(it.title)}</strong>
          <div class="product-foot">
            <span class="product-price">$${Number(it.price).toFixed(2)}</span>
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
        modal.querySelectorAll('.modal-step').forEach(s =>
          s.hidden = (+s.dataset.step !== n));
      }));

    // Final publish
    const form = document.getElementById('publishForm');
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const items = collectItems();
      if (!items.length) return toast('No items','error');

      const fd = new FormData(form);
      fd.append('items_json', JSON.stringify(items));

      const btn = form.querySelector('button[type=submit]');
      const orig = btn.textContent;
      btn.disabled = true; btn.textContent = 'Publishing…';

      try {
        const r = await fetch('publish.php', { method:'POST', body: fd });
        const j = await r.json();
        if (j.ok) location.href = j.redirect || 'dashboard.php';
        else { toast(j.error, 'error'); btn.disabled = false; btn.textContent = orig; }
      } catch (_) {
        toast('Network error','error');
        btn.disabled = false; btn.textContent = orig;
      }
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  /* ─────────── TOAST ─────────── */
  function toast(msg, type='info') {
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2600);
  }
})();

(() => {
  const search = document.querySelector('.searchbar input[name="q"]');
  const dropdown = document.getElementById('searchResults');
  if (!search || !dropdown) return;

  let t;
  search.addEventListener('input', () => {
    clearTimeout(t);
    const q = search.value.trim();
    if (!q) { dropdown.hidden = true; return; }
    t = setTimeout(async () => {
      const r = await fetch('search.php?q=' + encodeURIComponent(q));
      const j = await r.json();
      if (!j.ok) return;
      dropdown.innerHTML = '';

      if (j.sellers.length) {
        dropdown.insertAdjacentHTML('beforeend', '<div class="search-section">Shops</div>');
        j.sellers.forEach(s => {
          dropdown.insertAdjacentHTML('beforeend',
            `<a class="search-item" href="index.php?q=${encodeURIComponent(s.shop_name)}">
               <span class="search-avatar">${s.shop_name[0]}</span>
               <div><strong>${escapeHtml(s.shop_name)}</strong>
               <small>${s.item_count} items</small></div>
             </a>`);
        });
      }
      if (j.products.length) {
        dropdown.insertAdjacentHTML('beforeend', '<div class="search-section">Items</div>');
        j.products.forEach(p => {
          dropdown.insertAdjacentHTML('beforeend',
            `<a class="search-item" href="product.php?id=${p.id}">
               <span class="search-thumb" style="background-image:url('${p.cover_image||''}')"></span>
               <div><strong>${escapeHtml(p.title)}</strong>
               <small>${escapeHtml(p.seller_name)} · $${Number(p.price).toFixed(2)}</small></div>
             </a>`);
        });
      }
      if (!j.products.length && !j.sellers.length) {
        dropdown.innerHTML = '<div class="search-empty">No results</div>';
      }
      dropdown.hidden = false;
    }, 220);
  });

  document.addEventListener('click', e => {
    if (!e.target.closest('.searchbar, #searchResults')) dropdown.hidden = true;
  });
  
  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
})();