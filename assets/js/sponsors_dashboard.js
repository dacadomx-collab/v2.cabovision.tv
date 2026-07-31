// assets/js/sponsors_dashboard.js — Dashboard Ejecutivo B2B (vanilla JS, sin
// librerías de gráficos). Reutiliza getAuthSession()/redirectToLogin() ya
// definidas globalmente por assets/js/admin.js (cargado antes en la página).

document.addEventListener('DOMContentLoaded', () => {
    const session = getAuthSession();
    if (!session || !session.accessToken) {
        redirectToLogin();
        return;
    }

    document.getElementById('logout-btn')?.addEventListener('click', redirectToLogin);

    const esc = (value) => String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const fmtPct = (value) => (value === null || value === undefined ? 'Sin datos' : `${value}%`);

    async function loadReport(desde, hasta) {
        const statGrid = document.getElementById('stat-grid');
        const tableBody = document.getElementById('report-table-body');
        const noteEl = document.getElementById('report-note');

        const params = new URLSearchParams();
        if (desde) params.set('desde', desde);
        if (hasta) params.set('hasta', hasta);

        try {
            const response = await fetch(`/CaboVision.tv/api/sponsors_report.php?${params.toString()}`, {
                headers: { Authorization: `Bearer ${session.accessToken}` },
            });
            if (response.status === 401) {
                redirectToLogin();
                return;
            }
            const result = await response.json();
            if (result.status !== 'success') {
                tableBody.innerHTML = `<tr><td colspan="7">${esc(result.message || 'Error al cargar el reporte.')}</td></tr>`;
                return;
            }

            const { totales, por_banner: porBanner, rango, nota } = result.data;

            statGrid.innerHTML = `
                <div class="stat-card"><div class="stat-card__value">${totales.impresiones_periodo}</div><div class="stat-card__label">Impresiones (Viewability)</div></div>
                <div class="stat-card"><div class="stat-card__value">${totales.clics_periodo}</div><div class="stat-card__label">Clics</div></div>
                <div class="stat-card"><div class="stat-card__value">${totales.clics_unicos_periodo}</div><div class="stat-card__label">Clics Únicos</div></div>
                <div class="stat-card"><div class="stat-card__value">${fmtPct(totales.ctr_pct)}</div><div class="stat-card__label">CTR (${esc(rango.desde)} → ${esc(rango.hasta)})</div></div>
            `;

            if (!porBanner.length) {
                tableBody.innerHTML = '<tr><td colspan="7">Sin campañas registradas.</td></tr>';
            } else {
                tableBody.innerHTML = porBanner.map((b) => `
                    <tr>
                        <td>${esc(b.sponsor_name)}</td>
                        <td>${esc(b.placement_type)}</td>
                        <td>${esc(b.status)}</td>
                        <td>${esc(b.impresiones_periodo)}</td>
                        <td>${esc(b.clics_periodo)}</td>
                        <td>${esc(b.clics_unicos_periodo)}</td>
                        <td>
                            ${fmtPct(b.ctr_pct)}
                            <div class="ctr-bar-track"><div class="ctr-bar-fill" style="width:${b.ctr_pct || 0}%"></div></div>
                        </td>
                    </tr>
                `).join('');
            }

            noteEl.textContent = nota;
        } catch {
            tableBody.innerHTML = '<tr><td colspan="7">No se pudo contactar al servidor.</td></tr>';
        }
    }

    document.getElementById('range-form').addEventListener('submit', (event) => {
        event.preventDefault();
        loadReport(document.getElementById('desde').value, document.getElementById('hasta').value);
    });

    loadReport('', '');
});
