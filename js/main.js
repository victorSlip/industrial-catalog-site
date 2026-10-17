/* =========================================================
   Nordtec — scripts do site
   O conteúdo (contato, categorias, máquinas) vem de data/site.json,
   que é editado pelo painel em /admin.
   ========================================================= */

document.documentElement.classList.add("js");

let CONTACT = { email: "", phone: "", address: "", hours: "", social: {} };
let CATEGORIES = [];
let MACHINES = [];
let CAPTCHA = { provider: "", siteKey: "" };   // configurado no painel (Contato → Proteção contra spam)

/* ---------- Ícones (SVG inline) ---------- */
const ICONS = {
  mail: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2.5" y="5" width="19" height="14" rx="1.5"/><path d="m3 6 9 7 9-7"/></svg>',
  phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M5 3h3l2 5-2.5 1.5a11 11 0 0 0 7 7L16 14l5 2v3a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2Z"/></svg>',
  pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 21s-6-5.3-6-11a6 6 0 0 1 12 0c0 5.7-6 11-6 11Z"/><circle cx="12" cy="10" r="2.2"/></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  facebook: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-1.6 19.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.2c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.3v7A10 10 0 0 0 12 2Z"/></svg>',
  instagram: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>',
  x: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.8 3h3.1l-6.8 7.7L22 21h-6.2l-4.9-6.4L5.3 21H2.2l7.2-8.3L2 3h6.4l4.4 5.8L17.8 3Zm-1.1 16.2h1.7L7.4 4.7H5.6l11.1 14.5Z"/></svg>',
  linkedin: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2ZM8 19H5v-9h3v9ZM6.5 8.7a1.7 1.7 0 1 1 0-3.4 1.7 1.7 0 0 1 0 3.4ZM19 19h-3v-4.4c0-1.1 0-2.4-1.5-2.4s-1.7 1.1-1.7 2.3V19h-3v-9h2.9v1.2c.4-.8 1.4-1.5 2.9-1.5 3 0 3.5 2 3.5 4.6V19Z"/></svg>',
  chevronUp: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 15 7-7 7 7"/></svg>',
  chevronLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 5-7 7 7 7"/></svg>',
  chevronRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg>',
  arrowRight: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
};

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

const machineImg = (m, file = m.image || "main.jpg") => `img/machines/${m.id}/${file}`;
const machineUrl = (m) => `machine?id=${m.id}`;
const categoryOf = (m) => CATEGORIES.find((c) => c.id === m.category) || { id: "", name: "" };

/* ---------- Conteúdo no idioma escolhido ----------
   Traduções ficam em obj.translations.de / .pt (editadas no painel).
   Se a tradução estiver vazia, usa o texto em inglês. */
const filled = (v) => (Array.isArray(v) ? v.length > 0 : typeof v === "string" && v.trim() !== "");
const trOf = (obj) => (obj && obj.translations && obj.translations[LANG]) || {};
function loc(obj, field) {
  const v = trOf(obj)[field];
  return filled(v) ? v : obj[field];
}
const catName = (c) => loc(c, "name");
function locFeatures(m) {
  const tr = trOf(m).features || [];
  return (m.features || []).map((f, i) => {
    const x = tr[i] || {};
    return { ...f, title: filled(x.title) ? x.title : f.title, items: filled(x.items) ? x.items : f.items };
  });
}
const machinesIn = (catId) => MACHINES.filter((m) => m.category === catId);

const NAV_LINKS = [
  ["home", "./", "nav.home"],
  ["machines", "machines", "nav.machines"],
  ["services", "services", "nav.services"],
  ["about", "about", "nav.about"],
  ["contact", "contact", "nav.contact"],
];

/* Seletor de idioma (EN | DE | PT) */
function langSwitch() {
  return `<div class="lang-switch" role="group" aria-label="${t("lang.label")}">
    ${LANGS.map((l) => `<button type="button" data-lang="${l.code}" lang="${l.html}" title="${l.name}" aria-pressed="${l.code === LANG}">${l.label}</button>`).join("")}
  </div>`;
}

function socialLinks() {
  // Só mostra as redes que têm link preenchido no painel
  return ["facebook", "instagram", "x", "linkedin"]
    .filter((k) => CONTACT.social && CONTACT.social[k] && CONTACT.social[k] !== "#")
    .map((k) => `<a href="${escapeHtml(CONTACT.social[k])}" aria-label="${k}" target="_blank" rel="noopener">${ICONS[k]}</a>`)
    .join("");
}

/* Card de máquina (usado na listagem e em "máquinas relacionadas") */
function machineCard(m) {
  return `
    <article class="machine-card reveal">
      <a class="img-frame" href="${machineUrl(m)}" tabindex="-1"><img src="${machineImg(m)}" alt="${escapeHtml(m.name)}" loading="lazy"></a>
      <div class="machine-card__body">
        <span class="machine-card__cat">${escapeHtml(catName(categoryOf(m)))}</span>
        <h3>${escapeHtml(m.name)}</h3>
        <p>${escapeHtml(loc(m, "subtitle"))}</p>
        <a class="btn btn--sm btn--outline" href="${machineUrl(m)}">${t("card.details")} ${ICONS.arrowRight}</a>
      </div>
    </article>`;
}

/* ---------- Cabeçalho e rodapé (iguais em todas as páginas) ---------- */
function renderHeader() {
  const el = $("#site-header");
  if (!el) return;
  const current = document.body.dataset.page;
  el.outerHTML = `
    <header class="site-header">
      <div class="container">
        <a class="logo" href="./"><img src="img/logo.webp" alt="Nordtec Machinery" width="640" height="140"></a>
        <button class="nav-toggle" aria-label="${t("nav.openMenu")}" aria-expanded="false" aria-controls="main-nav">
          <span></span><span></span><span></span>
        </button>
        <nav class="nav" id="main-nav">
          <ul class="nav__links">
            ${NAV_LINKS.map(([id, href, key]) => `<li><a href="${href}"${id === current ? ' aria-current="page"' : ""}>${t(key)}</a></li>`).join("")}
          </ul>
          ${langSwitch()}
          <a class="btn" href="contact?subject=quote">${t("nav.quote")}</a>
        </nav>
      </div>
    </header>`;
}

function renderFooter() {
  const el = $("#site-footer");
  if (!el) return;
  el.outerHTML = `
    <footer class="site-footer">
      <div class="container footer-main">
        <div>
          <a class="logo" href="./"><img src="img/logo-white.webp" alt="Nordtec Machinery" width="640" height="140"></a>
          <p class="footer-about">${t("footer.about")}</p>
        </div>
        <div>
          <h4>${t("footer.navigation")}</h4>
          <ul class="footer-links">
            ${NAV_LINKS.map(([, href, key]) => `<li><a href="${href}">${t(key)}</a></li>`).join("")}
          </ul>
        </div>
        <div>
          <h4>${t("footer.contact")}</h4>
          <ul class="footer-contact">
            <li>${ICONS.mail}<a href="mailto:${CONTACT.email}">${CONTACT.email}</a></li>
            <li>${ICONS.phone}<span>${CONTACT.phone}</span></li>
            <li>${ICONS.pin}<span>${CONTACT.address}</span></li>
            ${CONTACT.address2 ? `<li>${ICONS.pin}<span>${escapeHtml(CONTACT.address2)}</span></li>` : ""}
          </ul>
        </div>
        ${socialLinks() ? `<div>
          <h4>${t("footer.follow")}</h4>
          <div class="footer-social">${socialLinks()}</div>
        </div>` : ""}
      </div>
      <div class="footer-bottom">
        <div class="container">
          <span>© ${new Date().getFullYear()} Nordtec Machinery. ${t("footer.rights")}</span>
          <span>${t("footer.location")}</span>
        </div>
      </div>
    </footer>
    <button class="to-top" aria-label="${t("toTop")}">${ICONS.chevronUp}</button>`;
}

/* Faixa azul de chamada para orçamento (onde houver <div id="cta-band">) */
function renderCtaBand() {
  const el = $("#cta-band");
  if (!el) return;
  el.outerHTML = `
    <section class="cta-band">
      <div class="container">
        <div>
          <h2>${t("cta.title")}</h2>
          <p>${t("cta.text")}</p>
        </div>
        <div class="cta-band__actions">
          <a class="btn btn--white btn--lg" href="contact?subject=quote">${t("cta.quote")}</a>
          <a class="btn btn--ghost btn--lg" href="mailto:${CONTACT.email}">${t("cta.email")}</a>
        </div>
      </div>
    </section>`;
}

/* ---------- Cabeçalho compacto ao rolar ---------- */
function initHeaderScroll() {
  const header = $(".site-header");
  if (!header) return;
  const update = () => header.classList.toggle("is-scrolled", window.scrollY > 40);
  window.addEventListener("scroll", update, { passive: true });
  update();
}

/* ---------- Menu mobile ---------- */
function initNav() {
  const toggle = $(".nav-toggle");
  const nav = $("#main-nav");
  if (!toggle) return;
  const setOpen = (open) => {
    nav.classList.toggle("is-open", open);
    toggle.setAttribute("aria-expanded", open);
    toggle.setAttribute("aria-label", open ? t("nav.closeMenu") : t("nav.openMenu"));
    document.body.style.overflow = open ? "hidden" : "";
  };
  toggle.addEventListener("click", () => setOpen(!nav.classList.contains("is-open")));
  nav.addEventListener("click", (e) => { if (e.target.closest("a")) setOpen(false); });
  window.addEventListener("resize", () => { if (window.innerWidth > 1200) setOpen(false); });

  // Troca de idioma
  nav.addEventListener("click", (e) => {
    const b = e.target.closest("[data-lang]");
    if (b && b.dataset.lang !== LANG) setLang(b.dataset.lang);
  });
}

/* ---------- Botão voltar ao topo ---------- */
function initToTop() {
  const btn = $(".to-top");
  if (!btn) return;
  const update = () => btn.classList.toggle("is-visible", window.scrollY > 600);
  window.addEventListener("scroll", update, { passive: true });
  btn.addEventListener("click", () => window.scrollTo({ top: 0 }));
  update();
}

/* ---------- Home: números e categorias ---------- */
function initHome() {
  const years = $("[data-years]");
  if (years) years.textContent = `${new Date().getFullYear() - 2004}+`;
  const count = $("[data-machine-count]");
  if (count) count.textContent = MACHINES.length;

  const grid = $("#category-grid");
  if (!grid) return;
  // Categorias sem máquinas não aparecem na Home
  grid.innerHTML = CATEGORIES.filter((c) => machinesIn(c.id).length).map((c) => {
    const list = machinesIn(c.id);
    const cover = MACHINES.find((m) => m.id === c.cover) || list[0];
    return `
      <a class="cat-card reveal" href="machines?category=${c.id}">
        <div class="cat-card__img"><img src="${machineImg(cover)}" alt="" loading="lazy"></div>
        <div class="cat-card__body">
          <div>
            <h3>${escapeHtml(catName(c))}</h3>
            <span class="cat-card__count">${t(list.length === 1 ? "cat.product" : "cat.products", { n: list.length })}</span>
          </div>
          <span class="cat-card__arrow">${ICONS.arrowRight}</span>
        </div>
      </a>`;
  }).join("");
}

/* ---------- Home: carrossel ---------- */
function initCarousel() {
  const root = $("#carousel");
  if (!root) return;
  const items = MACHINES.filter((m) => m.featured);
  if (!items.length) { root.closest(".section").remove(); return; }
  root.innerHTML = `
    <div class="carousel__track">
      ${items.map((m) => `
        <article class="carousel__slide">
          <div class="img-frame"><img src="${machineImg(m)}" alt="${escapeHtml(m.name)}" loading="lazy"></div>
          <div>
            <span class="carousel__cat">${escapeHtml(catName(categoryOf(m)))}</span>
            <h3>${escapeHtml(m.name)}</h3>
            <p>${escapeHtml(loc(m, "subtitle"))}</p>
            <a class="btn" href="${machineUrl(m)}">${t("carousel.readMore")} ${ICONS.arrowRight}</a>
          </div>
        </article>`).join("")}
    </div>
    <button class="carousel__btn carousel__btn--prev" aria-label="${t("carousel.prev")}">${ICONS.chevronLeft}</button>
    <button class="carousel__btn carousel__btn--next" aria-label="${t("carousel.next")}">${ICONS.chevronRight}</button>
    <div class="carousel__dots">
      ${items.map((m, i) => `<button aria-label="${t("carousel.slide", { n: i + 1 })}"></button>`).join("")}
    </div>`;

  const track = $(".carousel__track", root);
  const dots = $$(".carousel__dots button", root);
  let index = 0;
  let timer;

  const go = (i) => {
    index = (i + items.length) % items.length;
    track.style.transform = `translateX(-${index * 100}%)`;
    dots.forEach((d, n) => d.setAttribute("aria-current", n === index));
  };
  const restart = () => { clearInterval(timer); timer = setInterval(() => go(index + 1), 6000); };

  $(".carousel__btn--prev", root).addEventListener("click", () => { go(index - 1); restart(); });
  $(".carousel__btn--next", root).addEventListener("click", () => { go(index + 1); restart(); });
  dots.forEach((d, n) => d.addEventListener("click", () => { go(n); restart(); }));

  // Pausa quando o mouse está em cima
  root.addEventListener("mouseenter", () => clearInterval(timer));
  root.addEventListener("mouseleave", restart);

  // Arrastar com o dedo no celular
  let startX = null;
  track.addEventListener("touchstart", (e) => { startX = e.touches[0].clientX; }, { passive: true });
  track.addEventListener("touchend", (e) => {
    if (startX === null) return;
    const dx = e.changedTouches[0].clientX - startX;
    if (Math.abs(dx) > 40) { go(index + (dx < 0 ? 1 : -1)); restart(); }
    startX = null;
  });

  go(0);
  restart();
}

/* ---------- Página de máquinas: filtro + paginação ---------- */
function initMachineList() {
  const grid = $("#machine-grid");
  if (!grid) return;
  const catList = $("#category-list");
  const pager = $("#pagination");
  const PER_PAGE = 9;
  const params = new URLSearchParams(location.search);
  let category = params.get("category") || null;
  let page = 1;

  catList.innerHTML =
    `<li><button data-cat="">${t("machines.all")} <small>${MACHINES.length}</small></button></li>` +
    CATEGORIES.map((c) => `<li><button data-cat="${c.id}">${escapeHtml(catName(c))} <small>${machinesIn(c.id).length}</small></button></li>`).join("");

  const render = () => {
    const list = MACHINES.filter((m) => !category || m.category === category);
    const pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
    page = Math.min(page, pages);
    grid.innerHTML = list.slice((page - 1) * PER_PAGE, page * PER_PAGE).map(machineCard).join("");
    observeReveal(grid);

    $$("button", catList).forEach((b) => b.setAttribute("aria-pressed", b.dataset.cat === (category || "")));

    pager.innerHTML = pages < 2 ? "" :
      Array.from({ length: pages }, (_, i) => `<button data-page="${i + 1}"${i + 1 === page ? ' aria-current="true"' : ""}>${i + 1}</button>`).join("") +
      (page < pages ? `<button data-page="${page + 1}" aria-label="${t("machines.next")}">${t("machines.next")}</button>` : "");
  };

  catList.addEventListener("click", (e) => {
    const b = e.target.closest("button");
    if (!b) return;
    category = b.dataset.cat || null;
    page = 1;
    const url = new URL(location);
    category ? url.searchParams.set("category", category) : url.searchParams.delete("category");
    history.replaceState(null, "", url);
    render();
  });

  pager.addEventListener("click", (e) => {
    const b = e.target.closest("button");
    if (!b) return;
    page = +b.dataset.page;
    render();
    grid.scrollIntoView({ behavior: "smooth", block: "start" });
  });

  render();
}

/* ---------- Página de detalhe da máquina ---------- */
function initMachineDetail() {
  const root = $("#machine-detail");
  if (!root) return;
  const id = new URLSearchParams(location.search).get("id");
  const m = MACHINES.find((x) => x.id === id);

  if (!m) {
    root.innerHTML = `<div class="container section"><h1>${t("detail.notFound")}</h1><p><a href="machines">${t("detail.back")}</a></p></div>`;
    return;
  }

  const cat = categoryOf(m);
  document.title = `${m.name} | Nordtec`;

  const list = (items) => `<ul class="checklist">${items.map((i) => `<li>${escapeHtml(i)}</li>`).join("")}</ul>`;
  const subtitle = loc(m, "subtitle");
  const description = loc(m, "description");
  const highlights = loc(m, "highlights") || [];
  const specs = loc(m, "specs") || [];
  const featuresTitle = loc(m, "featuresTitle");
  const features = locFeatures(m);
  const related = machinesIn(m.category).filter((x) => x.id !== m.id).slice(0, 3);
  const quoteUrl = `contact?subject=quote&machine=${encodeURIComponent(m.name)}`;

  root.innerHTML = `
    <section class="section">
      <div class="container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="machines">${t("nav.machines")}</a><span>›</span>
          <a href="machines?category=${cat.id}">${escapeHtml(catName(cat))}</a><span>›</span>
          <span class="current">${escapeHtml(m.name)}</span>
        </nav>
        <div class="machine-top">
          <div>
            <div class="img-frame"><img src="${machineImg(m)}" alt="${escapeHtml(m.name)}"></div>
            ${specs.length ? `
              <h2 class="spec-title">${t("detail.techData")}</h2>
              <table class="spec-table"><tbody>${specs.map(([k, v]) => `<tr><th scope="row">${escapeHtml(k)}</th><td>${escapeHtml(v)}</td></tr>`).join("")}</tbody></table>` : ""}
          </div>
          <div>
            <p class="eyebrow">${escapeHtml(catName(cat))}</p>
            <h1 class="machine-title">${escapeHtml(m.name.toUpperCase())}</h1>
            <p class="machine-subtitle">${escapeHtml(subtitle)}</p>
            ${description ? `<div class="machine-description">${description.split(/\n\s*\n/).map((p) => `<p>${escapeHtml(p.trim())}</p>`).join("")}</div>` : ""}
            ${highlights.length ? `<h3>${t("detail.highlights")}</h3>${list(highlights)}` : ""}
            <div class="machine-actions">
              <a class="btn" href="${quoteUrl}">${t("nav.quote")}</a>
              <a class="btn btn--outline" href="contact?subject=question&machine=${encodeURIComponent(m.name)}">${t("detail.ask")}</a>
            </div>
            <p class="machine-contact">${t("detail.phone")}: ${CONTACT.phone} · ${t("detail.email")}: <a href="mailto:${CONTACT.email}">${CONTACT.email}</a></p>
          </div>
        </div>
      </div>
    </section>
    ${features.length ? `
    <section class="section section--white">
      <div class="container">
        <p class="eyebrow">${t("detail.features")}</p>
        <h2 class="feature-intro">${escapeHtml(featuresTitle || t("detail.inDetail", { name: m.name }))}</h2>
        <div class="feature-grid">
          ${features.map((f) => `
            <article class="feature reveal">
              ${f.image ? `<div class="img-frame"><img src="${machineImg(m, f.image)}" alt="${escapeHtml(f.title || m.name)}" loading="lazy"></div>` : ""}
              ${f.title || (f.items && f.items.length) ? `<div class="feature__body">${f.title ? `<h3>${escapeHtml(f.title)}</h3>` : ""}${list(f.items || [])}</div>` : ""}
            </article>`).join("")}
        </div>
      </div>
    </section>` : ""}
    ${m.gallery && m.gallery.length ? `
    <section class="section">
      <div class="container">
        <p class="eyebrow">${t("detail.gallery")}</p>
        <h2 class="section-title">${t("detail.galleryTitle")}</h2>
        <div class="gallery">
          ${m.gallery.map((g, i) => `<button class="gallery__item img-frame reveal" data-index="${i}" aria-label="${t("detail.openImage", { n: i + 1 })}"><img src="${machineImg(m, g)}" alt="${escapeHtml(m.name)} – ${t("detail.image", { n: i + 1 })}" loading="lazy"></button>`).join("")}
        </div>
      </div>
    </section>` : ""}
    ${related.length ? `
    <section class="section related">
      <div class="container">
        <div class="section-head section-head--row">
          <div><p class="eyebrow">${escapeHtml(catName(cat))}</p><h2 class="section-title">${t("detail.related")}</h2></div>
          <a class="link-arrow" href="machines?category=${cat.id}">${t("detail.seeAllIn", { cat: escapeHtml(catName(cat)) })}</a>
        </div>
        <div class="machine-grid">${related.map(machineCard).join("")}</div>
      </div>
    </section>` : ""}
    <div id="cta-band"></div>`;

  if (m.gallery && m.gallery.length) initLightbox(m.gallery.map((g) => machineImg(m, g)));
}

/* ---------- Galeria: ampliar imagem ao clicar ---------- */
function initLightbox(images) {
  const box = document.createElement("div");
  box.className = "lightbox";
  box.hidden = true;
  box.innerHTML = `
    <button class="lightbox__close" aria-label="${t("lightbox.close")}">&times;</button>
    <button class="lightbox__nav lightbox__nav--prev" aria-label="${t("carousel.prev")}">${ICONS.chevronLeft}</button>
    <img alt="">
    <button class="lightbox__nav lightbox__nav--next" aria-label="${t("carousel.next")}">${ICONS.chevronRight}</button>`;
  document.body.appendChild(box);
  const img = $("img", box);
  let index = 0;
  const show = (i) => { index = (i + images.length) % images.length; img.src = images[index]; };
  const open = (i) => { show(i); box.hidden = false; document.body.style.overflow = "hidden"; };
  const close = () => { box.hidden = true; document.body.style.overflow = ""; };

  $$(".gallery__item").forEach((b) => b.addEventListener("click", () => open(+b.dataset.index)));
  $(".lightbox__close", box).addEventListener("click", close);
  $(".lightbox__nav--prev", box).addEventListener("click", () => show(index - 1));
  $(".lightbox__nav--next", box).addEventListener("click", () => show(index + 1));
  box.addEventListener("click", (e) => { if (e.target === box) close(); });
  document.addEventListener("keydown", (e) => {
    if (box.hidden) return;
    if (e.key === "Escape") close();
    if (e.key === "ArrowLeft") show(index - 1);
    if (e.key === "ArrowRight") show(index + 1);
  });
}

/* ---------- Contato: preenche dados e trata o formulário ---------- */
function initContact() {
  const box = $("#contact-list");
  if (box) {
    box.innerHTML = `
      <li>${ICONS.pin}<span>${CONTACT.address}</span></li>
      ${CONTACT.address2 ? `<li>${ICONS.pin}<span>${escapeHtml(CONTACT.address2)}</span></li>` : ""}
      <li>${ICONS.mail}<a href="mailto:${CONTACT.email}">${CONTACT.email}</a></li>
      <li>${ICONS.phone}<span>${CONTACT.phone}</span></li>
      <li>${ICONS.clock}<span>${escapeHtml(loc(CONTACT, "hours") || "")}</span></li>`;
  }

  const form = $("#contact-form");
  if (!form) return;

  // Se veio de "Request a Quote", já preenche o assunto
  const params = new URLSearchParams(location.search);
  // Se veio de "Tirar uma dúvida", preenche o assunto com a pergunta sobre a máquina
  const kind = params.get("subject");
  const machine = params.get("machine");
  if (kind === "quote") {
    form.subject.value = machine ? t("form.quoteMachine", { machine }) : t("form.quote");
  } else if (kind === "question") {
    form.subject.value = machine ? t("form.questionMachine", { machine }) : t("form.question");
  }

  const captcha = initCaptcha(form);

  // Envia para api/contact.php (PHP na hospedagem): guarda no painel e envia por e-mail
  form.ts.value = Date.now();
  const status = $(".form-status", form);
  const button = $("button[type=submit]", form);
  const setStatus = (html, type) => { status.innerHTML = html; status.dataset.type = type || ""; };

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    button.disabled = true;
    button.textContent = t("form.sending");
    setStatus("");
    try {
      if (captcha && !captcha.token()) throw new Error(t("form.err.captcha"));
      // demonstração no GitHub Pages: não há PHP para receber a mensagem
      if (location.hostname.endsWith("github.io")) { setStatus(escapeHtml(t("form.demo")), "ok"); return; }
      const fd = new FormData(form);
      fd.append("lang", LANG);
      const res = await fetch("api/contact.php", { method: "POST", body: fd });
      const json = await res.json().catch(() => ({}));
      // o servidor devolve um código (ex.: "email"); a mensagem aparece no idioma do visitante
      if (!res.ok || !json.ok) throw new Error(t("form.err." + (json.code || "default")));
      form.reset();
      form.ts.value = Date.now();
      if (captcha) captcha.reset();
      setStatus(escapeHtml(t("form.success")), "ok");
    } catch (err) {
      const link = `<a href="mailto:${escapeHtml(CONTACT.email)}">${escapeHtml(CONTACT.email)}</a>`;
      const mail = CONTACT.email ? " " + escapeHtml(t("form.alsoEmail", { email: "%EMAIL%" })).replace("%EMAIL%", link) : "";
      setStatus(escapeHtml(err.message) + mail, "error");
      if (captcha) captcha.reset();   // o código de verificação só vale uma vez
    } finally {
      button.disabled = false;
      button.textContent = t("form.submit");
    }
  });
}

/* ---------- CAPTCHA (Google reCAPTCHA ou Cloudflare Turnstile) ----------
   Só é ativado quando as chaves estão preenchidas no painel. */
function initCaptcha(form) {
  const box = $("#captcha", form);
  const { provider, siteKey } = CAPTCHA;
  if (!box || !siteKey || !["recaptcha", "turnstile"].includes(provider)) return null;

  const hl = { en: "en", de: "de", pt: "pt-BR" }[LANG] || "en";
  let widget = null;
  let token = "";
  const render = () => {
    const opts = { sitekey: siteKey, callback: (v) => { token = v; }, "expired-callback": () => { token = ""; } };
    if (provider === "recaptcha") widget = window.grecaptcha.render(box, { ...opts, hl });
    else widget = window.turnstile.render(box, { ...opts, language: hl.toLowerCase() });
  };
  window.onCaptchaReady = () => (provider === "recaptcha" ? window.grecaptcha.ready(render) : render());
  const s = document.createElement("script");
  s.src = provider === "recaptcha"
    ? `https://www.google.com/recaptcha/api.js?onload=onCaptchaReady&render=explicit&hl=${hl}`
    : "https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onCaptchaReady&render=explicit";
  s.async = true;
  s.defer = true;
  document.head.appendChild(s);

  return {
    token: () => token,
    reset: () => {
      token = "";
      try {
        if (widget === null) return;
        if (provider === "recaptcha") window.grecaptcha.reset(widget);
        else window.turnstile.reset(widget);
      } catch (e) {}
    },
  };
}

/* ---------- Animação ao rolar (elementos com .reveal) ---------- */
const revealObserver = "IntersectionObserver" in window
  ? new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) { e.target.classList.add("is-visible"); revealObserver.unobserve(e.target); }
      });
    }, { rootMargin: "0px 0px -60px 0px" })
  : null;

function observeReveal(root = document) {
  const auto = ".section-head, .split > *, .stats__grid, .service-card, .timeline-item, .team-card, .history-intro, .why, .contact-box, .contact-form";
  $$(auto, root).forEach((el) => el.classList.add("reveal"));
  $$(".reveal:not(.is-visible)", root).forEach((el) => {
    revealObserver ? revealObserver.observe(el) : el.classList.add("is-visible");
  });
}

/* ---------- Inicialização ---------- */
applyStaticTranslations();
renderHeader();
initHeaderScroll();
initNav();

// cache: "no-cache" -> sempre confere se o painel salvou uma versão nova
fetch("data/site.json", { cache: "no-cache" })
  .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
  .then((data) => {
    CONTACT = data.contact || CONTACT;
    CAPTCHA = data.captcha || CAPTCHA;
    CATEGORIES = data.categories || [];
    MACHINES = data.machines || [];
  })
  .catch((err) => console.error("Could not load data/site.json", err))
  .finally(() => {
    initMachineDetail();   // antes do CTA: a página de detalhe cria o seu #cta-band
    renderCtaBand();
    renderFooter();
    initToTop();
    initHome();
    initCarousel();
    initMachineList();
    initContact();
    observeReveal();
  });
