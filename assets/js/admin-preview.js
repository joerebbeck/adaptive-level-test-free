/* Admin settings live-preview and accordion helpers.
 * Dynamic values (primary colour, i18n labels) are passed via wp_localize_script as eslAdminData.
 */
function eslTogglePreview(id) {
    var textarea = document.getElementById(id);
    var preview  = document.getElementById(id + '-preview');
    var btn      = document.getElementById(id + '-btn');
    var editMode = textarea.style.display !== 'none';
    textarea.style.display = editMode ? 'none' : '';
    preview.style.display  = editMode ? '' : 'none';
    if (editMode) { preview.textContent = textarea.value; }
    btn.textContent = editMode
        ? (typeof eslAdminData !== 'undefined' ? eslAdminData.labelEditHtml : 'Edit HTML')
        : (typeof eslAdminData !== 'undefined' ? eslAdminData.labelPreview  : 'Preview');
}

(function() {
    function eslConsentAccordion(cbId, cellId) {
        var cb   = document.getElementById(cbId);
        var cell = document.getElementById(cellId);
        if (!cb || !cell) return;
        var row = cell.closest('tr');
        if (!row) return;
        row.style.display = cb.checked ? '' : 'none';
        cb.addEventListener('change', function() {
            row.style.display = cb.checked ? '' : 'none';
        });
    }
    function eslConsentAccordionDiv(cbId, divId) {
        var cb  = document.getElementById(cbId);
        var div = document.getElementById(divId);
        if (!cb || !div) return;
        div.style.display = cb.checked ? '' : 'none';
        cb.addEventListener('change', function() {
            div.style.display = cb.checked ? '' : 'none';
        });
    }
    eslConsentAccordion('esl-gdpr2-cb', 'esl-gdpr2-msg-cell');
    eslConsentAccordion('esl-counter-cb', 'esl-counter-format-row');
    eslConsentAccordion('esl-show-error-rate-cb', 'esl-error-rate-cell');
    eslConsentAccordionDiv('esl-dyslexic-cb', 'esl-dyslexic-details');
    eslConsentAccordionDiv('esl-share-enabled-cb', 'esl-share-details');
})();

// Primary colour from General Settings — used as fallback for any accent colour not explicitly overridden
var eslPrimary = (typeof eslAdminData !== 'undefined' && eslAdminData.primaryColor) ? eslAdminData.primaryColor : '#2563eb';

// Shared helper: read value from a named input, return fallback if not found
function eslVal(name, fallback) {
    var el = document.querySelector('[name="' + name + '"]');
    return el ? el.value : fallback;
}
function eslChecked(name) {
    var el = document.querySelector('[name="' + name + '"]');
    return el ? el.checked : false;
}
// Apply box card styles (bg, text, radius, border, shadow) to an element
function eslApplyBox(card, prefix) {
    var bg  = eslVal(prefix + '_box_bg', '');
    var txt = eslVal(prefix + '_box_text_color', '');
    var rad = eslVal(prefix + '_box_border_radius', '');
    var bw  = eslVal(prefix + '_box_border_width', '0');
    var bc  = eslVal(prefix + '_box_border_color', '#e5e7eb');
    var shd = eslChecked(prefix + '_box_shadow');
    if (bg)  card.style.background   = bg;
    if (txt) card.style.color        = txt;
    if (rad) card.style.borderRadius = rad + 'px';
    card.style.border    = parseInt(bw) > 0 ? bw + 'px solid ' + bc : 'none';
    card.style.boxShadow = shd ? '0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06)' : 'none';
}
// Shared helper: bind input+change events on all matching elements
function eslBind(selectors, update) {
    document.querySelectorAll(selectors).forEach(function(el) {
        el.addEventListener('input',  update);
        el.addEventListener('change', update);
    });
}

// Live Preview — Before the Quiz
(function() {
    var prevCard     = document.getElementById('esl-prev-card');
    var prevTitle    = document.getElementById('esl-prev-title');
    if (!prevTitle) return;
    var prevSubtitle = document.getElementById('esl-prev-subtitle');
    var prevBody     = document.getElementById('esl-prev-body');
    var prevEmail    = document.getElementById('esl-prev-email');
    var prevBtn      = document.getElementById('esl-prev-btn');
    var prevGdpr     = document.getElementById('esl-prev-gdpr');
    var prevGdprMsg  = document.getElementById('esl-prev-gdpr-msg');

    // Dynamic style for ::placeholder (can't be set inline)
    var phStyle = document.createElement('style');
    document.head.appendChild(phStyle);

    function update() {
        // Content
        var t = document.getElementById('esl-start-title');
        if (t && prevTitle) prevTitle.textContent = t.value;

        var s = document.getElementById('esl-start-subtitle');
        if (s && prevSubtitle) prevSubtitle.textContent = s.value;

        var b = document.getElementById('esl-start-body');
        if (b && prevBody) {
            prevBody.textContent = b.value;
            prevBody.style.display = b.value.trim() ? '' : 'none';
        }

        var ep = document.querySelector('[name="adaptive_test_start_email_placeholder"]');
        if (ep && prevEmail) prevEmail.placeholder = ep.value;

        var bt = document.querySelector('[name="adaptive_test_start_button_text"]');
        if (bt && prevBtn) prevBtn.textContent = bt.value;

        var cb = document.getElementById('esl-gdpr2-cb');
        var gm = document.getElementById('esl-start-gdpr2');
        if (cb && prevGdpr) {
            prevGdpr.style.display = cb.checked ? 'flex' : 'none';
            if (gm && prevGdprMsg) prevGdprMsg.textContent = gm.value;
        }

        // Title
        if (prevTitle) {
            prevTitle.style.color      = eslVal('adaptive_test_before_title_color',  '#1f2937');
            prevTitle.style.fontSize   = eslVal('adaptive_test_before_title_size',   '28') + 'px';
            prevTitle.style.fontWeight = eslVal('adaptive_test_before_title_weight', '700');
        }
        // Subtitle
        if (prevSubtitle) {
            prevSubtitle.style.color      = eslVal('adaptive_test_before_subtitle_color',  '#6b7280');
            prevSubtitle.style.fontSize   = eslVal('adaptive_test_before_subtitle_size',   '16') + 'px';
            prevSubtitle.style.fontWeight = eslVal('adaptive_test_before_subtitle_weight', '400');
        }
        // Body
        if (prevBody) {
            prevBody.style.color      = eslVal('adaptive_test_before_body_color',  '#6b7280');
            prevBody.style.fontSize   = eslVal('adaptive_test_before_body_size',   '12') + 'px';
            prevBody.style.fontWeight = eslVal('adaptive_test_before_body_weight', '400');
        }
        // Email input
        if (prevEmail) {
            var bw = eslVal('adaptive_test_before_input_border_width',  '2');
            var br = eslVal('adaptive_test_before_input_border_radius', '12');
            var bc = eslVal('adaptive_test_before_input_border_color',  '#e5e7eb');
            var ps = eslVal('adaptive_test_before_input_placeholder_size', '16');
            var pc = eslVal('adaptive_test_before_input_placeholder_color', '#9ca3af');
            prevEmail.style.borderWidth  = bw + 'px';
            prevEmail.style.borderStyle  = 'solid';
            prevEmail.style.borderColor  = bc;
            prevEmail.style.borderRadius = br + 'px';
            prevEmail.style.fontSize     = ps + 'px';
            phStyle.textContent = '#esl-prev-email::placeholder{color:' + pc + ';}';
        }
        // Consent
        if (prevGdpr) {
            prevGdpr.style.color      = eslVal('adaptive_test_before_consent_color',  '#6b7280');
            prevGdpr.style.fontSize   = eslVal('adaptive_test_before_consent_size',   '13') + 'px';
            prevGdpr.style.fontWeight = eslVal('adaptive_test_before_consent_weight', '400');
        }
        // Button
        if (prevBtn) {
            prevBtn.style.background   = eslVal('adaptive_test_before_btn_color',         eslPrimary);
            prevBtn.style.color        = eslVal('adaptive_test_before_btn_text_color',    '#ffffff');
            prevBtn.style.fontSize     = eslVal('adaptive_test_before_btn_size',          '16') + 'px';
            prevBtn.style.fontWeight   = eslVal('adaptive_test_before_btn_weight',        '600');
            prevBtn.style.borderColor  = eslVal('adaptive_test_before_btn_border_color',  eslPrimary);
            prevBtn.style.borderWidth  = eslVal('adaptive_test_before_btn_border_width',  '2') + 'px';
            prevBtn.style.borderRadius = eslVal('adaptive_test_before_btn_border_radius', '12') + 'px';
            prevBtn.style.borderStyle  = 'solid';
        }
        // Box
        if (prevCard) eslApplyBox(prevCard, 'adaptive_test_before');
    }

    ['esl-start-title','esl-start-subtitle','esl-start-body','esl-start-gdpr2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', update);
    });
    document.getElementById('esl-gdpr2-cb') && document.getElementById('esl-gdpr2-cb').addEventListener('change', update);
    eslBind(
        '[name="adaptive_test_start_email_placeholder"],[name="adaptive_test_start_button_text"],' +
        '[name="adaptive_test_before_title_color"],[name="adaptive_test_before_title_size"],[name="adaptive_test_before_title_weight"],' +
        '[name="adaptive_test_before_subtitle_color"],[name="adaptive_test_before_subtitle_size"],[name="adaptive_test_before_subtitle_weight"],' +
        '[name="adaptive_test_before_body_color"],[name="adaptive_test_before_body_size"],[name="adaptive_test_before_body_weight"],' +
        '[name="adaptive_test_before_input_placeholder_color"],[name="adaptive_test_before_input_placeholder_size"],' +
        '[name="adaptive_test_before_input_border_width"],[name="adaptive_test_before_input_border_radius"],[name="adaptive_test_before_input_border_color"],' +
        '[name="adaptive_test_before_consent_color"],[name="adaptive_test_before_consent_size"],[name="adaptive_test_before_consent_weight"],' +
        '[name="adaptive_test_before_btn_color"],[name="adaptive_test_before_btn_text_color"],[name="adaptive_test_before_btn_size"],[name="adaptive_test_before_btn_weight"],' +
        '[name="adaptive_test_before_btn_border_color"],[name="adaptive_test_before_btn_border_width"],[name="adaptive_test_before_btn_border_radius"],' +
        '[name="adaptive_test_before_box_bg"],[name="adaptive_test_before_box_text_color"],[name="adaptive_test_before_box_border_radius"],' +
        '[name="adaptive_test_before_box_border_width"],[name="adaptive_test_before_box_border_color"],[name="adaptive_test_before_box_shadow"]',
        update
    );

    update();
})();

// Live Preview — During the Quiz
(function() {
    var prevCard = document.getElementById('esl-prev-card');
    if (!prevCard || !document.querySelector('[name="adaptive_test_during_show_progress"]')) return;

    var prevProgressWrap = document.getElementById('esl-prev-progress-wrap');
    var prevProgressBar  = document.getElementById('esl-prev-progress-bar');
    var prevCounter      = document.getElementById('esl-prev-counter');
    var prevQuestion     = document.getElementById('esl-prev-question');

    function update() {
        var showProg = document.querySelector('[name="adaptive_test_during_show_progress"]');
        if (prevProgressWrap) prevProgressWrap.style.display = (showProg && showProg.checked) ? '' : 'none';

        if (prevProgressBar) prevProgressBar.style.background = eslVal('adaptive_test_during_progress_color', eslPrimary);

        var showCtr = document.getElementById('esl-counter-cb');
        var ctrFmt  = document.querySelector('[name="adaptive_test_during_counter_format"]');
        if (prevCounter) {
            prevCounter.style.display    = (showCtr && showCtr.checked) ? '' : 'none';
            prevCounter.style.color      = eslVal('adaptive_test_during_counter_color',  '#6b7280');
            prevCounter.style.fontSize   = eslVal('adaptive_test_during_counter_size',   '13') + 'px';
            prevCounter.style.fontWeight = eslVal('adaptive_test_during_counter_weight', '400');
            if (ctrFmt) prevCounter.textContent = ctrFmt.value.replace('%n%','2').replace('%total%','5');
        }

        if (prevQuestion) {
            prevQuestion.style.textAlign  = eslVal('adaptive_test_during_question_align',  'center');
            prevQuestion.style.color      = eslVal('adaptive_test_during_question_color',  '#1f2937');
            prevQuestion.style.fontSize   = eslVal('adaptive_test_during_question_size',   '20') + 'px';
            prevQuestion.style.fontWeight = eslVal('adaptive_test_during_question_weight', '600');
        }

        var optColor    = eslVal('adaptive_test_during_option_color',                    '#000000');
        var selColor    = eslVal('adaptive_test_during_option_selected_color',          eslPrimary);
        var selTxtColor = eslVal('adaptive_test_during_option_selected_text',           '#ffffff');
        var selSz       = eslVal('adaptive_test_during_option_selected_size',           '15');
        var selWt       = eslVal('adaptive_test_during_option_selected_weight',         '400');
        var selBdrC     = eslVal('adaptive_test_during_option_selected_border_color',   eslPrimary);
        var selBdrW     = eslVal('adaptive_test_during_option_selected_border_width',   '2');
        var selBdrR     = eslVal('adaptive_test_during_option_selected_border_radius',  '12');
        var optBw       = eslVal('adaptive_test_during_option_border_width',            '2');
        var optRad      = eslVal('adaptive_test_during_option_border_radius',           '12');
        var optBc       = eslVal('adaptive_test_during_option_border_color',            '#e5e7eb');
        var optSz       = eslVal('adaptive_test_during_option_size',                    '15');
        var optWt       = eslVal('adaptive_test_during_option_weight',                  '400');
        var oAlign      = eslVal('adaptive_test_during_options_align',                  'center');
        document.querySelectorAll('.esl-prev-option').forEach(function(o) {
            var isSel = o.classList.contains('esl-prev-selected');
            o.style.textAlign = oAlign;
            if (isSel) {
                o.style.background   = selColor;
                o.style.color        = selTxtColor;
                o.style.fontSize     = selSz + 'px';
                o.style.fontWeight   = selWt;
                o.style.borderColor  = selBdrC;
                o.style.borderWidth  = selBdrW + 'px';
                o.style.borderRadius = selBdrR + 'px';
                o.style.borderStyle  = 'solid';
            } else {
                o.style.background   = '';
                o.style.color        = optColor;
                o.style.fontSize     = optSz + 'px';
                o.style.fontWeight   = optWt;
                o.style.borderColor  = optBc;
                o.style.borderWidth  = optBw + 'px';
                o.style.borderRadius = optRad + 'px';
                o.style.borderStyle  = 'solid';
            }
        });

        eslApplyBox(prevCard, 'adaptive_test_during');
    }

    eslBind(
        '[name="adaptive_test_during_show_progress"],[name="adaptive_test_during_question_align"],' +
        '[name="adaptive_test_during_question_color"],[name="adaptive_test_during_question_size"],[name="adaptive_test_during_question_weight"],' +
        '[name="adaptive_test_during_options_align"],[name="adaptive_test_during_counter_format"],' +
        '[name="adaptive_test_during_counter_color"],[name="adaptive_test_during_counter_size"],[name="adaptive_test_during_counter_weight"],' +
        '[name="adaptive_test_during_progress_color"],' +
        '[name="adaptive_test_during_option_color"],[name="adaptive_test_during_option_selected_color"],[name="adaptive_test_during_option_selected_text"],' +
        '[name="adaptive_test_during_option_selected_size"],[name="adaptive_test_during_option_selected_weight"],' +
        '[name="adaptive_test_during_option_selected_border_color"],[name="adaptive_test_during_option_selected_border_width"],[name="adaptive_test_during_option_selected_border_radius"],' +
        '[name="adaptive_test_during_option_border_width"],[name="adaptive_test_during_option_border_radius"],[name="adaptive_test_during_option_border_color"],' +
        '[name="adaptive_test_during_option_size"],[name="adaptive_test_during_option_weight"],' +
        '[name="adaptive_test_during_box_bg"],[name="adaptive_test_during_box_border_radius"],' +
        '[name="adaptive_test_during_box_border_width"],[name="adaptive_test_during_box_border_color"],[name="adaptive_test_during_box_shadow"]',
        update
    );
    var ctrCb = document.getElementById('esl-counter-cb');
    if (ctrCb) ctrCb.addEventListener('change', update);

    // Dyslexic toggle visibility, label, and interactive font switching
    var prevDyslexicToggle = document.getElementById('esl-prev-dyslexic-toggle');
    if (prevDyslexicToggle) {
        var dyslexicActive = false;
        function updateDyslexic() {
            var enabledCb  = document.querySelector('[name="adaptive_test_during_dyslexic_enabled"]');
            var labelOffIn = document.querySelector('[name="adaptive_test_during_dyslexic_off"]');
            var labelOnIn  = document.querySelector('[name="adaptive_test_during_dyslexic_on"]');
            var labelOff   = (labelOffIn && labelOffIn.value.trim()) ? labelOffIn.value : 'Change to dyslexia friendly font';
            var labelOn    = (labelOnIn  && labelOnIn.value.trim())  ? labelOnIn.value  : 'Change to regular font';
            var dysEnabled = enabledCb && enabledCb.checked;
            if (enabledCb) prevDyslexicToggle.style.display = dysEnabled ? '' : 'none';
            if (prevProgressWrap) prevProgressWrap.style.marginTop = dysEnabled ? '28px' : '0';
            prevDyslexicToggle.textContent = dyslexicActive ? labelOn : labelOff;
            prevDyslexicToggle.style.color        = eslVal('adaptive_test_during_dyslexic_color',         '#6b7280');
            prevDyslexicToggle.style.background   = eslVal('adaptive_test_during_dyslexic_bg',            '#ffffff');
            prevDyslexicToggle.style.fontSize     = eslVal('adaptive_test_during_dyslexic_size',          '11') + 'px';
            var bdw = eslVal('adaptive_test_during_dyslexic_border_width',  '1');
            var bdc = eslVal('adaptive_test_during_dyslexic_border_color',  '#e5e7eb');
            var bdr = eslVal('adaptive_test_during_dyslexic_border_radius', '20');
            prevDyslexicToggle.style.border       = parseInt(bdw) > 0 ? bdw + 'px solid ' + bdc : 'none';
            prevDyslexicToggle.style.borderRadius = bdr + 'px';
        }
        prevDyslexicToggle.addEventListener('click', function() {
            dyslexicActive = !dyslexicActive;
            prevCard.classList.toggle('esl-dyslexic', dyslexicActive);
            updateDyslexic();
        });
        eslBind(
            '[name="adaptive_test_during_dyslexic_enabled"],[name="adaptive_test_during_dyslexic_off"],[name="adaptive_test_during_dyslexic_on"],' +
            '[name="adaptive_test_during_dyslexic_color"],[name="adaptive_test_during_dyslexic_bg"],[name="adaptive_test_during_dyslexic_size"],' +
            '[name="adaptive_test_during_dyslexic_border_width"],[name="adaptive_test_during_dyslexic_border_color"],[name="adaptive_test_during_dyslexic_border_radius"]',
            updateDyslexic
        );
        updateDyslexic();
    }

    update();
})();

// Encouragement preview demo — eye button on During preview
(function() {
    var eyeBtn = document.getElementById('esl-encouragement-eye');
    if (!eyeBtn) return;
    eyeBtn.addEventListener('click', function() {
        var card = document.getElementById('esl-prev-card');
        if (!card) return;
        // Toggle off if already running
        var existing = document.getElementById('esl-prev-encouragement');
        if (existing) { existing.remove(); return; }
        var p   = eslPrimary || '#2563eb';
        var tri = 'width:0;height:0;border-top:10px solid transparent;border-bottom:10px solid transparent;';
        var demo = document.createElement('div');
        demo.id = 'esl-prev-encouragement';
        demo.style.cssText = 'position:absolute;inset:0;background:rgba(255,255,255,0.96);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:20;border-radius:inherit;transition:opacity 0.3s ease;';
        demo.innerHTML =
            '<div style="width:90%;height:28px;position:relative;margin-bottom:20px;">' +
                '<div id="esl-demo-l" style="position:absolute;top:50%;left:0%;transform:translateY(-50%);transition:left 1.2s ease;' + tri + 'border-left:16px solid ' + p + ';"></div>' +
                '<div id="esl-demo-dot" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:12px;height:12px;border-radius:50%;background:#f59e0b;border:2px solid #d97706;box-sizing:border-box;transition:transform 0.5s ease,opacity 0.5s ease;"></div>' +
                '<div id="esl-demo-r" style="position:absolute;top:50%;right:0%;transform:translateY(-50%);transition:right 1.2s ease;' + tri + 'border-right:16px solid ' + p + ';"></div>' +
            '</div>' +
            '<p style="font-size:0.88em;color:#6b7280;font-style:italic;margin:0;">Mapping your ability…</p>';
        card.appendChild(demo);
        var L   = demo.querySelector('#esl-demo-l');
        var R   = demo.querySelector('#esl-demo-r');
        var dot = demo.querySelector('#esl-demo-dot');
        setTimeout(function() { L.style.left = '35%'; R.style.right = '35%'; }, 80);
        setTimeout(function() {
            dot.style.transform = 'translate(-50%,-50%) scale(3.5)';
            dot.style.opacity   = '0';
        }, 1600);
        setTimeout(function() { demo.style.opacity = '0'; }, 2100);
        setTimeout(function() { if (demo.parentNode) demo.remove(); }, 2450);
    });
})();

// Live Preview — After the Quiz
(function() {
    var prevCard          = document.getElementById('esl-prev-card');
    var prevResultLevel   = document.getElementById('esl-prev-result-level');
    if (!prevResultLevel) return;
    var prevScaleBar      = document.getElementById('esl-prev-scale-bar');
    var prevErrorMargin   = document.getElementById('esl-prev-error-margin');
    var prevActiveLabel   = document.getElementById('esl-prev-active-label');
    var prevRetake        = document.getElementById('esl-prev-retake');
    var prevAfterTitle    = document.getElementById('esl-prev-after-title');
    var prevAfterSub      = document.getElementById('esl-prev-after-subheading');
    var prevAfterBody     = document.getElementById('esl-prev-after-body');
    var prevShareSection  = document.getElementById('esl-prev-share-section');
    var prevShareHeading  = document.getElementById('esl-prev-share-heading');
    var prevShareBody     = document.getElementById('esl-prev-share-body');
    var prevShareWa       = document.getElementById('esl-prev-share-wa');
    var prevShareFb       = document.getElementById('esl-prev-share-fb');
    var prevShareNative   = document.getElementById('esl-prev-share-native');
    var prevShareCopy     = document.getElementById('esl-prev-share-copy');

    function update() {
        // Title content + styling
        var titleEl = document.getElementById('esl-after-title');
        if (titleEl && prevAfterTitle) prevAfterTitle.textContent = titleEl.value;
        if (prevAfterTitle) {
            prevAfterTitle.style.color      = eslVal('adaptive_test_after_title_color',  '#1f2937');
            prevAfterTitle.style.fontSize   = eslVal('adaptive_test_after_title_size',   '24') + 'px';
            prevAfterTitle.style.fontWeight = eslVal('adaptive_test_after_title_weight', '700');
        }
        // Subheading content + styling
        var subEl = document.getElementById('esl-after-subheading');
        if (subEl && prevAfterSub) prevAfterSub.textContent = subEl.value;
        if (prevAfterSub) {
            prevAfterSub.style.color      = eslVal('adaptive_test_after_subheading_color',  '#6b7280');
            prevAfterSub.style.fontSize   = eslVal('adaptive_test_after_subheading_size',   '16') + 'px';
            prevAfterSub.style.fontWeight = eslVal('adaptive_test_after_subheading_weight', '400');
        }
        // Body content + styling
        var bodyEl = document.getElementById('esl-after-body');
        if (bodyEl && prevAfterBody) prevAfterBody.textContent = bodyEl.value;
        if (prevAfterBody) {
            prevAfterBody.style.color      = eslVal('adaptive_test_after_body_color',  '#6b7280');
            prevAfterBody.style.fontSize   = eslVal('adaptive_test_after_body_size',   '14') + 'px';
            prevAfterBody.style.fontWeight = eslVal('adaptive_test_after_body_weight', '400');
        }

        var resultColor = eslVal('adaptive_test_after_result_color', eslPrimary);
        prevResultLevel.style.color      = resultColor;
        prevResultLevel.style.fontSize   = eslVal('adaptive_test_after_result_size',   '64') + 'px';
        prevResultLevel.style.fontWeight = eslVal('adaptive_test_after_result_weight', '700');
        if (prevScaleBar) {
            var showErrRate = eslChecked('adaptive_test_show_error_rate');
            prevScaleBar.style.display = showErrRate ? '' : 'none';
            if (showErrRate) {
                prevScaleBar.style.background = resultColor;
                var errRate  = Math.max(0, parseInt(eslVal('adaptive_test_error_rate', '5')) || 0);
                var indWidth = Math.max(5, errRate * 2);
                var leftPos  = 50 - (indWidth / 2);
                prevScaleBar.style.width = indWidth + '%';
                prevScaleBar.style.left  = leftPos  + '%';
            }
        }
        if (prevErrorMargin) {
            var showErrRate2 = eslChecked('adaptive_test_show_error_rate');
            prevErrorMargin.style.display = showErrRate2 ? '' : 'none';
            if (showErrRate2) {
                var labelEl  = document.getElementById('esl-error-rate-label');
                var rawLabel = (labelEl && labelEl.value.trim()) ? labelEl.value : 'Margin of Error: ±{rate}%';
                var errRate2 = Math.max(0, parseInt(eslVal('adaptive_test_error_rate', '5')) || 0);
                prevErrorMargin.textContent = rawLabel.replace('{rate}', errRate2);
            }
        }
        if (prevActiveLabel) prevActiveLabel.style.color   = resultColor;

        if (prevRetake) {
            prevRetake.style.background   = eslVal('adaptive_test_after_retake_color',         eslPrimary);
            prevRetake.style.color        = eslVal('adaptive_test_after_retake_text_color',     '#ffffff');
            prevRetake.style.fontSize     = eslVal('adaptive_test_after_retake_size',           '16') + 'px';
            prevRetake.style.fontWeight   = eslVal('adaptive_test_after_retake_weight',         '600');
            prevRetake.style.borderColor  = eslVal('adaptive_test_after_retake_border_color',   eslPrimary);
            prevRetake.style.borderWidth  = eslVal('adaptive_test_after_retake_border_width',   '2') + 'px';
            prevRetake.style.borderRadius = eslVal('adaptive_test_after_retake_border_radius',  '8') + 'px';
            prevRetake.style.borderStyle  = 'solid';
        }

        if (prevCard) eslApplyBox(prevCard, 'adaptive_test_after');

        // Share section (Pro only)
        if (prevShareSection) {
            prevShareSection.style.display = 'none';
            if (prevShareHeading) {
                var shIn = document.querySelector('[name="adaptive_test_after_share_heading"]');
                if (shIn) prevShareHeading.textContent = shIn.value;
                prevShareHeading.style.color      = eslVal('adaptive_test_after_share_heading_color',  '#1f2937');
                prevShareHeading.style.fontSize   = eslVal('adaptive_test_after_share_heading_size',   '16') + 'px';
                prevShareHeading.style.fontWeight = eslVal('adaptive_test_after_share_heading_weight', '600');
            }
            if (prevShareBody) {
                var sbIn = document.querySelector('[name="adaptive_test_after_share_body"]');
                if (sbIn) prevShareBody.textContent = sbIn.value;
                prevShareBody.style.color      = eslVal('adaptive_test_after_share_body_color',  '#6b7280');
                prevShareBody.style.fontSize   = eslVal('adaptive_test_after_share_body_size',   '13') + 'px';
                prevShareBody.style.fontWeight = eslVal('adaptive_test_after_share_body_weight', '400');
            }
            if (prevShareWa) {
                var waIn = document.querySelector('[name="adaptive_test_after_share_whatsapp"]');
                if (waIn && waIn.value.trim()) prevShareWa.textContent = waIn.value;
            }
            if (prevShareFb) {
                var fbIn = document.querySelector('[name="adaptive_test_after_share_facebook"]');
                if (fbIn && fbIn.value.trim()) prevShareFb.textContent = fbIn.value;
            }
            if (prevShareNative) {
                var ntIn = document.querySelector('[name="adaptive_test_after_share_native"]');
                if (ntIn && ntIn.value.trim()) prevShareNative.textContent = ntIn.value;
                prevShareNative.style.background = eslVal('adaptive_test_after_share_native_bg',    '#000000');
                prevShareNative.style.color      = eslVal('adaptive_test_after_share_native_color', '#ffffff');
            }
            if (prevShareCopy) {
                var cpIn = document.querySelector('[name="adaptive_test_after_share_copy"]');
                if (cpIn && cpIn.value.trim()) prevShareCopy.textContent = cpIn.value;
                prevShareCopy.style.background = eslVal('adaptive_test_after_share_copy_bg',    '#f3f4f6');
                prevShareCopy.style.color      = eslVal('adaptive_test_after_share_copy_color', '#374151');
            }
        }
    }

    ['esl-after-title','esl-after-subheading','esl-after-body'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', update);
    });
    var errLabelEl = document.getElementById('esl-error-rate-label');
    if (errLabelEl) errLabelEl.addEventListener('input', update);
    eslBind(
        '[name="adaptive_test_show_error_rate"],[name="adaptive_test_error_rate"],' +
        '[name="adaptive_test_after_title_color"],[name="adaptive_test_after_title_size"],[name="adaptive_test_after_title_weight"],' +
        '[name="adaptive_test_after_subheading_color"],[name="adaptive_test_after_subheading_size"],[name="adaptive_test_after_subheading_weight"],' +
        '[name="adaptive_test_after_body_color"],[name="adaptive_test_after_body_size"],[name="adaptive_test_after_body_weight"],' +
        '[name="adaptive_test_after_result_color"],[name="adaptive_test_after_result_size"],[name="adaptive_test_after_result_weight"],' +
        '[name="adaptive_test_after_retake_color"],[name="adaptive_test_after_retake_text_color"],[name="adaptive_test_after_retake_size"],[name="adaptive_test_after_retake_weight"],' +
        '[name="adaptive_test_after_retake_border_color"],[name="adaptive_test_after_retake_border_width"],[name="adaptive_test_after_retake_border_radius"],' +
        '[name="adaptive_test_after_box_bg"],[name="adaptive_test_after_box_text_color"],[name="adaptive_test_after_box_border_radius"],' +
        '[name="adaptive_test_after_box_border_width"],[name="adaptive_test_after_box_border_color"],[name="adaptive_test_after_box_shadow"],' +
        '[name="adaptive_test_after_share_enabled"],' +
        '[name="adaptive_test_after_share_heading"],[name="adaptive_test_after_share_heading_color"],[name="adaptive_test_after_share_heading_size"],[name="adaptive_test_after_share_heading_weight"],' +
        '[name="adaptive_test_after_share_body"],[name="adaptive_test_after_share_body_color"],[name="adaptive_test_after_share_body_size"],[name="adaptive_test_after_share_body_weight"],' +
        '[name="adaptive_test_after_share_whatsapp"],[name="adaptive_test_after_share_facebook"],' +
        '[name="adaptive_test_after_share_native"],[name="adaptive_test_after_share_native_bg"],[name="adaptive_test_after_share_native_color"],' +
        '[name="adaptive_test_after_share_copy"],[name="adaptive_test_after_share_copy_bg"],[name="adaptive_test_after_share_copy_color"]',
        update
    );

    update();
})();
