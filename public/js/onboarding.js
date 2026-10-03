/* ═══════════════════════════════════════════════════════
   ONBOARDING ENGINE
   Handles seller + buyer wizards, draft persistence,
   live preview, and final account creation.
   ═══════════════════════════════════════════════════════ */

(() => {
  const wizard = document.querySelector('.wizard');
  if (!wizard) return;

  const role = wizard.dataset.role;              // 'seller' | 'buyer'
  const steps = wizard.querySelectorAll('.step');
  const progressBar = wizard.querySelector('.progress-bar span');
  const progressSteps = wizard.querySelectorAll('.p-step');
  const total = steps.length;

  const state = {
    role,
    step: 1,
    data: { images: [] }   // seller: images[{file, previewUrl, tempPath}]
  };

  // ─── NAV ────────────────────────────────────────────
  function goToStep(n) {
    if (n < 1 || n > total) return;
    steps.forEach(s => s.classList.toggle('active', +s.dataset.step === n));
    progressSteps.forEach(p => {
      const ps = +p.dataset.step;
      p.classList.toggle('active', ps === n);
      p.classList.toggle('done', ps < n);
    });
    progressBar.style.width = (n / total) * 100 + '%';
    state.step = n;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    persistDraft();
  }

  wizard.querySelectorAll('[data-next]').forEach(b =>
    b.addEventListener('click', () => goToStep(+b.dataset.next))
  );
  wizard.querySelectorAll('[data-prev]').forEach(b =>
    b.addEventListener('click', () => goToStep(+b.dataset.prev))
  );

  // ─── SELLER: STEP 1 — title + category ──────────────
  if (role === 'seller') {
    const titleEl = document.getElementById('title');
    const catButtons = wizard.querySelectorAll('.cat');
    const next1 = document.getElementById('next1');

    const check1 = () =>
      next1.disabled = !(state.data.title?.trim() && state.data.category_id);

    titleEl.addEventListener('input', e => {
      state.data.title = e.target.value;
      check1();
    });
    catButtons.forEach(btn => btn.addEventListener('click', () => {
      catButtons.forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      state.data.category_id = btn.dataset.cat;
      state.data.category_name = btn.textContent.trim();
      check1();
    }));

    // STEP 2 — images + fields
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('images');
    const thumbs = document.getElementById('thumbs');
    const priceEl = document.getElementById('price');
    const qtyEl   = document.getElementById('quantity');
    const descEl  = document.getElementById('description');
    const locEl   = document.getElementById('location');
    const next2   = document.getElementById('next2');

    dropzone.addEventListener('click', () => fileInput.click());
    dropzone.addEventListener('dragover', e => {
      e.preventDefault(); dropzone.classList.add('drag');
    });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag'));
    dropzone.addEventListener('drop', e => {
      e.preventDefault(); dropzone.classList.remove('drag');
      handleFiles(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', e => handleFiles(e.target.files));

    function handleFiles(files) {
      const allowed = ['image/jpeg','image/png','image/webp'];
      const maxSize = 5 * 1024 * 1024;
      [...files].slice(0, 5 - state.data.images.length).forEach(file => {
        if (!allowed.includes(file.type)) return toast('Only JPG, PNG, WebP','error');
        if (file.size > maxSize) return toast('Max 5MB per image','error');

        const reader = new FileReader();
        reader.onload = e => {
          const img = { file, previewUrl: e.target.result, tempPath: null };
          state.data.images.push(img);
          renderThumbs(); check2();
          uploadTempImage(file, img);
        };
        reader.readAsDataURL(file);
      });
    }

    function renderThumbs() {
      thumbs.innerHTML = state.data.images.map((im, i) => `
        <div class="thumb">
          <img src="${im.previewUrl}" alt="">
          <button type="button" data-rm="${i}">×</button>
        </div>
      `).join('');
      thumbs.querySelectorAll('[data-rm]').forEach(b =>
        b.addEventListener('click', e => {
          e.stopPropagation();
          state.data.images.splice(+b.dataset.rm, 1);
          renderThumbs(); check2();
        })
      );
    }

    async function uploadTempImage(file, imgRef) {
      const fd = new FormData();
      fd.append('image', file);
      try {
        const r = await fetch('../api/draft_upload.php', { method:'POST', body: fd });
        const j = await r.json();
        if (j.ok) {
          imgRef.tempPath = j.path;
          persistDraft();
        }
      } catch (_) {}
    }

    [priceEl, qtyEl, descEl, locEl].forEach(el =>
      el.addEventListener('input', () => {
        state.data.price       = priceEl.value;
        state.data.quantity    = +qtyEl.value || 1;
        state.data.description = descEl.value;
        state.data.location    = locEl.value;
        check2();
      })
    );

    function check2() {
      next2.disabled = !(state.data.images.length > 0 && +state.data.price > 0);
    }

    // STEP 3 — preview
    document.getElementById('next2').addEventListener('click', renderPreview);
    function renderPreview() {
      const d = state.data;
      const img = d.images[0]?.previewUrl;
      document.getElementById('previewImage').innerHTML =
        img ? `<img src="${img}" alt="">`
            : `<div class="image-placeholder">No image</div>`;
      document.getElementById('previewCat').textContent   = d.category_name || 'Uncategorised';
      document.getElementById('previewTitle').textContent = d.title || 'Untitled';
      document.getElementById('previewPrice').textContent =
        '$' + Number(d.price || 0).toFixed(2);
      document.getElementById('previewDesc').textContent  =
        d.description || 'No description provided.';
      document.getElementById('previewLoc').textContent   =
        '📍 ' + (d.location || 'No location');
    }

    // STEP 4 — mini preview
    document.getElementById('next3')?.addEventListener('click', () => {
      const d = state.data;
      document.getElementById('miniImg').src = d.images[0]?.previewUrl || '';
      document.getElementById('miniTitle').textContent = d.title || '';
      document.getElementById('miniPrice').textContent =
        '$' + Number(d.price || 0).toFixed(2);
    });

    check1(); check2();
  }

  // ─── BUYER: STEP 1 — pick product ───────────────────
  if (role === 'buyer') {
    const cards = wizard.querySelectorAll('.product-card');
    const next1 = document.getElementById('next1');

    cards.forEach(card => card.addEventListener('click', () => {
      cards.forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      state.data.product_id = card.dataset.id;
      state.data.title      = card.dataset.title;
      state.data.price      = card.dataset.price;
      state.data.image      = card.dataset.image;
      next1.disabled = false;
    }));

    // STEP 2 — details
    const qtyEl = document.getElementById('quantity');
    const phoneEl = document.getElementById('phone');
    const addrEl = document.getElementById('address');
    const notesEl = document.getElementById('notes');
    const next2 = document.getElementById('next2');

    const check2 = () => {
      state.data.quantity = +qtyEl.value || 1;
      state.data.phone    = phoneEl.value;
      state.data.address  = addrEl.value;
      state.data.notes    = notesEl.value;
      next2.disabled = !(state.data.quantity > 0 && state.data.address.trim());
    };
    [qtyEl, phoneEl, addrEl, notesEl].forEach(el =>
      el.addEventListener('input', check2));

    // STEP 3 — preview
    next2.addEventListener('click', () => {
      const d = state.data;
      const imgEl = document.getElementById('orderImage');
      imgEl.innerHTML = d.image
        ? `<img src="${d.image}" alt="">`
        : `<div class="image-placeholder">No image</div>`;
      document.getElementById('orderTitle').textContent = d.title || 'Item';
      document.getElementById('orderTotal').textContent =
        '$' + (Number(d.price) * Number(d.quantity)).toFixed(2);
      document.getElementById('orderAddress').textContent = d.address || '';
      document.getElementById('orderQty').textContent = 'Qty ' + d.quantity;
    });

    // STEP 4 — mini preview
    document.getElementById('next3')?.addEventListener('click', () => {
      const d = state.data;
      document.getElementById('miniImg').src = d.image || '';
      document.getElementById('miniTitle').textContent = d.title || 'Your order';
      document.getElementById('miniPrice').textContent =
        '$' + (Number(d.price) * Number(d.quantity)).toFixed(2);
    });

    check2();
  }

  // ─── DRAFT PERSISTENCE ──────────────────────────────
  let saveTimer;
  function persistDraft() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(async () => {
      const payload = {
        role: state.role,
        step: state.step,
        data: {
          ...state.data,
          images: state.data.images.map(i => ({ tempPath: i.tempPath || null }))
        }
      };
      try {
        await fetch('../../api/draft_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
      } catch (_) {}
    }, 400);
  }

  // ─── SIGNUP SUBMIT ──────────────────────────────────
  const signupForm = document.getElementById('signupForm');
  signupForm?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = signupForm.querySelector('button[type=submit]');
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Publishing…';

    try {
      const r = await fetch('/api/commit_draft.php', {
        method: 'POST',
        body: new FormData(signupForm)
      });
      const j = await r.json();
      if (j.ok) {
        wizard.innerHTML = `
          <div class="success-screen">
            <div class="success-icon">🎉</div>
            <h2>${role === 'seller' ? 'Your listing is live!' : 'Order placed!'}</h2>
            <p>${role === 'seller'
              ? 'Buyers can now find your item.'
              : 'The seller has been notified.'}</p>
            <a href="/dashboard.php" class="btn btn-accent btn-lg">
              Go to my dashboard →
            </a>
          </div>`;
      } else {
        toast(j.error || 'Something went wrong', 'error');
        btn.disabled = false;
        btn.textContent = original;
      }
    } catch (_) {
      toast('Network error. Try again.', 'error');
      btn.disabled = false;
      btn.textContent = original;
    }
  });

  // ─── TOAST ──────────────────────────────────────────
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
})();