"use strict";

const csrf = document.querySelector('meta[name="csrf-token"]').content;
const notice = document.getElementById("notice");
const historyKey = "securebox.history.v1";
const maxBytes = 50 * 1024 * 1024;
const date = (value) =>
  new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
const size = (bytes) =>
  bytes >= 1048576
    ? `${(bytes / 1048576).toFixed(1)} Mo`
    : `${Math.max(1, Math.round(bytes / 1024))} Ko`;

function message(text, success = false) {
  notice.textContent = text;
  notice.classList.toggle("success", success);
  notice.hidden = !text;
}

async function api(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    headers: { "X-CSRF-Token": csrf, ...options.headers },
    signal: AbortSignal.timeout(15000),
  });
  const data = response.status === 204 ? null : await response.json();
  if (!response.ok) {
    const error = new Error(data?.error || "La requête a échoué.");
    error.status = response.status;
    throw error;
  }
  return data;
}

async function copy(value) {
  try {
    await navigator.clipboard.writeText(value);
    message("Lien copié.", true);
  } catch {
    message("La copie est indisponible. Sélectionne le lien pour le copier.");
  }
}

function readHistory() {
  try {
    const values = JSON.parse(localStorage.getItem(historyKey) || "[]");
    if (!Array.isArray(values)) return [];
    return values
      .filter(
        (item) =>
          item &&
          /^[A-Za-z0-9_-]{43}$/.test(item.token) &&
          /^[A-Za-z0-9_-]{43}$/.test(item.ownerToken) &&
          typeof item.filename === "string" &&
          Number.isFinite(item.size) &&
          Number.isFinite(Date.parse(item.expiresAt)),
      )
      .slice(0, 50);
  } catch {
    return [];
  }
}

const sender = document.getElementById("sender");
if (sender) {
  const form = document.getElementById("transfer-form");
  const fileInput = document.getElementById("file");
  const dropZone = document.getElementById("drop-zone");
  const submit = document.getElementById("submit-transfer");
  const protect = document.getElementById("protect");
  const password = document.getElementById("password");
  const result = document.getElementById("result");
  const hours = document.getElementById("hours");
  let selectedFile = null;
  let sending = false;
  let history = readHistory();

  function persist() {
    try {
      localStorage.setItem(historyKey, JSON.stringify(history));
    } catch {
      message(
        "L’historique ne peut pas être conservé dans ce navigateur. Garde une copie du lien.",
      );
    }
    renderHistory();
  }

  function selectFile(file) {
    if (sending) return;
    if (file && (file.size < 1 || file.size > maxBytes)) {
      message("Choisis un fichier non vide de 50 Mo maximum.");
      return;
    }
    selectedFile = file;
    document.getElementById("file-heading").textContent = file
      ? file.name
      : "Le fichier va ici.";
    document.getElementById("file-description").textContent = file
      ? `${size(file.size)} · Prêt à envoyer`
      : "Glisse-le dans la boîte ou utilise le bouton.";
    document.getElementById("choose-file").hidden = Boolean(file);
    document.getElementById("clear-file").hidden = !file;
    submit.disabled = !file;
    if (!file) fileInput.value = "";
    message("");
  }

  function showView(view) {
    document.getElementById("send-view").hidden = view !== "send";
    document.getElementById("history-view").hidden = view !== "history";
    document
      .getElementById("show-send")
      .classList.toggle("active", view === "send");
    document
      .getElementById("show-history")
      .classList.toggle("active", view === "history");
    message("");
    if (view === "history") refreshHistory();
  }

  function renderHistory() {
    document.getElementById("history-count").textContent = history.length;
    const list = document.getElementById("history-list");
    list.replaceChildren();
    document.getElementById("history-empty").hidden = history.length > 0;
    for (const item of history) {
      const row = document
        .getElementById("history-row")
        .content.firstElementChild.cloneNode(true);
      const expired = Date.parse(item.expiresAt) <= Date.now();
      const unavailable = expired || item.unavailable;
      row.querySelector("h2").textContent = item.filename;
      row.querySelector(".history-meta").textContent =
        `${size(item.size)} · ${item.protected ? "Avec mot de passe" : "Sans mot de passe"} · Expire le ${date(item.expiresAt)}`;
      const state = row.querySelector(".history-state");
      state.textContent = expired
        ? "Expiré"
        : item.unavailable
          ? "Indisponible"
          : "Actif";
      state.classList.toggle("unavailable", Boolean(unavailable));
      row.querySelector(".history-downloads").textContent = unavailable
        ? "— téléchargement"
        : `${item.downloads} téléchargement(s)`;
      const link = `${location.origin}/t/${item.token}`;
      row.querySelector(".copy-link").hidden = Boolean(unavailable);
      row
        .querySelector(".copy-link")
        .addEventListener("click", () => copy(link));
      row.querySelector(".revoke-link").hidden = Boolean(unavailable);
      row.querySelector(".revoke-link").addEventListener("click", async () => {
        if (
          !confirm(
            `Révoquer « ${item.filename} » ? Le fichier sera supprimé du serveur.`,
          )
        )
          return;
        try {
          await api(`/api/transfers/${item.token}`, {
            method: "DELETE",
            headers: { "X-Owner-Token": item.ownerToken },
          });
          item.unavailable = true;
          persist();
          message("Transfert révoqué. Le fichier a été supprimé.", true);
        } catch (error) {
          message(error.message);
        }
      });
      row.querySelector(".forget-link").addEventListener("click", () => {
        if (
          !confirm(
            "Retirer cet envoi de l’historique de ce navigateur ? Le lien restera actif jusqu’à son expiration.",
          )
        )
          return;
        history = history.filter((record) => record.token !== item.token);
        persist();
      });
      list.append(row);
    }
  }

  async function refreshHistory() {
    const button = document.getElementById("refresh-history");
    button.disabled = true;
    await Promise.all(
      history.map(async (item) => {
        try {
          const updated = await api(`/api/transfers/${item.token}`, {
            headers: { "X-Owner-Token": item.ownerToken },
          });
          Object.assign(item, updated, { unavailable: false });
        } catch (error) {
          if ([404, 410].includes(error.status)) item.unavailable = true;
          else
            message(
              "L’actualisation a échoué. Les informations affichées datent du dernier relevé.",
            );
        }
      }),
    );
    persist();
    button.disabled = false;
  }

  document
    .getElementById("show-send")
    .addEventListener("click", () => showView("send"));
  document
    .getElementById("show-history")
    .addEventListener("click", () => showView("history"));
  document
    .getElementById("refresh-history")
    .addEventListener("click", refreshHistory);
  document
    .getElementById("choose-file")
    .addEventListener("click", () => fileInput.click());
  document.getElementById("clear-file").addEventListener("click", () => {
    selectFile(null);
    fileInput.click();
  });
  fileInput.addEventListener("change", () =>
    selectFile(fileInput.files[0] || null),
  );
  for (const name of ["dragenter", "dragover"])
    dropZone.addEventListener(name, (event) => {
      event.preventDefault();
      dropZone.classList.add("dragging");
    });
  for (const name of ["dragleave", "drop"])
    dropZone.addEventListener(name, (event) => {
      event.preventDefault();
      dropZone.classList.remove("dragging");
    });
  dropZone.addEventListener("drop", (event) => {
    if (event.dataTransfer.files.length !== 1) {
      message("Dépose un seul fichier à la fois.");
      return;
    }
    selectFile(event.dataTransfer.files[0]);
  });
  document.querySelectorAll("[data-hours]").forEach((button) =>
    button.addEventListener("click", () => {
      hours.value = button.dataset.hours;
      hours.dispatchEvent(new Event("input"));
    }),
  );
  hours.addEventListener("input", () =>
    document
      .querySelectorAll("[data-hours]")
      .forEach((button) =>
        button.classList.toggle(
          "selected",
          button.dataset.hours === hours.value,
        ),
      ),
  );
  protect.addEventListener("change", () => {
    document.getElementById("password-fields").hidden = !protect.checked;
    password.disabled = !protect.checked;
    password.required = protect.checked;
    if (protect.checked) password.focus();
    else password.value = "";
  });
  document
    .getElementById("toggle-password")
    .addEventListener("click", (event) => {
      password.type = password.type === "password" ? "text" : "password";
      event.currentTarget.textContent =
        password.type === "password" ? "Voir" : "Masquer";
    });

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    if (!selectedFile || sending) return;
    sending = true;
    submit.disabled = true;
    message("");
    document.getElementById("upload-progress").hidden = false;
    const progress = document.getElementById("progress");
    const label = document.getElementById("progress-label");
    progress.value = 0;
    label.textContent = "Envoi en cours…";
    const data = new FormData();
    data.append("file", selectedFile);
    data.append("hours", hours.value);
    data.append("password", protect.checked ? password.value : "");
    const request = new XMLHttpRequest();
    request.open("POST", "/api/transfers");
    request.setRequestHeader("X-CSRF-Token", csrf);
    request.responseType = "json";
    request.timeout = 90000;
    request.upload.addEventListener("progress", (event) => {
      if (!event.lengthComputable) return;
      progress.value = Math.round((event.loaded / event.total) * 100);
      label.textContent =
        progress.value === 100
          ? "Envoi reçu, chiffrement en cours…"
          : `Envoi : ${progress.value} %`;
    });
    const finish = () => {
      sending = false;
      submit.disabled = !selectedFile;
      document.getElementById("upload-progress").hidden = true;
    };
    request.addEventListener("load", () => {
      finish();
      if (request.status !== 201 || !request.response) {
        message(
          request.response?.error ||
            "L’envoi a échoué. Réessaie dans un instant.",
        );
        return;
      }
      const transfer = request.response;
      history = [transfer, ...history].slice(0, 50);
      persist();
      form.hidden = true;
      result.hidden = false;
      document.getElementById("result-file").textContent =
        `${transfer.filename} · ${size(transfer.size)}`;
      document.getElementById("share-url").value = transfer.url;
      document.getElementById("result-details").textContent =
        `Disponible jusqu’au ${date(transfer.expiresAt)} · ${transfer.protected ? "Protégé par mot de passe" : "Accessible avec le lien"}`;
      password.value = "";
    });
    request.addEventListener("error", () => {
      finish();
      message(
        "Le serveur ne répond pas. Vérifie la connexion avant de réessayer.",
      );
    });
    request.addEventListener("timeout", () => {
      finish();
      message(
        "L’envoi a pris trop de temps. Vérifie l’historique avant de réessayer.",
      );
    });
    request.send(data);
  });
  document
    .getElementById("copy-result")
    .addEventListener("click", () =>
      copy(document.getElementById("share-url").value),
    );
  document.getElementById("new-transfer").addEventListener("click", () => {
    form.reset();
    selectFile(null);
    protect.dispatchEvent(new Event("change"));
    hours.dispatchEvent(new Event("input"));
    form.hidden = false;
    result.hidden = true;
  });
  renderHistory();
}

const downloadForm = document.getElementById("download-form");
if (downloadForm)
  downloadForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = document.getElementById("download-button");
    const status = document.getElementById("download-status");
    button.disabled = true;
    status.textContent = "Préparation du téléchargement…";
    try {
      const response = await fetch(
        `/api/transfers/${downloadForm.dataset.token}/download`,
        {
          method: "POST",
          headers: { "X-CSRF-Token": csrf },
          body: new FormData(downloadForm),
          signal: AbortSignal.timeout(60000),
        },
      );
      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.error || "Téléchargement impossible.");
      }
      const blob = await response.blob();
      const disposition = response.headers.get("Content-Disposition") || "";
      const encoded = disposition.match(/filename\*=utf-8''([^;]+)/i);
      const plain = disposition.match(/filename="([^"]+)"/i);
      const filename = encoded
        ? decodeURIComponent(encoded[1])
        : plain
          ? plain[1]
          : "fichier";
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 30000);
      status.textContent = "Fichier reçu par le navigateur.";
      const password = document.getElementById("download-password");
      if (password) password.value = "";
    } catch (error) {
      status.textContent = error.message || "Le téléchargement a échoué.";
    } finally {
      button.disabled = false;
    }
  });
