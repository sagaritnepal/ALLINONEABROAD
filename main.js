/* =============================================
   ALL IN ONE ABROAD — main.js
   ============================================= */

// ─── SAFE STORAGE (localStorage throws in private mode / in-app browsers) ───
function storageGet(key, fallback) {
  try {
    const v = JSON.parse(localStorage.getItem(key) || 'null');
    return Array.isArray(fallback) ? (Array.isArray(v) ? v : fallback) : (v ?? fallback);
  } catch (e) { return fallback; }
}
function storageSet(key, val) {
  try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {}
}

// ─── IMAGE FALLBACK ──────────────────────────
const FALLBACK_IMG = 'data:image/svg+xml,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><rect width="100%" height="100%" fill="#f3f4f6"/><text x="50%" y="50%" font-family="Arial, sans-serif" font-size="16" fill="#9ca3af" text-anchor="middle" dominant-baseline="middle">Image unavailable</text></svg>'
);
function handleImgError(img) {
  img.onerror = null;
  img.src = FALLBACK_IMG;
}

// Bump alongside IMG_ASSET_VERSION in config.php whenever product images are
// re-uploaded, to bypass Hostinger's CDN caching a stale (e.g. 404) response.
const IMG_ASSET_VERSION = 4;
function imgUrl(path) {
  if (!path) return path;
  const sep = path.includes('?') ? '&' : '?';
  return path + sep + 'v=' + IMG_ASSET_VERSION;
}

// ─── PRODUCT DATA (loaded from the database) ─
let PRODUCTS = [];
let productsLoadError = false;

async function fetchProducts() {
  try {
    const res = await fetch('api.php?action=products');
    const data = await res.json();
    if (data.success) {
      PRODUCTS = data.products;
    } else {
      productsLoadError = true;
      console.error('Failed to load products:', data.message || 'Unknown error');
    }
  } catch (err) {
    productsLoadError = true;
    console.error('Failed to load products:', err);
  }
}

// ─── TOP BANNER (admin-editable) ─────────────
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

async function loadBannerMessages() {
  const bar = document.querySelector('.announce-bar');
  const track = document.querySelector('.announce-track');
  if (!bar || !track) return;
  try {
    const res = await fetch('api.php?action=banner_messages');
    const data = await res.json();
    if (!data.success) return;
    if (!data.messages.length) {
      bar.style.display = 'none';
      return;
    }
    const spans = data.messages.map(m => `<span>${escapeHtml(m)}</span>`).join('');
    track.innerHTML = spans + spans;
    bar.style.display = '';
  } catch (err) {
    // Backend unreachable — leave the static fallback content in place
  }
}

// ─── POPUP BANNER (admin-editable, closeable) ─
async function loadPopupBanner() {
  try { if (sessionStorage.getItem('aiaPopupDismissed')) return; } catch (e) {}
  try {
    const res = await fetch('api.php?action=popup_banner');
    const data = await res.json();
    if (!data.success || !data.enabled || !data.image) return;
    showPopupBanner(data.image, data.link);
  } catch (err) {
    // Backend unreachable — just skip the popup
  }
}

function showPopupBanner(image, link) {
  const overlay = document.createElement('div');
  overlay.id = 'popupBannerOverlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;';

  const card = document.createElement('div');
  card.style.cssText = 'position:relative;max-width:480px;width:100%;';

  const closeBtn = document.createElement('button');
  closeBtn.textContent = '×';
  closeBtn.setAttribute('aria-label', 'Close');
  closeBtn.style.cssText = 'position:absolute;top:-14px;right:-14px;width:32px;height:32px;border-radius:50%;background:#111827;color:#fff;border:none;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.3);';

  const img = document.createElement('img');
  img.src = image;
  img.alt = 'Promotion';
  img.style.cssText = 'max-width:100%;max-height:80vh;border-radius:16px;display:block;width:100%;';
  img.onerror = () => handleImgError(img);

  let mediaEl = img;
  if (link) {
    const a = document.createElement('a');
    a.href = link;
    a.appendChild(img);
    mediaEl = a;
  }

  card.appendChild(closeBtn);
  card.appendChild(mediaEl);
  overlay.appendChild(card);
  document.body.appendChild(overlay);

  function dismiss() {
    overlay.remove();
    try { sessionStorage.setItem('aiaPopupDismissed', '1'); } catch (e) {}
  }
  closeBtn.addEventListener('click', dismiss);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) dismiss(); });
}

// ─── CART STATE ──────────────────────────────
let cart = storageGet('aiaCart', []).filter(i => i && typeof i.price === 'number' && i.qty > 0);

function saveCart() { storageSet('aiaCart', cart); }

function addToCartById(id) {
  const p = PRODUCTS.find(x => x.id === id);
  if (!p) return;
  addToCart({ id: p.id, name: p.name, price: p.price, img: p.img, cat: p.cat });
}

function addToCart(product, qty = 1) {
  const existing = cart.find(i => i.id === product.id);
  if (existing) { existing.qty += qty; }
  else { cart.push({ ...product, qty }); }
  saveCart();
  updateCartUI();
  showToast(`✓ Added to cart: ${product.name}`);
}

function removeFromCart(id) {
  cart = cart.filter(i => i.id !== id);
  saveCart();
  updateCartUI();
  renderCartItems();
}

function changeQty(id, delta) {
  const item = cart.find(i => i.id === id);
  if (!item) return;
  item.qty += delta;
  if (item.qty <= 0) removeFromCart(id);
  else { saveCart(); updateCartUI(); renderCartItems(); }
}

function updateCartUI() {
  const total = cart.reduce((s, i) => s + i.qty, 0);
  const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
  document.querySelectorAll('#cartCount').forEach(el => el.textContent = total);
  const lbl = document.getElementById('cartLabel');
  if (lbl) lbl.textContent = total;
  const subEl = document.getElementById('cartSubtotal');
  if (subEl) subEl.textContent = 'Rs. ' + sub.toLocaleString('en-IN');
}

function renderCartItems() {
  const list = document.getElementById('cartItemsList');
  const empty = document.getElementById('cartEmpty');
  const footer = document.getElementById('cartFooter');
  if (!list) return;
  if (cart.length === 0) {
    list.innerHTML = '';
    if (empty) empty.style.display = 'block';
    if (footer) footer.style.display = 'none';
    return;
  }
  if (empty) empty.style.display = 'none';
  if (footer) footer.style.display = 'block';
  list.innerHTML = cart.map(item => `
    <div class="cart-item">
      <img src="${imgUrl(item.img)}" alt="${item.name}" onerror="handleImgError(this)"/>
      <div class="ci-info">
        <div class="ci-name">${item.name}</div>
        <div class="ci-price">Rs. ${item.price.toLocaleString('en-IN')}</div>
        <div class="ci-qty">
          <button onclick="changeQty(${item.id}, -1)">−</button>
          <span>${item.qty}</span>
          <button onclick="changeQty(${item.id}, 1)">+</button>
        </div>
      </div>
      <button class="ci-remove" onclick="removeFromCart(${item.id})" title="Remove">×</button>
    </div>
  `).join('');
}

// ─── CART DRAWER ────────────────────────────
function toggleCart() {
  const drawer = document.getElementById('cartDrawer');
  const overlay = document.getElementById('drawerOverlay');
  if (!drawer) return;
  drawer.classList.toggle('open');
  overlay.classList.toggle('open');
  if (drawer.classList.contains('open')) { renderCartItems(); document.body.style.overflow = 'hidden'; }
  else { document.body.style.overflow = ''; }
}

function closeAllDrawers() {
  document.getElementById('cartDrawer')?.classList.remove('open');
  document.getElementById('drawerOverlay')?.classList.remove('open');
  document.body.style.overflow = '';
}

// ─── MOBILE MENU ────────────────────────────
function toggleMobileMenu() {
  document.getElementById('mobileNav')?.classList.toggle('open');
}

// ─── TOAST ──────────────────────────────────
function showToast(msg) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

// ─── WISHLIST (local) ────────────────────────
let wishlist = storageGet('aiaWish', []).map(Number).filter(Boolean);

function updateWishUI() {
  document.querySelectorAll('.wish-count').forEach(el => {
    el.textContent = wishlist.length;
    el.style.display = wishlist.length ? '' : 'none';
  });
  document.querySelectorAll('[data-wish]').forEach(btn => {
    const on = wishlist.includes(Number(btn.dataset.wish));
    btn.textContent = on ? '♥' : '♡';
    btn.classList.toggle('active', on);
  });
  document.querySelectorAll('[data-wish-pdp]').forEach(btn => {
    btn.classList.toggle('active', wishlist.includes(Number(btn.dataset.wishPdp)));
  });
}

function toggleWish(id) {
  id = Number(id);
  const idx = wishlist.indexOf(id);
  if (idx > -1) { wishlist.splice(idx, 1); showToast('Removed from wishlist'); }
  else { wishlist.push(id); showToast('♥ Added to wishlist'); }
  storageSet('aiaWish', wishlist);
  updateWishUI();
  renderWishlist();
}

function renderWishlist() {
  const grid = document.getElementById('wishGrid');
  if (!grid) return;
  const empty = document.getElementById('wishEmpty');
  const countEl = document.getElementById('wishCountText');
  if (productsLoadError) {
    grid.innerHTML = '<div style="grid-column:1/-1;padding:32px;text-align:center;color:var(--gray);">Unable to load products right now. Please try again later.</div>';
    return;
  }
  const items = wishlist.map(id => PRODUCTS.find(p => p.id === id)).filter(Boolean);
  if (countEl) countEl.textContent = `${items.length} saved item${items.length === 1 ? '' : 's'}`;
  if (empty) empty.style.display = items.length ? 'none' : 'block';
  grid.innerHTML = items.map(renderProductCard).join('');
}

// ─── RENDER PRODUCT CARD ─────────────────────
function galleryImages(p) {
  const extra = p.images ? p.images.split('|').map(s => s.trim()).filter(Boolean) : [];
  return [...new Set([p.img, ...extra].filter(Boolean))];
}

function prodSetImage(wrap, idx) {
  wrap.querySelectorAll('.prod-gallery img').forEach((img, i) => img.classList.toggle('active', i === idx));
  wrap.querySelectorAll('.prod-dot').forEach((d, i) => d.classList.toggle('active', i === idx));
}
function prodGoTo(dot, idx) {
  const wrap = dot.closest('.prod-img-wrap');
  const t = prodHoverTimers.get(wrap);
  if (t) { clearInterval(t); prodHoverTimers.delete(wrap); } // manual selection takes over from auto-cycle
  prodSetImage(wrap, idx);
}

const prodHoverTimers = new WeakMap();
const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
function prodHoverStart(wrap) {
  if (!canHover) return;
  const imgs = wrap.querySelectorAll('.prod-gallery img');
  if (imgs.length < 2) return;
  let i = 0;
  prodHoverTimers.set(wrap, setInterval(() => { i = (i + 1) % imgs.length; prodSetImage(wrap, i); }, 950));
}
function prodHoverStop(wrap) {
  const t = prodHoverTimers.get(wrap);
  if (t) { clearInterval(t); prodHoverTimers.delete(wrap); }
  prodSetImage(wrap, 0);
}

function renderProductCard(p) {
  const inWish = wishlist.includes(p.id);
  const off = p.orig && p.orig > p.price ? Math.round((p.orig - p.price) / p.orig * 100) : 0;
  const outOfStock = (p.stock ?? 0) <= 0;
  const stars = p.stars ?? 5;
  const gallery = galleryImages(p);
  const hasGallery = gallery.length > 1;
  return `
  <div class="prod-card">
    <div class="prod-img-wrap${hasGallery ? ' has-gallery' : ''}" onmouseenter="prodHoverStart(this)" onmouseleave="prodHoverStop(this)">
      <a href="product.php?id=${p.id}">
        <div class="prod-gallery">
          ${gallery.map((src, i) => `<img src="${imgUrl(src)}" alt="${p.name}" loading="lazy" decoding="async" class="${i === 0 ? 'active' : ''}" onerror="handleImgError(this)"/>`).join('')}
        </div>
      </a>
      <div class="prod-badges">
        ${off > 0 ? `<span class="badge badge-off">${off}% OFF</span>` : ''}
        ${p.badge ? `<span class="badge badge-tag">${p.badge}</span>` : ''}
        ${outOfStock ? `<span class="badge" style="background:#6b7280;color:#fff;">OUT OF STOCK</span>` : ''}
      </div>
      <button class="wish-btn ${inWish ? 'active' : ''}" data-wish="${p.id}" onclick="toggleWish(${p.id})" title="Wishlist">${inWish ? '♥' : '♡'}</button>
      ${hasGallery ? `<div class="prod-dots">${gallery.map((_, i) => `<button type="button" class="prod-dot${i === 0 ? ' active' : ''}" onmouseenter="prodGoTo(this,${i})" onclick="prodGoTo(this,${i})" aria-label="Show photo ${i + 1}"></button>`).join('')}</div>` : ''}
    </div>
    <div class="prod-body">
      <div class="prod-cat">${p.cat.toUpperCase()}${p.pieceType === 'set' ? ' · <span class="type-tag type-tag-set">FULL SET</span>' : p.pieceType === 'single' ? ' · <span class="type-tag type-tag-single">SINGLE PIECE</span>' : ''}</div>
      <a href="product.php?id=${p.id}" style="color:inherit;text-decoration:none;"><div class="prod-name">${p.name}</div></a>
      <div class="prod-sub">${p.sub || ''}</div>
      <div class="prod-rating">
        <span class="stars">${'★'.repeat(stars)}${'☆'.repeat(5 - stars)}</span>
        <span class="review-ct">(${(p.reviews ?? 0).toLocaleString('en-IN')})</span>
      </div>
      <div class="prod-price-row">
        <span class="prod-price">Rs. ${p.price.toLocaleString('en-IN')}</span>
        ${off > 0 ? `<span class="prod-orig">Rs. ${p.orig.toLocaleString('en-IN')}</span>` : ''}
      </div>
      <button class="add-cart-btn" ${outOfStock ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : `onclick="addToCartById(${p.id})"`}>${outOfStock ? 'OUT OF STOCK' : 'ADD TO CART'}</button>
    </div>
  </div>`;
}

// ─── HOME PAGE: BEST SELLERS ─────────────────
function renderBestSellers() {
  const grid = document.getElementById('bestSellerGrid');
  if (!grid) return;
  if (productsLoadError) { grid.innerHTML = '<div style="grid-column:1/-1;padding:32px;text-align:center;color:var(--gray);">Unable to load products right now.</div>'; return; }
  const top4 = [...PRODUCTS].sort((a, b) => (b.reviews ?? 0) - (a.reviews ?? 0)).slice(0, 4);
  grid.innerHTML = top4.map(renderProductCard).join('') || '<div style="grid-column:1/-1;padding:32px;text-align:center;color:var(--gray);">No products yet.</div>';
}

// ─── SHOP PAGE ───────────────────────────────
function renderShop() {
  const grid = document.getElementById('shopGrid');
  if (!grid) return;
  if (productsLoadError) { grid.innerHTML = '<div style="grid-column:1/-1;padding:32px;text-align:center;color:var(--gray);">Unable to load products right now. Please try again later.</div>'; return; }
  const params = new URLSearchParams(window.location.search);
  const cat = params.get('cat') || 'all';
  const q = (params.get('q') || '').trim().toLowerCase();
  const sort = document.getElementById('sortSelect')?.value || 'featured';
  let prods = cat === 'all' ? [...PRODUCTS] : PRODUCTS.filter(p => p.cat === cat);
  if (q) prods = prods.filter(p => p.name.toLowerCase().includes(q) || (p.sub || '').toLowerCase().includes(q));
  if (sort === 'low') prods.sort((a, b) => a.price - b.price);
  else if (sort === 'high') prods.sort((a, b) => b.price - a.price);
  else if (sort === 'rated') prods.sort((a, b) => (b.reviews ?? 0) - (a.reviews ?? 0));
  const titleEl = document.getElementById('shopTitle');
  const descEl = document.getElementById('shopDesc');
  const countEl = document.getElementById('prodCount');
  const catMap = {
    luggage: { t: 'Luggage & Bags', d: 'Trolleys, backpacks & sets built for international travel.' },
    kitchen: { t: 'Kitchen Essentials', d: 'Pressure cookers, induction cooktops, tiffins & masala dabbas.' },
    appliances: { t: 'Home Appliances', d: 'Small appliances and gadgets for your new home abroad.' },
    all:     { t: 'All Products', d: 'Everything you need — luggage, kitchen and home appliances.' },
  };
  if (titleEl) titleEl.textContent = q ? `Search results for "${q}"` : (catMap[cat]?.t || 'All Products');
  if (descEl) descEl.textContent = catMap[cat]?.d || '';
  if (countEl) countEl.textContent = `${prods.length} products`;
  grid.innerHTML = prods.map(renderProductCard).join('') || '<div style="grid-column:1/-1;padding:32px;text-align:center;color:var(--gray);">No products found.</div>';

  // Active filter highlight
  document.querySelectorAll('[data-cat]').forEach(btn => {
    btn.style.fontWeight = btn.dataset.cat === cat ? '800' : '500';
    btn.style.color = btn.dataset.cat === cat ? 'var(--orange)' : '';
  });
}

// ─── SCROLL BEHAVIOURS ───────────────────────
window.addEventListener('scroll', () => {
  const bt = document.getElementById('backTop');
  if (bt) { bt.classList.toggle('show', window.scrollY > 400); }
});

// ─── HEADER STICKY SHADOW ────────────────────
window.addEventListener('scroll', () => {
  const h = document.getElementById('siteHeader');
  if (h) h.style.boxShadow = window.scrollY > 10 ? '0 2px 16px rgba(0,0,0,0.1)' : '';
});

// ─── SEARCH (basic) ──────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  const si = document.getElementById('searchInput');
  if (si) {
    si.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        const q = si.value.trim();
        if (q) window.location.href = `shop.html?q=${encodeURIComponent(q)}`;
      }
    });
  }
  // Init
  updateCartUI();
  updateWishUI();
  const loadingHtml = '<div style="grid-column:1/-1;padding:32px;text-align:center;color:var(--gray);">Loading products…</div>';
  const bestGrid = document.getElementById('bestSellerGrid');
  const shopGrid = document.getElementById('shopGrid');
  const wishGrid = document.getElementById('wishGrid');
  if (wishGrid) wishGrid.innerHTML = loadingHtml;
  if (bestGrid) bestGrid.innerHTML = loadingHtml;
  if (shopGrid) shopGrid.innerHTML = loadingHtml;
  await fetchProducts();
  renderBestSellers();
  renderShop();
  renderWishlist();
  updateWishUI();
  checkAuthState();
  loadBannerMessages();
  loadPopupBanner();
});

// ─── AUTH STATE (header sign-in / account) ───
async function checkAuthState() {
  const signInLinks = document.querySelectorAll('.btn-signin');
  if (!signInLinks.length) return;
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'me' })
    });
    const data = await res.json();
    if (data.success && data.user) {
      signInLinks.forEach(el => {
        el.textContent = `Hi, ${data.user.name.split(' ')[0]}`;
        el.href = '#';
        el.title = 'Click to log out';
        el.onclick = (e) => { e.preventDefault(); handleLogout(); };
      });
    }
  } catch (err) {
    // Backend unreachable — leave header as "Sign In"
  }
}

async function handleLogout() {
  try {
    await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'logout' })
    });
  } catch (err) {}
  window.location.href = '/';
}
