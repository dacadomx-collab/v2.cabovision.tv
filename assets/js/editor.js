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
        formData.append('slug', slugInput.value.trim());
        formData.append('category_id', document.getElementById('category_id').value);
        formData.append('extract', document.getElementById('extract').value.trim());
        formData.append('video_url', document.getElementById('video_url').value.trim());
        formData.append('content', document.getElementById('content').value);
        if (imageInput.files[0]) {
            formData.append('image', imageInput.files[0]);
        }

        try {
            const response = await fetch('/CaboVision.tv/api/articles_create.php', {
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
                const response = await fetch('/CaboVision.tv/api/articles_publish.php', {
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
