// Texts of the scripts in the language of the page: the translations given by the page
// (#deizi-i18n, see JS_TEXTS in lib/i18n.php), the French text otherwise; {name} placeholders.
const deiziTexts = (() => {
  try {
    return JSON.parse(document.getElementById("deizi-i18n")?.textContent || "{}");
  } catch (error) {
    return {};
  }
})();
window.t = (text, values = {}) => (deiziTexts[text] || text).replace(/\{(\w+)\}/g, (match, key) => (key in values ? String(values[key]) : match));

const accountMenu = document.querySelector(".account-menu");

if (accountMenu) {
  const toggle = accountMenu.querySelector(".account-menu-toggle");
  const navigation = accountMenu.querySelector(".account-menu-links");
  const mobileQuery = window.matchMedia("(max-width: 760px)");

  function closeMenu() {
    accountMenu.classList.remove("menu-open");
    toggle.setAttribute("aria-expanded", "false");
    toggle.setAttribute("aria-label", "Ouvrir le menu");
  }

  function updateMenuMode() {
    accountMenu.classList.toggle("menu-enhanced", mobileQuery.matches);
    if (!mobileQuery.matches) closeMenu();
  }

  toggle.addEventListener("click", () => {
    const isOpen = accountMenu.classList.toggle("menu-open");
    toggle.setAttribute("aria-expanded", String(isOpen));
    toggle.setAttribute("aria-label", isOpen ? "Fermer le menu" : "Ouvrir le menu");
  });

  navigation.addEventListener("click", (event) => {
    if (event.target.closest("a, button")) closeMenu();
  });

  document.addEventListener("click", (event) => {
    if (!accountMenu.contains(event.target)) closeMenu();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && accountMenu.classList.contains("menu-open")) {
      closeMenu();
      toggle.focus();
    }
  });

  mobileQuery.addEventListener("change", updateMenuMode);
  updateMenuMode();
}

document.querySelectorAll(".section-assignment-form").forEach((form) => {
  const branchGroups = [...form.querySelectorAll(".section-assignment-group")];

  function updateBranch(group, initialize = false) {
    const parent = group.querySelector("[data-section-parent]");
    const children = [...group.querySelectorAll("[data-section-child]")];
    if (!parent || children.length === 0) return;

    if (initialize && parent.checked) {
      children.forEach((child) => {
        child.checked = true;
      });
    }
    const checkedCount = children.filter((child) => child.checked).length;
    parent.checked = checkedCount === children.length;
    parent.indeterminate = checkedCount > 0 && checkedCount < children.length;
  }

  branchGroups.forEach((group) => {
    const parent = group.querySelector("[data-section-parent]");
    const children = [...group.querySelectorAll("[data-section-child]")];
    if (!parent || children.length === 0) return;

    parent.addEventListener("change", () => {
      parent.indeterminate = false;
      children.forEach((child) => {
        child.checked = parent.checked;
      });
      updateBranch(group);
    });

    children.forEach((child) => {
      child.addEventListener("change", () => updateBranch(group));
    });
    updateBranch(group, true);
  });
});

// Roadmap of the date form: the "Répertoire" boxes of the checked sections only (a branch's box
// for any of its sub-sections, a sub-section's box for itself or its whole branch).
document.querySelectorAll("[data-repertoires]").forEach((block) => {
  const form = block.closest("form");
  const boxes = [...block.querySelectorAll("[data-repertoire]")];
  const empty = block.querySelector("[data-repertoire-empty]");
  const update = () => {
    const checked = new Set([...form.querySelectorAll('input[name="sections[]"]:checked, input[name="guest_sections[]"]:checked')].map((input) => input.value));
    let shown = 0;
    boxes.forEach((box) => {
      box.hidden = !box.dataset.repertoire.split(" ").some((section) => checked.has(section));
      shown += box.hidden ? 0 : 1;
    });
    empty.hidden = shown > 0;
  };
  form.addEventListener("change", update);
  update();
});

document.querySelectorAll("[data-event-form]").forEach((form) => {
  const startDate = form.querySelector('[name="date"]');
  const endDate = form.querySelector('[name="end_date"]');
  const allDay = form.querySelector("[data-all-day]");
  const time = form.querySelector("[data-event-time]");
  const endTime = form.querySelector("[data-event-end-time]");
  if (!startDate || !endDate || !allDay || !time || !endTime) return;

  function syncEventDateFields() {
    endDate.min = startDate.value;
    endDate.setCustomValidity(
      endDate.value && startDate.value && endDate.value < startDate.value
        ? "La date de fin doit être égale ou postérieure à la date de début."
        : "",
    );
  }

  function syncAllDayFields() {
    time.disabled = allDay.checked;
    endTime.disabled = allDay.checked;
  }

  startDate.addEventListener("change", syncEventDateFields);
  endDate.addEventListener("change", syncEventDateFields);
  allDay.addEventListener("change", syncAllDayFields);
  syncEventDateFields();
  syncAllDayFields();
});

document.querySelectorAll("[data-password-toggle]").forEach((toggle) => {
  const input = document.getElementById(toggle.getAttribute("aria-controls"));
  if (!(input instanceof HTMLInputElement)) return;

  toggle.addEventListener("click", () => {
    const isVisible = input.type === "password";
    input.type = isVisible ? "text" : "password";
    toggle.textContent = isVisible ? t("Masquer") : t("Afficher");
    toggle.setAttribute("aria-label", isVisible ? t("Masquer le mot de passe") : t("Afficher le mot de passe"));
    toggle.setAttribute("aria-pressed", String(isVisible));
  });
});

document.querySelectorAll("[data-managed-scope]").forEach((scope) => {
  const form = scope.form;
  const roleSelect = form && form.querySelector('select[name="role"]');
  if (!roleSelect) return;
  const checkboxes = [...scope.querySelectorAll('input[type="checkbox"]')];

  function syncScope() {
    const isManager = roleSelect.value === "manager";
    scope.hidden = !isManager;
    scope.disabled = !isManager;
    checkboxes[0].setCustomValidity("");
  }

  roleSelect.addEventListener("change", syncScope);
  scope.addEventListener("change", () => checkboxes[0].setCustomValidity(""));
  form.addEventListener("submit", (event) => {
    if (roleSelect.value !== "manager" || checkboxes.some((checkbox) => checkbox.checked)) return;
    event.preventDefault();
    checkboxes[0].setCustomValidity("Cochez au moins une branche ou une sous-section gérée.");
    checkboxes[0].reportValidity();
  });
  syncScope();
});

// While printing, the page title becomes the name proposed for the PDF (e.g. "20270401-Concert").
const printTitle = document.querySelector("[data-print-title]")?.dataset.printTitle;
if (printTitle) {
  const screenTitle = document.title;
  window.addEventListener("beforeprint", () => {
    document.title = printTitle;
  });
  window.addEventListener("afterprint", () => {
    document.title = screenTitle;
  });
}

document.querySelectorAll("[data-print]").forEach((button) => {
  button.addEventListener("click", () => window.print());
});

// Detailed poll table: printed alone, in portrait (see "is-printing-poll-detail" in styles.css).
document.querySelectorAll("[data-print-poll-detail]").forEach((button) => {
  button.addEventListener("click", () => {
    document.body.classList.add("is-printing-poll-detail");
    window.addEventListener("afterprint", () => document.body.classList.remove("is-printing-poll-detail"), { once: true });
    window.print();
  });
});

document.querySelectorAll("[data-roadmap-rows]").forEach((container) => {
  const template = container.querySelector("template");
  const addButton = container.querySelector("[data-add-row]");
  if (!template || !addButton) return;
  let nextIndex = container.querySelectorAll("[data-roadmap-row]").length;

  container.addEventListener("click", (event) => {
    const removeButton = event.target.closest("[data-remove-row]");
    if (!removeButton) return;
    const row = removeButton.closest("[data-roadmap-row]");
    const rows = container.querySelectorAll("[data-roadmap-row]");
    if (rows.length > 1) {
      row.remove();
    } else {
      row.querySelectorAll("input, textarea").forEach((field) => {
        field.value = "";
      });
    }
  });

  addButton.addEventListener("click", () => {
    // Indexes only need to be unique within the form; the server ignores their values.
    const html = template.innerHTML.replaceAll("__INDEX__", `n${nextIndex}`);
    nextIndex += 1;
    addButton.insertAdjacentHTML("beforebegin", html);
    const added = addButton.previousElementSibling;
    added?.querySelector("input, textarea")?.focus();
  });
});

document.querySelectorAll("[data-copy]").forEach((button) => {
  const field = document.getElementById(button.dataset.copy);
  if (!field) return;
  const label = button.textContent;

  button.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(field.value);
    } catch {
      field.select();
      document.execCommand("copy");
    }
    button.textContent = "Lien copié";
    setTimeout(() => {
      button.textContent = label;
    }, 2000);
  });
});

// Configuration > Statuts: live preview of the colours derived from each status button colour,
// with the same rules as app_status_palette() in lib/bootstrap.php.
function statusPalette(color) {
  const rgb = [1, 3, 5].map((index) => parseInt(color.slice(index, index + 2), 16));
  const mix = (target, share) =>
    "#" + rgb.map((channel, index) => Math.round(channel * (1 - share) + target[index] * share).toString(16).padStart(2, "0")).join("");
  const linear = rgb.map((channel) => {
    const value = channel / 255;
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });
  const luminance = 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
  return {
    bg: color,
    fg: luminance > 0.18 ? mix([0, 0, 0], 0.7) : "#ffffff",
    accent: luminance > 0.45 ? mix([0, 0, 0], 0.3) : color,
    cell: mix([255, 255, 255], 0.65),
  };
}

document.querySelectorAll("[data-status-colors] [data-status-row]").forEach((row) => {
  const input = row.querySelector('input[type="color"]');
  const reset = row.querySelector("[data-status-default]");
  const preview = row.querySelector("[data-status-preview]");
  const defaults = JSON.parse(row.dataset.defaultPalette);
  if (!input || !reset || !preview) return;

  input.addEventListener("input", () => {
    const value = input.value.toLowerCase();
    const palette = value === input.dataset.default ? defaults : statusPalette(value);
    Object.entries(palette).forEach(([part, color]) => preview.style.setProperty(`--preview-${part}`, color));
    reset.hidden = value === input.dataset.default;
  });
  reset.addEventListener("click", () => {
    input.value = input.dataset.default;
    input.dispatchEvent(new Event("input"));
    input.focus();
  });
  // Label of the status: the badge of the preview follows it (the original one when empty).
  const label = row.querySelector("[data-status-label]");
  const badge = preview.querySelector(".status-preview-badge");
  if (label && badge) {
    label.addEventListener("input", () => {
      badge.textContent = label.value.trim() || label.placeholder;
    });
  }
});

// Detailed poll table: filter the rows on the login, first name or last name, ignoring case and accents.
document.querySelectorAll("[data-poll-filter]").forEach((filter) => {
  const table = filter.parentElement.querySelector("[data-poll-filter-table]");
  const input = filter.querySelector('input[type="search"]');
  const count = filter.querySelector("[data-poll-filter-count]");
  if (!table || !input || !count) return;
  const fold = (text) => text.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  const rows = [...table.querySelectorAll("tbody tr[data-search]")];
  const empty = table.querySelector("[data-poll-filter-empty]");
  rows.forEach((row) => {
    row.dataset.search = fold(row.dataset.search);
  });

  function applyFilter() {
    const terms = fold(input.value).split(/\s+/).filter(Boolean);
    let shown = 0;
    rows.forEach((row) => {
      row.hidden = !terms.every((term) => row.dataset.search.includes(term));
      if (!row.hidden) shown += 1;
    });
    if (empty) empty.hidden = shown > 0;
    count.textContent = terms.length > 0 ? `${shown} sur ${rows.length} affiché${shown > 1 ? "s" : ""}` : "";
  }

  input.addEventListener("input", applyFilter);
  filter.hidden = false;
});

// Date form: the roadmap fields are folded while "Feuille de route non disponible" is checked;
// they can still be opened with their summary, and open when the page targets #roadmap.
document.querySelectorAll("[data-roadmap-details]").forEach((details) => {
  const checkbox = details.closest("fieldset")?.querySelector("[data-roadmap-disabled]");
  if (checkbox) {
    checkbox.addEventListener("change", () => {
      details.open = !checkbox.checked;
    });
  }
  if (window.location.hash === "#roadmap") {
    details.open = true;
  }
  // An invalid field (e.g. a malformed link) must be visible for the browser to point to it.
  details.addEventListener("invalid", () => {
    details.open = true;
  }, true);
});

// Advanced views: the filter applies as soon as a box is checked or unchecked ("Appliquer" stays
// only as a fallback without JavaScript).
document.querySelectorAll("[data-autosubmit]").forEach((form) => {
  form.querySelectorAll("[data-autosubmit-button]").forEach((button) => {
    button.hidden = true;
  });
  form.addEventListener("change", () => form.requestSubmit());
});

// Generated quote (logistics-quote.php): groups of lines added or removed in the page, with the
// line totals, group subtotals and quote total recalculated while typing. The server computes the
// saved amounts again; indexes only need to be unique within the form.
document.querySelectorAll("[data-quote-groups]").forEach((container) => {
  const groupTemplate = container.querySelector("[data-group-template]");
  const lineTemplate = container.querySelector("[data-line-template]");
  const addGroupButton = container.querySelector("[data-add-group]");
  const totalOutput = document.querySelector("[data-quote-total]");
  const maxGroups = Number(container.dataset.maxGroups) || 10;
  const maxLines = Number(container.dataset.maxLines) || 20;
  if (!groupTemplate || !lineTemplate || !addGroupButton) return;
  let nextIndex = 1000;
  const money = new Intl.NumberFormat("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const format = (cents) => `${money.format(cents / 100).replace(/\s/g, " ")} €`;
  const parseAmount = (value) => {
    const match = value.replace(/[\s  €]/g, "").match(/^(\d{1,8})(?:[.,](\d{1,2}))?$/);
    return match ? Number(match[1]) * 100 + Number((match[2] || "0").padEnd(2, "0")) : null;
  };
  const groups = () => [...container.querySelectorAll("[data-quote-group]")];

  const refresh = () => {
    let quoteTotal = 0;
    groups().forEach((group) => {
      let groupTotal = 0;
      group.querySelectorAll("[data-quote-line]").forEach((line) => {
        const label = line.querySelector("input[name$='[label]']").value.trim();
        const quantity = Number(line.querySelector("[data-quote-quantity]").value) || 0;
        const unit = parseAmount(line.querySelector("[data-quote-unit]").value);
        const lineTotal = unit === null ? null : quantity * unit;
        line.querySelector("[data-quote-line-total]").textContent = lineTotal === null ? "" : format(lineTotal);
        if (label !== "" && lineTotal !== null) groupTotal += lineTotal;
      });
      group.querySelector("[data-quote-group-total]").textContent = format(groupTotal);
      quoteTotal += groupTotal;
    });
    if (totalOutput) totalOutput.textContent = format(quoteTotal);
    addGroupButton.hidden = groups().length >= maxGroups;
    groups().forEach((group) => {
      group.querySelector("[data-add-line]").hidden = group.querySelectorAll("[data-quote-line]").length >= maxLines;
    });
  };

  const addLine = (group) => {
    const html = lineTemplate.innerHTML.replaceAll("__G__", group.dataset.groupIndex).replaceAll("__L__", `n${nextIndex++}`);
    group.querySelector("[data-quote-lines]").insertAdjacentHTML("beforeend", html);
    group.querySelector("[data-quote-lines]").lastElementChild.querySelector("input")?.focus();
  };

  container.addEventListener("click", (event) => {
    const target = event.target;
    const group = target.closest("[data-quote-group]");
    if (target.closest("[data-add-line]") && group) {
      addLine(group);
    } else if (target.closest("[data-remove-line]") && group) {
      const lines = group.querySelectorAll("[data-quote-line]");
      const line = target.closest("[data-quote-line]");
      if (lines.length > 1) {
        line.remove();
      } else {
        line.querySelectorAll("input, textarea").forEach((field) => { field.value = field.matches("[data-quote-quantity]") ? "1" : ""; });
      }
    } else if (target.closest("[data-remove-group]") && group) {
      if (groups().length > 1) {
        group.remove();
      } else {
        group.querySelectorAll("input:not([data-quote-quantity]), textarea").forEach((field) => { field.value = ""; });
      }
    } else if (target.closest("[data-add-group]")) {
      const index = `n${nextIndex++}`;
      addGroupButton.insertAdjacentHTML("beforebegin", groupTemplate.innerHTML.replaceAll("__G__", index));
      addGroupButton.previousElementSibling.querySelector("input")?.focus();
    } else {
      return;
    }
    refresh();
  });
  container.addEventListener("input", refresh);
  refresh();
});

// Logistique > Cachet: the fee is computed while typing (contract − costs), as on save.
document.querySelectorAll("[data-fee-calculator]").forEach((form) => {
  const contract = form.querySelector("[data-fee-contract]");
  const costs = form.querySelector("[data-fee-costs]");
  const result = form.querySelector("[data-fee-result]");
  if (!contract || !costs || !result) return;
  const parse = (value) => {
    const match = value.replace(/[\s\u00a0\u202f€]/g, "").replace("\u2212", "-").match(/^(-?)(\d{1,8})(?:[.,](\d{1,2}))?$/);
    return match ? (match[1] === "-" ? -1 : 1) * (Number(match[2]) * 100 + Number((match[3] || "0").padEnd(2, "0"))) : null;
  };
  const money = new Intl.NumberFormat("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const refresh = () => {
    const contractCents = parse(contract.value);
    const costsCents = costs.value.trim() === "" ? 0 : parse(costs.value);
    result.textContent = contractCents === null || costsCents === null
      ? "—"
      : `${money.format((contractCents - costsCents) / 100).replace(/\s/g, "\u00a0")}\u00a0€`;
  };
  form.addEventListener("input", refresh);
});

// Installable application: the service worker (sw.js) shows an offline page when the network is
// missing; the "Installer" button uses the browser prompt where it exists (Chrome, Edge, Android),
// otherwise the page explains how to add the site to the home screen (Safari on iPhone).
if ("serviceWorker" in navigator) {
  window.addEventListener("load", () => navigator.serviceWorker.register("sw.js").catch(() => {}));
}
const isInstalledApp = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
let installPrompt = null;
document.querySelectorAll("[data-install-link]").forEach((link) => { link.hidden = isInstalledApp; });
document.querySelectorAll("[data-install-installed]").forEach((element) => { element.hidden = !isInstalledApp; });
document.querySelectorAll("[data-install-help]").forEach((element) => { element.hidden = isInstalledApp; });
window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  installPrompt = event;
  document.querySelectorAll("[data-install-button]").forEach((button) => { button.hidden = false; });
});
document.querySelectorAll("[data-install-button]").forEach((button) => {
  button.addEventListener("click", async () => {
    if (!installPrompt) return;
    installPrompt.prompt();
    await installPrompt.userChoice;
    installPrompt = null;
    button.hidden = true;
  });
});
window.addEventListener("appinstalled", () => {
  document.querySelectorAll("[data-install-button], [data-install-help]").forEach((element) => { element.hidden = true; });
  document.querySelectorAll("[data-install-installed]").forEach((element) => { element.hidden = false; });
});

// Install banner of the agenda (index.php): on phones only, when the application is not installed
// and the banner was not put off in the last 30 days. Android: "Installer" opens the browser prompt
// (beforeinstallprompt); iPhone/iPad: Safari has no prompt, the banner gives the two steps; other
// phone browsers: the menu entry to use. "Plus tard" hides it for 30 days on this phone.
(() => {
  const banner = document.querySelector("[data-install-banner]");
  if (!banner || isInstalledApp) return;
  const userAgent = navigator.userAgent;
  const isIos = /iPhone|iPad|iPod/.test(userAgent) || (/Macintosh/.test(userAgent) && navigator.maxTouchPoints > 1);
  const isAndroid = /Android/.test(userAgent);
  const isPhone = isIos || isAndroid || window.matchMedia("(pointer: coarse) and (max-width: 900px)").matches;
  const STORAGE_KEY = "deizi-install-later";
  const LATER_DAYS = 30;
  let putOffUntil = 0;
  try { putOffUntil = Number(localStorage.getItem(STORAGE_KEY)) || 0; } catch (error) { putOffUntil = 0; }
  if (!isPhone || Date.now() < putOffUntil) return;

  const button = banner.querySelector("[data-install-banner-button]");
  const show = (variant) => {
    banner.querySelectorAll("[data-install-banner-android], [data-install-banner-ios], [data-install-banner-other]").forEach((line) => {
      line.hidden = !line.matches("[data-install-banner-" + variant + "]");
    });
    banner.hidden = false;
    document.body.classList.add("has-install-banner");
  };
  const hide = () => {
    banner.hidden = true;
    document.body.classList.remove("has-install-banner");
  };

  if (isIos) {
    show("ios");
  } else {
    let prompted = false;
    window.addEventListener("beforeinstallprompt", () => {
      prompted = true;
      button.hidden = false;
      show("android");
    });
    // Without the browser prompt (Firefox, Samsung Internet…), point to the browser menu instead.
    window.setTimeout(() => { if (!prompted && banner.hidden) show("other"); }, 2500);
  }
  button.addEventListener("click", async () => {
    if (!installPrompt) return;
    installPrompt.prompt();
    const choice = await installPrompt.userChoice;
    installPrompt = null;
    if (choice.outcome === "accepted") hide();
  });
  banner.querySelector("[data-install-banner-later]").addEventListener("click", () => {
    try { localStorage.setItem(STORAGE_KEY, String(Date.now() + LATER_DAYS * 86400000)); } catch (error) { /* stays until next visit */ }
    hide();
  });
  window.addEventListener("appinstalled", hide);
})();

// Login page in the installed application: "Rester connecté" is ticked, since closing the
// application ends the session.
document.querySelectorAll("[data-remember]").forEach((box) => { if (isInstalledApp) box.checked = true; });

// Bell of the menu: closes when the member clicks elsewhere or presses Escape. Its number of dates
// becomes the badge of the installed application's icon (where the system supports it).
document.querySelectorAll(".notice-menu").forEach((menu) => {
  document.addEventListener("click", (event) => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
  document.addEventListener("keydown", (event) => { if (event.key === "Escape") menu.open = false; });
  const count = Number(menu.dataset.unreadCount || 0);
  if ("setAppBadge" in navigator) {
    (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
  }
});

// Push notifications of this device (page Mises à jour): the browser asks the member's permission,
// then the subscription is sent to the server, which encrypts each message for this device only.
(() => {
  const panel = document.querySelector("[data-push-panel]");
  if (!panel) return;
  const state = panel.querySelector("[data-push-state]");
  const enable = panel.querySelector("[data-push-enable]");
  const disable = panel.querySelector("[data-push-disable]");
  const show = (message, on, offer) => {
    state.textContent = message;
    state.className = "push-state " + (on ? "is-on" : "is-off");
    enable.hidden = on || !offer;
    disable.hidden = !on;
  };
  const post = (body) => fetch("notifications-action.php", {
    method: "POST",
    credentials: "same-origin",
    body: new URLSearchParams({ csrf_token: panel.dataset.csrf, ...body }),
  });
  const keyBytes = (base64url) => {
    const binary = atob(base64url.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - base64url.length % 4) % 4));
    return Uint8Array.from(binary, (character) => character.charCodeAt(0));
  };
  const toBase64url = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  const isIos = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);

  if (!("serviceWorker" in navigator) || !("PushManager" in window) || !("Notification" in window)) {
    show(isIos && !isInstalledApp
      ? "Installez d’abord l’application (voir ci-dessus), puis activez les notifications depuis cette page dans l’application."
      : "Ce navigateur ne permet pas les notifications : la cloche en haut de page signale les nouveautés.", false, false);
    return;
  }
  const refresh = async () => {
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (Notification.permission === "denied") {
      show("Les notifications sont bloquées pour ce site dans les réglages du navigateur ou du téléphone : la cloche en haut de page signale les nouveautés.", false, false);
    } else if (subscription) {
      // Sent again at each visit: the server keeps it if it was removed meanwhile.
      post({ action: "subscribe", ...subscriptionFields(subscription) }).catch(() => {});
      show("✓ Les notifications sont activées sur cet appareil.", true, true);
    } else {
      show("Les notifications ne sont pas activées sur cet appareil.", false, true);
    }
  };
  const subscriptionFields = (subscription) => ({
    endpoint: subscription.endpoint,
    p256dh: toBase64url(subscription.getKey("p256dh")),
    auth: toBase64url(subscription.getKey("auth")),
  });
  enable.addEventListener("click", async () => {
    enable.disabled = true;
    try {
      if (await Notification.requestPermission() !== "granted") {
        await refresh();
        return;
      }
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(panel.dataset.pushKey) });
      const response = await post({ action: "subscribe", ...subscriptionFields(subscription) });
      if (!response.ok) {
        await subscription.unsubscribe();
        show("L’activation a échoué. Réessayez plus tard.", false, true);
        return;
      }
      await refresh();
    } catch (error) {
      show("L’activation a échoué (" + error.message + ").", false, true);
    } finally {
      enable.disabled = false;
    }
  });
  disable.addEventListener("click", async () => {
    disable.disabled = true;
    try {
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.getSubscription();
      if (subscription) {
        await post({ action: "unsubscribe", endpoint: subscription.endpoint });
        await subscription.unsubscribe();
      }
      await refresh();
    } finally {
      disable.disabled = false;
    }
  });
  // The service worker may never become ready (private browsing, blocked site data).
  const unavailable = setTimeout(() => show("Les notifications ne sont pas disponibles ici : la cloche en haut de page signale les nouveautés.", false, false), 6000);
  refresh()
    .catch(() => show("Les notifications ne sont pas disponibles ici.", false, false))
    .finally(() => clearTimeout(unavailable));
})();

// Announcement form: "Tout le monde" makes the choice of sections unnecessary.
document.querySelectorAll("[data-announcement-everyone]").forEach((everyone) => {
  const sections = everyone.closest("form").querySelector("[data-announcement-sections]");
  const update = () => {
    sections.classList.toggle("is-disabled", everyone.checked);
    sections.querySelectorAll("input").forEach((input) => { input.disabled = everyone.checked; });
  };
  everyone.addEventListener("change", update);
  update();
});

// Announcement form: the folded options (text, link, period, audience) are summed up on the line
// that opens them, and open by themselves when one of their fields stops the sending.
document.querySelectorAll("[data-announcement-options]").forEach((options) => {
  const form = options.closest("form");
  const summary = options.querySelector("[data-announcement-summary]");
  const day = (value) => (value ? value.split("-").reverse().join("/") : "");
  const update = () => {
    const everyone = form.querySelector("[data-announcement-everyone]");
    let audience = [];
    if (everyone && everyone.checked) {
      audience = ["Tout le monde"];
    } else {
      form.querySelectorAll(".section-assignment-group").forEach((group) => {
        const parent = group.querySelector("[data-section-parent]");
        if (parent && parent.checked) {
          audience.push(parent.parentElement.textContent.replace("— toutes les sous-sections", "").trim());
        } else {
          group.querySelectorAll("[data-section-child]:checked").forEach((child) => audience.push(child.parentElement.textContent.trim()));
        }
      });
    }
    const starts = day(form.querySelector('[name="starts"]').value);
    const ends = day(form.querySelector('[name="ends"]').value);
    summary.textContent = "Visible par : " + (audience.length ? audience.join(", ") : "personne pour l’instant")
      + " · " + (ends ? "du " + starts + " au " + ends : "du " + starts + ", sans date de fin");
  };
  form.addEventListener("change", update);
  form.addEventListener("input", update);
  options.addEventListener("invalid", () => { options.open = true; }, true);
  update();
});

// Masonry of the Configuration page: the groups marked data-masonry-first (À propos, Membres,
// Documents) open the columns, in their order, each under the shortest column; the others are
// shared out among the columns so that the tallest column is as short as possible (every sharing
// is tried: there are only a few groups). As many columns as fit (460 px each), one on
// phones (plain grid then, in the order of the page).
document.querySelectorAll("[data-masonry]").forEach((container) => {
  const items = [...container.children];
  const minWidth = 460;
  const gap = 18;
  let columnCount = 0;
  const layout = () => {
    const count = Math.max(1, Math.floor((container.clientWidth + gap) / (minWidth + gap)));
    if (count === columnCount) return;
    columnCount = count;
    container.querySelectorAll(".settings-masonry-column").forEach((column) => column.remove());
    if (count === 1) {
      container.classList.remove("is-masonry");
      items.forEach((item) => container.append(item));
      return;
    }
    container.classList.add("is-masonry");
    const columns = Array.from({ length: count }, () => {
      const column = document.createElement("div");
      column.className = "settings-masonry-column";
      container.append(column);
      return column;
    });
    // Heights measured at the width of a column, then the tallest groups placed first.
    items.forEach((item) => columns[0].append(item));
    const heights = new Map(items.map((item) => [item, item.offsetHeight]));
    const filled = columns.map(() => 0);
    const first = items.filter((item) => item.hasAttribute("data-masonry-first"));
    const others = items.filter((item) => !item.hasAttribute("data-masonry-first"));
    first.forEach((item) => {
      const index = filled.indexOf(Math.min(...filled));
      columns[index].append(item);
      filled[index] += heights.get(item) + gap;
    });
    let best = null;
    let bestHeight = Infinity;
    const choice = new Array(others.length).fill(0);
    const tryFrom = (position, totals) => {
      if (position === others.length) {
        const tallest = Math.max(...totals);
        if (tallest < bestHeight) {
          bestHeight = tallest;
          best = [...choice];
        }
        return;
      }
      for (let index = 0; index < count; index += 1) {
        choice[position] = index;
        const next = [...totals];
        next[index] += heights.get(others[position]) + gap;
        if (Math.max(...next) < bestHeight) tryFrom(position + 1, next);
      }
    };
    tryFrom(0, filled);
    others.forEach((item, position) => columns[best ? best[position] : position % count].append(item));
  };
  layout();
  window.addEventListener("resize", layout);
});

// Trombinoscope: a thumbnail opens the portrait version enlarged.
document.addEventListener("click", (event) => {
  const link = event.target.closest("[data-photo-zoom]");
  if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || typeof HTMLDialogElement === "undefined") return;
  event.preventDefault();
  const dialog = document.createElement("dialog");
  dialog.className = "photo-dialog";
  const image = document.createElement("img");
  image.src = link.href;
  image.alt = t("Photo de {name}", { name: link.dataset.photoName });
  const bar = document.createElement("div");
  bar.className = "photo-dialog-bar";
  const name = document.createElement("span");
  name.textContent = link.dataset.photoName;
  const close = document.createElement("button");
  close.type = "button";
  close.className = "text-button";
  close.textContent = t("Fermer");
  close.addEventListener("click", () => dialog.close());
  bar.append(name, close);
  dialog.append(image, bar);
  dialog.addEventListener("click", (click) => { if (click.target === dialog) dialog.close(); });
  dialog.addEventListener("close", () => { dialog.remove(); link.focus(); });
  document.body.append(dialog);
  dialog.showModal();
});
