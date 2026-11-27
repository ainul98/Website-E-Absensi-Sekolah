(() => {
  let theme = "light";
  try {
    theme = localStorage.getItem("absen-theme") ||
      (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
  } catch {}
  document.documentElement.dataset.theme = theme;
})();
