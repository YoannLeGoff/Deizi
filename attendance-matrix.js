const matrixForm = document.querySelector("[data-attendance-matrix]");
// Texts in the language of the page (menu.js, JS_TEXTS in lib/i18n.php).
const t = (key, values) => (window.t ? window.t(key, values) : key);

if (matrixForm) {
  const table = matrixForm.querySelector(".attendance-matrix");
  const selects = [...matrixForm.querySelectorAll(".matrix-select")];
  const savebar = matrixForm.querySelector("[data-savebar]");
  const savebarStatus = matrixForm.querySelector("[data-savebar-status]");
  // Présent → Absent → Je ne sais pas → Sans réponse ("", the answer is withdrawn) → Présent.
  const cycle = ["present", "absent", "undecided", ""];
  const labels = { present: t("present"), absent: t("absent"), undecided: t("je_sais"), "": t("sans_reponse") };
  const shortcuts = { p: "present", a: "absent", n: "undecided", "?": "undecided", s: "", delete: "", backspace: "" };
  const icons = {
    present: '<path d="M5 12.5l4.2 4.2L19 7"/>',
    absent: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
    undecided: '<path d="M9.2 9.2a2.9 2.9 0 1 1 4.3 2.5c-.9.5-1.5 1.2-1.5 2.3v.5"/><path d="M12 18h.01"/>',
    "": "",
  };
  const buttons = new Map();
  // Automatic saving: each change is sent on its own, shortly after the last click on the cell.
  const csrfToken = matrixForm.querySelector('input[name="csrf_token"]').value;
  const returnEvent = matrixForm.querySelector('input[name="return_event"]')?.value ?? "";
  const timers = new Map();
  const inFlight = new Set();
  let lastError = "";
  let lastSavedAt = null;

  function render(select) {
    const button = buttons.get(select);
    const value = select.value;
    button.dataset.value = value;
    button.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[value]}</svg>`;
    const dirty = value !== select.dataset.original;
    const audit = dirty ? t("matrice_enregistrement_en_cours") : select.dataset.audit;
    button.setAttribute("aria-label", t("matrice_case_label", { member: select.dataset.label, answer: labels[value], audit }));
    button.title = select.disabled
      ? `${labels[value]} — ${audit}\n${t("matrice_non_modifiable")}`
      : `${labels[value]} — ${audit}\n${t("matrice_cliquer_changer")}`;
    select.closest(".matrix-cell").classList.toggle("is-dirty", dirty);
  }

  function refresh() {
    matrixForm.querySelectorAll("[data-count-event]").forEach((cell) => {
      const scope = selects.filter(
        (select) =>
          select.dataset.event === cell.dataset.countEvent &&
          select.dataset.subsection === cell.dataset.countSubsection,
      );
      const present = scope.filter((select) => select.value === "present").length;
      cell.textContent = `${present}/${scope.length}`;
    });

    const changed = new Set(
      selects.filter((select) => select.value !== select.dataset.original).map((select) => select.name),
    ).size;
    if (lastError !== "") {
      savebarStatus.textContent = lastError;
    } else if (changed > 0) {
      savebarStatus.textContent = t("matrice_enregistrement");
    } else if (lastSavedAt !== null) {
      savebarStatus.textContent = t("matrice_derniere_enregistree", { time: lastSavedAt });
    } else {
      savebarStatus.textContent = t("matrice_enregistrement_automatique");
    }
    savebar.classList.toggle("has-changes", changed > 0);
    savebar.classList.toggle("has-error", lastError !== "");
  }

  function sameMember(select) {
    return selects.filter((other) => other.name === select.name);
  }

  function scheduleSave(select) {
    clearTimeout(timers.get(select.name)?.timer);
    timers.set(select.name, { select, timer: setTimeout(() => save(select), 600) });
  }

  async function save(select) {
    clearTimeout(timers.get(select.name)?.timer);
    timers.delete(select.name);
    if (inFlight.has(select.name)) {
      // One request at a time per member; the latest value is sent once this one is done.
      scheduleSave(select);
      return;
    }
    const value = select.value;
    const original = select.dataset.original;
    if (value === original) {
      refresh();
      return;
    }
    inFlight.add(select.name);
    const body = new FormData();
    body.append("csrf_token", csrfToken);
    body.append("ajax", "1");
    body.append("return_event", returnEvent);
    body.append(select.name, value);
    body.append("originals", JSON.stringify({ [select.dataset.event]: { [select.dataset.member]: original } }));
    try {
      const response = await fetch(matrixForm.action, {
        method: "POST",
        body,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        // Lets the request finish when the page is left right after a click.
        keepalive: true,
      });
      const result = await response.json().catch(() => null);
      if (!result) {
        throw new Error(t("matrice_session_expiree"));
      }
      if (!result.ok) {
        throw new Error(result.message || t("matrice_reponse_non_enregistree"));
      }
      sameMember(select).forEach((other) => {
        other.dataset.original = value;
        if (result.audit) {
          other.dataset.audit = result.audit.text;
          other.closest(".matrix-cell").classList.toggle("is-proxy", result.audit.proxy);
        }
        if (other.value === value) render(other);
      });
      lastError = "";
      lastSavedAt = new Date().toLocaleTimeString(document.documentElement.lang || undefined, { hour: "2-digit", minute: "2-digit" });
    } catch (error) {
      // Back to the last saved answer, so the screen never shows an answer that is not saved.
      sameMember(select).forEach((other) => {
        other.value = original;
        render(other);
      });
      lastError = error instanceof TypeError ? t("matrice_connexion_impossible") : error.message;
    } finally {
      inFlight.delete(select.name);
      refresh();
    }
  }

  function setValue(select, value) {
    // A member listed under several sub-sections shares one value across all their cells.
    sameMember(select).forEach((other) => {
      other.value = value;
      render(other);
    });
    lastError = "";
    refresh();
    scheduleSave(select);
  }

  function focusCell(cell) {
    const button = cell?.querySelector(".matrix-toggle");
    if (button) button.focus();
    return Boolean(button);
  }

  function move(button, key) {
    const cell = button.closest("td");
    if (key === "ArrowLeft" || key === "ArrowRight") {
      let next = cell;
      do {
        next = key === "ArrowRight" ? next.nextElementSibling : next.previousElementSibling;
      } while (next && !focusCell(next));
      return;
    }
    let row = cell.parentElement;
    do {
      row = key === "ArrowDown" ? row.nextElementSibling : row.previousElementSibling;
    } while (row && !focusCell(row.cells[cell.cellIndex]));
  }

  selects.forEach((select) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "matrix-toggle";
    // Finished, cancelled or refused date: the answer is shown, the icon is disabled.
    button.disabled = select.disabled;
    buttons.set(select, button);
    select.after(button);

    button.addEventListener("click", () => {
      setValue(select, cycle[(cycle.indexOf(select.value) + 1) % cycle.length]);
    });
    button.addEventListener("keydown", (event) => {
      if (event.key.startsWith("Arrow")) {
        event.preventDefault();
        move(button, event.key);
        return;
      }
      const value = shortcuts[event.key.toLowerCase()];
      if (value !== undefined && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        setValue(select, value);
      }
    });
    render(select);
  });

  table.classList.add("is-enhanced");
  // Saving is automatic: the manual buttons are only needed without JavaScript.
  matrixForm.querySelector("[data-manual-save]")?.setAttribute("hidden", "");
  refresh();

  window.addEventListener("pagehide", () => {
    // Changes still waiting for their delay are sent right away.
    [...timers.values()].forEach(({ select }) => save(select));
  });
}
