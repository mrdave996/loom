(function () {
    'use strict';
    var script = document.currentScript;
    if (!script) return;
    var endpoint = script.dataset.endpoint || '/analytics/event';
    var consentRequired = script.dataset.consentRequired === 'true';
    var consentKey = 'loom_analytics_consent';
    var signupTokenKey = 'loom_signup_token';
    var sessionTimeout = 30 * 60 * 1000;
    var pageEnteredAt = Date.now();
    var pageExitSent = false;
    var activeStartedAt = document.visibilityState === 'visible' ? pageEnteredAt : 0;
    var activeDurationMs = 0;

    function get(storage, key) { try { return storage.getItem(key); } catch (e) { return null; } }
    function set(storage, key, value) { try { storage.setItem(key, value); } catch (e) {} }
    function id(prefix) { return prefix + '_' + (window.crypto && crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : Math.random().toString(16).slice(2) + Date.now()); }
    function cookie(name, value, age) { document.cookie = name + '=' + encodeURIComponent(value) + '; Max-Age=' + age + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); }
    function granted() { return !consentRequired || get(localStorage, consentKey) === 'granted'; }
    function path() { return window.location.pathname || '/'; }
    function referrer() {
        if (!document.referrer) return '';
        try { var u = new URL(document.referrer); return u.origin + u.pathname; } catch (e) { return ''; }
    }
    function searchKeyword() {
        if (!document.referrer) return '';
        try {
            var u = new URL(document.referrer), host = u.hostname.toLowerCase();
            if (!/(^|\\.)((google|bing|yahoo|duckduckgo)\\.)/.test(host)) return '';
            return (u.searchParams.get('q') || u.searchParams.get('query') || u.searchParams.get('text') || '').slice(0, 200);
        } catch (e) { return ''; }
    }
    function attribution() {
        var p = new URLSearchParams(window.location.search), result = {};
        ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','gbraid','wbraid','fbclid','msclkid'].forEach(function (key) { var value = p.get(key); if (value) result[key] = value.slice(0, 200); });
        return result;
    }
    function parse(value) { try { return value ? JSON.parse(value) : {}; } catch (e) { return {}; } }
    function context() {
        var now = Date.now(), visitor = get(localStorage, 'loom_visitor_id') || id('vis'), session = get(sessionStorage, 'loom_session_id');
        var started = parseInt(get(sessionStorage, 'loom_session_started_at') || '0', 10), fresh = !session || !started || now - started > sessionTimeout;
        if (fresh) {
            var firstReferrer = referrer();
            try { if (firstReferrer && new URL(firstReferrer).origin === window.location.origin) firstReferrer = ''; } catch (e) { firstReferrer = ''; }
            session = id('ses');
            set(sessionStorage, 'loom_session_id', session);
            set(sessionStorage, 'loom_session_started_at', String(now));
            set(sessionStorage, 'loom_session_landing_page', path());
            set(sessionStorage, 'loom_session_attribution', JSON.stringify(attribution()));
            set(sessionStorage, 'loom_session_referrer', firstReferrer);
        }
        set(localStorage, 'loom_visitor_id', visitor); cookie('loom_visitor_id', visitor, 34128000); cookie('loom_session_id', session, 7200);
        var original = get(localStorage, 'loom_original_attribution');
        if (!original && Object.keys(attribution()).length) { original = JSON.stringify(attribution()); set(localStorage, 'loom_original_attribution', original); }
        return { visitor_id: visitor, session_id: session, landing_page: get(sessionStorage, 'loom_session_landing_page') || path(), attribution: parse(get(sessionStorage, 'loom_session_attribution')), original_attribution: parse(original), original_referrer: get(sessionStorage, 'loom_session_referrer') || '', current_attribution: attribution(), new_session: fresh };
    }
    function signupContext(tenant) {
        if (!granted()) return null;
        var c = context(), token = get(sessionStorage, signupTokenKey) || id('sig');
        set(sessionStorage, signupTokenKey, token);
        return { signup_token: token, session_id: c.session_id, visitor_id: c.visitor_id, tenant: String(tenant || '').slice(0, 63) };
    }
    window.LoomAnalytics = window.LoomAnalytics || {};
    window.LoomAnalytics.signupContext = signupContext;
    function send(type, extra) {
        if (!granted()) return;
        var c = context(), event = Object.assign({ event_id: id('evt'), event_type: type, occurred_at: new Date().toISOString(), visitor_id: c.visitor_id, session_id: c.session_id, url: location.origin + path(), path: path(), page_title: document.title.slice(0, 200), referrer: referrer(), search_keyword: searchKeyword(), original_referrer: c.original_referrer, landing_page: c.landing_page, attribution: c.attribution, session_attribution: c.attribution, original_attribution: c.original_attribution, utm_source: c.current_attribution.utm_source || '', utm_medium: c.current_attribution.utm_medium || '', utm_campaign: c.current_attribution.utm_campaign || '', utm_term: c.current_attribution.utm_term || '', utm_content: c.current_attribution.utm_content || '', metadata: {} }, extra || {});
        var body; try { body = JSON.stringify(event); } catch (e) { return; }
        if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }))) return;
        if (window.fetch) fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'same-origin' }).catch(function () {});
    }
    if (!granted()) return;
    var initial = context();
    if (initial.new_session) send('session_start');
    send('page_view');
    document.addEventListener('click', function (event) { var target = event.target.closest ? event.target.closest('[data-loom-event], .btn') : null; if (target) send(target.dataset.loomEvent || 'cta_click', { metadata: { label: (target.dataset.loomLabel || target.getAttribute('aria-label') || '').slice(0, 120) } }); });
    document.addEventListener('focusin', function (event) { var form = event.target.closest ? event.target.closest('form') : null; if (form && form.dataset.loomStarted !== '1') { form.dataset.loomStarted = '1'; send('form_start'); } });
    document.addEventListener('submit', function (event) { if (event.target && event.target.tagName === 'FORM') send('form_submit'); });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { if (activeStartedAt > 0) activeDurationMs += Date.now() - activeStartedAt; activeStartedAt = 0; } else if (!pageExitSent) activeStartedAt = Date.now(); });
    window.addEventListener('pagehide', function () { if (pageExitSent) return; pageExitSent = true; var now = Date.now(); if (activeStartedAt > 0) activeDurationMs += now - activeStartedAt; var seconds = Math.max(0, Math.min(1800, Math.round((now - pageEnteredAt) / 1000))); var active = Math.max(0, Math.min(seconds, Math.round(activeDurationMs / 1000))); send('page_exit', { metadata: { duration_seconds: seconds, active_duration_seconds: active } }); });
}());
