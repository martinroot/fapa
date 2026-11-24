// i18n loader — detects browser language, loads /lang/XX.js
(function() {
  var supported = ['en','ru','ar','es','pt','fr','de','tr','id','ja','ro','bg','el','it','pl','uk','nl','cs','sv','hu'];
  var nav = (window.__langCode || navigator.language || navigator.userLanguage || 'en').slice(0,2).toLowerCase();
  var code = supported.indexOf(nav) !== -1 ? nav : 'en';

  // expose for JS code
  if (typeof _activeLang !== 'undefined') { try { _activeLang = code; } catch(e){} }
  window.__langCode = code;

  window.__applyI18n = function() {
    var T = window.__LANG_DATA__ || {};
    document.querySelectorAll('[data-i18n]').forEach(function(el) {
      var key = el.getAttribute('data-i18n');
      if (T[key] !== undefined) el.innerHTML = T[key];
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(function(el) {
      var key = el.getAttribute('data-i18n-placeholder');
      if (T[key] !== undefined) el.placeholder = T[key];
    });
    if (code === 'ar') document.documentElement.setAttribute('dir','rtl');
    else document.documentElement.removeAttribute('dir');
    try { if (typeof _activeLang !== 'undefined') _activeLang = code; } catch(e){}
  };

  // If PHP already pre-loaded the lang file, just apply and exit
  if (window.__LANG_DATA__) {
    if (window.__applyI18n) setTimeout(window.__applyI18n, 0);
    return;
  }

  function load(langCode, fallback) {
    var s = document.createElement('script');
    s.src = '/lang/' + langCode + '.js?v=10';
    s.async = false;
    s.onload = function() { if(window.__applyI18n) window.__applyI18n(); };
    if (fallback) {
      s.onerror = null;
    } else {
      s.onerror = function() { load('en', true); };
    }
    document.head.appendChild(s);
  }

  load(code, false);

  // Safety fallback
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(function(){ if(window.__applyI18n) window.__applyI18n(); }, 200);
    });
  } else {
    setTimeout(function(){ if(window.__applyI18n) window.__applyI18n(); }, 200);
  }
})();
