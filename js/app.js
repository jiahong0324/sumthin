/**
 * ============================================================================
 * File: js/app.js
 * Project: sumthin (Tech Gadget Online Store)
 * Description: Client-side jQuery event handlers and interactive behaviors.
 * Rules: Strictly NO inline event handlers; all interactions registered via jQuery.
 * ============================================================================
 */

$(document).ready(function() {
    'use strict';

    // 1. Flash Notification: Click to dismiss
    $(document).on('click', '.flash-close', function(e) {
        e.preventDefault();
        $(this).closest('.flash-alert').fadeOut(250, function() {
            $(this).remove();
        });
    });

    // 2. Flash Notification: Auto-fade after 6 seconds
    if ($('.flash-alert').length > 0) {
        setTimeout(function() {
            $('.flash-alert').fadeOut(400, function() {
                $(this).remove();
            });
        }, 6000);
    }

    // 3. Generic Confirmation Dialog for sensitive actions (e.g. Delete, Block, Logout)
    $(document).on('click', '[data-confirm]', function(e) {
        var message = $(this).attr('data-confirm') || 'Are you sure you want to proceed?';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });

    // 4. Quick Demo Credential Autofill (Convenience during live demonstration)
    $(document).on('click', '.btn-fill-demo', function(e) {
        e.preventDefault();
        var email = $(this).data('email');
        var password = $(this).data('password');

        if ($('#email').length) {
            $('#email').val(email);
        }
        if ($('#password').length) {
            $('#password').val(password);
        }
    });

    // 5. Prevent double form submissions on POST forms
    $('form').on('submit', function() {
        var $submitBtn = $(this).find('button[type="submit"], input[type="submit"]');
        if ($submitBtn.length && !$(this).data('submitting')) {
            $(this).data('submitting', true);
            $submitBtn.addClass('btn-submitting');
        }
    });

    // 6. Highlight active form control on focus
    $(document).on('focus', '.form-control, .form-select', function() {
        $(this).closest('.form-group').addClass('has-focus');
    }).on('blur', '.form-control, .form-select', function() {
        $(this).closest('.form-group').removeClass('has-focus');
    });

    // 7. Password visibility toggle (if toggle button present)
    $(document).on('click', '.toggle-password', function(e) {
        e.preventDefault();
        var targetInputId = $(this).data('target');
        var $input = $('#' + targetInputId);
        if ($input.length) {
            var currentType = $input.attr('type');
            var newType = (currentType === 'password') ? 'text' : 'password';
            $input.attr('type', newType);
            $(this).text(newType === 'password' ? 'Show' : 'Hide');
        }
    });

    // 8. Dark / Light Mode Theme Preference Switcher
    var savedTheme = localStorage.getItem('sumthin_theme');
    if (savedTheme === 'dark') {
        $('html, body').addClass('dark-theme');
        $('#theme-toggle').text('☀️').attr('title', 'Switch to Light Mode');
    } else {
        $('#theme-toggle').text('🌙').attr('title', 'Switch to Dark Mode');
    }

    $(document).on('click', '#theme-toggle', function(e) {
        e.preventDefault();
        $('html, body').toggleClass('dark-theme');
        var isDark = $('body').hasClass('dark-theme');
        localStorage.setItem('sumthin_theme', isDark ? 'dark' : 'light');
        $(this).text(isDark ? '☀️' : '🌙');
        $(this).attr('title', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
    });

    // 9. Shopee-Style Quantity Stepper (+ / - buttons)
    $(document).on('click', '.btn-qty-minus', function(e) {
        e.preventDefault();
        var $input = $(this).siblings('.input-qty-value');
        var val = parseInt($input.val(), 10) || 1;
        var min = parseInt($input.attr('min'), 10) || 1;
        if (val > min) {
            $input.val(val - 1).trigger('change');
        }
    });

    $(document).on('click', '.btn-qty-plus', function(e) {
        e.preventDefault();
        var $input = $(this).siblings('.input-qty-value');
        var val = parseInt($input.val(), 10) || 1;
        var max = parseInt($input.attr('max'), 10) || 99;
        if (val < max) {
            $input.val(val + 1).trigger('change');
        }
    });

    // 10. Shopee "Buy Now" vs "Add To Cart" action selector
    $(document).on('click', '.btn-action-buynow', function() {
        $('#shopee-buy-now-input').val('1');
    });

    $(document).on('click', '.btn-action-addcart', function() {
        $('#shopee-buy-now-input').val('0');
    });
});
