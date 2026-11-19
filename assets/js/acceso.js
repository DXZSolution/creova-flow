document.addEventListener("DOMContentLoaded", async () => {
    const loginPanel = document.getElementById("loginPanel");
    const registerPanel = document.getElementById("registerPanel");
    const alertBox = document.getElementById("authAlert");

    function showPanel(name) {
        const register = name === "registro";
        loginPanel.classList.toggle("d-none", register);
        registerPanel.classList.toggle("d-none", !register);
        document.querySelectorAll("[data-auth]").forEach(button => {
            button.classList.toggle("btn-primary", button.dataset.auth === name);
            button.classList.toggle("btn-outline-light", button.dataset.auth !== name);
        });
    }

    function showMessage(message, ok) {
        alertBox.textContent = message || "";
        alertBox.classList.toggle("d-none", !message);
        alertBox.classList.toggle("is-ok", Boolean(ok));
    }

    document.querySelectorAll("[data-auth]").forEach(button => {
        button.addEventListener("click", () => {
            showMessage("");
            showPanel(button.dataset.auth);
            history.replaceState({}, "", button.dataset.auth === "registro" ? "#registro" : "#entrar");
        });
    });

    showPanel(window.location.hash === "#registro" ? "registro" : "entrar");

    async function ensureToken() {
        if (FlowApi.token) {
            return;
        }

        await FlowApi.request("session");
    }

    document.getElementById("loginPanel").addEventListener("submit", async event => {
        event.preventDefault();
        showMessage("");

        try {
            await ensureToken();
            const data = await FlowApi.request("login", {
                method: "POST",
                body: {
                    correo: document.getElementById("loginEmail").value.trim(),
                    password: document.getElementById("loginPassword").value
                }
            });
            window.location.href = data.redirect;
        } catch (error) {
            showMessage(error.message);
        }
    });

    document.getElementById("registerPanel").addEventListener("submit", async event => {
        event.preventDefault();
        showMessage("");

        try {
            await ensureToken();
            const data = await FlowApi.request("register", {
                method: "POST",
                body: {
                    nombre: document.getElementById("registerName").value.trim(),
                    correo: document.getElementById("registerEmail").value.trim(),
                    password: document.getElementById("registerPassword").value,
                    passwordConfirmacion: document.getElementById("registerPassword2").value,
                    terminos: document.getElementById("registerTerms").checked
                }
            });
            window.location.href = data.redirect;
        } catch (error) {
            showMessage(error.message);
        }
    });

    try {
        const session = await FlowApi.request("session");

        if (session.user) {
            window.location.href = session.user.rol === "admin" ? "index.php" : "portal.php?seccion=ofertas";
        }
    } catch (error) {
        showMessage(error.message);
    }
});
