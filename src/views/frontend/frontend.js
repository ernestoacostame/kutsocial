let token = localStorage.getItem('kutsocial_token');
if (!token && window.KUTSOCIAL_AUTO_TOKEN) {
    token = window.KUTSOCIAL_AUTO_TOKEN;
    localStorage.setItem('kutsocial_token', token);
}
if (token || (window.KUTSOCIAL_OWNER && !window.KUTSOCIAL_OWNER.locked)) {
    document.documentElement.classList.add('is-authenticated');
}
let currentTimeline = 'public';
let lastRenderedTimeline = '';
let lastLoadedProfile = null;
function proxyUrl(url) {
    if (!url) return '';
    if (url.startsWith('/') || url.startsWith(window.location.origin) || !url.startsWith('http')) {
        return url;
    }
    return `/api/proxy?url=${encodeURIComponent(url)}`;
}
function sanitizeHTML(html) {
    if (!html) return '';
    if (window.DOMPurify) {
        return DOMPurify.sanitize(html, {
            ALLOWED_TAGS: ['p', 'br', 'a', 'span', 'strong', 'em', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote'],
            ALLOWED_ATTR: ['href', 'target', 'rel', 'class', 'style']
        });
    }
    return html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
}

function formatRelativeTime(dateInput) {
    const date = new Date(dateInput);
    if (isNaN(date.getTime())) return '';
    const now = new Date();
    const diffMs = now - date;
    const diffSecs = Math.floor(diffMs / 1000);
    const diffMins = Math.floor(diffSecs / 60);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffSecs < 60) {
        return 'hace segundos';
    } else if (diffMins < 60) {
        return `hace ${diffMins} min`;
    } else if (diffHours < 24) {
        return `hace ${diffHours} h`;
    } else {
        return `hace ${diffDays} días`;
    }
}

async function dismissGroupedNotifications(commaIds, element) {
    if (!confirm('¿Estás seguro de que deseas eliminar estas notificaciones?')) return;
    const ids = commaIds.split(',');
    try {
        await Promise.all(ids.map(id => 
            fetch(`/api/v1/notifications/${id}/dismiss`, {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${token}` }
            })
        ));
        element.remove();
        const list = document.getElementById('notifications-list');
        if (list.children.length === 0) {
            list.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">No tienes ninguna notificación por el momento.</div>';
        }
    } catch (err) {
        alert('Error al conectar con el servidor.');
    }
}

let lastId = 0;
let oldestId = null;
let hasMoreToots = true;
let isLoadingToots = false;
let eventSource = null;

// Comprobación de autenticación
window.addEventListener('DOMContentLoaded', () => {
    if (!token) {
        if (window.KUTSOCIAL_OWNER && !window.KUTSOCIAL_OWNER.locked) {
            initPublicView();
        } else {
            document.getElementById('login-container').style.display = 'flex';
        }
    } else {
        initApp();
    }

    // Registrar event listeners para autocompletado, drag & drop y paste en el composer
    const textarea = document.getElementById('composer-text');
    if (textarea) {
        textarea.addEventListener('input', handleComposerInput);
        textarea.addEventListener('keydown', handleComposerKeydown);

        // Drag & Drop en el textarea/composer-card
        const composerCard = document.querySelector('#tab-feed .composer-card');
        const dragTarget = composerCard || textarea;
        
        dragTarget.addEventListener('dragover', (e) => {
            e.preventDefault();
            dragTarget.style.borderColor = 'var(--primary)';
        });
        dragTarget.addEventListener('dragleave', () => {
            dragTarget.style.borderColor = '';
        });
        dragTarget.addEventListener('drop', (e) => {
            e.preventDefault();
            dragTarget.style.borderColor = '';
            const files = e.dataTransfer.files;
            if (files && files.length > 0) {
                for (let i = 0; i < files.length; i++) {
                    uploadFileDirectly(files[i]);
                }
            }
        });

        // Pegar desde el portapapeles
        textarea.addEventListener('paste', (e) => {
            const items = (e.clipboardData || e.originalEvent.clipboardData || window.clipboardData).items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].kind === 'file') {
                    const blob = items[i].getAsFile();
                    uploadFileDirectly(blob);
                }
            }
        });
    }

    // Inicializar checkbox de preferencia de AltText
    const warnMissingAltCheckbox = document.getElementById('pref-warn-missing-alt');
    if (warnMissingAltCheckbox) {
        const savedVal = localStorage.getItem('kutsocial_warn_missing_alt') !== 'false';
        warnMissingAltCheckbox.checked = savedVal;
        warnMissingAltCheckbox.addEventListener('change', () => {
            localStorage.setItem('kutsocial_warn_missing_alt', warnMissingAltCheckbox.checked);
        });
    }
});

// Event listener de scroll para carga diferida (Infinite Scroll)
window.addEventListener('scroll', () => {
    if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 600) {
        if (!isLoadingToots && hasMoreToots && currentTimeline !== 'bookmarks') {
            loadTimeline(true);
        }
    }
});

// Evento de Login
const loginForm = document.getElementById('login-form');
if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
    const u = document.getElementById('username').value;
    const p = document.getElementById('password').value;
    const errDiv = document.getElementById('login-error');

    errDiv.style.display = 'none';

    // Verificamos si existe el campo OTP
    const otpInput = document.getElementById('login-otp');
    let otpVal = '';
    if (otpInput) {
        otpVal = otpInput.value;
    }

    try {
        let body = `grant_type=password&username=${encodeURIComponent(u)}&password=${encodeURIComponent(p)}`;
        if (otpVal) {
            body += `&otp=${encodeURIComponent(otpVal)}`;
        }

        const response = await fetch('/oauth/token', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        
        const data = await response.json();
        if (data.access_token) {
            token = data.access_token;
            localStorage.setItem('kutsocial_token', token);
            window.location.reload();
        } else if (data.error === 'two_factor_required') {
            // Mostrar campo OTP si no existe ya
            if (!otpInput) {
                const form = document.getElementById('login-form');
                const otpGroup = document.createElement('div');
                otpGroup.className = 'form-group';
                otpGroup.innerHTML = `
                    <label for="login-otp">Código de Dos Factores (2FA)</label>
                    <input type="text" id="login-otp" required placeholder="123456" pattern="\\d{6}" maxlength="6" style="text-align:center; font-size:18px; letter-spacing:5px;">
                `;
                // Insertar antes del botón
                const button = form.querySelector('button');
                form.insertBefore(otpGroup, button);
            }
            errDiv.innerText = 'Por favor, introduce el código 2FA de tu app de autenticación.';
            errDiv.style.display = 'block';
        } else {
            errDiv.innerText = data.error_description || data.error || 'Fallo de acceso';
            errDiv.style.display = 'block';
        }
    } catch (err) {
        errDiv.innerText = 'Error de red al conectar al servidor.';
        errDiv.style.display = 'block';
    }
    });
}

function logout() {
    localStorage.removeItem('kutsocial_token');
    window.location.href = '/admin/logout?redirect=/';
}

// Funciones para vista pública (visitante)
function initPublicView() {
    document.getElementById('app-container').style.display = 'flex';
    document.getElementById('login-container').style.display = 'none';
    
    // Ocultar elementos exclusivos de usuarios registrados en el sidebar
    const linksToHide = [
        'nav-public', 'nav-home', 'nav-local', 'nav-notifications', 'nav-bookmarks', 
        'nav-lists', 'nav-collections', 'nav-hashtags', 'nav-profile', 
        'nav-admin-settings'
    ];
    linksToHide.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    
    // Mostrar link "Propietario"
    const itemOwner = document.getElementById('nav-item-owner');
    if (itemOwner) {
        itemOwner.style.display = 'block';
        const ownerLink = document.getElementById('nav-owner-profile');
        if (ownerLink && window.KUTSOCIAL_OWNER) {
            ownerLink.innerHTML = `<span>👤</span> Perfil de @${window.KUTSOCIAL_OWNER.username}`;
        }
    }
    
    // Reemplazar summary por botón Iniciar Sesión
    const summaryDiv = document.querySelector('.user-profile-summary');
    if (summaryDiv) {
        summaryDiv.innerHTML = `
            <button onclick="showLoginModal()" style="margin: 0; width: 100%; padding: 12px; font-weight:600; background: var(--primary); border:none; border-radius:10px; color:white; cursor:pointer;">🔑 Iniciar Sesión</button>
        `;
    }
    
    // Ocultar composer en feed
    const composer = document.querySelector('#tab-feed .composer-card');
    if (composer) {
        composer.style.display = 'none';
    }
    
    // Cargar perfil del dueño o enrutar por path
    if (!handlePathRouting() && window.KUTSOCIAL_OWNER) {
        viewProfile(window.KUTSOCIAL_OWNER.id);
    }
}

function showLoginModal() {
    document.documentElement.classList.remove('is-authenticated');
    document.getElementById('login-container').style.display = 'flex';
    
    let closeBtn = document.getElementById('btn-close-login');
    if (!closeBtn) {
        const card = document.querySelector('.login-card');
        closeBtn = document.createElement('a');
        closeBtn.id = 'btn-close-login';
        closeBtn.href = '#';
        closeBtn.innerText = 'Volver como Visitante';
        closeBtn.style.cssText = 'display:block; margin-top:15px; font-size:13.5px; color:var(--text-muted); text-decoration:none; font-weight: 500;';
        closeBtn.onclick = (e) => {
            e.preventDefault();
            document.documentElement.classList.add('is-authenticated');
            document.getElementById('login-container').style.display = 'none';
        };
        card.appendChild(closeBtn);
    }
}

let currentProfileData = null;
let replyToId = null;
let editTootId = null;
let quoteTootId = null;
let activeProfileViewId = null;
let activeProfileFeedToots = [];
let activeProfileSubtab = 'activity';
let activeThreadId = null;
let previousTab = 'feed';
let maxTootChars = 500;
let composerUploadedMediaIds = [];
let isPollActive = false;

const popularEmojis = [
    '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '😊', '😇',
    '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘', '😗', '😙', '😚',
    '😋', '😛', '😝', '😜', '🤪', '🤨', '🧐', '🤓', '😎', '🥸',
    '🥳', '😏', '😒', '😞', '😔', '😟', '😕', '🙁', '☹️', '😣',
    '😖', '😫', '😩', '🥺', '😢', '😭', '😤', '😠', '😡', '🤬',
    '🤯', '😳', '🥵', '🥶', '😱', '😨', '😰', '😥', '😓', '🤗',
    '🤔', '🤭', '🤫', '🤥', '😶', '😐', '😑', '😬', '🙄', '😯',
    '😦', '😧', '😮', '😲', '🥱', '😴', '🤤', '😪', '😵', '🤐',
    '🥴', '🤢', '🤮', '🤧', '😷', '🤒', '🤕', '😈', '👿', '👹',
    '👺', '💀', '☠️', '👻', '👽', '👾', '🤖', '🎃', '😺', '😸',
    '😹', '😻', '😼', '😽', '🙀', '😿', '😾', '👋', '🤚', '🖐️',
    '✋', '🖖', '👌', '🤌', '🤏', '✌️', '🤞', '🤟', '🤘', '🤙',
    '👈', '👉', '👆', '🖕', '👇', '☝️', '👍', '👎', '✊', '👊',
    '🤛', '🤜', '👏', '🙌', '👐', '🤲', '🤝', '🙏', '✍️', '💅',
    '🤳', '💪', '🦾', '🦿', '🦵', '🦶', '👂', '🦻', '👃', '🧠',
    '🫀', '🫁', '🦷', '🦴', '👀', '👁️', '👅', '👄', '💋', '🩸',
    '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔',
    '❤️‍🔥', '❤️‍🩹', '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝'
];

async function updateNotificationBadge() {
    if (!token) return;
    try {
        let frCount = 0;
        try {
            const frRes = await fetch('/api/v1/follow_requests', {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            if (frRes.ok) {
                const frData = await frRes.json();
                frCount = Array.isArray(frData) ? frData.length : 0;
            }
        } catch (e) {}

        let notifCount = 0;
        try {
            const res = await fetch('/api/v1/notifications', {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            if (res.ok) {
                const data = await res.json();
                notifCount = Array.isArray(data) ? data.filter(n => !n.read).length : 0;
            }
        } catch (e) {}

        const total = frCount + notifCount;
        const badge = document.getElementById('nav-notifications-count');
        if (badge) {
            if (total > 0) {
                badge.innerText = total;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
    } catch (e) {
        console.error("Error updating notification badge", e);
    }
}

async function resolveAndOpenProfile(username) {
    const expectedPath = window.location.pathname;
    try {
        const res = await fetch(`/api/v1/search?q=${encodeURIComponent('@' + username)}&resolve=true`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            const data = await res.json();
            if (window.location.pathname !== expectedPath) {
                return;
            }
            if (data.accounts && data.accounts.length > 0) {
                viewProfile(data.accounts[0].id, true);
            }
        }
    } catch (e) {
        console.error("Error al buscar perfil para enrutamiento", e);
    }
}

function handlePathRouting() {
    const path = window.location.pathname;
    if (path === '/public' || path === '/home' || path === '/local' || path === '/bookmarks' || path.startsWith('/list_') || path.startsWith('/tag_')) {
        const type = path.substring(1);
        switchTimeline(type, true);
        return true;
    } else if (path === '/notifications' || path === '/lists' || path === '/collections' || path === '/followed-hashtags' || path === '/profile' || path === '/search-results') {
        const tab = path.substring(1);
        if (tab === 'profile') {
            showTab('profile', true);
        } else {
            showTab(tab, true);
        }
        return true;
    } else if (path.startsWith('/@')) {
        const username = path.substring(2);
        if (username.startsWith('id-')) {
            const profileId = username.substring(3);
            viewProfile(profileId, true);
        } else if (window.KUTSOCIAL_OWNER && window.KUTSOCIAL_OWNER.username.toLowerCase() === username.toLowerCase()) {
            viewProfile(window.KUTSOCIAL_OWNER.id, true);
        } else {
            resolveAndOpenProfile(username);
        }
        return true;
    } else if (path.includes('/statuses/')) {
        const parts = path.split('/');
        const statusId = parts[parts.length - 1];
        viewTootThread(statusId, true);
        return true;
    }
    return false;
}

window.addEventListener('popstate', () => {
    handlePathRouting();
});

// Inicializar Aplicación Autenticada
async function initApp() {
    document.getElementById('login-container').style.display = 'none';
    document.getElementById('app-container').style.display = 'flex';

    // 1. Obtener datos del perfil
    try {
        const res = await fetch('/api/v1/accounts/verify_credentials', {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.status === 401) {
            logout();
            return;
        }
        const profile = await res.json();
        currentProfileData = profile;
        
        document.getElementById('my-display-name').innerText = profile.display_name;
        document.getElementById('my-username').innerText = '@' + profile.username;
        if (profile.avatar) {
            document.getElementById('my-avatar').src = profile.avatar;
            document.getElementById('composer-avatar').src = profile.avatar;
        }
        
        // Mostrar URL de perfil para configurar la etiqueta rel="me" en web externas
        document.getElementById('profile-verification-url-preview').innerText = profile.url;
    } catch (e) {
        console.error("Error al verificar perfil", e);
    }

    // Cargar la versión dinámica desde el servidor
    if (window.KUTSOCIAL_VERSION) {
        document.getElementById('stat-version').innerText = 'v' + window.KUTSOCIAL_VERSION;
    }

    // 2. Obtener estadísticas de instancia
    loadInstanceStats();

    // 3. Cargar datos según la sección renderizada por el servidor
    const activeSection = window.KUTSOCIAL_ACTIVE_SECTION || 'feed';
    const currentTimelineVal = window.KUTSOCIAL_CURRENT_TIMELINE || 'public';
    const activeProfileId = window.KUTSOCIAL_ACTIVE_PROFILE_VIEW_ID;
    const activeThreadId = window.KUTSOCIAL_ACTIVE_THREAD_ID;
    
    if (activeSection === 'feed') {
        currentTimeline = currentTimelineVal;
        showTab('feed', true);
        loadTimeline();
    } else if (activeSection === 'profile-view') {
        if (activeProfileId) {
            viewProfile(activeProfileId, true);
        }
    } else if (activeSection === 'thread-view') {
        if (activeThreadId) {
            viewTootThread(activeThreadId, true);
        }
    } else {
        showTab(activeSection, true);
    }

    // Cargar e iniciar badge de notificaciones
    updateNotificationBadge();
    setInterval(updateNotificationBadge, 30000);
}

async function loadInstanceStats() {
    try {
        const res = await fetch('/api/v1/instance');
        const data = await res.json();
        document.getElementById('stat-users').innerText = data.stats.user_count;
        document.getElementById('stat-toots').innerText = data.stats.status_count;
        if (data.kutsocial_version) {
            document.getElementById('stat-version').innerText = 'v' + data.kutsocial_version;
        }
        if (data.configuration && data.configuration.statuses && data.configuration.statuses.max_characters) {
            maxTootChars = parseInt(data.configuration.statuses.max_characters);
            const composerText = document.getElementById('composer-text');
            if (composerText) {
                composerText.setAttribute('maxlength', maxTootChars);
            }
            updateCharCount();
        }
    } catch (e) {}
}

async function loadTimeline(loadMore = false) {
    if (isLoadingToots) return;
    if (loadMore && !hasMoreToots) return;

    isLoadingToots = true;

    const feed = document.getElementById('feed');
    let loadingIndicator = null;
    const isTimelineChange = !loadMore && (lastRenderedTimeline !== currentTimeline || feed.innerHTML === '' || feed.innerHTML.includes('Cargando toots...'));

    if (!loadMore) {
        if (isTimelineChange) {
            feed.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Cargando toots...</div>';
            lastId = 0;
            oldestId = null;
        }
        hasMoreToots = true;
    } else {
        loadingIndicator = document.createElement('div');
        loadingIndicator.id = 'infinite-scroll-loading';
        loadingIndicator.style = 'text-align:center; padding: 20px; color: var(--text-muted);';
        loadingIndicator.innerText = 'Cargando más publicaciones...';
        feed.appendChild(loadingIndicator);
    }

    let url = '/api/v1/timelines/public';
    if (currentTimeline === 'home') {
        url = '/api/v1/timelines/home';
    } else if (currentTimeline === 'local') {
        url = '/api/v1/timelines/public?local=true';
    } else if (currentTimeline === 'bookmarks') {
        url = '/api/v1/bookmarks';
    } else if (currentTimeline.startsWith('list_')) {
        const listId = currentTimeline.split('_')[1];
        url = `/api/v1/timelines/list/${listId}`;
    } else if (currentTimeline.startsWith('tag_')) {
        const tagName = currentTimeline.split('_')[1];
        url = `/api/v1/timelines/tag/${tagName}`;
    }

    const limit = 15;
    const separator = url.includes('?') ? '&' : '?';
    let fetchUrl = `${url}${separator}limit=${limit}`;
    if (loadMore && oldestId !== null) {
        fetchUrl += `&max_id=${oldestId}`;
    }

    try {
        const res = await fetch(fetchUrl, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        const toots = await res.json();

        if (loadingIndicator && loadingIndicator.parentNode) {
            loadingIndicator.parentNode.removeChild(loadingIndicator);
        }

        // Si es refresco (no cambio de timeline) y no hay toots nuevos, salir silenciosamente
        if (!loadMore && !isTimelineChange && toots.length > 0) {
            const newestId = parseInt(toots[0].id);
            if (newestId <= lastId) {
                isLoadingToots = false;
                return;
            }
        }

        if (!loadMore) {
            feed.innerHTML = '';
            lastId = 0;
            oldestId = null;
        }

        if (toots.length === 0) {
            if (!loadMore) {
                feed.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">No hay publicaciones todavía.</div>';
            }
            hasMoreToots = false;
            isLoadingToots = false;
            return;
        }

        toots.forEach(toot => {
            const idInt = parseInt(toot.id);
            if (!loadMore) {
                if (idInt > lastId) {
                    lastId = idInt;
                }
            }
            if (oldestId === null || idInt < oldestId) {
                oldestId = idInt;
            }
            prependToot(toot, false);
        });

        if (toots.length < limit) {
            hasMoreToots = false;
        }

        if (!loadMore) {
            lastRenderedTimeline = currentTimeline;
            if (currentTimeline !== 'bookmarks') {
                startStreaming();
            } else if (eventSource) {
                eventSource.close();
            }
        }
    } catch (e) {
        if (loadingIndicator && loadingIndicator.parentNode) {
            loadingIndicator.parentNode.removeChild(loadingIndicator);
        }
        if (!loadMore) {
            feed.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar timeline.</div>';
        }
    } finally {
        isLoadingToots = false;
    }
}

// Conectar al endpoint SSE Streaming
function startStreaming() {
    if (eventSource) {
        eventSource.close();
    }

    let queryStr = `since_id=${lastId}`;
    if (token) {
        queryStr += `&access_token=${token}`;
    }
    if (currentTimeline === 'home') {
        queryStr += `&stream=user`;
    } else if (currentTimeline === 'local') {
        queryStr += `&stream=public:local`;
    } else if (currentTimeline.startsWith('tag_')) {
        const tagName = currentTimeline.split('_')[1];
        queryStr += `&stream=hashtag&tag=${tagName}`;
    } else if (currentTimeline.startsWith('list_')) {
        const listId = currentTimeline.split('_')[1];
        queryStr += `&stream=list&list_id=${listId}`;
    } else {
        queryStr += `&stream=public`;
    }

    eventSource = new EventSource(`/api/v1/streaming?${queryStr}`);
    
    eventSource.addEventListener('update', (e) => {
        const toot = JSON.parse(e.data);
        const idInt = parseInt(toot.id);
        if (idInt > lastId) {
            lastId = idInt;
        }
        prependToot(toot, true);
        
        const tootStat = document.getElementById('stat-toots');
        tootStat.innerText = parseInt(tootStat.innerText || 0) + 1;
    });

    eventSource.onerror = (e) => {
        console.log("Conexión SSE interrumpida. Reintentando...");
    };
}

function prependToot(toot, isNew = false) {
    const feed = document.getElementById('feed');
    
    if (feed.querySelector(`[data-toot-id="${toot.id}"]`)) {
        return;
    }
    
    if (feed.children.length === 1 && feed.children[0].innerText.includes('No hay publicaciones')) {
        feed.innerHTML = '';
    }

    const card = createThreadTootElement(toot, false);
    if (isNew) {
        card.style.borderLeft = '3px solid var(--secondary)';
        feed.insertBefore(card, feed.firstChild);
    } else {
        feed.appendChild(card);
    }
}

function createThreadTootElement(toot, isMain = false) {
    let isReblog = false;
    let rebloggedBy = null;
    let originalTootId = toot.id;
    
    if (toot.reblog) {
        isReblog = true;
        rebloggedBy = toot.account;
        toot = toot.reblog;
    }

    const card = document.createElement('div');
    card.className = 'toot-card';
    card._toot = toot;
    card.setAttribute('data-toot-id', originalTootId);
    if (toot.visibility === 'direct') {
        card.classList.add('toot-direct');
    } else if (toot.visibility === 'private') {
        card.classList.add('toot-private');
    }
    if (isMain) {
        card.style.background = 'rgba(99, 102, 241, 0.05)';
        card.style.borderLeft = '3px solid var(--primary)';
    }

    const absoluteDateStr = new Date(toot.created_at).toLocaleDateString('es-ES', {
        hour: '2-digit', minute: '2-digit'
    });
    const relativeDateStr = formatRelativeTime(toot.created_at);

    const isMyToot = currentProfileData && String(toot.account.id) === String(currentProfileData.id);
    const favClass = toot.favourited ? 'active-fav' : '';
    const bookmarkClass = toot.bookmarked ? 'active-bookmark' : '';

    let sanitizedContent = sanitizeHTML(toot.content);
    
    let quotePlaceholderHTML = '';
    const statusUrlRegex = /(\/(users|@)[a-zA-Z0-9_\-\.]+\/(statuses\/)?[a-zA-Z0-9]+)|(\/(notice|objects|display)\/[a-zA-Z0-9_\-\.]+)/i;
    
    const quotedToot = toot.quote ? (toot.quote.quoted_status || toot.quote) : null;
    
    if (quotedToot) {
        const quotePlaceholderId = `quote-placeholder-${toot.id}-${Math.floor(Math.random() * 100000)}`;
        quotePlaceholderHTML = `<div id="${quotePlaceholderId}" class="quote-toot-embed-container" style="margin-top:12px; border: 1px solid var(--border-color); border-radius:12px; padding:12px; background: rgba(255,255,255,0.02); transition: background 0.2s;"></div>`;
        
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = sanitizedContent;
        const links = tempDiv.querySelectorAll('a');
        for (let link of links) {
            const href = link.href || '';
            if (href === quotedToot.uri || href === quotedToot.url || statusUrlRegex.test(href)) {
                let prevNode = link.previousSibling;
                if (prevNode && prevNode.nodeType === 3 && prevNode.textContent.trim().endsWith('RE:')) {
                    const txt = prevNode.textContent;
                    prevNode.textContent = txt.substring(0, txt.lastIndexOf('RE:'));
                }
                link.remove();
            }
        }
        sanitizedContent = tempDiv.innerHTML;
        
        setTimeout(() => {
            renderEmbeddedQuote(quotePlaceholderId, quotedToot);
        }, 0);
    } else {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = sanitizedContent;
        const links = tempDiv.querySelectorAll('a');
        let quoteUrl = null;
        
        for (let link of links) {
            const href = link.href || '';
            if (statusUrlRegex.test(href)) {
                quoteUrl = href;
                break;
            }
        }

        if (quoteUrl) {
            for (let link of links) {
                if (link.href === quoteUrl) {
                    let prevNode = link.previousSibling;
                    if (prevNode && prevNode.nodeType === 3 && prevNode.textContent.trim().endsWith('RE:')) {
                        const txt = prevNode.textContent;
                        prevNode.textContent = txt.substring(0, txt.lastIndexOf('RE:'));
                    }
                    link.remove();
                }
            }
            sanitizedContent = tempDiv.innerHTML;

            const quotePlaceholderId = `quote-placeholder-${toot.id}-${Math.floor(Math.random() * 100000)}`;
            quotePlaceholderHTML = `<div id="${quotePlaceholderId}" class="quote-toot-embed-container" style="margin-top:12px; border: 1px solid var(--border-color); border-radius:12px; padding:12px; background: rgba(255,255,255,0.02); transition: background 0.2s;">Cargando publicación citada...</div>`;
            
            setTimeout(async () => {
                try {
                    const res = await fetch(`/api/v1/statuses/resolve?url=${encodeURIComponent(quoteUrl)}`, {
                        headers: { 'Authorization': `Bearer ${token}` }
                    });
                    if (res.ok) {
                        const resolvedToot = await res.json();
                        renderEmbeddedQuote(quotePlaceholderId, resolvedToot);
                    } else {
                        const el = document.getElementById(quotePlaceholderId);
                        if (el) el.remove();
                    }
                } catch (e) {
                    console.error("Error al resolver toot citado", e);
                    const el = document.getElementById(quotePlaceholderId);
                    if (el) el.remove();
                }
            }, 50);
        }
    }

    let contentHTML = `<div class="toot-content" style="${isMain ? 'font-size: 18px; line-height: 1.5; margin-bottom: 12px;' : ''}">${sanitizedContent}</div>`;
    if (toot.sensitive) {
        contentHTML = `
            <div style="margin-top: 8px;">
                <span style="color: #f59e0b; font-weight: 600; font-size: 13.5px;">⚠️ Advertencia: ${escapeHTML(toot.spoiler_text || 'CW')}</span>
                <div>
                    <button class="cw-spoiler-btn" onclick="toggleCWSpoiler(this)">Mostrar más</button>
                    <div class="cw-spoiler-content" style="display: none;">
                        <div class="toot-content" style="${isMain ? 'font-size: 18px; line-height: 1.5;' : ''}">${sanitizedContent}</div>
                    </div>
                </div>
            </div>
        `;
    }

    // Renderizar Multimedia subida si existe
    let mediaHTML = '';
    if (toot.media_attachments && toot.media_attachments.length > 0) {
        mediaHTML = `<div style="display: flex; flex-direction: column; gap: 12px; margin-top: 10px; width: 100%;">`;
        toot.media_attachments.forEach(media => {
            const type = (media.type || '').toLowerCase();
            let url = media.url || '';
            const altText = media.description ? escapeHTML(media.description) : '';
            
            const isVideo = type === 'video' || type === 'gifv' || url.endsWith('.mp4') || url.endsWith('.webm') || url.endsWith('.mov') || url.endsWith('.m4v');
            if (!isVideo && type !== 'audio') {
                url = proxyUrl(url);
            }
            
            if (type === 'audio' || url.endsWith('.mp3') || url.endsWith('.ogg') || url.endsWith('.wav') || url.endsWith('.m4a') || url.endsWith('.aac')) {
                mediaHTML += `
                    <div class="toot-media-item" style="border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color); width: 100%; padding: 8px; display: flex; flex-direction: column; background: rgba(0,0,0,0.2);">
                        <audio src="${url}" controls style="width: 100%;"></audio>
                    </div>
                `;
            } else {
                // Para Imagen, Video, Gifv
                const mediaTag = isVideo 
                    ? `<video src="${media.url}" poster="${proxyUrl(media.preview_url || '')}" controls loop playsinline style="width: 100%; max-height: 380px; background: #000; display: block; margin: 0 auto; border-radius: 4px;"></video>`
                    : `<img src="${url}" alt="${altText}" title="${altText}" style="max-width: 100%; max-height: 450px; object-fit: contain; cursor: pointer; display: block; margin: 0 auto; border-radius: 4px;" onclick="window.open('${url}', '_blank')">`;

                mediaHTML += `
                    <div class="toot-media-card" data-sensitive="${toot.sensitive ? 'true' : 'false'}" style="position: relative; border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color); background: #0b0c10; width: 100%; display: flex; flex-direction: column;">
                        
                        <!-- Botón superior derecho de ocultar/mostrar -->
                        <button class="media-toggle-btn" onclick="toggleMediaVisibility(this)" style="position: absolute; top: 12px; right: 12px; background: rgba(0,0,0,0.65); color: #fff; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; border: none; cursor: pointer; z-index: 20; transition: background 0.2s;">
                            ${toot.sensitive ? 'Show' : 'Hide'}
                        </button>

                        <!-- Contenedor del elemento multimedia -->
                        <div class="media-wrapper" style="display: ${toot.sensitive ? 'none' : 'block'}; position: relative; width: 100%;">
                            <div style="display: flex; justify-content: center; width: 100%; position: relative;">
                                ${mediaTag}
                            </div>
                        </div>

                        <!-- Botón ALT en la esquina inferior izquierda -->
                        ${altText ? `
                        <button class="media-alt-btn" onclick="toggleAltTextPopup(this, event)" style="display: ${toot.sensitive ? 'none' : 'block'}; position: absolute; bottom: 12px; left: 12px; background: rgba(0,0,0,0.75); color: #fff; font-size: 10px; font-weight: bold; padding: 3px 8px; border-radius: 4px; border: none; cursor: pointer; z-index: 20; transition: background 0.2s;">
                            ALT
                        </button>
                        
                        <!-- Popup flotante de Alt text en la parte inferior izquierda -->
                        <div class="media-alt-popup" style="display: none; position: absolute; bottom: 42px; left: 12px; width: 280px; max-width: 90%; background: #1e1f22; border: 1px solid #2f3037; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.5); z-index: 30; text-align: left; padding: 12px; color: #fff; font-family: sans-serif;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; border-bottom: 1px solid #2f3037; padding-bottom: 6px;">
                                <span style="font-size: 12px; font-weight: bold; color: var(--text-color);">Alt text</span>
                                <button onclick="closeAltTextPopup(this, event)" style="background: none; border: none; color: #aaa; cursor: pointer; font-size: 14px; padding: 0 4px; line-height: 1;">✕</button>
                            </div>
                            <div style="font-size: 12.5px; line-height: 1.4; color: #e3e4e8; word-break: break-word;">${altText}</div>
                        </div>
                        ` : ''}

                        <!-- Contenedor de Advertencia / Contenido Sensible -->
                        <div class="media-placeholder" style="display: ${toot.sensitive ? 'flex' : 'none'}; height: 220px; align-items: center; justify-content: center; flex-direction: column; background: #16181d; color: #ff9800; cursor: pointer; user-select: none;" onclick="revealMediaFromPlaceholder(this)">
                            <span style="font-size: 32px; margin-bottom: 8px;">⚠️</span>
                            <span style="font-size: 14px; font-weight: 600; color: var(--text-color);">${toot.spoiler_text || 'Contenido Sensible'}</span>
                            <span style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Haz clic para mostrar el contenido multimedia</span>
                        </div>
                    </div>
                `;
            }
        });
        mediaHTML += `</div>`;
    }

    // Renderizar Tarjeta de Enlace (Card preview) si existe
    let cardHTML = '';
    if (toot.card) {
        const card = toot.card;
        const authorAttributionHTML = (card.author_name && card.author_url) ? `
            <div style="display: flex; align-items: center; gap: 6px; margin-top: 8px; border-top: 1px dashed rgba(255,255,255,0.05); padding-top: 6px; font-size: 11.5px; color: var(--text-muted);">
                <span class="material-icons-outlined" style="font-size: 14px; color: var(--primary);">person</span>
                <span>Más de: <a href="#" onclick="event.preventDefault(); event.stopPropagation(); viewProfileByUrl('${card.author_url}')" style="color: var(--primary); font-weight: 600; text-decoration: none;">${escapeHTML(card.author_name)}</a></span>
            </div>
        ` : '';

        const parsedDomain = (u) => { try { return new URL(u).hostname; } catch(e) { return ''; } };

        cardHTML = `
            <div class="toot-link-card" onclick="window.open('${card.url}', '_blank')" style="display: flex; border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; margin-top: 12px; cursor: pointer; background: rgba(255,255,255,0.01); transition: all 0.2s; max-height: 140px; text-align: left; width: 100%;">
                ${card.image ? `
                    <div style="width: 120px; min-width: 120px; background-image: url('${card.image}'); background-size: cover; background-position: center; border-right: 1px solid var(--border-color);"></div>
                ` : ''}
                <div style="padding: 12px; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; flex: 1;">
                    <div>
                        <div style="font-weight: 700; font-size: 14px; color: var(--text-color); margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHTML(card.title)}</div>
                        <div style="font-size: 12px; color: var(--text-muted); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis;">${escapeHTML(card.description)}</div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; font-size: 11px; color: var(--text-muted);">
                        <span>🔗 ${escapeHTML(card.provider_name || parsedDomain(card.url))}</span>
                    </div>
                    ${authorAttributionHTML}
                </div>
            </div>
        `;
    }

    // Renderizar Encuesta interactiva si existe
    let pollHTML = '';
    if (toot.poll) {
        const poll = toot.poll;
        pollHTML = `<div class="toot-poll-card" id="poll-${poll.id}" style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; margin-top: 12px; display: flex; flex-direction: column; gap: 10px;">`;
        
        const showResults = poll.voted || poll.expired;
        if (showResults) {
            poll.options.forEach((opt, idx) => {
                const pct = poll.votes_count > 0 ? Math.round((opt.votes_count / poll.votes_count) * 100) : 0;
                const isOwnVote = poll.own_vote === idx;
                pollHTML += `
                    <div>
                        <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px;">
                            <span>${isOwnVote ? '☑️' : ''} ${escapeHTML(opt.title)}</span>
                            <strong>${pct}% (${opt.votes_count})</strong>
                        </div>
                        <div style="background: rgba(255,255,255,0.05); height: 8px; border-radius: 4px; overflow: hidden; border:1px solid var(--border-color);">
                            <div style="background: #4f46e5; width: ${pct}%; height: 100%; border-radius: 4px;"></div>
                        </div>
                    </div>
                `;
            });
        } else {
            poll.options.forEach((opt, idx) => {
                pollHTML += `
                    <button onclick="voteInPoll('${poll.id}', ${idx}, this)" style="text-align: left; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; font-family: inherit; font-size: 13px; color: white; cursor: pointer; transition: background 0.3s; font-weight: 500; width: 100%;">
                        🔘 ${escapeHTML(opt.title)}
                    </button>
                `;
            });
        }
        
        const expDateStr = new Date(poll.expires_at).toLocaleString('es-ES', {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        });
        pollHTML += `
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; display: flex; justify-content: space-between;">
                <span>${poll.votes_count} votos</span>
                <span>${poll.expired ? 'Finalizado' : 'Expira: ' + expDateStr}</span>
            </div>
        </div>`;
    }

    let reblogHeaderHTML = '';
    if (isReblog) {
        reblogHeaderHTML = `
            <div class="toot-reblog-header" style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-muted); margin-bottom: 8px; margin-left: 36px;">
                <span class="material-icons" style="font-size: 16px; color: #10b981;">repeat</span>
                <span><strong>${escapeHTML(rebloggedBy.display_name || rebloggedBy.username)}</strong> ha re-tooteado</span>
            </div>
        `;
    }

    card.innerHTML = `
        ${reblogHeaderHTML}
        <div class="toot-card-body">
            <a href="/@${toot.account.acct}"><img class="user-avatar clickable-actor" src="${proxyUrl(toot.account.avatar)}" alt="Avatar"></a>
            <div style="flex-grow: 1; min-width: 0;">
                <div class="toot-header">
                    <a href="/@${toot.account.acct}" class="toot-author-details clickable-actor" style="text-decoration: none; color: inherit;">
                        <span class="toot-author-name">${toot.account.display_name}</span>
                        <span class="toot-author-handle">@${toot.account.acct}</span>
                        ${toot.visibility === 'direct' ? `<span class="badge-direct">MENSAJE PRIVADO</span>` : ''}
                        ${toot.visibility === 'private' ? `<span class="badge-private">SOLO SEGUIDORES</span>` : ''}
                    </a>
                    <a href="/users/${toot.account.username || 'iam'}/statuses/${toot.id}" class="toot-time clickable-actor" title="${absoluteDateStr}">${relativeDateStr}</a>
                </div>
                ${contentHTML}
                ${mediaHTML}
                ${cardHTML}
                ${pollHTML}
                ${quotePlaceholderHTML}
                
                <div class="toot-actions" style="margin-top: 12px;">
                    <button class="toot-action-btn btn-reply" onclick="handleReplyButtonClick(this)" title="Responder">
                        <span class="material-icons-outlined">chat_bubble_outline</span> <span>${toot.replies_count || 0}</span>
                    </button>
                    <button class="toot-action-btn ${favClass}" onclick="toggleFavourite('${toot.id}', this)" title="Favorito">
                        <span class="${toot.favourited ? 'material-icons' : 'material-icons-outlined'}">${toot.favourited ? 'star' : 'star_border'}</span> <span class="fav-count">${toot.favourites_count || 0}</span>
                    </button>
                    <button class="toot-action-btn btn-reblog ${toot.reblogged ? 'active-reblog' : ''}" data-reblog-toot-id="${toot.id}" onclick="handleReblogButtonClick('${toot.id}', this, event)" title="Compartir / Citar">
                        <span class="${toot.reblogged ? 'material-icons' : 'material-icons-outlined'}" style="${toot.reblogged ? 'color: #10b981;' : ''}">repeat</span> <span class="reblog-count" style="${toot.reblogged ? 'color: #10b981; font-weight: bold;' : ''}">${toot.reblogs_count || 0}</span>
                    </button>
                    <button class="toot-action-btn ${bookmarkClass}" onclick="toggleBookmark('${toot.id}', this)" title="Guardar">
                        <span class="${toot.bookmarked ? 'material-icons' : 'material-icons-outlined'}">${toot.bookmarked ? 'bookmark' : 'bookmark_border'}</span>
                    </button>
                    ${isMyToot ? `
                        <button class="toot-action-btn btn-edit" onclick="handleEditButtonClick(this)" title="Editar">
                            <span class="material-icons-outlined">edit</span>
                        </button>
                        <button class="toot-action-btn btn-delete" onclick="deleteToot('${toot.id}', this)" title="Eliminar">
                            <span class="material-icons-outlined">delete</span>
                        </button>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
    return card;
}

function handleReplyButtonClick(btn) {
    const card = btn.closest('.toot-card');
    if (card && card._toot) {
        const toot = card._toot;
        initReplyToToot(toot.id, toot.account.acct, toot.visibility);
    }
}

function handleEditButtonClick(btn) {
    const card = btn.closest('.toot-card');
    if (card && card._toot) {
        const toot = card._toot;
        initEditToot(toot.id, toot.text || toot.content, toot.sensitive, toot.spoiler_text || '');
    }
}

let activeReblogMenu = null;

function handleReblogButtonClick(tootId, btn, event) {
    if (event) {
        event.stopPropagation();
        event.preventDefault();
    }

    if (activeReblogMenu) {
        activeReblogMenu.remove();
        activeReblogMenu = null;
    }

    const card = btn.closest('.toot-card');
    if (!card || !card._toot) return;
    const toot = card._toot;

    const rect = btn.getBoundingClientRect();
    const menu = document.createElement('div');
    menu.className = 'reblog-context-menu';
    menu.style = `
        position: fixed;
        top: ${rect.bottom + 6}px;
        left: ${rect.left}px;
        background: #1e1f22;
        border: 1px solid #2f3037;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.5);
        z-index: 9999;
        display: flex;
        flex-direction: column;
        padding: 6px 0;
        min-width: 140px;
    `;

    const optReblog = document.createElement('button');
    if (toot.reblogged) {
        optReblog.innerHTML = '<span class="material-icons" style="font-size:16px; margin-right:8px; color: #10b981;">repeat</span> Deshacer Re-toot';
    } else {
        optReblog.innerHTML = '<span class="material-icons-outlined" style="font-size:16px; margin-right:8px; color: var(--text-color);">repeat</span> Re-tootear';
    }
    optReblog.style = 'background:none; border:none; color:#fff; padding: 8px 16px; text-align:left; font-size:13px; cursor:pointer; display:flex; align-items:center; width:100%;';
    optReblog.onmouseover = () => optReblog.style.background = 'rgba(255,255,255,0.06)';
    optReblog.onmouseout = () => optReblog.style.background = 'none';
    optReblog.onclick = (e) => {
        e.stopPropagation();
        menu.remove();
        activeReblogMenu = null;
        executeReblog(tootId, btn, !toot.reblogged);
    };

    const optQuote = document.createElement('button');
    optQuote.innerHTML = '<span class="material-icons-outlined" style="font-size:16px; margin-right:8px; color: var(--text-color);">format_quote</span> Citar publicación';
    optQuote.style = 'background:none; border:none; color:#fff; padding: 8px 16px; text-align:left; font-size:13px; cursor:pointer; display:flex; align-items:center; width:100%;';
    optQuote.onmouseover = () => optQuote.style.background = 'rgba(255,255,255,0.06)';
    optQuote.onmouseout = () => optQuote.style.background = 'none';
    optQuote.onclick = (e) => {
        e.stopPropagation();
        menu.remove();
        activeReblogMenu = null;
        initQuoteToot(tootId, toot.account.acct, toot.uri);
    };

    menu.appendChild(optReblog);
    menu.appendChild(optQuote);
    document.body.appendChild(menu);
    activeReblogMenu = menu;

    setTimeout(() => {
        const closeMenu = () => {
            if (activeReblogMenu) {
                activeReblogMenu.remove();
                activeReblogMenu = null;
            }
            document.removeEventListener('click', closeMenu);
        };
        document.addEventListener('click', closeMenu);
    }, 50);
}

async function executeReblog(tootId, btn, isReblog) {
    const action = isReblog ? 'reblog' : 'unreblog';
    try {
        const res = await fetch(`/api/v1/statuses/${tootId}/${action}`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            const updatedToot = await res.json();
            const targetToot = updatedToot.reblog ? updatedToot.reblog : updatedToot;
            
            const card = btn.closest('.toot-card');
            if (card) {
                card._toot = targetToot;
            }

            const reblogBtns = document.querySelectorAll(`button.btn-reblog[data-reblog-toot-id="${tootId}"]`);
            reblogBtns.forEach(rebBtn => {
                const icon = rebBtn.querySelector('.material-icons') || rebBtn.querySelector('.material-icons-outlined');
                const countSpan = rebBtn.querySelector('.reblog-count');
                
                if (targetToot.reblogged) {
                    rebBtn.classList.add('active-reblog');
                    if (icon) {
                        icon.className = 'material-icons';
                        icon.style.color = '#10b981';
                    }
                    if (countSpan) {
                        countSpan.innerText = targetToot.reblogs_count;
                        countSpan.style.color = '#10b981';
                        countSpan.style.fontWeight = 'bold';
                    }
                } else {
                    rebBtn.classList.remove('active-reblog');
                    if (icon) {
                        icon.className = 'material-icons-outlined';
                        icon.style.color = '';
                    }
                    if (countSpan) {
                        countSpan.innerText = targetToot.reblogs_count;
                        countSpan.style.color = '';
                        countSpan.style.fontWeight = '';
                    }
                }
            });
        } else {
            alert('No se pudo procesar la acción del re-toot.');
        }
    } catch (e) {
        console.error("Error al re-tootear", e);
    }
}

function initQuoteToot(id, handle, uri) {
    quoteTootId = id;
    replyToId = null;
    editTootId = null;
    document.getElementById('composer-context').style.display = 'flex';
    document.getElementById('composer-context-text').innerText = `Citando publicación de @${handle}`;
    
    const textarea = document.getElementById('composer-text');
    textarea.value = '';
    textarea.focus();
    updateCharCount();
    showTab('feed');
}

function renderEmbeddedQuote(placeholderId, quotedToot) {
    const container = document.getElementById(placeholderId);
    if (!container) return;

    const absoluteDate = new Date(quotedToot.created_at).toLocaleDateString('es-ES', {
        hour: '2-digit', minute: '2-digit'
    });
    const relativeTime = formatRelativeTime(quotedToot.created_at);
    
    let mediaHTML = '';
    if (quotedToot.media_attachments && quotedToot.media_attachments.length > 0) {
        mediaHTML = `<div style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;">`;
        quotedToot.media_attachments.forEach(media => {
            const type = (media.type || '').toLowerCase();
            const url = type === 'audio' ? '' : proxyUrl(media.url);
            if (url) {
                mediaHTML += `<img src="${url}" style="width:50px; height:50px; object-fit:cover; border-radius:6px;" onclick="event.stopPropagation(); window.open('${url}', '_blank')">`;
            }
        });
        mediaHTML += `</div>`;
    }

    container.style.cursor = 'pointer';
    container.onclick = (e) => {
        e.stopPropagation();
        viewTootThread(quotedToot.id);
    };
    container.onmouseover = () => container.style.background = 'rgba(255,255,255,0.04)';
    container.onmouseout = () => container.style.background = 'rgba(255,255,255,0.02)';

    let cleanContent = sanitizeHTML(quotedToot.content);

    container.innerHTML = `
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
            <img src="${proxyUrl(quotedToot.account.avatar)}" style="width:20px; height:20px; border-radius:50%;" alt="Avatar">
            <span style="font-weight:600; font-size:12.5px; color:var(--text-color);">${escapeHTML(quotedToot.account.display_name || quotedToot.account.username)}</span>
            <span style="font-size:11.5px; color:var(--text-muted);">@${quotedToot.account.acct}</span>
            <span style="font-size:11.5px; color:var(--text-muted); margin-left:auto;" title="${absoluteDate}">${relativeTime}</span>
        </div>
        <div style="font-size:12.5px; line-height:1.4; color:var(--text-color);">${cleanContent}</div>
        ${mediaHTML}
    `;
}

// Variables para Autocompletado de menciones
let autocompleteActiveIndex = -1;
let autocompleteSuggestions = [];
let activeWordInfo = null;
let autocompleteTimeout = null;

function getActiveWord(textarea) {
    const text = textarea.value;
    const selectionEnd = textarea.selectionEnd;
    
    let wordStart = selectionEnd - 1;
    while (wordStart >= 0 && text[wordStart] !== ' ' && text[wordStart] !== '\n') {
        wordStart--;
    }
    wordStart++;
    
    const word = text.slice(wordStart, selectionEnd);
    return {
        word: word,
        start: wordStart,
        end: selectionEnd
    };
}

function handleComposerInput(e) {
    const textarea = e.target;
    const info = getActiveWord(textarea);
    
    if (info.word.startsWith('@')) {
        const query = info.word.substring(1);
        activeWordInfo = info;
        fetchAutocompleteSuggestions(query);
    } else {
        closeAutocompleteDropdown();
    }
}

function fetchAutocompleteSuggestions(query) {
    clearTimeout(autocompleteTimeout);
    autocompleteTimeout = setTimeout(async () => {
        try {
            const res = await fetch(`/api/v1/search?type=accounts&q=${encodeURIComponent(query)}`, {
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            if (res.ok) {
                const data = await res.json();
                autocompleteSuggestions = data.accounts || [];
                renderAutocompleteDropdown();
            }
        } catch (err) {
            console.error("Error al obtener sugerencias de autocompletado", err);
        }
    }, 150);
}

function renderAutocompleteDropdown() {
    const dropdown = document.getElementById('composer-autocomplete');
    if (!dropdown) return;
    
    if (autocompleteSuggestions.length === 0) {
        dropdown.style.display = 'none';
        return;
    }
    
    dropdown.innerHTML = '';
    dropdown.style.display = 'block';
    autocompleteActiveIndex = 0;
    
    autocompleteSuggestions.forEach((account, idx) => {
        const item = document.createElement('div');
        item.className = 'autocomplete-suggestion-item' + (idx === 0 ? ' active' : '');
        item.setAttribute('data-index', idx);
        
        const avatar = account.avatar || '/assets/default-avatar.png';
        const display_name = account.display_name || account.username;
        
        item.innerHTML = `
            <img class="autocomplete-suggestion-avatar" src="${avatar}" alt="">
            <div style="display: flex; flex-direction: column; min-width: 0; text-align: left;">
                <span class="autocomplete-suggestion-name">${escapeHTML(display_name)}</span>
                <span class="autocomplete-suggestion-handle">@${escapeHTML(account.acct)}</span>
            </div>
        `;
        
        item.addEventListener('click', () => {
            selectAutocompleteSuggestion(idx);
        });
        
        dropdown.appendChild(item);
    });
}

function selectAutocompleteSuggestion(idx) {
    const account = autocompleteSuggestions[idx];
    if (!account || !activeWordInfo) return;
    
    const textarea = document.getElementById('composer-text');
    const text = textarea.value;
    const mention = `@${account.acct} `;
    
    const before = text.slice(0, activeWordInfo.start);
    const after = text.slice(activeWordInfo.end);
    
    textarea.value = before + mention + after;
    
    const newCursorPos = activeWordInfo.start + mention.length;
    textarea.focus();
    textarea.setSelectionRange(newCursorPos, newCursorPos);
    
    closeAutocompleteDropdown();
    updateCharCount();
}

function handleComposerKeydown(e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        publishToot();
        return;
    }

    const dropdown = document.getElementById('composer-autocomplete');
    if (!dropdown || dropdown.style.display === 'none') return;
    
    const items = dropdown.querySelectorAll('.autocomplete-suggestion-item');
    if (items.length === 0) return;
    
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        items[autocompleteActiveIndex].classList.remove('active');
        autocompleteActiveIndex = (autocompleteActiveIndex + 1) % items.length;
        items[autocompleteActiveIndex].classList.add('active');
        items[autocompleteActiveIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        items[autocompleteActiveIndex].classList.remove('active');
        autocompleteActiveIndex = (autocompleteActiveIndex - 1 + items.length) % items.length;
        items[autocompleteActiveIndex].classList.add('active');
        items[autocompleteActiveIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        selectAutocompleteSuggestion(autocompleteActiveIndex);
    } else if (e.key === 'Escape') {
        e.preventDefault();
        closeAutocompleteDropdown();
    }
}

function closeAutocompleteDropdown() {
    const dropdown = document.getElementById('composer-autocomplete');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
    autocompleteActiveIndex = -1;
    autocompleteSuggestions = [];
    activeWordInfo = null;
}

// Registrar cierre de dropdown al hacer clic fuera del composer
document.addEventListener('click', (e) => {
    const dropdown = document.getElementById('composer-autocomplete');
    const textarea = document.getElementById('composer-text');
    if (dropdown && e.target !== dropdown && e.target !== textarea && !dropdown.contains(e.target)) {
        closeAutocompleteDropdown();
    }
});

async function publishToot() {
    const textarea = document.getElementById('composer-text');
    const text = textarea.value.trim();
    if (text === '' && composerUploadedMediaIds.length === 0) return;

    const visibility = document.getElementById('composer-visibility').value;
    const isCWActive = document.getElementById('composer-cw-container').style.display !== 'none';
    const cwText = isCWActive ? document.getElementById('composer-cw-text').value.trim() : '';

    const payload = {
        status: text,
        visibility: visibility,
        sensitive: isCWActive,
        spoiler_text: cwText
    };

    if (composerUploadedMediaIds.length > 0) {
        // Verificar si la preferencia de advertencia de AltText está activa
        const warnMissingAlt = localStorage.getItem('kutsocial_warn_missing_alt') !== 'false';
        if (warnMissingAlt) {
            const altInputs = document.querySelectorAll('#composer-media-preview .media-preview-item input');
            let missingAlt = false;
            altInputs.forEach(input => {
                if (!input.value.trim()) {
                    missingAlt = true;
                }
            });
            if (missingAlt) {
                const confirmPublish = confirm("¿Estás seguro de que quieres publicar? Hay imágenes sin texto alternativo (AltText).");
                if (!confirmPublish) {
                    return;
                }
            }
        }
        payload.media_ids = composerUploadedMediaIds;
    }

    const pollContainer = document.getElementById('composer-poll-container');
    if (pollContainer && pollContainer.style.display !== 'none') {
        const optionInputs = document.querySelectorAll('.poll-option-field');
        const options = Array.from(optionInputs)
            .map(input => input.value.trim())
            .filter(val => val !== '');
        
        if (options.length >= 2) {
            const expiresIn = parseInt(document.getElementById('poll-expires-in').value || 86400);
            payload.poll = {
                options: options,
                expires_in: expiresIn
            };
        }
    }

    let url = '/api/v1/statuses';
    let method = 'POST';

    if (editTootId) {
        url = `/api/v1/statuses/${editTootId}`;
        method = 'PUT';
    } else if (replyToId) {
        payload.in_reply_to_id = replyToId;
    } else if (quoteTootId) {
        payload.quote_id = quoteTootId;
    }

    try {
        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify(payload)
        });

        if (response.ok) {
            const newToot = await response.json();
            textarea.value = '';
            cancelComposerContext();
            
            if (editTootId) {
                loadTimeline(false);
            } else {
                if (currentTimeline === 'home' || currentTimeline === 'public' || currentTimeline === 'local') {
                    const idInt = parseInt(newToot.id);
                    if (idInt > lastId) {
                        lastId = idInt;
                    }
                    prependToot(newToot, true);
                } else {
                    loadTimeline(false);
                }
            }
        } else {
            const errData = await response.json();
            alert('Error: ' + (errData.error || 'No se pudo publicar.'));
        }
    } catch (e) {
        alert('Error al conectar con el servidor.');
    }
}

function switchTimeline(type, fromHashChange = false) {
    const tabFeed = document.getElementById('tab-feed');
    if (!tabFeed && !fromHashChange) {
        window.location.href = '/' + type;
        return;
    }
    
    if (fromHashChange && currentTimeline === type && tabFeed && tabFeed.style.display === 'block') {
        return;
    }
    currentTimeline = type;
    
    const newPath = '/' + type;
    if (window.location.pathname !== newPath && !fromHashChange) {
        history.pushState(null, '', newPath);
    }
    
    document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
    
    const navPub = document.getElementById('nav-public');
    const navHome = document.getElementById('nav-home');
    const navLocal = document.getElementById('nav-local');
    const navBook = document.getElementById('nav-bookmarks');
    const navList = document.getElementById('nav-lists');
    const navHash = document.getElementById('nav-hashtags');

    if (type === 'public' && navPub) {
        navPub.classList.add('active');
    } else if (type === 'home' && navHome) {
        navHome.classList.add('active');
    } else if (type === 'local' && navLocal) {
        navLocal.classList.add('active');
    } else if (type === 'bookmarks' && navBook) {
        navBook.classList.add('active');
    } else if (type.startsWith('list_') && navList) {
        navList.classList.add('active');
    } else if (type.startsWith('tag_') && navHash) {
        navHash.classList.add('active');
    }
    lastId = 0;
    showTab('feed', fromHashChange);
    loadTimeline();
}

function updateCharCount() {
    const textarea = document.getElementById('composer-text');
    if (textarea) {
        const text = textarea.value;
        document.getElementById('char-count').innerText = maxTootChars - text.length;

        // Auto-grow textarea up to twice its height (180px), then scroll
        textarea.style.height = '90px';
        const scrollHeight = textarea.scrollHeight;
        const maxHeight = 180;
        if (scrollHeight > 90) {
            if (scrollHeight > maxHeight) {
                textarea.style.height = maxHeight + 'px';
                textarea.style.overflowY = 'auto';
            } else {
                textarea.style.height = scrollHeight + 'px';
                textarea.style.overflowY = 'hidden';
            }
        } else {
            textarea.style.height = '90px';
            textarea.style.overflowY = 'hidden';
        }
    }
}

function showTab(tabName, fromHashChange = false) {
    const tabElement = document.getElementById('tab-' + tabName);
    if (fromHashChange && tabElement && tabElement.style.display === 'block') {
        return;
    }
    
    if (!tabElement && !fromHashChange) {
        let targetPath = '/public';
        if (tabName !== 'feed' && tabName !== 'profile-view' && tabName !== 'thread-view') {
            targetPath = '/' + tabName;
        }
        window.location.href = targetPath;
        return;
    }

    if (tabName !== 'feed' && tabName !== 'profile-view' && tabName !== 'thread-view') {
        const newPath = '/' + tabName;
        if (window.location.pathname !== newPath && !fromHashChange) {
            history.pushState(null, '', newPath);
        }
    }

    document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
    
    const tabs = ['feed', 'profile', 'profile-view', 'thread-view', 'notifications', 'users-list', 'search-results', 'lists', 'collections', 'followed-hashtags'];
    tabs.forEach(t => {
        const el = document.getElementById('tab-' + t);
        if (el) el.style.display = 'none';
    });

    if (tabElement) {
        tabElement.style.display = 'block';
    }

    if (tabName === 'feed') {
        const navPub = document.getElementById('nav-public');
        const navHome = document.getElementById('nav-home');
        const navLocal = document.getElementById('nav-local');
        const navBook = document.getElementById('nav-bookmarks');
        const navList = document.getElementById('nav-lists');
        const navHash = document.getElementById('nav-hashtags');
        if (currentTimeline === 'public' && navPub) navPub.classList.add('active');
        else if (currentTimeline === 'home' && navHome) navHome.classList.add('active');
        else if (currentTimeline === 'local' && navLocal) navLocal.classList.add('active');
        else if (currentTimeline === 'bookmarks' && navBook) navBook.classList.add('active');
        else if (currentTimeline.startsWith('list_') && navList) navList.classList.add('active');
        else if (currentTimeline.startsWith('tag_') && navHash) navHash.classList.add('active');
    } else if (tabName === 'profile') {
        const navProf = document.getElementById('nav-profile');
        if (navProf) navProf.classList.add('active');
        loadProfileFormValues();
    } else if (tabName === 'profile-view') {
        const navProf = document.getElementById('nav-profile');
        if (activeProfileViewId === currentProfileData?.id && navProf) {
            navProf.classList.add('active');
        }
    } else if (tabName === 'thread-view') {
        // Nada específico
    } else if (tabName === 'notifications') {
        const navNotif = document.getElementById('nav-notifications');
        if (navNotif) navNotif.classList.add('active');
        loadNotifications();
    } else if (tabName === 'users-list') {
        // Nada específico
    } else if (tabName === 'search-results') {
        // Nada específico
    } else if (tabName === 'lists') {
        const navList = document.getElementById('nav-lists');
        if (navList) navList.classList.add('active');
        loadLists();
    } else if (tabName === 'collections') {
        const navColl = document.getElementById('nav-collections');
        if (navColl) navColl.classList.add('active');
        loadCollections();
    } else if (tabName === 'followed-hashtags') {
        const navHash = document.getElementById('nav-hashtags');
        if (navHash) navHash.classList.add('active');
        loadFollowedHashtags();
    }
}

async function loadNotifications() {
    const list = document.getElementById('notifications-list');
    list.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Cargando notificaciones...</div>';
    
    updateNotificationBadge();
    
    try {
        // 1. Obtener solicitudes de seguimiento pendientes
        let followRequests = [];
        try {
            const frRes = await fetch('/api/v1/follow_requests', {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            if (frRes.ok) {
                followRequests = await frRes.json();
            }
        } catch (e) {
            console.error("Error al cargar solicitudes de seguimiento", e);
        }

        // 2. Obtener notificaciones estándar
        const res = await fetch('/api/v1/notifications', {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        const resText = await res.text();
        let data;
        try {
            data = JSON.parse(resText);
        } catch (jsonErr) {
            console.error("Error parsing JSON. Raw response was:", resText);
            list.innerHTML = `<div style="text-align:left; padding: 20px; color: var(--error);">
                <h4>Error del Servidor (no es JSON válido)</h4>
                <p>El servidor devolvió HTML/Texto en lugar de JSON. Detalle:</p>
                <div style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; font-family: monospace; max-height: 400px; overflow: auto; border: 1px solid var(--border-color); color: var(--text-color);">
                    ${escapeHTML(resText)}
                </div>
            </div>`;
            return;
        }
        list.innerHTML = '';
        
        // 3. Renderizar solicitudes de seguimiento pendientes en la parte superior
        followRequests.forEach(req => {
            const div = document.createElement('div');
            div.style = "display: flex; gap: 15px; align-items: center; background: rgba(16, 185, 129, 0.04); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 12px; padding: 15px; margin-bottom: 12px; transition: background 0.2s;";
            
            div.innerHTML = `
                <span class="material-icons-outlined" style="font-size: 24px; color: var(--secondary);">person_add</span>
                <img class="user-avatar clickable-actor" onclick="viewProfile('${req.id}')" src="${proxyUrl(req.avatar)}" alt="Avatar" style="width: 36px; height: 36px;">
                <div style="flex-grow: 1; min-width: 0;">
                    <div style="font-size: 14px;">
                        <strong class="clickable-actor" onclick="viewProfile('${req.id}')">${req.display_name}</strong> 
                        <span style="color: var(--text-muted);">@${req.acct}</span>
                        quiere seguirte
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 8px;">
                        <button onclick="respondFollowRequest('${req.id}', 'authorize')" class="btn-publish" style="font-size: 12px; padding: 4px 12px; border-radius: 15px; width: auto; margin-top: 0; box-shadow: none;">Aceptar</button>
                        <button onclick="respondFollowRequest('${req.id}', 'reject')" class="btn-publish" style="font-size: 12px; padding: 4px 12px; border-radius: 15px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-color); width: auto; margin-top: 0; box-shadow: none;">Rechazar</button>
                    </div>
                </div>
            `;
            list.appendChild(div);
        });

        if (data.length === 0 && followRequests.length === 0) {
            list.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">No tienes ninguna notificación por el momento.</div>';
            return;
        }

        // 4. Agrupar notificaciones como Phanpy
        const grouped = [];
        const followGroup = {
            type: 'grouped_follows',
            notifications: [],
            latest_created_at: null,
            read: true
        };
        
        const interactionGroups = {};

        data.forEach(notif => {
            const isUnread = !notif.read;
            const notifDate = new Date(notif.created_at);
            
            if (notif.type === 'follow') {
                followGroup.notifications.push(notif);
                if (!followGroup.latest_created_at || notifDate > new Date(followGroup.latest_created_at)) {
                    followGroup.latest_created_at = notif.created_at;
                }
                if (isUnread) {
                    followGroup.read = false;
                }
            } else if (notif.type === 'favourite' || notif.type === 'reblog') {
                const status = notif.status;
                if (status) {
                    const statusId = status.id;
                    if (!interactionGroups[statusId]) {
                        interactionGroups[statusId] = {
                            type: 'grouped_interactions',
                            status: status,
                            favourites: [],
                            reblogs: [],
                            notifications: [],
                            latest_created_at: null,
                            read: true
                        };
                    }
                    
                    const group = interactionGroups[statusId];
                    group.notifications.push(notif);
                    if (notif.type === 'favourite') {
                        if (!group.favourites.some(u => String(u.id) === String(notif.account.id))) {
                            group.favourites.push(notif.account);
                        }
                    } else if (notif.type === 'reblog') {
                        if (!group.reblogs.some(u => String(u.id) === String(notif.account.id))) {
                            group.reblogs.push(notif.account);
                        }
                    }
                    
                    if (!group.latest_created_at || notifDate > new Date(group.latest_created_at)) {
                        group.latest_created_at = notif.created_at;
                    }
                    if (isUnread) {
                        group.read = false;
                    }
                } else {
                    grouped.push({
                        type: 'individual',
                        notification: notif,
                        latest_created_at: notif.created_at
                    });
                }
            } else {
                grouped.push({
                    type: 'individual',
                    notification: notif,
                    latest_created_at: notif.created_at
                });
            }
        });

        if (followGroup.notifications.length > 0) {
            grouped.push(followGroup);
        }
        
        Object.values(interactionGroups).forEach(group => {
            grouped.push(group);
        });

        grouped.sort((a, b) => new Date(b.latest_created_at) - new Date(a.latest_created_at));

        // 5. Renderizar notificaciones agrupadas e individuales
        grouped.forEach(item => {
            if (item.type === 'grouped_follows') {
                const isUnread = !item.read;
                const qty = item.notifications.length;
                const div = document.createElement('div');
                div.className = 'notification-item grouped-follows-notification';
                div.style = `display: flex; flex-direction: column; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-left: 3px solid ${isUnread ? 'var(--primary)' : 'transparent'}; border-radius: 12px; padding: 15px; margin-bottom: 8px; transition: all 0.2s;`;
                
                let avatarsHTML = '<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px;">';
                item.notifications.forEach(n => {
                    avatarsHTML += `
                        <img class="user-avatar clickable-actor" onclick="viewProfile('${n.account.id}')" src="${proxyUrl(n.account.avatar)}" alt="${escapeHTML(n.account.display_name)}" title="@${escapeHTML(n.account.acct)} - ${escapeHTML(n.account.display_name)}" style="width: 32px; height: 32px; border-radius: 50%; border: 1.5px solid var(--border-color); cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.15)';" onmouseout="this.style.transform='scale(1)';" />
                    `;
                });
                avatarsHTML += '</div>';

                const dismissIds = item.notifications.map(n => n.id).join(',');
                const dismissButton = `
                    <button onclick="dismissGroupedNotifications('${dismissIds}', this.parentElement.parentElement)" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); color: var(--text-muted); display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; transition: all 0.2s; flex-shrink: 0; margin-left: auto;" title="Eliminar estas notificaciones" onmouseover="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.borderColor='rgba(239, 68, 68, 0.3)'; this.style.color='var(--error)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.03)'; this.style.borderColor='var(--border-color)'; this.style.color='var(--text-muted)';"><span class="material-icons-outlined" style="font-size: 14px;">delete</span></button>
                `;

                div.innerHTML = `
                    <div style="display: flex; align-items: center; width: 100%;">
                        <div style="display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; flex-shrink: 0; margin-right: 12px;">
                            <span class="material-icons-outlined" style="font-size: 20px; color: var(--primary); opacity: ${isUnread ? '1' : '0.6'};">person_add</span>
                        </div>
                        <div style="flex-grow: 1; min-width: 0; font-size: 14px; color: var(--text-color);">
                            <strong>${qty} ${qty === 1 ? 'persona' : 'personas'}</strong> te comenzaron a seguir.
                        </div>
                        ${dismissButton}
                    </div>
                    ${avatarsHTML}
                `;
                list.appendChild(div);
            } else if (item.type === 'grouped_interactions') {
                const isUnread = !item.read;
                const favQty = item.favourites.length;
                const rebQty = item.reblogs.length;
                const totalQty = favQty + rebQty;
                
                let text = '';
                let icon = '';
                if (favQty > 0 && rebQty > 0) {
                    text = `<strong>${totalQty} personas</strong> impulsaron y les gustó tu publicación.`;
                    icon = `<span class="material-icons" style="font-size: 20px; color: #f59e0b; opacity: ${isUnread ? '1' : '0.6'};">star</span>`;
                } else if (favQty > 0) {
                    text = `A <strong>${favQty} ${favQty === 1 ? 'persona' : 'personas'}</strong> le${favQty === 1 ? '' : 's'} gustó tu publicación.`;
                    icon = `<span class="material-icons" style="font-size: 20px; color: #ef4444; opacity: ${isUnread ? '1' : '0.6'};">favorite</span>`;
                } else if (rebQty > 0) {
                    text = `<strong>${rebQty} ${rebQty === 1 ? 'persona' : 'personas'}</strong> impulsó${rebQty === 1 ? 'n' : 'aron'} tu publicación.`;
                    icon = `<span class="material-icons" style="font-size: 20px; color: #10b981; opacity: ${isUnread ? '1' : '0.6'};">repeat</span>`;
                }

                const div = document.createElement('div');
                div.className = 'notification-item grouped-interactions-notification';
                div.style = `display: flex; flex-direction: column; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-left: 3px solid ${isUnread ? 'var(--primary)' : 'transparent'}; border-radius: 12px; padding: 15px; margin-bottom: 8px; transition: all 0.2s;`;
                
                let avatarsHTML = '<div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">';
                item.favourites.forEach(u => {
                    avatarsHTML += `
                        <div style="position: relative; display: inline-block;">
                            <img class="user-avatar clickable-actor" onclick="viewProfile('${u.id}')" src="${proxyUrl(u.avatar)}" alt="${escapeHTML(u.display_name)}" title="@${escapeHTML(u.acct)} - le gustó" style="width: 32px; height: 32px; border-radius: 50%; border: 1.5px solid var(--border-color); cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.15)';" onmouseout="this.style.transform='scale(1)';" />
                            <span style="position: absolute; bottom: -4px; right: -4px; font-size: 10px; background: rgba(0,0,0,0.6); border-radius: 50%; width: 14px; height: 14px; display: flex; align-items: center; justify-content: center; pointer-events: none;" title="Favorito">❤️</span>
                        </div>
                    `;
                });
                item.reblogs.forEach(u => {
                    avatarsHTML += `
                        <div style="position: relative; display: inline-block;">
                            <img class="user-avatar clickable-actor" onclick="viewProfile('${u.id}')" src="${proxyUrl(u.avatar)}" alt="${escapeHTML(u.display_name)}" title="@${escapeHTML(u.acct)} - impulsó" style="width: 32px; height: 32px; border-radius: 50%; border: 1.5px solid var(--border-color); cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.15)';" onmouseout="this.style.transform='scale(1)';" />
                            <span style="position: absolute; bottom: -4px; right: -4px; font-size: 10px; background: rgba(0,0,0,0.6); border-radius: 50%; width: 14px; height: 14px; display: flex; align-items: center; justify-content: center; pointer-events: none;" title="Impulso">🚀</span>
                        </div>
                    `;
                });
                avatarsHTML += '</div>';

                const statusPreviewHTML = `
                    <div class="notification-status-preview" onclick="viewTootThread('${item.status.id}')" style="margin-top: 12px; border: 1px solid var(--border-color); border-radius: 12px; background: rgba(255, 255, 255, 0.015); padding: 12px; cursor: pointer; transition: background 0.2s; font-size: 13.5px;" onmouseover="this.style.background='rgba(255, 255, 255, 0.04)'" onmouseout="this.style.background='rgba(255, 255, 255, 0.015)'">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 13px;">
                            <img src="${proxyUrl(item.status.account.avatar)}" style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover;">
                            <strong style="color: var(--text-color);">${escapeHTML(item.status.account.display_name || item.status.account.username)}</strong>
                            <span style="color: var(--text-muted);">@${escapeHTML(item.status.account.acct)}</span>
                            <span style="font-size: 11px; color: var(--text-muted); margin-left: auto;" title="${new Date(item.status.created_at).toLocaleDateString('es-ES', { hour: '2-digit', minute: '2-digit' })}">${formatRelativeTime(item.status.created_at)}</span>
                        </div>
                        <div class="toot-content" style="font-size: 13px; color: var(--text-color); line-height: 1.4;">
                            ${sanitizeHTML(item.status.content)}
                        </div>
                    </div>
                `;

                const dismissIds = item.notifications.map(n => n.id).join(',');
                const dismissButton = `
                    <button onclick="dismissGroupedNotifications('${dismissIds}', this.parentElement.parentElement)" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); color: var(--text-muted); display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; transition: all 0.2s; flex-shrink: 0; margin-left: auto;" title="Eliminar estas notificaciones" onmouseover="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.borderColor='rgba(239, 68, 68, 0.3)'; this.style.color='var(--error)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.03)'; this.style.borderColor='var(--border-color)'; this.style.color='var(--text-muted)';"><span class="material-icons-outlined" style="font-size: 14px;">delete</span></button>
                `;

                div.innerHTML = `
                    <div style="display: flex; align-items: center; width: 100%;">
                        <div style="display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; flex-shrink: 0; margin-right: 12px;">
                            ${icon}
                        </div>
                        <div style="flex-grow: 1; min-width: 0; font-size: 14px; color: var(--text-color);">
                            ${text}
                        </div>
                        ${dismissButton}
                    </div>
                    ${avatarsHTML}
                    ${statusPreviewHTML}
                `;
                list.appendChild(div);
            } else if (item.type === 'individual') {
                const notif = item.notification;
                const isUnread = !notif.read;
                const div = document.createElement('div');
                
                if (notif.type === 'mention') {
                    div.className = 'notification-item mention-notification';
                    div.style = `display: flex; flex-direction: column; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-left: 3px solid ${isUnread ? '#d97706' : 'transparent'}; border-radius: 12px; transition: all 0.2s; margin-bottom: 8px; overflow: hidden;`;
                    
                    const dateTooltip = new Date(notif.created_at).toLocaleDateString('es-ES', { hour: '2-digit', minute: '2-digit' });
                    const dateDisplay = formatRelativeTime(notif.created_at);

                    div.innerHTML = `
                        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; background: rgba(217, 119, 6, ${isUnread ? '0.1' : '0.04'}); border-bottom: 1px solid var(--border-color); font-size: 13px; color: var(--text-muted);">
                            <span class="material-icons-outlined" style="font-size: 18px; color: #d97706; opacity: ${isUnread ? '1' : '0.6'};">chat_bubble_outline</span>
                            <span>
                                <strong class="clickable-actor" onclick="viewProfile('${notif.account.id}')">${escapeHTML(notif.account.display_name || notif.account.username)}</strong>
                                te ha mencionado
                            </span>
                            <div style="flex-grow: 1;"></div>
                            <span style="font-size: 11.5px; color: var(--text-muted); margin-right: 8px;" title="${dateTooltip}">
                                ${dateDisplay}
                            </span>
                            <button onclick="dismissNotification('${notif.id}', this.parentElement.parentElement)" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); color: var(--text-muted); display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; transition: all 0.2s;" title="Eliminar notificación" onmouseover="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.borderColor='rgba(239, 68, 68, 0.3)'; this.style.color='var(--error)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.03)'; this.style.borderColor='var(--border-color)'; this.style.color='var(--text-muted)';"><span class="material-icons-outlined" style="font-size: 14px;">delete</span></button>
                        </div>
                    `;

                    if (notif.status) {
                        const statusCard = createThreadTootElement(notif.status, false);
                        statusCard.style.border = 'none';
                        statusCard.style.background = 'transparent';
                        statusCard.style.boxShadow = 'none';
                        statusCard.style.borderRadius = '0';
                        statusCard.style.margin = '0';
                        statusCard.style.padding = '15px';
                        div.appendChild(statusCard);
                    } else {
                        const fallbackDiv = document.createElement('div');
                        fallbackDiv.style = "padding: 15px; font-size: 14px; color: var(--text-muted);";
                        fallbackDiv.innerText = "No se pudo cargar el contenido de la mención.";
                        div.appendChild(fallbackDiv);
                    }
                } else {
                    div.className = 'notification-item individual-notification';
                    div.style = `display: flex; gap: 15px; align-items: center; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-left: 3px solid ${isUnread ? 'var(--primary)' : 'transparent'}; border-radius: 12px; padding: 15px; transition: all 0.2s; margin-bottom: 8px;`;
                    
                    let actionText = 'realizó una acción';
                    let actionIcon = '<span class="material-icons-outlined">notifications</span>';
                    
                    if (notif.type === 'follow') {
                        actionText = 'te ha seguido';
                        actionIcon = `<span class="material-icons-outlined" style="font-size: 20px; color: #10b981; opacity: ${isUnread ? '1' : '0.6'};">person_add</span>`;
                    }
                    
                    div.innerHTML = `
                        <div style="display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; flex-shrink: 0;">${actionIcon}</div>
                        <img class="user-avatar clickable-actor" onclick="viewProfile('${notif.account.id}')" src="${proxyUrl(notif.account.avatar)}" alt="Avatar" style="width: 36px; height: 36px; flex-shrink: 0;">
                        <div style="flex-grow: 1; min-width: 0;">
                            <div style="font-size: 14px;">
                                <strong class="clickable-actor" onclick="viewProfile('${notif.account.id}')">${escapeHTML(notif.account.display_name || notif.account.username)}</strong> 
                                <span style="color: var(--text-muted);">@${escapeHTML(notif.account.acct)}</span>
                                ${actionText}
                            </div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">
                                <span title="${new Date(notif.created_at).toLocaleDateString('es-ES', { hour: '2-digit', minute: '2-digit' })}">${formatRelativeTime(notif.created_at)}</span>
                            </div>
                        </div>
                        <button onclick="dismissNotification('${notif.id}', this.parentElement.parentElement)" style="background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); color: var(--text-muted); display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; transition: all 0.2s; flex-shrink: 0;" title="Eliminar notificación" onmouseover="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.borderColor='rgba(239, 68, 68, 0.3)'; this.style.color='var(--error)';" onmouseout="this.style.background='rgba(255, 255, 255, 0.03)'; this.style.borderColor='var(--border-color)'; this.style.color='var(--text-muted)';"><span class="material-icons-outlined" style="font-size: 14px;">delete</span></button>
                    `;
                }
                
                list.appendChild(div);
            }
        });
    } catch (e) {
        console.error("Error al cargar notificaciones:", e);
        list.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar notificaciones: ${escapeHTML(e.message)}<pre style="text-align:left; font-size:11px; margin-top:10px; max-height:200px; overflow:auto; background:rgba(0,0,0,0.2); padding:10px; border-radius:6px;">${escapeHTML(e.stack)}</pre></div>`;
    }
}

async function respondFollowRequest(id, action) {
    try {
        const res = await fetch(`/api/v1/follow_requests/${id}/${action}`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            loadNotifications();
        } else {
            alert('Error al procesar la solicitud de seguimiento.');
        }
    } catch (err) {
        alert('Error al conectar con el servidor.');
    }
}

async function dismissNotification(id, element) {
    if (!confirm('¿Estás seguro de que deseas eliminar esta notificación?')) return;
    try {
        const res = await fetch(`/api/v1/notifications/${id}/dismiss`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            element.remove();
            const list = document.getElementById('notifications-list');
            if (list.children.length === 0) {
                list.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">No tienes ninguna notificación por el momento.</div>';
            }
        } else {
            alert('Error al eliminar la notificación.');
        }
    } catch (err) {
        alert('Error al conectar con el servidor.');
    }
}

async function clearAllNotifications() {
    if (!confirm('¿Estás seguro de que deseas marcar todas las notificaciones como leídas?')) return;
    try {
        const res = await fetch('/api/v1/notifications/clear', {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            // Actualizar interfaz visual localmente de inmediato sin recargar el DOM
            const items = document.querySelectorAll('.notification-item');
            items.forEach(item => {
                // Quitar el borde de color
                item.style.borderLeftColor = 'transparent';
                
                // Atenuar iconos de tipo (star, reply, person_add)
                const icons = item.querySelectorAll('.material-icons, .material-icons-outlined');
                icons.forEach(icon => {
                    if (icon.innerText === 'star' || icon.innerText === 'reply' || icon.innerText === 'person_add') {
                        icon.style.opacity = '0.6';
                    }
                });
                
                // Atenuar fondos de cabecera en menciones
                const header = item.querySelector('div[style*="background: rgba(99, 102, 241"]');
                if (header) {
                    header.style.backgroundColor = 'rgba(99, 102, 241, 0.04)';
                }
            });
            
            // Ocultar el badge lateral
            const badge = document.getElementById('nav-notifications-count');
            if (badge) {
                badge.innerText = '0';
                badge.style.display = 'none';
            }
        } else {
            alert('Error al marcar las notificaciones como leídas.');
        }
    } catch (err) {
        alert('Error al conectar con el servidor.');
    }
}

// Cargar valores de Perfil en el formulario de edición
async function loadProfileFormValues() {
    if (!document.getElementById('tab-profile')) {
        window.location.href = '/profile';
        return;
    }
    if (!currentProfileData) {
        try {
            const res = await fetch('/api/v1/accounts/verify_credentials', {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            currentProfileData = await res.json();
        } catch (e) {
            console.error("Error al cargar credenciales", e);
            return;
        }
    }

    document.getElementById('profile-display-name').value = currentProfileData.display_name || '';
    
    let note = currentProfileData.note || '';
    note = note.replace(/<\/p>\s*<p>/gi, '\n\n')
               .replace(/<br\s*\/?>/gi, '\n')
               .replace(/<p>/gi, '')
               .replace(/<\/p>/gi, '');
    document.getElementById('profile-note').value = note;

    // Restaurar estado de los checkboxes de privacidad
    document.getElementById('profile-discoverable').checked = !!currentProfileData.discoverable;
    document.getElementById('profile-auto-accept').checked = !currentProfileData.locked;
    document.getElementById('profile-searchable').checked = !!currentProfileData.searchable;
    document.getElementById('profile-indexable').checked = !!currentProfileData.indexable;
    document.getElementById('profile-show-source').checked = !!currentProfileData.show_source;

    for (let i = 0; i < 4; i++) {
        document.getElementById(`field-name-${i}`).value = '';
        document.getElementById(`field-value-${i}`).value = '';
        document.getElementById(`field-verified-${i}`).innerText = '';
    }

    const fields = currentProfileData.fields || [];
    fields.forEach((f, idx) => {
        if (idx < 4) {
            document.getElementById(`field-name-${idx}`).value = f.name || '';
            
            let val = f.value || '';
            if (val.includes('<a href=')) {
                const match = val.match(/href="([^"]+)"/);
                if (match) {
                    val = match[1];
                }
            }
            document.getElementById(`field-value-${idx}`).value = val;

            if (f.verified_at) {
                document.getElementById(`field-verified-${idx}`).innerText = '✓ Verificado';
                document.getElementById(`field-verified-${idx}`).style.color = 'var(--secondary)';
            } else {
                document.getElementById(`field-verified-${idx}`).innerText = 'No verificado';
                document.getElementById(`field-verified-${idx}`).style.color = 'var(--text-muted)';
            }
        }
    });
}

// Interceptar submit del formulario de perfil
const profileForm = document.getElementById('profile-form');
if (profileForm) {
    profileForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const statusDiv = document.getElementById('profile-save-status');
        statusDiv.innerText = 'Guardando cambios...';
        statusDiv.style.color = 'var(--text-muted)';

        const formData = new FormData(profileForm);

        try {
            const response = await fetch('/api/v1/accounts/update_credentials', {
                method: 'PATCH',
                headers: {
                    'Authorization': `Bearer ${token}`
                },
                body: formData
            });

            if (response.ok) {
                const updatedProfile = await response.json();
                currentProfileData = updatedProfile;

                const dispNameEl = document.getElementById('my-display-name');
                if (dispNameEl) {
                    dispNameEl.innerText = updatedProfile.display_name;
                }
                if (updatedProfile.avatar) {
                    const myAvEl = document.getElementById('my-avatar');
                    if (myAvEl) {
                        myAvEl.src = updatedProfile.avatar;
                    }
                    const compAvEl = document.getElementById('composer-avatar');
                    if (compAvEl) {
                        compAvEl.src = updatedProfile.avatar;
                    }
                }

                statusDiv.innerText = '✓ Cambios guardados correctamente.';
                statusDiv.style.color = 'var(--secondary)';

                loadProfileFormValues();
            } else {
                const data = await response.json();
                statusDiv.innerText = 'Error al guardar: ' + (data.error || 'Intenta de nuevo.');
                statusDiv.style.color = 'var(--error)';
            }
        } catch (err) {
            statusDiv.innerText = 'Error al conectar al servidor.';
            statusDiv.style.color = 'var(--error)';
        }
    });
}

// Funciones de descarga de exportaciones
async function downloadExport(endpoint, defaultFilename) {
    try {
        const btn = event.target;
        const oldText = btn.innerText;
        btn.innerText = 'Exportando...';
        btn.disabled = true;

        const response = await fetch(endpoint, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        
        if (!response.ok) throw new Error('Error al descargar');
        
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = defaultFilename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
        
        btn.innerText = oldText;
        btn.disabled = false;
    } catch (err) {
        alert('No se pudo exportar la información: ' + err.message);
        event.target.innerText = 'Error';
        event.target.disabled = false;
    }
}

// Interceptar submit del formulario de importación
const importForm = document.getElementById('import-form');
if (importForm) {
    importForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const statusDiv = document.getElementById('import-status-msg');
        statusDiv.innerText = 'Procesando importación...';
        statusDiv.style.color = 'var(--text-muted)';

        const formData = new FormData(importForm);

        try {
            const response = await fetch('/api/v1/import', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`
                },
                body: formData
            });

            if (response.ok) {
                const data = await response.json();
                statusDiv.innerText = `✓ Importación completada: ${data.imported} registros procesados correctamente. Errores: ${data.errors || 0}`;
                statusDiv.style.color = 'var(--secondary)';
                document.getElementById('import-file').value = '';
            } else {
                const data = await response.json();
                statusDiv.innerText = 'Error al importar: ' + (data.error || 'Intenta de nuevo.');
                statusDiv.style.color = 'var(--error)';
            }
        } catch (err) {
            statusDiv.innerText = 'Error al conectar al servidor.';
            statusDiv.style.color = 'var(--error)';
        }
    });
}

// Re-enviar follows pendientes
async function resendPendingFollows() {
    const btn = document.getElementById('resend-pending-btn');
    const statusMsg = document.getElementById('resend-status-msg');
    
    btn.disabled = true;
    btn.innerText = '⏳ Enviando...';
    statusMsg.innerText = '';
    statusMsg.style.color = 'var(--text-muted)';
    
    try {
        const response = await fetch('/api/v1/follows/resend_pending', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });
        
        if (response.ok) {
            const data = await response.json();
            statusMsg.innerText = `✓ ${data.resent} solicitudes re-enviadas de ${data.total_pending} pendientes.`;
            statusMsg.style.color = 'var(--secondary)';
        } else {
            const data = await response.json();
            statusMsg.innerText = 'Error: ' + (data.error || 'Intenta de nuevo.');
            statusMsg.style.color = 'var(--error)';
        }
    } catch (err) {
        statusMsg.innerText = 'Error al conectar al servidor.';
        statusMsg.style.color = 'var(--error)';
    }
    
    btn.disabled = false;
    btn.innerText = '🔄 Re-enviar Follows Pendientes';
}

async function loadUsersList(type, accountId) {
    if (!accountId) return;
    showTab('users-list');
    
    const titleEl = document.getElementById('users-list-title');
    titleEl.innerText = type === 'followers' ? 'Seguidores' : 'Siguiendo';
    
    const container = document.getElementById('users-list-container');
    container.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Cargando usuarios...</div>';
    
    try {
        const endpoint = type === 'followers' 
            ? `/api/v1/accounts/${accountId}/followers?limit=80` 
            : `/api/v1/accounts/${accountId}/following?limit=80`;
            
        const res = await fetch(endpoint, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        
        if (!res.ok) throw new Error("Error en la solicitud.");
        const users = await res.json();
        
        container.innerHTML = '';
        if (users.length === 0) {
            container.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--text-muted);">No hay usuarios en esta lista.</div>`;
            return;
        }

        // Obtener relaciones en batch
        const myId = currentProfileData?.id;
        let relationshipsMap = {};
        if (myId) {
            const ids = users.map(u => u.id).filter(id => String(id) !== String(myId));
            if (ids.length > 0) {
                const idsParam = ids.map(id => `id[]=${id}`).join('&');
                try {
                    const relRes = await fetch(`/api/v1/relationships?${idsParam}`, {
                        headers: { 'Authorization': `Bearer ${token}` }
                    });
                    if (relRes.ok) {
                        const rels = await relRes.json();
                        rels.forEach(r => { relationshipsMap[r.id] = r; });
                    }
                } catch (e) { /* silenciar error de relaciones */ }
            }
        }
        
        users.forEach(user => {
            const item = document.createElement('div');
            item.className = 'user-list-item';
            item.style.cssText = 'display: flex; gap: 12px; align-items: center; padding: 12px; border: 1px solid var(--border-color); border-radius: 12px; background: rgba(255,255,255,0.01);';
            
            const avatarSrc = user.avatar || '/assets/default-avatar.png';
            const isMe = myId && String(user.id) === String(myId);
            const rel = relationshipsMap[user.id];
            const following = rel?.following || false;
            const followedBy = rel?.followed_by || false;
            const requested = rel?.requested || false;
            const isMutual = following && followedBy;

            // Badge de relación
            let badgeHTML = '';
            if (!isMe && rel) {
                if (isMutual) {
                    badgeHTML = `<span style="display:inline-flex; align-items:center; gap:3px; font-size:11px; padding:2px 8px; border-radius:20px; background:rgba(99,102,241,0.15); color:#818cf8; font-weight:600; white-space:nowrap;">🤝 Mutuo</span>`;
                } else if (followedBy) {
                    badgeHTML = `<span style="display:inline-flex; align-items:center; gap:3px; font-size:11px; padding:2px 8px; border-radius:20px; background:rgba(255,255,255,0.06); color:var(--text-muted); font-weight:500; white-space:nowrap;">Te sigue</span>`;
                }
            }

            // Botón de acción
            let actionHTML = '';
            if (!isMe) {
                const btnId = `user-list-follow-btn-${user.id}`;
                if (following) {
                    actionHTML = `<button id="${btnId}" onclick="handleUserListFollow('${user.id}', this, false)" style="margin:0; padding:6px 14px; font-size:12px; white-space:nowrap; background:rgba(255,255,255,0.06); border:1px solid var(--border-color); color:var(--text-color); border-radius:8px; cursor:pointer; min-width:100px;">Dejar de seguir</button>`;
                } else if (requested) {
                    actionHTML = `<button id="${btnId}" disabled style="margin:0; padding:6px 14px; font-size:12px; white-space:nowrap; background:rgba(255,255,255,0.03); border:1px solid var(--border-color); color:var(--text-muted); border-radius:8px; cursor:default; min-width:100px;">Pendiente</button>`;
                } else {
                    actionHTML = `<button id="${btnId}" onclick="handleUserListFollow('${user.id}', this, true)" style="margin:0; padding:6px 14px; font-size:12px; white-space:nowrap; background:var(--primary); border:none; color:white; border-radius:8px; cursor:pointer; min-width:100px;">Seguir</button>`;
                }
            }
            
            item.innerHTML = `
                <img src="${avatarSrc}" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; cursor: pointer; flex-shrink:0;" onclick="viewProfile('${user.id}')">
                <div style="flex: 1; min-width: 0; overflow: hidden;">
                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <span style="font-weight: 600; cursor: pointer; color: var(--text-color); font-size: 14px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" onclick="viewProfile('${user.id}')">${user.display_name}</span>
                        ${badgeHTML}
                    </div>
                    <div style="color: var(--text-muted); font-size: 12.5px; cursor: pointer; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" onclick="viewProfile('${user.id}')">@${user.acct}</div>
                </div>
                ${actionHTML}
            `;
            container.appendChild(item);
        });
    } catch (err) {
        container.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar la lista: ${err.message}</div>`;
    }
}

async function handleUserListFollow(accountId, btn, doFollow) {
    btn.disabled = true;
    const originalText = btn.innerText;
    btn.innerText = doFollow ? 'Siguiendo...' : 'Dejando...';
    
    try {
        const endpoint = doFollow 
            ? `/api/v1/accounts/${accountId}/follow`
            : `/api/v1/accounts/${accountId}/unfollow`;
        const res = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        
        if (res.ok) {
            const data = await res.json();
            const isNowFollowing = data.following;
            const isRequested = data.requested;
            
            if (isNowFollowing) {
                btn.innerText = 'Dejar de seguir';
                btn.style.background = 'rgba(255,255,255,0.06)';
                btn.style.border = '1px solid var(--border-color)';
                btn.style.color = 'var(--text-color)';
                btn.onclick = () => handleUserListFollow(accountId, btn, false);
            } else if (isRequested) {
                btn.innerText = 'Pendiente';
                btn.style.background = 'rgba(255,255,255,0.03)';
                btn.style.border = '1px solid var(--border-color)';
                btn.style.color = 'var(--text-muted)';
                btn.onclick = null;
            } else {
                btn.innerText = 'Seguir';
                btn.style.background = 'var(--primary)';
                btn.style.border = 'none';
                btn.style.color = 'white';
                btn.onclick = () => handleUserListFollow(accountId, btn, true);
            }

            // Actualizar badge de mutualidad
            const item = btn.closest('.user-list-item');
            if (item) {
                const badgeContainer = item.querySelector('div[style*="flex-wrap"]');
                if (badgeContainer) {
                    const existingBadge = badgeContainer.querySelector('span[style*="border-radius:20px"]');
                    const followedBy = data.followed_by;
                    const isMutual = isNowFollowing && followedBy;
                    
                    if (existingBadge) existingBadge.remove();
                    
                    if (isMutual) {
                        badgeContainer.insertAdjacentHTML('beforeend', `<span style="display:inline-flex; align-items:center; gap:3px; font-size:11px; padding:2px 8px; border-radius:20px; background:rgba(99,102,241,0.15); color:#818cf8; font-weight:600; white-space:nowrap;">🤝 Mutuo</span>`);
                    } else if (followedBy) {
                        badgeContainer.insertAdjacentHTML('beforeend', `<span style="display:inline-flex; align-items:center; gap:3px; font-size:11px; padding:2px 8px; border-radius:20px; background:rgba(255,255,255,0.06); color:var(--text-muted); font-weight:500; white-space:nowrap;">Te sigue</span>`);
                    }
                }
            }
        } else {
            btn.innerText = originalText;
        }
    } catch (e) {
        btn.innerText = originalText;
    }
    btn.disabled = false;
}

function goBackFromUsersList() {
    showTab('profile-view');
}

async function viewProfileByUrl(profileUrl) {
    try {
        const res = await fetch(`/api/v1/search?q=${encodeURIComponent(profileUrl)}&resolve=true`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            const data = await res.json();
            if (data.accounts && data.accounts.length > 0) {
                viewProfile(data.accounts[0].id);
            } else {
                window.open(profileUrl, '_blank');
            }
        } else {
            window.open(profileUrl, '_blank');
        }
    } catch (e) {
        window.open(profileUrl, '_blank');
    }
}

// ---------------------------
// VISTA DE PERFIL Y SEGUIMIENTOS
// ---------------------------
async function viewProfile(accountId, fromHashChange = false) {
    if (!accountId) return;
    if (!document.getElementById('tab-profile-view')) {
        window.location.href = '/@id-' + accountId;
        return;
    }
    if (fromHashChange && activeProfileViewId === accountId && document.getElementById('tab-profile-view').style.display === 'block') {
        return;
    }
    const tempPath = '/@id-' + accountId;
    if (window.location.pathname !== tempPath && !fromHashChange) {
        history.pushState(null, '', tempPath);
    }
    
    let activeTab = 'feed';
    const tabFeed = document.getElementById('tab-feed');
    const tabThread = document.getElementById('tab-thread-view');
    if (tabFeed && tabFeed.style.display !== 'none') {
        activeTab = 'feed';
    } else if (tabThread && tabThread.style.display !== 'none') {
        activeTab = 'thread-view';
    }
    previousTab = activeTab;

    activeProfileViewId = accountId;
    activeProfileSubtab = 'activity';
    
    const actSubtab = document.getElementById('profile-subtab-activity');
    if (actSubtab) actSubtab.classList.add('active');
    const medSubtab = document.getElementById('profile-subtab-media');
    if (medSubtab) medSubtab.classList.remove('active');
    const favSubtab = document.getElementById('profile-subtab-favourites');
    if (favSubtab) {
        favSubtab.classList.remove('active');
        favSubtab.style.display = 'none';
    }
    
    showTab('profile-view', fromHashChange);

    document.getElementById('profile-view-display-name').innerText = 'Cargando...';
    document.getElementById('profile-view-handle').innerText = '@...';
    document.getElementById('profile-view-followers-count').innerText = '-';
    document.getElementById('profile-view-following-count').innerText = '-';
    document.getElementById('profile-view-posts-count').innerText = '-';
    document.getElementById('profile-view-joined').innerText = '-';
    document.getElementById('profile-view-bio').innerHTML = '';
    document.getElementById('profile-view-fields').innerHTML = '';
    document.getElementById('profile-view-role-container').style.display = 'none';
    document.getElementById('profile-view-badge').innerText = '';
    document.getElementById('profile-view-follows-you-badge').style.display = 'none';
    document.getElementById('profile-view-mutual-badge').style.display = 'none';
    document.getElementById('profile-view-remove-follower-btn').style.display = 'none';
    document.getElementById('profile-feed').innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-muted);">Cargando toots...</div>';

    try {
        const res = await fetch(`/api/v1/accounts/${accountId}`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (!res.ok) throw new Error("Error cargando perfil");
        const account = await res.json();
        lastLoadedProfile = account;

        // Actualizar la URL de la barra del navegador con el handle real @username o @username@domain
        const handlePath = account.domain ? `/@${account.username}@${account.domain}` : `/@${account.username}`;
        if (window.location.pathname !== handlePath && !fromHashChange) {
            history.replaceState(null, '', handlePath);
        }

        document.getElementById('profile-view-display-name').innerText = account.display_name;
        document.getElementById('profile-view-handle').innerText = `@${account.acct}`;
        document.getElementById('profile-view-followers-count').innerText = formatStatNumber(account.followers_count);
        document.getElementById('profile-view-following-count').innerText = formatStatNumber(account.following_count);
        document.getElementById('profile-view-posts-count').innerText = formatStatNumber(account.statuses_count);
        
        const joinedYear = new Date(account.created_at).getFullYear();
        document.getElementById('profile-view-joined').innerText = joinedYear;

        const headerUrl = proxyUrl(account.header || "/assets/default-header.png");
        const avatarUrl = proxyUrl(account.avatar || "/assets/default-avatar.png");
        
        document.getElementById('profile-view-header-bg').style.backgroundImage = `url('${headerUrl}')`;
        document.getElementById('profile-view-avatar').src = avatarUrl;

        const actionBtn = document.getElementById('profile-view-action-btn');
        if (!token) {
            actionBtn.innerText = 'Seguir';
            actionBtn.style.background = 'var(--primary)';
            actionBtn.onclick = () => showLoginModal();
            document.getElementById('profile-view-manage-lists-btn').style.display = 'none';
        } else if (currentProfileData && String(account.id) === String(currentProfileData.id)) {
            actionBtn.innerText = 'Editar perfil';
            actionBtn.style.background = 'rgba(255,255,255,0.06)';
            actionBtn.onclick = () => showTab('profile');
            document.getElementById('profile-view-manage-lists-btn').style.display = 'none';
            
            const favSubtab = document.getElementById('profile-subtab-favourites');
            if (favSubtab) favSubtab.style.display = 'inline-block';
            
            document.getElementById('profile-view-badge').innerHTML = '⚙️';
            document.getElementById('profile-view-role-text').innerText = 'Owner (' + window.location.hostname + ')';
            document.getElementById('profile-view-role-container').style.display = 'inline-flex';
        } else {
            actionBtn.innerText = 'Consultando...';
            actionBtn.style.background = 'var(--primary)';
            actionBtn.onclick = null;
            document.getElementById('profile-view-manage-lists-btn').style.display = 'inline-block';

            const relRes = await fetch(`/api/v1/accounts/relationships?id[]=${account.id}`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            if (relRes.ok) {
                const rels = await relRes.json();
                const rel = rels[0];
                if (rel) {
                    const followsYou = !!rel.followed_by;
                    const following = !!rel.following;
                    
                    if (followsYou && following) {
                        document.getElementById('profile-view-mutual-badge').style.display = 'inline-flex';
                        document.getElementById('profile-view-follows-you-badge').style.display = 'none';
                    } else if (followsYou) {
                        document.getElementById('profile-view-follows-you-badge').style.display = 'inline-block';
                        document.getElementById('profile-view-mutual-badge').style.display = 'none';
                    } else {
                        document.getElementById('profile-view-follows-you-badge').style.display = 'none';
                        document.getElementById('profile-view-mutual-badge').style.display = 'none';
                    }

                    const removeFollowerBtn = document.getElementById('profile-view-remove-follower-btn');
                    if (followsYou) {
                        removeFollowerBtn.style.display = 'inline-block';
                        removeFollowerBtn.onclick = () => handleRemoveFollower(account.id, removeFollowerBtn);
                    } else {
                        removeFollowerBtn.style.display = 'none';
                    }

                    if (following) {
                        actionBtn.innerText = 'Dejar de seguir';
                        actionBtn.style.background = 'rgba(255,255,255,0.06)';
                        actionBtn.onclick = () => handleUnfollow(account.id, actionBtn);
                    } else {
                        actionBtn.innerText = 'Seguir';
                        actionBtn.style.background = 'var(--primary)';
                        actionBtn.onclick = () => handleFollow(account.id, actionBtn);
                    }
                }
            }
        }

        document.getElementById('profile-view-bio').innerHTML = sanitizeHTML(account.note) || '<p style="color:var(--text-muted)">Sin biografía.</p>';

        const fieldsGrid = document.getElementById('profile-view-fields');
        fieldsGrid.innerHTML = '';
        const fields = account.fields || [];
        fields.forEach(f => {
            const card = document.createElement('div');
            card.className = 'profile-field-card';
            if (f.verified_at) {
                card.classList.add('verified');
            }

            let valHTML = sanitizeHTML(f.value || '');
            if (valHTML.includes('<a ')) {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = valHTML;
                const links = tempDiv.querySelectorAll('a');
                links.forEach(a => {
                    const href = a.getAttribute('href');
                    const cleanText = a.textContent || href.replace(/^https?:\/\/(www\.)?/, '');
                    const newLink = document.createElement('a');
                    newLink.setAttribute('href', href);
                    newLink.setAttribute('target', '_blank');
                    newLink.setAttribute('rel', 'me nofollow noopener noreferrer');
                    newLink.textContent = cleanText;
                    a.parentNode.replaceChild(newLink, a);
                });
                valHTML = tempDiv.innerHTML;
            } else if (valHTML.startsWith('http://') || valHTML.startsWith('https://')) {
                const label = valHTML.replace(/^https?:\/\/(www\.)?/, '');
                valHTML = `<a href="${valHTML}" rel="me nofollow noopener noreferrer" target="_blank">${label}</a>`;
            }

            const verifiedBadge = f.verified_at ? `<span class="verified-badge">✓</span>` : '';
            card.innerHTML = `
                <span class="field-label" title="${f.name}">${f.name}</span>
                <span class="field-value">${valHTML} ${verifiedBadge}</span>
            `;
            fieldsGrid.appendChild(card);
        });

        await loadProfileStatuses(accountId);
    } catch (err) {
        console.error(err);
        document.getElementById('profile-feed').innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar el perfil.</div>';
    }
}

async function handleFollow(accountId, btn) {
    try {
        btn.innerText = 'Siguiendo...';
        const res = await fetch(`/api/v1/accounts/${accountId}/follow`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            btn.innerText = 'Dejar de seguir';
            btn.style.background = 'rgba(255,255,255,0.06)';
            btn.onclick = () => handleUnfollow(accountId, btn);
            const countSpan = document.getElementById('profile-view-followers-count');
            countSpan.innerText = parseInt(countSpan.innerText || 0) + 1;
        } else {
            btn.innerText = 'Seguir';
        }
    } catch (e) {
        btn.innerText = 'Seguir';
    }
}

async function handleUnfollow(accountId, btn) {
    try {
        btn.innerText = 'Dejando...';
        const res = await fetch(`/api/v1/accounts/${accountId}/unfollow`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            btn.innerText = 'Seguir';
            btn.style.background = 'var(--primary)';
            btn.onclick = () => handleFollow(accountId, btn);
            const countSpan = document.getElementById('profile-view-followers-count');
            countSpan.innerText = Math.max(0, parseInt(countSpan.innerText || 0) - 1);
        } else {
            btn.innerText = 'Dejar de seguir';
        }
    } catch (e) {
        btn.innerText = 'Dejar de seguir';
    }
}

async function handleRemoveFollower(accountId, btn) {
    if (!confirm('¿Estás seguro de que deseas eliminar a este usuario de tus seguidores?')) return;
    btn.disabled = true;
    btn.innerText = 'Eliminando...';
    try {
        const res = await fetch(`/api/v1/accounts/${accountId}/remove_from_followers`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            btn.style.display = 'none';
            document.getElementById('profile-view-follows-you-badge').style.display = 'none';
            document.getElementById('profile-view-mutual-badge').style.display = 'none';
            viewProfile(accountId);
        } else {
            alert('Error al eliminar seguidor.');
            btn.disabled = false;
            btn.innerText = 'Eliminar seguidor';
        }
    } catch (err) {
        alert('Error al conectar con el servidor.');
        btn.disabled = false;
        btn.innerText = 'Eliminar seguidor';
    }
}

async function loadProfileStatuses(accountId) {
    try {
        const headers = {};
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }
        const res = await fetch(`/api/v1/accounts/${accountId}/statuses`, {
            headers: headers
        });
        if (!res.ok) throw new Error("Error al obtener toots");
        activeProfileFeedToots = await res.json();
        renderProfileFeed();
    } catch (err) {
        console.error(err);
        document.getElementById('profile-feed').innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar toots del perfil.</div>';
    }
}

function renderProfileFeed() {
    const feedContainer = document.getElementById('profile-feed');
    feedContainer.innerHTML = '';

    let filteredToots = activeProfileFeedToots;
    if (activeProfileSubtab === 'media') {
        filteredToots = activeProfileFeedToots.filter(toot => {
            return toot.media_attachments && toot.media_attachments.length > 0;
        });
    }

    if (filteredToots.length === 0) {
        feedContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted); font-size:14px;">No hay publicaciones para mostrar.</div>';
        return;
    }

    filteredToots.forEach(toot => {
        feedContainer.appendChild(createThreadTootElement(toot, false));
    });
}

async function switchProfileSubtab(tabName) {
    const previousSubtab = activeProfileSubtab;
    activeProfileSubtab = tabName;
    
    document.getElementById('profile-subtab-activity').classList.remove('active');
    document.getElementById('profile-subtab-media').classList.remove('active');
    const favSubtab = document.getElementById('profile-subtab-favourites');
    if (favSubtab) favSubtab.classList.remove('active');

    if (tabName === 'activity') {
        document.getElementById('profile-subtab-activity').classList.add('active');
        if (previousSubtab === 'favourites') {
            await loadProfileStatuses(activeProfileViewId);
        } else {
            renderProfileFeed();
        }
    } else if (tabName === 'media') {
        document.getElementById('profile-subtab-media').classList.add('active');
        if (previousSubtab === 'favourites') {
            await loadProfileStatuses(activeProfileViewId);
        } else {
            renderProfileFeed();
        }
    } else if (tabName === 'favourites') {
        if (favSubtab) favSubtab.classList.add('active');
        
        const feedContainer = document.getElementById('profile-feed');
        feedContainer.innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-muted);">Cargando favoritos...</div>';
        try {
            const res = await fetch('/api/v1/favourites', {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            const toots = await res.json();
            feedContainer.innerHTML = '';
            if (toots.length === 0) {
                feedContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted); font-size:14px;">No tienes publicaciones favoritas.</div>';
                return;
            }
            toots.forEach(toot => {
                feedContainer.appendChild(createThreadTootElement(toot, false));
            });
        } catch (e) {
            feedContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar favoritos.</div>';
        }
    }
}

function formatStatNumber(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M';
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num;
}

// ---------------------------
// VISTA DE CONVERSACIÓN / HILO
// ---------------------------
async function viewTootThread(statusId, fromHashChange = false) {
    if (!statusId) return;
    if (!document.getElementById('tab-thread-view')) {
        window.location.href = `/users/iam/statuses/${statusId}`;
        return;
    }
    
    activeThreadId = statusId;
    const threadPath = `/users/iam/statuses/${statusId}`;
    if (window.location.pathname !== threadPath && !fromHashChange) {
        history.pushState(null, '', threadPath);
    }

    let activeTab = 'feed';
    const tabFeed = document.getElementById('tab-feed');
    const tabProfile = document.getElementById('tab-profile-view');
    if (tabFeed && tabFeed.style.display !== 'none') {
        activeTab = 'feed';
    } else if (tabProfile && tabProfile.style.display !== 'none') {
        activeTab = 'profile-view';
    }
    previousTab = activeTab;

    showTab('thread-view', fromHashChange);

    const ancestorsContainer = document.getElementById('thread-ancestors');
    const mainContainer = document.getElementById('thread-main');
    const descendantsContainer = document.getElementById('thread-descendants');

    ancestorsContainer.innerHTML = '';
    mainContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Cargando conversación...</div>';
    descendantsContainer.innerHTML = '';

    try {
        const res = await fetch(`/api/v1/statuses/${statusId}/context`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (!res.ok) throw new Error("Error cargando hilo");
        const context = await res.json();

        ancestorsContainer.innerHTML = '';
        if (context.ancestors && context.ancestors.length > 0) {
            context.ancestors.forEach(toot => {
                ancestorsContainer.appendChild(createThreadTootElement(toot, false));
            });
        }

        mainContainer.innerHTML = '';
        if (context.status) {
            mainContainer.appendChild(createThreadTootElement(context.status, true));
        } else {
            mainContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar publicación.</div>';
        }

        descendantsContainer.innerHTML = '';
        if (context.descendants && context.descendants.length > 0) {
            context.descendants.forEach(toot => {
                descendantsContainer.appendChild(createThreadTootElement(toot, false));
            });
        } else {
            descendantsContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted); font-size: 14px;">Fin de la conversación.</div>';
        }
    } catch (err) {
        console.error(err);
        mainContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar conversación.</div>';
    }
}

function goBackToFeed() {
    if (previousTab === 'profile-view') {
        showTab('profile-view');
    } else {
        showTab('feed');
    }
}

// ---------------------------
// INTERACCIONES CON TOOTS (FAV, MARCADORES, CW, EDIT, BORRAR)
// ---------------------------
async function toggleFavourite(statusId, btn) {
    const isFav = btn.classList.contains('active-fav');
    const action = isFav ? 'unfavourite' : 'favourite';
    try {
        const res = await fetch(`/api/v1/statuses/${statusId}/${action}`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            const status = await res.json();
            const countSpan = btn.querySelector('.fav-count');
            if (countSpan) {
                countSpan.innerText = status.favourites_count;
            }
            const icon = btn.querySelector('.material-icons, .material-icons-outlined');
            if (isFav) {
                btn.classList.remove('active-fav');
                if (icon) {
                    icon.textContent = 'star_border';
                    icon.className = 'material-icons-outlined';
                }
            } else {
                btn.classList.add('active-fav');
                if (icon) {
                    icon.textContent = 'star';
                    icon.className = 'material-icons';
                }
            }
        }
    } catch (e) {
        console.error("Error al favorito", e);
    }
}

async function toggleBookmark(statusId, btn) {
    const isBookmarked = btn.classList.contains('active-bookmark');
    const action = isBookmarked ? 'unbookmark' : 'bookmark';
    try {
        const res = await fetch(`/api/v1/statuses/${statusId}/${action}`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            const icon = btn.querySelector('.material-icons, .material-icons-outlined');
            if (isBookmarked) {
                btn.classList.remove('active-bookmark');
                if (icon) {
                    icon.textContent = 'bookmark_border';
                    icon.className = 'material-icons-outlined';
                }
            } else {
                btn.classList.add('active-bookmark');
                if (icon) {
                    icon.textContent = 'bookmark';
                    icon.className = 'material-icons';
                }
            }
        }
    } catch (e) {
        console.error("Error al marcador", e);
    }
}

async function deleteToot(statusId, btn) {
    if (!confirm('¿Estás seguro de que deseas eliminar este toot?')) return;
    try {
        const res = await fetch(`/api/v1/statuses/${statusId}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            const card = btn.closest('.toot-card');
            if (card) {
                card.remove();
            }
        } else {
            alert('No se pudo eliminar la publicación.');
        }
    } catch (e) {
        console.error("Error al eliminar", e);
    }
}

function initReplyToToot(id, handle, visibility = 'public') {
    replyToId = id;
    editTootId = null;
    document.getElementById('composer-context').style.display = 'flex';
    document.getElementById('composer-context-text').innerText = `Respondiendo a @${handle}`;
    
    const textarea = document.getElementById('composer-text');
    textarea.value = `@${handle} `;
    textarea.focus();
    updateCharCount();
    
    // Heredar la visibilidad de la publicación padre
    const visSelect = document.getElementById('composer-visibility');
    if (visSelect) {
        visSelect.value = visibility;
    }
    
    showTab('feed');
}

function initEditToot(id, content, sensitive, spoilerText) {
    editTootId = id;
    replyToId = null;
    document.getElementById('composer-context').style.display = 'flex';
    document.getElementById('composer-context-text').innerText = `Editando tu publicación`;

    const textarea = document.getElementById('composer-text');
    textarea.value = content;
    textarea.focus();
    updateCharCount();

    const container = document.getElementById('composer-cw-container');
    const btn = document.getElementById('composer-cw-btn');
    if (sensitive) {
        container.style.display = 'block';
        btn.classList.add('active');
        document.getElementById('composer-cw-text').value = spoilerText || '';
    } else {
        container.style.display = 'none';
        btn.classList.remove('active');
        document.getElementById('composer-cw-text').value = '';
    }

    showTab('feed');
}

function cancelComposerContext() {
    replyToId = null;
    editTootId = null;
    quoteTootId = null;
    document.getElementById('composer-context').style.display = 'none';
    document.getElementById('composer-text').value = '';
    document.getElementById('composer-cw-container').style.display = 'none';
    document.getElementById('composer-cw-btn').classList.remove('active');
    document.getElementById('composer-cw-text').value = '';
    
    // Limpiar archivos multimedia subidos
    composerUploadedMediaIds = [];
    document.getElementById('composer-media-preview').innerHTML = '';
    
    // Cerrar y limpiar encuesta
    closeComposerPoll();
    
    // Restablecer visibilidad por defecto a public
    const visSelect = document.getElementById('composer-visibility');
    if (visSelect) {
        visSelect.value = 'public';
    }
    
    updateCharCount();
}

function toggleComposerCW() {
    const container = document.getElementById('composer-cw-container');
    const btn = document.getElementById('composer-cw-btn');
    if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.classList.add('active');
    } else {
        container.style.display = 'none';
        btn.classList.remove('active');
        document.getElementById('composer-cw-text').value = '';
    }
}

let altTextTimeout = {};
async function updateMediaAltText(mediaId, value) {
    if (altTextTimeout[mediaId]) {
        clearTimeout(altTextTimeout[mediaId]);
    }
    altTextTimeout[mediaId] = setTimeout(async () => {
        try {
            await fetch(`/api/v1/media/${mediaId}`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `description=${encodeURIComponent(value.trim())}`
            });
        } catch (err) {
            console.error('Error al guardar el texto alternativo:', err);
        }
    }, 500);
}

async function uploadFileDirectly(file) {
    if (!file.type.startsWith('image/')) {
        alert('Solo se admiten imágenes.');
        return;
    }
    const previewContainer = document.getElementById('composer-media-preview');
    if (!previewContainer) return;
    
    const previewId = 'media-uploading-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const tempDiv = document.createElement('div');
    tempDiv.id = previewId;
    tempDiv.className = 'media-preview-item';
    tempDiv.style = "display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 8px; padding: 6px; position: relative; margin-bottom: 8px; width: 100%; box-sizing: border-box;";
    tempDiv.innerHTML = '<div style="font-size: 11px; color: var(--text-muted); padding: 5px;">Subiendo...</div>';
    previewContainer.appendChild(tempDiv);

    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await fetch('/api/v1/media', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`
            },
            body: formData
        });

        if (response.ok) {
            const media = await response.json();
            composerUploadedMediaIds.push(media.id);
            
            tempDiv.innerHTML = `
                <div style="width: 50px; height: 50px; border-radius: 6px; background-image: url(${media.url}); background-size: cover; background-position: center; position: relative; flex-shrink: 0;">
                    <button class="remove-media-btn" style="position: absolute; top: -5px; right: -5px; background: var(--error); color: white; border: none; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 5; margin-top: 0; box-shadow: none;">✕</button>
                </div>
                <input type="text" placeholder="Texto alternativo (Alt)..." style="flex-grow: 1; font-size: 12px; padding: 6px 10px; height: 32px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: white; border-radius: 6px; margin: 0; min-width: 0;" oninput="updateMediaAltText('${media.id}', this.value)">
            `;
            
            const removeBtn = tempDiv.querySelector('.remove-media-btn');
            removeBtn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                removeComposerMedia(media.id, previewId);
            };
        } else {
            tempDiv.remove();
            alert('Error al subir imagen: ' + file.name);
        }
    } catch (e) {
        tempDiv.remove();
        alert('Error de conexión al subir imagen: ' + file.name);
    }
}

async function handleComposerFileUpload() {
    const fileInput = document.getElementById('composer-file-input');
    const files = fileInput.files;
    if (!files.length) return;

    for (let i = 0; i < files.length; i++) {
        await uploadFileDirectly(files[i]);
    }
    fileInput.value = '';
}

function removeComposerMedia(mediaId, previewElementId) {
    composerUploadedMediaIds = composerUploadedMediaIds.filter(id => id !== mediaId);
    const el = document.getElementById(previewElementId);
    if (el) el.remove();
}

function toggleComposerPoll() {
    const container = document.getElementById('composer-poll-container');
    if (container.style.display === 'none') {
        container.style.display = 'block';
        isPollActive = true;
    } else {
        closeComposerPoll();
    }
}

function closeComposerPoll() {
    const container = document.getElementById('composer-poll-container');
    if (container) {
        container.style.display = 'none';
    }
    isPollActive = false;
    const inputsContainer = document.getElementById('poll-options-inputs');
    if (inputsContainer) {
        inputsContainer.innerHTML = `
            <input type="text" class="poll-option-field" placeholder="Opción 1" maxlength="25" style="padding:8px;">
            <input type="text" class="poll-option-field" placeholder="Opción 2" maxlength="25" style="padding:8px;">
        `;
    }
}

function addPollOptionField() {
    const inputsContainer = document.getElementById('poll-options-inputs');
    const currentFieldsCount = inputsContainer.querySelectorAll('.poll-option-field').length;
    if (currentFieldsCount >= 4) {
        alert('Las encuestas pueden tener un máximo de 4 opciones.');
        return;
    }
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'poll-option-field';
    input.placeholder = `Opción ${currentFieldsCount + 1}`;
    input.maxLength = 25;
    input.style = 'padding:8px;';
    inputsContainer.appendChild(input);
}

async function voteInPoll(pollId, choiceIndex, btn) {
    try {
        const response = await fetch(`/api/v1/polls/${pollId}/votes`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
                choices: [choiceIndex]
            })
        });

        if (response.ok) {
            if (document.getElementById('tab-thread-view').style.display === 'block') {
                if (activeThreadId) {
                    viewTootThread(activeThreadId);
                }
            } else {
                loadTimeline();
            }
        } else {
            const errData = await response.json();
            alert('Error al votar: ' + (errData.error || 'No se pudo registrar el voto.'));
        }
    } catch (e) {
        alert('Error al conectar con el servidor para votar.');
    }
}

function renderEmojiPicker() {
    const grid = document.getElementById('emoji-picker-grid');
    if (!grid) return;
    grid.innerHTML = '';
    popularEmojis.forEach(emoji => {
        const btn = document.createElement('button');
        btn.innerText = emoji;
        btn.style = "background: none; border: none; font-size: 20px; cursor: pointer; padding: 4px; border-radius: 4px; display: flex; align-items: center; justify-content: center; transition: background 0.1s;";
        btn.onmouseover = () => btn.style.background = 'rgba(255,255,255,0.1)';
        btn.onmouseout = () => btn.style.background = 'none';
        btn.onclick = (e) => {
            e.preventDefault();
            insertEmojiAtCursor(emoji);
        };
        grid.appendChild(btn);
    });
}

function toggleEmojiPicker(event) {
    event.stopPropagation();
    const container = document.getElementById('emoji-picker-container');
    if (container.style.display === 'none') {
        renderEmojiPicker();
        const btn = document.getElementById('emoji-picker-btn');
        container.style.display = 'block';
        container.style.top = (btn.offsetTop - container.offsetHeight - 5) + 'px';
        container.style.left = btn.offsetLeft + 'px';
    } else {
        container.style.display = 'none';
    }
}

function insertEmojiAtCursor(emoji) {
    const textarea = document.getElementById('composer-text');
    if (!textarea) return;
    const startPos = textarea.selectionStart;
    const endPos = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, startPos) + emoji + text.substring(endPos);
    const newCursorPos = startPos + emoji.length;
    textarea.selectionStart = newCursorPos;
    textarea.selectionEnd = newCursorPos;
    textarea.focus();
    updateCharCount();
}

// Cerrar selector al hacer click fuera
document.addEventListener('click', function(e) {
    const container = document.getElementById('emoji-picker-container');
    if (container && container.style.display !== 'none') {
        if (!container.contains(e.target) && e.target.id !== 'emoji-picker-btn') {
            container.style.display = 'none';
        }
    }
});

function toggleCWSpoiler(btn) {
    const container = btn.nextElementSibling;
    if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.innerText = 'Ocultar';
    } else {
        container.style.display = 'none';
        btn.innerText = 'Mostrar más';
    }
}

// Funciones para alternar la visibilidad de imágenes/videos (Sensibilidad/NSFW) y popups ALT
function toggleMediaVisibility(btn) {
    const card = btn.closest('.toot-media-card');
    const wrapper = card.querySelector('.media-wrapper');
    const placeholder = card.querySelector('.media-placeholder');
    
    if (wrapper.style.display === 'none') {
        wrapper.style.display = 'block';
        placeholder.style.display = 'none';
        btn.innerText = 'Hide';
        const altBtn = card.querySelector('.media-alt-btn');
        if (altBtn) altBtn.style.display = 'block';
    } else {
        wrapper.style.display = 'none';
        placeholder.style.display = 'flex';
        btn.innerText = 'Show';
        const altBtn = card.querySelector('.media-alt-btn');
        if (altBtn) altBtn.style.display = 'none';
        const altPopup = card.querySelector('.media-alt-popup');
        if (altPopup) altPopup.style.display = 'none';
    }
}

function revealMediaFromPlaceholder(placeholder) {
    const card = placeholder.closest('.toot-media-card');
    const btn = card.querySelector('.media-toggle-btn');
    const wrapper = card.querySelector('.media-wrapper');
    
    wrapper.style.display = 'block';
    placeholder.style.display = 'none';
    btn.innerText = 'Hide';
    const altBtn = card.querySelector('.media-alt-btn');
    if (altBtn) altBtn.style.display = 'block';
}

function toggleAltTextPopup(btn, event) {
    event.stopPropagation();
    const card = btn.closest('.toot-media-card');
    const popup = card.querySelector('.media-alt-popup');
    if (popup.style.display === 'none' || !popup.style.display) {
        popup.style.display = 'block';
    } else {
        popup.style.display = 'none';
    }
}

function closeAltTextPopup(btn, event) {
    event.stopPropagation();
    const popup = btn.closest('.media-alt-popup');
    popup.style.display = 'none';
}

function showProfileTechnicalInfo(event) {
    if (event) event.stopPropagation();
    if (!lastLoadedProfile) return;

    const content = document.getElementById('profile-tech-info-content');
    content.innerHTML = `
        <div><strong>ID local:</strong> <code style="background: rgba(255,255,255,0.06); padding: 2px 6px; border-radius: 4px; font-family: monospace;">${lastLoadedProfile.id}</code></div>
        <div><strong>Nombre de usuario:</strong> <code style="background: rgba(255,255,255,0.06); padding: 2px 6px; border-radius: 4px; font-family: monospace;">${lastLoadedProfile.username}</code></div>
        <div><strong>Dirección Fediverso:</strong> <code style="background: rgba(255,255,255,0.06); padding: 2px 6px; border-radius: 4px; font-family: monospace;">@${lastLoadedProfile.acct}</code></div>
        <div><strong>URL del Perfil:</strong> <a href="${lastLoadedProfile.url}" target="_blank" style="color: var(--primary); word-break: break-all;">${lastLoadedProfile.url}</a></div>
        <div><strong>Fecha de Registro:</strong> <span>${new Date(lastLoadedProfile.created_at).toLocaleString()}</span></div>
        <div><strong>Estado de Cuenta:</strong> <span>${lastLoadedProfile.locked ? '🔒 Privada (Aprobación manual)' : '🔓 Pública'}</span></div>
        <div><strong>Seguidores:</strong> <span>${lastLoadedProfile.followers_count}</span></div>
        <div><strong>Seguidos:</strong> <span>${lastLoadedProfile.following_count}</span></div>
        <div><strong>Publicaciones:</strong> <span>${lastLoadedProfile.statuses_count}</span></div>
    `;

    document.getElementById('modal-profile-tech-info').style.display = 'flex';
}

function closeProfileTechInfoModal() {
    document.getElementById('modal-profile-tech-info').style.display = 'none';
}

async function performSearch(query) {
    if (!query || !query.trim()) return;
    query = query.trim();
    
    showTab('search-results');
    
    const accountsContainer = document.getElementById('search-accounts-container');
    const statusesContainer = document.getElementById('search-statuses-container');
    
    accountsContainer.innerHTML = '<div style="color: var(--text-muted); font-size: 14px; text-align: center; padding: 20px 0;">🔍 Buscando perfiles...</div>';
    statusesContainer.innerHTML = '<div style="color: var(--text-muted); font-size: 14px; text-align: center; padding: 20px 0;">🔍 Buscando publicaciones...</div>';
    
    try {
        const res = await fetch(`/api/v2/search?q=${encodeURIComponent(query)}&resolve=true`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (!res.ok) throw new Error('Error al buscar en el servidor.');
        const data = await res.json();
        
        // 1. Mostrar perfiles
        accountsContainer.innerHTML = '';
        if (!data.accounts || data.accounts.length === 0) {
            accountsContainer.innerHTML = '<div style="color: var(--text-muted); font-size: 14px; padding: 10px 0; text-align: center;">No se encontraron perfiles.</div>';
        } else {
            data.accounts.forEach(acc => {
                const accDiv = document.createElement('div');
                accDiv.style = "display: flex; align-items: center; gap: 12px; border: 1px solid var(--border-color); border-radius: 12px; padding: 12px 16px; cursor: pointer; background: rgba(255,255,255,0.02); transition: all 0.2s ease;";
                accDiv.onmouseover = () => {
                    accDiv.style.background = 'rgba(255,255,255,0.06)';
                    accDiv.style.borderColor = 'var(--primary)';
                };
                accDiv.onmouseout = () => {
                    accDiv.style.background = 'rgba(255,255,255,0.02)';
                    accDiv.style.borderColor = 'var(--border-color)';
                };
                accDiv.onclick = () => viewProfile(acc.id);
                
                accDiv.innerHTML = `
                    <img class="user-avatar" src="${acc.avatar || '/assets/default-avatar.png'}" alt="Avatar" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover;">
                    <div class="user-info" style="display: flex; flex-direction: column; gap: 2px;">
                        <div class="user-name" style="font-weight: 600; color: var(--text-color); font-size: 15px;">${escapeHTML(acc.display_name || acc.username)}</div>
                        <div class="user-handle" style="font-size: 13px; color: var(--text-muted);">@${escapeHTML(acc.acct)}</div>
                    </div>
                `;
                accountsContainer.appendChild(accDiv);
            });
        }
        
        // 2. Mostrar publicaciones
        statusesContainer.innerHTML = '';
        if (!data.statuses || data.statuses.length === 0) {
            statusesContainer.innerHTML = '<div style="color: var(--text-muted); font-size: 14px; padding: 10px 0; text-align: center;">No se encontraron publicaciones.</div>';
        } else {
            data.statuses.forEach(toot => {
                const card = createThreadTootElement(toot, false);
                statusesContainer.appendChild(card);
            });
        }
    } catch (err) {
        accountsContainer.innerHTML = `<div style="color: var(--error); font-size: 14px; text-align: center; padding: 10px 0;">Error: ${escapeHTML(err.message)}</div>`;
        statusesContainer.innerHTML = '';
    }
}

function escapeJSString(str) {
    if (!str) return '';
    let text = str.replace(/<p>/g, '').replace(/<\/p>/g, '').replace(/<br\s*\/?>/g, '\n');
    return text.replace(/\\/g, '\\\\').replace(/`/g, '\\`').replace(/\$/g, '\\$');
}

// ==========================================
// --- GESTIÓN DE LISTAS (LISTS JS) ---
// ==========================================
let allLists = [];
let selectedListId = null;
let listActiveSubView = 'feed';

async function loadLists() {
    if (!document.getElementById('tab-lists')) {
        window.location.href = '/lists';
        return;
    }
    if (window.KUTSOCIAL_USER_LISTS) {
        allLists = window.KUTSOCIAL_USER_LISTS;
    } else {
        allLists = [];
    }
    renderListsSidebar();
    
    if (allLists.length > 0) {
        if (selectedListId && allLists.some(l => l.id === selectedListId)) {
            selectList(selectedListId);
        } else {
            selectList(allLists[0].id);
        }
    } else {
        document.getElementById('list-no-selection').style.display = 'block';
        document.getElementById('list-detail-view').style.display = 'none';
        selectedListId = null;
    }
}

function renderListsSidebar() {
    const sidebar = document.getElementById('lists-sidebar');
    sidebar.innerHTML = '';
    allLists.forEach(l => {
        const btn = document.createElement('button');
        btn.className = 'list-nav-btn' + (selectedListId === l.id ? ' active' : '');
        btn.onclick = () => selectList(l.id);
        btn.innerHTML = `
            <span>📋 ${escapeHTML(l.title)}</span>
            <span class="material-icons-outlined" style="font-size: 14px; opacity: 0.5;">chevron_right</span>
        `;
        sidebar.appendChild(btn);
    });
}

async function selectList(id) {
    selectedListId = id;
    listActiveSubView = 'feed';
    document.querySelectorAll('#lists-sidebar .list-nav-btn').forEach((btn, idx) => {
        if (allLists[idx] && allLists[idx].id === id) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    const list = allLists.find(l => l.id === id);
    if (!list) return;

    document.getElementById('list-no-selection').style.display = 'none';
    document.getElementById('list-detail-view').style.display = 'block';
    document.getElementById('selected-list-title').innerText = list.title;
    
    loadListTimelineView();
}

function loadListTimelineView() {
    listActiveSubView = 'feed';
    document.getElementById('btn-list-timeline').style.background = 'var(--primary)';
    document.getElementById('btn-list-members').style.background = 'rgba(255,255,255,0.06)';
    
    document.getElementById('list-members-container').style.display = 'none';
    const feedContainer = document.getElementById('list-feed-container');
    feedContainer.style.display = 'block';
    feedContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Cargando publicaciones de la lista...</div>';

    fetch(`/api/v1/timelines/list/${selectedListId}`, {
        headers: { 'Authorization': `Bearer ${token}` }
    })
    .then(res => res.json())
    .then(toots => {
        feedContainer.innerHTML = '';
        if (toots.length === 0) {
            feedContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">No hay publicaciones de miembros de esta lista.</div>';
            return;
        }
        toots.forEach(toot => {
            const card = createThreadTootElement(toot, false);
            feedContainer.appendChild(card);
        });
    })
    .catch(e => {
        feedContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar timeline de la lista.</div>';
    });
}

function loadListMembersView() {
    listActiveSubView = 'members';
    document.getElementById('btn-list-timeline').style.background = 'rgba(255,255,255,0.06)';
    document.getElementById('btn-list-members').style.background = 'var(--primary)';
    
    document.getElementById('list-feed-container').style.display = 'none';
    const membersContainer = document.getElementById('list-members-container');
    membersContainer.style.display = 'flex';
    membersContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Cargando miembros...</div>';

    fetch(`/api/v1/lists/${selectedListId}/accounts`, {
        headers: { 'Authorization': `Bearer ${token}` }
    })
    .then(res => res.json())
    .then(accounts => {
        membersContainer.innerHTML = '';
        if (accounts.length === 0) {
            membersContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Esta lista está vacía. Añade miembros desde sus perfiles.</div>';
            return;
        }
        accounts.forEach(acc => {
            const div = document.createElement('div');
            div.style = "display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 14px; background: rgba(255,255,255,0.02);";
            div.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px; cursor: pointer;" onclick="viewProfile('${acc.id}')">
                    <img class="user-avatar" src="${acc.avatar || '/assets/default-avatar.png'}" alt="Avatar" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                    <div>
                        <div style="font-weight:600; font-size:14px; color:var(--text-color);">${escapeHTML(acc.display_name || acc.username)}</div>
                        <div style="font-size:12px; color:var(--text-muted);">@${escapeHTML(acc.acct)}</div>
                    </div>
                </div>
                <button class="btn-publish" style="width:auto; padding: 4px 10px; font-size: 11px; margin: 0; background: var(--error);" onclick="removeFromList('${acc.id}', this)">Eliminar</button>
            `;
            membersContainer.appendChild(div);
        });
    })
    .catch(e => {
        membersContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar miembros de la lista.</div>';
    });
}

async function removeFromList(accountId, btn) {
    btn.innerText = 'Quitando...';
    try {
        const res = await fetch(`/api/v1/lists/${selectedListId}/accounts`, {
            method: 'DELETE',
            headers: { 
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ account_ids: [accountId] })
        });
        if (res.ok) {
            loadListMembersView();
        } else {
            btn.innerText = 'Eliminar';
        }
    } catch (e) {
        btn.innerText = 'Eliminar';
    }
}

async function deleteSelectedList() {
    if (!confirm('¿Seguro que deseas eliminar esta lista?')) return;
    try {
        const res = await fetch(`/api/v1/lists/${selectedListId}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            selectedListId = null;
            loadLists();
        }
    } catch (e) {
        console.error(e);
    }
}

function showCreateListModal() {
    document.getElementById('list-modal-title').innerText = 'Nueva Lista';
    document.getElementById('list-modal-input-title').value = '';
    document.getElementById('modal-manage-list').style.display = 'flex';
}

function closeListModal() {
    document.getElementById('modal-manage-list').style.display = 'none';
}

async function saveListModal() {
    const title = document.getElementById('list-modal-input-title').value.trim();
    if (!title) return alert('El nombre de la lista es requerido');
    
    try {
        const res = await fetch('/api/v1/lists', {
            method: 'POST',
            headers: { 
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title: title, replies_policy: 'followed' })
        });
        if (res.ok) {
            const newList = await res.json();
            selectedListId = newList.id;
            closeListModal();
            loadLists();
        } else {
            alert('Error al crear la lista');
        }
    } catch (e) {
        alert('Error de conexión');
    }
}

// ==========================================
// --- GESTIÓN DE COLECCIONES (COLLECTIONS JS) ---
// ==========================================
let allCollections = [];
let selectedCollectionId = null;

async function loadCollections() {
    if (!document.getElementById('tab-collections')) {
        window.location.href = '/collections';
        return;
    }
    if (window.KUTSOCIAL_USER_COLLECTIONS) {
        allCollections = window.KUTSOCIAL_USER_COLLECTIONS;
    } else {
        allCollections = [];
    }
    renderCollectionsSidebar();
    
    if (allCollections.length > 0) {
        if (selectedCollectionId && allCollections.some(c => c.id === selectedCollectionId)) {
            selectCollection(selectedCollectionId);
        } else {
            selectCollection(allCollections[0].id);
        }
    } else {
        document.getElementById('collection-no-selection').style.display = 'block';
        document.getElementById('collection-detail-view').style.display = 'none';
        selectedCollectionId = null;
    }
}

function renderCollectionsSidebar() {
    const sidebar = document.getElementById('collections-sidebar');
    sidebar.innerHTML = '';
    allCollections.forEach(c => {
        const btn = document.createElement('button');
        btn.className = 'list-nav-btn' + (selectedCollectionId === c.id ? ' active' : '');
        btn.onclick = () => selectCollection(c.id);
        btn.innerHTML = `
            <span>📁 ${escapeHTML(c.title || c.name)}</span>
            <span class="material-icons-outlined" style="font-size: 14px; opacity: 0.5;">chevron_right</span>
        `;
        sidebar.appendChild(btn);
    });
}

async function selectCollection(id) {
    selectedCollectionId = id;
    document.querySelectorAll('#collections-sidebar .list-nav-btn').forEach((btn, idx) => {
        if (allCollections[idx] && allCollections[idx].id === id) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    const collection = allCollections.find(c => c.id === id);
    if (!collection) return;

    document.getElementById('collection-no-selection').style.display = 'none';
    document.getElementById('collection-detail-view').style.display = 'block';
    document.getElementById('selected-collection-title').innerText = collection.title || collection.name;
    document.getElementById('selected-collection-desc').innerText = collection.description || 'Sin descripción';
    
    loadCollectionAccounts();
}

function loadCollectionAccounts() {
    const container = document.getElementById('collection-accounts-container');
    container.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Cargando cuentas...</div>';

    fetch(`/api/v1/collections/${selectedCollectionId}`, {
        headers: { 'Authorization': `Bearer ${token}` }
    })
    .then(res => res.json())
    .then(data => {
        container.innerHTML = '';
        const accounts = data.items || data.accounts || [];
        if (accounts.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Esta colección está vacía. Añade cuentas desde sus perfiles.</div>';
            return;
        }
        accounts.forEach(acc => {
            const div = document.createElement('div');
            div.style = "display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 14px; background: rgba(255,255,255,0.02);";
            div.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px; cursor: pointer;" onclick="viewProfile('${acc.id}')">
                    <img class="user-avatar" src="${acc.avatar || '/assets/default-avatar.png'}" alt="Avatar" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                    <div>
                        <div style="font-weight:600; font-size:14px; color:var(--text-color);">${escapeHTML(acc.display_name || acc.username)}</div>
                        <div style="font-size:12px; color:var(--text-muted);">@${escapeHTML(acc.acct)}</div>
                    </div>
                </div>
                <button class="btn-publish" style="width:auto; padding: 4px 10px; font-size: 11px; margin: 0; background: var(--error);" onclick="removeFromCollection('${acc.id}', this)">Eliminar</button>
            `;
            container.appendChild(div);
        });
    })
    .catch(e => {
        container.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--error);">Error al cargar cuentas de la colección.</div>';
    });
}

async function removeFromCollection(accountId, btn) {
    btn.innerText = 'Quitando...';
    try {
        const res = await fetch(`/api/v1/collections/${selectedCollectionId}/items`, {
            method: 'DELETE',
            headers: { 
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ account_ids: [accountId] })
        });
        if (res.ok) {
            loadCollectionAccounts();
        } else {
            btn.innerText = 'Eliminar';
        }
    } catch (e) {
        btn.innerText = 'Eliminar';
    }
}

async function deleteSelectedCollection() {
    if (!confirm('¿Seguro que deseas eliminar esta colección?')) return;
    try {
        const res = await fetch(`/api/v1/collections/${selectedCollectionId}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            selectedCollectionId = null;
            loadCollections();
        }
    } catch (e) {
        console.error(e);
    }
}

function showCreateCollectionModal() {
    document.getElementById('collection-modal-title').innerText = 'Nueva Colección';
    document.getElementById('collection-modal-input-title').value = '';
    document.getElementById('collection-modal-input-desc').value = '';
    document.getElementById('modal-manage-collection').style.display = 'flex';
}

function closeCollectionModal() {
    document.getElementById('modal-manage-collection').style.display = 'none';
}

async function saveCollectionModal() {
    const title = document.getElementById('collection-modal-input-title').value.trim();
    const desc = document.getElementById('collection-modal-input-desc').value.trim();
    if (!title) return alert('El nombre de la colección es requerido');
    
    try {
        const res = await fetch('/api/v1/collections', {
            method: 'POST',
            headers: { 
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title: title, description: desc })
        });
        if (res.ok) {
            const newColl = await res.json();
            selectedCollectionId = newColl.id;
            closeCollectionModal();
            loadCollections();
        } else {
            alert('Error al crear la colección');
        }
    } catch (e) {
        alert('Error de conexión');
    }
}

// ==========================================
// --- HASHTAGS SEGUIDOS (FOLLOWED HASHTAGS JS) ---
// ==========================================
let followedTags = [];

async function loadFollowedHashtags() {
    if (!document.getElementById('tab-followed-hashtags')) {
        window.location.href = '/followed-hashtags';
        return;
    }
    if (window.KUTSOCIAL_USER_HASHTAGS) {
        followedTags = window.KUTSOCIAL_USER_HASHTAGS;
    } else {
        followedTags = [];
    }
    
    const container = document.getElementById('followed-hashtags-container');
    container.innerHTML = '';
    
    if (followedTags.length === 0) {
        container.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding: 20px; color: var(--text-muted);">No sigues ningún hashtag todavía.</div>';
    } else {
        followedTags.forEach(tag => {
            const div = document.createElement('div');
            div.className = 'tag-follow-card';
            div.style = "display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 15px;";
            div.innerHTML = `
                <div style="cursor:pointer; display:flex; flex-direction:column; min-width:0; flex:1; margin-right:8px;" onclick="viewHashtagTimeline('${tag}')">
                    <span style="font-weight:700; color:var(--text-color); font-size:15px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">#${escapeHTML(tag)}</span>
                    <span style="font-size:11px; color:var(--text-muted); margin-top:2px;">Ver publicaciones</span>
                </div>
                <button class="btn-publish" style="width:auto; white-space:nowrap; flex-shrink:0; padding: 4px 10px; font-size: 11px; margin: 0; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-color);" onclick="unfollowHashtag('${tag}', this.parentElement)">Dejar de seguir</button>
            `;
            container.appendChild(div);
        });
    }
}

async function followHashtagFromInput() {
    const input = document.getElementById('hashtag-follow-input');
    const hashtag = input.value.trim().replace(/^#/, '');
    if (!hashtag) return;
    
    try {
        const res = await fetch(`/api/v1/tags/${encodeURIComponent(hashtag)}/follow`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            input.value = '';
            loadFollowedHashtags();
        }
    } catch (e) {
        console.error(e);
    }
}

async function followHashtag(name) {
    try {
        const res = await fetch(`/api/v1/tags/${encodeURIComponent(name)}/follow`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            loadFollowedHashtags();
            return true;
        }
    } catch (e) {
        console.error(e);
    }
    return false;
}

async function unfollowHashtag(name, btn) {
    if (btn) btn.innerText = 'Dejando...';
    try {
        const res = await fetch(`/api/v1/tags/${encodeURIComponent(name)}/unfollow`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            loadFollowedHashtags();
        } else if (btn) {
            btn.innerText = 'Dejar de seguir';
        }
    } catch (e) {
        if (btn) btn.innerText = 'Dejar de seguir';
    }
}

function viewHashtagTimeline(name) {
    switchTimeline('tag_' + name);
}

// ==========================================
// --- MODAL ORGANIZAR (ORGANIZE ACCOUNT MODAL JS) ---
// ==========================================
let organizeAccountId = null;

async function openManageListsModal() {
    organizeAccountId = activeProfileViewId;
    if (!organizeAccountId) return;

    const handleText = document.getElementById('profile-view-handle').innerText;
    document.getElementById('organize-account-handle').innerText = handleText;

    const listsContainer = document.getElementById('organize-lists-checkboxes');
    listsContainer.innerHTML = '<div style="font-size:12px; color:var(--text-muted);">Cargando listas...</div>';
    
    const collsContainer = document.getElementById('organize-collections-checkboxes');
    collsContainer.innerHTML = '<div style="font-size:12px; color:var(--text-muted);">Cargando colecciones...</div>';

    document.getElementById('modal-organize-account').style.display = 'flex';

    try {
        const listRes = await fetch('/api/v1/lists', {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        const lists = await listRes.json();
        
        const collRes = await fetch('/api/v1/collections', {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        const collData = await collRes.json();
        const colls = collData.items || [];

        listsContainer.innerHTML = '';
        if (lists.length === 0) {
            listsContainer.innerHTML = '<div style="font-size:12px; color:var(--text-muted); font-style: italic;">No tienes listas creadas.</div>';
        } else {
            for (const list of lists) {
                const inList = await checkAccountInList(list.id, organizeAccountId);
                const label = document.createElement('label');
                label.style = "display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; padding: 4px 0;";
                label.innerHTML = `
                    <input type="checkbox" ${inList ? 'checked' : ''} onchange="toggleAccountListMembership('${list.id}', '${organizeAccountId}', this)">
                    <span>${escapeHTML(list.title)}</span>
                `;
                listsContainer.appendChild(label);
            }
        }

        collsContainer.innerHTML = '';
        if (colls.length === 0) {
            collsContainer.innerHTML = '<div style="font-size:12px; color:var(--text-muted); font-style: italic;">No tienes colecciones creadas.</div>';
        } else {
            for (const coll of colls) {
                const inColl = await checkAccountInCollection(coll.id, organizeAccountId);
                const label = document.createElement('label');
                label.style = "display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; padding: 4px 0;";
                label.innerHTML = `
                    <input type="checkbox" ${inColl ? 'checked' : ''} onchange="toggleAccountCollectionMembership('${coll.id}', '${organizeAccountId}', this)">
                    <span>${escapeHTML(coll.title || coll.name)}</span>
                `;
                collsContainer.appendChild(label);
            }
        }
    } catch (e) {
        console.error(e);
    }
}

async function checkAccountInList(listId, accId) {
    try {
        const res = await fetch(`/api/v1/lists/${listId}/accounts`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        const accs = await res.json();
        return accs.some(a => String(a.id) === String(accId));
    } catch (e) {
        return false;
    }
}

async function checkAccountInCollection(collId, accId) {
    try {
        const res = await fetch(`/api/v1/collections/${collId}`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        const data = await res.json();
        const accs = data.items || data.accounts || [];
        return accs.some(a => String(a.id) === String(accId));
    } catch (e) {
        return false;
    }
}

async function toggleAccountListMembership(listId, accId, checkbox) {
    checkbox.disabled = true;
    const method = checkbox.checked ? 'POST' : 'DELETE';
    try {
        const res = await fetch(`/api/v1/lists/${listId}/accounts`, {
            method: method,
            headers: { 
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ account_ids: [accId] })
        });
        if (!res.ok) checkbox.checked = !checkbox.checked;
    } catch (e) {
        checkbox.checked = !checkbox.checked;
    } finally {
        checkbox.disabled = false;
    }
}

async function toggleAccountCollectionMembership(collId, accId, checkbox) {
    checkbox.disabled = true;
    const method = checkbox.checked ? 'POST' : 'DELETE';
    try {
        const res = await fetch(`/api/v1/collections/${collId}/items`, {
            method: method,
            headers: { 
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ account_ids: [accId] })
        });
        if (!res.ok) checkbox.checked = !checkbox.checked;
    } catch (e) {
        checkbox.checked = !checkbox.checked;
    } finally {
        checkbox.disabled = false;
    }
}

function closeOrganizeModal() {
    document.getElementById('modal-organize-account').style.display = 'none';
}

function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function escapeJSString(str) {
    if (!str) return '';
    let text = str.replace(/<p>/g, '').replace(/<\/p>/g, '').replace(/<br\s*\/?>/g, '\n');
    return text.replace(/\\/g, '\\\\').replace(/`/g, '\\`').replace(/\$/g, '\\$');
}

// Interceptar clicks en links de hashtag dentro de toots
document.addEventListener('click', function(e) {
    const link = e.target.closest('.toot-content a');
    if (!link) return;
    
    const href = link.getAttribute('href') || '';
    const text = link.textContent.trim();
    
    if (link.classList.contains('hashtag') || text.startsWith('#') || href.includes('/tags/')) {
        let tag = text.replace(/^#/, '');
        if (!tag && href.includes('/tags/')) {
            const match = href.match(/\/tags\/([^/?#]+)/);
            if (match) tag = match[1];
        }
        if (tag) {
            e.preventDefault();
            viewHashtagTimeline(tag);
        }
    }
});
