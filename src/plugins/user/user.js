(function () {
    'use strict';

    function init() {
        var root = document.querySelector('.user-settings');

        if (!root) {
            return;
        }

        var inputs = root.querySelectorAll('input[type="color"][data-role]');
        var previews = root.querySelectorAll('.user-settings-preview[data-mode]');
        var presets = root.querySelectorAll('.user-preset[data-preset]');
        var presetInput = root.querySelector('#user-color-preset');

        function contrast(hex) {
            hex = String(hex || '').replace('#', '');

            if (hex.length !== 6) {
                return '#ffffff';
            }

            var r = parseInt(hex.substr(0, 2), 16);
            var g = parseInt(hex.substr(2, 2), 16);
            var b = parseInt(hex.substr(4, 2), 16);
            var luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

            return luminance > 0.6 ? '#161b1d' : '#ffffff';
        }

        function palette(mode) {
            var colors = {};

            inputs.forEach(function (input) {
                if (input.getAttribute('data-mode') === mode) {
                    colors[input.getAttribute('data-role')] = input.value;
                }
            });

            return colors;
        }

        function updatePreview(preview) {
            var mode = preview.getAttribute('data-mode');
            var colors = palette(mode);

            if (colors.menu) {
                preview.style.setProperty('--preview-menu', colors.menu);
                preview.style.setProperty('--preview-menu-text', contrast(colors.menu));
            }

            if (colors.accent) {
                preview.style.setProperty('--preview-accent', colors.accent);
                preview.style.setProperty('--preview-accent-text', contrast(colors.accent));
            }

            if (colors.background) {
                preview.style.setProperty('--preview-background', colors.background);
            }

            if (colors.text) {
                preview.style.setProperty('--preview-text', colors.text);
            }

            if (colors.selection) {
                preview.style.setProperty('--preview-selection', colors.selection);
            }
        }

        function updatePreviews() {
            previews.forEach(updatePreview);
        }

        function setPresetState(value) {
            if (presetInput) {
                presetInput.value = value;
            }

            presets.forEach(function (button) {
                var active = button.getAttribute('data-preset') === value;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        function applyPreset(button) {
            ['light', 'dark'].forEach(function (mode) {
                var raw = button.getAttribute('data-' + mode);
                var colors = raw ? JSON.parse(raw) : null;

                if (!colors) {
                    return;
                }

                inputs.forEach(function (input) {
                    if (input.getAttribute('data-mode') !== mode) {
                        return;
                    }

                    var role = input.getAttribute('data-role');

                    if (colors[role]) {
                        input.value = colors[role];
                    }
                });
            });

            setPresetState(button.getAttribute('data-preset'));
            updatePreviews();
        }

        presets.forEach(function (button) {
            button.addEventListener('click', function () {
                applyPreset(button);
            }, false);
        });

        inputs.forEach(function (input) {
            input.addEventListener('input', function () {
                setPresetState('custom');
                updatePreviews();
            }, false);
            input.addEventListener('change', function () {
                setPresetState('custom');
                updatePreviews();
            }, false);
        });

        updatePreviews();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
