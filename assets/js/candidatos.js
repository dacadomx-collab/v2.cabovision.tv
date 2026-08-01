// assets/js/candidatos.js — Panel de Candidatos (Admin/Autor/Editor). Reutiliza
// getAuthSession()/redirectToLogin() ya globales por assets/js/admin.js.

document.addEventListener('DOMContentLoaded', () => {
    const session = getAuthSession();
    if (!session || !session.accessToken) {
        redirectToLogin();
        return;
    }

    document.getElementById('logout-btn')?.addEventListener('click', redirectToLogin);

    const esc = (value) => String(value ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const form = document.getElementById('candidate-form');
    const feedbackEl = document.getElementById('candidate-feedback');
    const tableBody = document.getElementById('candidates-table-body');
    const formTitle = document.getElementById('form-title');
    const submitBtn = document.getElementById('submit-btn');
    const cancelBtn = document.getElementById('cancel-edit-btn');
    const idField = document.getElementById('candidate_id');
    const searchInput = document.getElementById('search-input');
    const photoInput = document.getElementById('photo');
    const photoPreview = document.getElementById('image-preview');

    const fields = [
        'name', 'age', 'gender', 'description', 'facebook', 'twitter', 'youtube',
        'instagram', 'web_page', 'entrevista', 'parties', 'position', 'district',
        'house_address', 'public_phone', 'email',
    ];

    let allCandidates = [];
    let searchTimer = null;

    function resetForm() {
        form.reset();
        idField.value = '';
        photoPreview.style.display = 'none';
        photoPreview.removeAttribute('src');
        formTitle.textContent = 'Crear Candidato';
        submitBtn.textContent = 'Crear candidato';
        cancelBtn.hidden = true;
    }

    cancelBtn.addEventListener('click', resetForm);

    photoInput.addEventListener('change', () => {
        const file = photoInput.files[0];
        if (!file) {
            photoPreview.style.display = 'none';
            return;
        }
        photoPreview.src = URL.createObjectURL(file);
        photoPreview.style.display = 'block';
    });

    async function loadCandidates(search) {
        try {
            const url = new URL(`${window.BASE_PATH}/api/candidates_list.php`, window.location.origin);
            if (search) {
                url.searchParams.set('search', search);
            }
            const response = await fetch(url, {
                headers: { Authorization: `Bearer ${session.accessToken}` },
            });
            if (response.status === 401) {
                redirectToLogin();
                return;
            }
            if (response.status === 403) {
                tableBody.innerHTML = '<tr><td colspan="4">Tu rol no tiene acceso a este panel.</td></tr>';
                form.hidden = true;
                return;
            }
            const result = await response.json();
            if (result.status !== 'success') {
                tableBody.innerHTML = `<tr><td colspan="4">${esc(result.message || 'Error al cargar candidatos.')}</td></tr>`;
                return;
            }

            allCandidates = result.data.candidates;

            tableBody.innerHTML = allCandidates.length
                ? allCandidates.map((c) => `
                    <tr>
                        <td>${esc(c.name)}</td>
                        <td>${esc(c.parties)}</td>
                        <td>${esc(c.district || '—')}</td>
                        <td><a class="edit-link" href="#" data-id="${c.id}">Editar</a></td>
                    </tr>
                `).join('')
                : '<tr><td colspan="4">Sin candidatos registrados.</td></tr>';
        } catch {
            tableBody.innerHTML = '<tr><td colspan="4">No se pudo contactar al servidor.</td></tr>';
        }
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadCandidates(searchInput.value.trim()), 300);
    });

    tableBody.addEventListener('click', async (event) => {
        const link = event.target.closest('a.edit-link');
        if (!link) return;
        event.preventDefault();

        try {
            const response = await fetch(`${window.BASE_PATH}/api/candidates_get.php?id=${link.dataset.id}`, {
                headers: { Authorization: `Bearer ${session.accessToken}` },
            });
            if (response.status === 401) {
                redirectToLogin();
                return;
            }
            const result = await response.json();
            if (result.status !== 'success') {
                feedbackEl.textContent = result.message || 'No se pudo cargar el candidato.';
                feedbackEl.hidden = false;
                return;
            }

            const candidate = result.data.candidate;
            idField.value = candidate.id;
            fields.forEach((field) => {
                const el = document.getElementById(field);
                if (el) el.value = candidate[field] ?? '';
            });

            photoPreview.style.display = 'none';
            photoPreview.removeAttribute('src');
            if (candidate.photo) {
                photoPreview.src = `${window.BASE_PATH}/${candidate.photo}`;
                photoPreview.style.display = 'block';
            }

            formTitle.textContent = `Editando: ${candidate.name}`;
            submitBtn.textContent = 'Guardar cambios';
            cancelBtn.hidden = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch {
            feedbackEl.textContent = 'No se pudo contactar al servidor.';
            feedbackEl.hidden = false;
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        feedbackEl.hidden = true;

        const isEdit = idField.value !== '';
        const formData = new FormData();
        fields.forEach((field) => {
            const el = document.getElementById(field);
            if (el) formData.append(field, el.value.trim());
        });
        if (isEdit) {
            formData.append('id', idField.value);
        }
        if (photoInput.files[0]) {
            formData.append('photo', photoInput.files[0]);
        }

        const endpoint = isEdit ? 'candidates_update.php' : 'candidates_create.php';

        try {
            const response = await fetch(`${window.BASE_PATH}/api/${endpoint}`, {
                method: 'POST',
                headers: { Authorization: `Bearer ${session.accessToken}` },
                body: formData,
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
                loadCandidates(searchInput.value.trim());
            }
        } catch {
            feedbackEl.textContent = 'No se pudo contactar al servidor.';
            feedbackEl.hidden = false;
        }
    });

    loadCandidates();
});
