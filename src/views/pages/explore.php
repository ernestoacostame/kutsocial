<style>
#tab-explore.explore-page {
    width: 100%;
    margin: 0;
    padding: 0;
    background: transparent !important;
    border: none !important;
    border-radius: 0 !important;
}
#tab-explore .explore-header {
    padding: 0 !important;
    margin: 0 !important;
    border: none !important;
    border-radius: 0 !important;
    background: transparent !important;
}
#tab-explore .explore-title {
    margin: 0 !important;
    padding: 16px 16px 14px !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    text-align: center !important;
    color: var(--text-color, #fff) !important;
}
#tab-explore .explore-search-form {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 10px !important;
    margin: 0 16px 10px !important;
    padding: 9px 14px !important;
    border-radius: 8px !important;
    border: 1px solid rgba(255,255,255,0.22) !important;
    background: rgba(0,0,0,0.2) !important;
}
#tab-explore .explore-search-form .material-icons-outlined {
    font-size: 20px !important;
    color: var(--text-muted, #9ca3af) !important;
    flex-shrink: 0 !important;
    margin: 0 !important;
}
#tab-explore .explore-search-form input {
    flex: 1 1 auto !important;
    width: 100% !important;
    min-width: 0 !important;
    background: transparent !important;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    color: var(--text-color, #fff) !important;
    font-size: 14px !important;
    padding: 0 !important;
    margin: 0 !important;
}
#tab-explore .explore-tabs {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 0 !important;
    border-bottom: 1px solid rgba(255,255,255,0.1) !important;
    padding: 0 8px !important;
}
#tab-explore .explore-tab-btn {
    position: relative !important;
    width: 100% !important;
    background: transparent !important;
    background-image: none !important;
    box-shadow: none !important;
    border: none !important;
    border-radius: 0 !important;
    color: var(--text-muted, #9ca3af) !important;
    padding: 12px 6px !important;
    margin: 0 !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    text-align: center !important;
    transition: color 0.15s ease !important;
}
#tab-explore .explore-tab-btn:hover {
    color: var(--text-color, #fff) !important;
    background: transparent !important;
}
#tab-explore .explore-tab-btn.active {
    color: var(--text-color, #fff) !important;
}
#tab-explore .explore-tab-btn.active::after {
    content: "";
    position: absolute;
    left: 25%;
    right: 25%;
    bottom: 0;
    height: 3px;
    border-radius: 3px 3px 0 0;
    background: #858afa;
}
#tab-explore .explore-content {
    padding: 0 !important;
}
#tab-explore .explore-content .toot-card {
    border-radius: 0 !important;
    border-left: none !important;
    border-right: none !important;
    border-top: none !important;
    margin-bottom: 0 !important;
}
#tab-explore .explore-tag-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    padding: 14px 16px !important;
    border-bottom: 1px solid rgba(255,255,255,0.08) !important;
    text-decoration: none !important;
    color: var(--text-color, #fff) !important;
    background: transparent !important;
}
#tab-explore .explore-tag-row:hover {
    background: rgba(255,255,255,0.04) !important;
}
#tab-explore .explore-news-hero {
    display: block !important;
    text-decoration: none !important;
    color: var(--text-color, #fff) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    margin-bottom: 12px !important;
    background: rgba(255,255,255,0.02) !important;
}
#tab-explore .explore-news-hero img {
    width: 100% !important;
    max-height: 280px !important;
    object-fit: cover !important;
    display: block !important;
}
#tab-explore .explore-news-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 14px !important;
    padding: 14px 16px !important;
    border-bottom: 1px solid rgba(255,255,255,0.08) !important;
    text-decoration: none !important;
    color: var(--text-color, #fff) !important;
    background: transparent !important;
}
#tab-explore .explore-news-row:hover {
    background: rgba(255,255,255,0.04) !important;
}
#tab-explore .explore-news-thumb {
    width: 84px !important;
    height: 84px !important;
    border-radius: 10px !important;
    object-fit: cover !important;
    flex-shrink: 0 !important;
}
#tab-explore .explore-posts-badge {
    display: inline-block !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
    background: rgba(255,255,255,0.08) !important;
    color: var(--text-muted, #9ca3af) !important;
}
</style>
<div id="tab-explore" class="explore-page">
    <div class="explore-header">
        <h2 class="explore-title"><?= __('nav_explore', 'Explore') ?></h2>
        <form class="explore-search-form" onsubmit="event.preventDefault(); window.submitExploreSearch();">
            <span class="material-icons-outlined">search</span>
            <input type="text" id="explore-search-input" placeholder="<?= __('explore_search_placeholder', 'Search or paste link...') ?>" autocomplete="off">
        </form>
        <div class="explore-tabs" role="tablist">
            <button type="button" class="explore-tab-btn active" data-explore-tab="posts" onclick="window.switchExploreTab('posts')"><?= __('explore_posts', 'Posts') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="hashtags" onclick="window.switchExploreTab('hashtags')"><?= __('explore_hashtags', 'Hashtags') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="people" onclick="window.switchExploreTab('people')"><?= __('explore_people', 'People') ?></button>
            <button type="button" class="explore-tab-btn" data-explore-tab="news" onclick="window.switchExploreTab('news')"><?= __('explore_news', 'News') ?></button>
        </div>
    </div>
    <div id="explore-content-container" class="explore-content">
        <div style="text-align:center; padding: 30px; color: var(--text-muted);"><?= __('loading', 'Loading...') ?></div>
    </div>
</div>
<script>
(function () {
    function esc(s) {
        if (typeof escapeHTML === 'function') return escapeHTML(s);
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function pUrl(u) {
        if (typeof proxyUrl === 'function') return proxyUrl(u);
        return u || '';
    }
    function sparkline(history) {
        if (!Array.isArray(history) || history.length === 0) return '';
        var pts = history.slice(0, 7).reverse().map(function (h) { return parseInt(h.uses || '0', 10); });
        var max = Math.max.apply(null, [1].concat(pts));
        var w = 72, h = 26, step = pts.length > 1 ? w / (pts.length - 1) : w;
        var coords = pts.map(function (v, i) {
            return (i * step).toFixed(1) + ',' + (h - 3 - (v / max) * (h - 6)).toFixed(1);
        }).join(' ');
        return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" style="flex-shrink:0;"><polyline fill="none" stroke="#858afa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="' + coords + '"/></svg>';
    }

    window.submitExploreSearch = function () {
        var q = (document.getElementById('explore-search-input')?.value || '').trim();
        if (!q) return;
        var base = window.KUTSOCIAL_BASE_PATH || '';
        window.location.href = base + '/search-results?q=' + encodeURIComponent(q);
    };

    window.switchExploreTab = async function (tab) {
        document.querySelectorAll('#tab-explore .explore-tab-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.exploreTab === tab);
        });
        var container = document.getElementById('explore-content-container');
        if (!container) return;
        container.innerHTML = '<div style="text-align:center; padding: 30px; color: var(--text-muted);"><?= __('loading', 'Loading...') ?></div>';
        var tok = (typeof token !== 'undefined' && token) ? token : (localStorage.getItem('kutsocial_token') || window.KUTSOCIAL_AUTO_TOKEN || '');
        var headers = tok ? { 'Authorization': 'Bearer ' + tok } : {};
        var base = window.KUTSOCIAL_BASE_PATH || '';

        try {
            if (tab === 'posts') {
                var res = await fetch('/api/v1/trends/statuses?limit=20', { headers: headers });
                var items = await res.json();
                container.innerHTML = '';
                if (!Array.isArray(items) || items.length === 0) {
                    container.innerHTML = '<div style="text-align:center; padding: 30px; color: var(--text-muted);">No trending posts yet.</div>';
                    return;
                }
                items.forEach(function (st) {
                    if (typeof createThreadTootElement === 'function') {
                        container.appendChild(createThreadTootElement(st, false));
                    } else {
                        var div = document.createElement('div');
                        div.className = 'toot-card';
                        div.style.cssText = 'padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.08);';
                        div.innerHTML = '<div style="font-weight:700; margin-bottom:6px;">' + esc(st.account?.display_name || st.account?.username) + ' <span style="font-weight:400; color:var(--text-muted); font-size:12.5px;">@' + esc(st.account?.acct || st.account?.username || '') + '</span></div><div class="toot-content">' + (st.content || '') + '</div>';
                        container.appendChild(div);
                    }
                });
            } else if (tab === 'hashtags') {
                var resT = await fetch('/api/v1/trends/tags?limit=20', { headers: headers });
                var tags = await resT.json();
                if (!Array.isArray(tags) || tags.length === 0) {
                    container.innerHTML = '<div style="text-align:center; padding: 30px; color: var(--text-muted);">No trending hashtags yet.</div>';
                    return;
                }
                container.innerHTML = tags.map(function (t) {
                    var people = t.total_accounts || (t.history && t.history[0] ? t.history[0].accounts : 0);
                    return '<a class="explore-tag-row" href="' + base + '/tag_' + encodeURIComponent(t.name) + '">' +
                        '<div><div style="font-weight:700; font-size:15px;">#' + esc(t.name) + '</div>' +
                        '<div style="font-size:12.5px; color:var(--text-muted);">' + people + ' people recently</div></div>' +
                        sparkline(t.history) + '</a>';
                }).join('');
            } else if (tab === 'people') {
                var resP = await fetch('/api/v1/directory?limit=24', { headers: headers });
                var users = await resP.json();
                if (!Array.isArray(users) || users.length === 0) {
                    container.innerHTML = '<div style="text-align:center; padding: 30px; color: var(--text-muted);">No profiles available.</div>';
                    return;
                }
                container.innerHTML = '<div style="padding:12px 16px; display:flex; flex-direction:column; gap:10px;">' + users.map(function (u) {
                    var doc = new DOMParser().parseFromString(u.note || '', 'text/html');
                    var bio = esc((doc.body.textContent || '').trim().slice(0, 120));
                    return '<a href="' + base + '/@' + encodeURIComponent(u.acct) + '" style="display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid var(--border-color); border-radius:12px; text-decoration:none; color:inherit; background:rgba(255,255,255,0.02);">' +
                        '<img src="' + pUrl(u.avatar) + '" alt="" style="width:44px; height:44px; border-radius:50%; object-fit:cover;">' +
                        '<div style="min-width:0; flex:1;"><div style="font-weight:700; font-size:14.5px;">' + esc(u.display_name || u.username) + '</div>' +
                        '<div style="font-size:12.5px; color:var(--text-muted);">@' + esc(u.acct) + '</div>' +
                        (bio ? '<div style="font-size:12.5px; color:var(--text-muted); margin-top:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + bio + '</div>' : '') +
                        '</div><span class="explore-posts-badge">' + (u.statuses_count || 0) + ' posts</span></a>';
                }).join('') + '</div>';
            } else if (tab === 'news') {
                var resN = await fetch('/api/v1/trends/links?limit=15', { headers: headers });
                var links = await resN.json();
                if (!Array.isArray(links) || links.length === 0) {
                    container.innerHTML = '<div style="text-align:center; padding: 30px; color: var(--text-muted);">No trending links yet.</div>';
                    return;
                }
                var first = links[0];
                var firstUses = (first.history && first.history[0]) ? first.history[0].uses : 1;
                var html = '<div style="padding:12px 16px;"><a class="explore-news-hero" href="' + esc(first.url) + '" target="_blank" rel="noopener noreferrer">' +
                    (first.image ? '<img src="' + pUrl(first.image) + '" alt="">' : '') +
                    '<div style="padding:14px 16px;"><div style="font-size:12px; color:var(--text-muted); margin-bottom:4px;">' + esc(first.provider_name || '') + '</div>' +
                    '<div style="font-size:17px; font-weight:700; line-height:1.3; margin-bottom:10px;">' + esc(first.title) + '</div>' +
                    '<span class="explore-posts-badge">' + firstUses + ' posts</span></div></a>';
                if (links.length > 1) {
                    html += '<div style="border:1px solid var(--border-color); border-radius:14px; overflow:hidden;">' + links.slice(1).map(function (l) {
                        var uses = (l.history && l.history[0]) ? l.history[0].uses : 1;
                        return '<a class="explore-news-row" href="' + esc(l.url) + '" target="_blank" rel="noopener noreferrer">' +
                            '<div style="min-width:0; flex:1;"><div style="font-size:12px; color:var(--text-muted); margin-bottom:3px;">' + esc(l.provider_name || '') + '</div>' +
                            '<div style="font-size:14.5px; font-weight:700; line-height:1.3; margin-bottom:8px;">' + esc(l.title) + '</div>' +
                            '<span class="explore-posts-badge">' + uses + ' posts</span></div>' +
                            (l.image ? '<img class="explore-news-thumb" src="' + pUrl(l.image) + '" alt="">' : '') + '</a>';
                    }).join('') + '</div>';
                }
                html += '</div>';
                container.innerHTML = html;
            }
        } catch (err) {
            console.error(err);
            container.innerHTML = '<div style="text-align:center; padding: 30px; color: var(--error, #ef4444);">Error loading Explore.</div>';
        }
    };

    window.initExplorePage = function () {
        var attempts = 0;
        function start() {
            if (typeof createThreadTootElement === 'function' || attempts++ > 30) {
                window.switchExploreTab('posts');
            } else {
                setTimeout(start, 60);
            }
        }
        start();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.initExplorePage);
    } else {
        window.initExplorePage();
    }
})();
</script>
