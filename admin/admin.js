/* Painel Nordtec — pequenos comportamentos dos formulários */

// Pede confirmação antes de excluir
document.addEventListener("click", (e) => {
  const btn = e.target.closest("[data-confirm]");
  if (btn && !confirm(btn.dataset.confirm)) e.preventDefault();
});

// Adicionar / remover blocos de recursos
const features = document.getElementById("features");
const template = document.getElementById("feature-template");
document.querySelector("[data-add-feature]")?.addEventListener("click", () => {
  features.appendChild(template.content.cloneNode(true));
  features.lastElementChild.querySelector("input[name='feature_title[]']").focus();
  markDirty();
});
features?.addEventListener("click", (e) => {
  const btn = e.target.closest("[data-remove-feature]");
  if (btn && confirm("Remover este bloco?")) {
    btn.closest(".feature-block").remove();
    markDirty();
  }
});

// Mostra a foto escolhida antes de salvar
document.addEventListener("change", (e) => {
  const input = e.target;
  if (input.type !== "file" || input.multiple || !input.files[0]) return;
  const box = input.id ? document.querySelector(`[data-preview-for="${input.id}"]`) : input.closest(".feature-block")?.querySelector(".photo-preview");
  if (!box) return;
  const img = document.createElement("img");
  img.src = URL.createObjectURL(input.files[0]);
  box.replaceChildren(img);
});

// Avisa se a pessoa tentar sair com alterações não salvas
let dirty = false;
let submitting = false;
function markDirty() { dirty = true; }
document.querySelectorAll("form[data-warn-unsaved]").forEach((form) => {
  form.addEventListener("input", markDirty);
  form.addEventListener("change", markDirty);
  form.addEventListener("submit", () => {
    submitting = true;
    const btn = form.querySelector(".savebar .btn:last-child");
    if (btn) { btn.disabled = true; btn.textContent = "Salvando…"; }
  });
});
window.addEventListener("beforeunload", (e) => {
  if (dirty && !submitting) { e.preventDefault(); e.returnValue = ""; }
});

// Abas de idioma do formulário da máquina
const langForm = document.querySelector("[data-lang-form]");
if (langForm) {
  const tabs = [...langForm.querySelectorAll("[data-lang-tab]")];
  const show = (lang) => {
    langForm.dataset.lang = lang;
    tabs.forEach((t) => t.setAttribute("aria-selected", t.dataset.langTab === lang));
    try { sessionStorage.setItem("catalog-admin-lang", lang); } catch (e) {}
  };
  tabs.forEach((t) => t.addEventListener("click", () => show(t.dataset.langTab)));
  let start = "en";
  try { start = sessionStorage.getItem("catalog-admin-lang") || "en"; } catch (e) {}
  show(tabs.some((t) => t.dataset.langTab === start) ? start : "en");
}
