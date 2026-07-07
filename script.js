/* =========================================================================
   PromptCraft AI — script.js
   Handles navigation, all AJAX calls to api.php, canvas charts, and
   every interactive behaviour across the dashboard. Vanilla JS only.
   ========================================================================= */

(() => {
    'use strict';

    // Only run the dashboard controller if the app shell is present
    // (i.e. the user is logged in). The auth screens are handled below too.
    const isAppMode = document.body.classList.contains('app-mode');

    /* ---------------------------------------------------------------
       Small helpers
    --------------------------------------------------------------- */
    const $  = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

    async function api(action, data = {}) {
        const body = new FormData();
        body.append('action', action);
        Object.entries(data).forEach(([k, v]) => body.append(k, v));
        const res = await fetch('api.php', { method: 'POST', body });
        try {
            return await res.json();
        } catch (e) {
            return { success: false, message: 'Unexpected server response.' };
        }
    }

    function toast(message, type = 'info') {
        const container = $('#toast-container');
        if (!container) return;
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = message;
        container.appendChild(el);
        setTimeout(() => {
            el.style.animation = 'toastOut 0.35s var(--ease) forwards';
            setTimeout(() => el.remove(), 350);
        }, 3200);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function timeAgo(dateStr) {
        const d = new Date(dateStr.replace(' ', 'T'));
        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }

    function downloadText(filename, text) {
        const blob = new Blob([text], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(url);
    }

    /* ---------------------------------------------------------------
       Ripple effect for any .ripple element
    --------------------------------------------------------------- */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.ripple');
        if (!btn) return;
        const rect = btn.getBoundingClientRect();
        btn.style.setProperty('--rx', (e.clientX - rect.left) + 'px');
        btn.style.setProperty('--ry', (e.clientY - rect.top) + 'px');
        btn.classList.remove('rippling');
        void btn.offsetWidth; // restart animation
        btn.classList.add('rippling');
    });

    /* =================================================================
       AUTH SCREENS (login / register)
    ================================================================= */
    if (!isAppMode) {
        $$('.auth-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                $$('.auth-tab').forEach(t => t.classList.remove('active'));
                $$('.auth-form').forEach(f => f.classList.remove('active'));
                tab.classList.add('active');
                $('#' + tab.dataset.form).classList.add('active');
            });
        });

        const loginForm = $('#login-form');
        loginForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const errorEl = $('#login-error');
            errorEl.textContent = '';
            const fd = new FormData(loginForm);
            const res = await api('login', Object.fromEntries(fd));
            if (res.success) {
                window.location.href = 'index.php';
            } else {
                errorEl.textContent = res.message || 'Login failed.';
            }
        });

        const registerForm = $('#register-form');
        registerForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const errorEl = $('#register-error');
            const successEl = $('#register-success');
            errorEl.textContent = '';
            successEl.textContent = '';
            const fd = new FormData(registerForm);
            const res = await api('register', Object.fromEntries(fd));
            if (res.success) {
                successEl.textContent = res.message;
                registerForm.reset();
                setTimeout(() => $('.auth-tab[data-form="login-form"]').click(), 1200);
            } else {
                errorEl.textContent = res.message || 'Registration failed.';
            }
        });

        return; // nothing else to do on the auth screens
    }

    /* =================================================================
       APP SHELL: sidebar navigation, mobile menu, theme toggle
    ================================================================= */
    const sidebar = $('#sidebar');
    const overlay = $('#sidebar-overlay');

    $('#menu-toggle')?.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
    });
    overlay?.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
    });

    function goToPage(pageName) {
        $$('.page').forEach(p => p.classList.remove('active'));
        $(`#page-${pageName}`)?.classList.add('active');
        $$('.nav-item[data-page]').forEach(n => n.classList.toggle('active', n.dataset.page === pageName));
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        loadPageData(pageName);
    }

    $$('[data-page]').forEach(el => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            goToPage(el.dataset.page);
        });
    });


    // Logout
    $('#logout-btn')?.addEventListener('click', async () => {
        await api('logout');
        window.location.href = 'index.php';
    });

    // Global search bar -> jumps to Library and filters
    $('#global-search')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            goToPage('library');
            setTimeout(() => {
                $('#library-search').value = e.target.value;
                loadLibrary();
            }, 150);
        }
    });

    /* =================================================================
       CATEGORY OPTIONS (shared across Generate + Library filter)
    ================================================================= */
    let categoriesCache = [];
    async function loadCategories() {
        const res = await api('get_categories');
        if (!res.success) return;
        categoriesCache = res.categories;

        const genSelect = $('#gen-category');
        if (genSelect) {
            genSelect.innerHTML = categoriesCache.map(c =>
                `<option value="${c.category_id}">${escapeHtml(c.category_name)}</option>`).join('');
        }

        const filterSelect = $('#library-category-filter');
        if (filterSelect) {
            filterSelect.innerHTML = '<option value="All">All Categories</option>' +
                categoriesCache.map(c => `<option value="${escapeHtml(c.category_name)}">${escapeHtml(c.category_name)}</option>`).join('');
        }
    }

    /* =================================================================
       DASHBOARD HOME
    ================================================================= */
    async function loadDashboard() {
        const res = await api('get_dashboard_stats');
        if (!res.success) return;

        $('#stat-total-prompts').textContent = res.stats.total_prompts;
        $('#stat-favorites').textContent = res.stats.total_favorites;
        $('#stat-tests').textContent = res.stats.total_tests;
        $('#stat-templates').textContent = res.stats.total_templates;

        const promptsList = $('#recent-prompts-list');
        promptsList.innerHTML = res.recent_prompts.length ? res.recent_prompts.map(p => `
            <div class="list-item">
                <div>
                    <div class="li-title">${escapeHtml(p.title)}</div>
                    <div class="li-sub">${timeAgo(p.created_at)}</div>
                </div>
                <span class="li-badge">Prompt</span>
            </div>`).join('') : '<p class="empty-note">No prompts yet — generate your first one!</p>';

        const activityList = $('#recent-activity-list');
        activityList.innerHTML = res.recent_activity.length ? res.recent_activity.map(a => `
            <div class="list-item">
                <div>
                    <div class="li-title">${escapeHtml(a.activity)}</div>
                    <div class="li-sub">${escapeHtml(a.activity_detail || '')}</div>
                </div>
                <span class="li-badge">${timeAgo(a.activity_date)}</span>
            </div>`).join('') : '<p class="empty-note">No activity recorded yet.</p>';

        drawBarChart('chart-activity', groupByDay(res.recent_prompts));
    }

    function groupByDay(prompts) {
        const days = [];
        const now = new Date();
        for (let i = 6; i >= 0; i--) {
            const d = new Date(now);
            d.setDate(now.getDate() - i);
            days.push({ label: d.toLocaleDateString(undefined, { weekday: 'short' }), key: d.toISOString().slice(0, 10), value: 0 });
        }
        prompts.forEach(p => {
            const key = (p.created_at || '').slice(0, 10);
            const day = days.find(d => d.key === key);
            if (day) day.value++;
        });
        return days;
    }

    /* =================================================================
       CANVAS CHARTS (pure vanilla, no chart library)
    ================================================================= */
    function drawBarChart(canvasId, data) {
        const canvas = $('#' + canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.clientWidth || canvas.parentElement.clientWidth;
        const h = 180;
        canvas.style.height = "180px";
        canvas.style.width = "100%";
        canvas.width = w * dpr; canvas.height = h * dpr;
        ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, w, h);

        const max = Math.max(1, ...data.map(d => d.value));
        const padding = 28;
        const barW = (w - padding * 2) / data.length * 0.55;
        const gap = (w - padding * 2) / data.length;

        const grad = ctx.createLinearGradient(0, 0, 0, h - padding);
        grad.addColorStop(0, '#A855F7');
        grad.addColorStop(1, '#7C3AED');

        data.forEach((d, i) => {
            const barH = (d.value / max) * (h - padding * 2);
            const x = padding + i * gap + (gap - barW) / 2;
            const y = h - padding - barH;

            ctx.fillStyle = grad;
            roundRect(ctx, x, y, barW, barH, 6);
            ctx.fill();

            ctx.fillStyle = 'rgba(138,138,155,0.9)';
            ctx.font = '11px Inter, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(d.label, x + barW / 2, h - 8);

            if (d.value > 0) {
                ctx.fillStyle = '#F4F4F7';
                ctx.fillText(d.value, x + barW / 2, y - 6);
            }
        });
    }

    function drawLineChart(canvasId, data) {
        const canvas = $('#' + canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.clientWidth || canvas.parentElement.clientWidth;
        const h = 180;
        canvas.style.height = "180px";
        canvas.style.width = "100%";
        ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, w, h);

        const padding = 30;
        const max = Math.max(1, ...data.map(d => d.value));
        const stepX = (w - padding * 2) / Math.max(1, data.length - 1);

        ctx.beginPath();
        data.forEach((d, i) => {
            const x = padding + i * stepX;
            const y = h - padding - (d.value / max) * (h - padding * 2);
            i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
        });
        ctx.strokeStyle = '#A855F7';
        ctx.lineWidth = 2.5;
        ctx.stroke();

        // Fill under line
        ctx.lineTo(padding + (data.length - 1) * stepX, h - padding);
        ctx.lineTo(padding, h - padding);
        ctx.closePath();
        const grad = ctx.createLinearGradient(0, 0, 0, h);
        grad.addColorStop(0, 'rgba(139,92,246,0.35)');
        grad.addColorStop(1, 'rgba(139,92,246,0)');
        ctx.fillStyle = grad;
        ctx.fill();

        data.forEach((d, i) => {
            const x = padding + i * stepX;
            const y = h - padding - (d.value / max) * (h - padding * 2);
            ctx.beginPath();
            ctx.arc(x, y, 3.5, 0, Math.PI * 2);
            ctx.fillStyle = '#F4F4F7';
            ctx.fill();
            ctx.fillStyle = 'rgba(138,138,155,0.9)';
            ctx.font = '10px Inter, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(d.label, x, h - 8);
        });
    }

    function drawDonutChart(canvasId, data) {
        const canvas = $('#' + canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const w = canvas.clientWidth || canvas.parentElement.clientWidth;
        const h = canvas.clientHeight || 220;
        canvas.width = w * dpr; canvas.height = h * dpr;
        ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, w, h);

        const total = data.reduce((s, d) => s + d.value, 0) || 1;
        const cx = w / 2, cy = h / 2, radius = Math.min(w, h) / 2 - 10;
        const colors = ['#7C3AED', '#A855F7', '#8B5CF6', '#38BDF8', '#22C55E', '#F59E0B', '#F43F5E'];
        let start = -Math.PI / 2;

        if (data.length === 0 || total === 0) {
            ctx.fillStyle = 'rgba(138,138,155,0.7)';
            ctx.font = '13px Inter, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No data yet', cx, cy);
            return;
        }

        data.forEach((d, i) => {
            const angle = (d.value / total) * Math.PI * 2;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, radius, start, start + angle);
            ctx.closePath();
            ctx.fillStyle = colors[i % colors.length];
            ctx.fill();
            start += angle;
        });

        // Donut hole
        ctx.beginPath();
        ctx.arc(cx, cy, radius * 0.55, 0, Math.PI * 2);
        ctx.fillStyle = getComputedStyle(document.body).getPropertyValue('--bg-1') || '#111827';
        ctx.fill();
    }

    function roundRect(ctx, x, y, w, h, r) {
        if (h < 1) h = 1;
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    /* =================================================================
       GENERATE PROMPT
    ================================================================= */
    const generateForm = $('#generate-form');
    let lastGenerated = { title: '', text: '', categoryId: null, promptId: null };

    generateForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        toggleOutputState('generate', 'loading');
        const fd = Object.fromEntries(new FormData(generateForm));
        fd.category_id = fd.category;
        const res = await api('generate_prompt', {
            topic: fd.topic, category: $('#gen-category option:checked')?.textContent || 'Content',
            category_id: fd.category, language: fd.language, style: fd.style, length: fd.length,
        });

        if (res.success) {
            lastGenerated = { title: fd.topic.slice(0, 60), text: res.result, categoryId: fd.category, promptId: res.prompt_id };
            $('#generate-text').textContent = res.result;
            toggleOutputState('generate', 'result');
            toast('Prompt generated successfully!', 'success');
        } else {
            toggleOutputState('generate', 'empty');
            toast(res.message || 'Something went wrong.', 'error');
        }
    });

    function toggleOutputState(prefix, state) {
        ['empty', 'loading', 'result'].forEach(s => {
            $(`#${prefix}-${s}`)?.classList.toggle('hidden', s !== state);
        });
    }

    $('#generate-save-btn')?.addEventListener('click', async () => {
        toast('Prompt already saved to your library!', 'info');
    });
    $('#generate-favorite-btn')?.addEventListener('click', async () => {
        if (!lastGenerated.promptId) return;
        const res = await api('toggle_favorite', { prompt_id: lastGenerated.promptId });
        if (res.success) toast(res.favorited ? 'Added to favorites!' : 'Removed from favorites.', 'success');
    });
    $('#generate-improve-btn')?.addEventListener('click', () => {
        goToPage('improve');
        setTimeout(() => { $('#improve-input').value = lastGenerated.text; }, 150);
    });
    $('#generate-download-btn')?.addEventListener('click', () => {
        downloadText('prompt.txt', lastGenerated.text);
    });

    /* =================================================================
       IMPROVE PROMPT
    ================================================================= */
    const improveForm = $('#improve-form');
    let lastImproved = '';

    improveForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        toggleOutputState('improve', 'loading');
        const fd = Object.fromEntries(new FormData(improveForm));
        const res = await api('improve_prompt', { original: fd.original });

        if (res.success) {
            lastImproved = res.result;
            $('#improve-text').textContent = res.result;
            toggleOutputState('improve', 'result');
            toast('Prompt improved!', 'success');
        } else {
            toggleOutputState('improve', 'empty');
            toast(res.message || 'Something went wrong.', 'error');
        }
    });

    $('#improve-save-btn')?.addEventListener('click', async () => {
        const original = $('#improve-input').value;
        const res = await api('save_prompt', { title: original.slice(0, 60) || 'Improved Prompt', original, generated: lastImproved });
        if (res.success) toast('Saved to your library!', 'success');
    });
    $('#improve-download-btn')?.addEventListener('click', () => downloadText('improved-prompt.txt', lastImproved));

    /* =================================================================
       TEST PROMPT
    ================================================================= */
    const testForm = $('#test-form');
    let lastTestResponse = '';

    testForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        toggleOutputState('test', 'loading');
        const fd = Object.fromEntries(new FormData(testForm));
        const res = await api('test_prompt', { prompt: fd.prompt });

        if (res.success) {
            lastTestResponse = res.result;
            $('#test-text').textContent = res.result;
            toggleOutputState('test', 'result');
            toast('Test complete!', 'success');
        } else {
            toggleOutputState('test', 'empty');
            toast(res.message || 'Something went wrong.', 'error');
        }
    });
    $('#test-download-btn')?.addEventListener('click', () => downloadText('ai-response.txt', lastTestResponse));

    /* Copy buttons (shared) */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-copy]');
        if (!btn) return;
        const text = $('#' + btn.dataset.copy)?.textContent || '';
        navigator.clipboard.writeText(text).then(() => toast('Copied to clipboard!', 'success'));
    });

    /* =================================================================
       PROMPT LIBRARY
    ================================================================= */
    let libraryCache = [];

    async function loadLibrary() {
        const search = $('#library-search')?.value || '';
        const category = $('#library-category-filter')?.value || 'All';
        const res = await api('get_prompts', { search, category });
        if (!res.success) return;
        libraryCache = res.prompts;
        renderLibrary();
    }

    function renderLibrary() {
        const grid = $('#library-grid');
        if (!grid) return;
        if (!libraryCache.length) {
            grid.innerHTML = '<p class="empty-note">No prompts found. Try generating one!</p>';
            return;
        }
        grid.innerHTML = libraryCache.map(p => `
            <div class="prompt-card">
                <div class="prompt-card-head">
                    <h4>${escapeHtml(p.title)}</h4>
                    <span class="li-badge">${escapeHtml(p.category_name || 'General')}</span>
                </div>
                <p class="excerpt">${escapeHtml((p.generated_prompt || '').slice(0, 160))}...</p>
                <div class="prompt-card-foot">
                    <span class="muted">${timeAgo(p.created_at)}</span>
                    <div class="prompt-card-actions">
                        <button class="card-icon-btn ${p.is_favorite == 1 ? 'active' : ''}" data-fav="${p.prompt_id}" title="Favorite"><i class="ic-heart"></i></button>
                        <button class="card-icon-btn" data-edit="${p.prompt_id}" title="Edit"><i class="ic-edit"></i></button>
                        <button class="card-icon-btn" data-del="${p.prompt_id}" title="Delete"><i class="ic-trash"></i></button>
                    </div>
                </div>
            </div>`).join('');
    }

    $('#library-search')?.addEventListener('input', debounce(loadLibrary, 350));
    $('#library-category-filter')?.addEventListener('change', loadLibrary);

    document.addEventListener('click', async (e) => {
        const favBtn = e.target.closest('[data-fav]');
        if (favBtn) {
            const res = await api('toggle_favorite', { prompt_id: favBtn.dataset.fav });
            if (res.success) {
                toast(res.favorited ? 'Added to favorites!' : 'Removed from favorites.', 'success');
                favBtn.classList.toggle('active', res.favorited);
                if ($('#page-favorites').classList.contains('active')) loadFavorites();
            }
        }

        const delBtn = e.target.closest('[data-del]');
        if (delBtn) {
            if (!confirm('Delete this prompt? This cannot be undone.')) return;
            const res = await api('delete_prompt', { prompt_id: delBtn.dataset.del });
            if (res.success) { toast('Prompt deleted.', 'success'); loadLibrary(); }
        }

        const editBtn = e.target.closest('[data-edit]');
        if (editBtn) {
            const prompt = libraryCache.find(p => p.prompt_id == editBtn.dataset.edit);
            if (!prompt) return;
            const newTitle = window.prompt('Edit title:', prompt.title);
            if (newTitle === null) return;
            const newContent = window.prompt('Edit generated prompt:', prompt.generated_prompt);
            if (newContent === null) return;
            const res = await api('update_prompt', { prompt_id: prompt.prompt_id, title: newTitle, generated: newContent });
            if (res.success) { toast('Prompt updated.', 'success'); loadLibrary(); }
        }
    });

    function debounce(fn, delay) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
    }

    /* =================================================================
       FAVORITES
    ================================================================= */
    async function loadFavorites() {
        const res = await api('get_favorites');
        if (!res.success) return;
        const grid = $('#favorites-grid');
        if (!res.favorites.length) {
            grid.innerHTML = '<p class="empty-note">You haven\'t favorited any prompts yet.</p>';
            return;
        }
        grid.innerHTML = res.favorites.map(p => `
            <div class="prompt-card">
                <div class="prompt-card-head">
                    <h4>${escapeHtml(p.title)}</h4>
                    <span class="li-badge">${escapeHtml(p.category_name || 'General')}</span>
                </div>
                <p class="excerpt">${escapeHtml((p.generated_prompt || '').slice(0, 160))}...</p>
                <div class="prompt-card-foot">
                    <span class="muted">${timeAgo(p.created_at)}</span>
                    <div class="prompt-card-actions">
                        <button class="card-icon-btn active" data-fav="${p.prompt_id}" title="Remove favorite"><i class="ic-heart"></i></button>
                    </div>
                </div>
            </div>`).join('');
    }

    /* =================================================================
       HISTORY
    ================================================================= */
    async function loadHistory() {
        const res = await api('get_history');
        if (!res.success) return;
        const timeline = $('#history-timeline');
        if (!res.history.length) {
            timeline.innerHTML = '<p class="empty-note">No activity recorded yet.</p>';
            return;
        }
        timeline.innerHTML = res.history.map(h => `
            <div class="timeline-item">
                <div>
                    <div class="ti-title">${escapeHtml(h.activity)}</div>
                    <div class="ti-detail">${escapeHtml(h.activity_detail || '')}</div>
                    <div class="ti-time">${new Date(h.activity_date.replace(' ', 'T')).toLocaleString()}</div>
                </div>
            </div>`).join('');
    }

    /* =================================================================
       ANALYTICS
    ================================================================= */
    async function loadAnalytics() {
        const res = await api('get_analytics');
        if (!res.success) return;

        const days = [];
        const now = new Date();
        for (let i = 6; i >= 0; i--) {
            const d = new Date(now);
            d.setDate(now.getDate() - i);
            const key = d.toISOString().slice(0, 10);
            const found = res.by_day.find(x => x.d === key);
            days.push({ label: d.toLocaleDateString(undefined, { weekday: 'short' }), value: found ? parseInt(found.c) : 0 });
        }
        drawLineChart('chart-analytics-day', days);

        const cats = res.by_category
            .filter(c => c.category_name)
            .map(c => ({ label: c.category_name, value: parseInt(c.total) }));
        drawDonutChart('chart-analytics-category', cats);
    }

    /* =================================================================
       PROFILE
    ================================================================= */
    const profileForm = $('#profile-form');
    let profilePhotoData = null;

    async function loadProfile() {
        const res = await api('get_profile');
        if (!res.success) return;
        $('#profile-fullname').value = res.user.fullname;
        $('#profile-email').value = res.user.email;
        if (res.user.photo) {
            $('#profile-avatar').innerHTML = `<img src="${res.user.photo}" alt="Profile photo">`;
            $('#topbar-avatar').innerHTML = `<img src="${res.user.photo}" alt="Profile photo">`;
        }
        $('#settings-api-key').value = res.user.api_key || '';
        $('#settings-language').value = res.user.language || 'en';
    }

    $('#profile-photo-input')?.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            profilePhotoData = reader.result;
            $('#profile-avatar').innerHTML = `<img src="${profilePhotoData}" alt="Profile photo">`;
        };
        reader.readAsDataURL(file);
    });

    profileForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(profileForm));
        const payload = { fullname: fd.fullname, email: fd.email, password: fd.password || '' };
        if (profilePhotoData) payload.photo = profilePhotoData;
        const res = await api('update_profile', payload);
        const successEl = $('#profile-success');
        if (res.success) {
            successEl.textContent = 'Profile updated successfully!';
            $('#topbar-name').textContent = fd.fullname;
            if (profilePhotoData) $('#topbar-avatar').innerHTML = `<img src="${profilePhotoData}" alt="Profile photo">`;
            toast('Profile saved!', 'success');
        } else {
            toast(res.message || 'Could not update profile.', 'error');
        }
    });

    /* =================================================================
       SETTINGS
    ================================================================= */
    const settingsForm = $('#settings-form');
    settingsForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = Object.fromEntries(new FormData(settingsForm));
        const res = await api('update_settings', fd);
        if (res.success) {
            $('#settings-success').textContent = 'Settings saved!';
            applyTheme(fd.theme);
            localStorage.setItem('pc_theme', fd.theme);
            toast('Settings saved!', 'success');
        } else {
            toast(res.message || 'Could not save settings.', 'error');
        }
    });

    /* =================================================================
       PAGE ROUTER — load fresh data whenever a page becomes active
    ================================================================= */
    function loadPageData(page) {
        switch (page) {
            case 'dashboard': loadDashboard(); break;
            case 'library': loadLibrary(); break;
            case 'favorites': loadFavorites(); break;
            case 'history': loadHistory(); break;
            case 'analytics': loadAnalytics(); break;
            case 'profile': loadProfile(); break;
            case 'settings': loadProfile(); break;
        }
    }

    /* =================================================================
       INITIAL LOAD
    ================================================================= */
    loadCategories();
    loadDashboard();

    window.addEventListener('resize', debounce(() => {
        const activePage = $('.page.active')?.id;
        if (activePage === 'page-dashboard') loadDashboard();
        if (activePage === 'page-analytics') loadAnalytics();
    }, 300));

})();