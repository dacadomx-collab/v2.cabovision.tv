// assets/js/categorias.js — Panel de Categorías (Admin). Reutiliza
// getAuthSession()/redirectToLogin() ya globales por assets/js/admin.js.

document.addEventListener('DOMContentLoaded', () => {
    const session = getAuthSession();
    if (!session || !session.accessToken) {
        redirectToLogin();
        return;
    }

    document.getElementById('logout-btn')?.addEventListener('click', redirectToLogin);

    const esc = (value) => String(value ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const form = document.getElementById('category-form');
    const feedbackEl = document.getElementById('category-feedback');
    const parentSelect = document.getElementById('parent_id');
    const tableBody = document.getElementById('categories-table-body');
    const formTitle = document.getElementById('form-title');
    const submitBtn = document.getElementById('submit-btn');
    const cancelBtn = document.getElementById('cancel-edit-btn');
    const idField = document.getElementById('category_id');

    let allCategories = [];

    function resetForm() {
        form.reset();
        idField.value = '';
        formTitle.textContent = 'Crear Categoría';
        submitBtn.textContent = 'Crear categoría';
        cancelBtn.hidden = true;
    }

    cancelBtn.addEventListener('click', resetForm);

    async function loadCategories() {
        try {
            const response = await fetch(`${window.BASE_PATH}/api/categories_admin_list.php`, {
                headers: { Authorization: `Bearer ${session.accessToken}` },
            });
            if (response.status === 401) {
                redirectToLogin();
                return;
            }
            if (response.status === 403) {
                tableBody.innerHTML = '<tr><td colspan="4">Tu rol no tiene acceso a este panel — solo Admin.</td></tr>';
                form.hidden = true;
                return;
            }
            const result = await response.json();
            if (result.status !== 'success') {
                tableBody.innerHTML = `<tr><td colspan="4">${esc(result.message || 'Error al cargar categorías.')}</td></tr>`;
                return;
            }

            allCategories = result.data.categories;

            parentSelect.innerHTML = '<option value="">— Ninguna (categoría de primer nivel) —</option>'
                + allCategories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');

            tableBody.innerHTML = allCategories.length
                ? allCategories.map((c) => `
                    <tr>
                        <td>${esc(c.name)}</td>
                        <td>${esc(c.parent_name || '—')}</td>
                        <td>${esc(c.status)}</td>
                        <td><a class="edit-link" href="#" data-id="${c.id}">Editar</a></td>
                    </tr>
                `).join('')
                : '<tr><td colspan="4">Sin categorías registradas.</td></tr>';
        } catch {
            tableBody.innerHTML = '<tr><td colspan="4">No se pudo contactar al servidor.</td></tr>';
        }
    }

    tableBody.addEventListener('click', (event) => {
        const link = event.target.closest('a.edit-link');
        if (!link) return;
        event.preventDefault();
        const category = allCategories.find((c) => String(c.id) === link.dataset.id);
        if (!category) return;

        idField.value = category.id;
        document.getElementById('name').value = category.name;
        document.getElementById('description').value = category.description || '';
        parentSelect.value = category.parent_id || '';
        document.getElementById('publish').checked = String(category.status).toLowerCase() === 'publicada';

        formTitle.textContent = `Editando: ${category.name}`;
        submitBtn.textContent = 'Guardar cambios';
        cancelBtn.hidden = false;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        feedbackEl.hidden = true;

        const isEdit = idField.value !== '';
        const payload = {
            name: document.getElementById('name').value.trim(),
            description: document.getElementById('description').value.trim(),
            parent_id: parentSelect.value ? Number(parentSelect.value) : null,
            publish: document.getElementById('publish').checked,
        };
        if (isEdit) {
            payload.id = Number(idField.value);
        }

        const endpoint = isEdit ? 'categories_update.php' : 'categories_create.php';

        try {
            const response = await fetch(`${window.BASE_PATH}/api/${endpoint}`, {
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
                resetForm();
                loadCategories();
            }
        } catch {
            feedbackEl.textContent = 'No se pudo contactar al servidor.';
            feedbackEl.hidden = false;
        }
    });

    loadCategories();
});
