// ═══════════════════════════════════════════════════════
// ONBOARDING ENGINE
// Handles step navigation, draft persistence, live preview
// ═══════════════════════════════════════════════════════

const wizard = document.querySelector('.wizard');
const steps = document.querySelectorAll('.step');
const progressBar = document.querySelector('.progress-bar span');
const progressSteps = document.querySelectorAll('.p-step');

const state = {
  role: wizard.dataset.role,
  step: 1,
  data: {
    title: '',
    category_id: null,
    category_name: '',
    price: '',
    quantity: 1,
    description: '',
    location: '',
    images: [] // {file, previewUrl}
  }
};

// ─── NAVIGATION ───────────────────────────────────────
function goToStep(n) {
  steps.forEach(s => s.classList.toggle('active', +s.dataset.step === n));
  progressSteps.forEach(p => {
    const ps = +p.dataset.step;
    p.classList.toggle('active', ps === n);
    p.classList.toggle('done', ps < n);
  });
  const total = 4;
  progressBar.style.width = (n / total) * 100 + '%';
  state.step = n;
  window.scrollTo({ top: 0, behavior: 'smooth' });
  persistDraft();
}

document.querySelectorAll('[data-next]').forEach(b =>
  b.addEventListener('click', () => goToStep(+b.dataset.next))
);
document.querySelectorAll('[data-prev]').forEach(b =>
  b.addEventListener('click', () => goToStep(+b.dataset.prev))
);

// ─── STEP 1: TITLE + CATEGORY ─────────────────────────
const titleEl = document.getElementById('title');
const catButtons = document.querySelectorAll('.cat');
const next1 = document.getElementById('next1');

function checkStep1() {
  next1.disabled = !(state.data.title.trim() && state.data.category_id);
}
titleEl.addEventListener('input', e => {
  state.data.title = e.target.value;
  checkStep1();
});
catButtons.forEach(btn => btn.addEventListener('click', () => {
  catButtons.forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  state.data.category_id = btn.dataset.cat;
  state.data.category_name = btn.textContent.trim();
  checkStep1();
}));

// ─── STEP 2: IMAGES + DETAILS ─────────────────────────
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
dropzone.addEventListener('dragleave', () =>
  dropzone.classList.remove('drag'));
dropzone.addEventListener('drop', e => {
  e.preventDefault(); dropzone.classList.remove('drag');
  handleFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', e => handleFiles(e.target.files));

function handleFiles(files) {
  const allowed = ['image/jpeg','image/png','image/webp'];
  const maxSize = 5 * 1024 * 1024;
  [...files].slice(0, 5 - state.data.images.length).forEach(file => {
    if (!allowed.includes(file.type)) return toast('Only JPG, PNG, WebP', 'error');
    if (file.size > maxSize) return toast('Max 5MB per image', 'error');

    const reader = new FileReader();
    reader.onload = e => {
      const img = { file, previewUrl: e.target.result };
      state.data.images.push(img);
      renderThumbs();
      uploadTempImage(file);
      checkStep2();
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
      renderThumbs(); checkStep2();
    })
  );
}

// Upload to temp folder (needed to survive signup)
async function uploadTempImage(file) {
  const fd = new FormData();
  fd.append('image', file);
  try {
    const r = await fetch('/api/draft_upload.php', { method:'POST', body:fd });
    const j = await r.json();
    if (j.ok) {
      const idx = state.data.images.findIndex(i => i.file === file);
      if (idx > -1) state.data.images[idx].tempPath = j.path;
      persistDraft();
    }
  } catch (_) {/* non-blocking */}
}

[priceEl, qtyEl, descEl, locEl].forEach(el =>
  el.addEventListener('input', () => {
    state.data.price       = priceEl.value;
    state.data.quantity    = +qtyEl.value || 1;
    state.data.description = descEl.value;
    state.data.location    = locEl.value;
    checkStep2();
  })
);

function checkStep2() {
  const valid = state.data.images.length > 0 && +state.data.price > 0;
  next2.disabled = !valid;
}

// ─── STEP 3: PREVIEW (auto-filled) ────────────────────
document.getElementById('next2').addEventListener('click', renderPreview);
function renderPreview() {
  const d = state.data;
  const img = d.images[0]?.previewUrl;
  document.getElementById('previewImage').innerHTML =
    img ? `<img src="${img}" alt="">` : `<div class="image-placeholder">No image</div>`;
  document.getElementById('previewCat').textContent   = d.category_name || 'Uncategorised';
  document.getElementById('previewTitle').textContent = d.title || 'Untitled';
  document.getElementById('previewPrice').textContent =
    '$' + Number(d.price || 0).toFixed(2);
  document.getElementById('previewDesc').textContent  =
    d.description || 'No description provided.';
  document.getElementById('previewLoc').textContent   =
    '📍 ' + (d.location || 'No location');
}

// ─── STEP 4: MINI PREVIEW ─────────────────────────────
document.getElementById('next3').addEventListener('click', () => {
  const d = state.data;
  const img = d.images[0]?.previewUrl || '';
  document.getElementById('miniImg').src = img;
  document.getElementById('miniTitle').textContent = d.title;
  document.getElementById('miniPrice').textContent = '$' + Number(d.price).toFixed(2);
});

// ─── DRAFT PERSISTENCE ────────────────────────────────
let saveTimer;
function persistDraft() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(async () => {
    try {
      await fetch('/api/draft_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          role: state.role,
          step: state.step,
          data: {
            ...state.data,
            images: state.data.images.map(i => ({
              tempPath: i.tempPath || null
            }))
          }
        })
      });
    } catch (_) {}
  }, 400);
}

// ─── SIGNUP SUBMIT ────────────────────────────────────
const signupForm = document.getElementById('signupForm');
signupForm?.addEventListener('submit', async e => {
  e.preventDefault();
  const btn = signupForm.querySelector('button[type=submit]');
  btn.disabled = true;
  btn.textContent = 'Publishing…';

  const fd = new FormData(signupForm);
  try {
    const r = await fetch('/api/commit_draft.php', {
      method: 'POST', body: fd
    });
    const j = await r.json();
    if (j.ok) {
      document.querySelector('.wizard').innerHTML = `
        <div class="success-screen">
          <div class="success-icon">🎉</div>
          <h2>Your listing is live!</h2>
          <p>Buyers can now find "<strong>${state.data.title}</strong>".</p>
          <a href="/dashboard.php" class="btn btn-primary btn-lg">Go to my dashboard →</a>
        </div>`;
    } else {
      toast(j.error || 'Something went wrong', 'error');
      btn.disabled = false;
      btn.textContent = 'Publish my listing 🚀';
    }
  } catch (_) {
    toast('Network error. Try again.', 'error');
    btn.disabled = false;
    btn.textContent = 'Publish my listing 🚀';
  }
});

// ─── TOASTS ───────────────────────────────────────────
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

// Init
checkStep1(); checkStep2();