/* Unsaved-changes detection and confirmation dialog for the admin settings page. */
(function() {
    var dirty               = {};
    var pendingHref         = null;
    var intentionalNav      = false;
    var overlay   = document.getElementById('esl-unsaved-overlay');
    var list      = document.getElementById('esl-unsaved-list');
    var stayBtn   = document.getElementById('esl-unsaved-stay');
    var leaveBtn  = document.getElementById('esl-unsaved-leave');

    var labels = {
        'adaptive_test_options':            'General settings',
        'adaptive_test_msg_options':        'Message settings',
        'adaptive_test_before_options':     'Before the Quiz settings',
        'adaptive_test_before_pro_options': 'Before the Quiz customisation',
        'adaptive_test_during_options':     'During the Quiz settings',
        'adaptive_test_during_pro_options': 'During the Quiz customisation',
        'adaptive_test_after_options':      'After the Quiz settings',
        'adaptive_test_after_pro_options':  'After the Quiz customisation',
    };

    // Restore dirty groups that survived a form save + page reload
    var persisted = sessionStorage.getItem('adaptive_test_dirty_groups');
    if (persisted) {
        sessionStorage.removeItem('adaptive_test_dirty_groups');
        try { JSON.parse(persisted).forEach(function(k) { dirty[k] = true; }); } catch(e) {}
    }

    document.querySelectorAll('.wrap form').forEach(function(form) {
        var pageInput = form.querySelector('[name="option_page"]');
        if (!pageInput) return;
        var group = pageInput.value;
        form.querySelectorAll('input, select, textarea').forEach(function(el) {
            el.addEventListener('change', function() { dirty[group] = true; });
            if (el.tagName !== 'SELECT') {
                el.addEventListener('input', function() { dirty[group] = true; });
            }
        });
        form.addEventListener('submit', function() {
            // Persist any other dirty groups so they survive the page reload
            var remaining = Object.keys(dirty).filter(function(k) { return k !== group; });
            if (remaining.length) {
                sessionStorage.setItem('adaptive_test_dirty_groups', JSON.stringify(remaining));
            } else {
                sessionStorage.removeItem('adaptive_test_dirty_groups');
            }
            delete dirty[group];
        });
    });

    function showModal(href) {
        var dl = Object.keys(dirty).map(function(k) { return labels[k] || k; });
        list.innerHTML = '';
        dl.forEach(function(l) { var li = document.createElement('li'); li.textContent = l; list.appendChild(li); });
        pendingHref = href;
        overlay.classList.add('esl-visible');
        stayBtn.focus();
    }

    stayBtn.addEventListener('click', function() {
        overlay.classList.remove('esl-visible');
        pendingHref = null;
    });

    leaveBtn.addEventListener('click', function() {
        document.querySelectorAll('.wrap form').forEach(function(form) {
            var pageInput = form.querySelector('[name="option_page"]');
            if (pageInput && dirty[pageInput.value]) form.reset();
        });
        sessionStorage.removeItem('adaptive_test_dirty_groups');
        dirty = {};
        overlay.classList.remove('esl-visible');
        intentionalNav = true;
        window.location.href = pendingHref;
    });

    overlay.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            overlay.classList.remove('esl-visible');
            pendingHref = null;
        }
    });

    // Suppress any native unload dialog when navigation is triggered by our Leave button
    window.addEventListener('beforeunload', function(e) {
        if (intentionalNav) {
            e.returnValue = '';
            intentionalNav = false;
        }
    });

    // Capture phase ensures our handler fires before any other click handler
    document.querySelectorAll('.nav-tab-wrapper a, .esl-subnav a').forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (!Object.keys(dirty).length) return;
            e.preventDefault();
            showModal(this.href);
        }, true);
    });
})();
