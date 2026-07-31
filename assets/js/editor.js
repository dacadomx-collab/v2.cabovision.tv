// assets/js/editor.js — Editor "Una Sola Pantalla" (2026-07-21). Reutiliza
// getAuthSession()/redirectToLogin()/loadCategoriesSelect() ya globales por
// assets/js/admin.js (cargado antes en la página). Envía multipart/form-data
// real (FormData) — nunca JSON — porque api/articles_create.php v2 necesita
// recibir la Imagen Principal en el mismo request.

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('article-form');
    if (!form) {
        return;
    }

    const session = getAuthSession();
    if (!session || !session.accessToken) {
        redirectToLogin();
        return;
    }

    document.getElementById('logout-btn')?.addEventListener('click', redirectToLogin);
    loadCategoriesSelect();

    // Modo edición (?id=123 en la URL) — rescate de notas ya publicadas con
    // imagen genérica o texto por corregir. Sin ?id, el formulario funciona
    // exactamente igual que antes (alta de nota nueva).
    const editingId = new URLSearchParams(window.location.search).get('id');
    const isEditMode = editingId !== null && editingId !== '';

    // Preview del slug — puramente informativo para el redactor; la
    // normalización real (acentos, caracteres inválidos, unicidad) siempre la
    // hace el servidor vía slugify_text(), esto nunca decide el alias final.
    const titleInput = document.getElementById('title');
    const slugInput = document.getElementById('slug');
    const slugPreview = document.getElementById('slug-preview-text');

    function clientSlugify(text) {
        return text
            .normalize('NFD').replace(/[̀-ͯ]/g, '') // quita acentos
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function updateSlugPreview() {
        const base = slugInput.value.trim() !== '' ? slugInput.value : titleInput.value;
        slugPreview.textContent = clientSlugify(base) || '…';
    }

    titleInput.addEventListener('input', updateSlugPreview);
    slugInput.addEventListener('input', updateSlugPreview);

    // Preview de la imagen elegida — feedback inmediato, la validación real
    // (finfo_file()/getimagesize()) solo puede ocurrir en el servidor.
    const imageInput = document.getElementById('image');
    const imagePreview = document.getElementById('image-preview');
    // Precarga de la nota a editar — trae los campos reales desde
    // api/articles_get.php (autenticado, funciona con borradores o
    // publicadas, a diferencia de api/article_detail.php que es público).
    if (isEditMode) {
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.textContent = 'Guardar cambios';
        }
        const heading = document.querySelector('.admin-topbar h1');
        if (heading) {
            heading.textContent = 'Editando nota #' + editingId;
        }

        (async () => {
            try {
                const response = await fetch(`${window.BASE_PATH}/api/articles_get.php?id=${encodeURIComponent(editingId)}`, {
                    headers: { Authorization: `Bearer ${session.accessToken}` },
                });
                if (response.status === 401) {
                    redirectToLogin();
                    return;
                }
                const result = await response.json();
                if (result.status !== 'success') {
                    document.getElementById('article-feedback').textContent = result.message;
                    document.getElementById('article-feedback').hidden = false;
                    return;
                }
                const article = result.data.article;
                titleInput.value = article.title;
                document.getElementById('extract').value = article.extract || '';
                document.getElementById('content').value = article.content;
                // El <select> de categorías carga async (loadCategoriesSelect) —
                // se espera un instante antes de fijar el valor seleccionado.
                setTimeout(() => {
                    document.getElementById('category_id').value = article.category_id;
                }, 300);
                if (article.thumbnail) {
                    imagePreview.src = `${window.BASE_PATH}/${article.thumbnail}`;
                    imagePreview.style.display = 'block';
                }
                slugPreview.textContent = article.alias;
            } catch {
                document.getElementById('article-feedback').textContent = 'No se pudo cargar la nota a editar.';
                document.getElementById('article-feedback').hidden = false;
            }
        })();
    }

    // Listado de notas recientes — para encontrar rápido cuáles tienen
    // imagen genérica y necesitan "rescate" sin tener que adivinar el id.
    (async () => {
        const tbody = document.getElementById('recent-articles-body');
        if (!tbody) {
            return;
        }
        try {
            const response = await fetch(`${window.BASE_PATH}/api/articles_list.php?page=1&per_page=15`);
            const result = await response.json();
            const articles = result?.data?.articles || [];
            if (articles.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4">Sin notas publicadas todavía.</td></tr>';
                return;
            }
            tbody.innerHTML = articles.map((a) => `
                <tr>
                    <td>${escapeHtml(a.title)}</td>
                    <td>${escapeHtml(a.category_name)}</td>
                    <td>${escapeHtml(a.published_at || '')}</td>
                    <td><a class="edit-link" href="${window.BASE_PATH}/admin/editor.php?id=${a.id}">Editar</a></td>
                </tr>
            `).join('');
        } catch {
            tbody.innerHTML = '<tr><td colspan="4">No se pudo cargar el listado.</td></tr>';
        }
    })();

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    imageInput.addEventListener('change', () => {
        const file = imageInput.files[0];
        if (!file) {
            imagePreview.style.display = 'none';
            return;
        }
        const reader = new FileReader();
        reader.onload = (event) => {
            imagePreview.src = event.target.result;
            imagePreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const feedbackEl = document.getElementById('article-feedback');
        feedbackEl.hidden = true;

        // FormData real — NUNCA fijar Content-Type manualmente aquí: el
        // navegador debe generar el boundary de multipart/form-data él mismo.
        const formData = new FormData();
        formData.append('title', titleInput.value.trim());
        formData.append('category_id', document.getElementById('category_id').value);
        formData.append('extract', document.getElementById('extract').value.trim());
        formData.append('content', document.getElementById('content').value);
        if (imageInput.files[0]) {
            formData.append('image', imageInput.files[0]);
        }

        // articles_update.php no acepta slug/video_url (edita una nota ya
        // existente, el alias no cambia) — solo se envían en alta nueva.
        if (!isEditMode) {
            formData.append('slug', slugInput.value.trim());
            formData.append('video_url', document.getElementById('video_url').value.trim());
        } else {
            formData.append('id', editingId);
        }

        const endpoint = isEditMode ? 'articles_update.php' : 'articles_create.php';

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

            if (result.status === 'success' && !isEditMode) {
                form.reset();
                imagePreview.style.display = 'none';
                slugPreview.textContent = '…';
                offerPublish(result.data.id, feedbackEl);
            }
        } catch {
            feedbackEl.textContent = 'No se pudo contactar al servidor.';
            feedbackEl.hidden = false;
        }
    });

    /**
     * Ofrece publicar el borrador recién creado — llama a
     * api/articles_publish.php (Bearer JWT + rol Admin/Editor real, la propia
     * API rechaza con 403 a un Autor aunque el botón exista en su pantalla).
     */
    function offerPublish(articleId, feedbackEl) {
        const existing = document.getElementById('publish-now-btn');
        existing?.remove();

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'publish-now-btn';
        btn.className = 'publish-now-btn';
        btn.textContent = 'Publicar ahora';
        feedbackEl.insertAdjacentElement('afterend', btn);

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            try {
                const response = await fetch(`${window.BASE_PATH}/api/articles_publish.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Authorization: `Bearer ${session.accessToken}`,
                    },
                    body: JSON.stringify({ id: articleId }),
                });

                if (response.status === 401) {
                    redirectToLogin();
                    return;
                }
                if (response.status === 403) {
                    feedbackEl.textContent = 'Tu rol no tiene permiso para publicar — solo Admin/Editor.';
                    btn.remove();
                    return;
                }

                const result = await response.json();
                feedbackEl.textContent = result.message;
                if (result.status === 'success') {
                    btn.remove();
                }
            } catch {
                feedbackEl.textContent = 'No se pudo contactar al servidor.';
            } finally {
                btn.disabled = false;
            }
        });
    }
});
