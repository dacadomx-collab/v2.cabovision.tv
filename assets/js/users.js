// assets/js/users.js — Panel de Usuarios (Super Admin). Reutiliza
// getAuthSession()/redirectToLogin() ya globales por assets/js/admin.js.

document.addEventListener('DOMContentLoaded', () => {
    const session = getAuthSession();
    if (!session || !session.accessToken) {
        redirectToLogin();
        return;
    }

    document.getElementById('logout-btn')?.addEventListener('click', redirectToLogin);

    const esc = (value) => String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const roleSelect = document.getElementById('role_id');
    const tableBody = document.getElementById('users-table-body');

    async function loadUsers() {
        try {
            const response = await fetch('/CaboVision.tv/api/users_list.php', {
                headers: { Authorization: `Bearer ${session.accessToken}` },
            });
            if (response.status === 401) {
                redirectToLogin();
                return;
            }
            if (response.status === 403) {
                tableBody.innerHTML = '<tr><td colspan="5">Tu rol no tiene acceso a este panel — solo Admin.</td></tr>';
                document.getElementById('user-form').hidden = true;
                return;
            }
            const result = await response.json();
            if (result.status !== 'success') {
                tableBody.innerHTML = `<tr><td colspan="5">${esc(result.message || 'Error al cargar usuarios.')}</td></tr>`;
                return;
            }

            const { users, roles } = result.data;

            roleSelect.innerHTML = roles.map((r) => `<option value="${r.id}">${esc(r.name)}</option>`).join('');

            tableBody.innerHTML = users.length
                ? users.map((u) => `
                    <tr>
                        <td>${esc(u.name)}</td>
                        <td>${esc(u.email)}</td>
                        <td>${esc(u.roles || '—')}</td>
                        <td>${esc(u.status)}</td>
                        <td>${esc(u.created_at)}</td>
                    </tr>
                `).join('')
                : '<tr><td colspan="5">Sin usuarios registrados.</td></tr>';
        } catch {
            tableBody.innerHTML = '<tr><td colspan="5">No se pudo contactar al servidor.</td></tr>';
        }
    }

    document.getElementById('user-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const feedbackEl = document.getElementById('user-feedback');
        feedbackEl.hidden = true;
        document.querySelector('.temp-password-box')?.remove();

        const payload = {
            name: document.getElementById('name').value.trim(),
            email: document.getElementById('email').value.trim(),
            role_id: Number(roleSelect.value),
            send_welcome_email: document.getElementById('send_welcome_email').checked,
        };

        try {
            const response = await fetch('/CaboVision.tv/api/users_create.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${session.accessToken}`,
                },
                body: JSON.stringify(payload),
            });

            if (response.status === 401) {
                redirectToLogin();
                return;
            }

            const result = await response.json();
            feedbackEl.textContent = result.message;
            feedbackEl.hidden = false;

            if (result.status === 'success') {
                const box = document.createElement('div');
                box.className = 'temp-password-box';
                box.innerHTML = result.data.welcome_email_sent
                    ? `Contraseña temporal enviada por correo a ${esc(result.data.email)}.`
                    : `El correo de bienvenida no se pudo entregar (esperado en localhost) — comparte esta contraseña temporal manualmente: <code>${esc(result.data.temp_password)}</code>`;
                feedbackEl.insertAdjacentElement('afterend', box);

                document.getElementById('user-form').reset();
                loadUsers();
            }
        } catch {
            feedbackEl.textContent = 'No se pudo contactar al servidor.';
            feedbackEl.hidden = false;
        }
    });

    loadUsers();
});
