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

    // 4. Checkout Address Selector Toggle
    $(document).on('change', 'input[name="address_choice"]', function() {
        if ($(this).val() === 'new') {
            $('#new-address-fields').slideDown(200);
        } else {
            $('#new-address-fields').slideUp(200);
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

    // 11. Smooth horizontal mouse-wheel scrolling for category pills
    $('.category-scroll-list').on('wheel', function(e) {
        if (e.originalEvent.deltaY !== 0) {
            e.preventDefault();
            this.scrollBy({
                left: e.originalEvent.deltaY * 1.5,
                behavior: 'smooth'
            });
        }
    });

    // 12. Smooth in-page anchor scrolling with sticky header offset
    $(document).on('click', 'a[href^="#"]', function(e) {
        var targetHash = $(this).attr('href');
        if (targetHash && targetHash.length > 1) {
            var $target = $(targetHash);
            if ($target.length) {
                e.preventDefault();
                var headerHeight = $('.site-header').outerHeight() || 72;
                var targetTop = $target.offset().top - headerHeight - 16;
                $('html, body').stop().animate({
                    scrollTop: Math.max(0, targetTop)
                }, 350);

                if (history.pushState) {
                    history.pushState(null, null, targetHash);
                }
            }
        }
    });

    // 13. Enable smooth scrolling after initial page render/anchor positioning
    // to prevent unwanted jumping/bouncing animations on page load when a hash is present
    setTimeout(function() {
        $('html').addClass('smooth-scroll');
    }, 100);

    // 14. Smooth Floating Back to Top Button
    var $backToTop = $('#btn-back-to-top');
    if (!$backToTop.length) {
        $backToTop = $('<button type="button" id="btn-back-to-top" class="btn-back-to-top" title="Scroll to Top" aria-label="Scroll to top">&#8593;</button>').appendTo('body');
    }

    $(window).on('scroll', function() {
        if ($(this).scrollTop() > 300) {
            $backToTop.addClass('show');
        } else {
            $backToTop.removeClass('show');
        }
    });

    $backToTop.on('click', function(e) {
        e.preventDefault();
        $('html, body').stop().animate({ scrollTop: 0 }, 350);
    });

    // 15. Shopee Hero Promotional Carousel
    var $heroCarousel = $('#shopee-hero-carousel');
    if ($heroCarousel.length) {
        var $slides = $heroCarousel.find('.shopee-slide');
        var $dots   = $heroCarousel.find('.shopee-dot');
        var slideCount = $slides.length;
        var currentSlide = 0;
        var heroTimer = null;

        function showHeroSlide(index) {
            if (index < 0) index = slideCount - 1;
            if (index >= slideCount) index = 0;
            currentSlide = index;

            $slides.removeClass('active').eq(currentSlide).addClass('active');
            $dots.removeClass('active').eq(currentSlide).addClass('active');
        }

        function startHeroTimer() {
            stopHeroTimer();
            heroTimer = setInterval(function() {
                showHeroSlide(currentSlide + 1);
            }, 5000);
        }

        function stopHeroTimer() {
            if (heroTimer) {
                clearInterval(heroTimer);
                heroTimer = null;
            }
        }

        $heroCarousel.find('.shopee-carousel-next').on('click', function(e) {
            e.preventDefault();
            showHeroSlide(currentSlide + 1);
            startHeroTimer();
        });

        $heroCarousel.find('.shopee-carousel-prev').on('click', function(e) {
            e.preventDefault();
            showHeroSlide(currentSlide - 1);
            startHeroTimer();
        });

        $dots.on('click', function() {
            var idx = parseInt($(this).data('index'), 10);
            showHeroSlide(idx);
            startHeroTimer();
        });

        $heroCarousel.on('mouseenter', stopHeroTimer).on('mouseleave', startHeroTimer);
        startHeroTimer();
    }

    // 16. Shopee Flash Deals Dynamic Countdown Timer
    var $timerBoxes = $('.flash-countdown');
    if ($timerBoxes.length) {
        function updateFlashTimer() {
            var now = new Date();
            // Count down to next 3-hour window
            var targetHour = Math.ceil((now.getHours() + 1) / 3) * 3;
            var target = new Date(now.getFullYear(), now.getMonth(), now.getDate(), targetHour, 0, 0);
            var diff = Math.max(0, Math.floor((target - now) / 1000));

            var hours = Math.floor(diff / 3600);
            var minutes = Math.floor((diff % 3600) / 60);
            var seconds = diff % 60;

            $('#cd-hours').text(hours < 10 ? '0' + hours : hours);
            $('#cd-minutes').text(minutes < 10 ? '0' + minutes : minutes);
            $('#cd-seconds').text(seconds < 10 ? '0' + seconds : seconds);
        }
        updateFlashTimer();
        setInterval(updateFlashTimer, 1000);
    }

    // 17. Shopee One-Click Voucher Claiming
    $(document).on('click', '.btn-claim-voucher', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var code = $btn.data('code');

        if ($btn.hasClass('claimed')) return;

        // Copy code to clipboard
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code);
        }

        // Store in localStorage for checkout auto-fill
        localStorage.setItem('sumthin_claimed_voucher', code);

        $btn.addClass('claimed').text('Claimed ✓');

        // Trigger toast notification if ajax toast helper is available
        var toastMsg = 'Voucher "' + code + '" claimed! Code copied to clipboard.';
        var $toastBox = $('.ajax-toast-container');
        if (!$toastBox.length) {
            $toastBox = $('<div class="ajax-toast-container"></div>').appendTo('body');
        }
        var $t = $('<div class="ajax-toast ajax-toast-success"><span>✓</span><span>' + toastMsg + '</span></div>').appendTo($toastBox);
        setTimeout(function() {
            $t.css({ opacity: 0, transform: 'translateY(15px)' });
            setTimeout(function() { $t.remove(); }, 300);
        }, 3000);
    });
});
