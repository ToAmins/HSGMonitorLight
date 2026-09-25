// Kleine Helfer ohne Abhängigkeiten: Kopieren-Knöpfe und Sicherheitsabfragen vor dem Absenden.

document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-copy]');
  if (!button) return;
  const source = document.getElementById(button.dataset.copy);
  if (!source) return;
  try {
    await navigator.clipboard.writeText(source.textContent.trim());
    const label = button.textContent;
    button.textContent = 'Kopiert ✓';
    setTimeout(() => { button.textContent = label; }, 2000);
  } catch {
    // Zwischenablage nicht verfügbar (z. B. ohne HTTPS): Text zum Kopieren markieren.
    const range = document.createRange();
    range.selectNodeContents(source);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }
});

document.addEventListener('submit', (event) => {
  const message = event.target.dataset.confirm;
  if (message && !window.confirm(message)) event.preventDefault();
});
