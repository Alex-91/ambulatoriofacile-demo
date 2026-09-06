(function () {
    'use strict';

    var panel = document.getElementById('agendaFontPreferencesPanel');
    if (!panel) {
        return;
    }

    var defaults = {};
    try {
        defaults = JSON.parse(panel.getAttribute('data-defaults') || '{}');
    } catch (_) {
        defaults = {};
    }

    var presets = {
        current: defaults,
        comfortable: {
            day_headings: 17,
            time_labels: 16,
            appointment_title: 15,
            appointment_time: 13,
            appointment_details: 12,
            team_professionals: 17,
            controls: 14,
            mini_calendar: 15,
            notes: 14
        },
        large: {
            day_headings: 20,
            time_labels: 18,
            appointment_title: 18,
            appointment_time: 16,
            appointment_details: 15,
            team_professionals: 20,
            controls: 17,
            mini_calendar: 18,
            notes: 18
        }
    };

    var selects = Array.prototype.slice.call(
        panel.querySelectorAll('[data-agenda-font-setting]')
    );

    function updatePreview() {
        selects.forEach(function (select) {
            var key = select.getAttribute('data-agenda-font-setting');
            var value = parseInt(select.value, 10);
            if (!key || !Number.isFinite(value)) {
                return;
            }
            panel.style.setProperty('--preview-' + key.replace(/_/g, '-'), value + 'px');
        });
    }

    function setPreset(name) {
        var preset = presets[name] || presets.current || {};
        selects.forEach(function (select) {
            var key = select.getAttribute('data-agenda-font-setting');
            if (Object.prototype.hasOwnProperty.call(preset, key)) {
                select.value = String(preset[key]);
            }
        });
        updatePreview();
    }

    selects.forEach(function (select) {
        select.addEventListener('change', updatePreview);
    });

    Array.prototype.slice.call(panel.querySelectorAll('[data-agenda-font-preset]'))
        .forEach(function (button) {
            button.addEventListener('click', function () {
                setPreset(button.getAttribute('data-agenda-font-preset'));
            });
        });

    var resetButton = panel.querySelector('[data-agenda-font-reset]');
    if (resetButton) {
        resetButton.addEventListener('click', function () {
            setPreset('current');
        });
    }

    updatePreview();
})();
