    if (window.location.pathname.endsWith('/index.php') && window.history && typeof window.history.replaceState === 'function') {
        const cleanPath = window.location.pathname.replace(/index\.php$/, '');
        const cleanUrl = cleanPath + window.location.search + window.location.hash;
        window.history.replaceState({}, document.title, cleanUrl || './');
    }
