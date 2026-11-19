/**
 * ============================================================================
 * File: js/admin.js
 * Project: sumthin (Tech Gadget Online Store)
 * Module: Admin Email & Management Operations
 * Description: Unobtrusive jQuery event handlers for admin panels.
 *              Strictly avoids inline JS attributes (onclick, onchange).
 * ============================================================================
 */
$(function() {
    // 1. Email Template Dropdown Handler (Populate Subject and Message)
    $('#template_select').on('change', function() {
        var selectedId = $(this).val();
        if (!selectedId) {
            return;
        }

        var jsonElement = document.getElementById('email-templates-json');
        if (!jsonElement) {
            return;
        }

        try {
            var templates = JSON.parse(jsonElement.textContent);
            var match = templates.find(function(t) {
                return String(t.id) === String(selectedId);
            });

            if (match) {
                $('#subject').val(match.subject);
                $('#message').val(match.body);
            }
        } catch (err) {
            console.error('Error parsing email template data:', err);
        }
    });

    // 2. Character Counter for Admin Email Compose
    $('#message').on('input', function() {
        var len = $(this).val().length;
        $('#char-count').text(len);
        if (len < 10 || len > 5000) {
            $('#char-count').css('color', 'var(--error)');
        } else {
            $('#char-count').css('color', 'var(--gray)');
        }
    });

    // Initial counter check
    if ($('#message').length > 0) {
        var initialLen = $('#message').val().length;
        $('#char-count').text(initialLen);
    }
});
