"use strict";
// Delegated confirmations cover both destructive forms and individual submitters.
document.addEventListener("submit", (event) => {
  const message = event.submitter?.getAttribute("data-confirm") || event.target.getAttribute("data-confirm");
  if (message && !window.confirm(message)) event.preventDefault();
});

const editor = document.querySelector("[data-editor]");
if (editor) {
  const content = editor.querySelector('[name="content"]');
  const counter = document.getElementById("word-count");
  const snapshot = () => JSON.stringify(Array.from(new FormData(editor).entries()));
  const initial = snapshot();
  let saving = false;
  const updateCount = () => {
    // A template is inert: author HTML cannot execute scripts or load images.
    const parsed = document.createElement("template");
    parsed.innerHTML = content.value;
    parsed.content.querySelectorAll("script, style, template, noscript").forEach((node) => node.remove());
    parsed.content.querySelectorAll("p, br, div, li, h2, h3, h4, h5, h6, blockquote, tr, hr").forEach((node) => node.after(document.createTextNode(" ")));
    const text = parsed.content.textContent.trim();
    const words = text ? text.split(/\s+/u).length : 0;
    counter.textContent = `${words} ${words === 1 ? "word" : "words"}`;
  };
  content.addEventListener("input", updateCount);
  updateCount();
  window.addEventListener("beforeunload", (event) => {
    if (!saving && snapshot() !== initial) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
  // Run after delegated confirmations; a cancelled submit must remain dirty.
  document.addEventListener("submit", (event) => {
    if (event.target === editor && !event.defaultPrevented) saving = true;
  });
  document.addEventListener("keydown", (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
      event.preventDefault();
      editor.requestSubmit(editor.querySelector('[name="save_action"][value="continue"]'));
    }
  });
}
