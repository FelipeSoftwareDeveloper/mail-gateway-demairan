// Aplica o tema salvo antes da pintura da página (evita "piscar").
(function () {
  try {
    var t = localStorage.getItem('mgw-theme');
    if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
  } catch (e) { /* storage indisponível: segue o tema do sistema */ }
})();
