const headerTranslations = {
  de: {
    navWork: "Angebot",
    navRecommendations: "Empfehlungen",
    navContact: "Kontakt",
    headerCta: "Gespraech anfragen",
  },
  en: {
    navWork: "Offer",
    navRecommendations: "Recommendations",
    navContact: "Contact",
    headerCta: "Request first conversation",
  },
  fr: {
    navWork: "Offre",
    navRecommendations: "Recommandations",
    navContact: "Contact",
    headerCta: "Demander un premier echange",
  },
};

const chatFrame = document.querySelector("#feely-chat-frame");
const progressBar = document.querySelector(".scroll-progress");

function setHeaderLanguage(language) {
  const lang = headerTranslations[language] ? language : "de";
  document.documentElement.lang = lang;
  document.querySelectorAll("[data-i18n]").forEach((node) => {
    node.textContent = headerTranslations[lang][node.dataset.i18n] || node.textContent;
  });
  document.querySelectorAll(".lang-switch button").forEach((button) => {
    button.classList.toggle("active", button.dataset.lang === lang);
  });
  localStorage.setItem("preferred-language", lang);
}

function mountChat() {
  const chatUrl = new URL(chatFrame.dataset.src, window.location.href);
  const pageParams = new URLSearchParams(window.location.search);
  const configuredApiBase = document
    .querySelector('meta[name="feely-api-base"]')
    ?.content.trim();

  ["verify_email_token", "view", "mode"].forEach((name) => {
    const value = pageParams.get(name);
    if (value) chatUrl.searchParams.set(name, value);
  });

  const apiBase = pageParams.get("api_base") || configuredApiBase;
  if (apiBase) chatUrl.searchParams.set("api_base", apiBase);
  chatFrame.src = chatUrl.toString();
}

function sizeChatFrame() {
  const frameTop = chatFrame.getBoundingClientRect().top;
  const availableHeight = window.innerHeight - Math.max(frameTop, 0) - 24;
  chatFrame.style.height = `${Math.max(560, availableHeight)}px`;
}

function updateScrollProgress() {
  const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
  const progress = maxScroll <= 0 ? 0 : window.scrollY / maxScroll;
  progressBar.style.width = `${Math.min(progress * 100, 100)}%`;
}

document.querySelectorAll(".lang-switch button").forEach((button) => {
  button.addEventListener("click", () => setHeaderLanguage(button.dataset.lang));
});

window.addEventListener("resize", () => {
  sizeChatFrame();
  updateScrollProgress();
});
window.addEventListener("scroll", updateScrollProgress, { passive: true });

setHeaderLanguage(localStorage.getItem("preferred-language") || "de");
mountChat();
sizeChatFrame();
updateScrollProgress();
